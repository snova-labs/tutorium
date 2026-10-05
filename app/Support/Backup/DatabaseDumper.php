<?php

declare(strict_types=1);

namespace App\Support\Backup;

use HashContext;
use Illuminate\Database\Connection;

/**
 * A plain-SQL dump of a MySQL database, written by PHP over the app's own connection.
 *
 * No `mysqldump` binary: the application image does not carry one, and the database runs in a
 * separate container. Every statement is on one line (strings are escaped, newlines included), so
 * the file can be replayed line by line, by DatabaseImporter or by the `mysql` client.
 *
 * Rows are written in primary-key order, so dumping the same data twice gives the same text. That
 * is what lets the restore drill prove a restore is complete: it dumps the restored copy again and
 * compares each table's hash with the one recorded at backup time.
 */
final class DatabaseDumper
{
    private const ROWS_PER_INSERT = 100;

    /**
     * Dump to a writer (a gzip stream for a backup, a no-op for re-hashing a restored copy).
     *
     * @param callable(string): void $write receives one line at a time
     * @param list<string> $structureOnly tables whose rows are skipped
     * @return array<string, array{rows: int, sha256: string}> per table, in name order
     */
    public function dump(Connection $connection, callable $write, array $structureOnly = []): array
    {
        $tables = $this->tables($connection);
        $summary = [];

        // One consistent view of every table, unless a transaction is already open (tests): an
        // explicit START TRANSACTION would commit that one.
        $snapshot = $connection->transactionLevel() === 0;

        if ($snapshot) {
            $connection->unprepared('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $connection->unprepared('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        }

        try {
            $write('-- Tutorium backup. Replay line by line, or with: mysql <database> < file.sql');
            $write('SET NAMES utf8mb4;');
            $write('SET FOREIGN_KEY_CHECKS=0;');
            $write("SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';");

            foreach ($tables as $table) {
                $write('DROP TABLE IF EXISTS '.$this->name($table).';');
                $write($this->createStatement($connection, $table).';');

                $summary[$table] = in_array($table, $structureOnly, true)
                    ? ['rows' => 0, 'sha256' => hash('sha256', '')]
                    : $this->rows($connection, $table, $write);
            }

            $write('SET FOREIGN_KEY_CHECKS=1;');
        } finally {
            if ($snapshot) {
                $connection->unprepared('COMMIT');
            }
        }

        return $summary;
    }

    /** @return list<string> */
    public function tables(Connection $connection): array
    {
        $rows = $connection->select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        $tables = array_map(fn (object $row): string => (string) array_values((array) $row)[0], $rows);
        sort($tables);

        return $tables;
    }

    /** @return array{rows: int, sha256: string} */
    private function rows(Connection $connection, string $table, callable $write): array
    {
        $hash = hash_init('sha256');
        $count = 0;
        $batch = [];
        $columns = [];

        foreach ($this->ordered($connection, $table) as $row) {
            $values = (array) $row;
            $columns = array_keys($values);
            $batch[] = '('.implode(',', array_map($this->literal(...), array_values($values))).')';
            $count++;

            if (count($batch) >= self::ROWS_PER_INSERT) {
                $this->insert($table, $columns, $batch, $write, $hash);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $this->insert($table, $columns, $batch, $write, $hash);
        }

        return ['rows' => $count, 'sha256' => hash_final($hash)];
    }

    /**
     * @param list<string> $columns
     * @param list<string> $values
     */
    private function insert(string $table, array $columns, array $values, callable $write, HashContext $hash): void
    {
        $line = 'INSERT INTO '.$this->name($table).' ('.implode(',', array_map($this->name(...), $columns)).') VALUES '
            .implode(',', $values).';';

        hash_update($hash, $line."\n");
        $write($line);
    }

    /** @return iterable<int, object> */
    private function ordered(Connection $connection, string $table): iterable
    {
        $key = $this->primaryKey($connection, $table);
        $query = $connection->table($table);

        if (count($key) === 1) {
            return $query->lazyById(1000, $key[0]);
        }

        // A composite key, or none: order by it, or by every column, so the order is stable.
        $order = $key !== [] ? $key : array_map(
            fn (object $c): string => (string) $c->Field,
            $connection->select('SHOW COLUMNS FROM '.$this->name($table)),
        );

        foreach ($order as $column) {
            $query->orderBy($column);
        }

        return $query->lazy(1000);
    }

    /** @return list<string> */
    private function primaryKey(Connection $connection, string $table): array
    {
        $keys = $connection->select('SHOW KEYS FROM '.$this->name($table)." WHERE Key_name = 'PRIMARY'");
        usort($keys, fn (object $a, object $b): int => $a->Seq_in_index <=> $b->Seq_in_index);

        return array_map(fn (object $k): string => (string) $k->Column_name, $keys);
    }

    private function createStatement(Connection $connection, string $table): string
    {
        $row = (array) $connection->selectOne('SHOW CREATE TABLE '.$this->name($table));
        $sql = (string) array_values($row)[1];

        // The counter is not part of the data, and differs between the live copy and a restore.
        $sql = (string) preg_replace('/ AUTO_INCREMENT=\d+/', '', $sql);

        return str_replace("\n", ' ', $sql);
    }

    private function name(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    /** MySQL string syntax, escaped so that every value stays on one line. */
    private function literal(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return "'".strtr((string) $value, [
            '\\' => '\\\\',
            "\0" => '\\0',
            "\n" => '\\n',
            "\r" => '\\r',
            "'" => "\\'",
            "\x1a" => '\\Z',
        ])."'";
    }
}

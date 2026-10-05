<?php

declare(strict_types=1);

namespace App\Support\Backup;

use Illuminate\Database\Connection;
use RuntimeException;

/** Replays a DatabaseDumper file (gzip) into a connection, one statement per line. */
final class DatabaseImporter
{
    public function import(Connection $connection, string $gzipPath): int
    {
        $handle = gzopen($gzipPath, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Cannot open {$gzipPath}.");
        }

        $statements = 0;

        try {
            while (($line = gzgets($handle)) !== false) {
                $line = rtrim($line, "\r\n");

                if ($line === '' || str_starts_with($line, '--')) {
                    continue;
                }

                $connection->unprepared($line);
                $statements++;
            }
        } finally {
            gzclose($handle);
        }

        return $statements;
    }
}

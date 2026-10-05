<?php

declare(strict_types=1);

namespace App\Support\Backup;

use RuntimeException;

/**
 * Streamed gzip in PHP. The application image has `tar` but no `gzip` binary, so archives are
 * written as plain tar and compressed here (and the other way round for the restore drill).
 */
final class Gzip
{
    public static function compress(string $from, string $to): void
    {
        self::copy(fopen($from, 'rb'), gzopen($to, 'wb6'), fn ($out, string $chunk) => gzwrite($out, $chunk), gzclose(...));
    }

    public static function decompress(string $from, string $to): void
    {
        $in = gzopen($from, 'rb');
        $out = fopen($to, 'wb');

        if ($in === false || $out === false) {
            throw new RuntimeException("Cannot decompress {$from}.");
        }

        while (! gzeof($in)) {
            $chunk = gzread($in, 1 << 20);

            if ($chunk === false) {
                throw new RuntimeException("{$from} is not a readable gzip file.");
            }

            fwrite($out, $chunk);
        }

        gzclose($in);
        fclose($out);
    }

    /**
     * @param resource|false $in
     * @param resource|false $out
     */
    private static function copy($in, $out, callable $write, callable $close): void
    {
        if ($in === false || $out === false) {
            throw new RuntimeException('Cannot open the files to compress.');
        }

        while (! feof($in)) {
            $write($out, (string) fread($in, 1 << 20));
        }

        fclose($in);
        $close($out);
    }
}

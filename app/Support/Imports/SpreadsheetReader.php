<?php

declare(strict_types=1);

namespace App\Support\Imports;

use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use Throwable;

/**
 * Reads the first sheet of an .xlsx or .csv file into a header and rows.
 *
 * Row numbers are the ones the person sees in their spreadsheet (the header is row 1), so a
 * message saying "row 14" points at row 14. Blank rows are skipped rather than reported.
 */
final class SpreadsheetReader
{
    /**
     * @return array{headers: array<int, string>, rows: array<int, array<int, mixed>>}
     *                                                                                 rows keyed by spreadsheet row number
     */
    public function read(string $path, string $extension): array
    {
        try {
            $reader = IOFactory::createReader(strtolower($extension) === 'csv' ? 'Csv' : 'Xlsx');
            $reader->setReadDataOnly(true);

            if ($reader instanceof Csv) {
                $reader->setInputEncoding(Csv::GUESS_ENCODING);
            }

            $sheet = $reader->load($path)->getActiveSheet();

            // A CSV holds text, so "=SUM(...)" in one is text and is read as text. An .xlsx
            // formula is the person's own calculation, so its result is what they mean.
            $cells = $sheet->toArray(null, ! $reader instanceof Csv, false, false);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'file' => 'This file could not be read. Save it as .xlsx or .csv and try again.',
            ]);
        }

        $headers = array_map(fn ($value): string => trim((string) $value), array_shift($cells) ?? []);

        $rows = [];

        foreach ($cells as $index => $values) {
            if (array_filter($values, fn ($value): bool => trim((string) $value) !== '') === []) {
                continue;
            }

            // +2: arrays count from zero, and the header took row 1.
            $rows[$index + 2] = $values;
        }

        return ['headers' => $headers, 'rows' => $rows];
    }
}

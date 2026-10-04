<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Import;
use App\Models\Learner;
use App\Models\User;
use App\Support\Imports\SpreadsheetReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Learners and their main guardian, brought in from a spreadsheet (FR-PPL-5).
 *
 * Two steps, on purpose. The preview reads every row and says, by row number, what is wrong with
 * each blocked one; nothing is written. The commit re-reads the same file against the data as it
 * is now and writes every ready row in one transaction, so a failure part-way leaves nothing
 * behind. Blocked rows come back as a file with the problems beside them, to fix and re-import.
 */
final class LearnerImportService
{
    public const MAX_ROWS = 5000;

    /** Column => what it is called in the template. Aliases are matched as well. */
    public const COLUMNS = [
        'legal_name' => 'Legal name',
        'preferred_name' => 'Preferred name',
        'date_of_birth' => 'Date of birth',
        'gender' => 'Gender',
        'email' => 'Email',
        'phone' => 'Phone',
        'country' => 'Country',
        'guardian_name' => 'Guardian name',
        'guardian_email' => 'Guardian email',
        'guardian_phone' => 'Guardian phone',
        'guardian_receives_reports' => 'Guardian receives reports',
    ];

    private const ALIASES = [
        'name' => 'legal_name',
        'full_name' => 'legal_name',
        'learner_name' => 'legal_name',
        'student_name' => 'legal_name',
        'dob' => 'date_of_birth',
        'birth_date' => 'date_of_birth',
        'parent_name' => 'guardian_name',
        'parent_email' => 'guardian_email',
        'parent_phone' => 'guardian_phone',
    ];

    private const SAMPLE_SIZE = 10;

    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly LearnerService $learners,
        private readonly GuardianService $guardians,
    ) {}

    /** Check a file and keep it for the commit. Nothing about learners is written. */
    public function preview(UploadedFile $file, User $user): Import
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $analysis = $this->analyse((string) $file->getRealPath(), $extension);

        $disk = 'local';
        $path = $file->storeAs('imports/'.$user->tenant_id, Str::uuid()->toString().'.'.$extension, $disk);

        return Import::query()->create([
            'type' => Import::TYPE_LEARNERS,
            'status' => Import::STATUS_PREVIEWED,
            'original_name' => Str::limit($file->getClientOriginalName(), 180, ''),
            'file_disk' => $disk,
            'file_path' => $path,
            'totals' => $analysis['totals'],
            'issues' => $analysis['issues'],
            'run_by_user_id' => $user->getKey(),
        ]);
    }

    /**
     * Write every ready row, all or nothing. Possible duplicates of learners already on file are
     * left out unless the person reviewing the preview says to include them.
     */
    public function commit(Import $import, bool $includePossibleDuplicates = false): Import
    {
        return DB::transaction(function () use ($import, $includePossibleDuplicates): Import {
            $import = Import::query()->whereKey($import->getKey())->lockForUpdate()->firstOrFail();

            if (! $import->isOpen()) {
                throw ValidationException::withMessages([
                    'import' => 'This import has already been '.$import->status.'.',
                ]);
            }

            $path = Storage::disk((string) $import->file_disk)->path((string) $import->file_path);
            $analysis = $this->analyse($path, pathinfo((string) $import->file_path, PATHINFO_EXTENSION));

            $created = 0;
            $skipped = 0;

            try {
                foreach ($analysis['ready'] as $line => $row) {
                    if (! $includePossibleDuplicates && isset($analysis['duplicateLines'][$line])) {
                        $skipped++;

                        continue;
                    }

                    $this->write($row);
                    $created++;
                }
            } catch (Throwable $e) {
                // Nothing from this file is kept; the transaction rolls back every row.
                report($e);

                throw ValidationException::withMessages([
                    'import' => 'The import stopped part-way, so nothing was imported. Try again, '
                        .'or contact support if it happens twice.',
                ]);
            }

            $import->update([
                'status' => Import::STATUS_COMMITTED,
                'totals' => $analysis['totals'] + ['created' => $created, 'skipped_duplicates' => $skipped],
                'issues' => $analysis['issues'],
                'finished_at' => now(),
            ]);

            $this->forgetFile($import);

            return $import->refresh();
        });
    }

    public function discard(Import $import): Import
    {
        if (! $import->isOpen()) {
            throw ValidationException::withMessages([
                'import' => 'This import has already been '.$import->status.'.',
            ]);
        }

        $import->update(['status' => Import::STATUS_DISCARDED, 'finished_at' => now()]);
        $this->forgetFile($import);

        return $import->refresh();
    }

    /** The blocked rows, as they were, with what is wrong beside each one. */
    public function rejectsCsv(Import $import): string
    {
        $columns = array_keys(self::COLUMNS);

        return $this->csv(
            ['Row', ...array_values(self::COLUMNS), 'Problems'],
            array_map(fn (array $blocked): array => [
                $blocked['row'],
                ...array_map(fn (string $column) => $blocked['values'][$column] ?? '', $columns),
                implode(' ', $blocked['reasons']),
            ], $import->issues['blocked'] ?? []),
        );
    }

    public function templateCsv(): string
    {
        return $this->csv(array_values(self::COLUMNS), []);
    }

    /**
     * @return array{
     *     totals: array<string, int>,
     *     issues: array<string, mixed>,
     *     ready: array<int, array<string, mixed>>,
     *     duplicateLines: array<int, true>,
     * }
     */
    private function analyse(string $path, string $extension): array
    {
        ['headers' => $headers, 'rows' => $rows] = $this->reader->read($path, $extension);

        $map = $this->mapHeaders($headers);

        if (! in_array('legal_name', $map, true)) {
            throw ValidationException::withMessages([
                'file' => 'The first row must name the columns, and one of them must be "Legal name". '
                    .'Download the template to see the expected layout.',
            ]);
        }

        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages([
                'file' => 'This file has '.count($rows).' rows. Import at most '.self::MAX_ROWS.' at a time.',
            ]);
        }

        $existing = $this->existingLearners();
        $seenInFile = [];
        $ready = [];
        $blocked = [];
        $duplicates = [];
        $duplicateLines = [];

        foreach ($rows as $line => $cells) {
            $values = [];

            foreach ($map as $index => $column) {
                $values[$column] = $this->cell($column, $cells[$index] ?? null);
            }

            $reasons = $this->problems($values);
            $key = $this->identity($values);

            if ($reasons === [] && isset($seenInFile[$key])) {
                $reasons[] = 'Same learner as row '.$seenInFile[$key].'.';
            }

            if ($reasons !== []) {
                $blocked[] = ['row' => $line, 'reasons' => $reasons, 'values' => $values];

                continue;
            }

            $seenInFile[$key] = $line;
            $ready[$line] = $values;

            $matches = $existing[$this->normalise((string) $values['legal_name'])] ?? [];

            if ($matches !== []) {
                $sameBirthday = array_filter($matches, fn (array $m): bool => $m['date_of_birth'] !== null
                    && $m['date_of_birth'] === ($values['date_of_birth'] ?? null));

                $duplicates[] = [
                    'row' => $line,
                    'name' => $values['legal_name'],
                    'reason' => $sameBirthday !== [] ? 'same name and date of birth' : 'same name',
                    'matches' => array_column($sameBirthday !== [] ? $sameBirthday : $matches, 'number'),
                ];
                $duplicateLines[$line] = true;
            }
        }

        $unknown = array_values(array_filter(
            $headers,
            fn (string $header, int $index): bool => $header !== '' && ! isset($map[$index]),
            ARRAY_FILTER_USE_BOTH,
        ));

        return [
            'totals' => [
                'rows' => count($rows),
                'ready' => count($ready),
                'blocked' => count($blocked),
                'possible_duplicates' => count($duplicates),
            ],
            'issues' => [
                'blocked' => $blocked,
                'possible_duplicates' => $duplicates,
                'ignored_columns' => $unknown,
                'sample' => array_slice(array_values($ready), 0, self::SAMPLE_SIZE),
            ],
            'ready' => $ready,
            'duplicateLines' => $duplicateLines,
        ];
    }

    /**
     * @param array<int, string> $headers
     * @return array<int, string> column index => field
     */
    private function mapHeaders(array $headers): array
    {
        $known = [];

        foreach (self::COLUMNS as $field => $label) {
            $known[$this->headerKey($label)] = $field;
            $known[$field] = $field;
        }

        foreach (self::ALIASES as $alias => $field) {
            $known[$alias] = $field;
        }

        $map = [];

        foreach ($headers as $index => $header) {
            $field = $known[$this->headerKey($header)] ?? null;

            // The first column of a given meaning wins; a repeated one is ignored, not merged.
            if ($field !== null && ! in_array($field, $map, true)) {
                $map[$index] = $field;
            }
        }

        return $map;
    }

    private function headerKey(string $header): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($header))), '_');
    }

    private function cell(string $column, mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        // Excel stores dates as day counts; a typed date arrives as text and is checked as text.
        if ($column === 'date_of_birth' && is_numeric($value)) {
            return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }

        if (is_float($value) && floor($value) === $value) {
            $value = (int) $value;
        }

        return trim((string) $value);
    }

    /**
     * @param array<string, string|null> $values
     * @return array<int, string>
     */
    private function problems(array $values): array
    {
        $validator = Validator::make($values, [
            'legal_name' => ['required', 'string', 'max:190'],
            'preferred_name' => ['nullable', 'string', 'max:120'],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'gender' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:32'],
            'country' => ['nullable', 'string', 'size:2'],
            'guardian_name' => ['nullable', 'string', 'max:190'],
            'guardian_email' => ['nullable', 'email', 'max:190'],
            'guardian_phone' => ['nullable', 'string', 'max:32'],
            'guardian_receives_reports' => ['nullable', 'in:yes,no,y,n,true,false,1,0,Yes,No,YES,NO,Y,N,TRUE,FALSE'],
        ], [
            'legal_name.required' => 'A name is required.',
            'date_of_birth.date_format' => 'Write the date of birth as YYYY-MM-DD, or use a date cell.',
            'date_of_birth.before' => 'The date of birth is in the future.',
            'country.size' => 'Use the two-letter country code, such as NP or GB.',
            'guardian_receives_reports.in' => 'Guardian receives reports must be yes or no.',
        ], array_map('strtolower', self::COLUMNS));

        $reasons = $validator->errors()->all();

        $hasGuardianContact = ($values['guardian_email'] ?? null) !== null || ($values['guardian_phone'] ?? null) !== null;

        if ($hasGuardianContact && ($values['guardian_name'] ?? null) === null) {
            $reasons[] = 'A guardian email or phone needs the guardian\'s name as well.';
        }

        return $reasons;
    }

    /** @param array<string, mixed> $values */
    private function write(array $values): void
    {
        $learner = $this->learners->create(array_filter([
            'legal_name' => $values['legal_name'],
            'preferred_name' => $values['preferred_name'] ?? null,
            'date_of_birth' => $values['date_of_birth'] ?? null,
            'gender' => $values['gender'] ?? null,
            'email' => $values['email'] ?? null,
            'phone' => $values['phone'] ?? null,
            'country' => isset($values['country']) ? strtoupper((string) $values['country']) : null,
        ], fn ($value): bool => $value !== null));

        if (($values['guardian_name'] ?? null) === null) {
            return;
        }

        $this->guardians->attachOrCreate(
            $learner,
            array_filter([
                'name' => $values['guardian_name'],
                'email' => $values['guardian_email'] ?? null,
                'phone' => $values['guardian_phone'] ?? null,
            ], fn ($value): bool => $value !== null),
            isPrimary: true,
            receivesReports: ! in_array(strtolower((string) ($values['guardian_receives_reports'] ?? 'yes')), ['no', 'n', 'false', '0'], true),
        );
    }

    /**
     * Learners already on file, by normalised name, loaded once rather than queried per row.
     *
     * @return array<string, array<int, array{number: string, date_of_birth: string|null}>>
     */
    private function existingLearners(): array
    {
        $byName = [];

        Learner::query()->select(['id', 'number', 'legal_name', 'preferred_name', 'date_of_birth'])
            ->chunkById(500, function ($learners) use (&$byName): void {
                foreach ($learners as $learner) {
                    $entry = [
                        'number' => (string) $learner->number,
                        'date_of_birth' => $learner->date_of_birth?->format('Y-m-d'),
                    ];

                    foreach (array_filter([$learner->legal_name, $learner->preferred_name]) as $name) {
                        $byName[$this->normalise((string) $name)][] = $entry;
                    }
                }
            });

        return $byName;
    }

    /** @param array<string, string|null> $values */
    private function identity(array $values): string
    {
        return $this->normalise((string) ($values['legal_name'] ?? '')).'|'.($values['date_of_birth'] ?? '');
    }

    private function normalise(string $name): string
    {
        return (string) preg_replace('/\s+/', ' ', Str::lower(Str::ascii(trim($name))));
    }

    private function forgetFile(Import $import): void
    {
        if ($import->file_disk !== null && $import->file_path !== null) {
            Storage::disk($import->file_disk)->delete($import->file_path);
        }

        $import->update(['file_path' => null]);
    }

    /**
     * Spreadsheet programs run a cell that starts with = + - or @ as a formula, so a value typed as
     * "=HYPERLINK(...)" would become live in the rejects file. Such cells are prefixed with a quote.
     *
     * @param array<int, string> $header
     * @param array<int, array<int, mixed>> $rows
     */
    private function csv(array $header, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new \RuntimeException('Could not open a temporary stream.');
        }

        fputcsv($handle, $header, escape: '\\');

        foreach ($rows as $row) {
            fputcsv($handle, array_map(function ($value): string {
                $value = (string) $value;

                return $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true) ? "'".$value : $value;
            }, $row), escape: '\\');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}

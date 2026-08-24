<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Assessment;
use App\Models\AttendanceRecord;
use App\Models\Batch;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Guardian;
use App\Models\Learner;
use App\Models\Operator;
use App\Models\Report;
use App\Models\TeacherNote;
use App\Models\Tenant;
use App\Models\TenantExport;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * A complete copy of an account's data, in formats anyone can open.
 *
 * Deliberately plain JSON plus the report PDFs, not a database dump. An export a customer cannot
 * read without our software is not portability, it is a gesture (FR-DAT-1).
 */
final class TenantExportService
{
    /** Everything worth taking with you, in dependency order. */
    private const ENTITIES = [
        'brands' => Brand::class,
        'branches' => Branch::class,
        'staff' => User::class,
        'courses' => Course::class,
        'batches' => Batch::class,
        'sessions' => ClassSession::class,
        'learners' => Learner::class,
        'guardians' => Guardian::class,
        'enrollments' => Enrollment::class,
        'attendance' => AttendanceRecord::class,
        'assessments' => Assessment::class,
        'grades' => Grade::class,
        'notes' => TeacherNote::class,
        'reports' => Report::class,
    ];

    public function __construct(private readonly TenantContext $tenancy) {}

    public function request(Tenant $tenant, ?User $user = null, ?Operator $operator = null): TenantExport
    {
        return $this->tenancy->runAs($tenant, fn () => TenantExport::query()->create([
            'status' => TenantExport::STATUS_QUEUED,
            'requested_by_user_id' => $user?->getKey(),
            'requested_by_operator_id' => $operator?->getKey(),
            // Long enough to download without hurry, short enough that a stale link is not a
            // copy of a customer's data sitting around indefinitely.
            'expires_at' => now()->addDays(7),
        ]));
    }

    public function build(TenantExport $export): TenantExport
    {
        $tenant = $this->tenancy->withoutScoping(
            fn () => Tenant::withTrashed()->findOrFail($export->tenant_id),
        );

        $export->update(['status' => TenantExport::STATUS_RUNNING]);

        try {
            return $this->tenancy->runAs($tenant, function () use ($export, $tenant): TenantExport {
                $zipPath = $this->assemble($tenant, $export);
                $disk = config('reporting.storage.disk', 'local');
                $stored = sprintf('exports/tenant-%d/%s.zip', $tenant->getKey(), $export->getKey());

                Storage::disk($disk)->put($stored, file_get_contents($zipPath));
                @unlink($zipPath);

                $export->update([
                    'status' => TenantExport::STATUS_READY,
                    'file_path' => $stored,
                    'file_disk' => $disk,
                    'size_bytes' => Storage::disk($disk)->size($stored),
                ]);

                return $export->refresh();
            });
        } catch (Throwable $e) {
            $export->update(['status' => TenantExport::STATUS_FAILED, 'error' => $e->getMessage()]);

            throw $e;
        }
    }

    private function assemble(Tenant $tenant, TenantExport $export): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'export_').'.zip';
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the export archive.');
        }

        $manifest = ['account' => $tenant->name, 'exported_at_utc' => now()->toIso8601String(), 'counts' => []];

        foreach (self::ENTITIES as $name => $class) {
            $rows = $class::query()->get()->map(fn (Model $m) => $m->toArray())->all();
            $manifest['counts'][$name] = count($rows);

            $zip->addFromString(
                "data/{$name}.json",
                json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            );
        }

        $manifest['reports_included'] = $this->addReports($zip);

        $zip->addFromString(
            'manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );

        $zip->addFromString('README.txt', $this->readme($tenant, $manifest));
        $zip->close();

        $export->update(['manifest' => $manifest]);

        return $zipPath;
    }

    private function addReports(ZipArchive $zip): int
    {
        $included = 0;

        foreach (Report::query()->whereNotNull('file_path')->cursor() as $report) {
            $disk = Storage::disk($report->file_disk ?? config('reporting.storage.disk', 'local'));

            if (! $disk->exists($report->file_path)) {
                continue;
            }

            $zip->addFromString('reports/'.$report->number.'.pdf', (string) $disk->get($report->file_path));
            $included++;
        }

        return $included;
    }

    /** @param array<string, mixed> $manifest */
    private function readme(Tenant $tenant, array $manifest): string
    {
        $lines = [
            'Export of '.$tenant->name,
            'Produced '.$manifest['exported_at_utc'].' (UTC)',
            '',
            'data/     one JSON file per record type, readable in any text editor or spreadsheet tool',
            'reports/  every progress report that was generated, as PDF',
            '',
            'Contents:',
        ];

        foreach ($manifest['counts'] as $name => $count) {
            $lines[] = sprintf('  %-14s %d', $name, $count);
        }

        $lines[] = sprintf('  %-14s %d', 'report PDFs', $manifest['reports_included']);
        $lines[] = '';
        $lines[] = 'All times in the data files are UTC. Dates recorded against classes are in the';
        $lines[] = 'timezone of the branch that ran them, which is on each branch record.';

        return implode("\n", $lines)."\n";
    }
}

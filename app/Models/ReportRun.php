<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReportRunStatus;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\ReportRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A bulk generation, and what happened to each report in it. */
final class ReportRun extends Model
{
    /** @use HasFactory<ReportRunFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'batch_id', 'reporting_period_id', 'options', 'status',
        'total', 'succeeded', 'failed', 'failures', 'started_by', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'failures' => 'array',
            'status' => ReportRunStatus::class,
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Batch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /** @return HasMany<Report, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    /** Records a failure without stopping the run — the rest of the class still gets reports. */
    public function recordFailure(string $learner, string $reason): void
    {
        $failures = $this->failures ?? [];
        $failures[] = ['learner' => $learner, 'reason' => $reason];

        $this->update(['failures' => $failures, 'failed' => $this->failed + 1]);
    }

    public function progress(): int
    {
        return $this->total === 0 ? 0 : (int) round((($this->succeeded + $this->failed) / $this->total) * 100);
    }
}

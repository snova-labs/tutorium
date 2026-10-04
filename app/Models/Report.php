<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One generated report.
 *
 * `stats_snapshot` is the evidence. Everything a recipient read is frozen here at generation, so
 * a correction made three weeks later cannot quietly change what the report said.
 */
final class Report extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'enrollment_id', 'reporting_period_id', 'report_template_id', 'report_run_id',
        'number', 'file_path', 'file_disk', 'stats_snapshot', 'generated_by', 'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'stats_snapshot' => 'array',
            'generated_at' => 'immutable_datetime',
        ];
    }

    /**
     * The snapshot is what a parent was sent, so its numbers keep their type: an average of 80.0
     * must not come back as the integer 80.
     *
     * @param mixed $value
     * @param int $flags
     */
    protected function asJson($value, $flags = 0): string|false
    {
        return json_encode($value, $flags | JSON_PRESERVE_ZERO_FRACTION);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(ReportingPeriod::class, 'reporting_period_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ReportTemplate::class, 'report_template_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(ReportDelivery::class);
    }

    public function wasDelivered(): bool
    {
        return $this->deliveries()->where('status', 'sent')->exists();
    }

    public function auditModule(): string
    {
        return 'Reporting';
    }

    public function auditLabel(): ?string
    {
        return $this->number;
    }
}

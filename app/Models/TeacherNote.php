<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\TeacherNoteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A teacher's written observation about one learner in one period.
 *
 * The visibility flag is the whole point of the model. Half of what a good teacher records is
 * context that helps colleagues and would be unkind or unwise to send home.
 */
final class TeacherNote extends Model
{
    /** @use HasFactory<TeacherNoteFactory> */
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'enrollment_id', 'reporting_period_id', 'note_category_id',
        'body', 'is_report_visible', 'author_id',
    ];

    protected function casts(): array
    {
        return ['is_report_visible' => 'boolean'];
    }

    /** @return BelongsTo<Enrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /** @return BelongsTo<ReportingPeriod, $this> */
    public function period(): BelongsTo
    {
        return $this->belongsTo(ReportingPeriod::class, 'reporting_period_id');
    }

    /** @return BelongsTo<NoteCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(NoteCategory::class, 'note_category_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeVisibleOnReports(Builder $query): Builder
    {
        return $query->where('is_report_visible', true);
    }

    public function auditModule(): string
    {
        return 'Notes';
    }

    public function auditLabel(): ?string
    {
        return $this->enrollment?->number;
    }

    /**
     * Note bodies are personal opinion about a child. The audit log records that a note changed
     * and who changed it, never the text itself.
     *
     * @return array<int, string>
     */
    public function auditExcluded(): array
    {
        return ['body', 'updated_at', 'created_at'];
    }
}

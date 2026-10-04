<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\LearnerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * A person who learns here.
 *
 * Holds identity only. Everything academic — attendance, grades, notes, reports — hangs off an
 * enrollment instead, so a learner who moves between batches carries their history with them
 * rather than dragging it out of context.
 */
final class Learner extends Model
{
    /** @use HasFactory<LearnerFactory> */
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'number', 'legal_name', 'preferred_name', 'sort_name',
        'date_of_birth', 'gender', 'country', 'home_timezone', 'email', 'phone', 'photo_path',
        'status_id', 'status_reason', 'status_changed_on', 'custom',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'immutable_date',
            'status_changed_on' => 'immutable_date',
            'custom' => 'array',
        ];
    }

    /** @return BelongsTo<LearnerStatus, $this> */
    public function status(): BelongsTo
    {
        return $this->belongsTo(LearnerStatus::class, 'status_id');
    }

    /** @return BelongsToMany<Guardian, $this> */
    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(Guardian::class, 'guardian_learner')
            ->withPivot(['is_primary', 'receives_reports'])
            ->withTimestamps();
    }

    /** @return HasMany<Enrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /** The name to use when addressing them: what they answer to, not what the paperwork says. */
    public function displayName(): string
    {
        return $this->preferred_name ?: $this->legal_name;
    }

    /**
     * Everyone who should receive this learner's reports.
     *
     * @return Collection<int, Guardian>
     */
    public function reportRecipients(): Collection
    {
        return $this->guardians()->wherePivot('receives_reports', true)->get();
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $like = '%'.str_replace('%', '\%', $term).'%';

        return $query->where(fn (Builder $q) => $q
            ->where('legal_name', 'like', $like)
            ->orWhere('preferred_name', 'like', $like)
            ->orWhere('number', 'like', $like)
            ->orWhereHas('guardians', fn (Builder $g) => $g
                ->where('name', 'like', $like)->orWhere('email', 'like', $like)));
    }

    public function auditModule(): string
    {
        return 'People';
    }

    public function auditLabel(): string
    {
        return $this->displayName();
    }
}

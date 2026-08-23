<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A parent, guardian or sponsor — a first-class person, not a set of columns on a learner.
 *
 * The many-to-many is the point: one parent with three children here is one record receiving
 * three reports, not three copies of the same person whose email must be corrected three times.
 */
final class Guardian extends Model
{
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'name', 'relation_type_id', 'email', 'secondary_email',
        'phone', 'preferred_channel', 'locale', 'timezone',
    ];

    public function relationType(): BelongsTo
    {
        return $this->belongsTo(RelationType::class);
    }

    public function learners(): BelongsToMany
    {
        return $this->belongsToMany(Learner::class, 'guardian_learner')
            ->withPivot(['is_primary', 'receives_reports'])
            ->withTimestamps();
    }

    /** Where a report actually goes. */
    public function deliveryAddress(): ?string
    {
        return $this->email ?: $this->secondary_email;
    }

    public function auditModule(): string
    {
        return 'People';
    }
}

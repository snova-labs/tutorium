<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\SubmissionStatusFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Submitted, Late, Missing, Exempt.
 *
 * `excluded_from_average` is the flag that separates a learner who was excused from one who did
 * not hand work in. Both look like an empty cell on a grid; only one should cost them an average.
 */
final class SubmissionStatus extends Model
{
    /** @use HasFactory<SubmissionStatusFactory> */
    use Auditable, BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'name', 'code', 'counts_as_submitted',
        'excluded_from_average', 'is_negative', 'color', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'counts_as_submitted' => 'boolean',
            'excluded_from_average' => 'boolean',
            'is_negative' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function auditModule(): string
    {
        return 'Settings';
    }
}

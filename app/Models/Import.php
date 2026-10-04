<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\ImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A spreadsheet brought in: previewed, then committed in one transaction, or discarded.
 *
 * @property array<string, int>|null $totals
 * @property array<string, mixed>|null $issues
 */
final class Import extends Model
{
    /** @use HasFactory<ImportFactory> */
    use BelongsToTenant, HasFactory;

    public const TYPE_LEARNERS = 'learners';

    public const STATUS_PREVIEWED = 'previewed';

    public const STATUS_COMMITTED = 'committed';

    public const STATUS_DISCARDED = 'discarded';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'tenant_id', 'type', 'status', 'original_name', 'file_disk', 'file_path',
        'totals', 'issues', 'run_by_user_id', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'totals' => 'array',
            'issues' => 'array',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_PREVIEWED;
    }
}

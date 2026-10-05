<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One nightly backup or one restore drill (SL-415). Platform-wide: a backup covers every tenant.
 *
 * @property string $type
 * @property string $status
 * @property string|null $name
 * @property int|null $bytes
 * @property array<string, mixed>|null $details
 * @property string|null $error
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 */
final class BackupRun extends Model
{
    public const BACKUP = 'backup';

    public const DRILL = 'drill';

    public const RUNNING = 'running';

    public const OK = 'ok';

    public const FAILED = 'failed';

    protected $fillable = ['type', 'status', 'name', 'bytes', 'details', 'error', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'bytes' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public static function latestOk(string $type): ?self
    {
        return self::query()->where('type', $type)->where('status', self::OK)->latest('started_at')->latest('id')->first();
    }
}

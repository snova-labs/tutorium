<?php

declare(strict_types=1);

namespace App\Support\Platform;

use Illuminate\Contracts\Support\Arrayable;

/**
 * How an account is actually doing, derived from what it does rather than what it pays.
 *
 * @implements Arrayable<string, mixed>
 */
final class TenantHealth implements Arrayable
{
    public const HEALTHY = 'healthy';

    public const QUIET = 'quiet';

    public const STALLED = 'stalled';

    public const AT_RISK = 'at_risk';

    /** @param array<int, string> $signals */
    public function __construct(
        public readonly string $state,
        public readonly array $signals,
        public readonly ?string $lastActivityUtc,
        public readonly int $activeLearners,
        public readonly int $failedDeliveries,
    ) {}

    public function needsAttention(): bool
    {
        return $this->state !== self::HEALTHY;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'signals' => $this->signals,
            'last_activity_utc' => $this->lastActivityUtc,
            'active_learners' => $this->activeLearners,
            'failed_deliveries' => $this->failedDeliveries,
            'needs_attention' => $this->needsAttention(),
        ];
    }
}

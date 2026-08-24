<?php

declare(strict_types=1);

namespace App\Support\Billing;

use Illuminate\Contracts\Support\Arrayable;

/**
 * One answer about what an account may do, and where the answer came from.
 *
 * @implements Arrayable<string, mixed>
 */
final class Entitlement implements Arrayable
{
    public const SOURCE_PLAN = 'plan';

    public const SOURCE_OVERRIDE = 'override';

    public const SOURCE_LICENCE = 'licence';

    public const SOURCE_DEFAULT = 'default';

    public function __construct(
        public readonly string $key,
        public readonly mixed $value,
        public readonly string $source,
        public readonly bool $isHard,
        public readonly ?string $reason = null,
    ) {}

    public function asBool(): bool
    {
        return (bool) $this->value;
    }

    public function asInt(): ?int
    {
        return $this->value === null ? null : (int) $this->value;
    }

    /** A limit of null means unlimited, which is different from a limit of zero. */
    public function isUnlimited(): bool
    {
        return $this->value === null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'value' => $this->value,
            'source' => $this->source,
            'enforcement' => $this->isHard ? 'hard' : 'soft',
            'reason' => $this->reason,
        ];
    }
}

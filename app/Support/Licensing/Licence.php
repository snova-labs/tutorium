<?php

declare(strict_types=1);

namespace App\Support\Licensing;

use Carbon\CarbonImmutable;

/**
 * What one signed licence file says, once its signature has been checked.
 *
 * A licence conveys the licensee, a learner cap, the features that are switched on, the day it was
 * issued and the day it runs out (SL-BIL-006 §9). It knows nothing about the network, and nothing
 * here ever asks: the file in hand is the whole truth about what this install may do.
 */
final class Licence
{
    /** @param array<string, mixed> $features */
    public function __construct(
        public readonly string $id,
        public readonly string $licensee,
        public readonly CarbonImmutable $issuedOn,
        public readonly CarbonImmutable $expiresOn,
        public readonly ?int $maxLearners,
        public readonly array $features = [],
        public readonly ?string $notes = null,
        public readonly int $graceDays = 30,
    ) {}

    /**
     * Everything the licence allows, as the entitlement layer reads it.
     *
     * The cap is one feature among the rest so that a check for `max_learners` is the same check
     * in self-hosted and in cloud — the whole reason the entitlement interface exists.
     *
     * @return array<string, mixed>
     */
    public function entitlements(): array
    {
        return ['max_learners' => $this->maxLearners] + $this->features;
    }

    public function isExpired(?CarbonImmutable $at = null): bool
    {
        return ($at ?? CarbonImmutable::now())->startOfDay()->gt($this->expiresOn);
    }

    /** The last day this install keeps working on an expired licence. */
    public function graceEndsOn(): CarbonImmutable
    {
        return $this->expiresOn->addDays($this->graceDays);
    }

    /** Expired, but still inside the grace period: working, and worth saying loudly. */
    public function inGrace(?CarbonImmutable $at = null): bool
    {
        $at = ($at ?? CarbonImmutable::now())->startOfDay();

        return $this->isExpired($at) && $at->lte($this->graceEndsOn());
    }

    /** Expired for longer than the grace period: no new enrollments, every export still works. */
    public function lapsed(?CarbonImmutable $at = null): bool
    {
        return ($at ?? CarbonImmutable::now())->startOfDay()->gt($this->graceEndsOn());
    }

    /** Days until it runs out; negative once it has. */
    public function daysLeft(?CarbonImmutable $at = null): int
    {
        return (int) ($at ?? CarbonImmutable::now())->startOfDay()->diffInDays($this->expiresOn, false);
    }

    /** @return array<string, mixed> */
    public function toArray(?CarbonImmutable $at = null): array
    {
        return [
            'id' => $this->id,
            'licensee' => $this->licensee,
            'issued_on' => $this->issuedOn->toDateString(),
            'expires_on' => $this->expiresOn->toDateString(),
            'days_left' => $this->daysLeft($at),
            'max_learners' => $this->maxLearners,
            'features' => $this->features,
            'notes' => $this->notes,
            'grace_ends_on' => $this->graceEndsOn()->toDateString(),
            'in_grace' => $this->inGrace($at),
            'lapsed' => $this->lapsed($at),
        ];
    }
}

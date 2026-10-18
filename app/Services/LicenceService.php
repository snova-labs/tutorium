<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;
use App\Support\Licensing\Licence;
use App\Support\Licensing\LicenceFile;
use App\Support\Licensing\LicenceProblem;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * What the licence on this install says, and what it is time to tell somebody about.
 *
 * Only self-hosted installs have a licence; in cloud mode every method here says so by answering
 * null, and nothing changes. Reading the file is deliberately the one and only thing that happens:
 * no call home, no activation, no heartbeat. An install with no route to the internet behaves
 * identically to one sitting next to us (NFR-PORT-2).
 */
final class LicenceService
{
    private ?Licence $licence = null;

    private ?string $problem = null;

    private bool $read = false;

    public function __construct(
        private readonly MeteringService $metering,
        private readonly TenantContext $tenancy,
    ) {}

    public function selfHosted(): bool
    {
        return config('platform.mode') === 'self_hosted';
    }

    /** The licence, or null when there is no licence to be had — cloud mode, or a file we refuse. */
    public function licence(): ?Licence
    {
        $this->load();

        return $this->licence;
    }

    /** Why there is no usable licence, in words an administrator can act on. */
    public function problem(): ?string
    {
        $this->load();

        return $this->problem;
    }

    /**
     * Refuse a new enrollment on a licence that has run out for good.
     *
     * The cap itself is enforced through the entitlement layer, the same as a cloud plan's. This
     * is only the expiry, and only ever for the act of adding somebody new: a teacher recording
     * attendance, a coordinator finishing a register, an administrator exporting the lot — all
     * still work, on an expired licence as on a lapsed one. Taking the register away from people
     * who are already in the room is not a collections strategy (FR-ENT-3).
     */
    public function guardNewEnrollment(?CarbonImmutable $at = null): void
    {
        if (! $this->selfHosted()) {
            return;
        }

        $licence = $this->licence();

        if ($licence === null) {
            throw ValidationException::withMessages([
                'licence' => $this->problem ?? 'This install has no valid licence, so it cannot take new enrollments.',
            ]);
        }

        if ($licence->lapsed($at)) {
            throw ValidationException::withMessages([
                'licence' => sprintf(
                    'The licence expired on %s and its %d-day grace period ended on %s. '
                    .'Everything recorded stays readable and exportable; renewing the licence takes new enrollments again.',
                    $licence->expiresOn->toDateString(),
                    $licence->graceDays,
                    $licence->graceEndsOn()->toDateString(),
                ),
            ]);
        }
    }

    /**
     * The licence as a screen or a command shows it, warning included. Null in cloud mode.
     *
     * @return array<string, mixed>|null
     */
    public function status(?int $learnersNow = null, ?CarbonImmutable $at = null): ?array
    {
        if (! $this->selfHosted()) {
            return null;
        }

        $licence = $this->licence();
        $learnersNow ??= $this->learnersNow();

        return [
            'ok' => $licence !== null && ! $licence->lapsed($at),
            'problem' => $this->problem,
            'learners_now' => $learnersNow,
            'places_left' => $licence?->maxLearners === null || $learnersNow === null
                ? null
                : max(0, $licence->maxLearners - $learnersNow),
            'warning' => $this->warning($learnersNow, $at),
            'licence' => $licence?->toArray($at),
        ];
    }

    /**
     * The one sentence worth putting in front of somebody today, or none.
     *
     * Ordered by what it costs to find out too late: a licence nobody can read, then one that has
     * stopped working, then one about to, then a cap filling up. A warning that arrives on the day
     * the cap is reached is not a warning, which is why it starts well before.
     */
    public function warning(?int $learnersNow = null, ?CarbonImmutable $at = null): ?string
    {
        if (! $this->selfHosted()) {
            return null;
        }

        $licence = $this->licence();

        if ($licence === null) {
            return $this->problem;
        }

        if ($licence->lapsed($at)) {
            return sprintf(
                'The licence expired on %s and its grace period ended on %s. New enrollments are refused; '
                .'attendance, grades, reports and exports all still work.',
                $licence->expiresOn->toDateString(),
                $licence->graceEndsOn()->toDateString(),
            );
        }

        if ($licence->inGrace($at)) {
            return sprintf(
                'The licence expired on %s. This install keeps working until %s, and then stops taking new '
                .'enrollments. Nothing already recorded is affected.',
                $licence->expiresOn->toDateString(),
                $licence->graceEndsOn()->toDateString(),
            );
        }

        $days = $licence->daysLeft($at);

        if ($days <= 30) {
            return sprintf(
                'The licence runs out on %s, in %s.',
                $licence->expiresOn->toDateString(),
                $days === 1 ? 'a day' : sprintf('%d days', $days),
            );
        }

        // No count to hand and none to be had means the expiry had nothing to add; a cap warning
        // without a count would be an invention.
        return $learnersNow === null ? null : $this->capWarning($licence, $learnersNow);
    }

    /**
     * The live learner count, from wherever this is being asked.
     *
     * A request already knows its academy. A command run on the server does not, and a
     * self-hosted install has exactly one, so it is read directly rather than demanded of the
     * caller — otherwise `platform:licence` could not tell anybody how full their cap is.
     */
    private function learnersNow(): ?int
    {
        $tenant = $this->tenancy->get()
            ?? $this->tenancy->withoutScoping(fn (): ?Tenant => Tenant::query()->orderBy('id')->first());

        return $tenant === null ? null : $this->metering->activeLearners($tenant);
    }

    private function capWarning(Licence $licence, int $learnersNow): ?string
    {
        if ($licence->maxLearners === null || $licence->maxLearners === 0) {
            return null;
        }

        if ($learnersNow >= $licence->maxLearners) {
            return sprintf(
                'All %d learner places on the licence are in use. Nobody new can be enrolled until a place frees '
                .'up or the licence is raised; everyone already enrolled carries on as normal.',
                $licence->maxLearners,
            );
        }

        $used = (int) round(($learnersNow / $licence->maxLearners) * 100);

        if ($used < (int) config('licence.warn_at_percent', 90)) {
            return null;
        }

        return sprintf(
            '%d of the %d learner places on the licence are in use.',
            $learnersNow,
            $licence->maxLearners,
        );
    }

    private function load(): void
    {
        if ($this->read) {
            return;
        }

        $this->read = true;

        if (! $this->selfHosted()) {
            return;
        }

        try {
            $this->licence = LicenceFile::read(
                (string) config('licence.path'),
                (string) config('licence.public_key'),
                (int) config('licence.grace_days', 30),
            );
        } catch (LicenceProblem $e) {
            $this->problem = $e->getMessage();
        }
    }
}

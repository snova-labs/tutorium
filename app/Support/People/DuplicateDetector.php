<?php

declare(strict_types=1);

namespace App\Support\People;

use App\Models\Guardian;
use App\Models\Learner;
use Illuminate\Support\Collection;

/**
 * Finds learners who may already exist before a new one is created.
 *
 * Front desks create duplicates constantly — a parent phones, then emails, then walks in, and the
 * same child is entered three times with three spellings. The damage is not the extra row: it is
 * that attendance and grades split across two records, and the report sent home is wrong.
 *
 * This warns and offers the match; it never blocks. Two children in one family genuinely can
 * share a name, and a system that refuses to believe that is worse than one that asks.
 */
final class DuplicateDetector
{
    /** @return Collection<int, DuplicateCandidate> */
    public function check(string $name, ?string $guardianEmail = null, ?string $guardianPhone = null, ?\DateTimeInterface $dateOfBirth = null): Collection
    {
        $candidates = collect();
        $normalised = $this->normalise($name);

        $byName = Learner::query()
            ->with('guardians')
            ->get()
            ->filter(fn (Learner $l) => $this->normalise($l->legal_name) === $normalised
                || ($l->preferred_name !== null && $this->normalise($l->preferred_name) === $normalised));

        foreach ($byName as $learner) {
            $reasons = ['same name'];
            $strong = false;

            if ($dateOfBirth !== null && $learner->date_of_birth !== null
                && $learner->date_of_birth->isSameDay($dateOfBirth)) {
                $reasons[] = 'same date of birth';
                $strong = true;
            }

            if ($this->sharesGuardian($learner, $guardianEmail, $guardianPhone)) {
                $reasons[] = 'same guardian contact';
                $strong = true;
            }

            $candidates->push(new DuplicateCandidate($learner, $reasons, $strong));
        }

        // A guardian contact match on a different name is worth surfacing too — it is usually a
        // sibling being added, which is fine, but occasionally a nickname being entered twice.
        if ($guardianEmail !== null || $guardianPhone !== null) {
            $siblings = $this->learnersOfGuardian($guardianEmail, $guardianPhone)
                ->reject(fn (Learner $l) => $candidates->contains(
                    fn (DuplicateCandidate $c) => $c->learner->is($l),
                ));

            foreach ($siblings as $learner) {
                $candidates->push(new DuplicateCandidate($learner, ['same guardian contact'], false));
            }
        }

        return $candidates->values();
    }

    private function sharesGuardian(Learner $learner, ?string $email, ?string $phone): bool
    {
        if ($email === null && $phone === null) {
            return false;
        }

        return $learner->guardians->contains(function (Guardian $g) use ($email, $phone): bool {
            if ($email !== null && in_array(strtolower($email), array_filter([
                strtolower((string) $g->email), strtolower((string) $g->secondary_email),
            ]), true)) {
                return true;
            }

            return $phone !== null && $this->digits($phone) !== '' && $this->digits((string) $g->phone) === $this->digits($phone);
        });
    }

    /** @return Collection<int, Learner> */
    private function learnersOfGuardian(?string $email, ?string $phone): Collection
    {
        $guardians = Guardian::query()
            ->when($email !== null, fn ($q) => $q->orWhere('email', $email)->orWhere('secondary_email', $email))
            ->when($phone !== null, fn ($q) => $q->orWhere('phone', $phone))
            ->with('learners')
            ->get();

        return $guardians->flatMap->learners->unique('id')->values();
    }

    /** Case, spacing and punctuation are noise; two spellings of the same name should match. */
    private function normalise(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^\p{L}\p{N}\s]/u', '', $value) ?? $value;

        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }
}

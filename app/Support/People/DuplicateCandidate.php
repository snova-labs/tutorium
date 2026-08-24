<?php

declare(strict_types=1);

namespace App\Support\People;

use App\Models\Learner;
use Illuminate\Contracts\Support\Arrayable;

/**
 * A learner who might already be the person someone is about to add.
 *
 * @implements Arrayable<string, mixed>
 */
final class DuplicateCandidate implements Arrayable
{
    /** @param array<int, string> $reasons */
    public function __construct(
        public readonly Learner $learner,
        public readonly array $reasons,
        public readonly bool $isStrong,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->learner->getKey(),
            'number' => $this->learner->number,
            'name' => $this->learner->displayName(),
            'enrolled_in' => $this->learner->enrollments()->count(),
            'reasons' => $this->reasons,
            'confidence' => $this->isStrong ? 'likely the same person' : 'possibly the same person',
        ];
    }
}

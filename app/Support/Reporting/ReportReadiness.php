<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Whether a report can honestly be sent yet.
 *
 * Unmarked registers and ungraded work are gaps, not zeroes. A report generated over them is not
 * wrong so much as misleading, so the gaps are named and the send is blocked unless someone
 * deliberately overrides.
 *
 * @implements Arrayable<string, mixed>
 */
final class ReportReadiness implements Arrayable
{
    /** @param array<int, string> $gaps */
    public function __construct(
        public readonly bool $isReady,
        public readonly array $gaps,
    ) {}

    public static function ready(): self
    {
        return new self(true, []);
    }

    /** @param array<int, string> $gaps */
    public static function incomplete(array $gaps): self
    {
        return new self(false, $gaps);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['is_ready' => $this->isReady, 'gaps' => $this->gaps];
    }
}

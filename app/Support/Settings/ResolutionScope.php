<?php

declare(strict_types=1);

namespace App\Support\Settings;

/**
 * Describes where a setting is being read from, so the resolver knows which chain to walk.
 * Built with named constructors rather than a bag of nullable arguments, because a scope with
 * a batch but no branch is meaningless and should not be expressible.
 */
final class ResolutionScope
{
    private function __construct(
        public readonly ?int $brandId = null,
        public readonly ?int $branchId = null,
        public readonly ?int $courseId = null,
        public readonly ?int $batchId = null,
    ) {}

    public static function tenant(): self
    {
        return new self;
    }

    public static function brand(int $brandId): self
    {
        return new self(brandId: $brandId);
    }

    public static function branch(int $branchId, ?int $brandId = null): self
    {
        return new self(brandId: $brandId, branchId: $branchId);
    }

    public static function course(int $courseId, ?int $brandId = null): self
    {
        return new self(brandId: $brandId, courseId: $courseId);
    }

    public static function batch(int $batchId, ?int $courseId = null, ?int $branchId = null, ?int $brandId = null): self
    {
        return new self(brandId: $brandId, branchId: $branchId, courseId: $courseId, batchId: $batchId);
    }

    /**
     * The lookup order, most specific first.
     *
     * @return array<int, array{0: string, 1: ?int}>
     */
    public function chain(): array
    {
        $chain = [];

        if ($this->batchId !== null) {
            $chain[] = ['batch', $this->batchId];
        }

        if ($this->courseId !== null) {
            $chain[] = ['course', $this->courseId];
        }

        if ($this->branchId !== null) {
            $chain[] = ['branch', $this->branchId];
        }

        if ($this->brandId !== null) {
            $chain[] = ['brand', $this->brandId];
        }

        $chain[] = ['tenant', null];

        return $chain;
    }

    public function mostSpecificType(): string
    {
        return $this->chain()[0][0];
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Eloquent\Model;

/**
 * A running list of everything a sample-data load created.
 *
 * A small object rather than an array passed between methods, because an array passed by value
 * into a helper and returned again silently loses whatever a closure appended to the original —
 * and the failure mode of a manifest that quietly loses entries is orphaned demo records that the
 * clean-up button will not remove.
 */
final class SampleManifest
{
    /** @var array<int, array{0: class-string, 1: int|string}> */
    private array $entries = [];

    /**
     * @template T of Model
     *
     * @param T $model
     * @return T
     */
    public function record(Model $model): Model
    {
        $this->entries[] = [$model::class, $model->getKey()];

        return $model;
    }

    /** @return array<int, array{0: class-string, 1: int|string}> */
    public function all(): array
    {
        return $this->entries;
    }

    public function count(): int
    {
        return count($this->entries);
    }
}

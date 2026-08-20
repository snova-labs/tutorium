<?php

declare(strict_types=1);

namespace App\Support\Settings;

use Illuminate\Contracts\Support\Arrayable;

/**
 * A setting value together with its provenance, for screens that must show users where a value
 * came from and offer "override here" or "revert to inherited".
 *
 * @implements Arrayable<string, mixed>
 */
final class ResolvedSetting implements Arrayable
{
    public function __construct(
        public readonly string $key,
        public readonly mixed $value,
        public readonly string $source,
        public readonly ?int $sourceId,
        public readonly bool $isInherited,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'value' => $this->value,
            'source' => $this->source,
            'source_id' => $this->sourceId,
            'is_inherited' => $this->isInherited,
        ];
    }
}

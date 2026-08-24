<?php

declare(strict_types=1);

namespace App\Support\Presets;

use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Reads the preset catalogue.
 *
 * Presets live in configuration rather than in the database, because they are versioned product
 * decisions rather than customer data — a change to one is a release, and being able to see that
 * change in a diff matters more than being able to edit it at runtime. What a *tenant* does after
 * a preset is applied is ordinary editable configuration.
 */
final class PresetRepository
{
    /** @return Collection<string, PresetDefinition> */
    public function all(): Collection
    {
        $defaults = config('presets.defaults', []);

        return collect(config('presets.catalogue', []))
            ->map(fn (array $payload, string $code) => new PresetDefinition(
                $code,
                $this->merge($defaults, $payload),
            ));
    }

    public function find(string $code): PresetDefinition
    {
        return $this->all()->get($code)
            ?? throw new RuntimeException("There is no preset called [{$code}].");
    }

    public function exists(string $code): bool
    {
        return $this->all()->has($code);
    }

    /**
     * Shallow merge on purpose.
     *
     * A preset that lists assessment types means "these, not the defaults plus these" — a language
     * school that declares Speaking, Writing, Listening and Reading should not silently also get
     * Homework and Quiz. Only the keyed sections merge.
     *
     * @param array<string, mixed> $defaults
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function merge(array $defaults, array $payload): array
    {
        $merged = array_merge($defaults, $payload);

        foreach (['terminology', 'modules', 'settings', 'sequences', 'email_templates'] as $section) {
            $merged[$section] = array_merge($defaults[$section] ?? [], $payload[$section] ?? []);
        }

        return $merged;
    }
}

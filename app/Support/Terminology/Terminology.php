<?php

declare(strict_types=1);

namespace App\Support\Terminology;

use App\Models\TerminologyOverride;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * What this tenant calls things.
 *
 * Resolved once per request and cached, because a single screen asks for the same noun dozens of
 * times. The canonical keys never change — the API, the database and these documents all use
 * "learner" regardless of what a customer sees (FR-CFG-4).
 */
final class Terminology
{
    /** @var array<string, array{singular: string, plural: string}>|null */
    private ?array $terms = null;

    /** The words shipped with the product, used when a tenant has not overridden one. */
    private const CANONICAL = [
        'learner' => ['Learner', 'Learners'],
        'guardian' => ['Guardian', 'Guardians'],
        'batch' => ['Batch', 'Batches'],
        'course' => ['Course', 'Courses'],
        'session' => ['Session', 'Sessions'],
        'assessment' => ['Assessment', 'Assessments'],
        'period' => ['Period', 'Periods'],
    ];

    public function __construct(private readonly TenantContext $tenancy) {}

    public function singular(string $key): string
    {
        return $this->load()[$key]['singular'] ?? self::CANONICAL[$key][0] ?? Str::headline($key);
    }

    public function plural(string $key): string
    {
        return $this->load()[$key]['plural'] ?? self::CANONICAL[$key][1] ?? Str::plural(Str::headline($key));
    }

    /** "3 students" / "1 student" — pluralised by the tenant's own word, not by adding an s. */
    public function count(string $key, int $count): string
    {
        return $count.' '.($count === 1 ? $this->singular($key) : $this->plural($key));
    }

    public function lower(string $key, bool $plural = false): string
    {
        return Str::lower($plural ? $this->plural($key) : $this->singular($key));
    }

    /**
     * The nouns a tenant may rename.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys(self::CANONICAL);
    }

    /**
     * The words shipped with the product, so a screen can offer "put it back".
     *
     * @return array<string, array{singular: string, plural: string}>
     */
    public function defaults(): array
    {
        return array_map(fn (array $t) => ['singular' => $t[0], 'plural' => $t[1]], self::CANONICAL);
    }

    /** @return array<string, array{singular: string, plural: string}> */
    public function all(): array
    {
        $terms = [];

        foreach (array_keys(self::CANONICAL) as $key) {
            $terms[$key] = ['singular' => $this->singular($key), 'plural' => $this->plural($key)];
        }

        return $terms;
    }

    /** @param array<string, array{0: string, 1: string}> $terms */
    public function set(array $terms): void
    {
        foreach ($terms as $key => [$singular, $plural]) {
            TerminologyOverride::query()->updateOrCreate(
                ['term_key' => $key, 'locale' => null],
                ['singular' => $singular, 'plural' => $plural],
            );
        }

        $this->forget();
    }

    public function forget(): void
    {
        $this->terms = null;
    }

    /** @return array<string, array{singular: string, plural: string}> */
    private function load(): array
    {
        if ($this->terms !== null) {
            return $this->terms;
        }

        if (! $this->tenancy->check()) {
            return $this->terms = [];
        }

        return $this->terms = TerminologyOverride::query()
            ->whereNull('locale')
            ->get()
            ->mapWithKeys(fn (TerminologyOverride $t) => [
                $t->term_key => ['singular' => $t->singular, 'plural' => $t->plural],
            ])
            ->all();
    }
}

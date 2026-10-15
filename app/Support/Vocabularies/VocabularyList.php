<?php

declare(strict_types=1);

namespace App\Support\Vocabularies;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * One of the academy's small lists, as config/vocabularies.php declares it.
 *
 * A typed object rather than the raw array, so the controller, the service and the tests all read
 * the same five things out of it and a typo in a key fails here instead of three layers down.
 *
 * @template TModel of Model
 */
final class VocabularyList
{
    /**
     * @param class-string<TModel> $model
     * @param array{field: string, label: string, off: string, help: string, default: bool} $flag
     * @param array<class-string<Model>, string> $uses
     */
    private function __construct(
        public readonly string $key,
        public readonly string $model,
        public readonly string $name,
        public readonly string $singular,
        public readonly string $about,
        public readonly array $flag,
        public readonly bool $deactivates,
        public readonly array $uses,
    ) {}

    /**
     * Every list, in the order the configuration names them.
     *
     * @return array<string, self<Model>>
     */
    public static function all(): array
    {
        $lists = [];

        foreach ((array) config('vocabularies.lists') as $key => $definition) {
            $lists[(string) $key] = self::fromDefinition((string) $key, (array) $definition);
        }

        return $lists;
    }

    /** @return self<Model>|null */
    public static function find(string $key): ?self
    {
        return self::all()[$key] ?? null;
    }

    /** @return self<Model> */
    public static function findOrFail(string $key): self
    {
        return self::find($key) ?? throw new InvalidArgumentException("There is no list called [{$key}].");
    }

    /** The flag's name as the API and the form use it. */
    public function flagField(): string
    {
        return $this->flag['field'];
    }

    /**
     * @param array{model: class-string<Model>, name: string, singular: string, about: string, flag: array{field: string, label: string, off: string, help: string, default: bool}, deactivates: bool, uses: array<class-string<Model>, string>} $definition
     * @return self<Model>
     */
    private static function fromDefinition(string $key, array $definition): self
    {
        return new self(
            key: $key,
            model: $definition['model'],
            name: $definition['name'],
            singular: $definition['singular'],
            about: $definition['about'],
            flag: $definition['flag'],
            deactivates: $definition['deactivates'],
            uses: $definition['uses'],
        );
    }
}

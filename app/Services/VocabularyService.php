<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Vocabularies\VocabularyList;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Adding to and tidying the academy's own lists (config/vocabularies.php).
 *
 * The rules that matter are about not breaking what points at a term:
 *
 *   - a code is generated from the name, because a code is for imports and integrations and
 *     nobody should have to invent one to add "Trial lesson";
 *   - a code never changes once it exists, for the same reason;
 *   - a term something has used is never deleted. Where the list supports it the term is switched
 *     off instead, which keeps the registers and notes that point at it readable.
 */
final class VocabularyService
{
    /**
     * @param VocabularyList<Model> $list
     * @param array{name: string, color?: ?string, flag?: bool, sort?: ?int} $input
     */
    public function create(VocabularyList $list, array $input): Model
    {
        $model = $list->model;

        $term = new $model([
            'name' => trim($input['name']),
            'code' => $this->uniqueCode($list, $input['name']),
            $list->flagField() => $input['flag'] ?? $list->flag['default'],
            'color' => $this->color($input['color'] ?? null),
            'sort' => $input['sort'] ?? $this->nextSort($list),
        ]);

        $term->save();

        return $term;
    }

    /**
     * Everything but the code, which stays as it was.
     *
     * @param VocabularyList<Model> $list
     * @param array{name?: string, color?: ?string, flag?: bool, sort?: ?int, is_active?: bool} $input
     */
    public function update(VocabularyList $list, Model $term, array $input): Model
    {
        if (array_key_exists('name', $input)) {
            $term->setAttribute('name', trim($input['name']));
        }

        if (array_key_exists('color', $input)) {
            $term->setAttribute('color', $this->color($input['color']));
        }

        if (array_key_exists('flag', $input)) {
            $term->setAttribute($list->flagField(), $input['flag']);
        }

        if (array_key_exists('sort', $input) && $input['sort'] !== null) {
            $term->setAttribute('sort', $input['sort']);
        }

        if (array_key_exists('is_active', $input) && $list->deactivates) {
            $term->setAttribute('is_active', $input['is_active']);
        }

        $term->save();

        return $term;
    }

    /**
     * Remove a term nothing has used. Anything else is refused with what is using it, because the
     * database would refuse it anyway (every one of these is a restricted foreign key) and a
     * message naming the records is more use than a constraint violation.
     *
     * @param VocabularyList<Model> $list
     */
    public function remove(VocabularyList $list, Model $term): void
    {
        $used = $this->usage($list, $term);

        if ($used > 0) {
            throw ValidationException::withMessages([
                'term' => $list->deactivates
                    ? sprintf('%s is in use %d %s, so it cannot be removed. Switch it off instead: it stays readable on what already used it, and stops being offered.', $term->getAttribute('name'), $used, $used === 1 ? 'place' : 'places')
                    : sprintf('%s is in use %d %s, so it cannot be removed. Rename it if it is wrong.', $term->getAttribute('name'), $used, $used === 1 ? 'place' : 'places'),
            ]);
        }

        $term->delete();
    }

    /**
     * How many records point at this term.
     *
     * @param VocabularyList<Model> $list
     */
    public function usage(VocabularyList $list, Model $term): int
    {
        $total = 0;

        foreach ($list->uses as $model => $column) {
            /** @var class-string<Model> $model */
            $total += (int) $model::query()->where($column, $term->getKey())->count();
        }

        return $total;
    }

    /** @param VocabularyList<Model> $list */
    private function nextSort(VocabularyList $list): int
    {
        $model = $list->model;

        return ((int) $model::query()->max('sort')) + 10;
    }

    /**
     * A code from the name: TRIAL_LESSON. Suffixed rather than refused on a clash, so adding
     * "Trial" twice in two years is somebody's problem to rename, not an error to decipher.
     *
     * @param VocabularyList<Model> $list
     */
    private function uniqueCode(VocabularyList $list, string $name): string
    {
        $model = $list->model;
        $base = Str::upper(Str::snake(Str::ascii(trim($name)))) ?: 'TERM';
        $base = Str::limit(preg_replace('/[^A-Z0-9_]/', '', $base) ?: 'TERM', 28, '');
        $code = $base;
        $suffix = 2;

        while ($model::query()->where('code', $code)->exists()) {
            $code = Str::limit($base, 28 - strlen((string) $suffix), '').'_'.$suffix++;
        }

        return $code;
    }

    /** A colour the interface can use, or none at all. */
    private function color(?string $color): ?string
    {
        $color = trim((string) $color);

        if ($color === '') {
            return null;
        }

        return preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color) === 1
            ? Str::lower($color)
            : throw ValidationException::withMessages(['color' => 'A colour looks like #1B5FAA.']);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreVocabularyTermRequest;
use App\Services\VocabularyService;
use App\Support\Vocabularies\VocabularyList;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The academy's own small lists: session types, note categories, learner statuses.
 *
 * One controller for all three because they are the same shape — a name, a colour, one flag that
 * changes behaviour, an order — and config/vocabularies.php is where each one's meaning lives.
 * The list key is part of the path (`vocabularies/session-types`), and an unknown key is a 404
 * rather than a validation error: it is a route that does not exist.
 */
final class VocabularyController extends Controller
{
    public function __construct(private readonly VocabularyService $service) {}

    /** Every list with its terms, and what each list's flag means. */
    public function index(Request $request): JsonResponse
    {
        $out = [];

        foreach (VocabularyList::all() as $list) {
            // A list the user may not read is left out rather than refused: the screen shows what
            // this person can work with, and a teacher reading note categories is not an error.
            if ($request->user()->cannot('viewAny', $list->model)) {
                continue;
            }

            $out[] = [
                'key' => $list->key,
                'name' => $list->name,
                'singular' => $list->singular,
                'about' => $list->about,
                'flag' => $list->flag,
                'deactivates' => $list->deactivates,
                'can_manage' => $request->user()->can('create', $list->model),
                'terms' => $this->terms($list),
            ];
        }

        return response()->json(['data' => $out]);
    }

    public function store(StoreVocabularyTermRequest $request, string $list): JsonResponse
    {
        $vocabulary = $this->list($list);
        abort_unless($request->user()->can('create', $vocabulary->model), 403);

        /** @var array{name: string, color?: ?string, flag?: bool, sort?: ?int} $input */
        $input = $request->validated();
        $term = $this->service->create($vocabulary, $input);

        return response()->json([
            'data' => $this->term($vocabulary, $term),
            'meta' => ['message' => sprintf('Added %s.', $term->getAttribute('name'))],
        ], 201);
    }

    public function update(StoreVocabularyTermRequest $request, string $list, int $term): JsonResponse
    {
        $vocabulary = $this->list($list);
        $record = $this->findTerm($vocabulary, $term);
        abort_unless($request->user()->can('update', $record), 403);

        /** @var array{name?: string, color?: ?string, flag?: bool, sort?: ?int, is_active?: bool} $input */
        $input = $request->validated();
        $record = $this->service->update($vocabulary, $record, $input);

        return response()->json([
            'data' => $this->term($vocabulary, $record),
            'meta' => ['message' => sprintf('Saved %s.', $record->getAttribute('name'))],
        ]);
    }

    public function destroy(Request $request, string $list, int $term): JsonResponse
    {
        $vocabulary = $this->list($list);
        $record = $this->findTerm($vocabulary, $term);
        abort_unless($request->user()->can('delete', $record), 403);

        $name = (string) $record->getAttribute('name');
        // Refused with a message naming what uses it when anything does.
        $this->service->remove($vocabulary, $record);

        return response()->json(['meta' => ['message' => sprintf('Removed %s.', $name)]]);
    }

    /** @return VocabularyList<Model> */
    private function list(string $key): VocabularyList
    {
        return VocabularyList::find($key) ?? throw new NotFoundHttpException("There is no list called [{$key}].");
    }

    /**
     * Tenant-scoped underneath, so another academy's term is a 404 here and never a 403.
     *
     * @param VocabularyList<Model> $list
     */
    private function findTerm(VocabularyList $list, int $id): Model
    {
        $model = $list->model;

        return $model::query()->findOr($id, fn () => throw new NotFoundHttpException);
    }

    /**
     * @param VocabularyList<Model> $list
     * @return list<array<string, mixed>>
     */
    private function terms(VocabularyList $list): array
    {
        $model = $list->model;

        return $model::query()
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(fn (Model $term) => $this->term($list, $term))
            ->values()
            ->all();
    }

    /**
     * @param VocabularyList<Model> $list
     * @return array<string, mixed>
     */
    private function term(VocabularyList $list, Model $term): array
    {
        return [
            'id' => $term->getKey(),
            'name' => $term->getAttribute('name'),
            'code' => $term->getAttribute('code'),
            'flag' => (bool) $term->getAttribute($list->flagField()),
            'color' => $term->getAttribute('color'),
            'sort' => (int) $term->getAttribute('sort'),
            'is_active' => $list->deactivates ? (bool) $term->getAttribute('is_active') : true,
            // What the screen needs to say whether this one can be removed, and why not.
            'used' => $this->service->usage($list, $term),
        ];
    }
}

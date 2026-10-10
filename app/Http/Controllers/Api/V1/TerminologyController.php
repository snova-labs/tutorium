<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Support\Terminology\Terminology;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What this tenant calls things.
 *
 * A client fetches this once and renders every label from it. The API's own field names stay
 * canonical — `learner_id` is `learner_id` whatever the customer calls the person — so renaming a
 * noun can never break an integration (FR-CFG-4).
 */
final class TerminologyController
{
    public function __construct(private readonly Terminology $terms) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->terms->all(),
            'meta' => ['defaults' => $this->terms->defaults()],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);

        $request->validate([
            // Only the nouns the product knows. Anything else would be stored and never shown.
            'terms' => ['required', 'array', 'min:1', 'array:'.implode(',', $this->terms->keys())],
            'terms.*' => ['required', 'array:singular,plural'],
            'terms.*.singular' => ['required', 'string', 'max:60'],
            'terms.*.plural' => ['required', 'string', 'max:60'],
        ]);

        $this->terms->set($request->collect('terms')
            ->map(fn (array $t) => [trim($t['singular']), trim($t['plural'])])
            ->all());

        return response()->json([
            'data' => [
                'terms' => $this->terms->all(),
                'message' => 'Saved. Your wording now appears everywhere, including on reports and in emails.',
            ],
        ]);
    }
}

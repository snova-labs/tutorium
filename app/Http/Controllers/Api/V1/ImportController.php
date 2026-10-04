<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Import;
use App\Models\Learner;
use App\Services\LearnerImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Bringing learners in from a spreadsheet: template, preview, commit or discard.
 *
 * Open to whoever may create learners, since an import is many creates at once.
 */
final class ImportController
{
    public function __construct(private readonly LearnerImportService $imports) {}

    public function template(Request $request): Response
    {
        abort_unless($request->user()->can('create', Learner::class), 403);

        return $this->download($this->imports->templateCsv(), 'learners-template.csv');
    }

    public function preview(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('create', Learner::class), 403);

        $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:xlsx,csv,txt'],
        ], [
            'file.mimes' => 'Upload an .xlsx or .csv file.',
            'file.max' => 'The file is larger than 5 MB. Split it and import the parts one at a time.',
        ]);

        $import = $this->imports->preview($request->file('file'), $request->user());

        return response()->json(['data' => $this->present($import)], 201);
    }

    public function show(Request $request, Import $import): JsonResponse
    {
        abort_unless($request->user()->can('create', Learner::class), 403);

        return response()->json(['data' => $this->present($import)]);
    }

    public function rejects(Request $request, Import $import): Response
    {
        abort_unless($request->user()->can('create', Learner::class), 403);

        return $this->download($this->imports->rejectsCsv($import), 'import-'.$import->getKey().'-blocked-rows.csv');
    }

    public function commit(Request $request, Import $import): JsonResponse
    {
        abort_unless($request->user()->can('create', Learner::class), 403);

        $validated = $request->validate(['include_possible_duplicates' => ['boolean']]);

        $import = $this->imports->commit($import, (bool) ($validated['include_possible_duplicates'] ?? false));
        $totals = $import->totals ?? [];

        return response()->json(['data' => $this->present($import) + [
            'message' => sprintf(
                '%d imported. %d blocked%s.',
                $totals['created'] ?? 0,
                $totals['blocked'] ?? 0,
                ($totals['skipped_duplicates'] ?? 0) > 0
                    ? ', '.$totals['skipped_duplicates'].' left out as possible duplicates'
                    : '',
            ),
        ]]);
    }

    public function discard(Request $request, Import $import): JsonResponse
    {
        abort_unless($request->user()->can('create', Learner::class), 403);

        return response()->json(['data' => $this->present($this->imports->discard($import))]);
    }

    /** @return array<string, mixed> */
    private function present(Import $import): array
    {
        return [
            'id' => $import->getKey(),
            'type' => $import->type,
            'status' => $import->status,
            'file' => $import->original_name,
            'totals' => $import->totals,
            'blocked' => $import->issues['blocked'] ?? [],
            'possible_duplicates' => $import->issues['possible_duplicates'] ?? [],
            'ignored_columns' => $import->issues['ignored_columns'] ?? [],
            'sample' => $import->issues['sample'] ?? [],
            'rejects_url' => ($import->totals['blocked'] ?? 0) > 0
                ? route('api.imports.rejects', $import)
                : null,
            'finished_at' => $import->finished_at?->toIso8601String(),
        ];
    }

    private function download(string $csv, string $filename): Response
    {
        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}

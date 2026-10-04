<?php

declare(strict_types=1);

namespace App\Filament\Resources\Batches\Pages;

use App\Filament\Resources\Batches\BatchResource;
use App\Models\Batch;
use App\Services\BatchService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class EditBatch extends EditRecord
{
    protected static string $resource = BatchResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        // Through the service, so the refusal to change a timezone once sessions
        // exist applies here exactly as it does on the API.
        if (! $record instanceof Batch) {
            throw new LogicException('This page edits a batch, not '.$record::class.'.');
        }

        return app(BatchService::class)->update($record, $data);
    }
}

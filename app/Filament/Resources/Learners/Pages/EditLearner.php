<?php

declare(strict_types=1);

namespace App\Filament\Resources\Learners\Pages;

use App\Filament\Resources\Learners\LearnerResource;
use App\Models\Learner;
use App\Services\LearnerService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class EditLearner extends EditRecord
{
    protected static string $resource = LearnerResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Archive')
                ->modalHeading('Archive this record')
                ->modalDescription('Every attendance record, grade, note and report is kept. '
                    .'They stop appearing in lists and stop counting toward your bill.'),
        ];
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        // Through the service, so the identifier stays immutable and the change
        // is audited the same way it would be from the API.
        if (! $record instanceof Learner) {
            throw new LogicException('This page edits a learner, not '.$record::class.'.');
        }

        return app(LearnerService::class)->update($record, $data);
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\Learners\Pages;

use App\Filament\Resources\Learners\LearnerResource;
use App\Support\Terminology\Terminology;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListLearners extends ListRecords
{
    protected static string $resource = LearnerResource::class;

    /** @return array<int, \Filament\Actions\Action> */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(fn () => 'Add a '.app(Terminology::class)->lower('learner')),
        ];
    }
}

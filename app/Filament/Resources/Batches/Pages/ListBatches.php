<?php

declare(strict_types=1);

namespace App\Filament\Resources\Batches\Pages;

use App\Filament\Resources\Batches\BatchResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListBatches extends ListRecords
{
    protected static string $resource = BatchResource::class;

    /** @return array<int, \Filament\Actions\Action> */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invitations\Pages;

use App\Filament\Resources\Invitations\InvitationResource;
use Filament\Resources\Pages\ListRecords;

final class ListInvitations extends ListRecords
{
    protected static string $resource = InvitationResource::class;
}

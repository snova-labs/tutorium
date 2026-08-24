<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invitations;

use App\Filament\Resources\Invitations\Pages\ListInvitations;
use App\Models\Invitation;
use App\Models\User;
use App\Services\InvitationService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Spatie\Permission\Models\Role;

/**
 * Staff invitations.
 *
 * The role selector only offers what the person signing in may actually grant.
 * The escalation guard in InvitationService is the real control; this exists so
 * that a form never offers a choice the server is about to refuse, which is a
 * frustrating way to learn about a permission boundary.
 */
final class InvitationResource extends Resource
{
    protected static ?string $model = Invitation::class;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationLabel = 'Invitations';

    protected static ?int $navigationSort = 32;

    public static function getNavigationGroup(): ?string
    {
        return 'Administration';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->components([
                    TextInput::make('email')
                        ->email()
                        ->required()
                        ->maxLength(190)
                        ->helperText('An invitation grants nothing until they accept it, and expires '
                            .'after '.config('signup.invitation_days', 7).' days.'),

                    TextInput::make('name')->label('Their name')->maxLength(120),

                    Select::make('role_name')
                        ->label('Role')
                        ->options(fn (): array => self::grantableRoles())
                        ->required()
                        ->helperText('Only roles you hold the permissions for are listed. You cannot '
                            .'invite someone into a role stronger than your own.'),

                    Toggle::make('scope_all_branches')
                        ->label('Every location')
                        ->live()
                        ->disabled(fn (): bool => ! (auth()->user()?->scope_all_branches ?? false))
                        ->helperText(fn (): ?string => (auth()->user()?->scope_all_branches ?? false)
                            ? null
                            : 'You can only invite people to the locations you can reach yourself.'),

                    CheckboxList::make('branch_ids')
                        ->label('Locations')
                        ->options(fn () => \App\Models\Branch::query()
                            ->where('is_active', true)->pluck('name', 'id'))
                        ->columns(2)
                        ->visible(fn ($get): bool => ! $get('scope_all_branches')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')->searchable()->sortable(),
                TextColumn::make('role_name')->label('Role')->badge()->color('gray'),

                TextColumn::make('status')
                    ->label('State')
                    ->state(fn (Invitation $record): string => $record->status())
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'accepted' => 'success',
                        'pending' => 'info',
                        'revoked' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('inviter.name')->label('Invited by')->toggleable(),

                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->date('j M Y')
                    ->color(fn (Invitation $record): string => $record->isOpen() ? 'gray' : 'danger'),

                TextColumn::make('send_count')
                    ->label('Sent')
                    ->alignEnd()
                    ->fontFamily('mono')
                    ->toggleable(),
            ])
            ->recordActions([
                Action::make('resend')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (Invitation $record): bool => $record->isOpen())
                    ->action(function (Invitation $record): void {
                        app(InvitationService::class)->resend($record);

                        Notification::make()->success()
                            ->title('Sent again with a fresh link')
                            // Said plainly: the previous link stops working, which
                            // matters if the person is holding an older email.
                            ->body('The previous link no longer works.')
                            ->send();
                    }),

                Action::make('revoke')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('The link stops working immediately. Nothing was created in '
                        .'their name, so there is nothing else to undo.')
                    ->visible(fn (Invitation $record): bool => $record->isOpen())
                    ->action(function (Invitation $record): void {
                        app(InvitationService::class)->revoke($record);

                        Notification::make()->success()->title('Withdrawn')->send();
                    }),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Invite someone')
                    ->using(function (array $data): Invitation {
                        return app(InvitationService::class)->invite(auth()->user(), $data);
                    })
                    ->successNotificationTitle('Invitation sent'),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('users.manage') ?? false;
    }

    /**
     * Roles this person may hand out.
     *
     * @return array<string, string>
     */
    private static function grantableRoles(): array
    {
        /** @var User|null $user */
        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        $ownerRole = (string) config('permissions.owner_role', 'Owner');

        if ($user->isOwner()) {
            return Role::query()->pluck('name', 'name')->all();
        }

        $held = $user->getAllPermissions()->pluck('name');

        return Role::query()->with('permissions')->get()
            // Only the owner can invite another owner, so it never appears here.
            ->reject(fn (Role $role) => strcasecmp($role->name, $ownerRole) === 0)
            ->filter(fn (Role $role) => $role->permissions->pluck('name')->diff($held)->isEmpty())
            ->pluck('name', 'name')
            ->all();
    }

    /** @return array<string, class-string> */
    public static function getPages(): array
    {
        return ['index' => ListInvitations::route('/')];
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Resources\Learners;

use App\Filament\Resources\Learners\Pages\CreateLearner;
use App\Filament\Resources\Learners\Pages\EditLearner;
use App\Filament\Resources\Learners\Pages\ListLearners;
use App\Models\Learner;
use App\Models\LearnerStatus;
use App\Support\Settings\SettingsResolver;
use App\Support\Terminology\Terminology;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Learners.
 *
 * Note the labels: every one comes from the tenant's own vocabulary rather than
 * a hard-coded string, so a corporate account reads "Participants" throughout
 * the panel and not only on the screens somebody remembered to change.
 */
final class LearnerResource extends Resource
{
    protected static ?string $model = Learner::class;

    // protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 10;

    public static function getModelLabel(): string
    {
        return app(Terminology::class)->lower('learner');
    }

    public static function getPluralModelLabel(): string
    {
        return app(Terminology::class)->lower('learner', plural: true);
    }

    public static function getNavigationGroup(): string
    {
        return 'People';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identity')
                ->description('Names are stored whole rather than split into first and last — that '
                    .'split loses double surnames, family-name-first ordering and single-name '
                    .'cultures, and cannot be recovered once it has parsed wrongly.')
                ->columns(2)
                ->components([
                    TextInput::make('legal_name')
                        ->label('Full name')
                        ->required()
                        ->maxLength(190)
                        ->columnSpanFull(),

                    TextInput::make('preferred_name')
                        ->label('Preferred name')
                        ->helperText('What they answer to, if it differs. Used on reports.')
                        ->maxLength(120),

                    TextInput::make('sort_name')
                        ->label('Sort as')
                        ->helperText('Leave blank to sort by the full name.')
                        ->maxLength(190),

                    DatePicker::make('date_of_birth')
                        ->label('Date of birth')
                        ->maxDate(now()),

                    TextInput::make('country')
                        ->label('Country')
                        ->length(2)
                        ->helperText('Two-letter code, e.g. NP or CA.'),

                    Select::make('home_timezone')
                        ->label('Home timezone')
                        ->options(fn () => collect(timezone_identifiers_list())
                            ->mapWithKeys(fn (string $tz) => [$tz => $tz]))
                        ->searchable()
                        ->helperText('Only needed when they are not in the same place as the class.'),
                ]),

            Section::make('Contact')
                ->columns(2)
                ->components([
                    TextInput::make('email')->email()->maxLength(190),
                    TextInput::make('phone')->tel()->maxLength(32),
                ]),

            Section::make('Status')
                ->columns(2)
                ->components([
                    Select::make('status_id')
                        ->label('Status')
                        ->relationship('status', 'name')
                        ->default(fn () => LearnerStatus::query()->where('code', 'ACTIVE')->value('id'))
                        ->required(),

                    Textarea::make('status_reason')
                        ->label('Reason')
                        ->helperText('Someone will read this in a year and need to understand it.')
                        ->rows(2),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label('Identifier')
                    // Monospace so identifiers line up down the column, which is
                    // the only way scanning a list of them is not miserable.
                    ->fontFamily('mono')
                    ->searchable()
                    ->size('sm'),

                TextColumn::make('legal_name')
                    ->label('Name')
                    ->description(fn (Learner $record): ?string => $record->preferred_name)
                    ->searchable(['legal_name', 'preferred_name'])
                    ->sortable(),

                TextColumn::make('guardians.name')
                    ->label(fn () => app(Terminology::class)->plural('guardian'))
                    ->listWithLineBreaks()
                    ->limitList(2)
                    ->toggleable()
                    // Hidden entirely when the account has switched guardians off,
                    // rather than showing an empty column for a concept they do
                    // not use.
                    ->visible(fn () => (bool) app(SettingsResolver::class)
                        ->get('people.guardians_enabled', null, true)),

                TextColumn::make('enrollments_count')
                    ->counts('enrollments')
                    ->label('Enrolments')
                    ->alignEnd()
                    ->fontFamily('mono'),

                TextColumn::make('status.name')
                    ->label('Status')
                    ->badge()
                    ->color(fn (Learner $record): string => $record->status?->is_terminal ? 'gray' : 'success'),

                TextColumn::make('created_at')
                    ->label('Added')
                    ->dateTime('j M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status_id')
                    ->label('Status')
                    ->relationship('status', 'name')
                    ->multiple()
                    ->preload(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Archiving only. A learner with academic history is never
                    // force-deleted from a list screen.
                    DeleteBulkAction::make()
                        ->label('Archive selected')
                        ->modalDescription('Archiving keeps every attendance record, grade and report. '
                            .'Nothing is removed.'),
                ]),
            ])
            ->defaultSort('legal_name')
            ->emptyStateHeading(fn () => 'No '.app(Terminology::class)->lower('learner', plural: true).' yet');
    }

    /**
     * Teachers see only the people in the classes they teach.
     *
     * The tenant scope is already applied globally; this is the second layer,
     * not the only one.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('status');
        $user = auth()->user();

        if ($user !== null && ! $user->can('learners.update')) {
            $query->whereHas('enrollments.batch.teachers', fn (Builder $q) => $q->whereKey($user->getKey()));
        }

        return $query;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('learners.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('learners.create') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('learners.update') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('learners.archive') ?? false;
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListLearners::route('/'),
            'create' => CreateLearner::route('/create'),
            'edit' => EditLearner::route('/{record}/edit'),
        ];
    }
}

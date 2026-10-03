<?php

declare(strict_types=1);

namespace App\Filament\Resources\Branches;

use App\Filament\Resources\Branches\Pages\ListBranches;
use App\Models\Branch;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Branches.
 *
 * A location's timezone and week structure are the origin of every schedule
 * beneath it, which makes this the highest-consequence form in the panel and
 * the one worth the most explanation on screen.
 */
final class BranchResource extends Resource
{
    protected static ?string $model = Branch::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    protected static ?string $navigationLabel = 'Locations';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return 'Administration';
    }

    public static function form(Schema $schema): Schema
    {
        $days = ['monday' => 'Monday', 'tuesday' => 'Tuesday', 'wednesday' => 'Wednesday',
            'thursday' => 'Thursday', 'friday' => 'Friday', 'saturday' => 'Saturday', 'sunday' => 'Sunday'];

        return $schema->components([
            Section::make()
                ->columns(2)
                ->components([
                    TextInput::make('name')->required()->maxLength(120),
                    TextInput::make('code')->required()->maxLength(32),
                    Select::make('brand_id')->relationship('brand', 'name')->required(),
                    Toggle::make('is_active')->default(true)
                        ->helperText('Switching this off stops new enrolments and keeps every record.'),
                ]),

            Section::make('Local rules')
                ->description('Classes here inherit these unless they override them. Getting the '
                    .'timezone wrong produces a schedule that looks entirely plausible and is an '
                    .'hour out for everybody.')
                ->columns(2)
                ->components([
                    Select::make('timezone')
                        ->options(fn () => collect(timezone_identifiers_list())
                            ->mapWithKeys(fn (string $tz) => [$tz => $tz]))
                        ->searchable()
                        ->required()
                        ->helperText('An IANA name such as Asia/Kathmandu. Daylight saving is handled '
                            .'per occurrence, so a class keeps its local time across a clock change.'),

                    Select::make('week_start')
                        ->label('Week starts on')
                        ->options($days)
                        ->default('monday')
                        ->required(),

                    CheckboxList::make('weekend_days')
                        ->label('Weekend')
                        ->options($days)
                        ->columns(4)
                        ->default(['saturday', 'sunday'])
                        ->columnSpanFull()
                        // The single most common way school software fails outside
                        // the country it was written in.
                        ->helperText('Used for calendar shading and working-day calculations. '
                            .'Friday and Saturday in much of the Gulf; Saturday only in Nepal.'),
                ]),

            Section::make('Contact')
                ->columns(2)
                ->components([
                    TextInput::make('email')->email()->maxLength(190),
                    TextInput::make('phone')->tel()->maxLength(32),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->fontFamily('mono')->size('sm'),

                TextColumn::make('timezone')
                    ->fontFamily('mono')
                    ->size('sm')
                    ->description(fn (Branch $record): string => now()->setTimezone($record->timezone)
                        ->format('H:i').' there now'),

                TextColumn::make('weekend_days')
                    ->label('Weekend')
                    ->formatStateUsing(fn (?array $state): string => collect($state ?? [])
                        ->map(fn (string $d) => ucfirst(substr($d, 0, 3)))->implode(', '))
                    ->badge()
                    ->color('gray'),

                TextColumn::make('batches_count')
                    ->counts('batches')
                    ->label('Classes')
                    ->alignEnd()
                    ->fontFamily('mono'),

                TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Archived')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
            ])
            ->recordActions([EditAction::make()])
            ->headerActions([CreateAction::make()])
            ->defaultSort('name');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('organisation.manage') ?? false;
    }

    /** @return array<string, class-string> */
    public static function getPages(): array
    {
        return ['index' => ListBranches::route('/')];
    }
}

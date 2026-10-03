<?php

declare(strict_types=1);

namespace App\Filament\Resources\Batches;

use App\Enums\BatchStatus;
use App\Enums\DeliveryMode;
use App\Filament\Resources\Batches\Pages\EditBatch;
use App\Filament\Resources\Batches\Pages\ListBatches;
use App\Models\Batch;
use App\Support\Terminology\Terminology;
use App\Support\Time\SessionGenerator;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;


/**
 * Batches.
 *
 * The interesting screen in the whole panel, because a batch's timezone is the
 * origin of every session, due date and period boundary beneath it — and
 * because generating sessions is an action rather than a form.
 */
final class BatchResource extends Resource
{
    protected static ?string $model = Batch::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 21;

    public static function getModelLabel(): string
    {
        return app(Terminology::class)->lower('batch');
    }

    public static function getPluralModelLabel(): string
    {
        return app(Terminology::class)->lower('batch', plural: true);
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Academic';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->components([
                    TextInput::make('name')->required()->maxLength(160)->columnSpanFull(),

                    Select::make('course_id')
                        ->label(fn () => ucfirst(app(Terminology::class)->lower('course')))
                        ->relationship('course', 'name')
                        ->required()
                        ->searchable(),

                    Select::make('branch_id')
                        ->label('Location')
                        ->relationship('branch', 'name')
                        ->required()
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set): void {
                            // A batch left without an explicit timezone follows its
                            // branch. Defaulting to UTC instead would schedule every
                            // session at the wrong hour and look correct doing it.
                            $branch = \App\Models\Branch::query()->find($state);

                            if ($branch !== null) {
                                $set('timezone', $branch->timezone);
                            }
                        }),

                    TextInput::make('code')
                        ->required()
                        ->maxLength(32)
                        ->helperText('Short and stable. It appears in exports and file paths.'),

                    Select::make('timezone')
                        ->label('Timezone')
                        ->options(fn () => collect(timezone_identifiers_list())
                            ->mapWithKeys(fn (string $tz) => [$tz => $tz]))
                        ->searchable()
                        ->required()
                        // The warning is on the field because the consequence is
                        // invisible: a wrong timezone produces a schedule that
                        // looks entirely plausible and is an hour out for everyone.
                        ->helperText('Every session, due date and reporting period under this '
                            .'follows this clock. Inherited from the location unless you change it.')
                        ->disabled(fn (?Batch $record): bool => $record?->sessions()->exists() ?? false)
                        ->hint(fn (?Batch $record): ?string => ($record?->sessions()->exists() ?? false)
                            ? 'Locked — sessions already exist'
                            : null),
                ]),

            Section::make('Dates')
                ->columns(3)
                ->components([
                    DatePicker::make('starts_on')->required(),
                    DatePicker::make('ends_on')->afterOrEqual('starts_on'),
                    TextInput::make('capacity')
                        ->numeric()
                        ->minValue(1)
                        ->helperText('Leave blank for no limit.'),
                ]),

            Section::make('Delivery')
                ->columns(2)
                ->components([
                    Select::make('delivery_mode')
                        ->options(collect(DeliveryMode::cases())
                            ->mapWithKeys(fn (DeliveryMode $m) => [$m->value => ucfirst(str_replace('_', ' ', $m->value))]))
                        ->default(DeliveryMode::InPerson->value)
                        ->required(),

                    Select::make('status')
                        ->options(collect(BatchStatus::cases())
                            ->mapWithKeys(fn (BatchStatus $s) => [$s->value => ucfirst($s->value)]))
                        ->default(BatchStatus::Planned->value)
                        ->required(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('course'))
            ->columns([
                TextColumn::make('name')
                    ->description(fn (Batch $record): string => $record->course->name)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('branch.name')->label('Location')->toggleable(),

                TextColumn::make('timezone')
                    ->label('Clock')
                    ->fontFamily('mono')
                    ->size('sm')
                    // The provenance device, in a table column: grey when it came
                    // from the location, amber when this batch set its own.
                    ->badge()
                    ->color(fn (Batch $record): string => $record->timezone === $record->branch?->timezone
                        ? 'gray'
                        : 'warning')
                    ->tooltip(fn (Batch $record): string => $record->timezone === $record->branch?->timezone
                        ? 'Inherited from '.$record->branch?->name
                        : 'Set on this '.app(Terminology::class)->lower('batch')),

                TextColumn::make('enrollments_count')
                    ->counts('enrollments')
                    ->label('Enrolled')
                    ->alignEnd()
                    ->fontFamily('mono'),

                TextColumn::make('sessions_count')
                    ->counts('sessions')
                    ->label('Sessions')
                    ->alignEnd()
                    ->fontFamily('mono'),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (Batch $record): string => match ($record->status) {
                        BatchStatus::Running => 'success',
                        BatchStatus::Planned => 'info',
                        default => 'gray',
                    }),
            ])
            ->recordActions([
                EditAction::make(),

                // Generation is an action, not a form: it is something you press
                // and it tells you what it did, including what it declined to do.
                Action::make('generate')
                    ->label('Generate sessions')
                    ->icon('heroicon-o-sparkles')
                    ->visible(fn (): bool => auth()->user()?->can('sessions.manage') ?? false)
                    ->schema([
                        DatePicker::make('from')
                            ->required()
                            ->default(fn (Batch $record) => $record->localNow()->startOfMonth()),
                        DatePicker::make('to')
                            ->required()
                            ->default(fn (Batch $record) => $record->localNow()->endOfMonth()),
                    ])
                    ->modalDescription('Running this twice is safe — anything already scheduled is '
                        .'left alone and reported as skipped.')
                    ->action(function (Batch $record, array $data): void {
                        $result = app(SessionGenerator::class)->generate(
                            $record,
                            CarbonImmutable::parse($data['from'], $record->timezone),
                            CarbonImmutable::parse($data['to'], $record->timezone),
                        );

                        $clockChanges = $result->skippedFor('clock_change');

                        Notification::make()
                            ->title($result->summary())
                            // A skipped clock-change date needs a person to resolve
                            // it, so it is persistent rather than a toast that
                            // disappears while they are reading it.
                            ->body($clockChanges === [] ? null : $clockChanges[0]['detail'])
                            ->persistent($clockChanges !== [])
                            ->color($clockChanges === [] ? 'success' : 'warning')
                            ->send();
                    }),
            ])
            ->defaultSort('starts_on', 'desc');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('batches.manage') ?? false;
    }

    /** @return array<string, class-string> */
    public static function getPages(): array
    {
        return [
            'index' => ListBatches::route('/'),
            'edit' => EditBatch::route('/{record}/edit'),
        ];
    }
}
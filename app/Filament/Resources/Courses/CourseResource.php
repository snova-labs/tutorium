<?php

declare(strict_types=1);

namespace App\Filament\Resources\Courses;

use App\Enums\PeriodType;
use App\Filament\Resources\Courses\Pages\ListCourses;
use App\Models\Brand;
use App\Models\Course;
use App\Services\CourseService;
use App\Support\Terminology\Terminology;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Courses.
 *
 * The screen where "adding a new programme" stops being a development request
 * and becomes data entry — which was the whole point of replacing the original
 * system.
 */
final class CourseResource extends Resource
{
    protected static ?string $model = Course::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?int $navigationSort = 20;

    public static function getModelLabel(): string
    {
        return app(Terminology::class)->lower('course');
    }

    public static function getPluralModelLabel(): string
    {
        return app(Terminology::class)->lower('course', plural: true);
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

                    Select::make('brand_id')
                        ->label('Brand')
                        ->relationship('brand', 'name')
                        ->required()
                        ->default(fn () => Brand::query()->where('is_default', true)->value('id')),

                    TextInput::make('code')->required()->maxLength(32),

                    Select::make('audience')
                        ->options([
                            'kids' => 'Kids', 'teens' => 'Teens',
                            'adults' => 'Adults', 'corporate' => 'Corporate',
                        ])
                        ->default('kids'),

                    Toggle::make('is_active')->default(true),

                    Textarea::make('description')->rows(3)->columnSpanFull(),
                ]),

            Section::make('Reporting')
                ->description('How this course divides time. Everything downstream — attendance '
                    .'percentages, period averages, reports — is computed inside these boundaries, '
                    .'in each class\'s own timezone.')
                ->columns(3)
                ->components([
                    Select::make('period_type')
                        ->label('Periods')
                        ->options(collect(PeriodType::cases())
                            ->mapWithKeys(fn (PeriodType $t) => [$t->value => $t->label()]))
                        ->default(PeriodType::Monthly->value)
                        ->live()
                        ->required()
                        // Refused outright rather than warned about: changing this
                        // after a report has gone out would move boundaries the
                        // report was built on.
                        ->disabled(fn (?Course $record): bool => $record?->reportingPeriods()
                            ->where('status', '!=', 'open')->exists() ?? false)
                        ->hint(fn (?Course $record): ?string => ($record?->reportingPeriods()
                            ->where('status', '!=', 'open')->exists() ?? false)
                            ? 'Locked — this course has already reported'
                            : null),

                    Select::make('period_anchor_month')
                        ->label('Year starts in')
                        ->options(collect(range(1, 12))
                            ->mapWithKeys(fn (int $m) => [$m => date('F', mktime(0, 0, 0, $m, 1))]))
                        ->default(1)
                        ->visible(fn ($get): bool => $get('period_type') === PeriodType::Quarter->value)
                        ->helperText('For a financial year that does not start in January.'),

                    TextInput::make('period_block_weeks')
                        ->label('Block length (weeks)')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(52)
                        ->default(4)
                        ->visible(fn ($get): bool => $get('period_type') === PeriodType::Block->value),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->fontFamily('mono')->size('sm'),
                TextColumn::make('audience')->badge()->color('gray'),
                TextColumn::make('period_type')
                    ->label('Periods')
                    ->formatStateUsing(fn (PeriodType $state): string => $state->label())
                    ->badge(),
                TextColumn::make('batches_count')
                    ->counts('batches')
                    ->label(fn () => ucfirst(app(Terminology::class)->lower('batch', plural: true)))
                    ->alignEnd()
                    ->fontFamily('mono'),
            ])
            ->recordActions([EditAction::make()->using(
                // Through the service, so the refusal to change the period type
                // after reporting applies here too.
                fn (Course $record, array $data): Course => app(CourseService::class)->update($record, $data),
            )])
            ->headerActions([CreateAction::make()])
            ->defaultSort('name');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('courses.manage') ?? false;
    }

    /** @return array<string, class-string> */
    public static function getPages(): array
    {
        return ['index' => ListCourses::route('/')];
    }
}

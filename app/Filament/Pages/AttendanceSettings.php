<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\AttendancePolicy;
use App\Models\Batch;
use App\Support\Attendance\AttendancePolicyResolver;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Attendance policy, with its provenance visible.
 *
 * The one screen where the product's central claim has to be legible: every
 * value shows which level supplied it, and changing one here is visibly a
 * decision about *this* level rather than an edit to a global setting
 * (FR-CFG-1).
 */
final class AttendanceSettings extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Attendance policy';

    protected static ?int $navigationSort = 40;

    protected string $view = 'filament.pages.attendance-settings';

    public ?int $batchId = null;

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('settings.manage') ?? false;
    }

    public function mount(): void
    {
        $this->batchId = Batch::query()->value('id');
        $this->loadPolicy();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Select::make('batchId')
                    ->label('Editing')
                    ->options(fn () => Batch::query()->pluck('name', 'id'))
                    ->live()
                    ->afterStateUpdated(fn () => $this->loadPolicy())
                    ->helperText('A change here applies to this one only. Anything left alone keeps '
                        .'following the level above it.'),

                Section::make()
                    ->components([
                        Toggle::make('is_compulsory')
                            ->label('Attendance is compulsory')
                            ->helperText('When off, attendance is still recorded and shown, but never '
                                .'penalises anyone — no at-risk flag, no effect on engagement.')
                            ->hint(fn () => $this->originHint('is_compulsory')),

                        Toggle::make('allow_late_join')
                            ->label('Late arrivals count as attended')
                            ->live()
                            // Said out loud, because it is the single most
                            // consequential switch on the screen and its effect is
                            // otherwise invisible until a report looks wrong.
                            ->helperText('This changes what existing marks mean. No mark is edited — '
                                .'a class already marked Late simply stops counting as attended.')
                            ->hint(fn () => $this->originHint('allow_late_join')),

                        TextInput::make('late_grace_min')
                            ->label('Grace period (minutes)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(120)
                            ->visible(fn (): bool => (bool) ($this->data['allow_late_join'] ?? false))
                            ->hint(fn () => $this->originHint('late_grace_min')),

                        TextInput::make('low_threshold_pct')
                            ->label('Low attendance threshold (%)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->helperText('Below this, someone appears on the at-risk list.')
                            ->hint(fn () => $this->originHint('low_threshold_pct')),
                    ]),
            ]);
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('revert')
                ->label('Follow the level above')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('This removes the override on this batch. It will follow its course '
                    .'again, including any future change made there.')
                ->action(function (): void {
                    AttendancePolicy::query()
                        ->where('scope_type', 'batch')
                        ->where('scope_id', $this->batchId)
                        ->delete();

                    app(AttendancePolicyResolver::class)->forget();
                    $this->loadPolicy();

                    Notification::make()->success()->title('Reverted — now following the course.')->send();
                }),

            Action::make('save')
                ->label('Save')
                ->action(function (): void {
                    AttendancePolicy::query()->updateOrCreate(
                        ['scope_type' => 'batch', 'scope_id' => $this->batchId],
                        collect($this->data)->only([
                            'is_compulsory', 'allow_late_join', 'late_grace_min', 'low_threshold_pct',
                        ])->all(),
                    );

                    app(AttendancePolicyResolver::class)->forget();
                    $this->loadPolicy();

                    Notification::make()
                        ->success()
                        ->title('Saved for this batch')
                        ->body('Existing marks are unchanged. What they mean may have changed.')
                        ->send();
                }),
        ];
    }

    /** @return array<string, mixed> */
    public function resolved(): array
    {
        $batch = Batch::query()->with('course')->find($this->batchId);

        if ($batch === null) {
            return [];
        }

        return app(AttendancePolicyResolver::class)->for($batch)->toArray();
    }

    private function originHint(string $key): string
    {
        $origins = $this->resolved()['origins'] ?? [];
        $level = $origins[$key] ?? 'system';

        return $level === 'batch' ? 'set here' : 'from '.$level;
    }

    private function loadPolicy(): void
    {
        $batch = Batch::query()->with('course')->find($this->batchId);

        if ($batch === null) {
            return;
        }

        app(AttendancePolicyResolver::class)->forget();
        $policy = app(AttendancePolicyResolver::class)->for($batch);

        $this->data = [
            'batchId' => $this->batchId,
            'is_compulsory' => $policy->isCompulsory,
            'allow_late_join' => $policy->allowLateJoin,
            'late_grace_min' => $policy->lateGraceMinutes,
            'low_threshold_pct' => $policy->lowThresholdPct,
        ];
    }
}

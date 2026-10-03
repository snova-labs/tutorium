<?php

declare(strict_types=1);

namespace App\Filament\Resources\Learners\Pages;

use App\Filament\Resources\Learners\LearnerResource;
use App\Services\LearnerService;
use App\Support\People\DuplicateCandidate;
use App\Support\People\DuplicateDetector;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateLearner extends CreateRecord
{
    protected static string $resource = LearnerResource::class;

    public bool $forceCreate = false;

    /**
     * Possible duplicates are shown before the record is created, not after.
     *
     * Two children in one family genuinely can share a name, so this warns and
     * lets the person at the desk decide rather than refusing. What it will not
     * do is create a second record silently — that is how attendance and grades
     * end up split across two rows and the report sent home is wrong.
     *
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        if (! $this->forceCreate) {
            $matches = app(DuplicateDetector::class)->check(
                $data['legal_name'],
                dateOfBirth: isset($data['date_of_birth'])
                    ? new \DateTimeImmutable((string) $data['date_of_birth'])
                    : null,
            );

            if ($matches->isNotEmpty()) {
                $this->forceCreate = true;

                Notification::make()
                    ->warning()
                    ->title('This may already be someone you have on file')
                    ->body($matches->map(
                        fn (DuplicateCandidate $c) => $c->learner->displayName().' ('.$c->learner->number.') — '
                            .implode(', ', $c->reasons),
                    )->implode('<br>').'<br><br>Save again to add them anyway.')
                    ->persistent()
                    ->send();

                $this->halt();
            }
        }

        return app(LearnerService::class)->create($data);
    }
}

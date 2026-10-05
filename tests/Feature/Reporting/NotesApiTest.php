<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Models\Batch;
use App\Models\Enrollment;
use App\Models\NoteCategory;
use App\Models\TeacherNote;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SampleDataService;
use App\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Period-end notes from the staff client: the categories it offers, and the bulk save. */
final class NotesApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $provisioned = app(TenantProvisioner::class)->provision([
            'name' => 'Sample Academy',
            'owner_name' => 'Sample Owner',
            'owner_email' => 'owner@sample.test',
            'password' => 'a-long-enough-password',
            'timezone' => 'Asia/Kathmandu',
            'preset_code' => 'kids-tutoring-south-asia',
        ]);

        $this->tenant = $provisioned['tenant'];
        $this->owner = $provisioned['owner'];

        app(TenantContext::class)->runAs($this->tenant, fn () => app(SampleDataService::class)->load($this->tenant));

        Sanctum::actingAs($this->owner, ['staff']);
    }

    #[Test]
    public function the_client_can_list_note_categories(): void
    {
        $response = $this->getJson('/api/v1/note-categories')->assertOk();

        $this->assertNotEmpty($response->json('data'));
        $this->assertArrayHasKey('report_visible_default', $response->json('data.0'));
    }

    #[Test]
    public function a_whole_batch_is_written_in_one_pass_skipping_empty_boxes(): void
    {
        [$batch, $enrollments, $category] = $this->sample();

        $this->postJson("/api/v1/batches/{$batch->id}/notes", [
            'notes' => [
                ['enrollment_id' => $enrollments[0], 'note_category_id' => $category, 'body' => 'Reads fluently now.'],
                ['enrollment_id' => $enrollments[1], 'note_category_id' => $category, 'body' => ''],
                ['enrollment_id' => $enrollments[1], 'note_category_id' => $category],
            ],
        ])->assertOk()->assertJsonPath('data.written', 1)->assertJsonPath('data.skipped', 2);
    }

    #[Test]
    public function a_note_cannot_be_written_into_another_batch_through_this_one(): void
    {
        [$batch, , $category] = $this->sample();

        $elsewhere = app(TenantContext::class)->runAs($this->tenant, function () use ($batch): int {
            $other = $batch->replicate();
            $other->name = 'Another batch';
            $other->code = 'OTHER';
            $other->save();

            $enrollment = Enrollment::query()->where('batch_id', $batch->id)->firstOrFail()->replicate();
            $enrollment->number .= '-B';
            $enrollment->batch_id = $other->id;
            $enrollment->save();

            return $enrollment->id;
        });

        $this->postJson("/api/v1/batches/{$batch->id}/notes", [
            'notes' => [['enrollment_id' => $elsewhere, 'note_category_id' => $category, 'body' => 'Wrong class.']],
        ])->assertStatus(422)->assertJsonValidationErrors('notes');

        $this->assertSame(0, app(TenantContext::class)->runAs($this->tenant, fn () => TeacherNote::query()->count()));
    }

    /** @return array{0: Batch, 1: list<int>, 2: int} */
    private function sample(): array
    {
        return app(TenantContext::class)->runAs($this->tenant, function (): array {
            $batch = Batch::query()->firstOrFail();

            return [
                $batch,
                Enrollment::query()->where('batch_id', $batch->id)->pluck('id')->all(),
                (int) NoteCategory::query()->value('id'),
            ];
        });
    }
}

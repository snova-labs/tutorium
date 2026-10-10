<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Models\Batch;
use App\Models\Enrollment;
use App\Models\EnrollmentStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SampleDataService;
use App\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Changing an enrollment's status from the staff client, and the statuses it offers. */
final class EnrollmentStatusApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

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

        app(TenantContext::class)->runAs($this->tenant, fn () => app(SampleDataService::class)->load($this->tenant));

        Sanctum::actingAs($provisioned['owner'], ['staff']);
    }

    #[Test]
    public function the_statuses_say_which_end_an_enrollment_and_which_cost_money(): void
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->getJson('/api/v1/enrollment-statuses')->assertOk()->json('data');
        $statuses = array_column($rows, null, 'code');

        $this->assertTrue($statuses['ACTIVE']['counts_toward_billing']);
        $this->assertFalse($statuses['ACTIVE']['is_terminal']);
        $this->assertTrue($statuses['WITHDRAWN']['is_terminal']);
        $this->assertTrue($statuses['TRANSFERRED']['set_by_transfer_only']);
        $this->assertFalse($statuses['WITHDRAWN']['set_by_transfer_only']);
    }

    #[Test]
    public function ending_an_enrollment_needs_a_reason(): void
    {
        $enrollment = $this->enrollment();
        $withdrawn = $this->statusCoded('WITHDRAWN');

        $this->putJson("/api/v1/enrollments/{$enrollment->id}/status", ['status_id' => $withdrawn->id])
            ->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->putJson("/api/v1/enrollments/{$enrollment->id}/status", [
            'status_id' => $withdrawn->id,
            'reason' => 'Family moved away.',
        ])->assertOk()->assertJsonPath('data.status.reason', 'Family moved away.');

        $this->assertNotNull($this->fresh($enrollment)->ended_on);
    }

    #[Test]
    public function pausing_does_not_need_a_reason(): void
    {
        $enrollment = $this->enrollment();

        $this->putJson("/api/v1/enrollments/{$enrollment->id}/status", ['status_id' => $this->statusCoded('ON_HOLD')->id])
            ->assertOk();

        $this->assertNull($this->fresh($enrollment)->ended_on);
    }

    #[Test]
    public function the_response_carries_the_whole_history(): void
    {
        $enrollment = $this->enrollment();

        $this->putJson("/api/v1/enrollments/{$enrollment->id}/status", ['status_id' => $this->statusCoded('ON_HOLD')->id])
            ->assertOk();

        // A second change, so the history holds several rows (lazy loading only trips on more than one).
        $this->putJson("/api/v1/enrollments/{$enrollment->id}/status", ['status_id' => $this->statusCoded('ACTIVE')->id])
            ->assertOk()
            ->assertJsonPath('data.status.name', 'Active')
            ->assertJsonFragment(['from' => 'On hold', 'to' => 'Active']);
    }

    #[Test]
    public function transferred_is_only_set_by_a_transfer(): void
    {
        $enrollment = $this->enrollment();

        $this->putJson("/api/v1/enrollments/{$enrollment->id}/status", [
            'status_id' => $this->statusCoded('TRANSFERRED')->id,
            'reason' => 'Moving.',
        ])->assertStatus(422)->assertJsonValidationErrors('status_id');

        $this->assertNull($this->fresh($enrollment)->ended_on);
    }

    #[Test]
    public function a_transfer_closes_the_old_enrollment_and_opens_one_in_the_new_batch(): void
    {
        $enrollment = $this->enrollment();

        $target = app(TenantContext::class)->runAs($this->tenant, function () use ($enrollment): Batch {
            $other = $enrollment->batch->replicate();
            $other->name = 'Evening group';
            $other->code = 'EVENING';
            $other->save();

            return $other;
        });

        $response = $this->postJson("/api/v1/enrollments/{$enrollment->id}/transfer", ['batch_id' => $target->id])
            ->assertCreated()
            ->assertJsonPath('data.batch_id', $target->id);

        $old = $this->fresh($enrollment);
        $this->assertSame($response->json('data.id'), $old->transferred_to_enrollment_id);
        $this->assertNotNull($old->ended_on);
    }

    #[Test]
    public function a_teacher_cannot_change_a_status(): void
    {
        $enrollment = $this->enrollment();

        $teacher = app(TenantContext::class)->runAs($this->tenant, function (): User {
            $user = User::factory()->create(['email' => 'teacher@sample.test', 'is_active' => true, 'scope_all_branches' => true]);
            $user->syncRoles(['Teacher']);

            return $user->fresh();
        });

        Sanctum::actingAs($teacher, ['staff']);

        $this->putJson("/api/v1/enrollments/{$enrollment->id}/status", ['status_id' => $this->statusCoded('ON_HOLD')->id])
            ->assertForbidden();
    }

    private function enrollment(): Enrollment
    {
        return app(TenantContext::class)->runAs($this->tenant, fn () => Enrollment::query()->with('batch')->firstOrFail());
    }

    private function fresh(Enrollment $enrollment): Enrollment
    {
        return app(TenantContext::class)->runAs($this->tenant, fn () => Enrollment::query()->findOrFail($enrollment->id));
    }

    private function statusCoded(string $code): EnrollmentStatus
    {
        return app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => EnrollmentStatus::query()->where('code', $code)->firstOrFail(),
        );
    }
}

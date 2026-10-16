<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\ClassSession;
use App\Models\LearnerStatus;
use App\Models\NoteCategory;
use App\Models\SessionType;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Reporting\Concerns\BuildsReportingScenario;
use Tests\TestCase;

/**
 * The academy's own lists.
 *
 * A preset writes them on day one and an academy then needs its own: a "Trial lesson" that does
 * not count toward attendance. The risk is the other direction — a term something has used must
 * not be removable, because the registers and notes pointing at it would lose their meaning.
 */
final class VocabularyApiTest extends TestCase
{
    use BuildsReportingScenario, RefreshDatabase;

    #[Test]
    public function the_lists_arrive_with_what_each_flag_means(): void
    {
        Sanctum::actingAs($this->owner(), ['staff']);

        $response = $this->getJson('/api/v1/vocabularies')->assertOk();

        $this->assertSame(
            ['session-types', 'note-categories', 'learner-statuses'],
            array_column((array) $response->json('data'), 'key'),
        );

        // The screen says what ticking the box does, and the API is where that sentence lives.
        $this->assertSame('counts_in_attendance', $response->json('data.0.flag.field'));
        $this->assertSame('Counts toward attendance', $response->json('data.0.flag.label'));
        // Its own wording for being off, because negating the label reads as nonsense.
        $this->assertSame('Does not count toward attendance', $response->json('data.0.flag.off'));
        $this->assertTrue($response->json('data.0.deactivates'));
        $this->assertFalse($response->json('data.1.deactivates'));
    }

    #[Test]
    public function an_academy_can_add_a_trial_lesson_that_does_not_count_toward_attendance(): void
    {
        Sanctum::actingAs($this->owner(), ['staff']);

        $response = $this->postJson('/api/v1/vocabularies/session-types', [
            'name' => 'Trial lesson',
            'flag' => false,
            'color' => '#1B5FAA',
        ])->assertCreated();

        $this->assertSame('Trial lesson', $response->json('data.name'));
        // Nobody should have to invent a code to add a list item.
        $this->assertSame('TRIAL_LESSON', $response->json('data.code'));
        $this->assertFalse($response->json('data.flag'));
        $this->assertSame('#1b5faa', $response->json('data.color'));
        $this->assertSame(0, $response->json('data.used'));

        $this->inTenant(function (): void {
            $type = SessionType::query()->where('code', 'TRIAL_LESSON')->sole();
            $this->assertFalse((bool) $type->counts_in_attendance);
            $this->assertTrue((bool) $type->is_active);
        });
    }

    #[Test]
    public function a_second_term_of_the_same_name_gets_its_own_code(): void
    {
        Sanctum::actingAs($this->owner(), ['staff']);

        $first = $this->postJson('/api/v1/vocabularies/note-categories', ['name' => 'Homework'])->assertCreated();
        $second = $this->postJson('/api/v1/vocabularies/note-categories', ['name' => 'Homework'])->assertCreated();

        $this->assertSame('HOMEWORK', $first->json('data.code'));
        $this->assertSame('HOMEWORK_2', $second->json('data.code'));
    }

    #[Test]
    public function renaming_a_term_leaves_its_code_alone(): void
    {
        Sanctum::actingAs($this->owner(), ['staff']);

        $added = $this->postJson('/api/v1/vocabularies/session-types', ['name' => 'Trial lesson'])->assertCreated();

        // An import written against TRIAL_LESSON keeps working.
        $this->patchJson("/api/v1/vocabularies/session-types/{$added->json('data.id')}", ['name' => 'Taster class'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Taster class')
            ->assertJsonPath('data.code', 'TRIAL_LESSON');
    }

    #[Test]
    public function a_term_in_use_is_not_removed_and_says_what_to_do_instead(): void
    {
        Sanctum::actingAs($this->owner(), ['staff']);

        $type = $this->inTenant(fn (): SessionType => ClassSession::query()
            ->where('batch_id', $this->batch->getKey())
            ->firstOrFail()
            ->sessionType);

        $this->getJson('/api/v1/vocabularies')
            ->assertOk()
            ->assertJsonPath('data.0.terms.0.used', fn (int $used) => $used > 0);

        $this->deleteJson("/api/v1/vocabularies/session-types/{$type->getKey()}")
            ->assertStatus(422)
            ->assertJsonPath('errors.term.0', fn (string $message) => str_contains($message, 'Switch it off instead'));

        // Still there, and switching it off is the way to retire it.
        $this->patchJson("/api/v1/vocabularies/session-types/{$type->getKey()}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    #[Test]
    public function a_term_nothing_has_used_can_be_removed(): void
    {
        Sanctum::actingAs($this->owner(), ['staff']);

        $added = $this->postJson('/api/v1/vocabularies/learner-statuses', ['name' => 'On a break'])->assertCreated();
        $id = (int) $added->json('data.id');

        $this->deleteJson("/api/v1/vocabularies/learner-statuses/{$id}")->assertOk();

        $this->inTenant(function () use ($id): void {
            $this->assertNull(LearnerStatus::query()->find($id));
        });
    }

    #[Test]
    public function a_teacher_may_read_the_lists_but_not_change_them(): void
    {
        Sanctum::actingAs($this->teacher(), ['staff']);

        // A teacher filing a note needs the categories; the list itself is a setting.
        $this->getJson('/api/v1/vocabularies')->assertOk();
        $this->postJson('/api/v1/vocabularies/note-categories', ['name' => 'Mine'])->assertForbidden();
    }

    #[Test]
    public function another_academys_term_is_not_found_rather_than_forbidden(): void
    {
        Sanctum::actingAs($this->owner(), ['staff']);

        $other = app(TenantContext::class)->withoutScoping(
            fn (): Tenant => Tenant::factory()->create(['slug' => 'other-'.uniqid()]),
        );

        $theirs = app(TenantContext::class)->runAs($other, fn (): NoteCategory => NoteCategory::query()->create([
            'name' => 'Theirs', 'code' => 'THEIRS', 'report_visible_default' => false, 'sort' => 0,
        ]));

        $this->patchJson("/api/v1/vocabularies/note-categories/{$theirs->getKey()}", ['name' => 'Mine now'])
            ->assertNotFound();
    }

    #[Test]
    public function a_list_that_does_not_exist_is_a_404(): void
    {
        Sanctum::actingAs($this->owner(), ['staff']);

        // Enrollment statuses are deliberately not here: their flags decide what is invoiced.
        $this->postJson('/api/v1/vocabularies/enrollment-statuses', ['name' => 'Half price'])->assertNotFound();
    }

    #[Test]
    public function a_nameless_term_and_a_nonsense_colour_are_refused(): void
    {
        Sanctum::actingAs($this->owner(), ['staff']);

        $this->postJson('/api/v1/vocabularies/session-types', ['name' => ''])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Give it a name.');

        $this->postJson('/api/v1/vocabularies/session-types', ['name' => 'Workshop', 'color' => 'blue'])
            ->assertStatus(422)
            ->assertJsonPath('errors.color.0', 'A colour looks like #1B5FAA.');
    }

    private function owner(): User
    {
        return $this->person('Owner');
    }

    private function teacher(): User
    {
        return $this->person('Teacher');
    }

    private function person(string $role): User
    {
        app(RolesAndPermissionsSeeder::class)->run($this->tenant);

        return $this->inTenant(function () use ($role): User {
            $user = User::factory()->create(['is_active' => true, 'scope_all_branches' => true]);
            $user->syncRoles([$role]);

            return $user->fresh();
        });
    }

    /**
     * @template TReturn
     *
     * @param Closure(): TReturn $callback
     * @return TReturn
     */
    private function inTenant(Closure $callback): mixed
    {
        return app(TenantContext::class)->runAs($this->tenant, $callback);
    }
}

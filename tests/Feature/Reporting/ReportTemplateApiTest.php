<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Models\Report;
use App\Models\ReportTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ReportService;
use App\Support\Tenancy\TenantContext;
use App\Support\Time\PeriodService;
use Closure;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Reporting\Concerns\BuildsReportingScenario;
use Tests\TestCase;

/**
 * Choosing what a report contains.
 *
 * The screen that does this is new, which makes an old hazard likely: a template is read while
 * rendering, so changing one could have rewritten reports people had already been sent. What was
 * sent is now frozen with the report.
 */
final class ReportTemplateApiTest extends TestCase
{
    use BuildsReportingScenario, RefreshDatabase;

    #[Test]
    public function the_list_says_what_sections_exist_and_which_template_answers_first(): void
    {
        Sanctum::actingAs($this->owner(), ['staff']);

        $this->inTenant(function (): void {
            ReportTemplate::query()->create([
                'name' => 'Account default', 'blocks' => ['summary', 'work'], 'is_default' => true,
            ]);
            ReportTemplate::query()->create([
                'name' => 'This course', 'blocks' => ['summary'], 'course_id' => $this->batch->course_id,
            ]);
        });

        $response = $this->getJson('/api/v1/report-templates')->assertOk();

        // The catalogue of sections is the API's to state: the screen offers exactly these.
        $this->assertSame(
            ['summary', 'engagement', 'highlights', 'work', 'notes'],
            array_keys($response->json('meta.blocks')),
        );

        // Narrowest first, which is the order a report resolves them in.
        $this->assertSame('This course', $response->json('data.0.name'));
        $this->assertSame('Account default', $response->json('data.1.name'));
    }

    #[Test]
    public function a_template_is_added_and_changed(): void
    {
        Sanctum::actingAs($this->owner(), ['staff']);

        $id = $this->postJson('/api/v1/report-templates', [
            'name' => 'Short report', 'blocks' => ['summary', 'notes'], 'closing' => 'Warm regards,',
        ])->assertCreated()
            ->assertJsonPath('data.blocks', ['summary', 'notes'])
            // The first template of an account is its default without anyone deciding.
            ->assertJsonPath('data.is_default', true)
            ->json('data.id');

        $this->patchJson("/api/v1/report-templates/{$id}", ['blocks' => ['summary'], 'name' => 'Shorter'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Shorter')
            ->assertJsonPath('data.blocks', ['summary']);
    }

    #[Test]
    public function a_template_with_no_sections_is_refused(): void
    {
        Sanctum::actingAs($this->owner(), ['staff']);

        // A report with no sections is a greeting and nothing else.
        $this->postJson('/api/v1/report-templates', ['name' => 'Empty', 'blocks' => []])
            ->assertStatus(422)->assertJsonValidationErrors('blocks');

        $this->postJson('/api/v1/report-templates', ['name' => 'Invented', 'blocks' => ['horoscope']])
            ->assertStatus(422)->assertJsonValidationErrors('blocks.0');
    }

    #[Test]
    public function exactly_one_template_is_the_default(): void
    {
        Sanctum::actingAs($this->owner(), ['staff']);

        $first = $this->postJson('/api/v1/report-templates', ['name' => 'First', 'blocks' => ['summary']])
            ->json('data.id');
        $second = $this->postJson('/api/v1/report-templates', [
            'name' => 'Second', 'blocks' => ['summary'], 'is_default' => true,
        ])->json('data.id');

        $this->assertSame([$second], $this->defaults());

        // Taking the default off the only default would leave reports resolving to whichever row
        // came back first, so it stays.
        $this->patchJson("/api/v1/report-templates/{$second}", ['is_default' => false])->assertOk();
        $this->assertSame([$second], $this->defaults());

        $this->patchJson("/api/v1/report-templates/{$first}", ['is_default' => true])->assertOk();
        $this->assertSame([$first], $this->defaults());
    }

    #[Test]
    public function changing_a_template_does_not_rewrite_a_report_already_sent(): void
    {
        Storage::fake('local');

        $report = $this->inTenant(function () {
            $this->markAllPresent();
            $this->writeNote('Worked hard this period and asked good questions.', true);

            return $this->generate();
        });
        $sent = $this->inTenant(fn () => app(ReportService::class)->html($report));

        $this->assertStringContainsString('From the teacher', $sent);

        // The academy decides it would rather not send notes from now on.
        $this->inTenant(function (): void {
            ReportTemplate::query()->firstOrFail()->update(['blocks' => ['summary']]);
        });

        $again = $this->inTenant(fn () => app(ReportService::class)->html($report->refresh()));

        // What the guardian read is what a re-download shows, whatever the template says today.
        $this->assertStringContainsString('From the teacher', $again);
    }

    #[Test]
    public function a_teacher_may_read_the_templates_but_not_change_them(): void
    {
        Sanctum::actingAs($this->teacher(), ['staff']);

        $this->inTenant(fn () => ReportTemplate::query()->create([
            'name' => 'Default', 'blocks' => ['summary'], 'is_default' => true,
        ]));

        $this->getJson('/api/v1/report-templates')->assertOk();
        $this->postJson('/api/v1/report-templates', ['name' => 'Mine', 'blocks' => ['summary']])->assertForbidden();
    }

    #[Test]
    public function another_academys_template_is_not_found(): void
    {
        Sanctum::actingAs($this->owner(), ['staff']);

        $other = app(TenantContext::class)->withoutScoping(fn (): Tenant => Tenant::factory()->create());
        $theirs = app(TenantContext::class)->runAs($other, fn () => ReportTemplate::query()->create([
            'name' => 'Theirs', 'blocks' => ['summary'],
        ]));

        // Not-found, never forbidden: a 403 would confirm the record exists somewhere.
        $this->patchJson("/api/v1/report-templates/{$theirs->getKey()}", ['name' => 'Hijacked'])->assertNotFound();
    }

    /** The report this scenario would send for August, gaps and all. */
    private function generate(): Report
    {
        $period = app(PeriodService::class)->forLabel($this->batch->load('course'), '2026-08');

        return app(ReportService::class)->generate(
            $this->enrollment->setRelation('batch', $this->batch),
            $period,
            ['require_complete' => false],
        );
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

    /** @return array<int, int> */
    private function defaults(): array
    {
        return $this->inTenant(
            fn () => ReportTemplate::query()->where('is_default', true)->pluck('id')->map(fn ($id) => (int) $id)->all(),
        );
    }

    /** @param Closure(): mixed $callback */
    private function inTenant(Closure $callback): mixed
    {
        return app(TenantContext::class)->runAs($this->tenant, $callback);
    }
}

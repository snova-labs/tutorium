<?php

declare(strict_types=1);

namespace Tests\Feature\Operator;

use App\Models\AuditLog;
use App\Models\Impersonation;
use App\Models\Operator;
use App\Models\Tenant;
use App\Services\ImpersonationService;
use App\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Support access is defensible only if the customer can see it.
 *
 * SL-SEC-004 §6 and FR-AUD-3: a reason is required, the session expires on its own, and the whole
 * thing lands in the tenant's own activity log — the same log they read.
 */
final class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    private Operator $operator;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = Operator::factory()->create(['name' => 'Sample Operator']);
        $this->tenant = $this->provision();
    }

    #[Test]
    public function the_customer_can_see_that_we_looked(): void
    {
        app(ImpersonationService::class)->start(
            $this->operator,
            $this->tenant,
            'Ticket #204 — investigating a report delivery failure',
        );

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $entry = AuditLog::query()->where('action', 'support_access_started')->latest('id')->first();

            $this->assertNotNull($entry, 'Support access must appear in the tenant\'s own log.');
            $this->assertSame(AuditLog::ACTOR_OPERATOR, $entry->actor_type);
            $this->assertSame('Sample Operator (support)', $entry->actor_name);
            // The reason is copied verbatim, because a reason nobody can read is not a reason.
            $this->assertStringContainsString('Ticket #204', $entry->after['reason']);
        });
    }

    #[Test]
    public function a_reason_is_required(): void
    {
        $this->expectException(ValidationException::class);

        app(ImpersonationService::class)->start($this->operator, $this->tenant, '   ');
    }

    #[Test]
    public function the_session_expires_on_its_own(): void
    {
        $result = app(ImpersonationService::class)->start(
            $this->operator, $this->tenant, 'Ticket #204 — checking a register', 15,
        );

        $this->assertTrue($result['impersonation']->isActive());
        $this->assertSame(15, (int) $result['impersonation']->started_at
            ->diffInMinutes($result['impersonation']->expires_at));

        $this->travel(20)->minutes();

        $this->assertFalse($result['impersonation']->refresh()->isActive());
    }

    #[Test]
    public function a_session_is_capped_no_matter_what_is_asked_for(): void
    {
        $result = app(ImpersonationService::class)->start(
            $this->operator, $this->tenant, 'Ticket #204 — a long look', 600,
        );

        // Support work is short. Anything longer is a conversation, not a look.
        $this->assertSame(60, (int) $result['impersonation']->started_at
            ->diffInMinutes($result['impersonation']->expires_at));
    }

    #[Test]
    public function an_operator_without_two_factor_cannot_reach_a_customer(): void
    {
        $operator = Operator::factory()->withoutTwoFactor()->create();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Two-factor');

        app(ImpersonationService::class)->start($operator, $this->tenant, 'Ticket #204 — a look');
    }

    #[Test]
    public function only_one_session_can_be_open_at_a_time(): void
    {
        app(ImpersonationService::class)->start($this->operator, $this->tenant, 'Ticket #204 — first look');

        $this->expectException(ValidationException::class);
        app(ImpersonationService::class)->start($this->operator, $this->tenant, 'Ticket #205 — second look');
    }

    #[Test]
    public function ending_a_session_revokes_its_token_and_records_the_time_used(): void
    {
        $result = app(ImpersonationService::class)->start(
            $this->operator, $this->tenant, 'Ticket #204 — investigating',
        );

        $this->travel(8)->minutes();
        $ended = app(ImpersonationService::class)->end($result['impersonation']);

        $this->assertNotNull($ended->ended_at);
        $this->assertSame(8, $ended->minutesUsed());

        app(TenantContext::class)->runAs($this->tenant, function () use ($ended): void {
            $this->assertSame(
                0,
                $ended->user->tokens()->where('name', 'support-access-'.$ended->getKey())->count(),
                'The token must not outlive the session.',
            );

            $this->assertNotNull(AuditLog::query()->where('action', 'support_access_ended')->first());
        });
    }

    #[Test]
    public function expired_sessions_are_closed_by_the_scheduled_sweep(): void
    {
        app(ImpersonationService::class)->start($this->operator, $this->tenant, 'Ticket #204 — a look', 5);

        $this->travel(10)->minutes();

        $this->assertSame(1, app(ImpersonationService::class)->closeExpired());
        $this->assertNotNull(Impersonation::query()->latest('id')->first()->ended_at);
    }

    #[Test]
    public function the_customer_can_read_the_whole_history_without_asking_us(): void
    {
        $first = app(ImpersonationService::class)->start($this->operator, $this->tenant, 'Ticket #204 — first');
        app(ImpersonationService::class)->end($first['impersonation']);
        app(ImpersonationService::class)->start($this->operator, $this->tenant, 'Ticket #211 — second');

        $history = app(ImpersonationService::class)->historyFor($this->tenant);

        $this->assertCount(2, $history);
        $this->assertSame('Sample Operator', $history[0]['operator']);
        $this->assertTrue($history[0]['active']);
    }

    private function provision(): Tenant
    {
        return app(TenantProvisioner::class)->provision([
            'name' => 'Sample Academy',
            'owner_name' => 'Sample Owner',
            'owner_email' => 'owner@sample.test',
            'password' => 'a-long-enough-password',
            'timezone' => 'Asia/Kathmandu',
            'preset_code' => 'kids-tutoring-south-asia',
        ])['tenant'];
    }
}

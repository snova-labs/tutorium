<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\BillingProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Payments\FakePaymentProvider;
use App\Support\Payments\ManualPaymentProvider;
use App\Support\Payments\PaymentProvider;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A customer manages how they pay without writing to us: a card entered on the provider's hosted
 * page, a summary they can recognise, and invoicing for academies without a company card.
 */
final class PaymentMethodTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(PaymentProvider::class, new FakePaymentProvider);

        $this->tenant = app(TenantContext::class)->withoutScoping(fn () => Tenant::factory()->create([
            'slug' => 'payer',
            'status' => Tenant::STATUS_ACTIVE,
        ]));

        app(RolesAndPermissionsSeeder::class)->run($this->tenant);

        $this->owner = $this->user('Owner');
        Sanctum::actingAs($this->owner);
    }

    #[Test]
    public function with_no_card_the_summary_says_what_to_do(): void
    {
        $this->getJson('/api/v1/billing')->assertOk()
            ->assertJsonPath('data.payment_method.collection', 'card')
            ->assertJsonPath('data.payment_method.card', null)
            ->assertJsonPath('data.payment_method.card_available', true)
            ->assertJsonPath('data.payment_method.needs_attention', true)
            ->assertJsonPath('data.payment_method.summary', 'No card on file. Add one, or switch to invoicing.');
    }

    #[Test]
    public function a_first_card_goes_to_hosted_checkout_and_a_later_change_to_the_portal(): void
    {
        $this->postJson('/api/v1/billing/payment-method')->assertOk()
            ->assertJsonPath('data.url', 'https://payments.test/checkout/payer');

        $this->assertSame('fake_cus_'.$this->tenant->getKey(), $this->profile()->customer_ref);

        $this->giveCard(now()->addYear()->year, 4);

        $this->postJson('/api/v1/billing/payment-method')->assertOk()
            ->assertJsonPath('data.url', 'https://payments.test/portal/fake_cus_'.$this->tenant->getKey());

        $this->getJson('/api/v1/billing')->assertOk()
            ->assertJsonPath('data.payment_method.card.last_four', '4242')
            ->assertJsonPath('data.payment_method.card.expired', false)
            ->assertJsonPath('data.payment_method.needs_attention', false);
    }

    #[Test]
    public function the_hosted_page_only_ever_returns_to_our_own_address(): void
    {
        $this->postJson('/api/v1/billing/payment-method', ['return_url' => 'https://elsewhere.test/steal'])
            ->assertStatus(422)->assertJsonValidationErrors('return_url');

        $own = rtrim(config()->string('app.url'), '/').'/billing?tab=payment';
        $this->postJson('/api/v1/billing/payment-method', ['return_url' => $own])->assertOk();
    }

    #[Test]
    public function an_expired_or_expiring_card_is_called_out(): void
    {
        $this->giveCard(now()->subYear()->year, 1);

        $this->getJson('/api/v1/billing')->assertOk()
            ->assertJsonPath('data.payment_method.card.expired', true)
            ->assertJsonPath('data.payment_method.needs_attention', true);

        $this->giveCard(now()->year, now()->month);

        $this->getJson('/api/v1/billing')->assertOk()
            ->assertJsonPath('data.payment_method.card.expired', false)
            ->assertJsonPath('data.payment_method.card.expires_soon', true);
    }

    #[Test]
    public function switching_to_invoicing_needs_someone_to_address_the_invoice_to(): void
    {
        $this->putJson('/api/v1/billing/collection', ['method' => 'invoice'])
            ->assertStatus(422)->assertJsonValidationErrors(['legal_name', 'billing_email']);

        $this->assertFalse($this->profile()->prefers_invoicing);

        $this->putJson('/api/v1/billing/collection', [
            'method' => 'invoice',
            'legal_name' => 'Sample Academy Ltd',
            'billing_email' => 'accounts@sample.test',
        ])->assertOk()
            ->assertJsonPath('data.payment_method.collection', 'invoice')
            ->assertJsonPath('data.payment_method.needs_attention', false);

        $this->assertTrue($this->profile()->prefers_invoicing);
        $this->getJson('/api/v1/billing')
            ->assertJsonPath('data.payment_method.summary', 'Invoiced to accounts@sample.test and paid by bank transfer. Nothing is charged automatically.');
    }

    #[Test]
    public function switching_back_to_card_needs_a_card_that_can_be_charged(): void
    {
        $this->invoiced();

        $this->putJson('/api/v1/billing/collection', ['method' => 'card'])
            ->assertStatus(422)->assertJsonValidationErrors('method');

        $this->giveCard(now()->subYear()->year, 1);
        $this->putJson('/api/v1/billing/collection', ['method' => 'card'])->assertStatus(422);
        $this->assertTrue($this->profile()->prefers_invoicing);

        $this->giveCard(now()->addYears(2)->year, 6);
        $this->putJson('/api/v1/billing/collection', ['method' => 'card'])->assertOk()
            ->assertJsonPath('data.payment_method.collection', 'card');

        $this->assertFalse($this->profile()->prefers_invoicing);
    }

    #[Test]
    public function an_invoice_only_setup_offers_no_card(): void
    {
        $this->app->instance(PaymentProvider::class, new ManualPaymentProvider);

        $this->getJson('/api/v1/billing')->assertOk()
            ->assertJsonPath('data.payment_method.collection', 'invoice')
            ->assertJsonPath('data.payment_method.card_available', false)
            ->assertJsonPath('data.payment_method.needs_attention', false);

        $this->postJson('/api/v1/billing/payment-method')->assertOk()->assertJsonPath('data.url', null);

        $this->giveCard(now()->addYear()->year, 1);
        $this->putJson('/api/v1/billing/collection', ['method' => 'card'])
            ->assertStatus(422)->assertJsonValidationErrors('method');
    }

    #[Test]
    public function a_read_only_account_can_still_fix_how_it_pays(): void
    {
        app(TenantContext::class)->withoutScoping(fn () => $this->tenant->update(['status' => Tenant::STATUS_SUSPENDED]));

        // Everything else is read-only...
        $this->postJson('/api/v1/brands', ['name' => 'Nope', 'code' => 'NOPE'])->assertStatus(423);

        // ...but paying is how the account gets out of that state.
        $this->postJson('/api/v1/billing/payment-method')->assertOk();
        $this->putJson('/api/v1/billing/collection', [
            'method' => 'invoice', 'legal_name' => 'Sample Academy Ltd', 'billing_email' => 'accounts@sample.test',
        ])->assertOk();
    }

    #[Test]
    public function only_people_who_manage_billing_can_see_or_change_it(): void
    {
        Sanctum::actingAs($this->user('Front desk'));

        $this->getJson('/api/v1/billing')->assertForbidden();
        $this->postJson('/api/v1/billing/payment-method')->assertForbidden();
        $this->putJson('/api/v1/billing/collection', ['method' => 'invoice'])->assertForbidden();
    }

    private function user(string $role): User
    {
        return app(TenantContext::class)->runAs($this->tenant, function () use ($role): User {
            $user = User::factory()->create(['is_active' => true, 'scope_all_branches' => true]);
            $user->syncRoles([$role]);

            return $user->fresh() ?? $user;
        });
    }

    private function profile(): BillingProfile
    {
        return app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => BillingProfile::query()->firstOrCreate([], ['provider' => 'fake']),
        );
    }

    /** What the provider's webhook records once a card is added on the hosted page. */
    private function giveCard(int $year, int $month): void
    {
        $this->profile()->update([
            'method_brand' => 'Visa', 'method_last_four' => '4242',
            'method_exp_month' => $month, 'method_exp_year' => $year,
        ]);
    }

    private function invoiced(): void
    {
        $this->profile()->update([
            'prefers_invoicing' => true, 'legal_name' => 'Sample Academy Ltd', 'billing_email' => 'accounts@sample.test',
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Models\Tenant;
use App\Models\TerminologyOverride;
use App\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The settings screen: what things are called, and the setup guide. */
final class SettingsApiTest extends TestCase
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

        Sanctum::actingAs($provisioned['owner'], ['staff']);
    }

    #[Test]
    public function terminology_comes_with_the_product_defaults(): void
    {
        $this->getJson('/api/v1/terminology')
            ->assertOk()
            ->assertJsonPath('meta.defaults.learner.singular', 'Learner')
            ->assertJsonStructure(['data' => ['learner' => ['singular', 'plural']]]);
    }

    #[Test]
    public function renaming_a_noun_is_saved_and_shown(): void
    {
        $this->putJson('/api/v1/terminology', [
            'terms' => ['batch' => ['singular' => 'Class', 'plural' => 'Classes']],
        ])->assertOk()->assertJsonPath('data.terms.batch.plural', 'Classes');

        $this->getJson('/api/v1/terminology')->assertJsonPath('data.batch.singular', 'Class');
    }

    #[Test]
    public function only_nouns_the_product_knows_can_be_renamed(): void
    {
        $this->putJson('/api/v1/terminology', [
            'terms' => ['wizard' => ['singular' => 'Wizard', 'plural' => 'Wizards']],
        ])->assertStatus(422)->assertJsonValidationErrors('terms');

        $this->assertSame(
            0,
            app(TenantContext::class)->runAs($this->tenant, fn () => TerminologyOverride::query()->where('term_key', 'wizard')->count()),
        );
    }

    #[Test]
    public function a_hidden_setup_guide_can_be_brought_back(): void
    {
        $this->postJson('/api/v1/onboarding/dismiss')->assertOk();
        $this->getJson('/api/v1/onboarding')->assertJsonPath('data.dismissed', true);

        $this->postJson('/api/v1/onboarding/reopen')->assertOk();
        $this->getJson('/api/v1/onboarding')->assertJsonPath('data.dismissed', false);
    }
}

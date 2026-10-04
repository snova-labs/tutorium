<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Managing the team over the API is for a signed-in member of the team, and nobody else.
 */
final class InvitationApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $result = app(TenantProvisioner::class)->provision([
            'name' => 'Sample Academy',
            'owner_name' => 'Sample Owner',
            'owner_email' => 'owner@sample.test',
            'password' => 'a-long-enough-password',
            'timezone' => 'Asia/Kathmandu',
            'preset_code' => 'blank',
        ]);

        $this->owner = $result['owner'];
    }

    /** @return array<string, array{string, string}> */
    public static function teamRoutes(): array
    {
        return [
            'list invitations' => ['GET', '/api/v1/invitations'],
            'send an invitation' => ['POST', '/api/v1/invitations'],
            'resend an invitation' => ['POST', '/api/v1/invitations/1/resend'],
            'revoke an invitation' => ['DELETE', '/api/v1/invitations/1'],
            'trial status' => ['GET', '/api/v1/trial'],
        ];
    }

    #[Test]
    #[DataProvider('teamRoutes')]
    public function a_caller_without_a_token_is_turned_away(string $method, string $uri): void
    {
        $this->json($method, $uri, ['email' => 'teacher@example.test', 'role_name' => 'Teacher'])
            ->assertUnauthorized();
    }

    #[Test]
    public function the_owner_can_see_the_team_and_the_trial(): void
    {
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/v1/invitations')->assertOk()->assertJsonPath('data.invitations', []);
        $this->getJson('/api/v1/trial')->assertOk();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class HealthTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function liveness_answers_without_a_session(): void
    {
        $this->getJson('/up')->assertOk()->assertJsonPath('status', 'ok')->assertCookieMissing(
            (string) config('session.cookie'),
        );
    }

    #[Test]
    public function readiness_reports_each_dependency(): void
    {
        $this->getJson('/ready')
            ->assertOk()
            ->assertJsonPath('checks.database', true)
            ->assertJsonPath('checks.cache', true);
    }
}

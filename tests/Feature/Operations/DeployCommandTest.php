<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The only command the stack runs on the way up.
 *
 * What matters is what it does when nothing is configured — which is every ordinary deploy of an
 * environment that is already running: migrations, and not one thing more.
 */
final class DeployCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_ordinary_deploy_migrates_and_creates_nothing(): void
    {
        config(['platform.first_run.academy' => '', 'demo.seed_on_deploy' => false]);

        $this->artisan('platform:deploy')
            ->assertSuccessful()
            ->expectsOutputToContain('Migrations')
            ->expectsOutputToContain('FIRST_RUN_ACADEMY is not set')
            ->doesntExpectOutputToContain('SEED_DEMO');

        $this->assertSame(0, Tenant::query()->count());
    }

    #[Test]
    public function it_creates_the_first_academy_when_the_variables_ask_for_one(): void
    {
        config(['platform.first_run' => [
            'academy' => 'Sunrise Academy',
            'owner_name' => 'Sunrise Owner',
            'owner_email' => 'owner@example.com',
            'password' => '',
            'timezone' => 'Asia/Kathmandu',
            'preset' => 'blank',
        ], 'demo.seed_on_deploy' => false]);

        $this->artisan('platform:deploy')->assertSuccessful();

        $this->assertSame('Sunrise Academy', Tenant::query()->sole()->name);
    }

    #[Test]
    public function a_bad_first_run_variable_does_not_fail_the_deploy(): void
    {
        // The app, the queue and the scheduler all wait on this container. A mistyped academy name
        // must not be what keeps an environment down.
        config([
            'platform.first_run.academy' => 'Sunrise Academy',
            'platform.first_run.owner_name' => 'Sunrise Owner',
            'platform.first_run.owner_email' => 'nonsense',
        ]);

        $this->artisan('platform:deploy')
            ->assertSuccessful()
            ->expectsOutputToContain('is not an email address');

        $this->assertSame(0, Tenant::query()->count());
    }

    #[Test]
    public function the_demo_flag_is_read_as_the_environment_writes_it(): void
    {
        // SEED_DEMO reaches PHP as the string "true" or "false", and a string "false" is true.
        $this->assertTrue($this->flag('true'));
        $this->assertTrue($this->flag('1'));
        $this->assertFalse($this->flag('false'));
        $this->assertFalse($this->flag(''));
        $this->assertFalse($this->flag(null));
    }

    /** What config/demo.php makes of one value of SEED_DEMO. */
    private function flag(?string $value): bool
    {
        if ($value === null) {
            putenv('SEED_DEMO');
            unset($_ENV['SEED_DEMO'], $_SERVER['SEED_DEMO']);
        } else {
            putenv("SEED_DEMO={$value}");
            $_ENV['SEED_DEMO'] = $value;
            $_SERVER['SEED_DEMO'] = $value;
        }

        /** @var array{seed_on_deploy: bool} $config */
        $config = require config_path('demo.php');

        return $config['seed_on_deploy'];
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Support\Diagnostics\CheckResult;
use App\Support\Diagnostics\PlatformVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The check a deploy runs before trusting itself.
 *
 * Its own correctness matters more than most: a verify command that passes whatever the state of
 * the machine is worse than no command, because it is read as proof.
 */
final class VerifyCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_working_installation_passes(): void
    {
        $this->artisan('platform:verify')
            ->assertSuccessful()
            ->expectsOutputToContain('checks passed');
    }

    #[Test]
    public function every_check_reports_on_the_thing_it_names(): void
    {
        $results = $this->statuses();

        // Each of these is something a deploy can get wrong on its own.
        $this->assertSame(CheckResult::OK, $results['Database']);
        $this->assertSame(CheckResult::OK, $results['Migrations']);
        $this->assertSame(CheckResult::OK, $results['Cache']);
        $this->assertSame(CheckResult::OK, $results['Report PDFs']);
        $this->assertSame(CheckResult::OK, $results['Report storage']);
        $this->assertSame(CheckResult::OK, $results['Mail']);
    }

    #[Test]
    public function a_warning_is_not_a_failure(): void
    {
        // The suite runs on the sync queue, which is worth saying and not worth failing over.
        $this->assertSame(CheckResult::WARN, $this->statuses()['Queue']);

        $this->artisan('platform:verify')->assertSuccessful();
    }

    #[Test]
    public function a_disk_that_cannot_be_written_to_fails(): void
    {
        config(['reporting.storage.disk' => 'nowhere']);
        config(['filesystems.disks.nowhere' => ['driver' => 'local', 'root' => '/proc/nowhere', 'throw' => true]]);

        $this->assertSame(CheckResult::FAIL, $this->statuses()['Report storage']);

        $this->artisan('platform:verify')->assertFailed();
    }

    #[Test]
    public function an_unreachable_mail_server_fails_rather_than_reading_as_configured(): void
    {
        // Port 1 answers nowhere. A correct-looking host and a dead mail server are the same on
        // paper, and the difference is every report an academy sends.
        config(['mail.default' => 'smtp']);
        config(['mail.mailers.smtp' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1]]);

        $this->assertSame(CheckResult::FAIL, $this->statuses()['Mail']);
    }

    #[Test]
    public function debug_left_on_in_production_fails(): void
    {
        app()->detectEnvironment(fn (): string => 'production');
        config(['app.debug' => true, 'app.url' => 'http://localhost']);

        $results = app(PlatformVerifier::class)->run();
        $settings = collect($results)->firstWhere('name', 'Settings');

        $this->assertNotNull($settings);
        $this->assertSame(CheckResult::FAIL, $settings->status);
        $this->assertStringContainsString('APP_DEBUG', $settings->detail);
        $this->assertStringContainsString('APP_URL', $settings->detail);
    }

    #[Test]
    public function the_json_output_is_what_a_monitor_reads(): void
    {
        $this->assertSame(0, Artisan::call('platform:verify', ['--json' => true]));

        $json = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($json['ok']);
        $this->assertNotEmpty($json['checks']);

        // The exit code is the signal; each row says which check and what was found.
        foreach ($json['checks'] as $check) {
            $this->assertSame(['check', 'status', 'detail'], array_keys($check));
            $this->assertContains($check['status'], [CheckResult::OK, CheckResult::WARN, CheckResult::FAIL]);
        }
    }

    /** @return array<string, string> */
    private function statuses(): array
    {
        $results = [];

        foreach (app(PlatformVerifier::class)->run() as $result) {
            $results[$result->name] = $result->status;
        }

        return $results;
    }
}

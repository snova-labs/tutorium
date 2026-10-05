<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Http\TrustedProxies;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Behind the Cloudflare Tunnel every request comes from the connector's container. The app must
 * take the scheme and client address from it, and only from it.
 */
final class TrustedProxiesTest extends TestCase
{
    protected function tearDown(): void
    {
        TrustProxies::flushState();
        parent::tearDown();
    }

    #[Test]
    public function the_setting_is_read_as_a_list_a_wildcard_or_nothing(): void
    {
        $this->assertNull(TrustedProxies::parse(null));
        $this->assertNull(TrustedProxies::parse('  '));
        $this->assertSame('*', TrustedProxies::parse('*'));
        $this->assertSame(['10.0.0.0/8', '172.16.0.0/12'], TrustedProxies::parse('10.0.0.0/8, 172.16.0.0/12,'));
    }

    #[Test]
    public function a_trusted_proxy_sets_the_scheme_and_the_real_client_address(): void
    {
        TrustProxies::at(TrustedProxies::parse('172.16.0.0/12'));
        Route::get('/_probe', fn (Request $r) => ['ip' => $r->ip(), 'secure' => $r->isSecure(), 'url' => url('/x')]);

        // A client tried to claim 1.2.3.4; the connector appended the address it really saw.
        $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.7'])
            ->withHeaders(['X-Forwarded-For' => '1.2.3.4, 203.0.113.9', 'X-Forwarded-Proto' => 'https'])
            ->getJson('/_probe')
            ->assertJson(['ip' => '203.0.113.9', 'secure' => true])
            ->assertJsonPath('url', fn (string $url) => str_starts_with($url, 'https://'));
    }

    #[Test]
    public function an_untrusted_sender_cannot_claim_an_address_or_https(): void
    {
        TrustProxies::at(TrustedProxies::parse('172.16.0.0/12'));
        Route::get('/_probe', fn (Request $r) => ['ip' => $r->ip(), 'secure' => $r->isSecure()]);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->withHeaders(['X-Forwarded-For' => '1.2.3.4', 'X-Forwarded-Proto' => 'https'])
            ->getJson('/_probe')
            ->assertJson(['ip' => '198.51.100.20', 'secure' => false]);
    }
}

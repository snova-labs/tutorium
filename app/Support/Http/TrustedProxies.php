<?php

declare(strict_types=1);

namespace App\Support\Http;

/**
 * Which proxies may tell the app the client's address and scheme, from TRUSTED_PROXIES.
 *
 * Behind a Cloudflare Tunnel or a reverse proxy, every request arrives from the proxy. Unless the
 * proxy is trusted, the app sees plain http (and builds http:// links) and records the proxy's
 * address as the client, so every sign-in shares one rate limit.
 *
 * Name the proxy networks rather than "*": with only private ranges trusted, the client address is
 * the right-most public one in X-Forwarded-For, so a client cannot choose it by sending the header
 * itself. Unset (the default) trusts nobody, which is right for a server reached directly.
 */
final class TrustedProxies
{
    /** @return array<int, string>|string|null */
    public static function parse(?string $value): array|string|null
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if ($value === '*') {
            return '*';
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}

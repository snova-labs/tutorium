<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;
use App\Support\Demo\DemoProfiles;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * The set of demonstration academies: build them, remove them, and say where things stand.
 *
 * The state lives in the cache rather than a table, because the page that starts a build and the
 * queue worker that runs it are different processes, and nothing about a demo needs to outlive it.
 */
final class DemoSuite
{
    private const STATUS_KEY = 'demo:status';

    /** A build or removal that has said nothing for this long has died with its worker. */
    private const STALE_MINUTES = 45;

    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly TenantLifecycleService $lifecycle,
    ) {}

    /** Whether demos may be built here at all: never on production, and only once a password is set. */
    public function enabled(): bool
    {
        return ! app()->isProduction() && $this->password() !== '';
    }

    public function password(): string
    {
        return (string) config('demo.password', '');
    }

    public function checkPassword(string $given): bool
    {
        return $this->enabled() && hash_equals($this->password(), $given);
    }

    /** @return list<Tenant> */
    public function tenants(): array
    {
        return $this->tenancy->withoutScoping(
            fn (): array => Tenant::query()->whereIn('slug', DemoProfiles::slugs())->orderBy('id')->get()->all(),
        );
    }

    /**
     * @return array{state: string, message: string|null, updated_at: string|null}
     *                                                                             state: none (no demo), building, ready, removing or failed
     */
    public function status(): array
    {
        /** @var array{state: string, message: string|null, updated_at: string}|null $stored */
        $stored = Cache::get(self::STATUS_KEY);
        $present = $this->tenants() !== [];

        if ($stored !== null && in_array($stored['state'], ['building', 'removing'], true)
            && now()->diffInMinutes($stored['updated_at'], absolute: true) > self::STALE_MINUTES) {
            $stored = ['state' => 'failed', 'message' => 'The last run stopped without finishing. Try again.', 'updated_at' => $stored['updated_at']];
        }

        if ($stored === null || ($stored['state'] === 'ready' && ! $present) || ($stored['state'] === 'none' && $present)) {
            return ['state' => $present ? 'ready' : 'none', 'message' => null, 'updated_at' => null];
        }

        return $stored;
    }

    public function busy(): bool
    {
        return in_array($this->status()['state'], ['building', 'removing'], true);
    }

    /** Mark a run as started, so the page shows it before the queue picks it up. */
    public function starting(string $state): void
    {
        $this->remember($state, $state === 'building' ? 'Starting…' : 'Removing…');
    }

    /**
     * Build every academy not already there.
     *
     * @param callable(string): void|null $say
     * @return array<string, array<string, int>> counts per academy built
     */
    public function build(string $password, bool $fresh = false, ?callable $say = null): array
    {
        $this->guard();
        $say ??= static function (string $message): void {};
        $built = [];

        try {
            if ($fresh) {
                $this->removeAll();
            }

            $existing = array_map(fn (Tenant $t) => $t->slug, $this->tenants());

            foreach ($this->profiles() as $profile) {
                if (in_array($profile['slug'], $existing, true)) {
                    continue;
                }

                $report = function (string $message) use ($say): void {
                    $say($message);
                    $this->remember('building', $message);
                };

                // A new builder for each academy: it holds the state of the one it is building.
                $result = app(DemoAcademyBuilder::class)->build($profile, $password, $report);
                $built[$profile['name']] = $result['counts'];
            }
        } catch (Throwable $e) {
            $this->remember('failed', 'The demo could not be built: '.$e->getMessage());

            throw $e;
        }

        $this->remember('ready', null);

        return $built;
    }

    /** Remove every demo academy, and only those. */
    public function remove(): int
    {
        $this->guard();

        try {
            $removed = $this->removeAll();
        } catch (Throwable $e) {
            $this->remember('failed', 'The demo could not be removed: '.$e->getMessage());

            throw $e;
        }

        $this->remember('none', null);

        return $removed;
    }

    /**
     * Who to sign in as, for each academy that exists.
     *
     * @return list<array{academy: string, kind: string, accounts: list<array{name: string, email: string, role: string}>}>
     */
    public function accounts(): array
    {
        $present = array_map(fn (Tenant $t) => $t->slug, $this->tenants());
        $kinds = ['kids' => 'Kids tutoring centre', 'language' => 'Language school', 'skills' => 'IT & skills institute',
            'corporate' => 'Corporate training provider'];
        $out = [];

        foreach ($this->profiles() as $p) {
            if (! in_array($p['slug'], $present, true)) {
                continue;
            }

            $accounts = [['name' => $p['owner'][0], 'email' => $p['owner'][1].'@'.$p['domain'], 'role' => 'Owner']];

            foreach ($p['staff'] as [, $name, $mailbox, $role]) {
                $accounts[] = ['name' => $name, 'email' => $mailbox.'@'.$p['domain'], 'role' => $role];
            }

            $out[] = ['academy' => $p['name'], 'kind' => $kinds[$p['kind']] ?? $p['kind'], 'accounts' => $accounts];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function profiles(): array
    {
        $only = (array) config('demo.only', []);

        return array_values(array_filter(DemoProfiles::all(), fn (array $p) => $only === [] || in_array($p['slug'], $only, true)));
    }

    private function removeAll(): int
    {
        $removed = 0;

        foreach ($this->tenants() as $tenant) {
            $this->lifecycle->removeDemo($tenant);
            $removed++;
        }

        return $removed;
    }

    private function guard(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo academies are for staging and development. They are never created on production.');
        }
    }

    private function remember(string $state, ?string $message): void
    {
        Cache::put(self::STATUS_KEY, ['state' => $state, 'message' => $message, 'updated_at' => now()->toIso8601String()], now()->addDays(30));
    }
}

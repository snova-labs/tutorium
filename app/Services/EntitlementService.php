<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;
use App\Support\Billing\Entitlement;
use App\Support\Billing\EntitlementSource;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

/**
 * The only way the product asks what an account may do.
 *
 * Nothing else queries plans, subscriptions or licence files. That single rule is what lets the
 * same codebase run as metered SaaS and as a self-hosted install with an annual licence, and it is
 * why a feature check never needs to know which (FR-ENT-2).
 */
final class EntitlementService
{
    /** Shipped defaults, used when neither a plan nor a licence has an opinion. */
    private const DEFAULTS = [
        'max_brands' => 1,
        'max_branches' => null,
        'max_staff' => 5,
        'api_access' => false,
        'custom_domain' => false,
        'sso' => false,
        'ms_integrations' => false,
        'scheduled_reports' => false,
    ];

    /** @var array<int, array<string, Entitlement>> */
    private array $cache = [];

    public function __construct(
        private readonly EntitlementSource $source,
        private readonly TenantContext $tenancy,
    ) {}

    public function allows(string $feature, ?Tenant $tenant = null): bool
    {
        return $this->get($feature, $tenant)->asBool();
    }

    public function limit(string $key, ?Tenant $tenant = null): ?int
    {
        return $this->get($key, $tenant)->asInt();
    }

    public function get(string $key, ?Tenant $tenant = null): Entitlement
    {
        $tenant ??= $this->tenancy->require();

        $resolved = $this->all($tenant)[$key] ?? null;

        return $resolved ?? new Entitlement(
            key: $key,
            value: self::DEFAULTS[$key] ?? null,
            source: Entitlement::SOURCE_DEFAULT,
            isHard: false,
        );
    }

    /** @return array<string, Entitlement> */
    public function all(?Tenant $tenant = null): array
    {
        $tenant ??= $this->tenancy->require();

        return $this->cache[$tenant->getKey()] ??= $this->source->all($tenant);
    }

    /**
     * Refuse an action that would exceed a hard limit.
     *
     * Deliberately named for creation and documented as such: a hard limit blocks the action that
     * would take an account over the line, and never a save that would discard work already
     * entered. A teacher part-way through a register must always be able to finish it, whatever
     * the account owes us (FR-ENT-3).
     */
    public function guardCreation(string $key, int $currentCount, ?Tenant $tenant = null): void
    {
        $entitlement = $this->get($key, $tenant);

        if ($entitlement->isUnlimited() || ! $entitlement->isHard) {
            return;
        }

        $limit = $entitlement->asInt();

        if ($currentCount < $limit) {
            return;
        }

        throw ValidationException::withMessages([
            $key => sprintf(
                'Your plan covers %d. You have %d. Upgrade to add more.',
                $limit,
                $currentCount,
            ),
        ]);
    }

    /**
     * Whether an account is over a soft limit — worth telling someone about, never worth blocking.
     *
     * @return array<int, array{key: string, limit: int, current: int}>
     */
    public function softBreaches(array $counts, ?Tenant $tenant = null): array
    {
        $breaches = [];

        foreach ($counts as $key => $current) {
            $entitlement = $this->get($key, $tenant);

            if ($entitlement->isUnlimited() || $entitlement->isHard) {
                continue;
            }

            $limit = $entitlement->asInt();

            if ($limit !== null && $current > $limit) {
                $breaches[] = ['key' => $key, 'limit' => $limit, 'current' => $current];
            }
        }

        return $breaches;
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    public function sourceName(): string
    {
        return $this->source->name();
    }
}

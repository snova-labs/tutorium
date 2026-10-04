<?php

declare(strict_types=1);

namespace App\Support\Settings;

use App\Models\Setting;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/**
 * Resolves a setting through the scope chain, most specific first:
 *
 *   batch → course → branch → brand → tenant → preset seed → system default
 *
 * The resolver also reports which level answered, because inherited configuration that cannot be
 * traced is worse than no configuration at all — every settings screen shows the effective value
 * and its origin (SL-ARC-002 §7).
 */
final class SettingsResolver
{
    /** @var array<string, Collection<int, Setting>> */
    private array $loaded = [];

    public function __construct(private readonly TenantContext $context) {}

    public function get(string $key, ?ResolutionScope $scope = null, mixed $default = null): mixed
    {
        return $this->resolve($key, $scope)->value ?? $default ?? $this->systemDefault($key);
    }

    /**
     * The value together with the level that supplied it. Used by settings screens and by any
     * feature that must explain itself to a user.
     */
    public function explain(string $key, ?ResolutionScope $scope = null): ResolvedSetting
    {
        $setting = $this->resolve($key, $scope);

        if ($setting !== null) {
            return new ResolvedSetting(
                key: $key,
                value: $setting->value,
                source: $setting->scope_type,
                sourceId: $setting->scope_id,
                isInherited: $setting->scope_type !== ($scope?->mostSpecificType() ?? 'tenant'),
            );
        }

        return new ResolvedSetting(
            key: $key,
            value: $this->systemDefault($key),
            source: 'system',
            sourceId: null,
            isInherited: true,
        );
    }

    public function set(string $key, mixed $value, string $scopeType = 'tenant', ?int $scopeId = null): Setting
    {
        $setting = Setting::query()->updateOrCreate(
            [
                'tenant_id' => $this->context->require()->getKey(),
                'key' => $key,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
            ],
            ['value' => $value],
        );

        $this->flush();

        return $setting;
    }

    /** Remove an override so the value inherits again. */
    public function forget(string $key, string $scopeType, ?int $scopeId = null): void
    {
        Setting::query()
            ->where('key', $key)
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->delete();

        $this->flush();
    }

    public function flush(): void
    {
        $this->loaded = [];
    }

    private function resolve(string $key, ?ResolutionScope $scope): ?Setting
    {
        $candidates = $this->candidates($key);
        $chain = ($scope ?? ResolutionScope::tenant())->chain();

        foreach ($chain as [$type, $id]) {
            $match = $candidates->first(
                fn (Setting $setting) => $setting->scope_type === $type && $setting->scope_id === $id,
            );

            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    /**
     * All rows for a key are loaded once per request and filtered in memory. A key is read many
     * times per page; five queries per read would be the wrong trade.
     *
     * @return Collection<int, Setting>
     */
    private function candidates(string $key): Collection
    {
        return $this->loaded[$key] ??= Setting::query()->where('key', $key)->get();
    }

    private function systemDefault(string $key): mixed
    {
        return config("settings.defaults.{$key}");
    }
}

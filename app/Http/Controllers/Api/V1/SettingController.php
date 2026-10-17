<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\Settings\SettingsResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * How the academy behaves: the settings the resolver answers, with where each answer came from.
 *
 * The resolver has always been able to explain itself (SL-ARC-002 §7) and nothing exposed it, so
 * no screen could tell a value the preset chose from one somebody here chose. Both are served
 * here, with the catalogue in config/settings.php deciding what each key means and which control
 * it gets — a key absent from that catalogue is not offered, and writing to it is a 404.
 */
final class SettingController extends Controller
{
    public function __construct(private readonly SettingsResolver $settings) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);

        $groups = [];

        foreach ($this->catalogue() as $group => $definition) {
            $groups[] = [
                'key' => $group,
                'name' => $definition['name'],
                'settings' => array_map(
                    fn (string $key) => $this->setting($key, $definition['keys'][$key]),
                    array_keys($definition['keys']),
                ),
            ];
        }

        return response()->json([
            'data' => $groups,
            'meta' => ['roles' => $this->roles()],
        ]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);

        $definition = $this->definitionFor($key);
        $value = $this->validated($request, $key, $definition);

        $this->settings->set($key, $value, origin: Setting::ORIGIN_ACADEMY);

        return response()->json([
            'data' => $this->setting($key, $definition),
            'meta' => ['message' => sprintf('Saved. %s.', $definition['label'])],
        ]);
    }

    /**
     * Drop the academy's own value so the key inherits again — from the preset if it wrote one,
     * otherwise from the shipped default. The point of a revert is to get back to not having
     * decided, which is different from setting the default by hand.
     */
    public function destroy(Request $request, string $key): JsonResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);

        $definition = $this->definitionFor($key);
        $this->settings->forget($key, 'tenant');

        return response()->json([
            'data' => $this->setting($key, $definition),
            'meta' => ['message' => 'Back to the value you were given.'],
        ]);
    }

    /** @return array<string, array{name: string, keys: array<string, array<string, mixed>>}> */
    private function catalogue(): array
    {
        /** @var array<string, array{name: string, keys: array<string, array<string, mixed>>}> $catalogue */
        $catalogue = (array) config('settings.screen');

        return $catalogue;
    }

    /** @return array<string, mixed> */
    private function definitionFor(string $key): array
    {
        foreach ($this->catalogue() as $group) {
            if (isset($group['keys'][$key])) {
                return $group['keys'][$key];
            }
        }

        // Not every resolver key is a thing to offer: some belong to a location, some are internal.
        throw new NotFoundHttpException("[{$key}] is not a setting this screen offers.");
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function setting(string $key, array $definition): array
    {
        $resolved = $this->settings->explain($key);

        return [
            'key' => $key,
            'value' => $resolved->value,
            'type' => $definition['type'],
            'label' => $definition['label'],
            'help' => $definition['help'],
            'choices' => $definition['choices'] ?? null,
            'min' => $definition['min'] ?? null,
            'max' => $definition['max'] ?? null,
            // Where the answer came from: the level, and whether a person here chose it.
            'source' => $resolved->source,
            'origin' => $resolved->origin,
            // Anything stored at tenant level that the preset did not write is this academy's to
            // revert — including rows written before the origin column existed, whose origin is
            // null. Calling those a shipped default would be untrue: a value IS stored.
            'set_here' => $resolved->source === 'tenant' && $resolved->origin !== Setting::ORIGIN_PRESET,
            // A key contains dots itself, so the defaults are read from the array rather than
            // through config()'s dot notation, which would look for a nested entry and miss.
            'shipped_default' => $this->shippedDefaults()[$key] ?? null,
        ];
    }

    /**
     * Every role a setting may name.
     *
     * The owner role is configured on its own rather than in the roles catalogue, so a list built
     * from the catalogue alone would refuse the one role every academy has.
     *
     * @return list<string>
     */
    private function roles(): array
    {
        $owner = (string) config('permissions.owner_role', 'Owner');

        return array_values(array_unique([$owner, ...array_keys((array) config('permissions.roles'))]));
    }

    /** @return array<string, mixed> */
    private function shippedDefaults(): array
    {
        /** @var array<string, mixed> $defaults */
        $defaults = (array) config('settings.defaults');

        return $defaults;
    }

    /** @param array<string, mixed> $definition */
    private function validated(Request $request, string $key, array $definition): mixed
    {
        $rules = match ($definition['type']) {
            'switch' => ['required', 'boolean'],
            'number' => ['required', 'integer', 'min:'.($definition['min'] ?? 0), 'max:'.($definition['max'] ?? 1000)],
            'choice' => ['required', 'string', 'in:'.implode(',', array_keys($definition['choices'] ?? []))],
            'roles' => ['present', 'array'],
            default => ['required'],
        };

        $data = Validator::make($request->all(), ['value' => $rules] + ($definition['type'] === 'roles'
            ? ['value.*' => ['string', 'in:'.implode(',', $this->roles())]]
            : []))->validate();

        return $data['value'];
    }
}

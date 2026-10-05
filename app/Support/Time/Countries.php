<?php

declare(strict_types=1);

namespace App\Support\Time;

use DateTimeZone;
use Locale;

/**
 * Countries for the signup form, and the timezones in each.
 *
 * Read from PHP's own timezone database rather than a hand-kept list, so the two can never
 * disagree: every country offered has at least one timezone the branch can be set to.
 */
final class Countries
{
    /** @var array<string, list<string>>|null */
    private static ?array $zones = null;

    /**
     * ISO 3166-1 alpha-2 code => English name, sorted by name.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        $names = [];

        foreach (array_keys(self::zones()) as $code) {
            $names[$code] = self::name($code);
        }

        asort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $names;
    }

    public static function exists(string $code): bool
    {
        return isset(self::zones()[strtoupper($code)]);
    }

    /** @return list<string> */
    public static function timezones(string $code): array
    {
        return self::zones()[strtoupper($code)] ?? [];
    }

    public static function name(string $code): string
    {
        $code = strtoupper($code);

        if (class_exists(Locale::class)) {
            $name = Locale::getDisplayRegion('-'.$code, 'en');

            if ($name !== '' && $name !== $code) {
                return $name;
            }
        }

        return $code;
    }

    /** @return array<string, list<string>> */
    private static function zones(): array
    {
        if (self::$zones !== null) {
            return self::$zones;
        }

        $zones = [];

        foreach (DateTimeZone::listIdentifiers() as $identifier) {
            $country = (new DateTimeZone($identifier))->getLocation()['country_code'] ?? '??';

            if ($country !== '??') {
                $zones[$country][] = $identifier;
            }
        }

        return self::$zones = $zones;
    }
}

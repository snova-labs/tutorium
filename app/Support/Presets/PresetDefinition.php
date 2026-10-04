<?php

declare(strict_types=1);

namespace App\Support\Presets;

use Illuminate\Contracts\Support\Arrayable;

/**
 * One preset, with the shared defaults already merged in.
 *
 * @implements Arrayable<string, mixed>
 */
final class PresetDefinition implements Arrayable
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly string $code,
        public readonly array $payload,
    ) {}

    public function name(): string
    {
        return $this->payload['name'];
    }

    public function version(): int
    {
        return (int) $this->payload['version'];
    }

    public function summary(): string
    {
        return $this->payload['summary'] ?? '';
    }

    public function vertical(): string
    {
        return $this->payload['vertical'] ?? 'any';
    }

    /**
     * The rows of a list-shaped section (statuses, types), each one a positional list.
     *
     * @return array<int, list<mixed>>
     */
    public function rows(string $key): array
    {
        return array_values(array_map(
            static fn (array $row): array => array_values($row),
            array_filter($this->section($key), is_array(...)),
        ));
    }

    /** @return array<string, mixed> */
    public function section(string $key): array
    {
        return $this->payload[$key] ?? [];
    }

    /**
     * What this preset will set, in plain language, for the screen that asks a customer to choose.
     *
     * @return array<string, string>
     */
    public function preview(): array
    {
        $settings = $this->section('settings');
        $terminology = $this->section('terminology');
        $modules = $this->section('modules');

        return [
            'Learners are called' => $terminology['learner'][1] ?? 'Learners',
            'Guardians' => ($modules['people.guardians_enabled'] ?? true) ? 'On' : 'Off',
            'Reporting period' => ucfirst((string) ($settings['periods.type'] ?? 'monthly')),
            'Week starts' => ucfirst((string) ($settings['locale.week_start'] ?? 'monday')),
            'Weekend' => implode(', ', array_map('ucfirst', $settings['locale.weekend_days'] ?? ['saturday', 'sunday'])),
            'Attendance' => ($settings['attendance.compulsory'] ?? true) ? 'Compulsory' : 'Informational',
            'Grading' => implode(', ', array_column($this->section('grading_schemes'), 0)),
            'Assessment types' => implode(', ', array_column($this->section('assessment_types'), 0)),
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name(),
            'vertical' => $this->vertical(),
            'version' => $this->version(),
            'summary' => $this->summary(),
            'sets' => $this->preview(),
        ];
    }
}

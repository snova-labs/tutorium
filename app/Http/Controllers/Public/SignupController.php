<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Services\SignupService;
use App\Support\Presets\PresetDefinition;
use App\Support\Presets\PresetRepository;
use App\Support\Time\Countries;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The public door. No authentication, and therefore the most carefully guarded surface here.
 */
final class SignupController
{
    /** How each kind of academy is named on the form. */
    private const VERTICALS = [
        'tutoring' => 'Tutoring centre',
        'language' => 'Language school',
        'skills' => 'Skills or training institute',
    ];

    public function __construct(
        private readonly SignupService $signup,
        private readonly PresetRepository $presets,
    ) {}

    /** What the signup form needs to render itself. */
    public function options(): JsonResponse
    {
        $presets = $this->presets->all()->filter(fn (PresetDefinition $p) => $p->code !== 'blank');

        return response()->json([
            'data' => [
                'open' => (bool) config('signup.open', true),
                // The kind of academy, then the starting point within it.
                'verticals' => $presets->map(fn (PresetDefinition $p) => $p->vertical())->unique()->values()
                    ->map(fn (string $vertical) => ['code' => $vertical, 'name' => self::VERTICALS[$vertical] ?? ucfirst($vertical)]),
                'presets' => $presets->map(fn (PresetDefinition $p) => [
                    'code' => $p->code,
                    'vertical' => $p->vertical(),
                    'name' => $p->name(),
                    'summary' => $p->summary(),
                ])->values(),
                'countries' => collect(Countries::all())
                    ->map(fn (string $name, string $code) => [
                        'code' => $code,
                        'name' => $name,
                        'timezones' => Countries::timezones($code),
                    ])->values(),
                'trial_days' => (int) config('signup.trial_days', 14),
                'terms' => [
                    'No card required for the trial.',
                    'Everything you enter during the trial carries over if you continue.',
                    'We never sell your data or use it to train models.',
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! config('signup.open', true)) {
            return response()->json([
                'message' => 'New accounts are paused for the moment. Please try again later.',
            ], 503);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'owner_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'email:rfc,dns', 'max:190'],
            'password' => ['required', 'string', 'min:12', 'max:255'],
            'country' => ['required', 'string', 'size:2', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! Countries::exists($value)) {
                    $fail('Choose your country from the list.');
                }
            }],
            'timezone' => ['required', 'timezone:all'],
            'preset_code' => ['required', 'string', Rule::in($this->presets->all()->keys())],
            'week_start' => ['nullable', 'string'],
            'weekend_days' => ['nullable', 'array'],
            'locale' => ['nullable', 'string', 'max:12'],
        ], [
            // A twelve-character minimum with no composition rules: length is what helps, and
            // symbol requirements mostly produce Password1! on a sticky note.
            'password.min' => 'Use at least 12 characters. A short phrase works well.',
            'timezone.timezone' => 'Choose a timezone such as Asia/Kathmandu or America/Toronto.',
            'preset_code.in' => 'Choose the kind of academy from the list.',
        ]);

        $pending = $this->signup->signUp($validated, $request->ip());

        // 202: accepted, and nothing exists yet until the address is confirmed.
        return response()->json([
            'data' => [
                'email' => $pending->email,
                'expires_at' => $pending->expires_at->toIso8601String(),
                'next' => 'Check your email and use the link to create your account. Nothing is set up until you do.',
            ],
        ], 202);
    }

    /** What a confirmation link will create. Looking does not use it. */
    public function show(string $token): JsonResponse
    {
        return response()->json(['data' => $this->signup->describe($token)]);
    }

    /** Use the link: the account is provisioned now and the trial starts. */
    public function confirm(string $token): JsonResponse
    {
        $result = $this->signup->confirm($token);

        return response()->json([
            'data' => [
                'account' => $result['tenant']->name,
                'email' => $result['owner']->email,
                'trial_ends_on' => $result['tenant']->trial_ends_at?->toDateString(),
                'next' => 'Your account is ready. Sign in with your email and password.',
            ],
        ], 201);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Services\SignupService;
use App\Support\Presets\PresetDefinition;
use App\Support\Presets\PresetRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The public door. No authentication, and therefore the most carefully guarded surface here.
 */
final class SignupController
{
    public function __construct(
        private readonly SignupService $signup,
        private readonly PresetRepository $presets,
    ) {}

    /** What the signup form needs to render itself. */
    public function options(): JsonResponse
    {
        return response()->json([
            'data' => [
                'presets' => $this->presets->all()
                    ->filter(fn (PresetDefinition $p) => $p->code !== 'blank')
                    ->map(fn (PresetDefinition $p) => [
                        'code' => $p->code,
                        'name' => $p->name(),
                        'summary' => $p->summary(),
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
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'owner_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'email:rfc,dns', 'max:190'],
            'password' => ['required', 'string', 'min:12', 'max:255'],
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
        ]);

        $result = $this->signup->signUp($validated, $request->ip());

        return response()->json([
            'data' => [
                'account' => $result['tenant']->name,
                'trial_ends_on' => $result['tenant']->trial_ends_at?->toDateString(),
                'next' => 'Check your email to confirm your address. You can start setting up straight away.',
            ],
        ], 201);
    }

    public function verify(Request $request, string $token): JsonResponse
    {
        $user = $this->signup->verify($token);

        return response()->json([
            'data' => [
                'email' => $user->email,
                'message' => 'Address confirmed. Reports and notices can now be sent to families.',
            ],
        ]);
    }
}

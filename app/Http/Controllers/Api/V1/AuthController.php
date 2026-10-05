<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\SignInCodePolicy;
use App\Services\SignInCodes;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class AuthController
{
    public function __construct(
        private readonly SignInCodes $signInCodes,
        private readonly SignInCodePolicy $signInCodePolicy,
    ) {}

    public function login(Request $request, TenantContext $tenancy): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'code' => ['nullable', 'string', 'max:16'],
            'device' => ['nullable', 'string', 'max:60'],
        ]);

        $throttleKey = strtolower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many attempts. Try again in '
                    .ceil(RateLimiter::availableIn($throttleKey) / 60).' minutes.',
            ])->status(429);
        }

        // The lookup crosses tenants deliberately: at sign-in there is no tenant bound yet, and
        // the same address may staff more than one academy.
        $user = $tenancy->withoutScoping(
            fn () => User::query()->where('email', $credentials['email'])->first(),
        );

        if ($user === null || ! Hash::check($credentials['password'], $user->password) || ! $user->is_active) {
            RateLimiter::hit($throttleKey, 900);

            // One message for every failure. Telling a caller which part was wrong tells them
            // which addresses exist.
            throw ValidationException::withMessages([
                'email' => 'Those details do not match an active account.',
            ]);
        }

        // The second step, for the roles the academy has chosen: sign in again with the same
        // details plus the code just emailed.
        if ($this->signInCodePolicy->requiresCode($user)) {
            $code = $credentials['code'] ?? null;

            if ($code === null) {
                $this->signInCodes->send($user);

                throw ValidationException::withMessages([
                    'code' => 'We have emailed you a sign-in code. Enter it to finish signing in.',
                ]);
            }

            if (! $this->signInCodes->verify($user, $code)) {
                RateLimiter::hit($throttleKey, 900);

                throw ValidationException::withMessages([
                    'code' => 'That code is not right, or it has expired. Sign in again for a new one.',
                ]);
            }
        }

        RateLimiter::clear($throttleKey);

        $tenancy->set($user->tenant);
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        // A named ability rather than the default wildcard, so this token is never mistaken for
        // support access (which is marked "impersonate").
        $token = $user->createToken($credentials['device'] ?? 'api', ['staff'])->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => new UserResource($user->load('tenant')),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['data' => ['message' => 'Signed out.']]);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('tenant'));
    }
}

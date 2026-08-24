<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class AuthController
{
    public function login(Request $request, TenantContext $tenancy): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
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

        RateLimiter::clear($throttleKey);

        $tenancy->set($user->tenant);
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        $token = $user->createToken($credentials['device'] ?? 'api')->plainTextToken;

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

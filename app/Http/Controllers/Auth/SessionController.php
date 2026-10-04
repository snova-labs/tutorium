<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Models\User;
use App\Services\SignInCodePolicy;
use App\Services\SignInCodes;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class SessionController
{
    /** Where a signed-in-by-password, not-yet-coded user waits. Never an authenticated session. */
    private const PENDING = 'sign_in.pending';

    public function __construct(
        private readonly SignInCodes $signInCodes,
        private readonly SignInCodePolicy $signInCodePolicy,
    ) {}

    public function show(): View
    {
        return view('auth.sign-in');
    }

    public function store(Request $request, TenantContext $tenancy): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = mb_strtolower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many attempts. Try again in '
                    .ceil(RateLimiter::availableIn($throttleKey) / 60).' minutes.',
            ]);
        }

        // No tenant is bound at sign-in, and the same address may legitimately
        // staff more than one academy, so the lookup deliberately crosses tenants.
        $user = $tenancy->withoutScoping(
            fn () => User::query()->where('email', $credentials['email'])->first(),
        );

        if ($user === null || ! Hash::check($credentials['password'], $user->password) || ! $user->is_active) {
            RateLimiter::hit($throttleKey, 900);

            // One message for every failure. Telling someone which half was wrong
            // tells them which addresses exist here.
            throw ValidationException::withMessages([
                'email' => 'Those details do not match an active account.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        if ($this->signInCodePolicy->requiresCode($user)) {
            $request->session()->put(self::PENDING, [
                'user_id' => $user->getKey(),
                'remember' => $request->boolean('remember'),
                'expires_at' => now()->addMinutes(SignInCodes::MINUTES_VALID)->getTimestamp(),
            ]);
            $this->signInCodes->send($user);

            return redirect()->route('sign-in.code');
        }

        return $this->completeSignIn($request, $user, $request->boolean('remember'));
    }

    public function showCode(Request $request, TenantContext $tenancy): View|RedirectResponse
    {
        $user = $this->pendingUser($request, $tenancy);

        if ($user === null) {
            return redirect()->route('sign-in');
        }

        return view('auth.sign-in-code', ['email' => $user->email]);
    }

    public function verifyCode(Request $request, TenantContext $tenancy): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:16']]);
        $user = $this->pendingUser($request, $tenancy);

        if ($user === null) {
            return redirect()->route('sign-in')
                ->withErrors(['email' => 'That took too long. Sign in again for a new code.']);
        }

        if (! $this->signInCodes->verify($user, $validated['code'])) {
            throw ValidationException::withMessages([
                'code' => 'That code is not right, or it has expired.',
            ]);
        }

        $remember = (bool) ($request->session()->get(self::PENDING.'.remember') ?? false);
        $request->session()->forget(self::PENDING);

        return $this->completeSignIn($request, $user, $remember);
    }

    public function resendCode(Request $request, TenantContext $tenancy): RedirectResponse
    {
        $user = $this->pendingUser($request, $tenancy);

        if ($user === null) {
            return redirect()->route('sign-in');
        }

        $sent = $this->signInCodes->send($user);
        $request->session()->put(self::PENDING.'.expires_at', now()->addMinutes(SignInCodes::MINUTES_VALID)->getTimestamp());

        return redirect()->route('sign-in.code')->with('status', $sent
            ? 'A new code is on its way.'
            : 'A code was sent moments ago. Give it a minute before asking for another.');
    }

    private function completeSignIn(Request $request, User $user, bool $remember): RedirectResponse
    {
        Auth::login($user, $remember);
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return redirect()->intended(route('dashboard'));
    }

    /** The user who passed the password step in this browser, if that was recent enough. */
    private function pendingUser(Request $request, TenantContext $tenancy): ?User
    {
        $pending = $request->session()->get(self::PENDING);

        if (! is_array($pending) || ($pending['expires_at'] ?? 0) < now()->getTimestamp()) {
            $request->session()->forget(self::PENDING);

            return null;
        }

        $user = $tenancy->withoutScoping(
            fn () => User::query()->whereKey($pending['user_id'] ?? null)->first(),
        );

        return $user !== null && $user->is_active ? $user : null;
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('sign-in');
    }
}

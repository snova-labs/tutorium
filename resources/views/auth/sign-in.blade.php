<x-layouts.plain :title="'Sign in'">
    <div class="w-full max-w-sm">
        <div class="mb-6 flex items-center gap-2">
            <span class="grid size-8 place-items-center rounded-md bg-primary text-sm
                         font-bold text-white">T</span>
            <span class="font-semibold">{{ config('platform.name', 'Platform') }}</span>
        </div>

        <div class="card p-6">
            <h1 class="mb-1 text-lg font-semibold">Sign in</h1>
            <p class="mb-5 text-xs text-muted">
                Staff accounts only. Guardians and learners do not have logins.
            </p>

            <form method="POST" action="{{ route('sign-in.store') }}" class="space-y-4">
                @csrf

                <div>
                    <label class="field-label" for="email">Work email</label>
                    <input id="email" name="email" type="email" class="input" required autofocus
                           autocomplete="username" value="{{ old('email') }}">
                </div>

                <div>
                    <label class="field-label" for="password">Password</label>
                    <input id="password" name="password" type="password" class="input" required
                           autocomplete="current-password">
                </div>

                @error('email')
                    <p class="text-xs text-bad">{{ $message }}</p>
                @enderror

                <label class="flex items-center gap-2 text-xs text-muted">
                    <input type="checkbox" name="remember" class="rounded border-line">
                    Keep me signed in on this device
                </label>

                <button type="submit" class="btn btn-primary w-full">Sign in</button>
            </form>
        </div>

        <p class="mt-4 text-center text-[11px] text-faint">
            Five failed attempts pause sign-in for this address for fifteen minutes.
        </p>
    </div>
</x-layouts.plain>

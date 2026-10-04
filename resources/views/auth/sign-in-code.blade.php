<x-layouts.plain :title="'Enter your sign-in code'">
    <div class="w-full max-w-sm">
        <div class="mb-6 flex items-center gap-2">
            <span class="grid size-8 place-items-center rounded-md bg-primary text-sm
                         font-bold text-white">T</span>
            <span class="font-semibold">{{ config('platform.name', 'Platform') }}</span>
        </div>

        <div class="card p-6">
            <h1 class="mb-1 text-lg font-semibold">Check your email</h1>
            <p class="mb-5 text-xs text-muted">
                We have sent a six-digit code to {{ $email }}. It expires in ten minutes.
            </p>

            @if (session('status'))
                <p class="mb-4 text-xs text-muted">{{ session('status') }}</p>
            @endif

            <form method="POST" action="{{ route('sign-in.code.verify') }}" class="space-y-4">
                @csrf

                <div>
                    <label class="field-label" for="code">Sign-in code</label>
                    <input id="code" name="code" type="text" class="input" required autofocus
                           inputmode="numeric" autocomplete="one-time-code" maxlength="16">
                </div>

                @error('code')
                    <p class="text-xs text-bad">{{ $message }}</p>
                @enderror

                <button type="submit" class="btn btn-primary w-full">Sign in</button>
            </form>

            <form method="POST" action="{{ route('sign-in.code.resend') }}" class="mt-4 text-center">
                @csrf
                <button type="submit" class="text-xs text-muted underline">Send a new code</button>
            </form>
        </div>

        <p class="mt-4 text-center text-[11px] text-faint">
            Not you signing in? Change your password, and tell whoever runs your academy's account.
        </p>
    </div>
</x-layouts.plain>

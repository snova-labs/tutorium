<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('platform.name', 'Platform') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600|jetbrains-mono:400,500"
          rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full bg-page font-sans text-ink antialiased">

@php
    $tenant = app(\App\Support\Tenancy\TenantContext::class)->get();
    $terms = app(\App\Support\Terminology\Terminology::class);
@endphp

{{-- A read-only account should never have to guess why a save button is gone. --}}
@if ($tenant?->isSuspended())
    <div class="bg-warn-soft px-4 py-2 text-center text-sm text-warn ring-1 ring-warn/20">
        This account is read-only. Everything is still here and you can export all of it —
        <a href="{{ route('billing') }}" class="font-medium underline">restore full access</a>.
    </div>
@endif

<div class="mx-auto flex min-h-full max-w-7xl flex-col">

    <header class="sticky top-0 z-20 border-b border-line bg-surface/90 backdrop-blur">
        <div class="flex items-center gap-4 px-4 py-3 sm:px-6">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-2">
                <span class="grid size-7 place-items-center rounded-md bg-primary text-xs
                             font-bold text-white">{{ mb_substr($tenant?->name ?? 'P', 0, 1) }}</span>
                <span class="hidden text-sm font-semibold sm:inline">{{ $tenant?->name }}</span>
            </a>

            <nav class="ml-auto flex items-center gap-1 text-sm">
                {{-- Labels come from the tenant's own vocabulary, not ours. --}}
                <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                    Today
                </x-nav-link>
                <x-nav-link :href="route('learners')" :active="request()->routeIs('learners')">
                    {{ $terms->plural('learner') }}
                </x-nav-link>
            </nav>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="text-xs text-muted hover:text-ink">Sign out</button>
            </form>
        </div>
    </header>

    <main class="flex-1 px-4 py-6 sm:px-6">
        {{ $slot }}
    </main>

    <footer class="px-4 py-4 text-center sm:px-6">
        <p class="font-mono text-[10px] text-faint">
            Times are shown in your timezone, {{ auth()->user()?->timezone ?? 'UTC' }}.
            Where a class is taught elsewhere, both are shown.
        </p>
    </footer>
</div>

@livewireScripts
</body>
</html>

<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('platform.name', 'Platform') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600|jetbrains-mono:400,500" rel="stylesheet">
    @vite(['resources/css/app.css'])
</head>
<body class="grid min-h-full place-items-center bg-page px-4 font-sans text-ink antialiased">
    {{ $slot }}
</body>
</html>

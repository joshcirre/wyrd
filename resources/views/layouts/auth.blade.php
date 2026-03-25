<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <meta name="csrf-token" content="{{ csrf_token() }}" />

        <title>{{ config('app.name', 'My Laravel App') }}</title>

        <link
            rel="icon"
            href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='16' fill='%23000'/><text x='50' y='76' font-size='72' font-family='system-ui' font-weight='700' fill='%23fff' text-anchor='middle'>?</text></svg>"
        />

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400..600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @fluxAppearance
    </head>

    <body class="m-auto flex min-h-screen max-w-md items-center justify-center bg-white dark:bg-zinc-800">
        <div class="w-full">
            {{ $slot }}
        </div>
        @persist('toast')
            <flux:toast />
        @endpersist

        @fluxScripts
    </body>
</html>

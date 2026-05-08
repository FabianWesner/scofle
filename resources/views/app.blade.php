<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='7' fill='%230f766e'/%3E%3Cpath d='M10 22c1.5 1.2 3.4 1.8 5.8 1.8 3.7 0 6.2-1.7 6.2-4.5 0-2.5-1.6-3.6-5.2-4.4-2.4-.6-3.1-.9-3.1-1.7 0-.9.8-1.4 2.3-1.4 1.6 0 3 .5 4.2 1.5l1.3-2.5c-1.4-1.2-3.2-1.8-5.5-1.8-3.5 0-5.9 1.8-5.9 4.5 0 2.4 1.5 3.5 5.1 4.3 2.5.6 3.2 1 3.2 1.8 0 .9-.9 1.4-2.5 1.4-1.9 0-3.5-.6-4.8-1.7L10 22Z' fill='white'/%3E%3C/svg%3E">

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
        <x-inertia::head>
            <title>Scofle</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>{{ config('app.name') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&display=swap"
        rel="stylesheet"
    >

    {{-- Preamble React Refresh. WAJIB sebelum @vite, dan wajib ada karena
         root view di sini Blade, bukan index.html: plugin React mencari
         preamble yang biasanya disuntikkan plugin ke HTML, tidak menemukannya,
         lalu melempar "can't detect preamble" dan aplikasi tidak pernah
         mount. Direktif ini yang menyuntikkannya untuk Blade. --}}
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>

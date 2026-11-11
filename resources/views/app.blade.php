<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>{{ config('app.name') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    {{-- Syne, Onest dan JetBrains Mono adalah material konsol siswa (tema
         Terrazzo): kepala geometris yang lebar, badan sans yang tenang, dan
         mono untuk waktu serta kode saja. Ketiganya dimuat di sini, bukan
         lewat @import di CSS, karena berkas ini sudah punya preconnect ke
         fonts.gstatic.com dan satu permintaan koneksi untuk enam keluarga
         lebih murah daripada dua. Archivo dan IBM Plex tetap dipakai empat
         peran lain. --}}
    <link
        href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&family=JetBrains+Mono:wght@500&family=Onest:wght@400;500&family=Syne:wght@700&display=swap"
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

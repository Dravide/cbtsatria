<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>{{ config('app.name') }}</title>

    {{-- Identitas untuk mesin pencari dan untuk layar beranda.
         `noindex` dipasang di SETIAP halaman, bukan hanya lewat
         `public/robots.txt`: berkas itu hanya dibaca perayap yang
         menghormatinya, sementara halaman ini memuat nilai, presensi, dan
         jawaban ujian siswa. Meta tag ini ikut pada setiap respons, termasuk
         yang dibuka lewat tautan ruang - dan itu yang membuat halaman di
         balik login tidak pernah masuk indeks meski tautannya tersebar. --}}
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta
        name="description"
        content="{{ config('app.name') }} - computer based test untuk ujian sekolah: Admin, Guru, Pengawas, dan Siswa dalam satu aplikasi."
    >
    <meta name="theme-color" content="#1e4fa3">
    <meta name="color-scheme" content="light dark">
    <meta name="application-name" content="{{ config('app.name') }}">

    {{-- Pratinjau tautan - WhatsApp dan grup sekolah adalah jalur berbagi
         tautan yang sebenarnya dipakai, dan tautan tanpa pratinjau terbaca
         sebagai tautan mencurigakan. Judulnya TIDAK diisi per halaman:
         `@inertiaHead` di bawah yang menimpanya dengan judul halaman, dan
         dua tag og:title akan membuat perayap memilih yang salah. --}}
    <meta property="og:site_name" content="{{ config('app.name') }}">
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ config('app.name') }}">
    <meta
        property="og:description"
        content="Computer based test untuk ujian sekolah: Admin, Guru, Pengawas, dan Siswa dalam satu aplikasi."
    >
    <meta property="og:image" content="{{ asset('icon-512.png') }}">
    <meta name="twitter:card" content="summary">

    {{-- Ikon dan manifest. Lima ukuran karena lima tempat berbeda memintanya:
         tab peramban (ico + 16/32), layar beranda iOS (apple-touch-icon 180,
         yang TIDAK membaca manifest), dan Android/desktop (manifest 192/512).
         Tanpa berkas ini peramban meminta /favicon.ico dan menerima 404,
         lalu menampilkan ikon kosong di tab - satu-satunya penanda sekolah
         yang terlihat saat Admin membuka dua puluh tab. --}}
    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <link rel="icon" href="/favicon-16x16.png" type="image/png" sizes="16x16">
    <link rel="icon" href="/favicon-32x32.png" type="image/png" sizes="32x32">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png" sizes="180x180">
    <link rel="manifest" href="/site.webmanifest">

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

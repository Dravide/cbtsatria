<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Zona waktu aplikasi - SATU untuk seluruh pemasangan
    |--------------------------------------------------------------------------
    |
    | Zona ini adalah jam yang dipakai SELURUH penjadwalan ujian: jendela sesi
    | (`access_opens_at`, `access_closes_at`, `hard_end_at`), validitas token,
    | tautan pintu ruang, dan `now()` yang membandingkan semuanya.
    |
    | ---------------------------------------------------------------------
    | Mengapa satu zona, padahal `schools.timezone` ada
    | ---------------------------------------------------------------------
    | Sampai 07 Nov 2026 nilainya `'UTC'` sementara `schools.timezone` berbunyi
    | `Asia/Jakarta`. Dua konvensi itu hidup berdampingan tanpa satu pun galat -
    | dan akibatnya terlihat di dua tempat sekaligus pada hari ujian:
    |
    |   - Admin mengetik jendela 19:00, tersimpan apa adanya, lalu dibandingkan
    |     terhadap `now()` UTC (13:19) - sehingga layar siswa berbunyi "Sesi ini
    |     belum dibuka. Akses mulai pukul 19:00." pada pukul 19:00;
    |   - `/p/{slug}` memilih ruang-sesi dengan syarat `access_opens_at <= now()`
    |     yang sama, sehingga pintu ruang berbunyi "Ruang belum ditetapkan"
    |     untuk ruang yang tautannya sah.
    |
    | Karena itu jendela sesi disimpan sebagai JAM DINDING sekolah - apa yang
    | diketik Admin, itulah yang dibandingkan - dan zona aplikasi disetel ke
    | zona sekolah lewat `APP_TIMEZONE`. Titik-titik perbandingannya tidak
    | berubah satu pun.
    |
    | ---------------------------------------------------------------------
    | Batas yang diketahui: sekolah di WITA dan WIT
    | ---------------------------------------------------------------------
    | Satu nilai `.env` berlaku untuk seluruh pemasangan, sehingga sekolah
    | `Asia/Makassar` dan `Asia/Jayapura` - keduanya ditawarkan form Profil
    | Sekolah dan perintah `sekolah:daftarkan` - akan meleset 1 dan 2 jam.
    | Itu diterima sadar: hari ini seluruh sekolah pemakai berada di WIB, dan
    | menyelesaikannya berarti mengonversi setiap jendela sesi ke satu acuan
    | bersama, bukan mengubah satu nilai `.env`.
    |
    | Yang HARUS terjadi lebih dulu bila kelak ada sekolah WITA/WIT: tawaran
    | zona di kedua tempat di atas dipersempit, atau konversinya dikerjakan.
    | Membiarkan keduanya apa adanya saat ada sekolah di luar WIB berarti
    | memilih kegagalan yang tidak berbunyi - ujian yang terbuka satu jam
    | terlambat, tanpa satu pun galat yang menunjuk ke sini.
    */

    'timezone' => env('APP_TIMEZONE', 'UTC'),

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache", "array"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];

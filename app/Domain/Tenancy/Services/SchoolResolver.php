<?php

namespace App\Domain\Tenancy\Services;

use Illuminate\Contracts\Auth\Factory as AuthFactory;

/**
 * Satu-satunya sumber school_id.
 *
 * ---------------------------------------------------------------------
 * Mengapa resolver ini malas (lazy), bukan state per-request
 * ---------------------------------------------------------------------
 * Rancangan yang kelihatan wajar adalah: middleware tenant menyetel
 * school_id ke sebuah singleton, lalu global scope membacanya. Itu rusak
 * di Laravel karena SubstituteBindings berada di AKHIR grup middleware
 * `web`. Middleware yang harus mendahului route model binding harus
 * disisipkan di tengah grup - sementara prepend berjalan sebelum
 * EncryptCookies (sesi belum ada) dan append berjalan sesudah binding
 * (terlambat). Laravel tidak menyediakan helper sisip-setelah.
 *
 * Karena itu school_id TIDAK disimpan sebagai state yang disetel
 * middleware. Ia dibaca dari user yang sedang terautentikasi, saat
 * diminta. User sendiri dihidrasi dari sesi, jadi persyaratan "dari sesi"
 * tetap terpenuhi, tanpa satu pun kendala urutan middleware.
 *
 * ---------------------------------------------------------------------
 * Mengapa null itu penting
 * ---------------------------------------------------------------------
 * Sebelum login, id() mengembalikan null - dan SchoolScope sengaja TIDAK
 * melakukan apa pun saat null. Itulah yang membuat login bisa bekerja:
 * Auth::attempt menjalankan User::where('username', ...) pada saat
 * school_id belum diketahui siapa pun. Kalau scope menyaring dengan
 * school_id null, setiap login akan gagal.
 */
class SchoolResolver
{
    /** Cache per-request; resolusi dipanggil berkali-kali per permintaan. */
    private ?int $id = null;

    private bool $resolved = false;

    public function __construct(private readonly AuthFactory $auth) {}

    public function id(): ?int
    {
        if ($this->resolved) {
            return $this->id;
        }

        $this->resolved = true;

        $user = $this->auth->guard()->user();

        $this->id = $user?->school_id !== null ? (int) $user->school_id : null;

        return $this->id;
    }

    /**
     * Apakah ada konteks tenant sama sekali.
     */
    public function hasContext(): bool
    {
        return $this->id() !== null;
    }

    /**
     * Lupakan hasil resolusi.
     *
     * Dipakai setelah login (saat school_id baru diketahui) dan di test,
     * karena resolver ini hidup selama satu permintaan.
     */
    public function forget(): void
    {
        $this->id = null;
        $this->resolved = false;
    }

    /**
     * Tetapkan konteks tenant dari kredensial, bukan dari akun.
     *
     * ---------------------------------------------------------------------
     * Mengapa metode ini ada, dan mengapa hanya satu pemanggilnya
     * ---------------------------------------------------------------------
     * Sampai Fase 5 satu-satunya cara memperoleh konteks sekolah adalah
     * lewat akun yang sedang masuk. Fase 6 membuka pintu kedua: tautan ruang
     * `/p/{slug}` yang dibuka pengawas dari ponselnya, tanpa akun sama
     * sekali.
     *
     * Pada permintaan seperti itu `id()` mengembalikan null - dan null
     * BUKAN "tidak ada sekolah", melainkan "jangan saring". Perilaku itu
     * benar untuk login (scope yang menyaring dengan school_id null akan
     * membuat setiap login gagal), tetapi berbahaya di sini: tanpa
     * penetapan, setiap query di laman pengawas berjalan lintas sekolah,
     * dan kegagalannya bukan galat melainkan kebocoran.
     *
     * Karena itu slug di-resolve lebih dulu lewat `tanpaTenant()`, school_id
     * dibaca dari barisnya, lalu ditetapkan ke sini - dan sesudah ini
     * seluruh query kembali tersaring seperti biasa.
     *
     * HANYA boleh dipanggil middleware pintu ruang, tepat setelah baris
     * `room_entry_links` ditemukan. Nilainya berasal dari baris basis data,
     * tidak pernah dari parameter permintaan: menerima school_id dari
     * pemanggil HTTP berarti membiarkan siapa pun memilih sekolah mana yang
     * ingin dilihatnya.
     */
    public function tetapkan(int $schoolId): void
    {
        $this->id = $schoolId;
        $this->resolved = true;
    }

    /**
     * Jalankan callback TANPA scope tenant.
     *
     * Ini satu-satunya pintu resmi untuk melewati isolasi: seeder, perintah
     * console, dan proses lintas-tenant yang disengaja seperti impor. Setiap
     * pemakaiannya harus bisa dijelaskan - ia ada supaya kode yang butuh
     * melewati isolasi melakukannya secara eksplisit, bukan dengan
     * menambahkan whereNull atau mematikan scope diam-diam.
     */
    public function withoutTenant(callable $callback): mixed
    {
        $sebelumnya = [$this->id, $this->resolved];

        $this->id = null;
        $this->resolved = true;

        try {
            return $callback();
        } finally {
            [$this->id, $this->resolved] = $sebelumnya;
        }
    }
}

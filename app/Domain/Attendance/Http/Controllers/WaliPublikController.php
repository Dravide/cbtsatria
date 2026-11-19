<?php

namespace App\Domain\Attendance\Http\Controllers;

use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Attendance\Services\PemantauWali;
use App\Domain\Tenancy\Models\School;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pantau wali per kelas - `/wali/{slug}`, TANPA login.
 *
 * =====================================================================
 * Tiga aksi, dan yang ketiga adalah gerbangnya
 * =====================================================================
 *   GET   /wali/{slug}                  daftar kelas yang dibuka
 *   GET   /wali/{slug}/kelas/{kelas}    daftar siswa, nama dan ruang saja
 *   POST  /wali/{slug}/kelas/{kelas}    kode kelas → rincian penuh
 *
 * Dua yang pertama sengaja TIDAK menuntut kode. Alasan yang sama dengan
 * `/papan/{slug}`: pertanyaan "anak saya di ruang mana" perlu dijawab tanpa
 * upacara, dan yang dibocorkan olehnya hanya nama yang sudah tertulis di
 * papan ruang. Yang menuntut kode adalah JAM - masuk, mulai, durasi,
 * terkunci - karena hanya itu yang bisa difoto lalu disebar.
 *
 * =====================================================================
 * `withoutGlobalScopes()` di mana-mana, dan itu WAJIB
 * =====================================================================
 * Pada permintaan ini belum ada pengguna, jadi `SchoolResolver::id()`
 * mengembalikan `null` - dan `null` berarti "jangan saring", BUKAN "tidak
 * ada sekolah". Query yang lupa menyebut `school_id` akan membaca SELURUH
 * sekolah. Di sini sekolahnya datang dari slug, lalu diteruskan ke service
 * sebagai parameter - tidak pernah dari permintaan.
 *
 * =====================================================================
 * Kelas yang slug-nya NULL tidak punya alamat sama sekali
 * =====================================================================
 * `classes.wali_slug` nullable, dan yang kosong berakhir 404 - bukan
 * halaman yang menolak dengan 403. 403 membuktikan alamatnya ada.
 */
class WaliPublikController
{
    /** Nama kunci sesi yang menyimpan kelas yang sudah dibuka. */
    private const SESI_KODE = 'wali_terbuka';

    /** Berapa menit satu kode berlaku untuk satu peramban. */
    private const MENIT_SESI = 90;

    public function __construct(private readonly PemantauWali $pemantau) {}

    public function tampilkan(string $slug): Response
    {
        $sekolah = $this->sekolah($slug);

        return Inertia::render('Publik/Wali', [
            'sekolah' => $sekolah->name,
            'slug' => $slug,
            'kelas' => $this->pemantau->kelas($sekolah),
        ]);
    }

    public function kelas(string $slug, int $kelas): Response
    {
        $sekolah = $this->sekolah($slug);
        $model = $this->kelasMilik($sekolah, $kelas);

        $terbuka = $this->terbuka($kelas);

        // Satu laman untuk dua keadaan, dan yang membedakannya kode - bukan
        // URL. Dua alamat berarti dua tautan yang bisa dibagikan, dan yang
        // kedua akan membuka rincian tanpa kode kepada siapa pun yang
        // menemukannya.
        $isi = $terbuka
            ? $this->pemantau->rincian($sekolah, $model)
            : $this->pemantau->ringkas($sekolah, $model);

        return Inertia::render('Publik/WaliKelas', [
            'sekolah' => $sekolah->name,
            'slug' => $slug,
            'terbuka' => $terbuka,
            // Foto hanya dikirim bila ketiga saklar menyala: sekolah,
            // kelas, dan kode. Service sudah menyaring `final`+`sesudah`.
            'boleh_foto' => (bool) $sekolah->wali_foto_boleh && (bool) $model->wali_foto,
            ...$isi,
        ]);
    }

    /**
     * Buka rincian dengan kode enam angka.
     *
     * Pembatasan laju dipasang di rute (`throttle:wali-kelas`), dan yang
     * dijaga di sana BUKAN tebakan kode melainkan tabel yang bisa tumbuh:
     * tanpa batas, satu peramban bisa mencoba sejuta angka dalam satu menit.
     * Kode yang salah TIDAK menulis apa pun - ia hanya menyegarkan halaman
     * dengan pesan.
     */
    public function buka(Request $request, string $slug, int $kelas): RedirectResponse
    {
        $sekolah = $this->sekolah($slug);
        $model = $this->kelasMilik($sekolah, $kelas);

        $data = $request->validate([
            'kode' => ['required', 'string', 'size:6'],
        ], [], ['kode' => 'kode kelas']);

        $benar = $model->wali_code !== null
            && hash_equals((string) $model->wali_code, (string) $data['kode']);

        if (! $benar) {
            RateLimiter::hit($this->kunciKelas($slug, $kelas));

            throw ValidationException::withMessages([
                'kode' => 'Kode kelas tidak cocok. Minta ulang ke wali kelas.',
            ]);
        }

        RateLimiter::clear($this->kunciKelas($slug, $kelas));

        // Disimpan di SESI, bukan di cookie bertanda tangan: yang perlu
        // bertahan hanya sampai peramban ditutup, dan sesi Laravel sudah
        // punya masa berlakunya sendiri.
        $sudah = $request->session()->get(self::SESI_KODE, []);
        $sudah[$kelas] = now()->addMinutes(self::MENIT_SESI)->getTimestamp();
        $request->session()->put(self::SESI_KODE, $sudah);

        return redirect()
            ->route('wali.kelas', ['slug' => $slug, 'kelas' => $kelas])
            ->with('sukses', 'Kode diterima. Rincian kelas terbuka selama '.self::MENIT_SESI.' menit.');
    }

    /* ------------------------------------------------------------ Pembantu */

    /**
     * Apakah kelas ini sudah dibuka di peramban ini, dan belum kedaluwarsa.
     *
     * Batas waktunya diperiksa di SETIAP pembacaan, bukan hanya saat
     * menyimpan: sesi Laravel bisa bertahan lebih lama daripada 90 menit,
     * dan kode yang benar kemarin tidak boleh membuka rincian hari ini.
     */
    private function terbuka(int $kelas): bool
    {
        $sampai = Session::get(self::SESI_KODE.'.'.$kelas);

        return $sampai !== null && now()->getTimestamp() < (int) $sampai;
    }

    private function kunciKelas(string $slug, int $kelas): string
    {
        return 'wali:'.$slug.':'.$kelas;
    }

    /**
     * Kelas milik sekolah di URL - 404 bila bukan.
     *
     * Route model binding TIDAK dipakai, dan itu bukan kerapian: binding
     * mencari `classes.id` di seluruh tabel, sementara di sini belum ada
     * pengguna sehingga scope tenant diam. Id milik sekolah lain akan
     * ditemukan, dan namanya tayang.
     */
    private function kelasMilik(School $sekolah, int $kelas): SchoolClass
    {
        $model = SchoolClass::query()
            ->withoutGlobalScopes()
            ->where('school_id', $sekolah->id)
            ->whereNotNull('wali_slug')
            ->whereKey($kelas)
            ->first();

        if ($model === null) {
            abort(404);
        }

        return $model;
    }

    private function sekolah(string $slug): School
    {
        // `School` TIDAK memakai SchoolScope - ia tabel induknya. Tidak ada
        // `withoutGlobalScopes()` yang perlu disebut, dan justru itu yang
        // membuat barisnya bisa dibaca tanpa pengguna.
        $sekolah = School::query()->where('papan_slug', $slug)->first();

        if ($sekolah === null) {
            abort(404);
        }

        return $sekolah;
    }
}

<?php

namespace App\Domain\Attendance\Http\Controllers;

use App\Domain\Attendance\Services\PantauPublik;
use App\Domain\Tenancy\Models\School;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pantau publik aktivitas ujian - `/pantau/{slug}`, TANPA login.
 *
 * =====================================================================
 * Alamat kedua di luar `auth`, dan batasnya ditegakkan di layanan
 * =====================================================================
 * Yang pertama adalah `/papan/{slug}` (pengawas per ruang). Laman ini
 * menjawab pertanyaan lain dari orang yang sama: "ujiannya sudah sampai
 * mana, dan ada yang tertahan tidak". Batas isinya ada di `PantauPublik` -
 * angka, kode ruang, nama pengawas, jam, dan keadaan laporan. Tidak nama
 * siswa, tidak NIS, tidak nomor kursi, tidak token.
 *
 * ---------------------------------------------------------------------
 * Satu endpoint DENYUT, bukan dua laman
 * ---------------------------------------------------------------------
 * `denyut()` mengembalikan potret yang SAMA dengan `tampilkan()`, sebagai
 * JSON. Halaman memanggilnya tiap lima detik dan mengganti angkanya tanpa
 * memuat ulang - dan itu satu-satunya cara papan ini bisa bergerak tanpa
 * kehilangan posisi gulir orang yang sedang membacanya.
 *
 * Memakai Inertia untuk penyegaran berkala akan mengirim seluruh props
 * halaman (termasuk daftar mapel dan nama sekolah) setiap lima detik, dan
 * Inertia tidak dirancang untuk itu: ia menyimpan riwayat kunjungan, dan
 * `replace` berulang akan mengotori riwayat peramban.
 *
 * ---------------------------------------------------------------------
 * Limiter sendiri, dan ia ketat
 * ---------------------------------------------------------------------
 * Alamat ini di luar login, jadi siapa pun bisa memanggilnya. Tanpa limiter,
 * satu skrip sederhana menjadikan endpoint ini alat pembebanan atas basis
 * data sekolah - dan ia dipanggil tiap lima detik oleh setiap layar yang
 * membuka papan ini.
 */
class PantauPublikController
{
    public function __construct(private readonly PantauPublik $pantau) {}

    public function tampilkan(string $slug): Response
    {
        $sekolah = $this->sekolah($slug);

        $potret = $this->pantau->susun($sekolah);

        return Inertia::render('Publik/Pantau', [
            'slug' => $slug,
            'potret' => $potret,
            // Alamat denyut dibangun di server, bukan disusun ulang di React:
            // alamat yang hidup di dua tempat adalah alamat yang suatu hari
            // berbeda di salah satunya - pola yang sama dengan `ruang_url`.
            'denyut_url' => "/pantau/{$slug}/denyut",
            'papan_url' => "/papan/{$slug}",
        ]);
    }

    /**
     * Potret terbaru sebagai JSON - dipanggil halaman tiap lima detik.
     *
     * Tanpa slug yang sah, jawabannya 404 JSON, bukan halaman galat HTML:
     * pemanggilnya `fetch` di latar belakang, dan HTML 404 akan gagal
     * di-parse tanpa satu pun pesan yang bisa dibaca.
     */
    public function denyut(string $slug): JsonResponse
    {
        $sekolah = $this->sekolah($slug);

        return response()->json($this->pantau->susun($sekolah));
    }

    /**
     * Sekolah dari slug - TANPA scope tenant, dan itu wajib.
     *
     * Pada permintaan ini belum ada pengguna, sehingga `SchoolResolver`
     * mengembalikan null - dan null berarti "jangan saring", bukan "tidak
     * ada sekolah". Karena itu `School` dibaca apa adanya; ia tabel induk,
     * dan scope yang menyaringnya berarti tidak ada yang bisa membacanya.
     */
    private function sekolah(string $slug): School
    {
        $sekolah = School::query()->where('papan_slug', $slug)->first();

        if ($sekolah === null) {
            abort(404);
        }

        return $sekolah;
    }
}

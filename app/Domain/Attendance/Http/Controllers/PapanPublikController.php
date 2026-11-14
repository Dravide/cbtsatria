<?php

namespace App\Domain\Attendance\Http\Controllers;

use App\Domain\Attendance\Services\PapanPengawas;
use App\Domain\Tenancy\Models\School;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Papan publik pengawas - `/papan/{slug}`, TANPA login.
 *
 * =====================================================================
 * Mengapa di luar `auth`, dan apa yang membatasinya
 * =====================================================================
 * Yang membukanya di lorong belum tentu punya akun: wali kelas, panitia,
 * orang tua, atau pengawas ruang lain yang ingin tahu siapa ada di ruang
 * sebelah. Menuntutnya login lebih dulu berarti pertanyaan "Bu Ani di
 * mana?" hanya bisa dijawab orang yang sudah bisa menjawabnya sendiri.
 *
 * Batas isinya ditegakkan di `PapanPengawas` - lihat catatannya: hanya
 * nama ruang, nama pengawas, dan jam. Tidak nama siswa, tidak kursi,
 * tidak token.
 *
 * ---------------------------------------------------------------------
 * `tanpaTenant()`, dan itu WAJIB
 * ---------------------------------------------------------------------
 * Pada permintaan ini belum ada pengguna, sehingga `SchoolResolver`
 * mengembalikan null - dan null berarti "jangan saring", bukan "tidak ada
 * sekolah". Karena itu slug di-resolve lewat query tanpa scope tenant,
 * lalu `school_id` dari baris itulah yang dipakai seluruh pembacaan
 * berikutnya.
 *
 * Sekolah tanpa `papan_slug` tidak ditemukan, dan itu jawaban yang benar:
 * papannya memang tidak ada, bukan disembunyikan.
 */
class PapanPublikController
{
    public function __construct(private readonly PapanPengawas $papan) {}

    public function tampilkan(string $slug): Response
    {
        $sekolah = $this->sekolah($slug);

        $isi = $this->papan->susun($sekolah);

        return Inertia::render('Publik/Papan', [
            'sekolah' => $sekolah->name,
            'slug' => $slug,
            'sesi' => $isi['sesi'],
            'tanpa_pengawas' => $isi['tanpa_pengawas'],
            'diperbarui' => $isi['diperbarui'],
        ]);
    }

    public function pengawas(string $slug, int $supervisor): Response
    {
        $sekolah = $this->sekolah($slug);

        $isi = $this->papan->susunPengawas($sekolah, $supervisor);

        if ($isi === null) {
            // 404, bukan halaman kosong: pengawas yang tidak bertugas pada
            // sesi aktif memang tidak sedang mengawas, dan halaman yang
            // menjawab "tidak ada" untuk setiap id akan membuat orang
            // mengira papannya rusak.
            abort(404);
        }

        return Inertia::render('Publik/PapanPengawas', [
            'sekolah' => $sekolah->name,
            'slug' => $slug,
            'nama' => $isi['nama'],
            'ruang' => $isi['ruang'],
            'diperbarui' => $isi['diperbarui'],
        ]);
    }

    private function sekolah(string $slug): School
    {
        // `School` sendiri TIDAK memakai SchoolScope - ia tabel induknya,
        // dan scope yang menyaringnya berarti tidak ada yang bisa
        // membacanya. Karena itu tidak ada `withoutGlobalScopes()` di sini;
        // yang perlu disebut adalah bahwa pembacaan SESUDAH baris ini
        // memakai school_id dari hasilnya, bukan dari permintaan.
        $sekolah = School::query()->where('papan_slug', $slug)->first();

        if ($sekolah === null) {
            abort(404);
        }

        return $sekolah;
    }
}

<?php

namespace App\Domain\Admin\Http\Controllers;

use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Tenancy\Models\PengaturanAi;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Support\PengaturanBawaan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pengaturan sekolah - wireframe plate 23.
 *
 * ---------------------------------------------------------------------
 * Nilai di sini adalah BAWAAN untuk event baru
 * ---------------------------------------------------------------------
 * Kalimat di kotak bawah laman itu bukan hiasan, dan ia satu-satunya alasan
 * laman ini aman untuk diubah kapan saja:
 *
 *   "Nilai di sini adalah bawaan untuk event baru. Event yang sudah dibuat
 *    menyimpan nilainya sendiri dan tidak ikut berubah."
 *
 * Konsekuensinya di data: kolom `settings` di schools hanya dibaca saat
 * sebuah event DIBUAT, lalu nilainya disalin ke baris event itu. Tidak ada
 * event yang membaca balik ke sini sesudahnya. Kalau suatu saat ada yang
 * merancang sebaliknya - mis. event mewarisi ambang pelanggaran secara
 * dinamis - maka mengubah ambang di tengah ujian yang sedang berjalan akan
 * mengubah aturan main peserta yang sedang mengerjakan, dan kalimat di laman
 * ini menjadi bohong.
 *
 * Nilainya sendiri tinggal di Tenancy\Support\PengaturanBawaan, karena modul
 * Event juga membacanya saat membuat event - dan modul Event tidak boleh
 * mengimpor Admin\Http. Lihat catatan di kelas itu.
 */
class SchoolSettingController
{
    public function edit(Request $request): Response
    {
        $sekolah = $this->sekolah($request);

        Gate::authorize('kelolaPengaturan', $sekolah);

        return Inertia::render('Admin/Pengaturan', [
            'pengaturan' => PengaturanBawaan::gabung($sekolah->settings),
            // Tab AI. Kuncinya TIDAK ikut - hanya penanda bahwa ia ada dan
            // empat huruf terakhirnya. Yang tidak dikirim tidak bisa bocor
            // lewat devtools, tangkapan layar, atau sesi yang ditinggal
            // terbuka.
            'ai' => $this->konfigurasiAi($sekolah),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function konfigurasiAi(School $sekolah): array
    {
        $ai = PengaturanAi::query()->where('school_id', $sekolah->id)->first();

        return [
            'base_url' => $ai?->base_url ?? '',
            'model' => $ai?->model ?? '',
            'aktif' => (bool) ($ai?->aktif ?? false),
            'batas_token' => (int) ($ai?->batas_token ?: 1500),
            'ada_kunci' => filled($ai?->api_key_encrypted),
            'petunjuk_kunci' => $ai?->petunjukKunci(),
        ];
    }

    public function update(Request $request, ActivityLogger $logger): RedirectResponse
    {
        $sekolah = $this->sekolah($request);

        Gate::authorize('kelolaPengaturan', $sekolah);

        $data = $request->validate([
            'durasi_token_menit' => ['required', 'integer', 'min:1', 'max:240'],
            'ambang_pelanggaran' => ['required', 'integer', 'min:1', 'max:50'],
            'simpan_otomatis_detik' => ['required', 'integer', 'min:5', 'max:300'],
            'kkm' => ['required', 'integer', 'min:0', 'max:100'],
            'kunci_klik_kanan' => ['required', 'boolean'],
            'peringatkan_keluar_tab' => ['required', 'boolean'],
            'kunci_setelah_ambang' => ['required', 'boolean'],
            'izinkan_tempel_uraian' => ['required', 'boolean'],

            // Tiga penjaga layar. Ada di sini DAN di `PengaturanBawaan::NILAI`
            // karena `settings` ditulis UTUH - kunci yang tidak divalidasi
            // tidak ikut ke `$data`, lalu terhapus dari kolomnya pada
            // penyimpanan berikutnya.
            'deteksi_layar_terbagi' => ['required', 'boolean'],
            'deteksi_jendela_mengambang' => ['required', 'boolean'],
            'tanda_air_ujian' => ['required', 'boolean'],
        ]);

        $sekolah->forceFill(['settings' => $data])->save();

        // Aksi ini dicatat meski ia "hanya pengaturan": ia mengubah aturan main
        // ujian berikutnya, dan pertanyaan "sejak kapan ambangnya lima" harus
        // punya jawaban yang bisa ditunjuk.
        $logger->log('Mengubah pengaturan sekolah', $sekolah, [
            'kkm' => $data['kkm'],
            'ambang_pelanggaran' => $data['ambang_pelanggaran'],
        ]);

        return back()->with('sukses', 'Pengaturan tersimpan. Berlaku untuk event baru.');
    }

    private function sekolah(Request $request): School
    {
        return School::findOrFail($request->user()->school_id);
    }
}

<?php

namespace App\Domain\Admin\Http\Controllers;

use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\PengaturanAiSekolah;
use App\Support\Ai\GalatPenyedia;
use App\Support\Ai\KlienAi;
use App\Support\Ai\UrlPenyedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Konfigurasi penyedia AI - tab AI di laman Pengaturan sekolah.
 *
 * =====================================================================
 * Mengapa controller sendiri, bukan tiga field di SchoolSettingController
 * =====================================================================
 * Form Pengaturan mengirim DELAPAN kunci `required` sekaligus dan menulis
 * kolom `settings` secara utuh. Menambahkan AI di sana berarti:
 *
 *   1. `api_key` harus ikut terkirim pada setiap penyimpanan - padahal ia
 *      tidak pernah dikirim ke peramban, jadi memang selalu kosong;
 *   2. "Uji koneksi" menjadi aksi yang menyimpan;
 *   3. field rahasia duduk di form yang paling sering ditekan Admin.
 *
 * Yang ketiga bukan soal kerapian: satu kali Admin menekan Simpan di tab
 * Ujian, kunci API yang salah ketik ikut tersimpan - atau tidak tersimpan.
 * Memisahkannya membuat setiap tab punya satu niat.
 *
 * =====================================================================
 * Kunci API TIDAK PERNAH keluar dari server
 * =====================================================================
 * Tidak di props Inertia, tidak di jawaban `uji()`, tidak di log aktivitas,
 * tidak di pesan galat. Yang dikirim ke layar hanya `ada_kunci` dan
 * `petunjuk_kunci` (empat huruf terakhir) - cukup untuk meyakinkan Admin
 * bahwa kuncinya tersimpan, tidak cukup untuk memakainya.
 */
class PengaturanAiController
{
    public function update(
        Request $request,
        PengaturanAiSekolah $service,
        ActivityLogger $logger,
    ): RedirectResponse {
        $sekolah = $this->sekolah($request);

        Gate::authorize('kelolaPengaturan', $sekolah);

        $data = $request->validate([
            // `required_if` pada `aktif`: field boleh kosong selama AI
            // dimatikan. Menuntutnya selalu terisi berarti Admin tidak bisa
            // mematikan AI untuk sementara tanpa menghapus alamatnya.
            'base_url' => ['nullable', 'required_if:aktif,1', 'string', 'max:255'],
            'model' => ['nullable', 'required_if:aktif,1', 'string', 'max:120'],
            // Kosong berarti "jangan ubah" - lihat PengaturanAiSekolah.
            'api_key' => ['nullable', 'string', 'max:500'],
            'aktif' => ['boolean'],
            'batas_token' => ['required', 'integer', 'min:256', 'max:8000'],
        ], [
            'base_url.required_if' => 'Isi Base URL dulu sebelum menyalakan AI.',
            'model.required_if' => 'Isi nama model dulu sebelum menyalakan AI.',
            'batas_token.required' => 'Isi batas panjang jawaban.',
            'batas_token.min' => 'Batas token minimal 256 - di bawah itu JSON soal terpotong.',
            'batas_token.max' => 'Batas token maksimal 8000.',
        ], [
            'base_url' => 'Base URL',
            'model' => 'model',
            'api_key' => 'API key',
            'batas_token' => 'batas token',
        ]);

        if (filled($data['base_url'] ?? null)) {
            try {
                UrlPenyedia::pastikan(trim((string) $data['base_url']));
            } catch (GalatPenyedia $e) {
                // Ditolak sebagai galat VALIDASI pada kolomnya, bukan flash:
                // pesannya milik Base URL, dan `Field` sudah punya tempat
                // untuk menampilkannya tepat di bawah input yang salah.
                throw ValidationException::withMessages(['base_url' => $e->getMessage()]);
            }
        }

        // Menyalakan AI tanpa kunci menghasilkan galat di setiap klik Guru,
        // dengan pesan yang menyalahkan konfigurasi - dan Admin yang baru
        // saja menyimpan akan mengira dirinya sudah selesai.
        //
        // Diperiksa SEBELUM simpan: menyimpan lalu membalikkan sakelarnya
        // meninggalkan perubahan lain (model, batas token) yang tidak
        // diminta Admin, sementara layarnya melaporkan galat - dan ia akan
        // memperbaikinya lagi tanpa tahu sebagian sudah tersimpan.
        $adaKunciBaru = filled(trim((string) ($data['api_key'] ?? '')));
        $adaKunciLama = filled($service->ambil((int) $sekolah->id)?->api_key_encrypted);

        if (($data['aktif'] ?? false) && ! $adaKunciBaru && ! $adaKunciLama) {
            throw ValidationException::withMessages([
                'api_key' => 'Isi API key dulu sebelum menyalakan AI.',
            ]);
        }

        $pengaturan = $service->simpan(
            (int) $sekolah->id,
            $data,
            (int) $request->user()->id,
        );

        // Yang dicatat: alamat, model, dan sakelarnya. KUNCI TIDAK - bahkan
        // empat huruf terakhirnya. Log dibaca lebih banyak orang daripada
        // yang boleh melihat kunci itu.
        $logger->log('Mengubah pengaturan AI', $sekolah, [
            'base_url' => $pengaturan->base_url,
            'model' => $pengaturan->model,
            'aktif' => $pengaturan->aktif,
        ]);

        return back()->with('sukses', 'Pengaturan AI tersimpan.');
    }

    /**
     * Kirim satu permintaan sepele ke penyedia.
     *
     * Bukan kenyamanan. Tanpa tombol ini, kunci yang salah ketik atau Base
     * URL yang kurang `/v1` baru ketahuan saat Guru menekan Generate -
     * biasanya sehari sebelum ujian, ketika ia sedang menyusun paket dan
     * tidak punya waktu untuk menghubungi Admin.
     *
     * Tidak menyimpan apa pun: yang diuji adalah isian di layar SEKARANG,
     * bukan yang tersimpan. Admin yang menguji lalu membatalkan tidak
     * meninggalkan konfigurasi setengah jadi.
     */
    public function uji(Request $request, KlienAi $klien, PengaturanAiSekolah $service): JsonResponse
    {
        $sekolah = $this->sekolah($request);

        Gate::authorize('kelolaPengaturan', $sekolah);

        $data = $request->validate([
            'base_url' => ['required', 'string', 'max:255'],
            'model' => ['required', 'string', 'max:120'],
            'api_key' => ['nullable', 'string', 'max:500'],
        ], [
            'base_url.required' => 'Isi Base URL dulu.',
            'model.required' => 'Isi nama model dulu.',
        ]);

        try {
            UrlPenyedia::pastikan(trim((string) $data['base_url']));
        } catch (GalatPenyedia $e) {
            return response()->json(['pesan' => $e->getMessage()], 422);
        }

        // Kunci kosong di form berarti "pakai yang tersimpan" - sama dengan
        // aturan simpan, supaya tombol Uji tidak menuntut Admin mengetik
        // ulang kunci yang sudah ada.
        $kunci = filled($data['api_key'] ?? null)
            ? trim((string) $data['api_key'])
            : $service->ambil((int) $sekolah->id)?->kunciApi();

        if (blank($kunci)) {
            return response()->json([
                'pesan' => 'Isi API key dulu, atau simpan dulu kuncinya sebelum menguji.',
            ], 422);
        }

        try {
            $klien->kirim([
                'base_url' => trim((string) $data['base_url']),
                'model' => trim((string) $data['model']),
                'api_key' => $kunci,
                // Lebih pendek dari bawaan: yang diuji adalah sambungan dan
                // kuncinya, tidak ada soal yang perlu ditulis.
                'batas_token' => 256,
            ], [
                ['role' => 'system', 'content' => 'Balas hanya dengan objek JSON {"ok":true}.'],
                ['role' => 'user', 'content' => 'Uji sambungan.'],
            ]);
        } catch (GalatPenyedia $e) {
            return response()->json(['pesan' => $e->getMessage()], 422);
        }

        return response()->json(['pesan' => 'Tersambung. Model '.trim((string) $data['model']).' menjawab.']);
    }

    private function sekolah(Request $request): School
    {
        return School::findOrFail($request->user()->school_id);
    }
}

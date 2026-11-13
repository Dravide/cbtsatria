<?php

namespace App\Domain\Admin\Http\Controllers;

use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Support\IdentitasAplikasi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Identitas aplikasi - tab "Identitas" di plate 04 dan isi `site.webmanifest`.
 *
 * =====================================================================
 * Apa yang BISA diatur, dan apa yang TIDAK
 * =====================================================================
 * Bisa: nama situs, nama pendek (layar beranda), deskripsi, warna tema, dan
 * tiga ikon (favicon ICO/PNG/SVG, ikon 192/512, apple-touch).
 *
 * Tidak bisa, dan itu keputusan yang dikunci: `noindex`. Aplikasi ini
 * memuat nilai, presensi, dan jawaban ujian siswa. Halaman yang lolos ke
 * indeks mesin pencari membocorkan data anak ke mesin yang menyimpannya
 * bertahun-tahun - dan itu tidak bisa ditarik kembali. Toggle-nya karena itu
 * tidak pernah ada di layar, bukan disembunyikan.
 *
 * =====================================================================
 * Mengapa ikon disimpan dengan nama yang dibuat server
 * =====================================================================
 * Sama persis dengan logo sekolah: nama dari peramban bisa memuat `../`,
 * dan nama yang sama dua kali menimpa berkas sekolah lain. Ikon juga
 * DIBACA ukurannya sebelum disimpan - manifest yang menyebut `512x512`
 * untuk berkas 128px membuat Android memasang ikon buram, dan kegagalan itu
 * baru terlihat di ponsel, bukan di layar Admin.
 */
class IdentitasAplikasiController
{
    public function edit(Request $request): Response
    {
        $sekolah = $this->sekolah($request);

        Gate::authorize('view', $sekolah);

        $nilai = IdentitasAplikasi::nilai($sekolah);

        return Inertia::render('Admin/Identitas', [
            'identitas' => [
                'nama' => $nilai['nama'],
                'nama_pendek' => $nilai['nama_pendek'],
                'deskripsi' => $nilai['deskripsi'],
                'warna' => $nilai['warna'],
                'favicon_url' => $nilai['favicon'],
                'favicon_png_url' => $nilai['favicon_png'] === null ? null : '/storage/'.$nilai['favicon_png'],
                'ikon_url' => $nilai['ikon'][0]['src'] ?? null,
                'ikon_sizes' => $nilai['ikon'][0]['sizes'] ?? null,
                'apple_url' => $nilai['apple'],
            ],
            // Ikon bawaan ikut dikirim supaya layar bisa MENYEBUT bahwa yang
            // sedang tampil adalah bawaan, bukan hasil unggahan - Admin yang
            // mengira sudah mengganti ikon padahal belum akan mengunggahnya
            // dua kali.
            'bawaan' => [
                'ikon_url' => '/icon-512.png',
                'apple_url' => '/apple-touch-icon.png',
                'warna' => '#1e4fa3',
            ],
        ]);
    }

    public function update(Request $request, ActivityLogger $logger): RedirectResponse
    {
        $sekolah = $this->sekolah($request);

        Gate::authorize('update', $sekolah);

        $data = $request->validate([
            'nama' => ['required', 'string', 'max:60'],
            'nama_pendek' => ['required', 'string', 'max:12'],
            'deskripsi' => ['required', 'string', 'max:255'],
            // Hex enam digit, bukan nama warna CSS: nilai ini juga ditulis ke
            // `theme_color` manifest, dan nama warna seperti `rebeccapurple`
            // tidak diterima di sana.
            'warna' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], [
            'warna.regex' => 'Warna harus berbentuk #RRGGBB, mis. #1e4fa3.',
            'nama.required' => 'Nama situs wajib diisi.',
            'nama_pendek.max' => 'Nama pendek maksimal 12 karakter - Android memotongnya di tengah kata.',
        ], [
            'nama' => 'nama situs',
            'nama_pendek' => 'nama pendek',
            'deskripsi' => 'deskripsi',
            'warna' => 'warna tema',
        ]);

        $branding = $sekolah->branding ?? [];

        $sekolah->forceFill([
            'branding' => array_merge($branding, [
                'nama' => $data['nama'],
                'nama_pendek' => $data['nama_pendek'],
                'deskripsi' => $data['deskripsi'],
                'warna' => strtolower($data['warna']),
            ]),
        ])->save();

        $logger->log('Memperbarui identitas aplikasi', $sekolah, [
            'nama' => $data['nama'],
        ]);

        return back()->with('sukses', 'Identitas aplikasi tersimpan.');
    }

    /**
     * Unggah satu ikon.
     *
     * `{jenis}` adalah `favicon`, `ikon`, atau `apple` - divalidasi menjadi
     * salah satu dari ketiganya SEBELUM satu berkas pun dibaca, sehingga URL
     * yang menyebut jenis lain menerima 404, bukan kolom yang tidak ada.
     */
    public function unggahIkon(Request $request, string $jenis, ActivityLogger $logger): RedirectResponse
    {
        $sekolah = $this->sekolah($request);

        Gate::authorize('update', $sekolah);

        abort_unless(array_key_exists($jenis, IdentitasAplikasi::JENIS_IKON), 404);

        $aturan = match ($jenis) {
            // Favicon: ICO tidak bisa dibaca `getimagesize`, jadi ukurannya
            // tidak diperiksa - peramban menskalakan sendiri.
            'favicon' => ['required', 'file', 'mimes:png,svg,ico,x-icon', 'max:512'],
            // Ikon manifest WAJIB persegi >= 192px - lihat alasannya di bawah.
            'ikon' => ['required', 'file', 'mimes:png', 'max:1024'],
            'apple' => ['required', 'file', 'mimes:png', 'max:1024'],
        };

        $request->validate(['berkas' => $aturan], [
            'berkas.required' => 'Pilih berkasnya lebih dulu.',
            'berkas.mimes' => $jenis === 'favicon'
                ? 'Favicon harus PNG, SVG, atau ICO.'
                : 'Ikon harus berupa PNG.',
            'berkas.max' => 'Berkas terlalu besar. Maksimum 1 MB.',
        ], ['berkas' => 'berkas']);

        $berkas = $request->file('berkas');
        $jalur = $berkas->store('identitas', 'public');

        $branding = $sekolah->branding ?? [];
        $kolom = IdentitasAplikasi::JENIS_IKON[$jenis];
        $lama = $branding[$kolom] ?? null;

        $branding[$kolom] = $jalur;

        if ($jenis === 'ikon') {
            $ukuran = $this->ukuranPng(Storage::disk('public')->path($jalur));

            if ($ukuran === null) {
                Storage::disk('public')->delete($jalur);

                return back()->with(
                    'galat',
                    'Ikon harus PNG persegi minimal 192x192 piksel - Android menolak '
                    .'memasang ikon yang lebih kecil dari itu.',
                );
            }

            // Ukuran sebenarnya disimpan, bukan ditebak 512x512: manifest yang
            // menjanjikan ukuran lebih besar daripada berkasnya membuat
            // Android memasang ikon buram di layar beranda.
            $branding['ikon_sizes'] = $ukuran;
        }

        $sekolah->forceFill(['branding' => $branding])->save();

        // Berkas lama dihapus SESUDAH barisnya tersimpan - urutan sebaliknya
        // meninggalkan sekolah tanpa ikon bila penyimpanan barisnya gagal.
        if ($lama !== null) {
            Storage::disk('public')->delete($lama);
        }

        $logger->log('Mengunggah ikon aplikasi', $sekolah, ['jenis' => $jenis]);

        return back()->with('sukses', 'Ikon tersimpan.');
    }

    /** Hapus satu ikon, kembali ke bawaan. */
    public function hapusIkon(Request $request, string $jenis, ActivityLogger $logger): RedirectResponse
    {
        $sekolah = $this->sekolah($request);

        Gate::authorize('update', $sekolah);

        abort_unless(array_key_exists($jenis, IdentitasAplikasi::JENIS_IKON), 404);

        $branding = $sekolah->branding ?? [];
        $kolom = IdentitasAplikasi::JENIS_IKON[$jenis];
        $lama = $branding[$kolom] ?? null;

        if ($lama === null) {
            return back()->with('galat', 'Ikon itu belum pernah diunggah.');
        }

        unset($branding[$kolom]);

        if ($jenis === 'ikon') {
            unset($branding['ikon_sizes']);
        }

        // Baris dikosongkan LEBIH DULU, lalu berkasnya dihapus - kebalikan
        // dari unggah, dan alasannya sama: baris yang menunjuk berkas yang
        // sudah hilang menghasilkan ikon rusak, bukan ikon bawaan.
        $sekolah->forceFill(['branding' => $branding])->save();

        Storage::disk('public')->delete($lama);

        $logger->log('Menghapus ikon aplikasi', $sekolah, ['jenis' => $jenis]);

        return back()->with('sukses', 'Ikon dihapus. Yang dipakai sekarang ikon bawaan.');
    }

    /**
     * Ukuran PNG, atau null bila bukan PNG persegi yang cukup besar.
     *
     * `getimagesize` hanya membaca kepala berkas, bukan seluruh gambar -
     * penting karena berkasnya berasal dari unggahan siapa pun.
     */
    private function ukuranPng(string $jalur): ?string
    {
        $info = @getimagesize($jalur);

        if ($info === false || $info[0] !== $info[1] || $info[0] < 192) {
            return null;
        }

        return $info[0].'x'.$info[1];
    }

    private function sekolah(Request $request): School
    {
        return School::findOrFail($request->user()->school_id);
    }
}

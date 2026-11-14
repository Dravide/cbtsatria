<?php

namespace App\Domain\Admin\Http\Controllers;

use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Tenancy\Models\School;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Profil sekolah - wireframe plate 04.
 *
 * Empat tab: Identitas, Kontak, Zona waktu, Logo. Bentuknya satu form panjang
 * yang tampil bertab, bukan empat halaman - data ini diisi sekali di awal dan
 * jarang disentuh lagi, jadi memecahnya menjadi empat rute berarti empat
 * halaman yang tidak pernah punya isi baru.
 */
class SchoolProfileController
{
    public function edit(Request $request): Response
    {
        $sekolah = $this->sekolah($request);

        Gate::authorize('view', $sekolah);

        return Inertia::render('Admin/Sekolah', [
            'sekolah' => [
                'id' => $sekolah->id,
                'kode' => $sekolah->code,
                'nama' => $sekolah->name,
                'npsn' => $sekolah->npsn,
                'alamat' => $sekolah->address,
                'email' => $sekolah->email,
                'telepon' => $sekolah->phone,
                'zona_waktu' => $sekolah->timezone,
                'logo_url' => $sekolah->logo_path ? asset('storage/'.$sekolah->logo_path) : null,
                'status' => $sekolah->status,
                // Alamat papan publik pengawas; null = belum dinyalakan.
                // Dibaca laman Profil karena saklarnya ada di sana - lihat
                // `PengaturanPapanController` tentang mengapa rutenya sendiri.
                'papan_slug' => $sekolah->papan_slug,
            ],
            'zonaWaktu' => [
                'Asia/Jakarta' => 'WIB - Asia/Jakarta (UTC+7)',
                'Asia/Makassar' => 'WITA - Asia/Makassar (UTC+8)',
                'Asia/Jayapura' => 'WIT - Asia/Jayapura (UTC+9)',
            ],
        ]);
    }

    public function update(Request $request, ActivityLogger $logger): RedirectResponse
    {
        $sekolah = $this->sekolah($request);

        Gate::authorize('update', $sekolah);

        $data = $request->validate([
            'nama' => ['required', 'string', 'max:255'],
            'alamat' => ['nullable', 'string', 'max:1000'],
            'email' => ['nullable', 'email', 'max:255'],
            'telepon' => ['nullable', 'string', 'max:40'],
            'zona_waktu' => ['required', 'in:Asia/Jakarta,Asia/Makassar,Asia/Jayapura'],
        ], [], [
            'nama' => 'nama sekolah',
            'zona_waktu' => 'zona waktu',
        ]);

        // npsn tidak ada di daftar ini, dan sengaja demikian. Mengubahnya
        // berarti memindahkan sekolah ke identitas lain - operasi di luar
        // aplikasi. Mengabaikannya diam-diam lebih buruk daripada tidak
        // menerimanya sama sekali, jadi tombolnya pun tidak ada di UI; ini
        // hanya memastikan request langsung pun tidak berpengaruh.
        $sekolah->fill([
            'name' => $data['nama'],
            'address' => $data['alamat'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['telepon'] ?? null,
            'timezone' => $data['zona_waktu'],
        ])->save();

        $logger->log('Memperbarui profil sekolah', $sekolah);

        return back()->with('sukses', 'Profil sekolah tersimpan.');
    }

    /**
     * Sekolah milik pengguna yang sedang masuk.
     *
     * findOrFail pada id milik pengguna, bukan id dari URL: tidak ada route
     * yang menerima id sekolah, supaya tidak ada bentuk request apa pun yang
     * bisa menyebut sekolah lain.
     */
    private function sekolah(Request $request): School
    {
        return School::findOrFail($request->user()->school_id);
    }

    /**
     * Unggah logo sekolah - wireframe plate 04, kotak "Logo".
     *
     * ---------------------------------------------------------------------
     * Disk PUBLIK, dan itu satu-satunya yang dipakai halaman ini
     * ---------------------------------------------------------------------
     * Seluruh berkas lain di sistem ini tinggal di disk `local` (berita acara,
     * media soal, tanda tangan) dengan rute penyaji sendiri, karena semuanya
     * sensitif. Logo bukan: ia tercetak di kop surat sekolah, dan ia harus
     * tampil di `/p/{slug}` - halaman pintu ruang yang dibuka pengawas TANPA
     * login. Menaruhnya di disk privat berarti menambah rute penyaji yang
     * harus dibuka ke publik juga - lebih banyak kode untuk kerahasiaan yang
     * tidak pernah ada.
     *
     * Karena itu `storage:link` wajib ada di server. `docs/PELUNCURAN.md`
     * memuatnya di langkah penyebaran.
     *
     * ---------------------------------------------------------------------
     * Nama berkas dibuat sistem, bukan diambil dari unggahan
     * ---------------------------------------------------------------------
     * `store()` menghasilkan nama acak, dan itu yang dipakai - nama asli dari
     * peramban tidak pernah menyentuh disk. Dua alasan: nama yang dibuat
     * penyerang bisa berisi `../` atau ekstensi ganda, dan nama yang sama dua
     * kali menimpa berkas sekolah lain.
     */
    public function unggahLogo(Request $request, ActivityLogger $logger): RedirectResponse
    {
        $sekolah = $this->sekolah($request);

        Gate::authorize('update', $sekolah);

        // `mimes:png,svg`, bukan `image`: Laravel tidak menganggap SVG sebagai
        // gambar pada aturan `image`, padahal wireframe plate 04 menyebut
        // "PNG atau SVG" - dan SVG memang yang paling sering dipakai sekolah
        // karena ia tajam di segala ukuran cetak.
        //
        // `svg` punya risiko tersendiri: SVG adalah XML dan bisa memuat
        // <script>. Content-Type dari ekstensi TIDAK menolong - SVG yang
        // dibuka langsung berjalan di origin aplikasi ini. Yang menutupnya
        // adalah header CSP pada blok `location ^~ /storage/` di
        // docs/PELUNCURAN.md: gambarnya tetap tampil di <img>, logikanya tidak
        // bisa berjalan. Server TANPA header itu berarti logo SVG menjadi
        // jalan masuk. Yang juga tidak boleh dilakukan kelak: menyisipkan SVG
        // ini ke dalam HTML halaman - di situ ia jadi vektor XSS yang lolos
        // dari CSP berkas.
        $valid = $request->validate([
            'logo' => ['required', 'file', 'mimes:png,svg', 'max:1024'],
        ], [
            'logo.required' => 'Pilih berkas logonya lebih dulu.',
            'logo.mimes' => 'Logo harus berupa PNG atau SVG.',
            'logo.max' => 'Logo terlalu besar. Maksimum 1 MB.',
        ], ['logo' => 'logo']);

        $lama = $sekolah->logo_path;

        $sekolah->fill([
            'logo_path' => $request->file('logo')->store('logos', 'public'),
        ])->save();

        // Berkas lama dihapus SESUDAH barisnya tersimpan, bukan sebelumnya.
        // Urutan sebaliknya meninggalkan sekolah tanpa logo sama sekali bila
        // penyimpanan barisnya gagal di antaranya.
        if ($lama !== null) {
            Storage::disk('public')->delete($lama);
        }

        $logger->log('Mengunggah logo sekolah', $sekolah);

        return back()->with('sukses', 'Logo sekolah tersimpan.');
    }

    /**
     * Hapus logo sekolah.
     *
     * `logo_path` dikosongkan LEBIH DULU, lalu berkasnya dihapus. Kebalikan
     * dari unggah, dan alasannya sama: kalau penghapusan berkas berhasil
     * tetapi penyimpanan barisnya gagal, yang tersisa adalah baris yang
     * menunjuk berkas yang tidak ada - dan halaman ini akan menampilkan
     * gambar rusak, bukan "Belum ada logo".
     */
    public function hapusLogo(Request $request, ActivityLogger $logger): RedirectResponse
    {
        $sekolah = $this->sekolah($request);

        Gate::authorize('update', $sekolah);

        $lama = $sekolah->logo_path;

        if ($lama === null) {
            return back()->with('galat', 'Sekolah ini belum punya logo.');
        }

        $sekolah->fill(['logo_path' => null])->save();

        Storage::disk('public')->delete($lama);

        $logger->log('Menghapus logo sekolah', $sekolah);

        return back()->with('sukses', 'Logo sekolah dihapus.');
    }
}

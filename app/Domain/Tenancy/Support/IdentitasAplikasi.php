<?php

namespace App\Domain\Tenancy\Support;

use App\Domain\Tenancy\Models\School;
use Illuminate\Contracts\Auth\Factory as AuthFactory;

/**
 * Identitas yang terpasang di kepala dokumen: nama, deskripsi, warna tema, ikon.
 *
 * =====================================================================
 * Mengapa di Tenancy\Support, bukan di controller Admin
 * =====================================================================
 * Nilainya hidup di kolom `schools.branding`, dan ia dibaca oleh ROOT VIEW
 * (`resources/views/app.blade.php`) - satu tempat yang tidak mengenal modul
 * Admin sama sekali dan merender setiap halaman, termasuk `/login` dan
 * `/p/{slug}` yang dibuka tanpa akun. Menaruh pembacaannya di Admin\Http
 * berarti root view harus mengimpor controller, dan itu tepat yang dilarang
 * `DomainBoundariesTest`.
 *
 * =====================================================================
 * Bawaan: berkas statis di `public/`
 * =====================================================================
 * Sekolah yang belum mengunggah ikon tetap mendapat ikon yang sah - lima
 * berkas di `public/` yang sudah ada sejak awal. Tanpa bawaan itu, kolom
 * kosong berarti tag `<link rel="icon">` yang menunjuk `null`, dan peramban
 * meminta `/favicon.ico` lalu menerima 404 di setiap pembukaan halaman.
 *
 * =====================================================================
 * `noindex` TIDAK bisa diubah dari sini, dan itu disengaja
 * =====================================================================
 * Aplikasi ini memuat nilai, presensi, dan jawaban ujian siswa. Halaman yang
 * lolos ke indeks mesin pencari membocorkan data anak ke mesin yang
 * menyimpannya bertahun-tahun, dan itu tidak bisa ditarik kembali. Karena
 * itu yang bisa diatur adalah nama, deskripsi, warna, dan ikonnya - bukan
 * apakah halamannya boleh dirayapi. `IdentitasAplikasiTest` menjaga
 * `noindex` tetap ada di setiap halaman.
 *
 * =====================================================================
 * Tidak ada cache statis
 * =====================================================================
 * Nilai ini dibaca SEKALI per halaman oleh root view. Cache statis akan
 * bertahan lintas permintaan di bawah Octane, sehingga sekolah yang baru
 * mengganti favicon masih menyajikan ikon lama sampai worker didaur ulang -
 * persis kegagalan yang membuat Admin menekan "Simpan" berkali-kali.
 */
final class IdentitasAplikasi
{
    /** Jenis berkas yang bisa diunggah, dan nama kolomnya di `branding`. */
    public const JENIS_IKON = [
        'favicon' => 'favicon_path',
        'ikon' => 'ikon_path',
        'apple' => 'apple_path',
    ];

    /**
     * Nilai yang dipakai halaman, sudah di-merge dengan bawaannya.
     *
     * @return array{
     *     nama: string,
     *     deskripsi: string,
     *     warna: string,
     *     nama_pendek: string,
     *     ikon: array<int, array{src: string, sizes: string, type: string}>,
     *     apple: string,
     *     favicon: string|null,
     *     favicon_png: string|null
     * }
     */
    public static function nilai(?School $sekolah = null): array
    {
        $tersimpan = ($sekolah ?? self::sekolah())?->branding ?? [];

        $nama = trim((string) ($tersimpan['nama'] ?? ''));

        if ($nama === '') {
            $nama = (string) config('app.name');
        }

        $deskripsi = trim((string) ($tersimpan['deskripsi'] ?? ''));

        if ($deskripsi === '') {
            $deskripsi = 'Computer based test untuk ujian sekolah: Admin, Guru, Pengawas, '
                .'dan Siswa dalam satu aplikasi.';
        }

        $warna = trim((string) ($tersimpan['warna'] ?? ''));

        if (preg_match('/^#[0-9a-f]{6}$/i', $warna) !== 1) {
            $warna = '#1e4fa3';
        }

        $favicon = $tersimpan['favicon_path'] ?? null;

        return [
            'nama' => $nama,
            // Dibuang ke SATU baris: meta description yang memuat baris baru
            // dipotong mesin pencari di tempat yang acak.
            'deskripsi' => trim(preg_replace('/\s+/u', ' ', $deskripsi) ?? ''),
            'warna' => $warna,
            // `short_name` manifest dibatasi ~12 karakter sebelum Android
            // memotongnya sendiri di tengah kata.
            'nama_pendek' => mb_substr((string) ($tersimpan['nama_pendek'] ?? $nama), 0, 12) ?: $nama,
            'ikon' => self::ikon($tersimpan),
            'apple' => isset($tersimpan['apple_path'])
                ? '/storage/'.$tersimpan['apple_path']
                : '/apple-touch-icon.png',
            'favicon' => $favicon === null ? null : '/storage/'.$favicon,
            // Ikon 32px tetap dicetak dari berkas statis: peramban memilih
            // ukuran sendiri dari daftar `sizes`, dan satu favicon SVG/ICO
            // yang salah ukuran membuat tab menampilkan ikon buram.
            'favicon_png' => $tersimpan['favicon_png_path'] ?? null,
        ];
    }

    /**
     * Ikon manifest. Unggahan hanya dipakai bila ukurannya benar-benar
     * diketahui - manifest yang menyebut `512x512` untuk berkas 128px membuat
     * Android memasang ikon buram, dan itu lebih buruk daripada ikon bawaan.
     *
     * @param  array<string, mixed>  $tersimpan
     * @return array<int, array{src: string, sizes: string, type: string}>
     */
    private static function ikon(array $tersimpan): array
    {
        $jalur = $tersimpan['ikon_path'] ?? null;
        $ukuran = $tersimpan['ikon_sizes'] ?? null;

        if (is_string($jalur) && is_string($ukuran) && preg_match('/^\d+x\d+$/', $ukuran) === 1) {
            return [
                [
                    'src' => '/storage/'.$jalur,
                    'sizes' => $ukuran,
                    'type' => str_ends_with($jalur, '.svg') ? 'image/svg+xml' : 'image/png',
                ],
            ];
        }

        return [
            ['src' => '/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => '/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
        ];
    }

    /**
     * Sekolah yang identitasnya dipakai untuk permintaan ini.
     *
     * Urutannya: sekolah pengguna yang sedang masuk, lalu - bila belum masuk
     * atau yang membuka adalah tautan pintu tanpa akun - sekolah aktif
     * pertama di basis data. Satu pemasangan aplikasi ini melayani satu
     * sekolah pada praktiknya; bila kelak ada lebih dari satu dan
     * permintaannya anonim, yang terpilih adalah yang pertama - dan itu
     * tetap lebih benar daripada menampilkan nama produk di seluruh
     * perangkat sekolah.
     */
    public static function sekolah(): ?School
    {
        $pengguna = app(AuthFactory::class)->guard('web')->user();

        if ($pengguna?->school_id !== null) {
            return School::query()->find($pengguna->school_id);
        }

        return School::query()->where('status', 'aktif')->orderBy('id')->first();
    }
}

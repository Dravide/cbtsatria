<?php

namespace App\Domain\Facility\Services;

use App\Domain\Facility\Models\Room;
use App\Support\Qr\KodeQr;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Kartu pintu ruang sebagai PDF - satu lembar A4 untuk SETIAP ruang.
 *
 * ---------------------------------------------------------------------
 * Satu halaman penuh per ruang, bukan banyak kartu per lembar
 * ---------------------------------------------------------------------
 * Kartu pengawas (Event) memadatkan delapan kartu ke satu A4 karena ia
 * dipegang orang dan dipotong kecil. Kartu ini berbeda tugas: ia ditempel di
 * daun pintu, dan yang membacanya berdiri di depan pintu sambil memegang
 * ponsel. QR yang cukup besar untuk dipindai dari jarak sejengkal dan tautan
 * yang bisa dibaca tanpa menunduk membutuhkan seluruh lembar - memadatkan
 * beberapa ruang ke satu halaman berarti QR sebesar prangko yang gagal
 * dipindai di bawah lampu koridor.
 *
 * Karena itu jumlah lembarnya sama dengan jumlah ruang: 24 ruang, 24 lembar,
 * satu per pintu.
 *
 * ---------------------------------------------------------------------
 * Ruang tanpa tautan tetap mendapat halaman, berisi pemberitahuan
 * ---------------------------------------------------------------------
 * Ruang yang tautannya belum diterbitkan tidak dilewati diam-diam. Kalau ia
 * dilewati, Admin yang mencetak lalu menempel "semua" akan menemukan satu
 * pintu tanpa kertas dan tidak tahu apakah itu kelalaian cetak atau memang
 * belum diterbitkan. Halamannya tetap ada, dan isinya mengatakan yang
 * sebenarnya: terbitkan dulu tautannya.
 *
 * ---------------------------------------------------------------------
 * Yang TIDAK ada di kartu ini: KODE
 * ---------------------------------------------------------------------
 * Hanya tautan yang tercetak. Kode pengawas milik ORANG dan hidup di kartu
 * panitia, bukan di sini - memfoto kertas di pintu tidak boleh cukup untuk
 * masuk. Ini pembagian yang sama yang berlaku sejak 2026-11-07.
 */
class KartuPintu
{
    public const UKURAN = 'a4';

    /**
     * Render satu halaman A4 per ruang sebagai satu PDF.
     *
     * @param  iterable<int, Room>  $ruang
     * @param  array{nama: string, npsn: string|null, logo: string|null}  $sekolah
     */
    public function buat(iterable $ruang, array $sekolah): string
    {
        return Pdf::loadView('kartu.ruang', [
            'sekolah' => $sekolah,
            'halaman' => $this->halaman($ruang),
        ])->setPaper(self::UKURAN, 'portrait')->output();
    }

    public function namaBerkas(): string
    {
        return 'kartu-pintu-ruang.pdf';
    }

    /**
     * Satu halaman sebagai array siap render.
     *
     * Tautan yang aktif dibaca dari relasi `entryLinks` yang sudah dimuat
     * pemanggil, bukan lewat `tautanAktif()` yang menembak satu kueri per
     * ruang. Syarat "berlaku" tetap milik model (`RoomEntryLink::berlaku()`),
     * jadi definisinya tidak bercabang - yang dihindari hanya kueri berulang.
     *
     * Public karena ini yang diuji: bahwa ruang tanpa tautan menghasilkan
     * halaman berisi pemberitahuan (bukan terlewat), dan bahwa QR hanya
     * dibuat untuk tautan yang berlaku, adalah dua aturan yang kegagalannya
     * tidak terlihat pada PDF.
     *
     * @param  iterable<int, Room>  $ruang
     * @return array<int, array<string, string|null>>
     */
    public function halaman(iterable $ruang): array
    {
        $halaman = [];

        foreach ($ruang as $r) {
            $tautan = $r->entryLinks
                ->filter(fn ($t) => $t->berlaku())
                ->sortByDesc('created_at')
                ->first();

            $url = $tautan ? url('/p/'.$tautan->slug) : null;

            $halaman[] = [
                'kode' => $r->code,
                'nama' => $r->name,
                'lokasi' => $r->location,
                'url' => $url,
                // QR dibuat hanya bila ada tautan; halaman tanpa tautan
                // menampilkan pemberitahuan, bukan kotak QR kosong.
                'qr' => $url ? KodeQr::dataUri($url, 640) : null,
                'kedaluwarsa' => $tautan?->expires_at?->format('d/m/Y H:i'),
            ];
        }

        return $halaman;
    }
}

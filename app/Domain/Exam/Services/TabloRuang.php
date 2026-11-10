<?php

namespace App\Domain\Exam\Services;

use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Tablo - daftar hadir per ruang, sebagai PDF. Wireframe plate 16d.
 *
 * =====================================================================
 * Mengapa kertas, padahal semuanya sudah ada di layar
 * =====================================================================
 * Tablo adalah lembar yang DITANDATANGANI. Pengawas membawanya ke ruang
 * ujian, mencentang kehadiran anak satu per satu, mencatat jumlah hadir dan
 * tidak hadir di kakinya, lalu menyerahkannya ke panitia. Tanda tangan tidak
 * bisa dibubuhkan di layar, dan berita acara yang tidak bertanda tangan
 * adalah berita acara yang tidak bisa dipertanggungjawabkan.
 *
 * Karena itu bentuknya mengikuti cara kertas itu dipakai, bukan cara data
 * disimpan: satu halaman per ruang, kolom tanda tangan yang KOSONG, dan
 * ruang kosong untuk pengawas ruang di kaki halaman.
 *
 * =====================================================================
 * Satu halaman per ruang, dan halaman terakhir tanpa `page-break-after`
 * =====================================================================
 * Pola yang sama dengan `KartuPintu`: DomPDF menuruti `page-break-after`
 * apa adanya, dan halaman kosong di belakang lembar terakhir adalah
 * pemborosan kertas yang selalu diperhatikan orang yang mencetak tiga puluh
 * lembar.
 *
 * =====================================================================
 * Nomor kursinya SAMA dengan nomor di denah dan di layar pengawas
 * =====================================================================
 * Urutannya dari `NomorKursi`, bukan dari urutan kueri di sini. Tablo yang
 * menomori kursi dengan aturan sendiri adalah tablo yang membuat pengawas
 * memanggil nomor yang tidak ada di denah yang ditempel di meja - dan itu
 * kesalahan yang baru ketahuan setelah enam lembar dibagikan.
 *
 * =====================================================================
 * Tablo TIDAK boleh ditempel di koridor
 * =====================================================================
 * Ia memuat empat puluh nama anak. Halaman `/p/{slug}` di pintu sengaja tidak
 * memuat satu pun nama - dan lembar yang memuatnya membatalkan penjagaan itu
 * bila dipasang di daun pintu bersama kartu QR. Yang dicetak untuk pintu
 * tetap kartu QR; tablo berhenti di meja pengawas.
 */
final class TabloRuang
{
    public const UKURAN = 'a4';

    /**
     * PDF seluruh halaman tablo.
     *
     * @param  iterable<int, array<string, mixed>>  $halaman
     * @param  array{nama: string, npsn: ?string, logo: ?string}  $sekolah
     */
    public function buat(iterable $halaman, array $sekolah): string
    {
        return Pdf::loadView('kartu.tablo', [
            'sekolah' => $sekolah,
            'halaman' => $halaman,
        ])->setPaper(self::UKURAN, 'portrait')->output();
    }

    public function namaBerkas(): string
    {
        return 'tablo-daftar-hadir.pdf';
    }

    /**
     * Susun satu halaman tablo untuk satu ruang.
     *
     * Seluruh kursi yang DISEDIAKAN ruang itu ikut tercetak, termasuk yang
     * belum terisi. Baris kosongnya bukan cacat: pengawas memakainya untuk
     * menuliskan siswa pindahan, dan lembar yang berhenti di kursi terakhir
     * yang kebetulan terisi membuat ruangan tampak lebih penuh daripada
     * kenyataannya.
     *
     * @param  array<string, mixed>  $ruang  Hasil `ruang()`.
     * @return array<string, mixed>
     */
    public function halaman(array $ruang): array
    {
        return [
            'kode' => $ruang['kode'],
            'nama' => $ruang['nama'],
            'ujian' => $ruang['ujian'],
            'sesi' => $ruang['sesi'],
            'jam' => $ruang['jam'],
            'kapasitas' => $ruang['kapasitas'],
            'terisi' => $ruang['terisi'],
            'jumlah_meja' => (int) ceil(max(1, $ruang['kapasitas']) / NomorKursi::KURSI_PER_MEJA),
            'kursi_per_meja' => NomorKursi::KURSI_PER_MEJA,
            'baris' => $ruang['baris'],
        ];
    }
}

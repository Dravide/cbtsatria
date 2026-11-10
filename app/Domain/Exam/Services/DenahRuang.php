<?php

namespace App\Domain\Exam\Services;

use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Denah tempat duduk satu ruang sebagai PDF - lembar yang DITEMPPEL di meja.
 *
 * =====================================================================
 * Mengapa kertas, padahal denahnya sudah ada di layar
 * =====================================================================
 * Denah di layar dibaca oleh Admin yang menyiapkan hari ujian. Lembar ini
 * dibaca oleh ORANG YANG DUDUK di ruangan itu, dan lebih sering lagi oleh
 * pengawas yang menyusur meja mencari anak yang belum hadir. Yang dibutuhkan
 * di situ bukan layar, melainkan selembar kertas yang bisa ditempel di papan
 * tulis dan dibaca dari jarak beberapa meter.
 *
 * =====================================================================
 * Bentuknya SAMA dengan denah di layar, dan itu yang membuatnya berguna
 * =====================================================================
 * Susunannya datang dari `PapanKursi` - sumber yang sama dengan laman denah,
 * tablo daftar hadir, dan papan pengawas. Lembar yang menyusun ulang mejanya
 * sendiri akan menaruh anak di kursi yang berbeda dari yang tercetak di
 * daftar hadir, dan selisih itu baru ketahuan setelah kertasnya terlanjur
 * ditempel.
 *
 * =====================================================================
 * Nama ikut tercetak, dan itu keputusan yang perlu disebut
 * =====================================================================
 * Kartu pintu (`KartuPintu`) sengaja tidak memuat satu pun nama. Lembar ini
 * sebaliknya: ia ditempel DI DALAM ruang ujian, di papan tulis atau di
 * belakang pintu, dan gunanya justru menunjukkan siapa duduk di mana.
 * Memisahkan keduanya penting - yang mencetak denah ini tidak boleh
 * memindahkannya ke koridor.
 *
 * =====================================================================
 * Kursi kosong tetap digambar
 * =====================================================================
 * Meja yang kursinya seluruhnya kosong tetap mendapat kotaknya. Denah yang
 * berhenti di kursi terakhir yang kebetulan terisi membuat ruangan tampak
 * lebih kecil daripada kenyataannya, dan pengawas yang mencari tempat untuk
 * siswa pindahan akan menyusur meja yang sebenarnya sudah penuh.
 */
final class DenahRuang
{
    public const UKURAN = 'a4';

    /**
     * Mendatar, dan itu bukan pilihan gaya.
     *
     * =====================================================================
     * Denah dibaca menyusur, bukan menurun
     * =====================================================================
     * Bentuk Z-nya menyusur EMPAT meja per baris - delapan kursi sepanjang
     * satu jalur masuk. Pada kertas tegak, empat kotak berisi nama dan NIS
     * dalam satu baris dipaksa menjadi kolom sempit yang memotong nama
     * terpanjang; pada kertas mendatar kolomnya cukup lebar untuk dibaca dari
     * jarak beberapa meter.
     *
     * Tablo daftar hadir tetap tegak: ia dibaca menurun, satu nama per baris,
     * dan pengawas mencentangnya dengan pena.
     */
    public const ORIENTASI = 'landscape';

    /**
     * PDF seluruh halaman denah.
     *
     * @param  iterable<int, array<string, mixed>>  $halaman
     * @param  array{nama: string, npsn: ?string, logo: ?string}  $sekolah
     */
    public function buat(iterable $halaman, array $sekolah): string
    {
        return Pdf::loadView('kartu.denah', [
            'sekolah' => $sekolah,
            'halaman' => $halaman,
        ])->setPaper(self::UKURAN, self::ORIENTASI)->output();
    }

    public function namaBerkas(): string
    {
        return 'denah-tempat-duduk.pdf';
    }

    /**
     * Susun satu halaman denah dari satu ruang hasil `PapanKursi`.
     *
     * `sel` dikelompokkan per MEJA di sini, bukan di blade: DomPDF tidak
     * punya `flexbox` maupun `grid`, sehingga satu-satunya cara menggambar
     * deretan meja adalah tabel dengan kolom tetap. Pengelompokannya
     * mengikuti `meja` yang sudah dihitung `NomorKursi`, sehingga meja yang
     * nomornya melompat tetap diletakkan di baris yang benar.
     *
     * @param  array<string, mixed>  $ruang
     * @return array<string, mixed>
     */
    public function halaman(array $ruang): array
    {
        $perMeja = [];

        foreach ($ruang['sel'] as $s) {
            $perMeja[(int) $s['meja']][] = $s;
        }

        ksort($perMeja);

        $jumlahMeja = (int) ceil(max(1, (int) $ruang['kapasitas']) / NomorKursi::KURSI_PER_MEJA);

        return [
            'kode' => $ruang['kode'],
            'nama' => $ruang['nama'],
            'ujian' => $ruang['ujian'],
            'sesi' => $ruang['sesi'],
            'jam' => $ruang['jam'],
            'tanggal' => $ruang['tanggal'],
            'kapasitas' => (int) $ruang['kapasitas'],
            'terisi' => (int) $ruang['terisi'],
            'jumlah_meja' => $jumlahMeja,
            'kursi_per_meja' => NomorKursi::KURSI_PER_MEJA,
            'meja_per_baris' => NomorKursi::MEJA_PER_BARIS,
            'baris_meja' => $this->barisMeja($perMeja, NomorKursi::MEJA_PER_BARIS),
        ];
    }

    /**
     * Kelompokkan meja menjadi baris-baris denah, bentuk Z.
     *
     * @param  array<int, array<int, array<string, mixed>>>  $perMeja
     * @return array<int, array<int, array<int, array<string, mixed>>>>
     */
    private function barisMeja(array $perMeja, int $perBaris): array
    {
        $baris = [];

        foreach ($perMeja as $nomor => $sel) {
            $indeks = intdiv($nomor - 1, $perBaris);
            $baris[$indeks][] = $sel;
        }

        ksort($baris);

        // Lubang di tengah - mis. kapasitas yang berubah sesudah penomoran -
        // diisi baris kosong supaya nomor barisnya tetap terbaca.
        $penuh = [];

        for ($i = 0; $i <= array_key_last($baris ?: [0 => []]); $i++) {
            $penuh[$i] = $baris[$i] ?? [];
        }

        return $penuh;
    }
}

<?php

namespace App\Domain\Event\Services;

use App\Domain\Event\Models\EventCommitteeMember;
use App\Domain\Event\Models\SchoolEvent;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Ekspor daftar panitia event ke XLSX - PRD §6.6.
 *
 * ---------------------------------------------------------------------
 * Mengapa SINKRON, padahal ekspor pengguna dan laporan memakai antrean
 * ---------------------------------------------------------------------
 * PRD §19.3 menuntut antrean untuk pekerjaan yang "membutuhkan waktu lama",
 * dan yang membuat ekspor pengguna lama adalah 5.000 akun beserta profilnya.
 * Panitia satu event adalah puluhan baris dari satu kueri yang sudah dimuat
 * laman - dan menaruh pekerjaan secepat itu di antrean berarti Admin harus
 * menunggu worker hanya untuk mendapat berkas yang sudah bisa dibuat sebelum
 * jawaban HTTP-nya selesai disusun.
 *
 * Antrean di sini juga punya harga yang tidak sepadan: `user_exports`
 * menyimpan riwayat, penyaring, dan status - seluruh mesin yang berguna
 * untuk pekerjaan menit-menitan, dan yang di sini hanya akan menjadi tabel
 * yang selalu berisi satu baris `selesai`.
 *
 * ---------------------------------------------------------------------
 * Kolomnya SAMA dengan yang dibaca laman, bukan kueri kedua
 * ---------------------------------------------------------------------
 * `EventCommitteeController::index()` sudah memuat panitianya dengan relasi
 * ruang dan akun. Ekspor ini menerima koleksi yang SAMA - bukan menjalankan
 * kuerinya sendiri - karena dua kueri untuk satu daftar adalah dua daftar
 * yang bisa berbeda, dan yang berbeda baru ketahuan saat Admin membandingkan
 * Excel-nya dengan layarnya.
 */
class EksporPanitia
{
    /**
     * Kepala kolom.
     *
     * Urutannya sengaja sama dengan urutan kolom tabel plate 13 - Nama,
     * Jabatan, Cakupan, Status, Kode pengawas - sehingga berkasnya terbaca
     * oleh orang yang sudah mengenal laman itu, tanpa mempelajari urutan
     * baru.
     *
     * `kontak` ada di berkas tetapi tidak di tabel: yang menyalin daftar ini
     * ke WhatsApp panitia butuh nomor teleponnya, dan membukanya satu per
     * satu di laman adalah pekerjaan yang berkas ini justru menghapusnya.
     *
     * @return array<int, string>
     */
    public static function judul(): array
    {
        return ['Nama', 'Akun', 'Jabatan', 'Cakupan ruang', 'Status', 'Email', 'Telepon', 'Kode pengawas'];
    }

    /**
     * Bangun berkasnya dan kembalikan isinya sebagai string biner.
     *
     * @param  iterable<int, EventCommitteeMember>  $panitia
     */
    public function buat(SchoolEvent $event, iterable $panitia): string
    {
        $buku = new Spreadsheet;
        $lembar = $buku->getActiveSheet();
        $lembar->setTitle('Panitia');

        $lembar->fromArray(self::judul(), null, 'A1');

        $nomor = 2;

        foreach ($panitia as $p) {
            $lembar->fromArray($this->nilaiBaris($p), null, 'A'.$nomor);
            $nomor++;
        }

        foreach (array_keys(self::judul()) as $i) {
            $lembar->getColumnDimension(chr(65 + $i))->setAutoSize(true);
        }

        // Ditulis ke berkas sementara lebih dulu karena penulis XLSX menulis
        // ke path filesystem, bukan ke stream - pola yang sama dengan
        // `BuatEksporPengguna::tulisXlsx()`.
        $sementara = tempnam(sys_get_temp_dir(), 'panitia_').'.xlsx';
        (new Xlsx($buku))->save($sementara);

        $isi = (string) file_get_contents($sementara);

        @unlink($sementara);

        return $isi;
    }

    /**
     * Nama berkas yang dibaca Admin saat mengunduhnya.
     *
     * Kode event dipakai, bukan nama eventnya: nama event memuat spasi dan
     * garis miring yang membuat nama berkas rusak di sebagian sistem operasi,
     * sementara kode event sudah unik dan sudah dikenali Admin.
     */
    public function namaBerkas(SchoolEvent $event): string
    {
        return 'panitia-'.$event->code.'.xlsx';
    }

    /**
     * Satu panitia sebagai daftar nilai, berurutan sesuai `judul()`.
     *
     * @return array<int, mixed>
     */
    private function nilaiBaris(EventCommitteeMember $p): array
    {
        return [
            $p->name,
            $p->user?->username,
            $p->position,
            $p->labelCakupan(),
            $p->status,
            $p->email,
            $p->phone,
            // Kode pengawas ikut ke berkas karena berkasnya dipakai panitia
            // untuk MENCETAK kartu. Mengosongkannya berarti Admin harus
            // membuka laman untuk setiap baris - dan kolom yang kosong
            // melulu akan membuatnya mengira kodenya belum terbit.
            //
            // Baris tanpa kode ditulis kosong, bukan nol - sama seperti
            // ekspor lain: sel kosong berarti "tidak ada", dan itu memang
            // keadaannya bagi ketua, koordinator, dan panitia tanpa akun.
            $p->code,
        ];
    }
}

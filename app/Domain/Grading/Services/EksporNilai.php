<?php

namespace App\Domain\Grading\Services;

use App\Domain\Exam\Models\Exam;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Nilai satu ujian sebagai berkas XLSX - unduhan sisi Guru, plate 07.
 *
 * ---------------------------------------------------------------------
 * Kolomnya SAMA dengan tabel di layar, bukan kueri kedua
 * ---------------------------------------------------------------------
 * Alasan yang sama dengan `EksporPanitia`: barisnya disiapkan pemanggil
 * (controller yang sudah memuatnya untuk laman), dan kelas ini hanya
 * menuliskannya. Dua kueri untuk satu daftar adalah dua daftar yang bisa
 * berbeda, dan yang berbeda baru ketahuan saat Guru membandingkan Excel-nya
 * dengan layarnya.
 *
 * ---------------------------------------------------------------------
 * Uraian kosong ditulis sebagai sel KOSONG, bukan 0
 * ---------------------------------------------------------------------
 * Sel berisi 0 ikut dijumlahkan Excel sebagai nol, dan rata-rata yang
 * dihitung dari berkas itu akan berbeda dari rata-rata di layar. Berkasnya
 * karena itu hanya dibuat bila seluruh barisnya sudah final - lihat
 * `NilaiController::unduh()`. Kolom "Nilai uraian" tetap boleh kosong untuk
 * ujian tanpa uraian sama sekali; itu bukan keadaan "belum dikoreksi".
 */
class EksporNilai
{
    /**
     * Kepala kolom, urutannya mengikuti tabel plate 07.
     *
     * @return array<int, string>
     */
    public static function judul(): array
    {
        return ['NIS', 'Nama', 'Kelas', 'Nilai objektif', 'Nilai uraian', 'Nilai akhir', 'Status'];
    }

    /**
     * Bangun berkasnya dan kembalikan isinya sebagai string biner.
     *
     * @param  iterable<int, array{nis: ?string, nama: ?string, kelas: ?string, objektif: float, uraian: ?float, nilai: float, status_label: string}>  $baris
     */
    public function buat(Exam $ujian, iterable $baris): string
    {
        $buku = new Spreadsheet;
        $lembar = $buku->getActiveSheet();

        // Judul lembar dipotong 31 karakter: batas keras Excel, dan nama
        // mapel yang panjang akan membuat berkasnya ditolak saat dibuka.
        $lembar->setTitle(mb_substr($ujian->code ?? 'Nilai', 0, 31, 'UTF-8'));

        $lembar->fromArray(self::judul(), null, 'A1');

        $nomor = 2;

        foreach ($baris as $satu) {
            $lembar->fromArray([
                $satu['nis'],
                $satu['nama'],
                $satu['kelas'],
                $satu['objektif'],
                $satu['uraian'],
                $satu['nilai'],
                $satu['status_label'],
            ], null, 'A'.$nomor);

            $nomor++;
        }

        foreach (array_keys(self::judul()) as $i) {
            $lembar->getColumnDimension(chr(65 + $i))->setAutoSize(true);
        }

        // Penulis XLSX menulis ke path filesystem, bukan ke stream - pola
        // yang sama dengan `EksporPanitia::buat()`.
        $sementara = tempnam(sys_get_temp_dir(), 'nilai_').'.xlsx';
        (new Xlsx($buku))->save($sementara);

        $isi = (string) file_get_contents($sementara);

        @unlink($sementara);

        return $isi;
    }

    /**
     * Nama berkas yang dibaca Guru saat mengunduhnya.
     *
     * Kode ujian dipakai, bukan judulnya: judul memuat spasi dan garis miring
     * yang merusak nama berkas di sebagian sistem operasi. Nama kelas
     * ditambahkan bila unduhannya memang satu kelas - dua berkas yang
     * bernama sama di folder unduhan Guru membuatnya tidak tahu mana yang
     * mana.
     */
    public function namaBerkas(Exam $ujian, ?string $kelas = null): string
    {
        $dasar = 'nilai-'.($ujian->code ?? $ujian->id);

        return $kelas === null || $kelas === ''
            ? $dasar.'.xlsx'
            : $dasar.'-'.preg_replace('/[^A-Za-z0-9._-]+/', '-', $kelas).'.xlsx';
    }
}

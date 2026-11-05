<?php

namespace App\Domain\Attendance\Http\Controllers;

use App\Domain\Attendance\Models\SessionReport;
use App\Domain\Attendance\Models\SessionReportFile;
use App\Domain\Attendance\Models\SessionReportSignature;
use App\Domain\Attendance\Services\KonteksRuang;
use App\Domain\Exam\Models\ExamSessionRoom;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Berkas berita acara - foto dan tanda tangan - wireframe plate 18.
 *
 * =====================================================================
 * Disk PRIVAT, dan rute inilah satu-satunya jalan keluarnya
 * =====================================================================
 * Lihat catatan panjang di LaporanSesiService tentang mengapa berkasnya
 * TIDAK diletakkan di `storage/app/public` dan mengapa symlink
 * `public/storage` sengaja tidak dibuat.
 *
 * Akibatnya, berkas berita acara hanya bisa dibaca lewat sini - dan karena
 * rute ini memanggil `KonteksRuang::dari($ruang)` lagi, mencabut tautan
 * ruang mencabut fotonya juga, tanpa satu baris kode tambahan. Itu bukan
 * kebetulan: bukti yang masih bisa dibuka setelah kredensialnya dicabut
 * bukan bukti yang tercabut.
 *
 * ---------------------------------------------------------------------
 * DUA rute, bukan satu `/laporan/{berkas}` dengan id tercampur
 * ---------------------------------------------------------------------
 * Rencana awal menyebut satu rute. Yang membuatnya ditinggalkan adalah hal
 * yang akan menggigit diam-diam: `session_report_files` dan
 * `session_report_signatures` punya ruang id masing-masing, dan id 7 pada
 * yang pertama adalah foto ruang A sementara id 7 pada yang kedua adalah
 * tanda tangan ruang B.
 *
 * Satu rute berarti salah satu dari dua hal yang sama buruknya: id-nya
 * harus dikodekan ("f7"/"t7") sehingga jadi format yang harus diurai di dua
 * tempat, atau urutan pencariannya harus ditebak - dan tebakan yang salah
 * mengirim tanda tangan orang lain sebagai jawaban atas permintaan foto.
 * Dua rute menghapus seluruh pertanyaan itu, dan yang membedakannya adalah
 * MODEL yang di-bind, bukan angka di dalam string.
 *
 * ---------------------------------------------------------------------
 * Kedua model di-bind implisit, dan pemeriksaan sekolahnya tetap dijalankan
 * ---------------------------------------------------------------------
 * Pada permintaan ber-tautan belum ada user saat binding berjalan, sehingga
 * `SchoolScope` menjadi no-op - persis urutan yang dijelaskan di
 * AksesPengawas. Karena itu kepemilikannya TIDAK dipercaya dari hasil
 * binding: ruangnya diambil dari laporannya, dan `KonteksRuang::dari($ruang)`
 * yang memutuskan pemanggil berhak atau tidak. Untuk pemegang tautan,
 * pemeriksaan itu menuntut ruangnya SAMA PERSIS dengan ruang tautannya -
 * sehingga berkas milik sekolah lain berakhir 404.
 */
class BerkasLaporanController
{
    /** Foto berita acara. */
    public function foto(SessionReportFile $berkas): StreamedResponse
    {
        $this->izinkan($this->ruangDari($berkas->laporan));

        return $this->sajikan($berkas->path, $berkas->nama_asli, $berkas->mime);
    }

    /** Tanda tangan berita acara - PNG dari kanvas. */
    public function ttd(SessionReportSignature $ttd): StreamedResponse
    {
        $this->izinkan($this->ruangDari($ttd->laporan));

        return $this->sajikan($ttd->path, 'tanda-tangan-'.$ttd->peran->value.'.png', 'image/png');
    }

    /* ------------------------------------------------------------- Pembantu */

    /**
     * Ruang-sesi yang memiliki laporan ini.
     *
     * Relasi `ruangSesi()` dipakai apa adanya; bila barisnya sudah tidak ada
     * (ruang-sesi dihapus), permintaannya berakhir 404 sebagai
     * ModelNotFoundException - sama seperti berkas yang tidak ada.
     */
    private function ruangDari(?SessionReport $laporan): ExamSessionRoom
    {
        if ($laporan === null) {
            abort(404);
        }

        $ruang = $laporan->ruangSesi()->first();

        if ($ruang === null) {
            abort(404);
        }

        return $ruang;
    }

    /** KonteksRuang yang memutuskan; ia melempar 404 bila tidak berhak. */
    private function izinkan(ExamSessionRoom $ruang): void
    {
        KonteksRuang::dari($ruang);
    }

    /**
     * Kirim berkasnya, dengan nama aslinya.
     *
     * `Content-Disposition: inline` dipilih, bukan `attachment`: yang
     * membuka laman ini paling sering adalah pengawas yang ingin MELIHAT
     * fotonya sebelum laporan dikirim, dan berkas yang dipaksa terunduh
     * setiap kali dilihat mengotori folder unduhan ponselnya.
     *
     * Nama aslinya dikirim di sini, dan hanya di sini - di disk namanya
     * acak (lihat SessionReportFile). Kalau berkasnya sudah tidak ada,
     * jawabannya 404, bukan 500: berkas yang hilang dari disk adalah
     * keadaan yang bisa terjadi (pemulihan cadangan, pembersihan manual),
     * dan galat server untuk keadaan itu tidak memberi tahu siapa pun apa
     * yang sebenarnya terjadi.
     */
    private function sajikan(?string $path, ?string $nama, ?string $mime): StreamedResponse
    {
        if ($path === null || ! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        return Storage::disk('local')->response(
            $path,
            $nama ?? basename($path),
            ['Content-Type' => $mime ?? 'application/octet-stream'],
        );
    }
}

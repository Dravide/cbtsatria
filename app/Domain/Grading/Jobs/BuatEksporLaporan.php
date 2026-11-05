<?php

namespace App\Domain\Grading\Jobs;

use App\Domain\Grading\Exports\LaporanData;
use App\Domain\Grading\Models\ReportExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

/**
 * Menyiapkan satu berkas laporan di latar belakang - plate 22.
 *
 * =====================================================================
 * Mengapa antrean, padahal berkasnya bisa dibuat langsung
 * =====================================================================
 * Kalimat di kaki plate 22: "berkas besar: disiapkan di latar belakang".
 * Nilai satu angkatan adalah 1.284 baris lintas enam ujian, dan menyiapkannya
 * di dalam permintaan HTTP berarti Admin menatap tombol berputar selama
 * pekerjaan yang sama sekali tidak ia butuhkan untuk membacanya - sementara
 * worker menunggu.
 *
 * Yang diantrekan adalah SATU baris (`report_exports`), dan job ini yang
 * mengisinya. Ekspor yang bisa selesai dalam milidetik tetap lewat jalur yang
 * sama: satu jalur, satu tempat yang bisa gagal dengan benar.
 *
 * =====================================================================
 * `tries = 1`, dan itu bukan kelalaian
 * =====================================================================
 * Ekspor yang gagal karena basis data sedang sibuk layak diulang; ekspor
 * yang gagal karena parameternya salah akan gagal lagi dengan cara yang sama
 * tiga kali, dan Admin menunggu tiga kali lebih lama untuk pesan galat yang
 * sama. Yang mengulang adalah Admin, dengan penyaring yang sudah ia perbaiki.
 *
 * =====================================================================
 * Galatnya DITANGKAP, bukan dilempar
 * =====================================================================
 * `failed()` tidak cukup: job yang melempar membiarkan barisnya berstatus
 * `diproses` sampai batas waktu berikutnya, dan Admin melihat "Diproses"
 * selamanya. Yang ditangkap di sini ditulis ke barisnya sendiri, dengan
 * pesan yang bisa ia baca.
 */
class BuatEksporLaporan implements ShouldQueue
{
    use Queueable;

    /** Satu percobaan - lihat catatan kepala kelas. */
    public int $tries = 1;

    /** Batas waktu worker. Laporan 1.284 baris selesai jauh di bawah ini. */
    public int $timeout = 300;

    public function __construct(public readonly int $eksporId) {}

    public function handle(): void
    {
        $ekspor = ReportExport::query()->withoutGlobalScopes()->find($this->eksporId);

        if ($ekspor === null || $ekspor->status === ReportExport::STATUS_SELESAI) {
            return;
        }

        $ekspor->forceFill([
            'status' => ReportExport::STATUS_DIPROSES,
            'started_at' => now(),
        ])->save();

        try {
            $baris = LaporanData::ambil(
                $ekspor->jenis,
                (int) ($ekspor->parameter_json['event_id'] ?? 0),
                $ekspor->parameter_json ?? [],
            );

            $kolom = LaporanData::kolom($ekspor->jenis);
            $nama = $this->namaBerkas($ekspor);
            $path = 'laporan/'.$ekspor->school_id.'/'.$nama;

            match ($ekspor->format) {
                'pdf' => $this->tulisPdf($path, $ekspor, $kolom, $baris),
                'csv' => $this->tulisCsv($path, $kolom, $baris),
                default => $this->tulisXlsx($path, $kolom, $baris),
            };

            $ekspor->forceFill([
                'status' => ReportExport::STATUS_SELESAI,
                'total_baris' => $baris->count(),
                'path' => $path,
                'nama_berkas' => $nama,
                'ukuran' => Storage::disk('local')->size($path),
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            // Pesannya dipotong 500 karakter - kolomnya text, dan yang
            // membacanya Admin, bukan pengembang. Jejak lengkapnya tetap
            // sampai ke log lewat report().
            report($e);

            $ekspor->forceFill([
                'status' => ReportExport::STATUS_GAGAL,
                'pesan_gagal' => mb_substr($e->getMessage(), 0, 500, 'UTF-8'),
                'finished_at' => now(),
            ])->save();
        }
    }

    /* ------------------------------------------------------------ Penulis */

    private function tulisXlsx(string $path, array $kolom, $baris): void
    {
        $buku = new Spreadsheet;
        $lembar = $buku->getActiveSheet();
        $lembar->setTitle('Laporan');

        $lembar->fromArray(array_values($kolom), null, 'A1');

        $nomor = 2;

        foreach ($baris as $satu) {
            $lembar->fromArray($this->nilaiBaris($kolom, $satu), null, 'A'.$nomor);
            $nomor++;
        }

        // Lebar kolom mengikuti isinya, dibatasi: tanpa batas, satu nama
        // sekolah yang panjang membuat satu kolom selebar layar.
        foreach (array_keys($kolom) as $i => $kunci) {
            $lembar->getColumnDimension(chr(65 + $i))->setAutoSize(true);
        }

        // Ditulis ke berkas sementara lebih dulu, lalu dipindahkan ke disk
        // privat. Penulis XLSX menulis ke path filesystem, bukan ke disk
        // abstrak Laravel - memindahkannya membuat satu jalur yang sama untuk
        // ketiga format.
        $sementara = tempnam(sys_get_temp_dir(), 'ekspor_').'.xlsx';
        (new Xlsx($buku))->save($sementara);

        Storage::disk('local')->put($path, file_get_contents($sementara));

        @unlink($sementara);
    }

    private function tulisCsv(string $path, array $kolom, $baris): void
    {
        $pegangan = fopen('php://temp', 'r+');

        // BOM UTF-8: tanpa ini Excel di Windows membaca nama berhuruf
        // beraksen sebagai karakter rusak, dan laporan yang dibuka pertama
        // kali terlihat seperti berkas yang gagal dibuat.
        fwrite($pegangan, "\xEF\xBB\xBF");
        fputcsv($pegangan, array_values($kolom), ';');

        foreach ($baris as $satu) {
            fputcsv($pegangan, $this->nilaiBaris($kolom, $satu), ';');
        }

        rewind($pegangan);
        Storage::disk('local')->put($path, stream_get_contents($pegangan));
        fclose($pegangan);
    }

    /**
     * PDF memuat lima puluh baris pertama, bukan seluruhnya.
     *
     * Bukan pembatasan teknis: PDF adalah format yang DICETAK, dan laporan
     * 1.284 baris dalam satu berkas PDF adalah berkas yang tidak muat di meja
     * Admin maupun di printer sekolah. Yang membutuhkan seluruh baris memakai
     * XLSX.
     */
    private function tulisPdf(string $path, ReportExport $ekspor, array $kolom, $baris): void
    {
        $pdf = Pdf::loadView('laporan.pdf', [
            'judul' => LaporanData::JENIS[$ekspor->jenis] ?? 'Laporan',
            'kolom' => $kolom,
            'baris' => $baris->take(50)->map(fn ($satu) => $this->nilaiBaris($kolom, $satu))->all(),
            'total' => $baris->count(),
            'dibuat' => now()->format('d M Y H:i'),
        ])->setPaper('a4', 'landscape');

        Storage::disk('local')->put($path, $pdf->output());
    }

    /**
     * Satu baris sebagai daftar nilai, berurutan sesuai kepala kolom.
     *
     * @param  array<string, string>  $kolom
     * @return array<int, mixed>
     */
    private function nilaiBaris(array $kolom, array $satu): array
    {
        $hasil = [];

        foreach (array_keys($kolom) as $kunci) {
            $nilai = $satu[$kunci] ?? null;

            // Null ditulis sebagai sel KOSONG, bukan sebagai 0. Nilai yang
            // masih menunggu koreksi uraian adalah contohnya - dan sel berisi
            // 0 akan ikut dijumlahkan Excel sebagai nol.
            $hasil[] = $nilai;
        }

        return $hasil;
    }

    private function namaBerkas(ReportExport $ekspor): string
    {
        $jenis = $ekspor->jenis;
        $slug = Str::slug(LaporanData::JENIS[$jenis] ?? $jenis);

        // Nama acak di DEPAN, nama nyaman di belakang: berkas di disk privat
        // tidak bisa ditebak, tetapi yang sudah diunduh Admin tetap bernama
        // jelas di folder unduhannya.
        return Str::random(20).'-'.$slug.'.'.$ekspor->format;
    }
}

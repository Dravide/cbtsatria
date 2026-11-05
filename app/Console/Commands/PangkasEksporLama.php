<?php

namespace App\Console\Commands;

use App\Domain\Grading\Models\ReportExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Pangkas ekspor laporan yang sudah lama - Fase 9.
 *
 * =====================================================================
 * Mengapa berkasnya harus dipangkas sama sekali
 * =====================================================================
 * Setiap ekspor yang selesai meninggalkan satu berkas di disk privat:
 * XLSX 1.284 baris, PDF, atau CSV. Berkas itu tidak punya masa berlaku -
 * dan Admin yang membuat enam laporan seminggu selama satu tahun akademik
 * meninggalkan lebih dari tiga ratus berkas yang tidak pernah dibuka lagi,
 * masing-masing ratusan kilobyte.
 *
 * Yang membuatnya bukan sekadar soal ruang: `storage/app/private` juga
 * menyimpan media soal, berita acara, dan tanda tangan. Disk yang penuh
 * karena laporan lama menghentikan penyimpanan berita acara - dan berita
 * acara adalah dokumen yang HARUS ada, sementara laporannya bisa dibuat ulang
 * kapan saja dari data yang masih ada.
 *
 * =====================================================================
 * Berkasnya dihapus LEBIH DULU, barisnya belakangan
 * =====================================================================
 * Urutan itu penting, dan bukan pilihan gaya.
 *
 * Kalau barisnya dihapus lebih dulu lalu penghapusan berkasnya gagal, yang
 * tersisa adalah berkas yatim yang TIDAK ADA LAGI yang menunjuknya - tidak
 * ada satu pun baris di basis data yang menyebut path itu, sehingga tidak ada
 * cara menemukannya selain memeriksa isi direktori satu per satu. Berkas
 * seperti itu akan tinggal di disk selamanya, dan justru itulah masalah yang
 * perintah ini dibuat untuk menyelesaikannya.
 *
 * Dengan urutan ini, kegagalan menghapus berkas meninggalkan barisnya utuh -
 * dan putaran berikutnya (besok malam) akan mencobanya lagi.
 *
 * =====================================================================
 * Hanya `selesai` dan `gagal` yang dipangkas
 * =====================================================================
 * `menunggu` dan `diproses` adalah pekerjaan yang sedang berjalan: yang
 * pertama belum tersentuh worker, yang kedua mungkin sedang menulis berkasnya
 * saat perintah ini berjalan. Memangkas keduanya akan menghapus baris di
 * bawah job yang sedang bekerja, dan job itu akan menulis `path` ke baris
 * yang sudah tidak ada.
 *
 * `--hari` dihitung dari `created_at`, bukan `finished_at`: ekspor yang
 * menunggu berhari-hari lalu gagal tetap harus dipangkas, dan `finished_at`
 * yang terisi kemarin akan membuatnya lolos dari sapuan berikutnya.
 */
class PangkasEksporLama extends Command
{
    protected $signature = 'laporan:pangkas-ekspor
                            {--hari=30 : Umur minimum sebelum sebuah ekspor dipangkas}
                            {--kering : Laporkan saja, jangan hapus apa pun}';

    protected $description = 'Hapus berkas dan baris ekspor laporan yang lebih tua dari N hari';

    public function handle(): int
    {
        $hari = max(1, (int) $this->option('hari'));
        $kering = (bool) $this->option('kering');
        $batas = now()->subDays($hari);

        $ekspor = ReportExport::query()
            ->withoutGlobalScopes()
            ->whereIn('status', [ReportExport::STATUS_SELESAI, ReportExport::STATUS_GAGAL])
            ->where('created_at', '<=', $batas)
            ->orderBy('id')
            ->get();

        if ($ekspor->isEmpty()) {
            $this->info("Tidak ada ekspor lebih tua dari {$hari} hari.");

            return self::SUCCESS;
        }

        $berkas = 0;
        $byte = 0;
        $gagal = 0;

        foreach ($ekspor as $satu) {
            // `path` NULL pada ekspor yang gagal sebelum sempat menulis
            // berkas, dan pada ekspor XLSX yang gagal di tengah penulisan.
            // Pemeriksaannya eksplisit supaya `null` tidak sampai ke
            // `Storage::exists()` - yang akan mengembalikan false dan
            // menyamarkan baris yang memang tidak punya berkas sebagai baris
            // yang berkasnya hilang.
            $punyaBerkas = $satu->path !== null && Storage::disk('local')->exists($satu->path);

            if ($kering) {
                $this->line(sprintf(
                    '  #%d  %s  %s  %s',
                    $satu->id,
                    $satu->jenis,
                    $punyaBerkas ? $satu->path : '(tanpa berkas)',
                    $punyaBerkas ? $this->ukuranManusia((int) Storage::disk('local')->size($satu->path)) : '-',
                ));

                if ($punyaBerkas) {
                    $berkas++;
                    $byte += (int) Storage::disk('local')->size($satu->path);
                }

                continue;
            }

            if ($punyaBerkas) {
                try {
                    $ukuran = (int) Storage::disk('local')->size($satu->path);

                    Storage::disk('local')->delete($satu->path);

                    $berkas++;
                    $byte += $ukuran;
                } catch (Throwable $e) {
                    // Berkasnya gagal dihapus, jadi barisnya DIBIARKAN -
                    // lihat catatan kepala kelas. Kegagalannya dilaporkan
                    // supaya tidak hilang, dan angkanya tetap disebut di
                    // ringkasan sehingga kebocoran disk tidak pernah tidak
                    // terlihat.
                    report($e);
                    $gagal++;

                    continue;
                }
            }

            $satu->delete();
        }

        $ringkas = "{$ekspor->count()} baris, {$berkas} berkas, ".$this->ukuranManusia($byte);

        if ($kering) {
            $this->info("{$ringkas} akan dipangkas (mode kering - tidak ada yang dihapus).");

            return self::SUCCESS;
        }

        $this->info("Dipangkas: {$ringkas}.");

        if ($gagal > 0) {
            // Baris yang berkasnya gagal dihapus sengaja tidak dihapus, dan
            // angka itu disebut supaya tidak ada yang mengira sapuannya
            // bersih padahal ada yang tersisa.
            $this->warn("{$gagal} baris dilewati karena berkasnya gagal dihapus.");
        }

        return self::SUCCESS;
    }

    /** Ukuran dalam satuan yang bisa dibaca Admin di keluaran cron. */
    private function ukuranManusia(int $byte): string
    {
        return match (true) {
            $byte >= 1024 * 1024 => round($byte / 1024 / 1024, 1).' MB',
            $byte >= 1024 => round($byte / 1024, 1).' KB',
            default => $byte.' B',
        };
    }
}

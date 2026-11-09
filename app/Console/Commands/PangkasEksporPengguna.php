<?php

namespace App\Console\Commands;

use App\Domain\Admin\Models\UserExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Pangkas ekspor pengguna yang sudah lama - Fase 9, diperluas.
 *
 * =====================================================================
 * Mengapa perintah kedua, bukan satu perintah untuk keduanya
 * =====================================================================
 * Bentuknya memang sama dengan `PangkasEksporLama`, dan itu sudah
 * dipertimbangkan: potongan bersama itu (`--hari`, `--kering`, ringkasan
 * ukuran, urutan hapus berkas-lalu-baris) bisa diangkat jadi trait di
 * `App\Support\Ekspor`. Yang menahannya sekarang adalah jumlahnya - dua
 * pemakai, dan yang kedua baru. Abstraksi atas dua contoh yang belum pernah
 * berbeda adalah abstraksi yang menebak; yang ketiga akan membuat polanya
 * terlihat, dan saat itu trait-nya ditulis dengan bukti.
 *
 * Yang TIDAK boleh terjadi adalah salah satunya diam-diam tidak dijadwalkan:
 * ekspor pengguna meninggalkan berkas dengan ukuran yang sama besarnya, dan
 * `storage/app/private` juga menyimpan berita acara serta media soal.
 *
 * =====================================================================
 * Berkasnya dihapus LEBIH DULU, barisnya belakangan
 * =====================================================================
 * Urutan yang sama dengan `PangkasEksporLama`, dan alasannya sama: kalau
 * barisnya dihapus lebih dulu lalu penghapusan berkas gagal, yang tersisa
 * adalah berkas yatim yang tidak ada satu pun baris yang menunjuknya -
 * sehingga tidak ada cara menemukannya selain memeriksa isi direktori satu
 * per satu. Dengan urutan ini, kegagalannya meninggalkan barisnya utuh dan
 * putaran besok malam mencobanya lagi.
 *
 * =====================================================================
 * Hanya `selesai` dan `gagal` yang dipangkas
 * =====================================================================
 * `menunggu` dan `diproses` adalah pekerjaan yang sedang berjalan - yang
 * kedua mungkin sedang menulis berkasnya saat perintah ini berjalan.
 * Memangkasnya akan menghapus baris di bawah job yang sedang bekerja.
 */
class PangkasEksporPengguna extends Command
{
    protected $signature = 'pengguna:pangkas-ekspor
                            {--hari=30 : Umur minimum sebelum sebuah ekspor dipangkas}
                            {--kering : Laporkan saja, jangan hapus apa pun}';

    protected $description = 'Hapus berkas dan baris ekspor pengguna yang lebih tua dari N hari';

    public function handle(): int
    {
        $hari = max(1, (int) $this->option('hari'));
        $kering = (bool) $this->option('kering');
        $batas = now()->subDays($hari);

        $ekspor = UserExport::query()
            // Tanpa scope: perintah ini berjalan dari cron, tanpa pengguna
            // terautentikasi, sehingga `SchoolResolver::id()` null dan
            // scope-nya memang tidak menyala. `withoutGlobalScopes()` membuat
            // niatnya terbaca daripada bergantung pada keadaan itu.
            ->withoutGlobalScopes()
            ->whereIn('status', [UserExport::STATUS_SELESAI, UserExport::STATUS_GAGAL])
            // `created_at`, bukan `finished_at`: ekspor yang menunggu
            // berhari-hari lalu gagal tetap harus dipangkas, dan
            // `finished_at` yang terisi kemarin akan membuatnya lolos dari
            // sapuan berikutnya.
            ->where('created_at', '<=', $batas)
            ->orderBy('id')
            ->get();

        if ($ekspor->isEmpty()) {
            $this->info("Tidak ada ekspor pengguna lebih tua dari {$hari} hari.");

            return self::SUCCESS;
        }

        $berkas = 0;
        $byte = 0;
        $gagal = 0;

        foreach ($ekspor as $satu) {
            // `path` NULL pada ekspor yang gagal sebelum sempat menulis
            // berkas. Pemeriksaannya eksplisit supaya `null` tidak sampai ke
            // `Storage::exists()` - yang akan mengembalikan false dan
            // menyamarkan baris yang memang tidak punya berkas sebagai baris
            // yang berkasnya hilang.
            $punyaBerkas = $satu->path !== null && Storage::disk('local')->exists($satu->path);

            if ($kering) {
                $this->line(sprintf(
                    '  #%d  %s  %s  %s',
                    $satu->id,
                    strtoupper($satu->format),
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

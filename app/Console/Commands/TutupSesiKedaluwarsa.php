<?php

namespace App\Console\Commands;

use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Exam\Enums\StatusSesi;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Services\SesiService;
use DomainException;
use Illuminate\Console\Command;

/**
 * Tutup sesi yang jendelanya sudah berakhir - ditambahkan 2026-11-14.
 *
 * =====================================================================
 * Gejala yang melahirkannya
 * =====================================================================
 * Sesi yang `hard_end_at`-nya lewat TIDAK pernah pindah dari `berjalan`,
 * karena satu-satunya penulis status itu adalah `SesiService::tutup()` -
 * dan pemanggilnya hanya tombol Tutup di laman jadwal. Admin yang lupa
 * menekannya meninggalkan dua akibat yang keduanya berbunyi sebagai
 * kebenaran yang salah:
 *
 *   - `/admin/events` tab Berjalan terus memuat event yang ujiannya sudah
 *     selesai berhari-hari lalu, dan tab itu menjawab "apa yang masih perlu
 *     saya kerjakan" - jawabannya salah;
 *   - papan pengawas dan laman ruang masih menyebut sesinya berjalan.
 *
 * `ujian:tutup-kedaluwarsa` TIDAK menutup ini: ia menutup ATTEMPT, satu
 * tingkat di bawah sesi. Keduanya perlu ada, dan keduanya berbeda pertanyaan.
 *
 * =====================================================================
 * Memanggil `SesiService::tutup()`, bukan UPDATE borongan
 * =====================================================================
 * `UPDATE exam_sessions SET status = 'selesai'` akan lebih pendek dan SALAH
 * dengan cara yang sama seperti pada `TutupAttemptKedaluwarsa`: yang
 * terlewat adalah pemindahan status EVENT. `tutup()` memindahkan eventnya
 * ke Selesai begitu tidak ada lagi sesi yang belum tuntas - dan justru
 * pemindahan itulah yang dibutuhkan tab Berjalan.
 *
 * Yang tetap ditulis di sini hanya baris log, karena tidak ada pengguna yang
 * menekan tombol: jejaknya harus menyebut bahwa ini penutupan OTOMATIS,
 * supaya Admin yang membuka log tidak mencari-cari siapa yang menutupnya.
 *
 * =====================================================================
 * Kuerinya lintas sekolah
 * =====================================================================
 * Penjadwal berjalan tanpa pengguna, sehingga `SchoolResolver::id()`
 * mengembalikan null dan scope-nya sudah diam. `tanpaTenant()` tetap dipakai
 * karena perintah ini bisa dijalankan dari test yang terautentikasi, dan di
 * sana konteksnya terisi - kegagalan yang tidak berbunyi, dan yang
 * menemukannya adalah sekolah yang event-nya tidak pernah selesai.
 */
class TutupSesiKedaluwarsa extends Command
{
    protected $signature = 'ujian:tutup-sesi
                            {--limit=200 : Jumlah sesi yang ditutup dalam satu eksekusi}
                            {--kering : Laporkan saja, jangan ubah apa pun}';

    protected $description = 'Tutup sesi yang jendela waktunya sudah berakhir, beserta eventnya';

    public function handle(SesiService $service, ActivityLogger $logger): int
    {
        $batas = max(1, (int) $this->option('limit'));
        $kering = (bool) $this->option('kering');

        $total = 0;
        $gagal = 0;

        // Perulangan sampai batch-nya kosong, dengan alasan yang sama dengan
        // `TutupAttemptKedaluwarsa`: setiap baris yang diproses KELUAR dari
        // filter, sehingga batch berikutnya selalu berisi baris baru dan tidak
        // ada pergeseran offset yang melewatkan sesuatu.
        while (true) {
            $sesi = ExamSession::tanpaTenant()
                ->where('status', StatusSesi::Berjalan)
                ->where('hard_end_at', '<=', now())
                // Paling lama terlambat lebih dulu: bila tumpukannya lebih
                // besar dari `--limit`, yang paling menyesatkan di layar
                // adalah yang paling lama dibiarkan berjalan.
                ->orderBy('hard_end_at')
                ->limit($batas)
                ->get();

            if ($sesi->isEmpty()) {
                break;
            }

            foreach ($sesi as $satu) {
                if ($kering) {
                    $this->line(sprintf(
                        '  #%d  %s  berakhir %s',
                        $satu->id,
                        $satu->label(),
                        $satu->hard_end_at?->format('d M Y H:i'),
                    ));

                    continue;
                }

                try {
                    $service->tutup($satu);
                } catch (DomainException $e) {
                    // Satu sesi yang ditolak graf tidak boleh menahan yang
                    // lain - dan penolakannya DILAPORKAN, bukan ditelan:
                    // sesi yang tetap berjalan sesudah jendelanya lewat
                    // adalah keadaan yang harus terlihat.
                    $gagal++;
                    $this->warn("  #{$satu->id} dilewati: {$e->getMessage()}");

                    continue;
                }

                $logger->log('Menutup sesi otomatis', $satu->exam?->event, [
                    'sesi' => $satu->label(),
                    'alasan' => 'jendela sesi berakhir',
                ]);
            }

            $total += $sesi->count();

            if ($sesi->count() < $batas) {
                break;
            }
        }

        if ($kering) {
            $this->info($total === 0
                ? 'Tidak ada sesi yang jendelanya berakhir.'
                : "{$total} sesi akan ditutup (mode kering - tidak ada yang diubah).");

            return self::SUCCESS;
        }

        $pesan = $total === 0
            ? 'Tidak ada sesi yang jendelanya berakhir.'
            : "{$total} sesi ditutup.";

        $this->info($gagal > 0 ? "{$pesan} {$gagal} dilewati." : $pesan);

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Services\MesinUjian;
use Illuminate\Console\Command;

/**
 * Tutup attempt yang waktu ujiannya sudah habis - Fase 9.
 *
 * =====================================================================
 * Mengapa perintah ini ada, padahal penutupannya sudah otomatis
 * =====================================================================
 * `MesinUjian::tutupBilaKedaluwarsa()` sudah dipanggil di setiap pembacaan
 * attempt: denyut, muat ulang laman, dan simpan jawaban. Untuk siswa yang
 * perambaninya masih terbuka, itu cukup - dan komentar di method itu
 * menyatakan alasannya ("penjadwal yang mati akan membuat attempt kedaluwarsa
 * tetap berjalan").
 *
 * Yang tidak ditutupnya adalah siswa yang MENUTUP TABNYA. Dan justru merekalah
 * yang paling sering kehabisan waktu: anak yang menyerah, anak yang ponselnya
 * mati, anak yang pulang lebih dulu. Bagi mereka tidak ada lagi permintaan
 * yang datang, sehingga tidak ada lagi pemanggil `tutupBilaKedaluwarsa()`.
 *
 * Akibatnya terlihat di dua tempat sekaligus:
 *
 *   - papan monitoring ruang menampilkan mereka sebagai masih mengerjakan,
 *     berjam-jam sesudah ujiannya berakhir - dan pengawas yang mempercayai
 *     papan itu akan mencari anak yang sudah pulang;
 *   - `exam_results` tidak berisi barisnya, sehingga Guru di plate 07 melihat
 *     kelas yang jumlahnya kurang, tanpa cara mengetahui mengapa.
 *
 * Perintah ini adalah pemanggil yang tidak bergantung pada peramban siapa pun.
 *
 * =====================================================================
 * Kenapa MEMANGGIL ULANG methodnya, bukan UPDATE borongan
 * =====================================================================
 * Ini keputusan yang menentukan isi seluruh kelas ini.
 *
 * `UPDATE exam_attempts SET status = 'kedaluwarsa' WHERE ...` akan jauh lebih
 * pendek, dan ia SALAH. `tutupBilaKedaluwarsa()` mengerjakan tiga hal:
 *
 *   1. mengisi `status = kedaluwarsa`;
 *   2. mengisi `submitted_at = deadline_at` - waktu HABISNYA, bukan waktu
 *      perintah ini kebetulan berjalan;
 *   3. memanggil `penilai->nilai()` sehingga baris `exam_results` lahir.
 *
 * Langkah ketiga itulah yang membuat perbedaan antara "ditutup" dan "dinilai".
 * `UPDATE` borongan akan menghasilkan siswa yang ujiannya berstatus selesai
 * tetapi nilainya tidak pernah ada - dan yang kehilangan nilainya adalah
 * justru siswa yang paling tidak menyentuh sistem lagi, sehingga tidak ada
 * satu pun dari mereka yang akan mengadukannya.
 *
 * =====================================================================
 * Kuerinya lintas sekolah, dan itu dinyatakan eksplisit
 * =====================================================================
 * Penjadwal berjalan tanpa pengguna, sehingga `SchoolResolver::id()`
 * mengembalikan null - dan pada scope ini null berarti "JANGAN saring", bukan
 * "tidak ada sekolah" (lihat SchoolScope). Jadi scope-nya sebenarnya sudah
 * diam dengan sendirinya.
 *
 * Tetap `tanpaTenant()` yang dipakai, dan bukan mengandalkan kebetulan itu:
 * perintah ini bisa dijalankan dari test yang terautentikasi, dan di sana
 * konteksnya TERISI - sehingga tanpa `tanpaTenant()` ia akan diam-diam hanya
 * menutup satu sekolah. Kegagalan seperti itu tidak berbunyi, dan yang
 * menemukannya adalah sekolah yang siswanya tidak pernah mendapat nilai.
 *
 * Perintah di `app/Console/Commands/` boleh menyentuh dua domain sekaligus
 * (Attendance dan Grading, lewat `MesinUjian`), karena
 * `tests/Architecture/DomainBoundariesTest` hanya memindai `app/Domain/*`.
 * Orkestrasi lintas domain memang tempatnya di sini.
 */
class TutupAttemptKedaluwarsa extends Command
{
    protected $signature = 'ujian:tutup-kedaluwarsa
                            {--limit=500 : Jumlah attempt yang ditutup dalam satu eksekusi}
                            {--kering : Laporkan saja, jangan ubah apa pun}';

    protected $description = 'Tutup attempt yang waktu ujiannya sudah habis, beserta nilainya';

    public function handle(MesinUjian $mesin): int
    {
        $batas = max(1, (int) $this->option('limit'));
        $kering = (bool) $this->option('kering');

        $total = 0;

        // Perulangan sampai batch-nya kosong, bukan `chunkById` sekali jalan.
        // Alasannya: tiap baris yang diproses KELUAR dari filter (statusnya
        // berubah), sehingga batch berikutnya selalu berisi baris baru. Dengan
        // begitu tidak ada masalah pergeseran offset yang membuat `chunk()`
        // biasa melewatkan baris.
        //
        // `--limit` per PUTARAN, bukan untuk seluruh eksekusi: bila ada
        // tumpukan 10.000 attempt, satu putaran tetap dibatasi 500 sehingga
        // worker tidak tertahan berjam-jam, sementara putaran berikutnya
        // (satu menit kemudian) melanjutkan sisanya.
        while (true) {
            $attempt = ExamAttempt::tanpaTenant()
                ->where('status', ExamAttempt::STATUS_BERJALAN)
                ->whereNotNull('deadline_at')
                ->where('deadline_at', '<=', now())
                // Urut dari yang paling lama terlambat. Kalau tumpukannya
                // lebih besar dari `--limit`, yang ditutup lebih dulu adalah
                // yang paling lama menunggu - bukan yang kebetulan berid
                // terkecil.
                ->orderBy('deadline_at')
                ->limit($batas)
                ->get();

            if ($attempt->isEmpty()) {
                break;
            }

            foreach ($attempt as $satu) {
                if ($kering) {
                    $this->line(sprintf(
                        '  #%d  peserta %d  deadline %s',
                        $satu->id,
                        $satu->exam_participant_id,
                        $satu->deadline_at?->format('d M Y H:i'),
                    ));

                    continue;
                }

                // Method yang sama dengan yang dipanggil peramban siswa -
                // lihat catatan kepala kelas tentang mengapa bukan UPDATE.
                $mesin->tutupBilaKedaluwarsa($satu);
            }

            $total += $attempt->count();

            // Satu batch penuh berarti kemungkinan masih ada lagi; batch yang
            // tidak penuh berarti sudah habis, dan putaran berikutnya hanya
            // akan mengembalikan koleksi kosong. Berhenti lebih awal menghemat
            // satu kueri per eksekusi - pada perintah yang berjalan tiap menit,
            // itu bukan penghematan yang berarti, tetapi juga bukan kode yang
            // lebih rumit.
            if ($attempt->count() < $batas) {
                break;
            }
        }

        if ($kering) {
            $this->info($total === 0
                ? 'Tidak ada attempt yang kedaluwarsa.'
                : "{$total} attempt akan ditutup (mode kering - tidak ada yang diubah).");

            return self::SUCCESS;
        }

        $this->info($total === 0
            ? 'Tidak ada attempt yang kedaluwarsa.'
            : "{$total} attempt ditutup dan dinilai.");

        return self::SUCCESS;
    }
}

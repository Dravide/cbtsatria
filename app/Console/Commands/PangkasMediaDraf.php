<?php

namespace App\Console\Commands;

use App\Domain\Question\Models\QuestionMedia;
use App\Domain\Question\Services\MediaSoal;
use Illuminate\Console\Command;

/**
 * Pangkas media SOAL DRAF yang tak pernah jadi soal - Fase 7, diperluas.
 *
 * =====================================================================
 * Apa yang dipangkas, dan mengapa ia bisa ada
 * =====================================================================
 * Gambar pada soal dan pilihan jawaban boleh diunggah SEBELUM soalnya punya
 * id (lihat migrasi `..._make_question_id_nullable` dan MediaSoal::simpan).
 * Selama draf itu, barisnya `question_id` NULL. Begitu Guru menekan Simpan,
 * `BankSoalService::tautkanDraf()` menautkannya ke soal - di dalam transaksi
 * yang sama - sehingga draf yang berhasil TIDAK pernah menggantung.
 *
 * Yang tersisa adalah draf yang ditinggalkan: peramban ditutup, sambungan
 * putus, atau Guru membuang tab sebelum menyimpan. Berkasnya sudah terlanjur
 * duduk di disk privat, dan tanpa pemangkas ia menumpuk selamanya - satu
 * sekolah bisa menumpuk ratusan megabita gambar yang tidak pernah dipakai
 * soal mana pun, dan tidak satu pun layar menampilkannya.
 *
 * =====================================================================
 * Batas waktunya LONGGAR, dan itu disengaja
 * =====================================================================
 * Bawaan 24 jam, bukan satu jam. Builder boleh terbuka lama: Guru menyusun
 * satu paket empat puluh soal sambil mengajar di antaranya, dan draf media
 * yang berumur enam jam masih bisa sedang dipakai di tab yang lupa ditutup.
 * Yang dipangkas hanya yang benar-benar ditinggalkan, dan menunggu sehari
 * penuh nyaris tidak mengubah besarnya disk yang dibebaskan.
 *
 * `created_at` dipakai, bukan semacam "terakhir disentuh": draf tidak punya
 * siklus hidup yang lebih kaya dari itu - ia dibuat sekali, lalu entah
 * ditautkan atau ditinggalkan.
 *
 * =====================================================================
 * Baris dulu, berkas kemudian - lewat MediaSoal
 * =====================================================================
 * Urutan penghapusan yang sama dengan `MediaSoal::hapus()` dan
 * `PangkasEksporPengguna`, dan alasannya sama: baris yang sudah pergi tidak
 * akan menyisakan berkas yatim yang bisa ditemukan. Karena itu perintah ini
 * memakai `MediaSoal::hapus()`, bukan menghapus sendiri - satu pintu untuk
 * penulisan media, juga saat membuangnya.
 */
class PangkasMediaDraf extends Command
{
    protected $signature = 'soal:pangkas-media-draf
                            {--jam=24 : Umur minimum sebelum media draf dipangkas}
                            {--kering : Laporkan saja, jangan hapus apa pun}';

    protected $description = 'Hapus media draf soal (belum tertaut ke soal mana pun) yang lebih tua dari N jam';

    public function handle(MediaSoal $media): int
    {
        $jam = max(1, (int) $this->option('jam'));
        $kering = (bool) $this->option('kering');
        $batas = now()->subHours($jam);

        $draf = QuestionMedia::tanpaTenant()
            ->whereNull('question_id')
            ->where('created_at', '<=', $batas)
            ->orderBy('id')
            ->get();

        if ($draf->isEmpty()) {
            $this->info("Tidak ada media draf lebih tua dari {$jam} jam.");

            return self::SUCCESS;
        }

        $byte = (int) $draf->sum(fn (QuestionMedia $m) => (int) $m->ukuran);

        if ($kering) {
            foreach ($draf as $satu) {
                $this->line(sprintf(
                    '  #%d  %s  %s  %s',
                    $satu->id,
                    strtoupper((string) $satu->media_type),
                    $satu->storage_path,
                    $this->ukuranManusia((int) $satu->ukuran),
                ));
            }

            $this->info(sprintf(
                '%d berkas draf, %s akan dipangkas (mode kering - tidak ada yang dihapus).',
                $draf->count(),
                $this->ukuranManusia($byte),
            ));

            return self::SUCCESS;
        }

        $terhapus = 0;

        foreach ($draf as $satu) {
            $media->hapus($satu);
            $terhapus++;
        }

        $this->info(sprintf('Dipangkas: %d berkas draf, %s.', $terhapus, $this->ukuranManusia($byte)));

        return self::SUCCESS;
    }

    /** Ukuran dalam satuan yang bisa dibaca dari keluaran cron. */
    private function ukuranManusia(int $byte): string
    {
        return match (true) {
            $byte >= 1024 * 1024 => round($byte / 1024 / 1024, 1).' MB',
            $byte >= 1024 => round($byte / 1024, 1).' KB',
            default => $byte.' B',
        };
    }
}

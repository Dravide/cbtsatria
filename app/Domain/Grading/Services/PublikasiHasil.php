<?php

namespace App\Domain\Grading\Services;

use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Grading\Enums\StatusNilai;
use App\Domain\Grading\Models\ExamResult;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Publikasi hasil satu event, dan kunci koreksinya - PRD §13.5, plate 21.
 *
 * =====================================================================
 * Satu aksi untuk seluruh event
 * =====================================================================
 * Plate 21: "Publikasi adalah satu aksi untuk seluruh event, bukan per
 * ujian." Karena itu method di sini menerima `SchoolEvent`, bukan `Exam`,
 * dan `exams.publish_results` tidak ada lagi - lihat catatan kepala `Exam`.
 *
 * Yang dikerjakan `terbitkan()`:
 *
 *   1. Tolak bila masih ada uraian belum dikoreksi. Kalimat blueprint §1276
 *      kata per kata: "tombol publikasi tidak dapat menandai hasil yang
 *      masih menunggu koreksi".
 *   2. Isi `exam_results.published_at` untuk seluruh hasil event ini.
 *   3. Isi `school_events.results_published_at` + `results_published_by`.
 *   4. Kunci koreksi (`gradings_locked = true`).
 *
 * =====================================================================
 * Langkah 1 adalah pemeriksaan, bukan saran
 * =====================================================================
 * Guru yang menekan publikasi ketika 12 uraian belum dikoreksi akan
 * menerbitkan nilai yang lebih rendah daripada yang sebenarnya - dan siswa
 * yang melihatnya tidak akan tahu bahwa angkanya belum final. Menolaknya di
 * sini berarti tombol yang mati di UI punya penegaknya sendiri, sehingga
 * permintaan langsung pun tidak bisa melewatinya.
 *
 * =====================================================================
 * Membuka koreksi TIDAK mencabut publikasi
 * =====================================================================
 * Blueprint keputusan #10. `bukaKoreksi()` hanya menyetel `gradings_locked`
 * ke false; `results_published_at` dan `exam_results.published_at` tidak
 * disentuh. Hasil yang sudah dibaca 300 siswa tetap terbaca, dan yang
 * berubah adalah Guru bisa memperbaiki poinnya.
 *
 * Alasannya wajib, dan ia masuk `activity_logs` - bukan ke kolom di tabel
 * hasil. Yang bertanya "kenapa nilai anak saya berubah setelah terbit"
 * adalah pertanyaan tentang SATU event, bukan tentang satu baris hasil.
 */
class PublikasiHasil
{
    /** Panjang maksimal alasan yang dicatat. */
    public const BATAS_ALASAN = 1000;

    public function __construct(private readonly ActivityLogger $log) {}

    /**
     * Terbitkan seluruh hasil satu event.
     *
     * @throws ValidationException Bila masih ada uraian belum dikoreksi.
     */
    public function terbitkan(SchoolEvent $event, User $admin): SchoolEvent
    {
        $sisa = $this->sisaKoreksi($event);

        if ($sisa > 0) {
            throw ValidationException::withMessages([
                'publikasi' => "Masih ada {$sisa} jawaban uraian yang belum dikoreksi. "
                    .'Publikasi dikunci sampai koreksinya selesai.',
            ]);
        }

        $sekarang = now();

        DB::transaction(function () use ($event, $admin, $sekarang) {
            // Hanya hasil yang sudah FINAL yang diterbitkan. Hasil yang
            // belum final tidak mungkin ada di sini - pemeriksaan di atas
            // sudah menolaknya - tetapi klausa statusnya tetap ditulis:
            // baris yang lahir di antara pemeriksaan dan transaksi tidak
            // boleh terbit tanpa melewati aturannya.
            ExamResult::query()
                ->whereIn('exam_attempt_id', $this->attemptEvent($event))
                ->whereIn('grading_status', [StatusNilai::DinilaiPenuh->value, StatusNilai::Terbit->value])
                ->update([
                    'published_at' => $sekarang,
                    'published_by' => $admin->id,
                ]);

            $event->forceFill([
                'results_published_at' => $sekarang,
                'results_published_by' => $admin->id,
                'gradings_locked' => true,
            ])->save();
        });

        $this->log->log('hasil_dipublikasikan', $event, [
            'hasil' => ExamResult::query()
                ->whereIn('exam_attempt_id', $this->attemptEvent($event))
                ->count(),
        ]);

        return $event->refresh();
    }

    /**
     * Tarik publikasi - hasil kembali tidak terlihat siswa.
     *
     * Ada, dan sengaja tetap ada meski plate 21 tidak menggambarnya sebagai
     * tombol utama: salah menerbitkan adalah kesalahan yang terjadi, dan
     * satu-satunya alternatif tanpa method ini adalah Admin mengubah basis
     * data langsung. Yang WAJIB berubah bersama publikasinya adalah kunci
     * koreksi - hasil yang tidak terbit tidak punya alasan untuk tetap
     * terkunci.
     */
    public function tarik(SchoolEvent $event, User $admin, string $alasan): SchoolEvent
    {
        $alasan = $this->alasanWajib($alasan);

        DB::transaction(function () use ($event, $alasan) {
            ExamResult::query()
                ->whereIn('exam_attempt_id', $this->attemptEvent($event))
                ->whereNotNull('published_at')
                ->update([
                    'published_at' => null,
                    'published_by' => null,
                ]);

            $event->forceFill([
                'results_published_at' => null,
                'results_published_by' => null,
                'results_announced_at' => null,
                'gradings_locked' => false,
            ])->save();

            $this->log->log('hasil_ditarik', $event, ['alasan' => $alasan]);
        });

        return $event->refresh();
    }

    /**
     * Buka kembali koreksi TANPA menarik publikasinya.
     *
     * Blueprint keputusan #10. Hasil yang sudah dibaca siswa tetap terbaca -
     * guru memperbaiki satu poin, nilai hasilnya berubah saat dihitung ulang,
     * dan publikasinya tidak pernah hilang dari layar.
     *
     * Alasannya dicatat di `activity_logs`. Itu bukan formalitas: yang
     * membuka kunci karena salah input dan yang membukanya karena kunci
     * soal benar-benar keliru menghasilkan nilai yang sama-sama berubah, dan
     * hanya catatannya yang bisa membedakan keduanya sesudah itu.
     */
    public function bukaKoreksi(SchoolEvent $event, User $admin, string $alasan): SchoolEvent
    {
        $alasan = $this->alasanWajib($alasan);

        $event->forceFill(['gradings_locked' => false])->save();

        $this->log->log('koreksi_dibuka', $event, [
            'alasan' => $alasan,
            'hasil_terbit' => $event->hasilTerbit(),
        ]);

        return $event->refresh();
    }

    /**
     * Kunci ulang koreksi.
     *
     * Dipakai sesudah Guru selesai memperbaiki. Terpisah dari `terbitkan()`
     * karena kunci yang HANYA bisa ditegakkan lewat publikasi berarti Admin
     * harus menerbitkan ulang event yang sudah terbit untuk menutup kembali
     * koreksinya - dan publikasi kedua tidak boleh mengubah `published_at`
     * yang sudah dibaca siswa.
     */
    public function kunciKoreksi(SchoolEvent $event, User $admin): SchoolEvent
    {
        $event->forceFill(['gradings_locked' => true])->save();

        $this->log->log('koreksi_dikunci', $event);

        return $event->refresh();
    }

    /**
     * Kirim pengumuman ke siswa - tombol terpisah di plate 21.
     *
     * Berbeda dari publikasi, dan perbedaannya bukan pilihan desain: hasil
     * bisa sudah dibuka sementara pengumumannya ditahan sampai rapat guru
     * selesai. Tanpa kolom yang terpisah, tombol ini tidak punya apa pun
     * untuk dikerjakan.
     */
    public function umumkan(SchoolEvent $event, User $admin): SchoolEvent
    {
        if (! $event->hasilTerbit()) {
            throw ValidationException::withMessages([
                'pengumuman' => 'Hasilnya belum dipublikasikan. Terbitkan dulu sebelum mengirim pengumuman.',
            ]);
        }

        $event->forceFill(['results_announced_at' => now()])->save();

        $this->log->log('hasil_diumumkan', $event);

        return $event->refresh();
    }

    /**
     * Simpan dua kotak centang tampilan siswa di plate 21.
     *
     * `show_objectives` dan `show_explanations`. Yang kedua bawaannya false
     * dan itu disengaja - lihat catatan migrasinya: pembahasan pilihan ganda
     * sama dengan kuncinya ditambah alasannya.
     */
    public function aturTampilan(SchoolEvent $event, bool $tampilkanObjektif, bool $tampilkanPembahasan): SchoolEvent
    {
        $event->forceFill([
            'show_objectives' => $tampilkanObjektif,
            'show_explanations' => $tampilkanPembahasan,
        ])->save();

        return $event->refresh();
    }

    /**
     * Jumlah uraian yang belum dikoreksi di seluruh event ini.
     *
     * Dibaca dari `exam_results.pending_essays` - angka yang dipelihara
     * `PenghitungHasil`, bukan dihitung ulang di sini. Satu fakta, satu
     * tempat: kalau keduanya menghitung sendiri, tombol publikasi dan daftar
     * "perlu koreksi" bisa berbeda pendapat tentang event yang sama.
     */
    public function sisaKoreksi(SchoolEvent $event): int
    {
        return (int) ExamResult::query()
            ->whereIn('exam_attempt_id', $this->attemptEvent($event))
            ->sum('pending_essays');
    }

    /** @return Builder<ExamAttempt> */
    private function attemptEvent(SchoolEvent $event): Builder
    {
        return ExamAttempt::query()
            ->whereHas('penempatan.exam', fn ($q) => $q->where('event_id', $event->id))
            ->select('id');
    }

    /** Alasan yang wajib diisi untuk aksi yang mengubah nilai yang sudah terbit. */
    private function alasanWajib(string $alasan): string
    {
        $alasan = trim($alasan);

        if ($alasan === '') {
            throw ValidationException::withMessages([
                'alasan' => 'Alasan wajib diisi; ia dicatat di log aktivitas.',
            ]);
        }

        return mb_substr($alasan, 0, self::BATAS_ALASAN, 'UTF-8');
    }
}

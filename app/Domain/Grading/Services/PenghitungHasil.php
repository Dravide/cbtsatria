<?php

namespace App\Domain\Grading\Services;

use App\Domain\Attendance\Models\AttemptQuestion;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Grading\Enums\StatusNilai;
use App\Domain\Grading\Models\EssayGrading;
use App\Domain\Grading\Models\ExamResult;
use App\Domain\Question\Enums\JenisSoal;
use Illuminate\Support\Facades\DB;

/**
 * Menghitung nilai akhir satu attempt - PRD §13.4.
 *
 * =====================================================================
 * Rumusnya dari PRD, kata per kata
 * =====================================================================
 *
 *     Nilai Akhir = (Total Skor Diperoleh / Total Skor Maksimal) x 100
 *
 * "Total Skor Diperoleh" mencakup objektif DAN uraian. "Total Skor
 * Maksimal" adalah jumlah bobot seluruh butir - termasuk bobot butir uraian
 * yang belum dikoreksi, karena bobot maksimalnya tidak berkurang hanya
 * karena belum ada yang mengoreksinya.
 *
 * Konsekuensi yang perlu disadari dan memang diinginkan: selama uraian
 * belum dikoreksi, nilai akhirnya RENDAH karena pembilangnya belum terisi.
 * Itulah sebabnya hasil seperti itu tidak boleh dipublikasikan - bukan
 * karena angkanya salah, melainkan karena angkanya benar untuk pekerjaan
 * yang belum selesai.
 *
 * =====================================================================
 * Tiga kolom skor, bukan satu
 * =====================================================================
 * `automatic_score`, `essay_score`, dan `total_score` dipisah karena plate
 * 07 menampilkan "Obj." dan "Uraian" sebagai dua kolom sebelum dijumlahkan.
 * Guru perlu melihat dari mana angka akhirnya datang; kolom tunggal
 * menyembunyikan bahwa separuh kelas belum dikoreksi.
 *
 * =====================================================================
 * `pending_essays` dihitung di sini, dan itu satu-satunya jalur
 * =====================================================================
 * Plate 21 menonaktifkan tombol publikasi selama angka ini bukan nol. Kalau
 * ia dihitung di tempat lain juga, dua tempat itu akan berbeda, dan yang
 * berbeda adalah tombol yang mati padahal seharusnya hidup - atau sebaliknya,
 * yang jauh lebih buruk.
 *
 * `PenilaiUraian` memanggil `hitungUlang()` sesudah setiap koreksi, sehingga
 * angka ini bergerak bersama kenyataannya tanpa ada yang perlu mengingatnya.
 */
class PenghitungHasil
{
    public function __construct(
        private readonly PenilaiOtomatis $otomatis,
    ) {}

    /**
     * Hitung (atau hitung ulang) hasil satu attempt.
     *
     * Aman dipanggil berulang. Attempt yang masih BERJALAN ditolak dengan
     * null - nilainya belum final, dan menghitungnya sekarang berarti
     * menulis angka yang pasti berubah.
     */
    public function hitungUlang(ExamAttempt $attempt): ?ExamResult
    {
        if ($attempt->status === ExamAttempt::STATUS_BERJALAN) {
            return null;
        }

        // Attempt yang BATAL tidak punya nilai: pesertanya dikeluarkan atau
        // ujiannya dibatalkan, dan baris hasil untuk keadaan itu akan muncul
        // di laporan sebagai "0" - bukan sebagai "tidak berlaku".
        if ($attempt->status === ExamAttempt::STATUS_BATAL) {
            return null;
        }

        return DB::transaction(fn () => $this->hitung($attempt));
    }

    private function hitung(ExamAttempt $attempt): ExamResult
    {
        $butir = AttemptQuestion::query()
            ->where('exam_attempt_id', $attempt->id)
            ->with(['soal', 'jawaban'])
            ->get();

        // Koreksi uraian dibaca lewat SATU kueri, bukan relasi pada
        // StudentAnswer. Relasinya tidak boleh hidup di sana: `StudentAnswer`
        // milik Attendance, dan Attendance tidak pernah mengenal Grading -
        // kalau `koreksi()` ditambahkan ke modelnya, Attendance mengimpor
        // model Grading dan arah modulnya berbalik.
        $koreksi = EssayGrading::query()
            ->whereIn('student_answer_id', $butir->pluck('jawaban.id')->filter()->all())
            ->get()
            ->keyBy('student_answer_id');

        $auto = 0.0;
        $uraian = 0.0;
        $maksimal = 0.0;
        $totalUraian = 0;
        $sisaUraian = 0;

        foreach ($butir as $satu) {
            if ($satu->soal === null) {
                continue;
            }

            $bobot = $satu->soal->bobotBerlaku();
            $maksimal += $bobot;

            if ($satu->soal->jenis === JenisSoal::Uraian) {
                $totalUraian++;

                // URAIAN YANG TIDAK DIJAWAB BUKAN PEKERJAAN YANG MENUNGGU.
                //
                // Siswa boleh mengumpulkan dengan uraian kosong, dan saat itu
                // `student_answers` TIDAK punya barisnya sama sekali -
                // `JawabanService` hanya membuat baris ketika ada yang
                // disimpan. Baris yang tidak ada berarti antrean koreksi
                // (plate 06) juga tidak bisa menampilkannya: Guru tidak
                // melihat apa pun untuk dikoreksi.
                //
                // Sebelum cabang ini ada, keadaan itu menghitung
                // `sisaUraian`, sehingga `pending_essays` tidak pernah
                // mencapai nol, dan tombol publikasi di plate 21 MATI
                // SELAMANYA untuk event itu - dengan pesan "masih ada N
                // jawaban uraian yang belum dikoreksi" padahal N itu tidak
                // ada di antrean siapa pun. Jalan keluarnya hanya mengoreksi
                // baris yang tidak bisa dibuka.
                //
                // Jawaban kosong sama dengan pilihan ganda yang tidak
                // dipilih: nilainya nol, dan penilaiannya SUDAH selesai.
                // Yang menunggu koreksi hanyalah uraian yang benar-benar
                // ditulis siswa tetapi belum dibaca Guru - dan itulah arti
                // "menunggu" yang dipakai tombol publikasi.
                if ($satu->jawaban === null || ! $satu->jawaban->terjawab()) {
                    continue;
                }

                $nilaiKoreksi = $koreksi->get($satu->jawaban->id);

                if ($nilaiKoreksi === null) {
                    // Belum dikoreksi. Bobotnya TETAP masuk penyebut - lihat
                    // catatan kepala kelas - sehingga nilainya sementara
                    // lebih rendah, bukan dilebih-lebihkan.
                    $sisaUraian++;

                    continue;
                }

                $uraian += (float) $nilaiKoreksi->score;

                continue;
            }

            $auto += (float) ($satu->jawaban?->score_awarded ?? 0);
        }

        $total = $auto + $uraian;

        // PRD §13.4. Pembagian nol mungkin: ujian yang snapshotnya kosong
        // (tidak seharusnya terjadi, tetapi `MesinUjian` menolak memulai
        // ujian tanpa butir - jadi ini cadangan, bukan jalur biasa).
        $persen = $maksimal > 0 ? round($total / $maksimal * 100, 2) : 0.0;

        // Terbit MENANG: publikasi tidak dicabut saat koreksi dibuka kembali.
        $terbit = (bool) $attempt->penempatan?->exam?->event?->hasilTerbit();

        $status = StatusNilai::dari($sisaUraian, $totalUraian, $terbit);

        $hasil = ExamResult::query()
            ->where('exam_attempt_id', $attempt->id)
            ->first() ?? new ExamResult;

        $hasil->forceFill([
            'school_id' => $attempt->school_id,
            'exam_attempt_id' => $attempt->id,
            'automatic_score' => round($auto, 2),
            'essay_score' => round($uraian, 2),
            'total_score' => round($total, 2),
            'maximum_score' => round($maksimal, 2),
            'final_percentage' => $persen,
            'grading_status' => $status,
            'pending_essays' => $sisaUraian,
            // `published_at` TIDAK ditulis di sini - publikasi adalah aksi
            // Admin lewat `PublikasiHasil`, dan hasil yang menghitung ulang
            // dirinya sendiri tidak berhak menerbitkan apa pun.
            'published_at' => $terbit ? $hasil->published_at : null,
            'dihitung_pada' => now(),
        ])->save();

        return $hasil;
    }

    /**
     * Hitung ulang seluruh attempt dalam satu ujian.
     *
     * Dipakai setelah Admin memperbaiki kunci soal (plate 15) dan oleh
     * tombol "Hitung ulang" di plate 07. Mengembalikan jumlah yang dihitung
     * supaya pemanggilnya bisa melaporkannya, bukan menebak.
     */
    public function hitungUlangUjian(int $examId): int
    {
        $attempt = ExamAttempt::query()
            ->whereIn('status', [
                ExamAttempt::STATUS_DIKIRIM,
                ExamAttempt::STATUS_KEDALUWARSA,
                ExamAttempt::STATUS_DIKUNCI,
            ])
            ->whereHas('penempatan', fn ($q) => $q->where('exam_id', $examId))
            ->get();

        $jumlah = 0;

        foreach ($attempt as $satu) {
            // Objektif dinilai ulang lebih dulu: kunci soal mungkin berubah,
            // dan hasil yang dihitung dari `score_awarded` yang basi adalah
            // hasil yang membantah kunci barunya.
            $this->otomatis->nilaiAttempt($satu);

            if ($this->hitungUlang($satu) !== null) {
                $jumlah++;
            }
        }

        return $jumlah;
    }
}

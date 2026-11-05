<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Models\AttemptQuestion;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Models\StudentAnswer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Penyimpanan jawaban siswa - PRD §11.4, plate 22 & 26.
 *
 * ---------------------------------------------------------------------
 * `version` naik DI DALAM transaksi, dan itu satu-satunya tempat ia naik
 * ---------------------------------------------------------------------
 * PRD §11.4 menuntut optimistic concurrency. Yang membuatnya bekerja bukan
 * keberadaan kolomnya, melainkan bahwa pembandingannya terjadi di dalam
 * transaksi dengan `lockForUpdate()` - sehingga dua permintaan yang tiba
 * bersamaan benar-benar berbaris, bukan sekadar saling memeriksa nilai yang
 * keduanya sudah baca sebelum salah satunya menulis.
 *
 * Bentuk kegagalannya sengaja 409, bukan 422: yang salah bukan isi
 * jawabannya, melainkan bahwa klien memegang salinan yang sudah basi. Layar
 * siswa yang menerimanya memuat ulang butirnya dan mencoba sekali lagi -
 * dan jawaban yang lebih baru menang, bukan yang lebih belakangan dikirim.
 *
 * ---------------------------------------------------------------------
 * Menyimpan tidak pernah menolak karena attempt sudah ditutup - ia diam
 * ---------------------------------------------------------------------
 * Autosave dari halaman yang sudah lama terbuka tiba SESUDAH siswa menekan
 * Kumpulkan, dan itu kejadian biasa: peramban mengirim simpanan terakhir
 * sambil menunggu tanggapan. Menjawabnya dengan galat akan membuat siswa
 * yang sudah selesai melihat pesan gagal - tepat pada detik ia menekan
 * tombol yang mengakhirinya.
 *
 * Yang dilakukan justru sebaliknya: simpanan itu DITOLAK DIAM-DIAM, dan
 * jawabannya tetap seperti sebelum ujian ditutup. Tidak ada jawaban yang
 * berubah sesudah waktu habis.
 *
 * ---------------------------------------------------------------------
 * Mengapa `ditandai` ditulis di sini, bukan di output yang terpisah
 * ---------------------------------------------------------------------
 * Ragu adalah sifat jawaban - lihat docblock StudentAnswer. Menandai butir
 * yang belum dijawab tetap harus bisa, dan itu berarti barisnya dibuat
 * dengan `answer_json` null. Memisahkannya menjadi endpoint sendiri berarti
 * dua penulis untuk satu baris yang sama, dan keduanya bisa saling menimpa
 * versi.
 */
class JawabanService
{
    /**
     * Simpan jawaban satu butir.
     *
     * @param  array<string, mixed>  $isi  Bentuknya mengikuti jenis soal;
     *                                     kosong berarti "dikosongkan lagi".
     * @param  int|null  $versi  Versi yang dipegang klien; null berarti klien
     *                           belum pernah membaca barisnya.
     */
    public function simpan(
        ExamAttempt $attempt,
        AttemptQuestion $butir,
        array $isi,
        ?int $versi = null,
        ?bool $ditandai = null,
    ): StudentAnswer {
        $this->pastikanMilikAttempt($attempt, $butir);

        return DB::transaction(function () use ($attempt, $butir, $isi, $versi, $ditandai) {
            $baris = StudentAnswer::query()
                ->where('attempt_question_id', $butir->id)
                ->lockForUpdate()
                ->first();

            // Attempt yang sudah ditutup: disimpan diam-diam sebagai "tidak
            // berubah". Lihat catatan kelas ini.
            if ($attempt->status !== ExamAttempt::STATUS_BERJALAN) {
                return $baris ?? new StudentAnswer([
                    'school_id' => $attempt->school_id,
                    'attempt_question_id' => $butir->id,
                    'version' => 0,
                    'answer_json' => null,
                ]);
            }

            if ($baris === null) {
                // Klien yang mengirim versi padahal belum ada barisnya
                // memegang salinan dari baris yang sudah dihapus - keadaan
                // yang tidak bisa terjadi lewat UI, dan karenanya ditolak
                // daripada ditebak.
                if ($versi !== null && $versi !== 0) {
                    $this->bentrok();
                }

                return StudentAnswer::create([
                    'school_id' => $attempt->school_id,
                    'attempt_question_id' => $butir->id,
                    'answer_json' => $this->bersihkan($isi),
                    'saved_at' => now(),
                    'version' => 1,
                    'ditandai' => $ditandai ?? false,
                    // is_correct dan score_awarded sengaja TIDAK diisi:
                    // null berarti "belum dinilai", dan penilaian otomatis
                    // adalah Fase 8. Menulisnya sekarang berarti menjawab
                    // pertanyaan yang belum boleh dijawab.
                ]);
            }

            if ($versi !== null && $versi !== $baris->version) {
                $this->bentrok();
            }

            $baris->forceFill([
                'answer_json' => $this->bersihkan($isi),
                'saved_at' => now(),
                'version' => $baris->version + 1,
                'ditandai' => $ditandai ?? $baris->ditandai,
            ])->save();

            return $baris;
        });
    }

    /**
     * Tandai atau lepas tanda ragu pada satu butir.
     *
     * Hanya mengubah `ditandai`, dan TIDAK menaikkan versi - karena itu
     * memang disengaja: tanda ragu bukan jawaban, dan menaikkan versinya
     * berarti tab lain yang sedang menyunting jawaban butir itu akan
     * ditolak hanya karena siswa menekan "Ragu" di tab pertama.
     */
    public function tandai(ExamAttempt $attempt, AttemptQuestion $butir, bool $ditandai): StudentAnswer
    {
        $this->pastikanMilikAttempt($attempt, $butir);

        return DB::transaction(function () use ($attempt, $butir, $ditandai) {
            $baris = StudentAnswer::query()
                ->where('attempt_question_id', $butir->id)
                ->lockForUpdate()
                ->first();

            if ($baris === null) {
                return StudentAnswer::create([
                    'school_id' => $attempt->school_id,
                    'attempt_question_id' => $butir->id,
                    'answer_json' => null,
                    'saved_at' => now(),
                    'version' => 1,
                    'ditandai' => $ditandai,
                ]);
            }

            $baris->forceFill(['ditandai' => $ditandai])->save();

            return $baris;
        });
    }

    /**
     * Hitungan progres satu attempt: terjawab, kosong, ditandai.
     *
     * Dibaca tiga tempat, dan itu alasan ia ada di sini: penghitung
     * "11 / 40 terjawab" di plate 22, ringkasan plate 26, dan kolom Progres
     * papan monitoring pengawas. Tiga tempat yang menghitung sendiri adalah
     * tiga angka yang bisa berbeda untuk keadaan yang sama.
     *
     * @return array{total: int, terjawab: int, kosong: int, ditandai: int, ringkas: string}
     */
    public function progres(ExamAttempt $attempt): array
    {
        $baris = StudentAnswer::query()
            ->whereIn('attempt_question_id', function ($q) use ($attempt) {
                $q->select('id')
                    ->from('attempt_questions')
                    ->where('exam_attempt_id', $attempt->id);
            })
            ->get();

        $total = AttemptQuestion::query()
            ->where('exam_attempt_id', $attempt->id)
            ->count();

        $terjawab = $baris->filter(fn (StudentAnswer $b) => $b->terjawab())->count();

        return [
            'total' => $total,
            'terjawab' => $terjawab,
            'kosong' => max(0, $total - $terjawab),
            'ditandai' => $baris->where('ditandai', true)->count(),
            'ringkas' => $terjawab.' / '.$total.' terjawab',
        ];
    }

    /**
     * Butir milik attempt ini, atau menyerah.
     *
     * Diperiksa di sini, bukan di controller, karena satu attempt bisa
     * dikirim lewat dua endpoint (simpan dan tandai) dan keduanya memikul
     * pemeriksaan yang sama. Tanpa itu, siswa bisa menuliskan jawaban ke
     * butir milik attempt ORANG LAIN dengan menebak id-nya - dan yang
     * tertimpa adalah jawaban anak yang tidak pernah membuka laman itu.
     */
    private function pastikanMilikAttempt(ExamAttempt $attempt, AttemptQuestion $butir): void
    {
        if ((int) $butir->exam_attempt_id !== (int) $attempt->id) {
            abort(404);
        }
    }

    /**
     * Bentuk yang disimpan: array, dan sel kosong dibuang.
     *
     * `{"opsi":[]}` dan `null` sama-sama berarti "belum dijawab" - lihat
     * `StudentAnswer::terjawab()`. Mengosongkan pilihan lalu mengirim
     * `{"opsi":[]}` harus tersimpan sebagai "tidak menjawab", bukan sebagai
     * jawaban berisi daftar kosong yang dihitung terjawab oleh siapa pun
     * yang memakai `filled()`.
     *
     * @param  array<string, mixed>  $isi
     * @return array<string, mixed>|null
     */
    private function bersihkan(array $isi): ?array
    {
        $bersih = [];

        foreach ($isi as $kunci => $nilai) {
            if (is_array($nilai)) {
                if ($nilai !== []) {
                    $bersih[$kunci] = $nilai;
                }

                continue;
            }

            if ($nilai !== null && $nilai !== '') {
                $bersih[$kunci] = $nilai;
            }
        }

        return $bersih === [] ? null : $bersih;
    }

    /**
     * Bentrokan versi - 409, bukan 422.
     *
     * Kode statusnya disengaja: layar siswa yang menerima 422 akan
     * menampilkan pesan di bawah kotak jawaban seolah isinya salah. Yang
     * terjadi bukan itu - isinya baik-baik saja, ia hanya datang dari
     * salinan yang sudah basi.
     */
    private function bentrok(): never
    {
        throw ValidationException::withMessages([
            'version' => 'Jawaban ini sudah tersimpan dari tempat lain. Muat ulang, lalu simpan lagi.',
        ])->status(409);
    }
}

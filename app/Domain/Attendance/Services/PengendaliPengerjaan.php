<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Contracts\PenghapusHasil;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Models\ExamViolation;
use App\Domain\Attendance\Support\TanggaPelanggaran;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Dua tindakan Admin atas satu pengerjaan: reset dan blokir manual.
 *
 * ---------------------------------------------------------------------
 * Reset TIDAK menghapus attempt lama, ia membatalkannya
 * ---------------------------------------------------------------------
 * `exam_attempts.status` sudah punya `batal` sejak Fase 6, dan
 * `KelayakanUjian::periksaAttempt()` menghitung batas kesempatan HANYA dari
 * `dikirim` dan `kedaluwarsa`. Artinya attempt yang dibatalkan tidak memakai
 * satu kesempatan pun - dan itu tepat yang dibutuhkan "ulangi dari awal":
 * anak memulai attempt berikutnya lewat gerbang biasa, dengan urutan soal
 * dan deadline yang baru, tanpa perlu menaikkan `max_attempts` milik ujian.
 *
 * Yang dihapus hanya baris `exam_results`-nya, lewat kontrak `PenghapusHasil`
 * (dijawab modul Grading). Baris itu nilai atas pengerjaan yang menurut
 * sistem tidak berlaku, dan membiarkannya berarti laman "Nilai saya" bisa
 * menampilkan nilai satu pengerjaan yang sudah dibatalkan.
 *
 * Jawaban, butir, dan koreksinya SENGAJA dibiarkan: attempt yang dibatalkan
 * tetap attempt yang pernah terjadi, dan Admin yang mereset karena kesalahan
 * teknis justru sedang membutuhkan riwayat itu.
 *
 * ---------------------------------------------------------------------
 * Blokir manual menulis baris pelanggaran, dan pesannya milik Admin
 * ---------------------------------------------------------------------
 * `lock_reason` adalah kolom yang dibaca siswa di plate 25, dan mengisinya
 * dengan kalimat Admin sendiri adalah seluruh mekanismenya. Yang ditambahkan
 * di sini hanya baris `exam_violations` bertipe `diblokir_admin` - supaya
 * riwayat pelanggaran tetap menjawab "apa yang terjadi pada attempt ini",
 * dan supaya penguncian oleh ORANG tidak tertukar dengan penguncian oleh
 * tangga pelanggaran.
 *
 * Jenisnya ada di `ExamViolation::JENIS_PENGAWAS` dan bertingkat 0, sehingga
 * ia tidak menambah `violation_count` dan tidak pernah terbaca sebagai
 * pelanggaran siswa - alasannya sama dengan `kunci_dibuka`.
 */
class PengendaliPengerjaan
{
    public function __construct(private readonly PenghapusHasil $penghapus) {}

    /**
     * Batalkan satu pengerjaan supaya pesertanya mengulang dari awal.
     *
     * @return int Nomor attempt yang dibatalkan.
     */
    public function reset(ExamAttempt $attempt, string $pelaku, string $alasan): int
    {
        if ($attempt->status === ExamAttempt::STATUS_BATAL) {
            throw new RuntimeException('Pengerjaan ini sudah dibatalkan sebelumnya.');
        }

        return DB::transaction(function () use ($attempt, $pelaku, $alasan) {
            $terkunci = ExamAttempt::query()
                ->whereKey($attempt->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $nomor = (int) $terkunci->attempt_number;

            $terkunci->forceFill([
                'status' => ExamAttempt::STATUS_BATAL,
                // Kunci dilepas bersama pembatalan: attempt yang batal tidak
                // lagi menunggu siapa pun, dan `lock_reason` yang tertinggal
                // akan membuat baris ini terbaca "dikunci" di papan mana pun
                // yang memeriksa kolomnya tanpa memeriksa status.
                'lock_reason' => null,
                'locked_at' => null,
            ])->save();

            // Nilai atas pengerjaan yang dibatalkan bukan nilai. Dihapus
            // SESUDAH statusnya tersimpan, karena `PenghitungHasil` menolak
            // menghitung attempt yang bukan lagi berjalan - urutan sebaliknya
            // akan membiarkan satu baris lahir kembali tepat sesudah dihapus.
            $this->penghapus->hapus($terkunci);

            $this->catatTindakan($terkunci, 'diblokir_admin', $pelaku, [
                'tindakan' => 'reset',
                'alasan' => $alasan,
            ]);

            return $nomor;
        });
    }

    /**
     * Hentikan satu pengerjaan dengan pesan yang ditulis Admin.
     *
     * Pesannya sampai ke layar siswa lewat `lock_reason` - lihat
     * `TerkunciSiswaController`.
     */
    public function blokir(ExamAttempt $attempt, string $pesan, string $pelaku, ?int $pelakuId): void
    {
        if ($attempt->status !== ExamAttempt::STATUS_BERJALAN) {
            throw new RuntimeException('Hanya pengerjaan yang sedang berjalan dapat dihentikan.');
        }

        DB::transaction(function () use ($attempt, $pesan, $pelaku, $pelakuId) {
            $terkunci = ExamAttempt::query()
                ->whereKey($attempt->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $terkunci->forceFill([
                'status' => ExamAttempt::STATUS_DIKUNCI,
                'lock_reason' => $pesan,
                'locked_at' => now(),
            ])->save();

            $this->catatTindakan($terkunci, 'diblokir_admin', $pelaku, [
                'pesan' => $pesan,
            ], $pelakuId);
        });
    }

    /**
     * Baris riwayat untuk tindakan orang - append-only, tingkat 0.
     *
     * `resolved_at` diisi karena tindakannya SUDAH terjadi: yang membacanya
     * di riwayat tidak perlu menindaklanjuti apa pun, dan baris yang terbuka
     * akan muncul sebagai insiden yang menunggu.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function catatTindakan(
        ExamAttempt $attempt,
        string $jenis,
        string $pelaku,
        array $metadata,
        ?int $pelakuId = null,
    ): ExamViolation {
        $sekarang = now();

        return ExamViolation::create([
            'school_id' => $attempt->school_id,
            'exam_attempt_id' => $attempt->id,
            'exam_participant_id' => $attempt->exam_participant_id,
            'violation_type' => $jenis,
            'severity' => TanggaPelanggaran::tingkat($jenis),
            'occurred_at' => $sekarang,
            'duration_ms' => null,
            'metadata_json' => ['oleh' => $pelaku, ...$metadata],
            // Bukan laporan peramban: yang menuliskannya Admin.
            'reported_by_client' => false,
            'resolved_by' => $pelakuId,
            'resolved_at' => $sekarang,
            'created_at' => $sekarang,
        ]);
    }
}

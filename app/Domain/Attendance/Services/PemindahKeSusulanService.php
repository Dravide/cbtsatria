<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Contracts\PemindahKeSusulan;
use App\Domain\Attendance\Enums\StatusPresensi;
use App\Domain\Attendance\Models\AttendanceLog;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSession;
use DomainException;

/**
 * Reset presensi saat peserta dipindah ke sesi susulan - PRD §10.7.
 *
 * ---------------------------------------------------------------------
 * Mengapa `lupakan()` tidak dipakai, dan itu inti masalahnya
 * ---------------------------------------------------------------------
 * Jalur pemindahan yang biasa (`PenempatanService::lepas()`) memanggil
 * `PencatatKehadiran::lupakan()`, yang MENOLAK menghapus baris yang sudah
 * diverifikasi. Pada susulan, baris yang diverifikasi itulah yang paling
 * sering ada: siswa ditandai `tidak_hadir` di sesi utama oleh pengawas, dan
 * justru itulah alasan ia berhak susulan. Kalau `lupakan()` dipakai:
 *
 *   - baris `tidak_hadir` tetap berdiri, dan
 *   - `pastikanUntuk()` memakai `firstOrCreate`, sehingga tidak membuat baris
 *     baru saat peserta ditempatkan ke ruang susulan,
 *
 * dan pengawas susulan melihat siswa "tidak hadir (terverifikasi)" untuk
 * ujian yang belum ia kerjakan. `KelayakanUjian` menolak gerbangnya.
 *
 * ---------------------------------------------------------------------
 * Yang benar: koreksi administratif, bukan penghapusan
 * ---------------------------------------------------------------------
 * Baris yang sudah diverifikasi adalah catatan tentang apa yang terjadi, dan
 * catatan itu tidak dihapus - ia DIBUAT BARU di `attendance_logs` (status
 * lama -> pending, dengan alasan), lalu status efektifnya dikembalikan ke
 * `pending` dengan verifikasinya dikosongkan. Itulah bentuk yang diminta PRD
 * §10.7 untuk koreksi sesudah attempt: alasan tertulis, pelaku, dan jejak
 * yang tidak hilang.
 *
 * `pelaku_label` diisi "Sistem (susulan)": koreksi ini dipicu Admin lewat
 * pemindahan, bukan oleh pengawas ruang yang berdiri di depan kursi. Alasannya
 * tetap tercatat di log aktivitas Admin, dan alasan yang diketik di laman
 * susulan ikut masuk ke `reason` baris log ini.
 */
class PemindahKeSusulanService implements PemindahKeSusulan
{
    public function kandidat(Exam $ujian): array
    {
        // Sesi UTAMA ujian ini saja. Siswa yang sudah dipindah ke sesi susulan
        // tidak lagi menunjuk sesi utama, sehingga otomatis tidak muncul.
        $sesiUtamaIds = ExamSession::query()
            ->where('exam_id', $ujian->id)
            ->where('jenis', '!=', 'susulan')
            ->pluck('id');

        if ($sesiUtamaIds->isEmpty()) {
            return [];
        }

        $penempatan = ExamParticipant::query()
            ->where('exam_id', $ujian->id)
            ->whereIn('exam_session_id', $sesiUtamaIds)
            ->where('status', ExamParticipant::STATUS_TERDAFTAR)
            ->with(['student:id,name,username', 'pesertaEvent.kelas:id,name'])
            ->get();

        $presensi = AttendanceRecord::query()
            ->whereIn('exam_participant_id', $penempatan->pluck('id'))
            ->get()
            ->keyBy('exam_participant_id');

        $kandidat = [];

        foreach ($penempatan as $p) {
            $record = $presensi->get($p->id);

            if ($record === null) {
                continue;
            }

            $status = $record->attendance_status;

            if (! in_array($status, [StatusPresensi::TidakHadir, StatusPresensi::Izin], true)) {
                continue;
            }

            $kandidat[] = [
                'peserta_id' => $p->id,
                'nis' => $p->student?->username,
                'nama' => (string) $p->student?->name,
                'kelas' => $p->pesertaEvent?->kelas?->name,
                'status' => $status->value,
                'status_label' => $status->label(),
            ];
        }

        return $kandidat;
    }

    public function pindahkan(ExamParticipant $penempatan, ExamSession $tujuan, string $alasan): void
    {
        // Peserta yang sudah punya attempt sudah mengerjakan. Memindahkannya
        // ke sesi susulan berarti mengubah fakta yang sudah terjadi - jawaban
        // dan nilainya menempel pada attempt itu. Ditolak, bukan dibiarkan.
        $sudahMengerjakan = ExamAttempt::query()
            ->where('exam_participant_id', $penempatan->id)
            ->whereIn('status', [
                ExamAttempt::STATUS_BERJALAN,
                ExamAttempt::STATUS_DIKIRIM,
                ExamAttempt::STATUS_KEDALUWARSA,
                ExamAttempt::STATUS_DIKUNCI,
            ])
            ->exists();

        if ($sudahMengerjakan) {
            throw new DomainException(
                'Peserta ini sudah mengerjakan ujian, sehingga tidak bisa dipindah ke sesi susulan.',
            );
        }

        $record = AttendanceRecord::query()
            ->where('exam_participant_id', $penempatan->id)
            ->first();

        // Belum ada baris presensi: tidak ada yang perlu direset. Barisnya
        // lahir lagi - `pending` - saat peserta ditempatkan ke ruang susulan
        // lewat `PencatatKehadiran::pastikanUntuk()`.
        if ($record === null) {
            return;
        }

        $lama = $record->attendance_status;

        // Sudah `pending`: tidak ada yang berubah, dan menulis log "pending ->
        // pending" hanya mengotori riwayat.
        if ($lama === StatusPresensi::Pending) {
            return;
        }

        AttendanceLog::create([
            'school_id' => $record->school_id,
            'attendance_record_id' => $record->id,
            'changed_by' => null,
            'pelaku_label' => 'Sistem (susulan)',
            'old_status' => $lama,
            'new_status' => StatusPresensi::Pending,
            'reason' => $alasan,
            'created_at' => now(),
        ]);

        $record->forceFill([
            'attendance_status' => StatusPresensi::Pending,
            'verified_by' => null,
            'verified_at' => null,
            'pelaku_label' => null,
            'notes' => null,
        ])->save();
    }
}

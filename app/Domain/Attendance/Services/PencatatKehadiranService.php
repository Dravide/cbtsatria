<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Contracts\PencatatKehadiran;
use App\Domain\Attendance\Enums\StatusPresensi;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Exam\Models\ExamParticipant;

/**
 * Implementasi kontrak PencatatKehadiran.
 *
 * ---------------------------------------------------------------------
 * Mengapa kelas ini terpisah dari KehadiranService
 * ---------------------------------------------------------------------
 * KehadiranService adalah jalur tulis yang dipakai LAMAN - ia menerima
 * konteks pelaku, menulis log, dan memeriksa status. Kelas ini adalah jalur
 * tulis yang dipakai MODUL LAIN, dan pekerjaannya sengaja sesempit mungkin:
 * memastikan barisnya ada.
 *
 * Menggabungkan keduanya berarti `PenempatanService::sebar()` bisa menulis
 * log presensi - dan log presensi yang ditulis oleh penyebaran peserta
 * adalah log yang berbohong tentang apa yang terjadi di ruang ujian.
 *
 * ---------------------------------------------------------------------
 * firstOrCreate, bukan create
 * ---------------------------------------------------------------------
 * `sebar()` dipanggil berkali-kali sepanjang penyusunan jadwal - Admin
 * menyebar, mengubah kapasitas, menyebar lagi. Tanpa idempotensi, setiap
 * penyebaran menambah baris presensi baru, dan yang paling mungkin terjadi
 * bukan galat dari unique constraint, melainkan penolakan yang membingungkan
 * di tengah penyusunan jadwal.
 *
 * `firstOrCreate` di sini BUKAN pengganti unique constraint - keduanya ada.
 * Yang pertama membuat pemanggilan ulang tidak berisik; yang kedua membuat
 * baris ganda mustahil, bahkan bila suatu hari ada kode lain yang menulis
 * langsung.
 */
class PencatatKehadiranService implements PencatatKehadiran
{
    public function pastikanUntuk(ExamParticipant $penempatan): void
    {
        // Hanya penempatan yang benar-benar punya RUANG yang mendapat baris
        // presensi. Peserta yang terdaftar di ujian tetapi belum ditempatkan
        // tidak punya kursi, dan presensi atas kursi yang tidak ada adalah
        // baris yang tidak bisa dijelaskan.
        if ($penempatan->exam_session_room_id === null) {
            return;
        }

        AttendanceRecord::query()->firstOrCreate(
            [
                'school_id' => $penempatan->school_id,
                'exam_participant_id' => $penempatan->id,
            ],
            [
                'attendance_status' => StatusPresensi::Pending,
            ],
        );
    }

    public function lupakan(ExamParticipant $penempatan): void
    {
        $record = AttendanceRecord::query()
            ->where('exam_participant_id', $penempatan->id)
            ->first();

        if ($record === null) {
            return;
        }

        // Baris yang sudah diverifikasi TIDAK dihapus. Ia catatan tentang apa
        // yang benar-benar terjadi di ruang ujian, dan perubahan jadwal tidak
        // membatalkan apa yang sudah terjadi.
        if ($record->sudahDiverifikasi()) {
            return;
        }

        $record->delete();
    }
}

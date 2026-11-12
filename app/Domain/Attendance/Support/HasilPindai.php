<?php

namespace App\Domain\Attendance\Support;

use App\Domain\Attendance\Models\AttendanceAcknowledgement;
use App\Domain\Attendance\Models\AttendanceScanCode;
use App\Domain\Exam\Models\ExamParticipant;

/**
 * Hasil satu pemeriksaan pindai - diterima, atau ditolak beserta sebabnya.
 *
 * Dibuat sebagai kelas dan bukan array supaya "diterima" dan "ditolak" tidak
 * pernah bisa keduanya benar: kalau `penolakan` kosong, `kode` dan `peserta`
 * PASTI terisi, dan pemanggilnya tidak perlu memeriksa dua penanda.
 */
final readonly class HasilPindai
{
    public function __construct(
        public ?AttendanceScanCode $kode,
        public ?ExamParticipant $peserta,
        public ?AttendanceAcknowledgement $pengakuan,
        public ?PenolakanPindai $penolakan,
        /**
         * Kode yang sah tetapi tujuannya sudah beres.
         *
         * Hanya `periksa()` yang menyalakannya, dan hanya untuk jawaban
         * "sudah tercatat". Ia ada supaya pemanggil tidak perlu menebak dari
         * isi teks judulnya.
         */
        public bool $tenang = false,
    ) {}

    public function diterima(): bool
    {
        return $this->penolakan === null;
    }

    /**
     * Ditolak, tetapi bukan karena kesalahan pemindainya.
     */
    public function ditolakDenganTenang(): bool
    {
        return $this->penolakan !== null && $this->tenang;
    }
}

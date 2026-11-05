<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Enums\StatusPresensi;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Support\Kelayakan;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSession;

/**
 * Sebelas syarat PRD §10.8, dalam satu tempat, dalam urutan yang benar.
 *
 * ---------------------------------------------------------------------
 * Mengapa satu kelas memeriksa SEMUANYA, bukan satu pemeriksaan per layar
 * ---------------------------------------------------------------------
 * PRD §10.8 menutup dengan kalimat yang menentukan bentuk kelas ini:
 *
 *   "Validasi dilakukan kembali pada endpoint start exam. Pemeriksaan pada
 *    halaman frontend saja tidak cukup."
 *
 * Karena itu laman gerbang dan endpoint `mulai` memanggil fungsi yang SAMA.
 * Laman gerbang yang memeriksa sendiri akan berbeda dari endpoint begitu
 * salah satunya ditambah syarat - dan perbedaannya berupa siswa yang melihat
 * tombol aktif lalu ditolak server, atau sebaliknya.
 *
 * ---------------------------------------------------------------------
 * Urutannya adalah bagian dari jawabannya
 * ---------------------------------------------------------------------
 * Peserta yang belum dipresensi DAN sesinya sudah tutup harus menerima
 * pesan yang pertama ia butuhkan, bukan yang pertama diperiksa program.
 * Karena itu urutannya mengikuti perjalanan siswa: identitas, penempatan,
 * presensi, sesi, lalu attempt. Menukarnya tidak menggagalkan apa pun -
 * ia hanya membuat siswa menelepon pengawas untuk hal yang salah.
 *
 * ---------------------------------------------------------------------
 * Yang TIDAK diperiksa di sini
 * ---------------------------------------------------------------------
 * Syarat 9 (token) diperiksa di endpoint `mulai` saja, bukan di gerbang,
 * karena token adalah kredensial yang diketik SAAT memulai - dan laman
 * gerbang yang menolak karena token salah sebelum siswa sempat mengetiknya
 * adalah laman yang tidak bisa dipakai.
 *
 * Syarat 6 memakai `StatusPresensi::membukaGerbang()`, yang sudah diuji
 * sejak Fase 6 sesuai perintah blueprint `:1413`. Kelas ini TIDAK
 * mengulang aturannya.
 */
class KelayakanUjian
{
    /**
     * Periksa seluruh syarat yang berlaku di luar token.
     *
     * @param  bool  $periksaWaktu  false pada laman riwayat, yang harus tetap
     *                              bisa dibuka sesudah sesinya berakhir.
     */
    public function periksa(ExamParticipant $peserta, bool $periksaWaktu = true): Kelayakan
    {
        $ujian = $peserta->exam;

        // 1 & 2 - akun aktif dan sekolah sesuai.
        //
        // Yang memeriksanya bukan kelas ini melainkan global scope dan
        // middleware tenant: peserta yang tidak sepaham sekolahnya tidak
        // pernah sampai di sini. Baris ini ada supaya pembaca berikutnya
        // tidak mencari pemeriksaan yang sengaja tidak ditulis.
        //
        // 3 - terdaftar sebagai peserta event. Dijamin composite FK dari
        // event_participant_id; lihat docblock ExamParticipant.
        if ($ujian === null) {
            return Kelayakan::tolak('Penempatan ini tidak terhubung ke ujian mana pun.');
        }

        // 4 - status penempatan.
        if ($peserta->status !== ExamParticipant::STATUS_TERDAFTAR) {
            return Kelayakan::tolak(
                'Anda tidak terdaftar sebagai peserta ujian ini.',
                'Hubungi Admin sekolah bila menurut Anda ini keliru.',
            );
        }

        // 5 - penempatan sesi dan ruang.
        $sesi = $peserta->session;

        if ($sesi === null) {
            return Kelayakan::tolak(
                'Anda belum ditempatkan pada sesi mana pun.',
                'Hubungi Admin sekolah untuk penempatan sesi.',
            );
        }

        // 6 & 7 - presensi, dan verifikasinya oleh Pengawas ruang.
        //
        // Satu kueri, bukan dua, dan `verified_at` ikut diperiksa: baris
        // presensi yang dibuat otomatis (PRD §10.6) sudah berstatus `hadir`
        // tetapi belum diverifikasi siapa pun, dan siswa yang memakainya
        // untuk masuk berarti melewati satu-satunya pemeriksaan yang
        // memastikan ada orang yang benar-benar melihatnya di kursi.
        $kehadiran = AttendanceRecord::query()
            ->where('exam_participant_id', $peserta->id)
            ->first();

        $galatPresensi = $this->periksaPresensi($kehadiran, $ujian->allow_late_entry);

        if ($galatPresensi !== null) {
            return $galatPresensi;
        }

        if ($periksaWaktu) {
            // 8 - sesi masih menerima peserta.
            $galatWaktu = $this->periksaWaktuSesi($sesi);

            if ($galatWaktu !== null) {
                return $galatWaktu;
            }
        }

        // 10 & 11 - batas attempt dan attempt aktif lain.
        $galatAttempt = $this->periksaAttempt($peserta, $ujian->max_attempts);

        if ($galatAttempt !== null) {
            return $galatAttempt;
        }

        return Kelayakan::boleh();
    }

    /**
     * Syarat 6 dan 7.
     *
     * Dipisah supaya laman gerbang bisa membedakan "belum dipresensi" dari
     * "dipresensi tetapi belum diverifikasi" - dua kalimat yang berbeda bagi
     * siswa, dan plate 20 hanya menggambar yang pertama.
     */
    public function periksaPresensi(?AttendanceRecord $kehadiran, bool $izinkanTerlambat): ?Kelayakan
    {
        if ($kehadiran === null) {
            return Kelayakan::tolak(Kelayakan::PESAN_BELUM_DIPRESENSI);
        }

        // `membukaGerbang()` milik enum, bukan aturan yang diketik ulang di
        // sini - lihat docblock StatusPresensi. `terlambat` hanya membuka
        // gerbang bila ujiannya mengizinkan, dan itu keputusan ujian.
        if (! $kehadiran->attendance_status->membukaGerbang($izinkanTerlambat)) {
            return match ($kehadiran->attendance_status) {
                StatusPresensi::Terlambat => Kelayakan::tolak(
                    'Anda tercatat terlambat, dan ujian ini tidak menerima peserta terlambat.',
                    'Hubungi Pengawas ruang untuk menanyakan kebijakannya.',
                ),
                StatusPresensi::Izin => Kelayakan::tolak(
                    'Anda tercatat izin pada ujian ini.',
                    'Hubungi Pengawas ruang bila menurut Anda ini keliru.',
                ),
                StatusPresensi::TidakHadir => Kelayakan::tolak(
                    'Anda tercatat tidak hadir pada ujian ini.',
                    'Hubungi Pengawas ruang bila menurut Anda ini keliru.',
                ),
                default => Kelayakan::tolak(Kelayakan::PESAN_BELUM_DIPRESENSI),
            };
        }

        // Syarat 7. Status `hadir` yang belum diverifikasi adalah baris yang
        // dibuat otomatis, dan masuknya lewat pintu itu berarti melewati
        // satu-satunya bukti bahwa ada orang yang melihatnya di kursi.
        if ($kehadiran->verified_at === null) {
            return Kelayakan::tolak(Kelayakan::PESAN_BELUM_DIPRESENSI);
        }

        return null;
    }

    /** Syarat 8 - jendela sesi. */
    public function periksaWaktuSesi(ExamSession $sesi): ?Kelayakan
    {
        $sekarang = now();

        if ($sekarang->lt($sesi->access_opens_at)) {
            return Kelayakan::tolak(
                'Sesi ini belum dibuka. Akses mulai pukul '.$sesi->access_opens_at->format('H:i').'.',
                'Laman ini akan menyegar sendiri.',
            );
        }

        if ($sekarang->gt($sesi->hard_end_at)) {
            return Kelayakan::tolak(
                'Sesi ini sudah berakhir pukul '.$sesi->hard_end_at->format('H:i').'.',
                'Hubungi Pengawas ruang bila menurut Anda ini keliru.',
            );
        }

        return null;
    }

    /**
     * Syarat 10 dan 11 - jumlah attempt dan attempt yang masih berjalan.
     *
     * Attempt yang TERKUNCI tidak dihitung sebagai "aktif": ia menunggu
     * Pengawas membukanya, dan menghitungnya akan membuat siswa yang sudah
     * dikunci lalu dibuka tidak bisa melanjutkan sama sekali. Ia juga tidak
     * menambah nomor attempt baru - membuka kunci melanjutkan yang sama.
     */
    private function periksaAttempt(ExamParticipant $peserta, int $batas): ?Kelayakan
    {
        $attempt = ExamAttempt::query()
            ->where('exam_participant_id', $peserta->id)
            ->orderByDesc('attempt_number')
            ->first();

        if ($attempt === null) {
            return null;
        }

        // 11 - attempt lain yang masih berjalan.
        //
        // MVP membatasi satu attempt aktif (PRD §11.6), sehingga attempt
        // berjalan yang ditemukan bukan "salah satu dari beberapa" - ia
        // satu-satunya, dan siswa tinggal melanjutkannya.
        if ($attempt->status === ExamAttempt::STATUS_BERJALAN) {
            return null;
        }

        if ($attempt->status === ExamAttempt::STATUS_DIKUNCI) {
            return Kelayakan::tolak(
                'Ujian Anda sedang dikunci.',
                'Hubungi Pengawas ruang untuk membukanya. Waktu ujian tetap berjalan selama terkunci.',
            );
        }

        // 10 - batas jumlah attempt. Attempt yang sudah dikirim atau
        // kedaluwarsa dihitung, karena keduanya memang sudah dipakai.
        $terpakai = ExamAttempt::query()
            ->where('exam_participant_id', $peserta->id)
            ->whereIn('status', [ExamAttempt::STATUS_DIKIRIM, ExamAttempt::STATUS_KEDALUWARSA])
            ->count();

        if ($terpakai >= $batas) {
            return Kelayakan::tolak(
                'Anda sudah memakai seluruh kesempatan ujian ini ('.$batas.'×).',
                'Hubungi Admin sekolah bila menurut Anda ini keliru.',
            );
        }

        return null;
    }

    /**
     * Attempt yang boleh dilanjutkan, bila ada.
     *
     * Dipakai laman gerbang untuk memutuskan apakah tombolnya berbunyi
     * "Mulai ujian" atau "Lanjutkan". Bedanya bukan kosmetik: siswa yang
     * meninggalkan ujian karena sinyal putus lalu kembali harus tahu bahwa
     * jawabannya masih ada, dan tombol "Mulai" membuatnya menduga ujiannya
     * hilang.
     */
    public function attemptBerjalan(ExamParticipant $peserta): ?ExamAttempt
    {
        return ExamAttempt::query()
            ->where('exam_participant_id', $peserta->id)
            ->where('status', ExamAttempt::STATUS_BERJALAN)
            ->orderByDesc('attempt_number')
            ->first();
    }
}

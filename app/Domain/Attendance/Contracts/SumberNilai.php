<?php

namespace App\Domain\Attendance\Contracts;

use App\Domain\Attendance\Models\ExamAttempt;

/**
 * Angka nilai yang sudah dihitung modul Grading - dibaca laman siswa.
 *
 * ---------------------------------------------------------------------
 * Mengapa kontrak, bukan relasi pada ExamAttempt
 * ---------------------------------------------------------------------
 * `exam_results` milik modul Grading, dan `exam_attempts` milik Attendance.
 * Relasi `hasil()` pada `ExamAttempt` adalah satu baris yang terlihat wajar
 * dan justru membalik arah modul: Attendance akan mengimpor
 * `Grading\Models\ExamResult`, sementara Grading sudah mengimpor
 * `Attendance\Models\ExamAttempt` untuk menghitungnya. Lingkarannya nyata dan
 * `DomainBoundariesTest` menolaknya - dengan alasan yang benar: attempt tidak
 * pernah boleh tahu bagaimana nilainya dihitung.
 *
 * Bentuk penyelesaiannya sama dengan `PencatatKehadiran` dan
 * `PenilaiPengerjaan`: Attendance yang mendeklarasikan, Grading yang menjawab,
 * provider yang menyambungkan.
 *
 * ---------------------------------------------------------------------
 * Mengapa peta, bukan daftar
 * ---------------------------------------------------------------------
 * Pemanggilnya adalah laman "Nilai saya", yang sudah memegang daftar attempt
 * milik satu siswa. Ia butuh angka PER attempt, bukan daftar hasil yang harus
 * dicocokkan ulang dengan `exam_attempt_id`. Peta ber-kunci id attempt
 * membuat pencocokan itu satu `array_key_exists`, dan membuat attempt yang
 * belum punya baris hasil (mis. yang masih berjalan) hadir sebagai
 * ketiadaan - bukan sebagai baris berisi nol.
 */
interface SumberNilai
{
    /**
     * Nilai yang tersimpan untuk sekumpulan attempt.
     *
     * Attempt yang belum punya baris hasil TIDAK muncul di peta. Pemanggil
     * yang memperlakukannya sebagai nol akan mengarang nilai untuk pengerjaan
     * yang belum selesai.
     *
     * @param  array<int, int>  $attemptIds
     * @return array<int, array{
     *     otomatis: float,
     *     uraian: float,
     *     total: float,
     *     maksimal: float,
     *     persen: float,
     *     final: bool,
     *     sisa_uraian: int,
     *     terbit: bool
     * }> Ber-kunci `exam_attempt_id`.
     */
    public function perAttempt(array $attemptIds): array;

    /**
     * Apakah attempt ini punya nilai yang sudah boleh dibaca siswa.
     *
     * Dipisah dari `perAttempt()` karena pertanyaannya berbeda: yang pertama
     * "berapa nilainya", yang kedua "bolehkah ia melihatnya". Siswa tidak
     * boleh menerima angka yang belum dipublikasikan meski ia ada di basis
     * data - dan satu-satunya tempat yang memutuskan itu adalah method ini.
     */
    public function bolehDibaca(ExamAttempt $attempt): bool;
}

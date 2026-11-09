<?php

namespace App\Domain\Academic\Services;

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\ClassEnrollment;
use App\Domain\Academic\Models\SchoolClass;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Menempatkan siswa ke kelas, dan mengeluarkannya.
 *
 * =====================================================================
 * Mengapa service ini ada, padahal penulisannya cuma dua baris
 * =====================================================================
 * Sampai Fase 10, satu-satunya kode yang menulis `class_enrollments` adalah
 * `SchoolClassController::simpanAnggota()`. Impor pengguna membutuhkan
 * penulisan yang sama - dan menyalinnya berarti dua tempat yang bisa berbeda
 * diam-diam pada aturan yang justru harus tunggal.
 *
 * Yang menuntutnya lebih keras lagi: SKEMA TIDAK MENJAGA ATURAN ITU.
 * `class_enrollments` hanya punya `unique(school_id, class_id, student_id)` -
 * tidak ada `unique(school_id, student_id, academic_year_id)`. Artinya basis
 * data mengizinkan seorang siswa berada di DUA kelas pada tahun ajaran yang
 * sama, dan satu-satunya yang mencegahnya adalah kehati-hatian layar. Impor
 * massal 1.284 baris tidak boleh bergantung pada kehati-hatian layar.
 *
 * =====================================================================
 * Mengapa di Academic, bukan di Admin
 * =====================================================================
 * Peta batas modul (`DomainBoundariesTest`) tidak memuat satu modul pun yang
 * mengizinkan impor `Admin`. Service yang lahir di `Admin\Services` karena itu
 * tidak bisa dijangkau modul lain tanpa melanggar batas - sementara invarian
 * yang dijaga di sini ("satu keanggotaan aktif per siswa per tahun ajaran")
 * adalah invarian Academic atas tabel Academic.
 *
 * Admin tetap boleh memanggilnya: Academic ada di daftar impor Admin.
 *
 * =====================================================================
 * `rencana()` dan `tempatkan()` memakai perhitungan yang SAMA
 * =====================================================================
 * Pratinjau impor menampilkan "pindah dari X-1" SEBELUM apa pun disimpan, dan
 * yang ditampilkan itu harus persis yang akan terjadi. Karena itu
 * `tempatkan()` memanggil `rencana()` alih-alih menghitung ulang - alasan yang
 * sama dengan `pratinjau_json` yang menyimpan muatannya: layar dan simpanan
 * tidak boleh bisa berbeda.
 */
class PenempatanKelas
{
    /**
     * Apa yang akan terjadi bila siswa ini ditempatkan ke kelas itu.
     *
     * TIDAK menulis apa pun - dipakai laman pratinjau impor.
     *
     * @return array{aksi: string, dari: ?string, kelas: string}
     */
    public function rencana(int $studentId, SchoolClass $kelas): array
    {
        $sekarang = $this->keanggotaanAktif($studentId, (int) $kelas->academic_year_id);

        $dari = $sekarang
            ->firstWhere('class_id', $kelas->id);

        if ($dari !== null) {
            return ['aksi' => 'tetap', 'dari' => null, 'kelas' => $kelas->name];
        }

        // Keanggotaan aktif di kelas LAIN pada tahun ajaran yang sama. Inilah
        // yang membuat perpindahan terlihat sebelum disimpan: Admin yang
        // mengimpor daftar kelas baru perlu tahu bahwa 12 siswa berpindah,
        // bukan hanya bahwa 12 baris tersimpan.
        // Tiga argumen, dan itu wajib: `firstWhere('class_id', '!=')` menukar
        // posisi operator dan nilai, sehingga yang dicari adalah baris yang
        // class_id-nya sama dengan string "!=" - dan hasilnya selalu null.
        $lain = $sekarang->firstWhere('class_id', '!=', $kelas->id);

        if ($lain !== null) {
            return [
                'aksi' => 'pindah',
                'dari' => $lain->kelas?->name,
                'kelas' => $kelas->name,
            ];
        }

        // Siswa yang pernah keluar dari kelas ini lalu dimasukkan kembali
        // sudah punya barisnya. Ia "baru" bagi kelasnya, tetapi barisnya
        // dipakai ulang oleh updateOrCreate - dan itu memang yang terjadi.
        return ['aksi' => 'baru', 'dari' => null, 'kelas' => $kelas->name];
    }

    /**
     * Tempatkan siswanya, dan kembalikan apa yang terjadi.
     *
     * @return array{aksi: string, dari: ?string, kelas: string}
     */
    public function tempatkan(int $studentId, SchoolClass $kelas): array
    {
        return DB::transaction(function () use ($studentId, $kelas) {
            $rencana = $this->rencana($studentId, $kelas);

            if ($rencana['aksi'] === 'tetap') {
                // Sudah aktif di kelas ini. Barisnya disegarkan alih-alih
                // dibiarkan: siswa yang tadi berstatus `pindah` lalu kembali
                // harus benar-benar aktif lagi, dan itu satu-satunya alasan
                // method ini dipanggil untuk keanggotaan yang sudah ada.
                ClassEnrollment::query()
                    ->where('school_id', $kelas->school_id)
                    ->where('class_id', $kelas->id)
                    ->where('student_id', $studentId)
                    ->update(['status' => 'aktif']);

                return $rencana;
            }

            // Keanggotaan aktif lain di tahun ajaran yang sama ditutup LEBIH
            // DULU. Urutannya bukan selera: bila siswa sudah aktif di X-1 dan
            // kini masuk X-2, membiarkan X-1 aktif berarti satu siswa di dua
            // kelas pada tahun ajaran yang sama - keadaan yang tidak dicegah
            // skema, dan yang baru ketahuan saat cetak daftar hadir.
            if ($rencana['aksi'] === 'pindah') {
                ClassEnrollment::query()
                    ->where('school_id', $kelas->school_id)
                    ->where('student_id', $studentId)
                    ->where('academic_year_id', $kelas->academic_year_id)
                    ->where('class_id', '!=', $kelas->id)
                    ->where('status', 'aktif')
                    ->update(['status' => 'pindah']);
            }

            // updateOrCreate, BUKAN create: siswa yang pernah keluar dari
            // kelas ini lalu dimasukkan kembali sudah punya barisnya, dan
            // unique(school_id, class_id, student_id) akan menolak insert
            // kedua - sebagai galat basis data, bukan sebagai pesan yang bisa
            // dibaca Admin.
            ClassEnrollment::updateOrCreate(
                [
                    'school_id' => $kelas->school_id,
                    'class_id' => $kelas->id,
                    'student_id' => $studentId,
                ],
                [
                    'academic_year_id' => $kelas->academic_year_id,
                    'enrollment_date' => now()->toDateString(),
                    'status' => 'aktif',
                ],
            );

            return $rencana;
        });
    }

    /**
     * Keluarkan sekumpulan siswa dari satu kelas.
     *
     * Ditandai `pindah`, bukan dihapus - alasan yang sama dengan seluruh
     * penghapusan di aplikasi ini: keanggotaan kelas adalah riwayat, dan
     * daftar hadir ujian menunjuk ke sana.
     *
     * @param  array<int, int>  $studentId
     * @return int Jumlah baris yang berubah.
     */
    public function keluarkan(SchoolClass $kelas, array $studentId): int
    {
        if ($studentId === []) {
            return 0;
        }

        return ClassEnrollment::query()
            ->where('school_id', $kelas->school_id)
            ->where('class_id', $kelas->id)
            ->whereIn('student_id', $studentId)
            ->where('status', 'aktif')
            ->update(['status' => 'pindah']);
    }

    /**
     * Kelas siswa pada tahun ajaran berjalan, bila ada.
     *
     * =====================================================================
     * Mengapa lewat service ini, bukan kueri di controller
     * =====================================================================
     * Dua alasan, dan keduanya sudah ditulis di docblock kelas ini: skema
     * TIDAK menjaga "satu keanggotaan aktif per siswa per tahun ajaran"
     * (`class_enrollments` hanya `unique(school_id, class_id, student_id)`),
     * jadi kueri ad-hoc adalah tempat kedua yang menebak baris mana yang
     * "aktif" - dan dua tempat menebak aturan yang sama akan berbeda diam-diam.
     *
     * =====================================================================
     * Pembacaan, bukan penulisan
     * =====================================================================
     * Method ini tidak mengubah apa pun. Ia dipakai laman Akun siswa, yang
     * TIDAK terikat event: rantai yang dipakai `JadwalSiswaController` dan
     * `NilaiSiswaController` (`pesertaEvent?->kelas`) berbunyi kosong bagi
     * siswa yang belum ditempatkan di ujian mana pun - keadaan yang sah dan
     * justru paling sering terjadi.
     *
     * "Tahun berjalan" memakai rumus yang sudah ada di repo
     * (`MasterImportController::tahunDipilih()`): yang aktif, jika tidak yang
     * `start_date`-nya terbaru. Tanpa fallback itu, sekolah yang lupa
     * mengaktifkan tahun ajaran baru akan menampilkan kelas tahun lalu.
     */
    public function kelasSekarang(int $studentId): ?SchoolClass
    {
        $tahun = AcademicYear::query()->where('is_active', true)->first()
            ?? AcademicYear::query()->orderByDesc('start_date')->first();

        if ($tahun === null) {
            return null;
        }

        return ClassEnrollment::query()
            ->with('kelas')
            ->where('student_id', $studentId)
            ->where('academic_year_id', $tahun->id)
            ->where('status', 'aktif')
            ->orderByDesc('enrollment_date')
            ->first()
            ?->kelas;
    }

    /**
     * Keanggotaan aktif siswa pada satu tahun ajaran.
     *
     * @return Collection<int, ClassEnrollment>
     */
    private function keanggotaanAktif(int $studentId, int $tahunId)
    {
        return ClassEnrollment::query()
            ->with('kelas:id,name')
            ->where('student_id', $studentId)
            ->where('academic_year_id', $tahunId)
            ->where('status', 'aktif')
            ->get();
    }
}

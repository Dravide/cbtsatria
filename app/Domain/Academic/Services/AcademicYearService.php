<?php

namespace App\Domain\Academic\Services;

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Tenancy\Services\SchoolResolver;
use Illuminate\Support\Facades\DB;

/**
 * Aturan tahun ajaran yang tidak bisa ditegakkan skema.
 *
 * ---------------------------------------------------------------------
 * Mengapa "hanya satu aktif" hidup di sini, bukan di kolom
 * ---------------------------------------------------------------------
 * MySQL tidak punya partial unique index. Satu-satunya cara menegakkannya di
 * skema adalah generated column bersyarat, dan itu menambah mekanika yang
 * sulit dibaca untuk hasil yang sama.
 *
 * Yang lebih menentukan: mengaktifkan tahun ajaran BUKAN satu penulisan. Ia
 * selalu dua - menyalakan yang baru, mematikan yang lama - dan keduanya harus
 * terjadi bersamaan atau tidak sama sekali. Itu transaksi, dan transaksi
 * memang tempatnya di service, bukan di model.
 *
 * Karena itu pula aturannya tidak ditaruh di model: seeder, importer, dan
 * console command tidak selalu lewat model, tetapi semua yang mengubah status
 * tahun ajaran harus lewat sini.
 */
class AcademicYearService
{
    public function __construct(private readonly SchoolResolver $resolver) {}

    /**
     * Jadikan satu tahun ajaran sebagai satu-satunya yang aktif.
     *
     * Yang lama dimatikan dulu, lalu yang baru dinyalakan, di dalam satu
     * transaksi. Urutan itu penting: bila dibalik, akan ada saat ketika dua
     * tahun ajaran sama-sama aktif, dan pemeriksaan apa pun yang berjalan di
     * antaranya - termasuk request lain di koneksi lain - melihat keadaan
     * yang seharusnya tidak pernah ada.
     */
    public function aktifkan(AcademicYear $tahun): void
    {
        DB::transaction(function () use ($tahun) {
            AcademicYear::query()
                ->whereKeyNot($tahun->getKey())
                ->where('is_active', true)
                ->update(['is_active' => false]);

            $tahun->forceFill(['is_active' => true])->save();

            // Semester di tahun ajaran yang tidak lagi aktif ikut dimatikan.
            // Membiarkannya menyala berarti ada semester aktif di tahun ajaran
            // yang tidak aktif - keadaan yang tidak punya arti.
            Semester::query()
                ->where('academic_year_id', '!=', $tahun->getKey())
                ->where('is_active', true)
                ->update(['is_active' => false]);
        });
    }

    /**
     * Tutup tahun ajaran (arsipkan) tanpa menghapus apa pun.
     *
     * Peringatan di wireframe plate 06 berlaku di sini: "Menutup tahun ajaran
     * tidak menghapus data. Kelas, penugasan, dan event lama diarsipkan dan
     * tetap dapat dibuka sebagai arsip." Karena itu method ini TIDAK menyentuh
     * tabel lain - ia hanya melepas status aktif. Arsip adalah keadaan yang
     * diturunkan dari "tidak aktif dan tanggalnya sudah lewat", bukan kolom
     * terpisah yang bisa berbohong.
     */
    public function tutup(AcademicYear $tahun): void
    {
        if (! $tahun->is_active) {
            return;
        }

        $tahun->forceFill(['is_active' => false])->save();

        Semester::query()
            ->where('academic_year_id', $tahun->getKey())
            ->update(['is_active' => false]);
    }

    /**
     * Tahun ajaran yang sedang berlaku, bila ada.
     */
    public function aktif(): ?AcademicYear
    {
        return AcademicYear::query()->where('is_active', true)->first();
    }

    /**
     * Semester yang sedang berlaku di tahun ajaran aktif.
     */
    public function semesterAktif(): ?Semester
    {
        return Semester::query()->where('is_active', true)->first();
    }

    /**
     * Aktifkan satu semester dan matikan yang lain di sekolah yang sama.
     */
    public function aktifkanSemester(Semester $semester): void
    {
        DB::transaction(function () use ($semester) {
            Semester::query()
                ->whereKeyNot($semester->getKey())
                ->where('is_active', true)
                ->update(['is_active' => false]);

            $semester->forceFill(['is_active' => true])->save();
        });
    }

    /**
     * Pastikan tahun ajaran milik sekolah yang sedang berkonteks.
     *
     * Dipakai sebelum mengaktifkan, supaya id dari request tidak bisa
     * menunjuk tahun ajaran sekolah lain. Scope sudah menyembunyikan barisnya,
     * jadi pemeriksaan ini adalah lapis kedua yang murah - dan ia menutup
     * jalur `withoutGlobalScope` yang mungkin dipakai kode lain.
     */
    public function milikKonteks(AcademicYear $tahun): bool
    {
        $schoolId = $this->resolver->id();

        return $schoolId !== null && $tahun->school_id === $schoolId;
    }
}

<?php

namespace App\Domain\Question\Services;

use App\Domain\Question\Models\EventQuestionPackage;
use App\Domain\Question\Models\QuestionPackage;
use App\Domain\Question\Models\QuestionPackageItem;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya pintu pemasangan paket ke mapel sebuah event.
 *
 * =====================================================================
 * Mengapa harus satu pintu
 * =====================================================================
 * Blueprint `:312`: "mengubah paket setelah jadwal tersimpan harus lewat satu
 * pintu, dan pintu itu mencatat siapa yang mengubahnya serta kapan."
 *
 * Yang menjadikannya penting: paket yang dipasang menentukan APA YANG
 * DIKERJAKAN SISWA. Mengubahnya setelah ujian dijadwalkan berarti mengubah
 * ujiannya - dan kalau perubahan itu tidak tercatat, pertanyaan "paket ini
 * dipasang kemarin atau bulan lalu, dan oleh siapa" hanya bisa dijawab dari
 * log aktivitas, yang tidak mengenal urutan pemasangan per mapel.
 *
 * ---------------------------------------------------------------------
 * Dua tabel ditulis dalam SATU transaksi, dan itu yang menjaganya konsisten
 * ---------------------------------------------------------------------
 *   event_subjects.question_package_id   -> paket mana yang berlaku SEKARANG
 *   event_question_packages              -> siapa memasang apa, KAPAN
 *
 * Kalau salah satunya gagal ditulis, keadaannya lebih buruk daripada tidak
 * ada riwayat sama sekali: kolomnya menyebut paket A sementara riwayatnya
 * berakhir di paket B, dan tidak ada cara mengetahui mana yang benar. Karena
 * itu keduanya berada dalam satu `DB::transaction`.
 *
 * ---------------------------------------------------------------------
 * Memasang ULANG tidak menghapus baris riwayat lama
 * ---------------------------------------------------------------------
 * Memasang paket kedua setelah yang pertama adalah DUA peristiwa. Baris lama
 * tetap ada, dan `replaced_package_id` di baris baru menyebut apa yang
 * digantikannya - sehingga urutan pemasangan bisa dibaca dari riwayatnya
 * sendiri, tanpa kolom "aktif" yang harus dijaga tetap satu.
 *
 * ---------------------------------------------------------------------
 * Apa yang TIDAK diperiksa di sini
 * ---------------------------------------------------------------------
 * Izin. Yang memanggil Pintu ini adalah controller yang sudah menjalankan
 * EventPolicy::update (Admin saja). Memeriksanya dua kali berarti dua tempat
 * yang bisa berbeda diam-diam.
 */
class PemasanganPaket
{
    /**
     * Pasang paket ke satu mapel event.
     *
     * @param  int  $eventSubjectId  Baris event_subjects yang dipasangi.
     */
    public function pasang(
        int $eventSubjectId,
        ?int $questionPackageId,
        int $olehUserId,
        ?string $catatan = null,
    ): ?EventQuestionPackage {
        return DB::transaction(function () use ($eventSubjectId, $questionPackageId, $olehUserId, $catatan) {
            // Barisnya dibaca lewat model Event lewat service ini - dan itu
            // satu-satunya cara Question menyentuh tabel Event tanpa
            // melanggar peta modul: yang dibaca adalah kolom, bukan relasi.
            $mapel = DB::table('event_subjects')->where('id', $eventSubjectId)->first();

            if ($mapel === null) {
                return null;
            }

            $sebelumnya = $mapel->question_package_id === null
                ? null
                : (int) $mapel->question_package_id;

            DB::table('event_subjects')
                ->where('id', $eventSubjectId)
                ->update(['question_package_id' => $questionPackageId]);

            // Melepas paket (null) TIDAK menulis baris riwayat: tidak ada
            // paket yang dipasang, dan baris riwayat tanpa paket hanya
            // memenuhi tabel dengan kejadian yang tidak menjawab pertanyaan
            // apa pun. Riwayatnya tetap terbaca dari baris pemasangan
            // terakhir beserta kolom yang sekarang kosong.
            if ($questionPackageId === null) {
                return null;
            }

            return EventQuestionPackage::create([
                'school_id' => $mapel->school_id,
                'event_id' => $mapel->event_id,
                'event_subject_id' => $eventSubjectId,
                'question_package_id' => $questionPackageId,
                'assigned_by' => $olehUserId,
                'replaced_package_id' => $sebelumnya,
                'assigned_at' => now(),
                'catatan' => $catatan,
            ]);
        });
    }

    /**
     * Riwayat pemasangan satu mapel, terbaru di atas.
     *
     * @return array<int, array<string, mixed>>
     */
    public function riwayat(int $eventSubjectId): array
    {
        return EventQuestionPackage::query()
            ->where('event_subject_id', $eventSubjectId)
            ->with(['paket:id,name', 'pemasang:id,name'])
            ->orderByDesc('assigned_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (EventQuestionPackage $baris) => [
                'id' => $baris->id,
                'paket' => $baris->paket?->name,
                'pemasang' => $baris->pemasang?->name,
                'pada' => $baris->assigned_at?->toIso8601String(),
                'catatan' => $baris->catatan,
            ])
            ->all();
    }

    /**
     * Paket yang boleh dipasang ke sebuah mapel event.
     *
     * Disaring per MAPEL, dan itu yang membuat pemilihnya berguna: mapel
     * Matematika tidak boleh dipasangi paket Fisika, dan daftar berisi 40
     * paket lintas mapel berarti setiap pemasangan menuntut Admin mengingat
     * paket mana milik mapel mana.
     *
     * @return array<int, array<string, mixed>>
     */
    public function paketTersedia(int $schoolId, int $subjectId): array
    {
        return QuestionPackage::query()
            // Kueri ini dipanggil dari laman Admin, yang konteks tenant-nya
            // sudah benar - scope-nya tetap dipasang, bukan dilewati.
            ->where('school_id', $schoolId)
            ->where('subject_id', $subjectId)
            ->withCount('butir')
            ->orderByDesc('id')
            ->get()
            ->map(fn (QuestionPackage $paket) => [
                'id' => $paket->id,
                'nama' => $paket->name,
                'status' => $paket->status->value,
                'butir' => (int) $paket->butir_count,
            ])
            ->all();
    }

    /** Jumlah butir paket - dipakai butir kesiapan event. */
    public function jumlahButir(?int $packageId): int
    {
        if ($packageId === null) {
            return 0;
        }

        return QuestionPackageItem::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('question_package_id', $packageId)
            ->count();
    }

    /**
     * Ringkasan beberapa paket sekaligus, diindeks id-nya.
     *
     * Dibuat untuk laman Admin plate 15, dan bentuknya - peta berisi skalar -
     * adalah alasan keberadaannya. Laman itu menampilkan nama paket, status,
     * dan jumlah butir untuk SETIAP baris mapel event, dan cara yang mudah
     * adalah `QuestionPackage::find()` per baris: empat puluh mapel berarti
     * empat puluh kueri untuk data yang bisa diambil dalam satu.
     *
     * Yang dikembalikan hanya teks dan angka, bukan model - alasannya sama
     * dengan PenghitungButir: begitu modelnya menyeberang batas modul,
     * pemanggilnya bisa memanggil relasi dan menyimpannya, dan pemisahan
     * modulnya batal tanpa satu baris pun di berkas ini yang berubah.
     *
     * @param  array<int, int>  $packageIds
     * @return array<int, array{nama: string, status: string, butir: int}>
     */
    public function ringkasPaket(array $packageIds): array
    {
        if ($packageIds === []) {
            return [];
        }

        return QuestionPackage::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->whereIn('id', $packageIds)
            ->withCount('butir')
            ->get()
            ->keyBy('id')
            ->map(fn (QuestionPackage $paket) => [
                'nama' => $paket->name,
                'status' => $paket->status->value,
                'butir' => (int) $paket->butir_count,
            ])
            ->all();
    }
}

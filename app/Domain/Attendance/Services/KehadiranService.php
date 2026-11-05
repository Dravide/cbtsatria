<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Enums\StatusPresensi;
use App\Domain\Attendance\Models\AttendanceLog;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSessionRoom;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya jalur tulis untuk attendance_records dan attendance_logs.
 *
 * ---------------------------------------------------------------------
 * Setiap perubahan menulis baris log, tanpa kecuali
 * ---------------------------------------------------------------------
 * PRD §10.5 menuntut setiap perubahan mencatat identitas pelaku, waktu, dan
 * alasan. Janji itu bukan janji kelas ini kepada pemanggilnya, melainkan
 * janji SISTEM kepada orang tua siswa yang kelak bertanya "mengapa anak saya
 * tercatat tidak hadir". Karena itu jalur tulisnya satu, dan di dalamnya
 * tidak ada cabang yang menyimpan tanpa mencatat.
 *
 * Yang paling mudah salah adalah verifikasi massal: dua puluh siswa
 * ditandai hadir dengan satu ketukan. Kalau ia menulis SATU baris log
 * untuk dua puluh perubahan, log-nya berhenti menjawab pertanyaan per
 * siswa - dan pertanyaan itu ditanyakan per siswa. Karena itu massal
 * menulis satu baris log PER PESERTA, meski hasilnya terlihat sama.
 *
 * ---------------------------------------------------------------------
 * Mengapa status yang sama tidak menulis log
 * ---------------------------------------------------------------------
 * Menandai hadir dua kali karena tombolnya tersentuh dua kali bukan
 * perubahan, dan log yang memuatnya akan penuh baris yang tidak menyatakan
 * apa pun. Yang menentukan adalah selisihnya, bukan tindakannya.
 */
class KehadiranService
{
    /**
     * Catat satu perubahan status presensi.
     *
     * @param  string|null  $alasan  Wajib untuk koreksi; opsional saat verifikasi.
     */
    public function catat(
        AttendanceRecord $record,
        StatusPresensi $status,
        KonteksRuang $konteks,
        ?string $alasan = null,
    ): AttendanceRecord {
        return DB::transaction(function () use ($record, $status, $konteks, $alasan) {
            $lama = $record->attendance_status;

            if ($lama === $status) {
                return $record;
            }

            $record->forceFill([
                'attendance_status' => $status,
                'verified_by' => $konteks->penggunaId(),
                'verified_at' => now(),
                'pelaku_label' => $konteks->pelakuLabel(),
            ])->save();

            $this->catatLog($record, $lama, $status, $konteks, $alasan);

            return $record;
        });
    }

    /**
     * Verifikasi massal - tetapi lognya TETAP satu baris per peserta.
     *
     * @param  array<int, int>  $recordIds
     * @return int Jumlah baris yang benar-benar berubah.
     */
    public function verifikasiMassal(
        ExamSessionRoom $ruang,
        array $recordIds,
        StatusPresensi $status,
        KonteksRuang $konteks,
        ?string $alasan = null,
    ): int {
        return DB::transaction(function () use ($ruang, $recordIds, $status, $konteks, $alasan) {
            // Penyaringan ruang dijalankan lagi di sini, bukan hanya
            // dipercaya dari daftar id yang dikirim peramban. Daftar id
            // datang dari klien, dan klien bisa mengirim id milik ruang lain
            // - yang berarti pengawas R-02 bisa memverifikasi peserta R-03
            // dengan menyunting permintaannya, tepat yang dilarang kriteria
            // blueprint.
            $records = $this->milikRuang($ruang)
                ->whereIn('id', $recordIds)
                ->get();

            $berubah = 0;

            foreach ($records as $record) {
                $lama = $record->attendance_status;

                if ($lama === $status) {
                    continue;
                }

                $record->forceFill([
                    'attendance_status' => $status,
                    'verified_by' => $konteks->penggunaId(),
                    'verified_at' => now(),
                    'pelaku_label' => $konteks->pelakuLabel(),
                ])->save();

                $this->catatLog($record, $lama, $status, $konteks, $alasan);
                $berubah++;
            }

            return $berubah;
        });
    }

    /**
     * Kembalikan satu peserta ke `pending`.
     *
     * Ada karena salah tandai pasti terjadi - pengawas memanggil nama yang
     * mirip, atau menekan baris yang salah saat daftarnya bergeser. Yang
     * TIDAK ada adalah menghapus jejaknya: pengembalian menulis log yang
     * menyatakan status sebelumnya, sehingga "pernah ditandai hadir lalu
     * dikembalikan" tetap terbaca.
     */
    public function kembalikan(
        AttendanceRecord $record,
        KonteksRuang $konteks,
        string $alasan,
    ): AttendanceRecord {
        return $this->catat($record, StatusPresensi::Pending, $konteks, $alasan);
    }

    /**
     * Seluruh presensi satu ruang-sesi, beserta penempatan dan siswanya.
     *
     * Urutannya abjad nama siswa, bukan urutan id: pengawas memanggil nama
     * dengan mata, dan daftar yang urutannya berubah-ubah setiap kali laman
     * disegarkan membuatnya kehilangan tempat.
     *
     * @return Collection<int, AttendanceRecord>
     */
    public function daftarRuang(ExamSessionRoom $ruang): Collection
    {
        return $this->milikRuang($ruang)
            ->with([
                'penempatan.student:id,name',
                'penempatan.student.studentProfile:id,user_id,nis',
                'penempatan.pesertaEvent.kelas:id,name',
                'penempatan.exam:id,title,allow_late_entry',
                'log' => fn ($q) => $q->orderByDesc('id'),
            ])
            ->get()
            ->sortBy(fn (AttendanceRecord $r) => $r->penempatan?->student?->name)
            ->values();
    }

    /**
     * Rekap satu ruang-sesi untuk kepala laman: berapa hadir, berapa belum.
     *
     * @return array{total: int, hadir: int, terlambat: int, tidak_hadir: int, izin: int, pending: int}
     */
    public function rekap(ExamSessionRoom $ruang): array
    {
        return $this->rekapDari($this->milikRuang($ruang)->get());
    }

    /**
     * Rekap dari baris presensi yang SUDAH dimuat pemanggil.
     *
     * ---------------------------------------------------------------------
     * Mengapa dipisah dari `rekap()`, bukan ditambahkan sebagai cabang
     * ---------------------------------------------------------------------
     * Papan presensi Admin menampilkan SELURUH ruang satu event, dan ia
     * sudah memuat tabel presensinya sekali di awal laman - satu kueri
     * untuk event itu. Memanggil `rekap()` per ruang di sana bukan sekadar
     * N+1: ia membaca ULANG dari basis data baris yang sudah ada di memori.
     *
     * Karena itu penghitungannya dipisah dari pengambilannya. `rekap()`
     * tetap ada dan tetap satu kueri, karena laman pengawas hanya punya
     * satu ruang dan tidak punya alasan memuat lebih banyak.
     *
     * ---------------------------------------------------------------------
     * `total` menghitung baris yang ADA, bukan jumlah peserta
     * ---------------------------------------------------------------------
     * Arti ini dipertahankan persis dari `rekap()`, dan itu bukan detail:
     * penempatan yang belum melahirkan baris presensi tidak ikut dihitung.
     * Menghitungnya dari jumlah peserta akan mengubah "12/30 hadir" menjadi
     * "12/32" pada event yang punya penempatan tanpa baris - sebuah
     * perubahan diam-diam pada papan yang dibaca pengawas saat ujian
     * berlangsung, pada angka yang menentukan apakah ia perlu mencari anak
     * yang hilang.
     *
     * @param  Collection<int, AttendanceRecord>  $catatan
     * @return array{total: int, hadir: int, terlambat: int, tidak_hadir: int, izin: int, pending: int}
     */
    public function rekapDari(Collection $catatan): array
    {
        $hitung = fn (StatusPresensi $s) => $catatan
            ->filter(fn (AttendanceRecord $r) => $r->attendance_status === $s)
            ->count();

        return [
            'total' => $catatan->count(),
            'hadir' => $hitung(StatusPresensi::Hadir),
            'terlambat' => $hitung(StatusPresensi::Terlambat),
            'tidak_hadir' => $hitung(StatusPresensi::TidakHadir),
            'izin' => $hitung(StatusPresensi::Izin),
            'pending' => $hitung(StatusPresensi::Pending),
        ];
    }

    /**
     * Query presensi yang SUDAH tersaring ke satu ruang-sesi.
     *
     * Penyaringan menempuh penempatannya, karena tabel presensi tidak punya
     * `exam_session_room_id` - lihat catatan di AttendanceRecord. Satu tempat,
     * supaya tidak ada query presensi yang lupa menyaring.
     *
     * @return Builder<AttendanceRecord>
     */
    private function milikRuang(ExamSessionRoom $ruang): Builder
    {
        return AttendanceRecord::query()->whereIn(
            'exam_participant_id',
            ExamParticipant::query()
                ->where('exam_session_room_id', $ruang->id)
                ->pluck('id'),
        );
    }

    private function catatLog(
        AttendanceRecord $record,
        StatusPresensi $lama,
        StatusPresensi $baru,
        KonteksRuang $konteks,
        ?string $alasan,
    ): void {
        AttendanceLog::create([
            'school_id' => $record->school_id,
            'attendance_record_id' => $record->id,
            'changed_by' => $konteks->penggunaId(),
            // NOT NULL, dan itu disengaja: baris log yang tidak menyebut
            // pelakunya adalah baris yang tidak bisa dipertanggungjawabkan.
            'pelaku_label' => $konteks->pelakuLabel(),
            'old_status' => $lama,
            'new_status' => $baru,
            'reason' => $alasan,
            'created_at' => now(),
        ]);
    }
}

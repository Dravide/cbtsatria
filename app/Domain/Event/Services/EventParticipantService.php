<?php

namespace App\Domain\Event\Services;

use App\Domain\Academic\Models\ClassEnrollment;
use App\Domain\Event\Models\EventParticipant;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Penarikan peserta dari keanggotaan kelas - PRD §6.7, wireframe plate 14.
 *
 * ---------------------------------------------------------------------
 * Empat pemeriksaan PRD §6.7, dan di mana masing-masing ditegakkan
 * ---------------------------------------------------------------------
 * PRD §6.7 meminta sistem memeriksa empat hal. Yang penting bukan keempat
 * pemeriksaannya, melainkan bahwa ketiganya bisa ditegakkan tanpa satu pun
 * query manual - scope tenant yang melakukannya:
 *
 *   1. "Siswa berasal dari sekolah yang sama" - dijaga composite FK.
 *      Baris event_participants yang menunjuk siswa sekolah lain mustahil
 *      dibuat, bukan sekadar dicegah.
 *   2. "Siswa terdaftar pada kelas yang dipilih" - dicari lewat
 *      ClassEnrollment yang ter-scope, sehingga keanggotaan sekolah lain
 *      tidak pernah terlihat dan siswa sekolah lain otomatis tersaring.
 *   3. "Siswa tidak terdaftar ganda" - dijaga unique(school_id, event_id,
 *      student_id).
 *   4. "Status siswa aktif" - diperiksa eksplisit di bawah, karena tidak ada
 *      constraint yang bisa menyatakannya.
 *
 * ---------------------------------------------------------------------
 * Tarik ulang tidak menghapus peserta manual
 * ---------------------------------------------------------------------
 * Kolom `source` yang membedakannya. Menarik ulang daftar kelas:
 *
 *   - menambah peserta baru dari kelas yang kini ikut;
 *   - menandai "keluar" peserta BERSUMBER KELAS yang kelasnya tidak lagi
 *     ikut - tanpa menghapusnya, karena peserta yang pernah terdaftar lalu
 *     keluar adalah riwayat, bukan kesalahan;
 *   - tidak menyentuh peserta bersumber manual sama sekali.
 *
 * Item ketiga adalah yang paling mudah salah. Peserta manual adalah
 * pengecualian yang dibuat Admin dengan niat; menghapusnya saat kelas ditarik
 * ulang berarti Admin mengulang pekerjaannya setiap kali daftar kelas
 * berubah, dan ia tidak akan tahu mengapa.
 */
class EventParticipantService
{
    /**
     * Tarik peserta dari seluruh kelas yang ditetapkan ke event.
     *
     * @return array{baru: int, keluar: int, total: int}
     */
    public function tarikDariKelas(SchoolEvent $event): array
    {
        return DB::transaction(function () use ($event) {
            $kelasId = $event->classes()->pluck('class_id')->all();

            if ($kelasId === []) {
                throw new DomainException(
                    'Belum ada kelas yang ditetapkan ke event ini. Tetapkan kelasnya lebih dahulu.',
                );
            }

            // Siswa aktif yang terdaftar di salah satu kelas tersebut.
            $anggota = ClassEnrollment::query()
                ->whereIn('class_id', $kelasId)
                ->where('status', 'aktif')
                ->with('student:id,is_active')
                ->get();

            $sekarang = now();

            $baru = 0;

            foreach ($anggota as $baris) {
                // Pemeriksaan keempat PRD §6.7 - status siswa aktif. Ia tidak
                // bisa dijaga constraint apa pun, dan siswa yang dinonaktifkan
                // di tengah tahun tetap punya baris keanggotaan kelas.
                if (! $baris->student?->is_active) {
                    continue;
                }

                // updateOrCreate, bukan create: siswa yang pernah keluar dari
                // kelas ini lalu masuk kembali tidak boleh gagal pada unique
                // constraint. Ia dikembalikan ke status terdaftar.
                $peserta = EventParticipant::updateOrCreate(
                    [
                        'school_id' => $event->school_id,
                        'event_id' => $event->id,
                        'student_id' => $baris->student_id,
                    ],
                    [
                        'class_id' => $baris->class_id,
                        'source' => EventParticipant::SUMBER_KELAS,
                        'status' => EventParticipant::STATUS_TERDAFTAR,
                        'registered_at' => $sekarang,
                    ],
                );

                if ($peserta->wasRecentlyCreated) {
                    $baru++;
                }
            }

            // Peserta bersumber KELAS yang kelasnya tidak lagi ikut - atau
            // yang pindah kelas. Peserta manual tidak pernah masuk daftar ini
            // karena where('source', ...) di bawah.
            $keluar = EventParticipant::query()
                ->where('event_id', $event->id)
                ->where('source', EventParticipant::SUMBER_KELAS)
                ->where('status', EventParticipant::STATUS_TERDAFTAR)
                ->whereNotIn('class_id', $kelasId)
                ->update(['status' => EventParticipant::STATUS_DIKECUALIKAN]);

            return [
                'baru' => $baru,
                'keluar' => $keluar,
                'total' => EventParticipant::query()
                    ->where('event_id', $event->id)
                    ->where('status', EventParticipant::STATUS_TERDAFTAR)
                    ->count(),
            ];
        });
    }

    /**
     * Tambah peserta secara manual.
     *
     * Diperiksa: siswa benar-benar ada di sekolah ini (scope), berperan
     * Siswa, dan belum terdaftar di event ini. Yang ketiga akan ditolak
     * unique constraint juga, tetapi galat basis data bukan cara menjelaskan
     * aturan kepada Admin.
     */
    public function tambahManual(SchoolEvent $event, int $studentId, ?int $classId = null): EventParticipant
    {
        // findOrFail lewat scope: id milik sekolah lain tidak ditemukan, dan
        // permintaannya berakhir 404 - bukan 403 yang membuktikan barisnya ada.
        $siswa = User::findOrFail($studentId);

        if ($siswa->role !== Role::Student) {
            throw new DomainException("{$siswa->name} bukan siswa.");
        }

        if (! $siswa->is_active) {
            throw new DomainException("Akun {$siswa->name} sedang nonaktif.");
        }

        $sudah = EventParticipant::query()
            ->where('event_id', $event->id)
            ->where('student_id', $studentId)
            ->exists();

        if ($sudah) {
            throw new DomainException("{$siswa->name} sudah ada di daftar peserta event ini.");
        }

        // Kelas yang dikirim harus benar-benar ikut event ini. Menyimpan
        // class_id sembarang akan membuat kolom Kelas di tabel plate 14
        // menampilkan kelas yang tidak ada hubungannya dengan event.
        if ($classId !== null && ! $event->classes()->where('class_id', $classId)->exists()) {
            throw new DomainException('Kelas itu tidak termasuk kelas peserta event ini.');
        }

        return EventParticipant::create([
            'school_id' => $event->school_id,
            'event_id' => $event->id,
            'student_id' => $studentId,
            'class_id' => $classId,
            'source' => EventParticipant::SUMBER_MANUAL,
            'status' => EventParticipant::STATUS_TERDAFTAR,
            'registered_at' => now(),
        ]);
    }

    /**
     * Kecualikan atau kembalikan sekumpulan peserta.
     *
     * @param  array<int, int>  $pesertaId
     * @return int Jumlah baris yang berubah.
     */
    public function setStatus(array $pesertaId, string $status): int
    {
        if (! in_array($status, [
            EventParticipant::STATUS_TERDAFTAR,
            EventParticipant::STATUS_DIKECUALIKAN,
        ], true)) {
            throw new DomainException("Status peserta '{$status}' tidak dikenal.");
        }

        // Scope tetap berlaku: id milik sekolah lain tidak akan cocok, dan
        // permintaan itu berakhir "0 baris berubah" - bukan galat yang
        // membocorkan bahwa barisnya ada.
        return EventParticipant::query()
            ->whereIn('id', $pesertaId)
            ->update(['status' => $status]);
    }
}

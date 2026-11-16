<?php

namespace App\Domain\Exam\Services;

use App\Domain\Attendance\Contracts\PemindahKeSusulan;
use App\Domain\Event\Enums\EventStatus;
use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSession;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Ujian susulan: gelombang pengganti pada ujian yang sama - PRD §9.3, plate 16.
 *
 * =====================================================================
 * Sesi baru, bukan ujian baru - dan seluruh mesin sesi ikut gratis
 * =====================================================================
 * Susulan adalah sesi KEDUA (atau ketiga) pada `exam_id` yang sama, bertanda
 * `jenis = susulan`. Nilai, analisis butir, laporan, dan publikasi semuanya
 * berkunci pada `exam_id`, sehingga nilai susulan menyatu ke baris mapel yang
 * sama tanpa satu pun kode penggabungan. Token, pengawas, presensi, dan denah
 * juga sudah menempel pada SESI, sehingga portal pengawas bekerja apa adanya.
 *
 * =====================================================================
 * Siswa DIPINDAHKAN, bukan ditambahkan - dan itu aturan skema
 * =====================================================================
 * `exam_participants` punya `unique(school_id, exam_id, student_id)`: satu
 * siswa satu penempatan per ujian. Karena itu susulan tidak menambah baris
 * penempatan kedua; ia memindahkan `exam_session_id` baris yang sudah ada ke
 * sesi susulan. Presensinya di sesi utama direset lewat koreksi administratif
 * berlog (lihat `PemindahKeSusulan`), sehingga pengawas susulan menerima baris
 * `pending` yang bersih.
 *
 * =====================================================================
 * Sesi susulan dibuat SEBELUM sesi terakhir ditutup, dan itu menahan event
 * =====================================================================
 * `SesiService::tutup()` memindahkan event ke `Selesai` begitu `semuaTuntas()`
 * - tidak ada sesi Draft/Siap/Berjalan pada ujiannya. Sesi susulan yang lahir
 * sebagai `Draft` membuat `semuaTuntas()` bernilai false, sehingga event TETAP
 * `Berjalan` sampai susulannya tuntas. Itulah cara susulan "menahan" event
 * tanpa menyentuh graf `EventStatus` (yang tidak punya `Selesai -> Berjalan`).
 *
 * Karena itu `buatSesi()` MENOLAK bila eventnya sudah final (`selesai`,
 * `arsip`, `batal`): pada keadaan itu satu-satunya jalan keluar adalah membuka
 * kembali event lewat `Arsip -> Berjalan`, bukan menyelundupkan sesi yang akan
 * berjalan di bawah event yang sistemnya sendiri anggap selesai.
 */
class ExamSusulanService
{
    public function __construct(private readonly SesiService $sesi) {}

    /**
     * Buat sesi susulan pada satu ujian.
     *
     * @param  array<string, mixed>  $data  Tiga waktu + event_room_id, bentuk
     *                                      yang sama dengan `SesiService::buat()`.
     */
    public function buatSesi(Exam $ujian, array $data): ExamSession
    {
        $event = $ujian->event;

        if ($event !== null && in_array($event->status, [
            EventStatus::Completed,
            EventStatus::Archived,
            EventStatus::Cancelled,
        ], true)) {
            // Event final (Selesai/Arsip/Batal) tidak bisa menampung sesi baru
            // yang akan berjalan. Pesannya menyebut jalan keluarnya.
            throw new DomainException(
                "Event berstatus {$event->status->label()} dan tidak bisa menampung sesi susulan. ".
                'Buat sesi susulan sebelum sesi terakhir ditutup, atau buka kembali event lewat Arsip → Berjalan.',
            );
        }

        return $this->sesi->buat($ujian, [
            ...$data,
            'jenis' => 'susulan',
        ]);
    }

    /**
     * Kandidat peserta susulan untuk satu ujian - lihat `PemindahKeSusulan`.
     *
     * Diteruskan lewat kontrak supaya controller Exam tidak menyebut model
     * Attendance sama sekali.
     *
     * @return array<int, array{peserta_id: int, nis: ?string, nama: string, kelas: ?string, status: string, status_label: string}>
     */
    public function kandidat(Exam $ujian): array
    {
        return app(PemindahKeSusulan::class)->kandidat($ujian);
    }

    /**
     * Pindahkan peserta ke sesi susulan.
     *
     * @param  array<int, int>  $pesertaIds  Id `exam_participants`.
     * @return int Jumlah peserta yang benar-benar dipindahkan.
     */
    public function pindahkan(Exam $ujian, ExamSession $tujuan, array $pesertaIds, string $alasan): int
    {
        if (! $tujuan->susulan() || (int) $tujuan->exam_id !== (int) $ujian->id) {
            throw new DomainException('Sesi tujuan bukan sesi susulan pada ujian ini.');
        }

        $alasan = trim($alasan);

        if ($alasan === '') {
            throw new DomainException('Alasan pemindahan ke sesi susulan wajib diisi.');
        }

        $pemindah = app(PemindahKeSusulan::class);

        return DB::transaction(function () use ($ujian, $tujuan, $pesertaIds, $alasan, $pemindah) {
            $penempatan = ExamParticipant::query()
                ->where('exam_id', $ujian->id)
                ->whereIn('id', $pesertaIds)
                ->get();

            $dipindah = 0;

            foreach ($penempatan as $p) {
                // Sudah di sesi susulan ini: lewati diam-diam (idempoten).
                if ((int) $p->exam_session_id === (int) $tujuan->id) {
                    continue;
                }

                // Reset presensi DULU: `pindahkan()` menolak peserta yang sudah
                // punya attempt, dan penolakan itu harus terjadi sebelum
                // `exam_session_id` berubah - supaya tidak ada baris yang
                // setengah dipindah saat satu peserta ditolak.
                $pemindah->pindahkan($p, $tujuan, $alasan);

                $p->forceFill([
                    'exam_session_id' => $tujuan->id,
                    'exam_session_room_id' => null,
                    'assigned_at' => null,
                ])->save();

                $dipindah++;
            }

            return $dipindah;
        });
    }
}

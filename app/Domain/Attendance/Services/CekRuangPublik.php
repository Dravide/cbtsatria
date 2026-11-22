<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Academic\Models\ClassEnrollment;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Event\Enums\EventStatus;
use App\Domain\Event\Models\EventParticipant;
use App\Domain\Event\Models\EventRoom;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Enums\StatusSesi;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Services\NomorKursi;
use App\Domain\Exam\Services\PetaKursiEvent;
use App\Domain\Identity\Models\StudentProfile;
use App\Domain\Tenancy\Models\School;
use Illuminate\Support\Collection;

/**
 * Cek ruang & nomor kursi publik - `/ruang/{slug}`, TANPA login.
 *
 * =====================================================================
 * Batas privasi: HANYA SATU SISWA, setelah kelas + NIS cocok
 * =====================================================================
 * Laman ini tidak pernah mengirim daftar siswa sekolah. Pengunjung harus
 * memilih kelas dan memasukkan NIS (atau NISN) yang tepat. Bila cocok,
 * yang dikembalikan hanya penempatan ruang, nomor kursi (meja), dan
 * jadwal sesi milik siswa itu sendiri.
 *
 * Nilai (`exam_results`), token (`exam_tokens`), catatan kehadiran, dan
 * attempt TIDAK pernah dibaca maupun dikirim.
 *
 * =====================================================================
 * Nomor kursi memakai `NomorKursi` & `PetaKursiEvent`
 * =====================================================================
 * Aturan penomoran yang sama persis dengan denah, tablo cetak, papan
 * pengawas, dan laman siswa - tidak ada perhitungan kursi tandingan.
 */
class CekRuangPublik
{
    public function __construct(
        private readonly PetaKursiEvent $petaKursi,
    ) {}

    /**
     * Daftar kelas yang memiliki peserta pada event aktif/siap/selesai,
     * atau kelas aktif di sekolah bila belum ada peserta event.
     *
     * @return array<int, array{id: int, nama: string, tingkat: ?string}>
     */
    public function daftarKelas(School $sekolah): array
    {
        return SchoolClass::query()
            ->withoutGlobalScopes()
            ->where('school_id', $sekolah->id)
            ->where('status', 'aktif')
            ->orderBy('grade_level')
            ->orderBy('name')
            ->get(['id', 'name', 'grade_level'])
            ->map(fn (SchoolClass $k) => [
                'id' => (int) $k->id,
                'nama' => $k->name,
                'tingkat' => $k->grade_level,
            ])
            ->values()
            ->all();
    }

    /**
     * Cari penempatan ruang & nomor kursi untuk SATU siswa berdasarkan
     * pasangan (kelas_id, nis/nisn) di sekolah ini.
     *
     * @return array<string, mixed>|null Null bila tidak cocok.
     */
    public function cari(School $sekolah, int $kelasId, string $nis): ?array
    {
        $nisBersih = trim($nis);

        if ($nisBersih === '') {
            return null;
        }

        $kelas = SchoolClass::query()
            ->withoutGlobalScopes()
            ->where('school_id', $sekolah->id)
            ->whereKey($kelasId)
            ->first(['id', 'name', 'grade_level']);

        if ($kelas === null) {
            return null;
        }

        $profil = StudentProfile::query()
            ->withoutGlobalScopes()
            ->where('school_id', $sekolah->id)
            ->where(function ($q) use ($nisBersih) {
                $q->where('nis', $nisBersih)->orWhere('nisn', $nisBersih);
            })
            ->with(['user' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'school_id', 'name', 'is_active')])
            ->first();

        if ($profil === null || $profil->user === null) {
            return null;
        }

        $studentId = (int) $profil->user_id;

        // Pastikan siswa memang tercatat pada kelas yang dipilih (baik di
        // keanggotaan kelas maupun di kepesertaan event).
        $diKelas = ClassEnrollment::query()
            ->withoutGlobalScopes()
            ->where('school_id', $sekolah->id)
            ->where('class_id', $kelas->id)
            ->where('student_id', $studentId)
            ->exists();

        if (! $diKelas) {
            $diKelas = EventParticipant::query()
                ->withoutGlobalScopes()
                ->where('school_id', $sekolah->id)
                ->where('class_id', $kelas->id)
                ->where('student_id', $studentId)
                ->where('status', EventParticipant::STATUS_TERDAFTAR)
                ->exists();
        }

        if (! $diKelas) {
            return null;
        }

        $events = SchoolEvent::query()
            ->withoutGlobalScopes()
            ->where('school_id', $sekolah->id)
            ->whereIn('status', [
                EventStatus::Ongoing->value,
                EventStatus::Ready->value,
                EventStatus::Completed->value,
            ])
            ->whereHas('participants', function ($q) use ($sekolah, $studentId) {
                $q->withoutGlobalScopes()
                    ->where('school_id', $sekolah->id)
                    ->where('student_id', $studentId)
                    ->where('status', EventParticipant::STATUS_TERDAFTAR);
            })
            ->orderByRaw(
                'CASE status WHEN ? THEN 1 WHEN ? THEN 2 ELSE 3 END',
                [EventStatus::Ongoing->value, EventStatus::Ready->value],
            )
            ->orderBy('start_at')
            ->limit(JadwalPublik::MAKS_EVENT)
            ->get();

        $hasilEvent = $events
            ->map(fn (SchoolEvent $event) => $this->susunEventSiswa($sekolah, $event, $studentId))
            ->values()
            ->all();

        return [
            'nama' => $profil->user->name,
            'nis' => $profil->nis ?? $profil->nisn,
            'kelas' => $kelas->name,
            'events' => $hasilEvent,
        ];
    }

    /** @return array<string, mixed> */
    private function susunEventSiswa(School $sekolah, SchoolEvent $event, int $studentId): array
    {
        $penempatan = ExamParticipant::query()
            ->withoutGlobalScopes()
            ->where('school_id', $sekolah->id)
            ->where('student_id', $studentId)
            ->where('status', ExamParticipant::STATUS_TERDAFTAR)
            ->whereHas('exam', function ($q) use ($sekolah, $event) {
                $q->withoutGlobalScopes()
                    ->where('school_id', $sekolah->id)
                    ->where('event_id', $event->id);
            })
            ->with([
                'exam' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'school_id', 'event_id', 'subject_id', 'title'),
                'exam.subject' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'name'),
                'session' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'exam_id', 'session_number', 'jenis', 'access_opens_at', 'hard_end_at', 'status'),
                'room' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'exam_session_id', 'event_room_id'),
                'room.eventRoom' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'event_id', 'room_id'),
                'room.eventRoom.room' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'code', 'name', 'location'),
            ])
            ->get()
            ->filter(fn (ExamParticipant $p) => $p->session !== null && $p->session->status !== StatusSesi::Batal)
            ->sortBy(fn (ExamParticipant $p) => [
                $p->session?->access_opens_at?->getTimestamp() ?? PHP_INT_MAX,
                (int) ($p->session?->session_number ?? 0),
            ])
            ->values();

        [$ruangUtama, $kursiUtama] = $this->tentukanRuangDanKursi($event, $studentId, $penempatan);

        $jadwal = $penempatan->map(function (ExamParticipant $p) {
            $sesi = $p->session;
            $ruang = $p->room?->eventRoom?->room;
            $mulai = $sesi?->access_opens_at?->format('H:i') ?? '--:--';
            $selesai = $sesi?->hard_end_at?->format('H:i') ?? '--:--';

            return [
                'id' => (int) $p->id,
                'ujian' => $p->exam?->title,
                'mapel' => $p->exam?->subject?->name,
                'hari_tanggal' => $sesi?->access_opens_at?->locale('id')->translatedFormat('l, d M Y'),
                'jam' => "{$mulai}–{$selesai}",
                'sesi_label' => $sesi?->label(),
                'ruang_kode' => $ruang?->code,
                'status' => $sesi?->status->value,
                'status_label' => $sesi?->status->label(),
                'susulan' => $sesi?->susulan() ?? false,
            ];
        })->all();

        return [
            'id' => (int) $event->id,
            'nama' => $event->name,
            'status' => $event->status->value,
            'status_label' => $event->status->label(),
            'ruang' => $ruangUtama,
            'kursi' => $kursiUtama,
            'meja' => $kursiUtama !== null ? NomorKursi::meja($kursiUtama) : null,
            'jadwal' => $jadwal,
        ];
    }

    /**
     * Tentukan ruang event dan nomor kursi siswa (mengikuti `PetaKursiEvent`).
     *
     * @param  Collection<int, ExamParticipant>  $penempatan
     * @return array{0: array{kode: ?string, nama: ?string, lokasi: ?string}|null, 1: ?int}
     */
    private function tentukanRuangDanKursi(SchoolEvent $event, int $studentId, Collection $penempatan): array
    {
        /** @var ExamParticipant|null $denganRuang */
        $denganRuang = $penempatan->first(fn (ExamParticipant $p) => $p->room?->eventRoom?->room !== null);

        if ($denganRuang !== null) {
            $ruangModel = $denganRuang->room->eventRoom->room;
            $kursi = $this->petaKursi->kursiSatu($denganRuang);

            return [
                [
                    'kode' => $ruangModel->code,
                    'nama' => $ruangModel->name,
                    'lokasi' => $ruangModel->location,
                ],
                $kursi,
            ];
        }

        // Cadangan bila event sudah dikocok benihnya tetapi sesi belum seluruhnya disebar.
        $roster = $this->petaKursi->rosterEvent($event);

        if ($roster !== null) {
            foreach ($roster as $eventRoomId => $anggota) {
                foreach ($anggota as $baris) {
                    if ((int) $baris['student_id'] === $studentId) {
                        $eventRoom = EventRoom::query()
                            ->withoutGlobalScopes()
                            ->where('event_id', $event->id)
                            ->whereKey($eventRoomId)
                            ->with(['room' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'code', 'name', 'location')])
                            ->first();

                        $ruangModel = $eventRoom?->room;

                        return [
                            $ruangModel === null ? null : [
                                'kode' => $ruangModel->code,
                                'nama' => $ruangModel->name,
                                'lokasi' => $ruangModel->location,
                            ],
                            (int) $baris['kursi'],
                        ];
                    }
                }
            }
        }

        return [null, null];
    }
}

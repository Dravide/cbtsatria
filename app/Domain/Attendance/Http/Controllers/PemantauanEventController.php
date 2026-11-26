<?php

namespace App\Domain\Attendance\Http\Controllers;

use App\Domain\Attendance\Enums\PeranTandaTangan;
use App\Domain\Attendance\Enums\SisiFoto;
use App\Domain\Attendance\Enums\StatusPresensi;
use App\Domain\Attendance\Models\AttemptQuestion;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Models\ExamViolation;
use App\Domain\Attendance\Models\RoomSignal;
use App\Domain\Attendance\Models\SessionReport;
use App\Domain\Attendance\Services\InsidenService;
use App\Domain\Attendance\Services\KehadiranService;
use App\Domain\Attendance\Services\KonteksRuang;
use App\Domain\Attendance\Services\PencatatPelanggaran;
use App\Domain\Attendance\Services\PengendaliPengerjaan;
use App\Domain\Attendance\Services\PindahRuangDaruratService;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Event\Models\EventRoom;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Exam\Services\NomorKursi;
use App\Domain\Exam\Services\PetaKursiEvent;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Presensi dan monitoring lintas ruang untuk konsol Admin.
 * Koreksi presensi oleh Admin selalu mewajibkan alasan tertulis dan mencatat baris `attendance_logs` baru tanpa mengubah baris lama.
 */
class PemantauanEventController
{
    public function __construct(
        private readonly KehadiranService $kehadiran,
        private readonly InsidenService $insiden,
        private readonly NomorKursi $kursi,
        private readonly PetaKursiEvent $peta,
        private readonly PencatatPelanggaran $pencatat,
        private readonly PindahRuangDaruratService $pindahDarurat,
    ) {}

    /**
     * Titik masuk presensi event (daftar sesi beserta rekap presensi, berita acara, dan insiden).
     */
    public function presensi(SchoolEvent $event): Response
    {
        Gate::authorize('view', $event);

        $ruangan = $this->ruangan($event);

        // Baca berita acara sekali untuk seluruh halaman.
        $laporan = $this->beritaAcara($ruangan);

        return $this->daftarSesiPresensi($event, $ruangan, $laporan);
    }

    /**
     * Papan presensi seluruh ruang event; fallback ke per ruang-sesi jika event belum pernah dikocok (`rosterEvent()` null).
     */
    public function presensiRuang(SchoolEvent $event): Response
    {
        Gate::authorize('view', $event);

        $ruangan = $this->ruangan($event);
        $laporan = $this->beritaAcara($ruangan);
        $roster = $this->peta->rosterEvent($event);

        return $roster === null
            ? $this->presensiPerRuangSesi($event, $ruangan, $laporan)
            : $this->presensiPerRuangEvent($event, $ruangan, $roster, $laporan);
    }

    /**
     * Presensi satu sesi dengan baris per ruang-sesi (bukan ruang event).
     */
    public function presensiSesi(SchoolEvent $event, ExamSession $sesi): Response
    {
        Gate::authorize('view', $event);

        // Pastikan sesi milik event di URL (binding implisit hanya memeriksa tenant sekolah); kembalikan 404 agar tidak membocorkan eksistensi.
        abort_unless($sesi->exam?->event_id === $event->id, 404);

        $sesi->load(['exam:id,code,title,subject_id', 'exam.subject:id,name']);

        $ruangan = ExamSessionRoom::query()
            ->where('exam_session_id', $sesi->id)
            ->with([
                'eventRoom.room:id,code,name,location',
                'session.exam:id,code,title,subject_id',
                'session.exam.subject:id,name',
                'supervisors.supervisor:id,name',
                'participants:id,exam_session_room_id,student_id,school_id',
                'participants.student:id,name,school_id',
                'participants.student.studentProfile:id,user_id,nis',
            ])
            ->orderBy('id')
            ->get();

        $laporan = $this->beritaAcara($ruangan);

        return $this->presensiPerRuangSesi($event, $ruangan, $laporan, $sesi);
    }

    /**
     * Daftar sesi event beserta rekap presensi; seluruh data dibaca sekali di awal agar jumlah query konstan (dijaga `PemantauanQueryTest`).
     *
     * @param  Collection<int, ExamSessionRoom>  $ruangan
     * @param  Collection<int, SessionReport>  $laporan  kunci: exam_session_room_id
     */
    private function daftarSesiPresensi(SchoolEvent $event, Collection $ruangan, Collection $laporan): Response
    {
        $records = AttendanceRecord::query()
            ->whereIn('exam_participant_id', $ruangan->flatMap->participants->pluck('id'))
            ->get()
            ->keyBy('exam_participant_id');

        $insidenPerRuang = $this->insiden->jumlahTerbukaPerRuang($ruangan);

        $sesi = ExamSession::query()
            ->whereIn('exam_id', $event->exams()->pluck('id'))
            ->with(['exam:id,code,title,subject_id', 'exam.subject:id,name'])
            ->orderBy('access_opens_at')
            ->orderBy('session_number')
            ->get();

        $baris = $sesi->map(function (ExamSession $s) use ($ruangan, $laporan, $records, $insidenPerRuang) {
            $milik = $ruangan->where('exam_session_id', $s->id)->values();

            $catatan = $milik
                ->flatMap->participants
                ->pluck('id')
                ->map(fn ($id) => $records->get($id))
                ->filter()
                ->values();

            $rekap = $this->kehadiran->rekapDari($catatan);

            // `total` dihitung dari peserta terdaftar di sesi (termasuk yang belum dipresensi), bukan jumlah baris presensi.
            $rekap['total'] = $milik->flatMap->participants->pluck('id')->unique()->count();
            $rekap['pending'] = $rekap['total'] - ($rekap['hadir'] + $rekap['terlambat'] + $rekap['tidak_hadir'] + $rekap['izin']);

            $berita = $milik
                ->map(fn (ExamSessionRoom $r) => $this->ringkasLaporan($r, $laporan->get($r->id)))
                ->values();

            return [
                'id' => $s->id,
                'label' => $s->label(),
                'ujian' => $s->exam?->subject?->name ?? $s->exam?->title,
                'judul_ujian' => $s->exam?->title,
                'tanggal' => $s->access_opens_at?->format('d M'),
                'jam' => $s->access_opens_at?->format('H:i'),
                'selesai' => $s->hard_end_at?->format('H:i'),
                'status' => $s->status->value,
                'status_label' => $s->status->label(),
                'jenis' => $s->jenis?->value ?? 'utama',
                'susulan' => $s->susulan(),
                'ruang' => $milik->count(),
                'ruang_tanpa_pengawas' => $milik
                    ->filter(fn (ExamSessionRoom $r) => $r->supervisors->isEmpty())
                    ->map(fn (ExamSessionRoom $r) => $r->eventRoom?->room?->code ?? 'R-?')
                    ->values()
                    ->all(),
                'rekap' => $rekap,
                'insiden' => $milik->sum(fn (ExamSessionRoom $r) => $insidenPerRuang[$r->id] ?? 0),
                'berita_acara' => [
                    'ada' => $berita->count(),
                    'final' => $berita->where('selesai', true)->count(),
                    'dibuka' => $berita->where('dibuka', true)->count(),
                ],
            ];
        })->values();

        return Inertia::render('Admin/Event/PresensiDaftarSesi', [
            'event' => $this->ringkas($event),
            'baris' => $baris->all(),
            'angka' => [
                'sesi' => $baris->count(),
                'belum_tuntas' => $baris->filter(fn (array $b) => $b['rekap']['pending'] > 0)->count(),
                'peserta' => $baris->sum(fn (array $b) => $b['rekap']['total']),
                'hadir' => $baris->sum(fn (array $b) => $b['rekap']['hadir']),
                'tanpa_pengawas' => $baris->sum(fn (array $b) => count($b['ruang_tanpa_pengawas'])),
                'insiden' => $baris->sum('insiden'),
            ],
            'mapel' => $this->mapelEvent($event),
            'jalur_sesi' => "/admin/events/{$event->id}/presensi/sesi",
            'jalur_ruang' => "/admin/events/{$event->id}/presensi/ruang",
        ]);
    }

    /**
     * Fallback presensi per ruang-sesi untuk event yang belum dikocok.
     *
     * @param  Collection<int, ExamSessionRoom>  $ruangan
     * @param  Collection<int, SessionReport>  $laporan  kunci: exam_session_room_id
     */
    private function presensiPerRuangSesi(SchoolEvent $event, Collection $ruangan, Collection $laporan, ?ExamSession $sesi = null): Response
    {
        // Muat seluruh presensi event dalam satu query untuk menghindari N+1 per ruang.
        $records = AttendanceRecord::query()
            ->whereIn('exam_participant_id', $ruangan->flatMap->participants->pluck('id'))
            ->get()
            ->keyBy('exam_participant_id');

        $ruangPeserta = $ruangan->flatMap(
            fn (ExamSessionRoom $r) => $r->participants->mapWithKeys(
                fn (ExamParticipant $p) => [$p->id => $r->id],
            ),
        );

        $recordsPerRuang = $records->groupBy(fn (AttendanceRecord $r) => $ruangPeserta->get($r->exam_participant_id));

        $insidenPerRuang = $this->insiden->jumlahTerbukaPerRuang($ruangan);

        $baris = $ruangan->map(function (ExamSessionRoom $ruang) use ($records, $recordsPerRuang, $insidenPerRuang, $laporan) {
            $rekap = $this->kehadiran->rekapDari(
                $recordsPerRuang->get($ruang->id, new Collection),
            );

            return [
                'ruang_id' => $ruang->id,
                'ruang' => $ruang->eventRoom?->room?->code ?? 'R-?',
                'ruang_nama' => $ruang->eventRoom?->room?->name,
                'lokasi' => $ruang->eventRoom?->room?->location,
                'sesi_id' => $ruang->session?->id,
                'sesi' => $ruang->session?->label(),
                'ujian' => $ruang->session?->exam?->title,
                'jam' => $ruang->session === null
                    ? null
                    : $ruang->session->access_opens_at->format('H:i').'–'.$ruang->session->hard_end_at->format('H:i'),
                'pengawas' => $ruang->supervisors
                    ->map(fn ($t) => $t->supervisor?->name)
                    ->filter()
                    ->values()
                    ->all(),
                'rekap' => $rekap,
                'insiden' => $insidenPerRuang[$ruang->id] ?? 0,
                'berita_acara' => [$this->ringkasLaporan($ruang, $laporan->get($ruang->id))],
                'peserta' => $this->peserta($ruang, $records),
            ];
        })->values();

        return $this->renderPresensi($event, $baris, $sesi);
    }

    /**
     * Presensi per ruang event dari hasil kocokan benih (`rosterEvent()`), termasuk peserta yang ujiannya belum punya sesi.
     * Query dijaga konstan oleh `PemantauanQueryTest`; jika siswa punya beberapa baris presensi di event ini, dipakai yang terbaru.
     *
     * @param  Collection<int, ExamSessionRoom>  $ruangan  Ruang sesi event ini.
     * @param  array<int, array<int, array{id: ?int, student_id: int, kursi: int, nama: ?string, nis: ?string, kelas: ?string}>>  $roster
     * @param  Collection<int, SessionReport>  $laporan  kunci: exam_session_room_id
     */
    private function presensiPerRuangEvent(SchoolEvent $event, Collection $ruangan, array $roster, Collection $laporan): Response
    {
        // Saring penempatan ke ujian milik event ini saja agar presensi siswa di event lain tidak terbawa.
        $idUjian = $event->exams()->pluck('id')->all();

        $idPenempatan = $this->peta
            ->penempatanPerSiswa($this->siswaDariRoster($roster))
            ->map(fn (\Illuminate\Support\Collection $baris) => $baris
                ->filter(fn (ExamParticipant $p) => in_array((int) $p->exam_id, $idUjian, true))
                ->values());

        // Pakai `flatMap` (bukan `flatten(1)`) karena nilainya Collection, bukan array biasa.
        $records = AttendanceRecord::query()
            ->whereIn('exam_participant_id', $idPenempatan->flatMap(fn ($b) => $b->pluck('id')))
            ->get()
            ->keyBy('exam_participant_id');

        // Baca dari EventRoom agar ruang yang ujiannya belum dijadwalkan tetap tampil.
        $fisik = EventRoom::query()
            ->where('event_id', $event->id)
            ->with('room:id,code,name,location')
            ->get()
            ->keyBy('id');

        $insidenPerSesi = $this->insiden->jumlahTerbukaPerRuang($ruangan);
        $insidenPerRuang = $ruangan
            ->groupBy(fn (ExamSessionRoom $r) => (int) $r->event_room_id)
            // Parameter closure tanpa tipe karena `map()` Eloquent Collection mengembalikan Support\Collection saat hasil bukan model.
            ->map(fn ($sesi) => $sesi->sum(fn (ExamSessionRoom $r) => $insidenPerSesi[$r->id] ?? 0));

        $pengawasPerRuang = $ruangan
            ->groupBy(fn (ExamSessionRoom $r) => (int) $r->event_room_id)
            ->map(fn ($sesi) => $sesi
                ->flatMap(fn (ExamSessionRoom $r) => $r->supervisors->map(fn ($t) => $t->supervisor?->name))
                ->filter()
                ->unique()
                ->values()
                ->all());

        $ruangSesiPerRuang = $ruangan->groupBy(fn (ExamSessionRoom $r) => (int) $r->event_room_id);

        $baris = [];

        foreach ($roster as $idRuangEvent => $anggota) {
            $ruang = $fisik->get((int) $idRuangEvent);
            $sesi = $ruangan->firstWhere('event_room_id', $idRuangEvent);

            $baris[] = [
                'ruang_id' => (int) $idRuangEvent,
                'ruang' => $ruang?->room?->code ?? 'R-?',
                'ruang_nama' => $ruang?->room?->name,
                'lokasi' => $ruang?->room?->location,
                'sesi_id' => $sesi?->session?->id,
                'sesi' => $sesi?->session?->label(),
                'ujian' => $sesi?->session?->exam?->title,
                'jam' => $sesi?->session === null
                    ? null
                    : $sesi->session->access_opens_at->format('H:i').'–'.$sesi->session->hard_end_at->format('H:i'),
                'punya_sesi' => $sesi !== null,
                'pengawas' => $pengawasPerRuang[$idRuangEvent] ?? [],
                'rekap' => $this->rekapRuangEvent($anggota, $idPenempatan, $records),
                'insiden' => (int) ($insidenPerRuang[$idRuangEvent] ?? 0),
                'berita_acara' => ($ruangSesiPerRuang->get((int) $idRuangEvent) ?? collect())
                    ->map(fn (ExamSessionRoom $r) => $this->ringkasLaporan($r, $laporan->get($r->id)))
                    ->values()
                    ->all(),
                'peserta' => $this->pesertaRuangEvent($anggota, $idPenempatan, $records),
            ];
        }

        return $this->renderPresensi($event, collect($baris));
    }

    /**
     * Hitung rekap ruang event atas seluruh penghuni roster; peserta tanpa baris presensi dimasukkan ke `pending`.
     *
     * @param  array<int, array{id: ?int, student_id: int, kursi: int, nama: ?string, nis: ?string, kelas: ?string}>  $anggota
     * @param  \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, ExamParticipant>>  $idPenempatan
     * @param  \Illuminate\Support\Collection<int, AttendanceRecord>  $records
     * @return array{total: int, hadir: int, terlambat: int, tidak_hadir: int, izin: int, pending: int}
     */
    private function rekapRuangEvent(array $anggota, \Illuminate\Support\Collection $idPenempatan, \Illuminate\Support\Collection $records): array
    {
        $catatan = collect($anggota)
            ->map(fn (array $p) => $this->recordUntuk($p['student_id'], $idPenempatan, $records))
            ->filter()
            ->values();

        $rekap = $this->kehadiran->rekapDari($catatan);

        $tanpaBaris = count($anggota) - $catatan->count();
        $rekap['pending'] += $tanpaBaris;
        $rekap['total'] = count($anggota);

        return $rekap;
    }

    /**
     * Ambil baris presensi terbaru satu siswa di antara penempatannya.
     *
     * @param  \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, ExamParticipant>>  $idPenempatan
     * @param  \Illuminate\Support\Collection<int, AttendanceRecord>  $records
     */
    private function recordUntuk(
        int $studentId,
        \Illuminate\Support\Collection $idPenempatan,
        \Illuminate\Support\Collection $records,
    ): ?AttendanceRecord {
        $baris = $idPenempatan->get($studentId);

        if ($baris === null) {
            return null;
        }

        return $baris
            ->map(fn (ExamParticipant $p) => $records->get($p->id))
            ->filter()
            ->sortByDesc(fn (AttendanceRecord $r) => $r->id)
            ->first();
    }

    /**
     * @param  array<int, array{id: ?int, student_id: int, kursi: int, nama: ?string, nis: ?string, kelas: ?string}>  $anggota
     * @param  \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, ExamParticipant>>  $idPenempatan
     * @param  \Illuminate\Support\Collection<int, AttendanceRecord>  $records
     * @return array<int, array<string, mixed>>
     */
    private function pesertaRuangEvent(array $anggota, \Illuminate\Support\Collection $idPenempatan, \Illuminate\Support\Collection $records): array
    {
        $baris = [];

        foreach ($anggota as $p) {
            $record = $this->recordUntuk($p['student_id'], $idPenempatan, $records);

            $baris[] = [
                // `peserta_id` bisa null jika ujian belum disebar; `student_id` selalu ada.
                'peserta_id' => $p['id'],
                'student_id' => $p['student_id'],
                'kursi' => $p['kursi'],
                'record_id' => $record?->id,
                'nama' => $p['nama'] ?? 'Peserta',
                'nis' => $p['nis'],
                'kelas' => $p['kelas'],
                'status' => $record?->attendance_status->value,
                'status_label' => $record === null
                    ? 'Belum ada sesi'
                    : $record->attendance_status->label(),
                'status_nada' => $record?->attendance_status->nada() ?? 'mute',
                'diverifikasi_oleh' => $record?->pelaku_label,
                'diverifikasi_jam' => $record?->verified_at?->format('H:i'),
            ];
        }

        return $baris;
    }

    /**
     * Seluruh `student_id` pada peta roster, tanpa duplikat.
     *
     * @param  array<int, array<int, array{student_id: int}>>  $roster
     * @return array<int, int>
     */
    private function siswaDariRoster(array $roster): array
    {
        $id = [];

        foreach ($roster as $anggota) {
            foreach ($anggota as $p) {
                $id[(int) $p['student_id']] = true;
            }
        }

        return array_keys($id);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $baris
     */
    private function renderPresensi(SchoolEvent $event, \Illuminate\Support\Collection $baris, ?ExamSession $sesi = null): Response
    {
        return Inertia::render('Admin/Event/Presensi', [
            'event' => $this->ringkas($event),
            'baris' => $baris->all(),

            // Null berarti papan seluruh event; eksplisit agar tidak ambigu dengan sesi yang belum punya ruang.
            'sesi' => $sesi === null ? null : [
                'id' => $sesi->id,
                'label' => $sesi->label(),
                'ujian' => $sesi->exam?->subject?->name ?? $sesi->exam?->title,
                'judul_ujian' => $sesi->exam?->title,
                'tanggal' => $sesi->access_opens_at?->format('d M'),
                'jam' => $sesi->access_opens_at?->format('H:i'),
                'selesai' => $sesi->hard_end_at?->format('H:i'),
                'status_label' => $sesi->status->label(),
                'susulan' => $sesi->susulan(),
            ],

            'jalur_daftar_sesi' => "/admin/events/{$event->id}/presensi",

            'angka' => [
                'ruang' => $baris->count(),
                'belum_tuntas' => $baris->filter(fn (array $b) => $b['rekap']['pending'] > 0)->count(),
                'hadir' => $baris->sum(fn (array $b) => $b['rekap']['hadir']),
                'peserta' => $baris->sum(fn (array $b) => $b['rekap']['total']),
                'insiden' => $baris->sum('insiden'),

                // Mencakup ruang yang berita acaranya belum dibuka maupun belum final.
                'berita_acara_belum' => $baris
                    ->filter(fn (array $b) => collect($b['berita_acara'])
                        ->contains(fn (array $l) => $l['selesai'] === false))
                    ->count(),
            ],

            'opsi' => StatusPresensi::options(),
            'mapel' => $this->mapelEvent($event),

            'tanpa_pengawas' => $baris
                ->filter(fn (array $b) => $b['pengawas'] === [])
                ->pluck('ruang')
                ->values()
                ->all(),
        ]);
    }

    /**
     * Papan monitoring lintas sesi; daftar anak dipisah ke `monitoringRuang()` agar payload dan query tetap konstan (dijaga `PemantauanQueryTest`).
     * Pelanggaran per ruang memakai kolom `violation_count` pada attempt tanpa mengueri `exam_violations`.
     */
    public function monitoring(SchoolEvent $event): Response
    {
        Gate::authorize('view', $event);

        $ruangan = $this->ruangan($event);
        $attempt = $this->attemptTerakhir($ruangan->flatMap->participants->pluck('id'));
        $insidenPerRuang = $this->insiden->jumlahTerbukaPerRuang($ruangan);

        $sesi = ExamSession::query()
            ->whereIn('exam_id', $event->exams()->pluck('id'))
            ->with(['exam:id,code,title,subject_id', 'exam.subject:id,name'])
            ->orderBy('access_opens_at')
            ->orderBy('session_number')
            ->get();

        $baris = $sesi->map(function (ExamSession $s) use ($ruangan, $attempt, $insidenPerRuang) {
            $milik = $ruangan->where('exam_session_id', $s->id)->values();
            $peserta = $milik->flatMap->participants;

            $cari = fn (callable $saring) => $peserta
                ->filter(fn (ExamParticipant $p) => $saring($attempt->get($p->id)))
                ->count();

            return [
                'id' => $s->id,
                'label' => $s->label(),
                'ujian' => $s->exam?->subject?->name ?? $s->exam?->title,
                'judul_ujian' => $s->exam?->title,
                'tanggal' => $s->access_opens_at?->format('d M'),
                'jam' => $s->access_opens_at?->format('H:i'),
                // Pakai `jam_selesai` agar tidak bentrok dengan kunci `selesai` (jumlah pengerjaan dikirim).
                'jam_selesai' => $s->hard_end_at?->format('H:i'),
                'status' => $s->status->value,
                'status_label' => $s->status->label(),
                'susulan' => $s->susulan(),
                'ruang' => $milik->count(),
                'ruang_tanpa_pengawas' => $milik
                    ->filter(fn (ExamSessionRoom $r) => $r->supervisors->isEmpty())
                    ->map(fn (ExamSessionRoom $r) => $r->eventRoom?->room?->code ?? 'R-?')
                    ->values()
                    ->all(),

                'peserta' => $peserta->count(),
                'mengerjakan' => $cari(fn (?ExamAttempt $a) => $a?->status === ExamAttempt::STATUS_BERJALAN),
                'selesai' => $cari(fn (?ExamAttempt $a) => $a?->status === ExamAttempt::STATUS_DIKIRIM),
                // Hanya peserta yang sedang mengerjakan (berlangsung) yang dapat dihitung terputus.
                'terputus' => $cari(fn (?ExamAttempt $a) => $a !== null && $a->status === ExamAttempt::STATUS_BERJALAN && ! $a->terhubung()),
                'pelanggaran' => $peserta->sum(fn (ExamParticipant $p) => $attempt->get($p->id)?->violation_count ?? 0),
                'terkunci' => $cari(fn (?ExamAttempt $a) => $a?->dikunci() ?? false),
                'insiden' => $milik->sum(fn (ExamSessionRoom $r) => $insidenPerRuang[$r->id] ?? 0),
            ];
        })->values();

        $terkunciDaftar = $this->daftarTerkunci($ruangan, $attempt);

        return Inertia::render('Admin/Event/MonitoringDaftarSesi', [
            'event' => $this->ringkas($event),
            'baris' => $baris->all(),
            'terkunci_daftar' => $terkunciDaftar,
            'angka' => [
                'sesi' => $baris->count(),
                'peserta' => $baris->sum('peserta'),
                'mengerjakan' => $baris->sum('mengerjakan'),
                'selesai' => $baris->sum('selesai'),
                'terputus' => $baris->sum('terputus'),
                'pelanggaran' => $baris->sum('pelanggaran'),
                'terkunci' => $baris->sum('terkunci'),
            ],
            'mapel' => $this->mapelEvent($event),
            'sinyal' => $this->sinyalAktif($ruangan),
            'jalur_sesi' => "/admin/events/{$event->id}/monitoring/sesi",
            'jalur_ruang' => "/admin/events/{$event->id}/monitoring/ruang",
        ]);
    }

    /** Daftar peserta yang pengerjaannya sedang terkunci pada event ini. */
    public function terkunci(SchoolEvent $event): Response
    {
        Gate::authorize('view', $event);

        $ruangan = $this->ruangan($event);
        $attempt = $this->attemptTerakhir($ruangan->flatMap->participants->pluck('id'));
        $daftar = $this->daftarTerkunci($ruangan, $attempt);

        return Inertia::render('Admin/Event/MonitoringTerkunci', [
            'event' => $this->ringkas($event),
            'baris' => $daftar,
            'angka' => [
                'terkunci' => count($daftar),
                'ruang_terdampak' => collect($daftar)->pluck('ruang_id')->unique()->count(),
            ],
        ]);
    }

    /**
     * @param  Collection<int, ExamSessionRoom>  $ruangan
     * @param  Collection<int, ExamAttempt>  $attempt
     * @return array<int, array<string, mixed>>
     */
    private function daftarTerkunci(Collection $ruangan, Collection $attempt): array
    {
        return $ruangan->flatMap(function (ExamSessionRoom $r) use ($attempt) {
            return $r->participants->map(function (ExamParticipant $p) use ($r, $attempt): ?array {
                $att = $attempt->get($p->id);
                if ($att === null || ! $att->dikunci()) {
                    return null;
                }

                return [
                    'peserta_id' => $p->id,
                    'nama' => $p->student?->name ?? 'Peserta',
                    'ruang_id' => $r->id,
                    'ruang' => $r->eventRoom?->room?->code ?? 'R-?',
                    'sesi' => $r->session?->label() ?? '—',
                    'ujian' => $r->session?->exam?->subject?->name ?? $r->session?->exam?->title,
                    'alasan' => $att->lock_reason,
                    'pelanggaran' => (int) $att->violation_count,
                    'dikunci_jam' => $att->locked_at?->format('H:i:s'),
                ];
            })->filter();
        })->values()->all();
    }

    public function monitoringRuangEvent(SchoolEvent $event): Response
    {
        Gate::authorize('view', $event);

        $ruangan = $this->ruangan($event);
        $peserta = $ruangan->flatMap->participants;

        $attempt = $this->attemptTerakhir($peserta->pluck('id'));
        $insidenPerRuang = $this->insiden->jumlahTerbukaPerRuang($ruangan);

        $baris = $ruangan->map(function (ExamSessionRoom $ruang) use ($attempt, $insidenPerRuang) {
            $anak = $ruang->participants;

            return [
                'ruang_id' => $ruang->id,
                'ruang' => $ruang->eventRoom?->room?->code ?? 'R-?',
                'ruang_nama' => $ruang->eventRoom?->room?->name,
                'sesi' => $ruang->session?->label(),
                'ujian' => $ruang->session?->exam?->title,
                'status_sesi' => $ruang->session?->status->value,
                'status_label' => $ruang->session?->status->label(),

                'peserta' => $anak->count(),
                'mengerjakan' => $anak->filter(
                    fn (ExamParticipant $p) => $attempt->get($p->id)?->status === ExamAttempt::STATUS_BERJALAN,
                )->count(),
                'selesai' => $anak->filter(
                    fn (ExamParticipant $p) => $attempt->get($p->id)?->status === ExamAttempt::STATUS_DIKIRIM,
                )->count(),
                'terputus' => $anak->filter(function (ExamParticipant $p) use ($attempt) {
                    $a = $attempt->get($p->id);

                    return $a !== null && $a->status === ExamAttempt::STATUS_BERJALAN && ! $a->terhubung();
                })->count(),
                'pelanggaran' => $anak->sum(fn (ExamParticipant $p) => $attempt->get($p->id)?->violation_count ?? 0),
                'terkunci' => $anak->filter(fn (ExamParticipant $p) => $attempt->get($p->id)?->dikunci() ?? false)->count(),
                'insiden' => $insidenPerRuang[$ruang->id] ?? 0,
            ];
        })->values();

        return $this->renderMonitoring($event, $baris, $peserta->count(), null, $ruangan);
    }

    /** Papan monitoring satu sesi dengan baris per ruang-sesi. */
    public function monitoringSesi(SchoolEvent $event, ExamSession $sesi): Response
    {
        Gate::authorize('view', $event);

        // Pastikan sesi milik event di URL; kembalikan 404 agar tidak membocorkan eksistensi lintas event.
        abort_unless($sesi->exam?->event_id === $event->id, 404);

        $sesi->load(['exam:id,code,title,subject_id', 'exam.subject:id,name']);

        $ruangan = ExamSessionRoom::query()
            ->where('exam_session_id', $sesi->id)
            ->with([
                'eventRoom.room:id,code,name,location',
                'session.exam:id,code,title,subject_id',
                'session.exam.subject:id,name',
                'supervisors.supervisor:id,name',
                'participants:id,exam_session_room_id,student_id,school_id',
            ])
            ->orderBy('id')
            ->get();

        $peserta = $ruangan->flatMap->participants;
        $attempt = $this->attemptTerakhir($peserta->pluck('id'));
        $insidenPerRuang = $this->insiden->jumlahTerbukaPerRuang($ruangan);

        $baris = $ruangan->map(function (ExamSessionRoom $ruang) use ($attempt, $insidenPerRuang) {
            $anak = $ruang->participants;

            return [
                'ruang_id' => $ruang->id,
                'ruang' => $ruang->eventRoom?->room?->code ?? 'R-?',
                'ruang_nama' => $ruang->eventRoom?->room?->name,
                'sesi' => $ruang->session?->label(),
                'ujian' => $ruang->session?->exam?->title,
                'status_sesi' => $ruang->session?->status->value,
                'status_label' => $ruang->session?->status->label(),

                'peserta' => $anak->count(),
                'mengerjakan' => $anak->filter(
                    fn (ExamParticipant $p) => $attempt->get($p->id)?->status === ExamAttempt::STATUS_BERJALAN,
                )->count(),
                'selesai' => $anak->filter(
                    fn (ExamParticipant $p) => $attempt->get($p->id)?->status === ExamAttempt::STATUS_DIKIRIM,
                )->count(),
                'terputus' => $anak->filter(function (ExamParticipant $p) use ($attempt) {
                    $a = $attempt->get($p->id);

                    return $a !== null && $a->status === ExamAttempt::STATUS_BERJALAN && ! $a->terhubung();
                })->count(),
                'pelanggaran' => $anak->sum(fn (ExamParticipant $p) => $attempt->get($p->id)?->violation_count ?? 0),
                'terkunci' => $anak->filter(fn (ExamParticipant $p) => $attempt->get($p->id)?->dikunci() ?? false)->count(),
                'insiden' => $insidenPerRuang[$ruang->id] ?? 0,
            ];
        })->values();

        return $this->renderMonitoring($event, $baris, $peserta->count(), $sesi);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $baris
     */
    private function renderMonitoring(
        SchoolEvent $event,
        \Illuminate\Support\Collection $baris,
        int $jumlahPeserta,
        ?ExamSession $sesi,
        ?Collection $ruangan = null,
    ): Response {
        $ruangIds = $ruangan !== null
            ? $ruangan->pluck('id')
            : $baris->pluck('ruang_id')->filter();

        return Inertia::render('Admin/Event/Monitoring', [
            'event' => $this->ringkas($event),
            'baris' => $baris->all(),
            'sinyal' => $this->sinyalAktifDariIds($ruangIds),

            'sesi' => $sesi === null ? null : [
                'id' => $sesi->id,
                'label' => $sesi->label(),
                'ujian' => $sesi->exam?->subject?->name ?? $sesi->exam?->title,
                'judul_ujian' => $sesi->exam?->title,
                'tanggal' => $sesi->access_opens_at?->format('d M'),
                'jam' => $sesi->access_opens_at?->format('H:i'),
                'selesai' => $sesi->hard_end_at?->format('H:i'),
                'status_label' => $sesi->status->label(),
                'susulan' => $sesi->susulan(),
            ],

            'jalur_daftar_sesi' => "/admin/events/{$event->id}/monitoring",
            'ruang_url' => "/admin/events/{$event->id}/monitoring/ruang",
            'tidak_hadir_url' => "/admin/events/{$event->id}/monitoring/tidak-hadir",
            'mapel' => $this->mapelEvent($event),
            'angka' => [
                'ruang' => $baris->count(),
                'peserta' => $jumlahPeserta,
                'mengerjakan' => $baris->sum('mengerjakan'),
                'selesai' => $baris->sum('selesai'),
                'terputus' => $baris->sum('terputus'),
                'pelanggaran' => $baris->sum('pelanggaran'),
                'terkunci' => $baris->sum('terkunci'),
            ],
        ]);
    }

    /**
     * Sinyal kendala infrastruktur aktif untuk daftar ruang-sesi.
     *
     * @param  Collection<int, ExamSessionRoom>  $ruangan
     * @return array<int, array<string, mixed>>
     */
    private function sinyalAktif(Collection $ruangan): array
    {
        return $this->sinyalAktifDariIds($ruangan->pluck('id'));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, mixed>  $ruangIds
     * @return array<int, array<string, mixed>>
     */
    private function sinyalAktifDariIds(\Illuminate\Support\Collection $ruangIds): array
    {
        if ($ruangIds->isEmpty()) {
            return [];
        }

        return RoomSignal::query()
            ->whereIn('exam_session_room_id', $ruangIds->all())
            ->where('status', RoomSignal::STATUS_AKTIF)
            ->with(['room.eventRoom.room:id,code,name,location', 'room.session:id,session_number'])
            ->latest('id')
            ->get()
            ->map(fn (RoomSignal $s) => [
                'id' => $s->id,
                'ruang' => $s->room?->eventRoom?->room?->code ?? 'Ruang',
                'ruang_nama' => $s->room?->eventRoom?->room?->name,
                'lokasi' => $s->room?->eventRoom?->room?->location,
                'jenis' => $s->jenis,
                'label' => $s->labelJenis(),
                'catatan' => $s->catatan,
                'pelapor' => $s->pelapor_nama,
                'jam' => $s->created_at?->format('H:i'),
            ])
            ->all();
    }

    /**
     * Rekap siswa berstatus `tidak_hadir` dan `izin` dari `attendance_records` (mengecualikan `pending`).
     */
    public function rekapTidakHadir(SchoolEvent $event): Response
    {
        Gate::authorize('view', $event);

        $idUjian = $event->exams()->pluck('id');

        // Mulai dari `attendance_records` karena `ExamParticipant` sengaja tidak punya relasi ke Attendance (dijaga `DomainBoundariesTest`).
        $presensi = AttendanceRecord::query()
            ->whereIn('attendance_status', [
                StatusPresensi::TidakHadir->value,
                StatusPresensi::Izin->value,
            ])
            ->with('verifikator:id,name')
            ->get()
            ->keyBy('exam_participant_id');

        if ($presensi->isEmpty()) {
            return Inertia::render('Admin/Event/MonitoringTidakHadir', [
                'event' => $this->ringkas($event),
                'baris' => [],
                'per_kelas' => [],
                'angka' => ['tidak_hadir' => 0, 'izin' => 0, 'kelas' => 0],
                'monitoring_url' => "/admin/events/{$event->id}/monitoring",
                'presensi_url' => "/admin/events/{$event->id}/presensi",
            ]);
        }

        $baris = ExamParticipant::query()
            ->whereIn('exam_id', $idUjian)
            ->whereIn('id', $presensi->keys())
            ->with([
                'student:id,name,school_id',
                'student.studentProfile:id,user_id,nis',
                'pesertaEvent.kelas:id,name,grade_level',
                'session:id,session_number,exam_id',
                'session.exam:id,code,title,subject_id',
                'session.exam.subject:id,name',
                'room.eventRoom.room:id,code,name',
            ])
            ->get()
            ->map(function (ExamParticipant $p) use ($presensi) {
                $catatan = $presensi->get($p->id);

                return [
                    'peserta_id' => $p->id,
                    'nis' => $p->student?->studentProfile?->nis,
                    'nama' => $p->student?->name,
                    'kelas' => $p->pesertaEvent?->kelas?->name,
                    'tingkat' => $p->pesertaEvent?->kelas?->grade_level,
                    'ruang' => $p->room?->eventRoom?->room?->code,
                    'ruang_nama' => $p->room?->eventRoom?->room?->name,
                    'sesi' => $p->session === null ? null : 'S-'.$p->session->session_number,
                    'ujian' => $p->session?->exam?->subject?->name ?? $p->session?->exam?->title,
                    'status' => $catatan?->attendance_status?->value,
                    'status_label' => $catatan?->attendance_status?->label(),
                    'diverifikasi_pada' => $catatan?->verified_at?->format('d M H:i'),
                    'diverifikasi_oleh' => $catatan?->verifikator?->name ?? $catatan?->pelaku_label,
                    'catatan' => $catatan?->notes,
                ];
            })
            ->sortBy(fn (array $b) => sprintf(
                '%s|%s|%s',
                str_pad((string) ($b['tingkat'] ?? ''), 4, '0', STR_PAD_LEFT),
                $b['kelas'] ?? '',
                $b['nama'] ?? '',
            ))
            ->values();

        $perKelas = $baris
            ->groupBy(fn (array $b) => $b['kelas'] ?? 'Tanpa kelas')
            ->map(fn ($daftar, $kelas) => [
                'kelas' => $kelas,
                'tingkat' => $daftar->first()['tingkat'] ?? null,
                'tidak_hadir' => $daftar->where('status', StatusPresensi::TidakHadir->value)->count(),
                'izin' => $daftar->where('status', StatusPresensi::Izin->value)->count(),
                'total' => $daftar->count(),
            ])
            ->sortBy('kelas')
            ->values()
            ->all();

        return Inertia::render('Admin/Event/MonitoringTidakHadir', [
            'event' => $this->ringkas($event),
            'baris' => $baris->all(),
            'per_kelas' => $perKelas,
            'angka' => [
                'tidak_hadir' => $baris->where('status', StatusPresensi::TidakHadir->value)->count(),
                'izin' => $baris->where('status', StatusPresensi::Izin->value)->count(),
                'kelas' => count($perKelas),
            ],
            'monitoring_url' => "/admin/events/{$event->id}/monitoring",
            'presensi_url' => "/admin/events/{$event->id}/presensi",
        ]);
    }

    /**
     * Detail monitoring per peserta untuk satu ruang-sesi; ruang diverifikasi milik event ini (404 jika bukan).
     */
    public function monitoringRuang(SchoolEvent $event, ExamSessionRoom $ruang): Response
    {
        Gate::authorize('view', $event);

        $ruang = $this->ruangDiEvent($event, (int) $ruang->id);

        $anak = $ruang->participants;
        $idPeserta = $anak->pluck('id');

        $attempt = $this->attemptTerakhir($idPeserta);
        $pelanggaran = $this->pelanggaranPeserta($idPeserta);
        $butir = $this->butirRuang($attempt, $anak->count());

        return Inertia::render('Admin/Event/MonitoringRuang', [
            'event' => $this->ringkas($event),
            'ruang' => [
                'id' => (int) $ruang->id,
                'kode' => $ruang->eventRoom?->room?->code ?? 'R-?',
                'nama' => $ruang->eventRoom?->room?->name,
                'lokasi' => $ruang->eventRoom?->room?->location,
                'sesi' => $ruang->session?->label(),
                'ujian' => $ruang->session?->exam?->title,
                'status_sesi' => $ruang->session?->status->value,
                'status_label' => $ruang->session?->status->label(),
                'jam' => $ruang->session === null
                    ? null
                    : $ruang->session->access_opens_at->format('H:i').'–'.$ruang->session->hard_end_at->format('H:i'),
                'pengawas' => $ruang->supervisors
                    ->map(fn ($t) => $t->supervisor?->name)
                    ->filter()
                    ->values()
                    ->all(),
            ],
            'angka' => [
                'peserta' => $anak->count(),
                'mengerjakan' => $anak->filter(
                    fn (ExamParticipant $p) => $attempt->get($p->id)?->status === ExamAttempt::STATUS_BERJALAN,
                )->count(),
                'selesai' => $anak->filter(
                    fn (ExamParticipant $p) => $attempt->get($p->id)?->status === ExamAttempt::STATUS_DIKIRIM,
                )->count(),
                'terputus' => $anak->filter(function (ExamParticipant $p) use ($attempt) {
                    $a = $attempt->get($p->id);

                    return $a !== null && $a->status === ExamAttempt::STATUS_BERJALAN && ! $a->terhubung();
                })->count(),
                'terkunci' => $anak->filter(fn (ExamParticipant $p) => $attempt->get($p->id)?->dikunci() ?? false)->count(),
                'pelanggaran' => $anak->sum(fn (ExamParticipant $p) => $attempt->get($p->id)?->violation_count ?? 0),
                'insiden' => $this->insiden->jumlahTerbuka($ruang),
            ],
            'anak' => $this->daftarAnak($ruang, $attempt, $pelanggaran, $butir['terjawab']),
            'soal' => $butir['soal'],
            'angka_soal' => $butir['angka'],
            'ruang_tujuan' => $this->pindahDarurat->tujuanTersedia($ruang),
            'monitoring_url' => "/admin/events/{$event->id}/monitoring",
        ]);
    }

    /** Pindah ruang darurat satu peserta oleh Admin (khusus sesi ini saja). */
    public function pindahRuang(
        SchoolEvent $event,
        Request $request,
        ExamParticipant $peserta,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $event);

        $peserta->loadMissing(['exam:id,event_id', 'student:id,name', 'room.eventRoom.room:id,code']);

        if ((int) $peserta->exam?->event_id !== (int) $event->id || $peserta->room === null) {
            abort(404);
        }

        $valid = $request->validate([
            'ruang_tujuan_id' => ['required', 'integer'],
            'mode' => ['required', 'in:hp,pc'],
            'nomor_pc' => ['nullable', 'integer', 'min:1'],
            'alasan' => ['required', 'string', 'min:3', 'max:255'],
        ], [], [
            'ruang_tujuan_id' => 'ruang tujuan',
            'mode' => 'perangkat',
            'nomor_pc' => 'nomor PC',
            'alasan' => 'alasan pindah',
        ]);

        $ruangAsal = $peserta->room;
        $tujuan = ExamSessionRoom::query()
            ->with('eventRoom.room:id,code,name,capacity,device_count')
            ->where('exam_session_id', $ruangAsal->exam_session_id)
            ->find($valid['ruang_tujuan_id']);

        if ($tujuan === null) {
            return back()->with('galat', 'Ruang tujuan tidak ditemukan pada sesi ini.');
        }

        try {
            $this->pindahDarurat->pindahkan(
                $peserta,
                $ruangAsal,
                $tujuan,
                $valid['mode'],
                isset($valid['nomor_pc']) ? (int) $valid['nomor_pc'] : null,
                $valid['alasan'],
            );
        } catch (DomainException $e) {
            return back()->with('galat', $e->getMessage());
        }

        $kodeAsal = $ruangAsal->eventRoom?->room?->code ?? "R-{$ruangAsal->id}";
        $kodeTujuan = $tujuan->eventRoom?->room?->code ?? "R-{$tujuan->id}";
        $infoPc = $valid['mode'] === 'pc' ? " (PC {$valid['nomor_pc']})" : ' (HP)';

        $logger->log('Memindahkan peserta darurat (sesi ini)', $event, [
            'peserta_id' => $peserta->id,
            'siswa' => $peserta->student?->name,
            'dari' => $kodeAsal,
            'ke' => $kodeTujuan,
            'mode' => $valid['mode'],
            'nomor_pc' => $valid['mode'] === 'pc' ? (int) $valid['nomor_pc'] : null,
            'alasan' => $valid['alasan'],
        ]);

        return back()->with(
            'sukses',
            ($peserta->student?->name ?? 'Peserta')." dipindahkan ke {$kodeTujuan}{$infoPc} khusus sesi ini.",
        );
    }

    /** Koreksi presensi oleh Admin; alasan tertulis selalu wajib. */
    public function koreksi(SchoolEvent $event, Request $request): RedirectResponse
    {
        Gate::authorize('view', $event);

        $valid = $request->validate([
            'record_id' => ['required', 'integer'],
            'status' => ['required', 'string'],
            'alasan' => ['required', 'string', 'min:4', 'max:500'],
        ], [
            'alasan.required' => 'Koreksi presensi oleh Admin wajib disertai alasan tertulis.',
            'alasan.min' => 'Alasan koreksi terlalu pendek untuk bisa dipertanggungjawabkan.',
        ], ['record_id' => 'peserta', 'status' => 'status presensi', 'alasan' => 'alasan koreksi']);

        $status = StatusPresensi::tryFrom($valid['status']);

        if ($status === null) {
            return back()->with('galat', 'Status presensi itu tidak dikenal.');
        }

        // Pastikan record berasal dari ruang-sesi milik event ini agar tidak bisa mengubah event lain.
        $record = $this->recordDiEvent($event, (int) $valid['record_id']);

        if ($record === null) {
            abort(404);
        }

        $ruang = $record->penempatan?->room;

        if ($ruang === null) {
            abort(404);
        }

        $this->kehadiran->catat($record, $status, KonteksRuang::dari($ruang), $valid['alasan']);

        return back()->with('sukses', 'Koreksi tersimpan sebagai perubahan baru.');
    }

    /**
     * Muat berita acara yang sudah ada untuk seluruh ruang-sesi dalam satu query (read-only, tidak membuat draf kosong seperti `LaporanSesiService::untuk()`).
     *
     * @param  Collection<int, ExamSessionRoom>  $ruangan
     * @return Collection<int, SessionReport> kunci: exam_session_room_id
     */
    private function beritaAcara(Collection $ruangan): Collection
    {
        if ($ruangan->isEmpty()) {
            return new Collection;
        }

        return SessionReport::query()
            ->whereIn('exam_session_room_id', $ruangan->pluck('id'))
            ->with([
                'berkas:id,session_report_id,sisi,path,nama_asli',
                'tandaTangan:id,session_report_id,peran,nama,path,pelaku_label',
            ])
            ->get()
            ->keyBy('exam_session_room_id');
    }

    /**
     * Ringkas status berita acara satu ruang-sesi; `$laporan` null berarti belum pernah dibuka pengawas.
     *
     * @return array<string, mixed>
     */
    private function ringkasLaporan(ExamSessionRoom $ruang, ?SessionReport $laporan): array
    {
        $kurang = $laporan?->kekuranganFinal() ?? [];
        $ttd = $laporan?->tandaTanganPeran(PeranTandaTangan::Pengawas);

        return [
            'ruang_sesi_id' => (int) $ruang->id,
            'pengawas' => $ruang->supervisors
                ->map(fn ($t) => $t->supervisor?->name)
                ->filter()
                ->values()
                ->all(),
            'url' => route('pengawas.berita-acara', ['ruang' => $ruang->id], absolute: false),

            'dibuka' => $laporan !== null,
            'final' => $laporan?->final() ?? false,
            'dikirim_pada' => $laporan?->submitted_at?->format('d M H:i'),

            'foto' => $laporan === null
                ? 0
                : $laporan->berkas->count(),
            'foto_sesudah' => $laporan?->berkasSisi(SisiFoto::Sesudah->value) !== null,

            'ttd_pengawas' => $ttd !== null,
            'ttd_oleh' => $ttd?->nama,

            'kurang' => $kurang,
            'selesai' => $laporan !== null && $laporan->final(),
        ];
    }

    /**
     * Daftar peserta satu ruang-sesi beserta `record_id` untuk aksi koreksi presensi.
     *
     * @param  \Illuminate\Support\Collection<int, AttendanceRecord>  $records
     * @return array<int, array<string, mixed>>
     */
    private function peserta(ExamSessionRoom $ruang, \Illuminate\Support\Collection $records): array
    {
        return $ruang->participants
            ->map(function (ExamParticipant $p) use ($records) {
                $record = $records->get($p->id);

                return [
                    'peserta_id' => $p->id,
                    'record_id' => $record?->id,
                    'nama' => $p->student?->name ?? 'Peserta',
                    'nis' => $p->student?->studentProfile?->nis,
                    'status' => $record?->attendance_status->value,
                    'status_label' => $record?->attendance_status->label() ?? 'Belum ada baris presensi',
                    'status_nada' => $record?->attendance_status->nada() ?? 'mute',
                    'diverifikasi_oleh' => $record?->pelaku_label,
                    'diverifikasi_jam' => $record?->verified_at?->format('H:i'),
                ];
            })
            ->sortBy('nama')
            ->values()
            ->all();
    }

    /**
     * Buka kunci attempt peserta dari konsol Admin lewat `PencatatPelanggaran::catatPembukaan()` dengan label pelaku Admin.
     */
    public function bukaKunci(SchoolEvent $event, Request $request, ExamParticipant $peserta): RedirectResponse
    {
        Gate::authorize('view', $event);

        // Pastikan peserta milik event ini agar tidak bisa membuka kunci lintas event.
        abort_unless((int) $peserta->exam?->event_id === (int) $event->id, 404);

        $valid = $request->validate([
            'alasan' => ['nullable', 'string', 'max:200'],
        ]);

        $attempt = ExamAttempt::query()
            ->where('exam_participant_id', $peserta->id)
            ->orderByDesc('attempt_number')
            ->first();

        if ($attempt === null) {
            return back()->with('galat', 'Peserta ini belum mulai mengerjakan, jadi tidak ada yang terkunci.');
        }

        if (! $attempt->dikunci()) {
            return back()->with('galat', 'Pengerjaan peserta ini tidak sedang terkunci.');
        }

        $alasan = trim((string) ($valid['alasan'] ?? ''));

        $this->pencatat->catatPembukaan(
            $attempt,
            'Admin '.($request->user()?->name ?? ''),
            $alasan === '' ? 'Dibuka dari papan monitoring Admin.' : $alasan,
            $request->user()?->id,
        );

        return back()->with('sukses', 'Kunci dibuka. Penghitung pelanggaran tidak direset, dan pembukaannya tercatat.');
    }

    /**
     * Batalkan attempt terakhir peserta dan hapus nilainya agar dapat mengulang dari awal tanpa menaikkan `max_attempts`.
     */
    public function resetUjian(
        SchoolEvent $event,
        Request $request,
        ExamParticipant $peserta,
        PengendaliPengerjaan $kendali,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('view', $event);

        abort_unless((int) $peserta->exam?->event_id === (int) $event->id, 404);

        $valid = $request->validate([
            'alasan' => ['required', 'string', 'min:4', 'max:200'],
        ], [], ['alasan' => 'alasan reset']);

        $attempt = ExamAttempt::query()
            ->where('exam_participant_id', $peserta->id)
            ->orderByDesc('attempt_number')
            ->first();

        if ($attempt === null) {
            return back()->with('galat', 'Peserta ini belum mulai mengerjakan, jadi tidak ada yang bisa direset.');
        }

        $alasan = trim($valid['alasan']);
        $pelaku = 'Admin '.($request->user()?->name ?? '');

        try {
            $nomor = $kendali->reset($attempt, $pelaku, $alasan);
        } catch (RuntimeException $e) {
            return back()->with('galat', $e->getMessage());
        }

        $logger->log('Meriset pengerjaan ujian', $attempt, [
            'peserta_id' => $peserta->id,
            'attempt_number' => $nomor,
            'alasan' => $alasan,
        ]);

        return back()->with(
            'sukses',
            'Pengerjaan ke-'.$nomor.' dibatalkan. Peserta akan mengulang dari soal pertama saat membuka gerbang lagi.',
        );
    }

    /**
     * Reset pengerjaan seluruh peserta dalam satu ruang-sesi; transaksi berjalan per peserta agar yang belum mulai atau sudah batal dilewati tanpa membatalkan yang lain.
     */
    public function resetMassal(
        SchoolEvent $event,
        Request $request,
        ExamSessionRoom $ruang,
        PengendaliPengerjaan $kendali,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('view', $event);

        $ruang = $this->ruangDiEvent($event, (int) $ruang->id);

        $valid = $request->validate([
            'alasan' => ['required', 'string', 'min:8', 'max:200'],
        ], [
            // Ambang min:8 (lebih ketat dari reset tunggal) karena alasan disalin ke riwayat seluruh ruang.
            'alasan.min' => 'Alasan reset massal harus cukup panjang untuk menjelaskan kejadiannya ke seluruh ruang.',
        ], ['alasan' => 'alasan reset massal']);

        $alasan = trim($valid['alasan']);
        $pelaku = 'Admin '.($request->user()?->name ?? '');

        $direset = 0;
        $dilewati = 0;
        $gagal = [];

        foreach ($ruang->participants as $peserta) {
            /** @var ExamParticipant $peserta */
            $attempt = ExamAttempt::query()
                ->where('exam_participant_id', $peserta->id)
                ->orderByDesc('attempt_number')
                ->first();

            if ($attempt === null) {
                $dilewati++;

                continue;
            }

            try {
                $kendali->reset($attempt, $pelaku, $alasan);
                $direset++;
            } catch (RuntimeException $e) {
                // Attempt yang sudah batal dilewati tanpa membatalkan peserta lain.
                $gagal[$peserta->student?->name ?? "peserta {$peserta->id}"] = $e->getMessage();
            }
        }

        $logger->log('Meriset pengerjaan satu ruang', $ruang, [
            'ruang' => $ruang->eventRoom?->room?->code,
            'direset' => $direset,
            'dilewati' => $dilewati,
            'alasan' => $alasan,
        ]);

        if ($direset === 0) {
            return back()->with('galat', $this->kalimatKosong($dilewati, $gagal));
        }

        $pesan = "{$direset} pengerjaan di ruang ".($ruang->eventRoom?->room?->code ?? 'ini').' dibatalkan.';

        if ($dilewati > 0) {
            $pesan .= " {$dilewati} peserta belum mulai mengerjakan, jadi tidak ada yang direset.";
        }

        if ($gagal !== []) {
            $pesan .= ' '.count($gagal).' dilewati karena pengerjaannya sudah dibatalkan sebelumnya.';
        }

        return back()->with('sukses', $pesan);
    }

    /** Bedakan pesan galat saat tidak ada peserta yang berhasil direset. */
    private function kalimatKosong(int $dilewati, array $gagal): string
    {
        if ($dilewati > 0 && $gagal === []) {
            return 'Tidak ada yang bisa direset: seluruh peserta ruang ini belum mulai mengerjakan.';
        }

        if ($gagal !== []) {
            return 'Tidak ada yang bisa direset: seluruh pengerjaan di ruang ini sudah dibatalkan sebelumnya.';
        }

        return 'Ruang ini tidak punya peserta yang bisa direset.';
    }

    /**
     * Hentikan pengerjaan satu peserta dengan pesan Admin (`lock_reason`) dan catat riwayat `diblokir_admin`.
     */
    public function blokirPeserta(
        SchoolEvent $event,
        Request $request,
        ExamParticipant $peserta,
        PengendaliPengerjaan $kendali,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('view', $event);

        abort_unless((int) $peserta->exam?->event_id === (int) $event->id, 404);

        $valid = $request->validate([
            'pesan' => ['required', 'string', 'min:4', 'max:300'],
        ], [], ['pesan' => 'pesan penghentian']);

        $attempt = ExamAttempt::query()
            ->where('exam_participant_id', $peserta->id)
            ->orderByDesc('attempt_number')
            ->first();

        if ($attempt === null) {
            return back()->with('galat', 'Peserta ini belum mulai mengerjakan, jadi tidak ada yang bisa dihentikan.');
        }

        $pesan = trim($valid['pesan']);
        $pelaku = 'Admin '.($request->user()?->name ?? '');

        try {
            $kendali->blokir($attempt, $pesan, $pelaku, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->with('galat', $e->getMessage());
        }

        $logger->log('Menghentikan pengerjaan peserta', $attempt, [
            'peserta_id' => $peserta->id,
            'pesan' => $pesan,
        ]);

        return back()->with('sukses', 'Pengerjaan dihentikan. Pesan yang Anda tulis tampil di layar peserta.');
    }

    /**
     * Hentikan pengerjaan seluruh peserta yang sedang berlangsung dalam satu ruang-sesi dengan pesan Admin.
     */
    public function hentikanMassal(
        SchoolEvent $event,
        Request $request,
        ExamSessionRoom $ruang,
        PengendaliPengerjaan $kendali,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('view', $event);

        $ruang = $this->ruangDiEvent($event, (int) $ruang->id);

        $valid = $request->validate([
            'pesan' => ['required', 'string', 'min:4', 'max:300'],
        ], [], ['pesan' => 'pesan penghentian']);

        $pesan = trim($valid['pesan']);
        $pelaku = 'Admin '.($request->user()?->name ?? '');

        $dihentikan = 0;
        $dilewati = 0;

        foreach ($ruang->participants as $peserta) {
            /** @var ExamParticipant $peserta */
            $attempt = ExamAttempt::query()
                ->where('exam_participant_id', $peserta->id)
                ->orderByDesc('attempt_number')
                ->first();

            if ($attempt === null || $attempt->status !== ExamAttempt::STATUS_BERJALAN) {
                $dilewati++;

                continue;
            }

            try {
                $kendali->blokir($attempt, $pesan, $pelaku, $request->user()?->id);
                $dihentikan++;
            } catch (RuntimeException) {
                $dilewati++;
            }
        }

        $logger->log('Menghentikan pengerjaan satu ruang', $ruang, [
            'ruang' => $ruang->eventRoom?->room?->code,
            'dihentikan' => $dihentikan,
            'dilewati' => $dilewati,
            'pesan' => $pesan,
        ]);

        if ($dihentikan === 0) {
            return back()->with('galat', 'Tidak ada pengerjaan yang sedang berlangsung di ruang ini untuk dihentikan.');
        }

        $kodeRuang = $ruang->eventRoom?->room?->code ?? 'ini';
        $ringkas = "{$dihentikan} pengerjaan di ruang {$kodeRuang} dihentikan. Pesan yang Anda tulis tampil di layar peserta.";

        if ($dilewati > 0) {
            $ringkas .= " {$dilewati} peserta dilewati karena tidak sedang mengerjakan.";
        }

        return back()->with('sukses', $ringkas);
    }

    /**
     * Susun baris monitoring per peserta; `nomor` dan `progres` dibaca dari snapshot heartbeat (`guard_flags_json`) agar tidak menambah query per anak.
     * Nomor kursi dihitung atas ruang event dengan kunci `student_id` agar konsisten dengan papan pengawas.
     *
     * @param  \Illuminate\Support\Collection<int, ExamAttempt>  $attempt
     * @param  \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, ExamViolation>>  $pelanggaran
     * @return array<int, array<string, mixed>>
     */
    private function daftarAnak(
        ExamSessionRoom $ruang,
        \Illuminate\Support\Collection $attempt,
        \Illuminate\Support\Collection $pelanggaran,
        \Illuminate\Support\Collection $terjawab,
    ): array {
        $kursi = $this->kursi->petaRuangEvent((int) $ruang->eventRoom?->id);

        return $ruang->participants
            ->map(function (ExamParticipant $p) use ($attempt, $pelanggaran, $kursi, $terjawab) {
                $a = $attempt->get($p->id);
                $jenis = $pelanggaran->get($p->id, collect());
                $butir = $terjawab->get($p->id);

                return [
                    'peserta_id' => $p->id,
                    'kursi' => $kursi[(int) $p->student_id] ?? null,
                    'nama' => $p->student?->name ?? 'Peserta',
                    'nis' => $p->student?->studentProfile?->nis,

                    'status' => $a?->status,
                    'status_label' => $a?->labelStatus() ?? 'Belum mulai',
                    'status_nada' => $a?->nadaStatus() ?? 'mute',

                    // Null berarti belum ada heartbeat atau pengerjaan sudah selesai/tidak berlangsung.
                    'terhubung' => $a !== null && $a->status === ExamAttempt::STATUS_BERJALAN ? $a->terhubung() : null,
                    'nomor' => $a?->guard_flags_json['nomor'] ?? null,
                    'progres' => $a?->guard_flags_json['progres'] ?? null,
                    'denyut' => $a?->last_heartbeat_at?->format('H:i:s'),

                    // Null untuk peserta tanpa attempt (berbeda dari array kosong yang berarti selesai).
                    'dikerjakan' => $butir['dikerjakan'] ?? null,
                    'belum' => $butir['belum'] ?? null,
                    'ditandai' => $butir['ditandai'] ?? null,
                    'butir' => $butir['butir'] ?? null,

                    'pelanggaran' => $a?->violation_count ?? 0,

                    // Kecualikan tindakan pengawas/Admin (`JENIS_PENGAWAS`) agar tidak tampil sebagai pelanggaran siswa.
                    'jenis_pelanggaran' => $jenis
                        ->reject(fn (ExamViolation $v) => in_array($v->violation_type, ExamViolation::JENIS_PENGAWAS, true))
                        ->map(fn (ExamViolation $v) => $v->jumlah.' '.strtolower(ExamViolation::JENIS[$v->violation_type] ?? $v->violation_type))
                        ->values()
                        ->all(),

                    // `layar_penuh` adalah status terkini dari heartbeat; `layar_terbagi` adalah total kejadian dari riwayat pelanggaran.
                    'layar_penuh' => $a === null
                        ? null
                        : (bool) ($a->guard_flags_json['layar_penuh'] ?? false),
                    'layar_terbagi' => (int) ($jenis
                        ->firstWhere('violation_type', 'layar_terbagi')?->jumlah ?? 0),

                    'terkunci' => $a?->dikunci() ?? false,
                    'kunci_alasan' => $a?->lock_reason,
                ];
            })
            ->sortBy('nama')
            ->values()
            ->all();
    }

    /**
     * Hitung jawaban tersimpan (`student_answers`) per peserta memakai `display_order` (urutan layar anak) dan statistik per soal memakai `exam_question_id`.
     * Seluruh butir, jawaban, dan soal dimuat sekaligus untuk ruang ini agar bebas N+1.
     *
     * @param  \Illuminate\Support\Collection<int, ExamAttempt>  $attempt  kunci: exam_participant_id
     * @param  int  $pesertaRuang  jumlah anak yang tercatat di ruang ini, attempt atau belum
     * @return array{
     *     terjawab: \Illuminate\Support\Collection<int, array{dikerjakan: array<int, int>, belum: array<int, int>, ditandai: array<int, int>, butir: array<int, array{nomor: int, soal_id: int, dijawab: bool, ditandai: bool}>}>,
     *     soal: array<int, array<string, mixed>>,
     *     angka: array<string, int>
     * }
     */
    private function butirRuang(\Illuminate\Support\Collection $attempt, int $pesertaRuang): array
    {
        $kosong = [
            'terjawab' => collect(),
            'soal' => [],
            'angka' => [
                'soal' => 0, 'peserta' => 0, 'sudah' => 0, 'belum' => 0,
                'ditandai' => 0, 'selesai_semua' => 0, 'belum_mulai' => $pesertaRuang,
            ],
        ];

        $punyaAttempt = $attempt->filter(fn (ExamAttempt $a) => $a->id !== null);

        if ($punyaAttempt->isEmpty()) {
            return $kosong;
        }

        $butir = AttemptQuestion::query()
            ->whereIn('exam_attempt_id', $punyaAttempt->pluck('id'))
            ->with([
                'jawaban:id,attempt_question_id,answer_json,is_correct,ditandai',
                'soal:id,position,jenis,judul,bobot',
            ])
            ->orderBy('display_order')
            ->get();

        $berAttempt = $butir->groupBy('exam_attempt_id');

        $pesertaDariAttempt = $punyaAttempt->mapWithKeys(
            fn (ExamAttempt $a, int $pesertaId) => [(int) $a->id => $pesertaId],
        );

        $perPeserta = collect();
        $selesaiSemua = 0;
        $sudah = 0;
        $ditandai = 0;

        foreach ($berAttempt as $attemptId => $daftar) {
            $pesertaId = $pesertaDariAttempt->get((int) $attemptId);

            if ($pesertaId === null) {
                continue;
            }

            $dikerjakan = [];
            $belum = [];
            $ragu = [];
            $urut = [];

            foreach ($daftar as $b) {
                $nomor = (int) $b->display_order;
                $dijawab = $b->jawaban?->terjawab() === true;
                $raguSatu = $b->jawaban?->ditandai === true;

                if ($dijawab) {
                    $dikerjakan[] = $nomor;
                    $sudah++;
                } else {
                    $belum[] = $nomor;
                }

                if ($raguSatu) {
                    $ragu[] = $nomor;
                    $ditandai++;
                }

                // Hubungkan nomor layar anak (`display_order`) dengan `exam_question_id` saat soal diacak.
                $urut[] = [
                    'nomor' => $nomor,
                    'soal_id' => (int) $b->exam_question_id,
                    'dijawab' => $dijawab,
                    'ditandai' => $raguSatu,
                ];
            }

            sort($dikerjakan);
            sort($belum);
            sort($ragu);

            if ($belum === [] && $dikerjakan !== []) {
                $selesaiSemua++;
            }

            $perPeserta->put($pesertaId, [
                'dikerjakan' => $dikerjakan,
                'belum' => $belum,
                'ditandai' => $ragu,
                'butir' => $urut,
            ]);
        }

        $soal = $this->statistikButir($butir);

        return [
            'terjawab' => $perPeserta,
            'soal' => $soal,
            'angka' => [
                'soal' => count($soal),
                'peserta' => $perPeserta->count(),
                'sudah' => $sudah,
                'belum' => $butir->count() - $sudah,
                'ditandai' => $ditandai,
                'selesai_semua' => $selesaiSemua,
                // Dihitung dari `$pesertaRuang` karena `$attempt` hanya memuat peserta yang sudah mulai.
                'belum_mulai' => max(0, $pesertaRuang - $perPeserta->count()),
            ],
        ];
    }

    /**
     * Statistik per soal dikelompokkan berdasarkan `exam_question_id` (bukan `display_order` yang bisa teracak).
     * `benar` dan `salah` bernilai null selama belum ada jawaban yang dinilai (`is_correct` terisi) agar tidak terbaca sebagai 0 benar.
     *
     * @param  Collection<int, AttemptQuestion>  $butir
     * @return array<int, array<string, mixed>>
     */
    private function statistikButir(Collection $butir): array
    {
        return $butir
            ->groupBy('exam_question_id')
            ->map(function (\Illuminate\Support\Collection $kelompok) {
                $soal = $kelompok->first()->soal;

                $terjawab = $kelompok->filter(fn (AttemptQuestion $b) => $b->jawaban?->terjawab() === true);
                $otomatis = $soal?->jenis->otomatis() ?? false;

                $dinilai = $terjawab->filter(fn (AttemptQuestion $b) => $b->jawaban?->is_correct !== null);

                $jumlah = $kelompok->count();
                $sudah = $terjawab->count();
                $adaNilai = $otomatis && $dinilai->isNotEmpty();

                return [
                    'id' => (int) $kelompok->first()->exam_question_id,
                    // Urutan paket ujian (`position`), bukan `display_order` per anak.
                    'nomor' => (int) ($soal?->position ?? 0),
                    'jenis' => $soal?->jenis->value,
                    'jenis_label' => $soal?->jenis->label(),
                    'jenis_pendek' => $soal?->jenis->pendek(),
                    'judul' => $soal?->judul,
                    'bobot' => (float) ($soal?->bobot ?? 0),
                    'otomatis' => $otomatis,
                    'peserta' => $jumlah,
                    'sudah' => $sudah,
                    'belum' => $jumlah - $sudah,
                    'ditandai' => $kelompok->filter(fn (AttemptQuestion $b) => $b->jawaban?->ditandai === true)->count(),
                    'dinilai' => $dinilai->count(),
                    'benar' => $adaNilai ? $dinilai->filter(fn (AttemptQuestion $b) => $b->jawaban?->is_correct === true)->count() : null,
                    'salah' => $adaNilai ? $dinilai->filter(fn (AttemptQuestion $b) => $b->jawaban?->is_correct === false)->count() : null,
                    'persen' => $jumlah === 0 ? 0 : (int) round($sudah / $jumlah * 100),
                ];
            })
            ->sortBy('nomor')
            ->values()
            ->all();
    }

    /**
     * Ambil attempt terakhir tiap peserta dalam satu query (`orderBy('attempt_number')` menjamin `last()` adalah yang terbaru).
     *
     * @param  \Illuminate\Support\Collection<int, int>  $idPeserta
     * @return \Illuminate\Support\Collection<int, ExamAttempt>
     */
    private function attemptTerakhir(\Illuminate\Support\Collection $idPeserta): \Illuminate\Support\Collection
    {
        return ExamAttempt::query()
            ->whereIn('exam_participant_id', $idPeserta)
            ->orderBy('attempt_number')
            ->get()
            ->groupBy('exam_participant_id')
            ->map(fn ($baris) => $baris->last());
    }

    /**
     * Agregasi pelanggaran per peserta dan jenis dalam satu query (hanya dipanggil dari `monitoringRuang()`).
     *
     * @param  \Illuminate\Support\Collection<int, int>  $idPeserta
     * @return \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, ExamViolation>>
     */
    private function pelanggaranPeserta(\Illuminate\Support\Collection $idPeserta): \Illuminate\Support\Collection
    {
        return ExamViolation::query()
            ->whereIn('exam_participant_id', $idPeserta)
            ->selectRaw('exam_participant_id, violation_type, count(*) as jumlah')
            ->groupBy('exam_participant_id', 'violation_type')
            ->get()
            ->groupBy('exam_participant_id');
    }

    /**
     * Cari ruang-sesi di dalam event ini; kembalikan 404 (bukan 403) jika di luar event agar tidak membocorkan eksistensi.
     */
    private function ruangDiEvent(SchoolEvent $event, int $ruangId): ExamSessionRoom
    {
        $ruang = $this->ruangan($event)->firstWhere('id', $ruangId);

        if ($ruang === null) {
            abort(404);
        }

        return $ruang;
    }

    /**
     * Cari baris presensi yang penempatannya berada di salah satu ruang-sesi milik event ini.
     */
    private function recordDiEvent(SchoolEvent $event, int $recordId): ?AttendanceRecord
    {
        $record = AttendanceRecord::query()
            ->with('penempatan:id,exam_session_room_id')
            ->find($recordId);

        if ($record === null) {
            return null;
        }

        $ruangId = $record->penempatan?->exam_session_room_id;

        if ($ruangId === null) {
            return null;
        }

        return $this->ruangan($event)->contains(fn (ExamSessionRoom $r) => (int) $r->id === (int) $ruangId)
            ? $record
            : null;
    }

    /**
     * Muat seluruh ruang-sesi event beserta penempatan dan pengawas dalam satu daftar terurut konsisten.
     *
     * @return Collection<int, ExamSessionRoom>
     */
    private function ruangan(SchoolEvent $event): Collection
    {
        $sesi = ExamSession::query()
            ->whereIn('exam_id', $event->exams()->pluck('id'))
            ->pluck('id');

        return ExamSessionRoom::query()
            ->whereIn('exam_session_id', $sesi)
            ->with([
                'eventRoom.room:id,code,name,location',
                'session.exam:id,code,title,subject_id',
                'session.exam.subject:id,name',
                'supervisors.supervisor:id,name',
                'participants:id,exam_session_room_id,student_id,school_id',
                'participants.student:id,name,school_id',
                'participants.student.studentProfile:id,user_id,nis',
            ])
            ->orderBy('exam_session_id')
            ->orderBy('id')
            ->get();
    }

    /** @return array<string, mixed> */
    private function ringkas(SchoolEvent $event): array
    {
        return [
            'id' => $event->id,
            'kode' => $event->code,
            'nama' => $event->name,
            'status' => $event->status->value,
            'status_label' => $event->status->label(),
        ];
    }

    /**
     * Daftar seluruh mapel event dari `event_subjects` (termasuk yang belum punya ujian/sesi) dengan jumlah query konstan (dijaga `PemantauanQueryTest`).
     *
     * @return array<int, array<string, mixed>>
     */
    private function mapelEvent(SchoolEvent $event): array
    {
        $mapel = $event->subjects()
            ->with('subject:id,code,name,grade_level')
            ->get()
            ->sortBy(fn ($baris) => $baris->subject?->code)
            ->values();

        if ($mapel->isEmpty()) {
            return [];
        }

        $ujian = Exam::query()
            ->where('event_id', $event->id)
            ->with(['subject:id,name,grade_level', 'classes.kelas:id,grade_level'])
            ->get(['id', 'subject_id', 'title']);

        $sesi = ExamSession::query()
            ->whereIn('exam_id', $ujian->pluck('id'))
            ->orderBy('session_number')
            ->get(['id', 'exam_id', 'session_number', 'status', 'access_opens_at', 'hard_end_at'])
            // `toBase()` wajib sebelum `only()` karena `only()` Eloquent Collection mengharapkan key model, bukan `exam_id`.
            ->toBase()
            ->groupBy('exam_id');

        $ruangPerSesi = ExamSessionRoom::query()
            ->whereIn('exam_session_id', $sesi->collapse()->pluck('id'))
            ->selectRaw('exam_session_id, count(*) as jumlah')
            ->groupBy('exam_session_id')
            ->pluck('jumlah', 'exam_session_id');

        // Hitung per ujian (bukan per mapel) karena satu mapel dapat memiliki lebih dari satu ujian.
        $pesertaPerUjian = ExamParticipant::query()
            ->whereIn('exam_id', $ujian->pluck('id'))
            ->where('status', ExamParticipant::STATUS_TERDAFTAR)
            ->selectRaw('exam_id, count(*) as jumlah')
            ->groupBy('exam_id')
            ->pluck('jumlah', 'exam_id');

        $presensiPerUjian = AttendanceRecord::query()
            ->join('exam_participants', 'exam_participants.id', '=', 'attendance_records.exam_participant_id')
            ->whereIn('exam_participants.exam_id', $ujian->pluck('id'))
            ->selectRaw('exam_participants.exam_id as exam_id, attendance_records.attendance_status as status, count(*) as jumlah')
            ->groupBy('exam_participants.exam_id', 'attendance_records.attendance_status')
            ->get()
            ->toBase()
            ->groupBy('exam_id');

        $tingkatPerUjian = $ujian->mapWithKeys(fn (Exam $u) => [
            $u->id => $u->tingkatSasaran(),
        ]);

        return $mapel->map(function ($baris) use ($ujian, $tingkatPerUjian, $sesi, $ruangPerSesi, $pesertaPerUjian, $presensiPerUjian) {
            $namaBaris = mb_strtolower(trim((string) ($baris->subject?->name ?? '')));
            $tingkatBaris = $baris->subject?->grade_level !== null ? (string) $baris->subject->grade_level : null;

            $daftarUjian = $ujian->filter(function (Exam $u) use ($baris, $namaBaris, $tingkatBaris, $tingkatPerUjian): bool {
                if ($u->subject_id === $baris->subject_id) {
                    return true;
                }

                $namaUjian = mb_strtolower(trim((string) ($u->subject?->name ?? '')));
                if ($namaBaris === '' || $namaUjian !== $namaBaris) {
                    return false;
                }

                $sasaran = $tingkatPerUjian->get($u->id, []);

                return $sasaran === [] || ($tingkatBaris !== null && in_array($tingkatBaris, $sasaran, true));
            })->values();
            $idUjian = $daftarUjian->pluck('id');

            // Pakai `collapse()` (bukan `flatten()`) karena nilainya Collection, bukan array.
            $jumlahSesi = $sesi->only($idUjian->all())->collapse()->count();

            $presensi = $presensiPerUjian->only($idUjian->all())->collapse();

            $hitung = fn (StatusPresensi $status) => (int) $presensi
                ->where('status', $status->value)
                ->sum('jumlah');

            return [
                'subject_id' => $baris->subject_id,
                'kode' => $baris->subject?->code,
                'nama' => $baris->subject?->name,
                'ujian' => $daftarUjian->pluck('title')->values()->all(),
                'sesi' => $daftarUjian->flatMap(fn ($u) => $sesi->get($u->id, collect())->map(fn ($s) => [
                    'id' => $s->id,
                    'label' => $s->label(),
                    'status' => $s->status->value,
                    'status_label' => $s->status->label(),
                    'jam' => $s->access_opens_at->format('H:i').'–'.$s->hard_end_at->format('H:i'),
                    'ruang' => (int) ($ruangPerSesi[$s->id] ?? 0),
                ]))->values()->all(),
                'jumlah_sesi' => $jumlahSesi,
                'keadaan' => $jumlahSesi > 0 ? 'terjadwal' : ($daftarUjian->isEmpty() ? 'belum_ujian' : 'belum_sesi'),
                'peserta' => (int) $idUjian->sum(fn ($id) => (int) ($pesertaPerUjian[$id] ?? 0)),
                'hadir' => $hitung(StatusPresensi::Hadir) + $hitung(StatusPresensi::Terlambat),
                'tidak_hadir' => $hitung(StatusPresensi::TidakHadir),
                'izin' => $hitung(StatusPresensi::Izin),
                'belum_diverifikasi' => $hitung(StatusPresensi::Pending),
            ];
        })->values()->all();
    }
}

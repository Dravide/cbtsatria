<?php

namespace App\Domain\Attendance\Http\Controllers;

use App\Domain\Attendance\Enums\StatusPresensi;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Services\InsidenService;
use App\Domain\Attendance\Services\KehadiranService;
use App\Domain\Attendance\Services\KonteksRuang;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Models\ExamSessionRoom;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Presensi dan monitoring lintas ruang, dari konsol Admin - PRD §3.5.
 *
 * =====================================================================
 * Mengapa BERBEDA dari laman pengawas, bukan sekadar versi lebih lebar
 * =====================================================================
 * Pertanyaan Admin dan pertanyaan pengawas bukan pertanyaan yang sama, dan
 * menyatukan keduanya menghasilkan laman yang menjawab keduanya dengan
 * buruk:
 *
 *   pengawas  "siapa di ruang SAYA yang belum saya tandai"   -> satu ruang
 *   Admin     "ruang mana yang pengawasnya belum selesai"    -> semua ruang
 *
 * Karena itu tidak ada satu pun daftar kursi di sini. Barisnya adalah
 * RUANG-SESI, dan kolomnya adalah rekap - sebab Admin tidak memanggil nama
 * satu per satu, ia mencari ruang yang tertinggal.
 *
 * ---------------------------------------------------------------------
 * Admin boleh MENGOREKSI, dan koreksinya menuntut alasan SELALU
 * ---------------------------------------------------------------------
 * Ini kewenangan yang diberikan PRD §3.5 secara eksplisit ("Presensi:
 * Monitoring atau koreksi"), dan ia berbeda sifatnya dari penandaan
 * pengawas: Admin mengubah catatan ruang yang tidak ia masuki, sering kali
 * berhari-hari sesudahnya.
 *
 * ---------------------------------------------------------------------
 * Alasan koreksi Admin: divergensi yang disengaja dari plate 17
 * ---------------------------------------------------------------------
 * Plate 17 membebaskan pengawas dari alasan SELAMA sesi berjalan, dan
 * membebaskannya justru karena ia melihat orangnya berdiri di depan kursi.
 * Admin tidak melihat apa pun - yang sampai kepadanya adalah laporan bahwa
 * "anak saya sebenarnya hadir". Mewarisi keringanan itu akan menghasilkan
 * koreksi tanpa kalimat, tepat pada kasus yang paling perlu kalimat.
 *
 * Yang TIDAK dilakukan Admin di sini: mengubah baris lama. `catat()`
 * menulis baris `attendance_logs` baru setiap kali, tanpa cabang.
 */
class PemantauanEventController
{
    public function __construct(
        private readonly KehadiranService $kehadiran,
        private readonly InsidenService $insiden,
    ) {}

    /** Ringkasan presensi per ruang-sesi. */
    public function presensi(SchoolEvent $event): Response
    {
        Gate::authorize('view', $event);

        $ruangan = $this->ruangan($event);

        // Satu query presensi untuk SELURUH event, bukan satu per ruang.
        // Laman ini dibuka Admin di tengah hari-H saat basis data paling
        // sibuk, dan N+1 di sini berarti dua puluh query yang semuanya
        // mencari hal yang sama.
        $records = AttendanceRecord::query()
            ->whereIn('exam_participant_id', $ruangan->flatMap->participants->pluck('id'))
            ->get()
            ->keyBy('exam_participant_id');

        // Peta peserta -> ruang, dibangun dari relasi yang SUDAH dimuat
        // `ruangan()`. Tanpa ini, rekap per ruang harus mencari sendiri baris
        // mana miliknya - dan satu-satunya cara melakukannya tanpa memuat
        // ulang adalah kueri per ruang, yaitu N+1 yang justru dihindari.
        $ruangPeserta = $ruangan->flatMap(
            fn (ExamSessionRoom $r) => $r->participants->mapWithKeys(
                fn (ExamParticipant $p) => [$p->id => $r->id],
            ),
        );

        // Presensi yang sudah dimuat, dikelompokkan menurut ruangnya.
        $recordsPerRuang = $records->groupBy(fn (AttendanceRecord $r) => $ruangPeserta->get($r->exam_participant_id));

        // Jumlah insiden terbuka untuk SELURUH ruang dalam satu kueri -
        // lihat catatan `InsidenService::jumlahTerbukaPerRuang()`.
        $insidenPerRuang = $this->insiden->jumlahTerbukaPerRuang($ruangan);

        $baris = $ruangan->map(function (ExamSessionRoom $ruang) use ($records, $recordsPerRuang, $insidenPerRuang) {
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

                // Daftar anak-anaknyalah yang membuat laman ini bisa MEMPERBAIKI
                // sesuatu; tanpa mereka, Admin hanya bisa melihat bahwa satu
                // ruangan tertinggal tanpa bisa berbuat apa pun. `record_id`
                // disertakan karena koreksi bekerja atas baris presensi, bukan
                // atas peserta.
                'peserta' => $this->peserta($ruang, $records),
            ];
        })->values();

        return Inertia::render('Admin/Event/Presensi', [
            'event' => $this->ringkas($event),
            'baris' => $baris->all(),

            'angka' => [
                'ruang' => $baris->count(),
                // "Ruangan yang belum tuntas" adalah satu-satunya angka yang
                // membuat laman ini dibuka Admin: sisanya sudah terbaca di
                // kolomnya masing-masing.
                'belum_tuntas' => $baris->filter(fn (array $b) => $b['rekap']['pending'] > 0)->count(),
                'hadir' => $baris->sum(fn (array $b) => $b['rekap']['hadir']),
                'peserta' => $baris->sum(fn (array $b) => $b['rekap']['total']),
                'insiden' => $baris->sum('insiden'),
            ],

            'opsi' => StatusPresensi::options(),

            'tanpa_pengawas' => $baris
                ->filter(fn (array $b) => $b['pengawas'] === [])
                ->pluck('ruang')
                ->values()
                ->all(),
        ]);
    }

    /**
     * Papan keadaan sesi lintas ruang.
     *
     * Sama seperti papan pengawas, ia membaca `exam_attempts` yang belum
     * punya penulis sampai Fase 7 - dan itu dinyatakan apa adanya, bukan
     * ditambal angka karangan.
     */
    public function monitoring(SchoolEvent $event): Response
    {
        Gate::authorize('view', $event);

        $ruangan = $this->ruangan($event);
        $peserta = $ruangan->flatMap->participants;

        $attempt = ExamAttempt::query()
            ->whereIn('exam_participant_id', $peserta->pluck('id'))
            ->orderBy('attempt_number')
            ->get()
            ->groupBy('exam_participant_id')
            ->map(fn ($baris) => $baris->last());

        // Satu kueri untuk seluruh ruang, bukan satu per ruang - lihat
        // catatan di `InsidenService::jumlahTerbukaPerRuang()`.
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

                    return $a !== null && ! $a->terhubung();
                })->count(),
                'pelanggaran' => $anak->sum(fn (ExamParticipant $p) => $attempt->get($p->id)?->violation_count ?? 0),
                'terkunci' => $anak->filter(fn (ExamParticipant $p) => $attempt->get($p->id)?->dikunci() ?? false)->count(),
                'insiden' => $insidenPerRuang[$ruang->id] ?? 0,
            ];
        })->values();

        return Inertia::render('Admin/Event/Monitoring', [
            'event' => $this->ringkas($event),
            'baris' => $baris->all(),
            'angka' => [
                'ruang' => $baris->count(),
                'peserta' => $peserta->count(),
                'mengerjakan' => $baris->sum('mengerjakan'),
                'selesai' => $baris->sum('selesai'),
                'terputus' => $baris->sum('terputus'),
                'pelanggaran' => $baris->sum('pelanggaran'),
            ],
        ]);
    }

    /**
     * Koreksi presensi oleh Admin - dan alasannya WAJIB, selalu.
     *
     * Lihat catatan kelas ini tentang mengapa Admin tidak mewarisi
     * keringanan plate 17. Pesan galatnya menyebut sebabnya, bukan sekadar
     * "wajib diisi".
     */
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

        // Barisnya dicari lewat PENEMPATANNYA, dan penempatan itu harus
        // berada di salah satu ruang-sesi event ini. Tanpa syarat kedua, id
        // dari event lain akan ditemukan - dan Admin yang membuka event A
        // bisa mengubah presensi event B dengan menyunting satu angka.
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

    /* ------------------------------------------------------------- Pembantu */

    /**
     * Baris presensi satu ruang, untuk daftar peserta di laman Admin.
     *
     * Admin tidak memanggil nama satu per satu - tetapi ia MENGOREKSI, dan
     * koreksi bekerja atas baris presensi seseorang. Tanpa daftar ini,
     * laman presensi Admin hanya bisa memberi tahu bahwa satu ruangan
     * tertinggal tanpa memberi jalan memperbaikinya, dan Admin yang
     * menemukan anak yang tercatat alpa akan menelepon pengawasnya.
     *
     * `record_id` ada di setiap baris karena itulah yang dikirim kembali
     * saat koreksi - bukan `peserta_id`. Koreksi atas baris yang belum ada
     * akan gagal, dan penempatan yang belum punya baris presensi karena itu
     * ditampilkan tanpa tombolnya.
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
     * Satu baris presensi, dicari HANYA di dalam event ini.
     *
     * Pencariannya lewat penempatannya, dan penempatan itu harus berada di
     * salah satu ruang-sesi event ini. Tanpa syarat kedua, id dari event
     * lain akan ditemukan - dan Admin yang membuka event A bisa mengubah
     * presensi event B dengan menyunting satu angka.
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
     * Ruang-sesi seluruh event, beserta penempatan dan pengawasnya.
     *
     * Satu query untuk seluruh laman - dua laman Admin memakai daftar yang
     * sama, dan daftar yang disusun ulang di tiap method adalah daftar yang
     * suatu hari berbeda urutannya.
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
                // Nama dan NIS ikut, karena laman presensi Admin
                // menampilkan daftar anaknya - lihat `peserta()`. NIS
                // datang dari profil siswa: yang menyimpannya adalah
                // StudentProfile, dan menyalinnya ke peserta event berarti
                // dua tempat yang bisa berbeda diam-diam saat siswa pindah
                // kelas.
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
}

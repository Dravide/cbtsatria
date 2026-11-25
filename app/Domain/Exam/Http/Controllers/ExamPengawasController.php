<?php

namespace App\Domain\Exam\Http\Controllers;

use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Exam\Models\SupervisionAssignment;
use App\Domain\Exam\Models\SupervisorSessionExclusion;
use App\Domain\Exam\Services\PengawasService;
use App\Domain\Identity\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pengawas & penempatan ruang - wireframe plate 17, PRD §9.6.
 *
 * ---------------------------------------------------------------------
 * Panitia berjabatan pengawas BUKAN penugasan, dan laman ini tempat bedanya
 * ---------------------------------------------------------------------
 * Plate 13 mencatat siapa saja pengawasnya di tingkat event - satu daftar
 * nama. Laman ini mencatat siapa yang berdiri di R-02 pada sesi 1. Plate 17
 * menyatakan mengapa pemisahan itu menentukan:
 *
 *   "penugasan inilah yang memberi Pengawas hak melihat dan memverifikasi
 *    presensi di ruang itu. Tanpa penugasan, ia tidak melihat apa pun."
 *
 * Karena itu halaman ini TIDAK membaca kepanitiaan sebagai penugasan. Ia
 * memakainya hanya sebagai daftar calon di panel "Tugaskan" dan di tombol
 * "Tugaskan otomatis" - supaya Admin tidak mengetik ulang nama yang sudah ia
 * masukkan di plate 13.
 *
 * ---------------------------------------------------------------------
 * Baris tabelnya adalah RUANG-SESI, bukan ruang
 * ---------------------------------------------------------------------
 * Plate 17 menampilkan `Ruang | Pengawas 1 | Pengawas 2 | Sesi`, dan barisnya
 * terbaca seperti satu ruang. Yang disimpan tabelnya bukan itu: `R-01` muncul
 * tiga kali bila ia dipakai tiga sesi, karena pengawas sesi pagi belum tentu
 * pengawas sesi siang - dan menugaskan satu orang untuk "R-01" tanpa menyebut
 * sesinya berarti ia dikunci sepanjang hari.
 *
 * Karena itu barisnya adalah (ruang, sesi), dan kolom `Sesi` menyebut sesi
 * mana yang dimaksud. Kolom itu bukan hiasan; ia satu-satunya yang membuat
 * barisnya bisa dibedakan.
 */
class ExamPengawasController
{
    public function index(SchoolEvent $event, PengawasService $penugasan): Response
    {
        Gate::authorize('view', $event);

        $sesi = ExamSession::query()
            ->whereIn('exam_id', $event->exams()->pluck('id'))
            ->with(['exam:id,code,title,subject_id', 'exam.subject:id,name'])
            ->orderBy('access_opens_at')
            ->orderBy('id')
            ->get();

        $ruangan = ExamSessionRoom::query()
            ->whereIn('exam_session_id', $sesi->pluck('id'))
            ->with([
                'eventRoom.room:id,code,name,capacity',
                'session:id,exam_id,session_number,access_opens_at,hard_end_at',
                'session.exam:id,code,title,subject_id',
                'session.exam.subject:id,name',
                'supervisors.supervisor:id,name,username,role',
            ])
            ->withCount(['participants as terisi' => fn ($q) => $q->where('status', 'terdaftar')])
            ->orderBy('exam_session_id')
            ->orderBy('id')
            ->get();

        $kelasPerRuangSesi = $ruangan->isEmpty()
            ? collect()
            : ExamParticipant::query()
                ->join('event_participants', 'event_participants.id', '=', 'exam_participants.event_participant_id')
                ->join('classes', 'classes.id', '=', 'event_participants.class_id')
                ->whereIn('exam_participants.exam_session_room_id', $ruangan->pluck('id'))
                ->where('exam_participants.status', ExamParticipant::STATUS_TERDAFTAR)
                ->select('exam_participants.exam_session_room_id', 'classes.name as kelas_nama')
                ->distinct()
                ->orderBy('classes.name')
                ->get()
                ->groupBy('exam_session_room_id')
                ->map(fn ($items) => $items->pluck('kelas_nama')->values()->all());

        $baris = $ruangan->map(fn (ExamSessionRoom $r) => [
            'id' => $r->id,
            'ruang' => $r->eventRoom?->room?->code,
            'ruang_nama' => $r->eventRoom?->room?->name,
            'kapasitas' => $r->kapasitasEfektif(),
            'kelas' => $kelasPerRuangSesi->get($r->id, []),
            'sesi' => $r->session?->label(),
            'sesi_id' => $r->session?->id,
            'ujian' => $r->session?->exam?->subject?->name ?? $r->session?->exam?->title,
            'jam' => $r->session?->access_opens_at?->format('H:i'),
            'selesai' => $r->session?->hard_end_at?->format('H:i'),
            'tanggal' => $r->session?->access_opens_at?->format('d M'),
            'hari_tanggal' => $r->session?->access_opens_at?->locale('id')->translatedFormat('l, d M Y'),

            // Pengawas dikirim sebagai daftar, bukan dua kolom tetap: PRD §9.6
            // mengizinkan lebih dari dua pengawas per ruang, dan kolom tetap
            // berarti orang ketiga tidak punya tempat untuk muncul - ia
            // tersimpan, tetapi tidak terlihat di laman yang menugaskannya.
            'pengawas' => $r->supervisors
                ->map(fn (SupervisionAssignment $t) => [
                    'id' => $t->id,
                    'user_id' => $t->supervisor_id,
                    'nama' => $t->supervisor?->name,
                    'username' => $t->supervisor?->username,
                    'status' => $t->status,
                ])
                ->values()
                ->all(),

            'peserta' => (int) $r->terisi,
        ])->all();

        // Ruang tanpa pengawas dibaca dari PengawasService, bukan dihitung ulang
        // di sini: angka yang sama dipakai SesiService untuk menolak membuka
        // sesi, dan KPI yang bilang "0" sementara sesinya menolak dibuka adalah
        // layar yang bertengkar dengan dirinya sendiri.
        $tanpaPengawas = $penugasan->ruangTanpaPengawas($event);

        $aturan = $penugasan->aturan($event);
        $bebanEvent = $penugasan->bebanEvent($event);
        $kuotaPerOrang = $penugasan->kuotaPerOrang($event);
        $pengecualian = $penugasan->pengecualianEvent($event);
        $calonUsers = $penugasan->calonPengawas($event);

        $penugasanPerPengawas = [];
        foreach ($ruangan as $r) {
            foreach ($r->supervisors as $t) {
                if ($t->status !== 'aktif') {
                    continue;
                }
                $penugasanPerPengawas[$t->supervisor_id][] = [
                    'sesi_id' => $r->exam_session_id,
                    'sesi_label' => $r->session?->label() ?? 'Sesi',
                    'ujian' => $r->session?->exam?->subject?->name ?? $r->session?->exam?->title,
                    'tanggal' => $r->session?->access_opens_at?->format('d M'),
                    'jam' => $r->session?->access_opens_at?->format('H:i'),
                    'ruang' => $r->eventRoom?->room?->code ?? '—',
                ];
            }
        }

        $matriks = collect($calonUsers)->map(function (User $u) use (
            $aturan,
            $bebanEvent,
            $kuotaPerOrang,
            $pengecualian,
            $sesi,
            $penugasanPerPengawas,
        ): array {
            $terpakai = $bebanEvent[$u->id] ?? 0;
            $maksKhusus = $kuotaPerOrang[$u->id] ?? null;
            $maks = $maksKhusus !== null ? $maksKhusus : $aturan['maks_per_event'];
            $dikecualikanSesi = [];
            $alasanSesi = [];
            foreach ($sesi as $s) {
                $kunci = "{$s->id}:{$u->id}";
                if (array_key_exists($kunci, $pengecualian)) {
                    $dikecualikanSesi[] = $s->id;
                    if ($pengecualian[$kunci] !== null) {
                        $alasanSesi[(string) $s->id] = $pengecualian[$kunci];
                    }
                }
            }

            $statusKuota = 'aman';
            if ($maks > 0 && $terpakai > $maks) {
                $statusKuota = 'melebihi';
            } elseif ($maks > 0 && $terpakai === $maks) {
                $statusKuota = 'penuh';
            }

            return [
                'id' => $u->id,
                'nama' => $u->name,
                'username' => $u->username,
                'peran' => $u->role->label(),
                'terpakai' => $terpakai,
                'maks' => $maks,
                'maks_khusus' => $maksKhusus,
                'dikecualikan_sesi' => $dikecualikanSesi,
                'alasan_sesi' => $alasanSesi,
                'penugasan' => $penugasanPerPengawas[$u->id] ?? [],
                'status_kuota' => $statusKuota,
            ];
        })->values()->all();

        $melebihiBatas = collect($matriks)->where('status_kuota', 'melebihi')->count();

        $daftarPengecualian = SupervisorSessionExclusion::query()
            ->where('event_id', $event->id)
            ->with([
                'supervisor:id,name,username',
                'session:id,exam_id,session_number,access_opens_at,hard_end_at',
                'session.exam:id,title,subject_id',
                'session.exam.subject:id,name',
            ])
            ->orderBy('exam_session_id')
            ->orderBy('id')
            ->get()
            ->map(fn (SupervisorSessionExclusion $e) => [
                'id' => $e->id,
                'supervisor_id' => $e->supervisor_id,
                'nama' => $e->supervisor?->name ?? 'Pengawas',
                'username' => $e->supervisor?->username,
                'exam_session_id' => $e->exam_session_id,
                'sesi_label' => $e->session?->label() ?? 'Sesi',
                'ujian' => $e->session?->exam?->subject?->name ?? $e->session?->exam?->title,
                'hari_tanggal' => $e->session?->access_opens_at?->locale('id')->translatedFormat('l, d M Y'),
                'jam' => $e->session?->access_opens_at?->format('H:i'),
                'reason' => $e->reason ?? '',
            ])
            ->values()
            ->all();

        return Inertia::render('Admin/Event/Pengawas', [
            'event' => $this->ringkas($event),
            'baris' => $baris,
            'per_sesi' => $this->kelompokkanPerSesi($sesi, $ruangan, $kelasPerRuangSesi),

            'angka' => [
                'ruang' => $ruangan->count(),
                'ditugaskan' => $penugasan->jumlahPenugasan($event),
                'tanpa_pengawas' => $tanpaPengawas,
                'saran_per_ruang' => PengawasService::SARAN_PER_RUANG,
                'minimal_per_ruang' => PengawasService::MINIMAL_PER_RUANG,
                'melebihi_batas' => $melebihiBatas,
            ],

            'saran' => collect($baris)
                ->filter(fn (array $b) => count($b['pengawas']) > 0
                    && count($b['pengawas']) < PengawasService::SARAN_PER_RUANG)
                ->values()
                ->all(),

            'calon' => collect($calonUsers)
                ->map(fn (User $u) => [
                    'id' => $u->id,
                    'nama' => $u->name,
                    'username' => $u->username,
                    'peran' => $u->role->label(),
                ])
                ->values()
                ->all(),

            'aturan' => $aturan,
            'matriks' => $matriks,
            'daftar_pengecualian' => $daftarPengecualian,
            'sesi_kolom' => $sesi->map(fn (ExamSession $s) => [
                'id' => $s->id,
                'label' => $s->label(),
                'ujian' => $s->exam?->subject?->name ?? $s->exam?->title,
                'tanggal' => $s->access_opens_at?->format('d M'),
                'tanggal_iso' => $s->access_opens_at?->toDateString(),
                'jam' => $s->access_opens_at?->format('H:i'),
            ])->values()->all(),
            'beban_pengawas' => collect($matriks)->mapWithKeys(fn (array $m) => [
                $m['id'] => ['terpakai' => $m['terpakai'], 'maks' => $m['maks']],
            ])->all(),

            'jalur_sesi' => "/admin/events/{$event->id}/pengawas/sesi",

            'sesi_tanpa_ruang' => $sesi
                ->reject(fn (ExamSession $s) => $ruangan->contains('exam_session_id', $s->id))
                ->map(fn (ExamSession $s) => [
                    'id' => $s->id,
                    'label' => $s->label(),
                    'ujian' => $s->exam?->subject?->name ?? $s->exam?->title,
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * Unduh jadwal pengawasan satu event sebagai dokumen PDF (A4 portrait).
     */
    public function pdf(SchoolEvent $event): HttpResponse
    {
        Gate::authorize('view', $event);

        $event->load(['school', 'academicYear', 'semester']);

        $sesi = ExamSession::query()
            ->whereIn('exam_id', $event->exams()->pluck('id'))
            ->with(['exam:id,code,title,subject_id', 'exam.subject:id,code,name'])
            ->orderBy('access_opens_at')
            ->orderBy('session_number')
            ->orderBy('id')
            ->get();

        $ruangan = ExamSessionRoom::query()
            ->whereIn('exam_session_id', $sesi->pluck('id'))
            ->with([
                'eventRoom.room:id,code,name,location',
                'supervisors.supervisor:id,name,username',
            ])
            ->orderBy('exam_session_id')
            ->orderBy('id')
            ->get();

        $totalPenugasan = 0;

        $daftarSesi = $sesi->map(function (ExamSession $s) use ($ruangan, &$totalPenugasan): ?array {
            $ruangSesi = $ruangan->where('exam_session_id', $s->id)->values();

            if ($ruangSesi->isEmpty()) {
                return null;
            }

            $barisRuang = $ruangSesi->map(function (ExamSessionRoom $r) use (&$totalPenugasan): array {
                $pengawas = $r->supervisors
                    ->filter(fn (SupervisionAssignment $t) => $t->status === 'aktif' && $t->supervisor !== null)
                    ->map(fn (SupervisionAssignment $t) => $t->supervisor->name)
                    ->values()
                    ->all();

                $totalPenugasan += count($pengawas);

                return [
                    'kode' => $r->eventRoom?->room?->code ?? '—',
                    'nama' => $r->eventRoom?->room?->name ?? '—',
                    'lokasi' => $r->eventRoom?->room?->location,
                    'pengawas' => $pengawas,
                ];
            })->all();

            return [
                'label' => $s->label(),
                'mapel' => $s->exam?->subject?->name ?? $s->exam?->title ?? 'Ujian',
                'hari_tanggal' => $s->access_opens_at?->locale('id')->translatedFormat('l, d M Y') ?? '—',
                'waktu' => $s->access_opens_at
                    ? $s->access_opens_at->format('H:i').'–'.($s->hard_end_at?->format('H:i') ?? '?')
                    : '—',
                'ruang' => $barisRuang,
            ];
        })->filter()->values()->all();

        $kode = preg_replace('/[^A-Za-z0-9\-_]+/', '-', strtolower($event->code)) ?: (string) $event->id;

        return Pdf::loadView('laporan.jadwal-pengawasan', [
            'sekolah' => $event->school,
            'event' => $event,
            'sesi' => $daftarSesi,
            'ringkasan' => [
                'sesi' => count($daftarSesi),
                'ruang' => $ruangan->count(),
                'penugasan' => $totalPenugasan,
            ],
            'dicetak' => now()->format('d/m/Y H:i'),
        ])
            ->setPaper('a4', 'portrait')
            ->download("jadwal-pengawasan-{$kode}.pdf");
    }

    /**
     * Satu SESI: daftar ruang beserta pengawasnya - laman tersendiri.
     *
     * ---------------------------------------------------------------------
     * Mengapa dipisah dari laman pengawas
     * ---------------------------------------------------------------------
     * Event dengan tiga puluh tiga ruang per sesi dan dua belas sesi
     * menghasilkan empat ratus baris yang semuanya dapat ditugaskan. Dirender
     * di satu laman, daftar SESINYA sendiri tenggelam - padahal itulah yang
     * dipindai Admin lebih dulu ("sesi mana yang belum berpengawas").
     *
     * Laman induk kini hanya menampilkan ringkasan per sesi; pekerjaan
     * menugaskan terjadi di sini, satu sesi pada satu waktu.
     */
    public function sesi(SchoolEvent $event, ExamSession $sesi, PengawasService $penugasan): Response
    {
        Gate::authorize('view', $event);

        // Sesi harus benar-benar milik event di URL. Binding implisit
        // menemukan sesi di seluruh sekolah, dan scope tenant tidak menutupnya
        // karena sekolahnya sama - 404, bukan 403.
        abort_unless($sesi->exam?->event_id === $event->id, 404);

        $sesi->load(['exam:id,code,title,subject_id', 'exam.subject:id,name']);

        $ruangan = ExamSessionRoom::query()
            ->where('exam_session_id', $sesi->id)
            ->with([
                'eventRoom.room:id,code,name,capacity',
                'supervisors.supervisor:id,name,username,role',
            ])
            ->withCount(['participants as terisi' => fn ($q) => $q->where('status', 'terdaftar')])
            ->orderBy('id')
            ->get();

        $daftarRuang = $ruangan->map(fn (ExamSessionRoom $r) => [
            'id' => $r->id,
            'ruang' => $r->eventRoom?->room?->code,
            'ruang_nama' => $r->eventRoom?->room?->name,
            'kapasitas' => $r->kapasitasEfektif(),
            'peserta' => (int) $r->terisi,
            'pengawas' => $r->supervisors
                ->map(fn (SupervisionAssignment $t) => [
                    'id' => $t->id,
                    'user_id' => $t->supervisor_id,
                    'nama' => $t->supervisor?->name,
                    'username' => $t->supervisor?->username,
                    'status' => $t->status,
                ])
                ->values()
                ->all(),
        ])->all();

        $aturan = $penugasan->aturan($event);
        $bebanEvent = $penugasan->bebanEvent($event);
        $kuotaPerOrang = $penugasan->kuotaPerOrang($event);
        $pengecualian = $penugasan->pengecualianEvent($event);

        return Inertia::render('Admin/Event/PengawasSesi', [
            'event' => $this->ringkas($event),
            'sesi' => [
                'id' => $sesi->id,
                'label' => $sesi->label(),
                'ujian' => $sesi->exam?->subject?->name ?? $sesi->exam?->title,
                'tanggal' => $sesi->access_opens_at?->format('d M'),
                'jam' => $sesi->access_opens_at?->format('H:i'),
                'selesai' => $sesi->hard_end_at?->format('H:i'),
                'status' => $sesi->status->value,
                'status_label' => $sesi->status->label(),
                'jenis' => $sesi->jenis?->value ?? 'utama',
                'susulan' => $sesi->susulan(),
            ],
            'ruang' => $daftarRuang,
            'aturan' => $aturan,

            'angka' => [
                'ruang' => count($daftarRuang),
                'tanpa_pengawas' => collect($daftarRuang)
                    ->filter(fn (array $r) => $r['pengawas'] === [])
                    ->count(),
                'saran_per_ruang' => $aturan['target_per_ruang'],
                'minimal_per_ruang' => PengawasService::MINIMAL_PER_RUANG,
            ],

            'calon' => collect($penugasan->calonPengawas($event))
                ->map(function (User $u) use ($aturan, $bebanEvent, $kuotaPerOrang, $pengecualian, $sesi): array {
                    $terpakai = $bebanEvent[$u->id] ?? 0;
                    $maks = $kuotaPerOrang[$u->id] ?? $aturan['maks_per_event'];
                    $kunci = "{$sesi->id}:{$u->id}";

                    return [
                        'id' => $u->id,
                        'nama' => $u->name,
                        'username' => $u->username,
                        'peran' => $u->role->label(),
                        'terpakai' => $terpakai,
                        'maks' => $maks,
                        'dikecualikan' => array_key_exists($kunci, $pengecualian),
                        'alasan_pengecualian' => $pengecualian[$kunci] ?? null,
                    ];
                })
                ->values()
                ->all(),

            'jalur_induk' => "/admin/events/{$event->id}/pengawas",
        ]);
    }

    /**
     * Tugaskan satu pengawas ke satu ruang-sesi.
     *
     * Bentrok ditolak di sini DAN dilaporkan di plate 16, dan itu disengaja:
     * KonflikJadwal menilai keadaan jadwal yang sudah tersimpan, sementara
     * pemeriksaan ini mencegah penugasan yang bentrok pernah tersimpan sama
     * sekali.
     */
    public function tugaskan(
        Request $request,
        SchoolEvent $event,
        ExamSessionRoom $ruang,
        PengawasService $service,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $event);
        $this->pastikanRuangMilikEvent($ruang, $event);

        $data = $request->validate([
            'supervisor_id' => ['required', 'integer'],
        ]);

        $pengawas = User::query()->find($data['supervisor_id']);

        if ($pengawas === null) {
            return back()->with('galat', 'Akun pengawas itu tidak ditemukan.');
        }

        try {
            $service->tugaskan($ruang, $pengawas, (int) $request->user()->id);
        } catch (ValidationException $e) {
            return back()->with('galat', $e->validator->errors()->first());
        } catch (DomainException $e) {
            return back()->with('galat', $e->getMessage());
        }

        $logger->log('Menugaskan pengawas', $event, [
            'ruang' => $ruang->eventRoom?->room?->code,
            'pengawas' => $pengawas->name,
        ]);

        return back()->with('sukses', "{$pengawas->name} ditugaskan di ruang itu.");
    }

    public function batalkan(
        SchoolEvent $event,
        SupervisionAssignment $tugas,
        PengawasService $service,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $event);
        $this->pastikanTugasMilikEvent($tugas, $event);

        $nama = $tugas->supervisor?->name ?? 'Pengawas';

        $service->batalkan($tugas);

        $logger->log('Membatalkan penugasan pengawas', $event, ['pengawas' => $nama]);

        return back()->with('sukses', "Penugasan {$nama} dibatalkan.");
    }

    /**
     * Tugaskan otomatis seluruh ruang yang belum cukup pengawas.
     *
     * Yang bentrok DILEWATI, bukan dijadikan galat: gunanya tombol ini
     * menyelesaikan pekerjaan yang membosankan, dan berhenti pada benturan
     * pertama berarti Admin tetap harus menugaskan sisanya satu per satu -
     * pekerjaan yang justru ingin ia hindari.
     */
    public function otomatis(
        Request $request,
        SchoolEvent $event,
        PengawasService $service,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $event);

        $data = $request->validate([
            'mode' => ['nullable', 'in:cepat,expert'],
        ]);
        $mode = $data['mode'] ?? 'cepat';

        try {
            $hasil = $service->tugaskanOtomatis($event, (int) $request->user()->id, null, $mode);
        } catch (DomainException $e) {
            return back()->with('galat', $e->getMessage());
        }

        $logger->log('Menugaskan pengawas otomatis', $event, $hasil);

        if ($hasil['penugasan'] === 0 && $hasil['dilewati'] === 0) {
            return back()->with('sukses', 'Setiap ruang sudah punya pengawas yang cukup.');
        }

        if ($mode === 'expert') {
            $detik = number_format(($hasil['durasi_ms'] ?? 0) / 1000, 2, ',', '.');
            $iterasi = $hasil['iterasi'] ?? 1;
            $pesan = "Mode Expert selesai ({$detik} dtk, {$iterasi} percobaan): {$hasil['penugasan']} penugasan dibuat di {$hasil['ruang']} ruang.";

            if ($hasil['dilewati'] > 0) {
                $detail = ! empty($hasil['diagnosis'])
                    ? ' '.$hasil['diagnosis']
                    : " {$hasil['dilewati']} slot belum terpenuhi karena batas kuota/pengecualian/bentrok.";

                return back()->with('galat', $pesan.$detail);
            }

            return back()->with('sukses', $pesan.' Seluruh slot terpenuhi.');
        }

        $pesan = "{$hasil['penugasan']} penugasan dibuat di {$hasil['ruang']} ruang.";

        if ($hasil['dilewati'] > 0) {
            // Disebutkan, bukan disembunyikan: orang yang dilewati karena
            // bentrok meninggalkan ruang yang tetap kurang pengawas, dan Admin
            // perlu tahu bahwa sebagian pekerjaan belum selesai.
            return back()->with(
                'galat',
                $pesan." {$hasil['dilewati']} calon dilewati karena bentrok jadwal - tugaskan manual.",
            );
        }

        return back()->with('sukses', $pesan);
    }

    public function simpanAturan(
        Request $request,
        SchoolEvent $event,
        PengawasService $service,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $event);

        $data = $request->validate([
            'maks_per_event' => ['required', 'integer', 'min:0', 'max:50'],
            'maks_per_hari' => ['required', 'integer', 'min:0', 'max:20'],
            'target_per_ruang' => ['required', 'integer', 'in:1,2'],
            'prioritas' => ['required', 'string', 'in:merata,urutan'],
            'hormati_pengecualian' => ['required', 'boolean'],
            'hindari_wali_kelas' => ['required', 'boolean'],
            'hindari_guru_mapel' => ['required', 'boolean'],
            'tolak_melebihi_kuota' => ['required', 'boolean'],
        ]);

        $aturan = $service->simpanAturan($event, $data);
        $logger->log('Memperbarui aturan penjadwalan pengawas', $event, $aturan);

        return back()->with('sukses', 'Aturan penjadwalan pengawas disimpan.');
    }

    public function togglePengecualian(
        Request $request,
        SchoolEvent $event,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $event);

        $data = $request->validate([
            'exam_session_id' => ['required', 'integer'],
            'supervisor_id' => ['required', 'integer'],
            'reason' => ['nullable', 'string', 'max:120'],
        ]);

        $sesi = ExamSession::query()->with('exam')->find($data['exam_session_id']);
        abort_unless($sesi !== null && $sesi->exam?->event_id === $event->id, 404);

        $existing = SupervisorSessionExclusion::query()
            ->where('event_id', $event->id)
            ->where('exam_session_id', $sesi->id)
            ->where('supervisor_id', $data['supervisor_id'])
            ->first();

        if ($existing !== null) {
            $existing->delete();
            $logger->log('Mengaktifkan kembali ketersediaan pengawas di sesi', $event, $data);

            return back()->with('sukses', 'Pengawas ditandai tersedia pada sesi tersebut.');
        }

        SupervisorSessionExclusion::query()->create([
            'school_id' => $event->school_id,
            'event_id' => $event->id,
            'exam_session_id' => $sesi->id,
            'supervisor_id' => (int) $data['supervisor_id'],
            'reason' => ($data['reason'] ?? null) ?: null,
            'created_by' => (int) $request->user()->id,
        ]);

        $logger->log('Mengosongkan ketersediaan pengawas di sesi', $event, $data);

        return back()->with('sukses', 'Pengawas dikosongkan pada sesi tersebut.');
    }

    public function kosongkanHari(
        Request $request,
        SchoolEvent $event,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $event);

        $data = $request->validate([
            'supervisor_id' => ['required', 'integer'],
            'tanggal' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:120'],
        ]);

        $idSesiHari = ExamSession::query()
            ->whereIn('exam_id', $event->exams()->pluck('id'))
            ->whereDate('access_opens_at', $data['tanggal'])
            ->pluck('id');

        $dibuat = 0;
        foreach ($idSesiHari as $sesiId) {
            $baris = SupervisorSessionExclusion::query()->firstOrCreate(
                [
                    'event_id' => $event->id,
                    'exam_session_id' => $sesiId,
                    'supervisor_id' => (int) $data['supervisor_id'],
                ],
                [
                    'school_id' => $event->school_id,
                    'reason' => ($data['reason'] ?? null) ?: 'Libur / izin harian',
                    'created_by' => (int) $request->user()->id,
                ],
            );

            if ($baris->wasRecentlyCreated) {
                $dibuat++;
            }
        }

        $logger->log('Mengosongkan pengawas satu hari penuh', $event, [
            'supervisor_id' => $data['supervisor_id'],
            'tanggal' => $data['tanggal'],
            'sesi' => $dibuat,
        ]);

        return back()->with('sukses', "{$dibuat} sesi pada tanggal tersebut dikosongkan.");
    }

    public function simpanKuota(
        Request $request,
        SchoolEvent $event,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $event);

        $data = $request->validate([
            'supervisor_id' => ['required', 'integer'],
            'maks_mengawas' => ['nullable', 'integer', 'min:0', 'max:50'],
        ]);

        $updated = $event->committee()
            ->where('user_id', (int) $data['supervisor_id'])
            ->update(['maks_mengawas' => $data['maks_mengawas']]);

        abort_unless($updated > 0, 404);

        $logger->log('Memperbarui kuota mengawas pengawas', $event, $data);

        return back()->with('sukses', 'Kuota pengawas diperbarui.');
    }

    public function simpanKuotaMassal(
        Request $request,
        SchoolEvent $event,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $event);

        $data = $request->validate([
            'kuota' => ['required', 'array', 'min:1'],
            'kuota.*.supervisor_id' => ['required', 'integer'],
            'kuota.*.maks_mengawas' => ['nullable', 'integer', 'min:0', 'max:50'],
        ]);

        $diperbarui = 0;

        DB::transaction(function () use ($event, $data, &$diperbarui) {
            foreach ($data['kuota'] as $item) {
                $diperbarui += $event->committee()
                    ->where('user_id', (int) $item['supervisor_id'])
                    ->update(['maks_mengawas' => $item['maks_mengawas'] ?? null]);
            }
        });

        $logger->log('Memperbarui kuota mengawas secara massal', $event, [
            'jumlah' => $diperbarui,
        ]);

        return back()->with('sukses', "Kuota mengawas untuk {$diperbarui} pengawas diperbarui.");
    }

    public function otomatisSesi(
        Request $request,
        SchoolEvent $event,
        ExamSession $sesi,
        PengawasService $service,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $event);
        $sesi->loadMissing('exam');
        abort_unless($sesi->exam?->event_id === $event->id, 404);

        try {
            $hasil = $service->tugaskanOtomatis($event, (int) $request->user()->id, $sesi->id);
        } catch (DomainException $e) {
            return back()->with('galat', $e->getMessage());
        }

        $logger->log('Menugaskan pengawas otomatis per sesi', $event, ['sesi_id' => $sesi->id, ...$hasil]);

        if ($hasil['penugasan'] === 0 && $hasil['dilewati'] === 0) {
            return back()->with('sukses', 'Setiap ruang pada sesi ini sudah punya pengawas yang cukup.');
        }

        $pesan = "{$hasil['penugasan']} penugasan dibuat di {$hasil['ruang']} ruang.";

        if ($hasil['dilewati'] > 0) {
            return back()->with('galat', $pesan." {$hasil['dilewati']} calon dilewati karena kuota/pengecualian/bentrok.");
        }

        return back()->with('sukses', $pesan);
    }

    public function kosongkanSesi(
        SchoolEvent $event,
        ExamSession $sesi,
        PengawasService $service,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $event);
        $sesi->loadMissing('exam');
        abort_unless($sesi->exam?->event_id === $event->id, 404);

        $dihapus = $service->kosongkanSesi($sesi);
        $logger->log('Mengosongkan penugasan pengawas satu sesi', $event, ['sesi_id' => $sesi->id, 'dihapus' => $dihapus]);

        return back()->with('sukses', "{$dihapus} penugasan pengawas pada sesi ini dihapus.");
    }

    /**
     * Reset seluruh penugasan pengawas pada semua sesi event ini.
     */
    public function resetPengawasan(
        SchoolEvent $event,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $event);

        $sesiIds = ExamSession::query()
            ->whereIn('exam_id', $event->exams()->pluck('id'))
            ->pluck('id');

        $ruangIds = ExamSessionRoom::query()
            ->whereIn('exam_session_id', $sesiIds)
            ->pluck('id');

        $dihapus = $ruangIds->isEmpty()
            ? 0
            : SupervisionAssignment::query()
                ->whereIn('exam_session_room_id', $ruangIds)
                ->delete();

        if ($dihapus === 0) {
            return back()->with('galat', 'Belum ada penugasan pengawas yang dapat direset.');
        }

        $logger->log('Mereset seluruh penugasan pengawas event', $event, ['dihapus' => $dihapus]);

        return back()->with('sukses', "{$dihapus} penugasan pengawas pada event ini dihapus.");
    }

    public function ubahPengecualian(
        Request $request,
        SchoolEvent $event,
        SupervisorSessionExclusion $pengecualian,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $event);
        abort_unless((int) $pengecualian->event_id === (int) $event->id, 404);

        $data = $request->validate([
            'exam_session_id' => ['required', 'integer'],
            'reason' => ['nullable', 'string', 'max:120'],
        ]);

        $sesi = ExamSession::query()->with('exam')->find($data['exam_session_id']);
        abort_unless($sesi !== null && (int) $sesi->exam?->event_id === (int) $event->id, 404);

        $duplikat = SupervisorSessionExclusion::query()
            ->where('event_id', $event->id)
            ->where('exam_session_id', $sesi->id)
            ->where('supervisor_id', $pengecualian->supervisor_id)
            ->whereKeyNot($pengecualian->id)
            ->exists();

        if ($duplikat) {
            return back()->with('galat', 'Pengawas tersebut sudah memiliki pengecualian pada sesi tujuan.');
        }

        $pengecualian->update([
            'exam_session_id' => $sesi->id,
            'reason' => ($data['reason'] ?? '') !== '' ? $data['reason'] : null,
        ]);

        $logger->log('Memperbarui pengecualian pengawas', $event, [
            'id' => $pengecualian->id,
            'supervisor_id' => $pengecualian->supervisor_id,
            'exam_session_id' => $sesi->id,
        ]);

        return back()->with('sukses', 'Pengecualian pengawas diperbarui.');
    }

    public function hapusPengecualian(
        SchoolEvent $event,
        SupervisorSessionExclusion $pengecualian,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $event);
        abort_unless((int) $pengecualian->event_id === (int) $event->id, 404);

        $info = [
            'id' => $pengecualian->id,
            'supervisor_id' => $pengecualian->supervisor_id,
            'exam_session_id' => $pengecualian->exam_session_id,
        ];

        $pengecualian->delete();

        $logger->log('Menghapus pengecualian pengawas', $event, $info);

        return back()->with('sukses', 'Pengecualian pengawas dihapus.');
    }

    /* ------------------------------------------------------------ Pembantu */

    /**
     * Ruang-sesi harus benar-benar milik event di URL.
     *
     * Binding implisit menemukan id di seluruh sekolah, dan scope tenant tidak
     * menutupnya karena sekolahnya sama. Tanpa pemeriksaan ini, penugasan di
     * event B bisa dibuat lewat URL event A. 404, bukan 403: bagi event ini
     * baris itu tidak ada.
     */
    private function pastikanRuangMilikEvent(ExamSessionRoom $ruang, SchoolEvent $event): void
    {
        abort_unless($ruang->session?->exam?->event_id === $event->id, 404);
    }

    /**
     * Tukar dua penugasan pengawas - ditambahkan 2026-11-14.
     *
     * ---------------------------------------------------------------------
     * Yang dikirim adalah DUA id penugasan, bukan dua id orang
     * ---------------------------------------------------------------------
     * Penugasan menempel pada ruang-SESI, dan orang yang sama bisa memegang
     * dua penugasan sah di sesi berbeda. Mengirim `supervisor_id` berarti
     * laman harus memilih penugasan mana yang dimaksud - dan pilihan itu
     * bisa salah tanpa terlihat. Yang dikirim karena itu id BARIS-nya.
     *
     * ---------------------------------------------------------------------
     * Kedua baris harus milik event ini
     * ---------------------------------------------------------------------
     * Binding implisit hanya menjamin keduanya milik sekolah yang sama.
     * Tanpa pemeriksaan tambahan, Admin yang membuka event A bisa menukar
     * pengawas event B dengan satu angka di URL.
     */
    public function tukar(
        Request $request,
        SchoolEvent $event,
        PengawasService $service,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $event);

        $data = $request->validate([
            'a' => ['required', 'integer'],
            'b' => ['required', 'integer', 'different:a'],
        ], [
            'b.different' => 'Pilih dua penugasan yang berbeda.',
        ], ['a' => 'penugasan pertama', 'b' => 'penugasan kedua']);

        $a = SupervisionAssignment::query()->with('room.session.exam')->find($data['a']);
        $b = SupervisionAssignment::query()->with('room.session.exam')->find($data['b']);

        if ($a === null || $b === null) {
            return back()->with('galat', 'Salah satu penugasan itu sudah tidak ada.');
        }

        $this->pastikanTugasMilikEvent($a, $event);
        $this->pastikanTugasMilikEvent($b, $event);

        $namaA = $a->supervisor?->name ?? 'Pengawas';
        $namaB = $b->supervisor?->name ?? 'Pengawas';

        try {
            $service->tukar($a, $b);
        } catch (ValidationException $e) {
            return back()->with('galat', $e->validator->errors()->first());
        }

        $logger->log('Menukar pengawas antar ruang', $event, [
            'a' => $namaA,
            'a_ruang' => $a->room?->eventRoom?->room?->code,
            'b' => $namaB,
            'b_ruang' => $b->room?->eventRoom?->room?->code,
        ]);

        return back()->with(
            'sukses',
            "{$namaA} dan {$namaB} bertukar ruang.",
        );
    }

    private function pastikanTugasMilikEvent(SupervisionAssignment $tugas, SchoolEvent $event): void
    {
        abort_unless($tugas->room?->session?->exam?->event_id === $event->id, 404);
    }

    /** @return array<string, mixed> */
    /**
     * Baris ruang-sesi, dikelompokkan per SESI.
     *
     * ---------------------------------------------------------------------
     * Mengapa kelompoknya disusun di server, bukan di layar
     * ---------------------------------------------------------------------
     * Layar sudah menerima `baris` datar, dan sebenarnya bisa mengelompokkannya
     * sendiri. Yang membuatnya lebih baik di sini: urutan sesi (waktu mulai,
     * lalu nomor), urutan ruang di dalamnya, dan ringkasan "berapa ruang belum
     * berpengawas" adalah tiga aturan yang sama dengan yang dipakai
     * `ruangTanpaPengawas()` - dan menuliskannya ulang di TypeScript berarti
     * tiga aturan yang bisa berbeda.
     *
     * Semua ruang-sesi ditampilkan walau peserta belum disebar agar Admin
     * tetap bisa menyusun penugasan pengawas lebih awal.
     *
     * @param  Collection<int, ExamSession>  $sesi
     * @param  Collection<int, ExamSessionRoom>  $ruangan
     * @return array<int, array<string, mixed>>
     */
    private function kelompokkanPerSesi($sesi, $ruangan, $kelasPerRuangSesi = null): array
    {
        $kelasPerRuangSesi ??= collect();

        return $sesi
            ->map(function (ExamSession $s) use ($ruangan, $kelasPerRuangSesi) {
                $ruangSesi = $ruangan
                    ->where('exam_session_id', $s->id)
                    ->values();

                // Sesi yang ruangnya kosong setelah penyaringan tidak perlu
                // ditampilkan: tidak ada satu pun penugasan yang bisa dibuat
                // di sana.
                if ($ruangSesi->isEmpty()) {
                    return null;
                }

                $daftarRuang = $ruangSesi->map(fn (ExamSessionRoom $r) => [
                    'id' => $r->id,
                    'ruang' => $r->eventRoom?->room?->code,
                    'ruang_nama' => $r->eventRoom?->room?->name,
                    'kapasitas' => $r->kapasitasEfektif(),
                    'kelas' => $kelasPerRuangSesi->get($r->id, []),
                    'peserta' => (int) $r->terisi,
                    'pengawas' => $r->supervisors
                        ->map(fn (SupervisionAssignment $t) => [
                            'id' => $t->id,
                            'user_id' => $t->supervisor_id,
                            'nama' => $t->supervisor?->name,
                            'username' => $t->supervisor?->username,
                            'status' => $t->status,
                        ])
                        ->values()
                        ->all(),
                ])->all();

                // Nama pengawas unik untuk sesi ini - satu orang yang menjaga
                // dua ruang pada sesi yang sama tetap dihitung sekali, karena
                // pertanyaannya "siapa saja yang bertugas sesi ini".
                $orangUnik = $ruangSesi
                    ->flatMap(fn (ExamSessionRoom $r) => $r->supervisors)
                    ->map(fn (SupervisionAssignment $t) => $t->supervisor_id)
                    ->unique()
                    ->count();

                return [
                    'id' => $s->id,
                    'label' => $s->label(),
                    'ujian' => $s->exam?->subject?->name ?? $s->exam?->title,
                    'tanggal' => $s->access_opens_at?->format('d M'),
                    'hari_tanggal' => $s->access_opens_at?->locale('id')->translatedFormat('l, d M Y'),
                    'jam' => $s->access_opens_at?->format('H:i'),
                    'selesai' => $s->hard_end_at?->format('H:i'),
                    'status' => $s->status->value,
                    'status_label' => $s->status->label(),
                    'jenis' => $s->jenis?->value ?? 'utama',
                    'susulan' => $s->susulan(),

                    'ruang' => $daftarRuang,
                    'jumlah_ruang' => count($daftarRuang),
                    'jumlah_pengawas' => $orangUnik,

                    // Ruang di sesi ini yang belum punya satu pun pengawas.
                    // Angka yang sama dipakai `SesiService::mulai()` untuk
                    // menolak membuka sesi - lihat catatan di `ruangTanpaPengawas`.
                    'ruang_tanpa_pengawas' => collect($daftarRuang)
                        ->filter(fn (array $r) => $r['pengawas'] === [])
                        ->pluck('ruang')
                        ->values()
                        ->all(),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

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

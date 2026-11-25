<?php

namespace App\Domain\Exam\Services;

use App\Domain\Academic\Models\TeachingAssignment;
use App\Domain\Event\Models\EventCommitteeMember;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Exam\Models\SupervisionAssignment;
use App\Domain\Exam\Models\SupervisorSessionExclusion;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Penugasan pengawas ke ruang-sesi - PRD §9.6, wireframe plate 17.
 *
 * ---------------------------------------------------------------------
 * Panitia berjabatan pengawas BUKAN sumber penugasan
 * ---------------------------------------------------------------------
 * Plate 13 mencatat siapa saja pengawasnya di tingkat event; tabel ini
 * mencatat siapa yang berdiri di R-02 pada sesi 1. Keduanya berbeda modul di
 * peta blueprint (5 dan 11), dan plate 17 menjelaskan mengapa pemisahan itu
 * menentukan: "penugasan inilah yang memberi Pengawas hak melihat dan
 * memverifikasi presensi di ruang itu. Tanpa penugasan, ia tidak melihat apa
 * pun."
 *
 * Karena itu `tugaskanOtomatis` MEMBACA kepanitiaan sebagai daftar calon,
 * tetapi tidak pernah memperlakukan keanggotaan panitia sebagai penugasan.
 * Pengawas yang ada di kepanitiaan tetapi tidak ditugaskan ke satu ruang pun
 * memang tidak bisa membuka ruang mana pun - itu aturannya, bukan celah.
 *
 * ---------------------------------------------------------------------
 * Benturan diperiksa pada saat penugasan, bukan hanya saat validasi jadwal
 * ---------------------------------------------------------------------
 * KonflikJadwal memeriksa benturan pengawas sebagai laporan. Pemeriksaan itu
 * berguna untuk melihat keadaan, tetapi ia tidak mencegah apa pun - Admin
 * tetap bisa menyimpan penugasan yang bentrok, lalu menemukannya di panel
 * validasi, lalu harus menebak mana yang harus digeser.
 *
 * Karena itu pemeriksaan yang sama diulang di sini, pada saat penugasannya
 * dibuat, dan kali ini ia MENOLAK. Dua tempat memeriksa hal yang sama memang
 * duplikasi, dan itu disengaja: satu menilai keadaan, satu mencegahnya.
 */
class PengawasService
{
    /**
     * Jumlah pengawas per ruang yang dituju "Tugaskan otomatis".
     *
     * =====================================================================
     * SATU per ruang - dan itu keputusan sekolah, bukan angka bawaan
     * =====================================================================
     * PRD §9.6 mengizinkan lebih dari satu pengawas per ruang, dan dulu angka
     * ini 2 supaya penugasan otomatis mengisi sampai saran plate 17. Yang
     * terjadi di lapangan berbeda: event dengan 33 ruang hanya punya 33-40
     * pengawas, sehingga menuntut dua per ruang berarti setengah ruang
     * dibiarkan kosong - atau, lebih buruk, penugasan otomatis diam-diam
     * menempatkan orang yang sama di dua ruang pada jam berbeda.
     *
     * Satu orang menjaga satu ruang adalah bentuk yang dipakai sekolah ini,
     * dan penugasan otomatis mengikuti bentuk itu. Menambah pengawas kedua
     * tetap BISA - `tugaskan()` tidak membatasi jumlah - tetapi ia tidak lagi
     * dilakukan otomatis.
     */
    public const SARAN_PER_RUANG = 1;

    /**
     * Jumlah pengawas paling sedikit yang membuat ruang bisa dibuka.
     *
     * Sama dengan `SARAN_PER_RUANG` sejak 2026-11-16: sekolah ini menugaskan
     * satu orang per ruang, dan angka yang berbeda untuk "boleh dibuka" dan
     * "disarankan" hanya menghasilkan dua pertanyaan untuk satu keadaan.
     *
     * Yang tetap menjadi TEMUAN adalah ruang yang NOL pengawas - presensi
     * harus diverifikasi orang, dan orang yang tidak ditugaskan tidak bisa
     * membuka ruangnya. SesiService menolak membuka sesi, dan TokenSesi
     * menolak menerbitkan token, hanya pada keadaan itu.
     */
    public const MINIMAL_PER_RUANG = 1;

    public function __construct(
        private readonly KonflikJadwal $konflik,
    ) {}

    /**
     * @return array{
     *   maks_per_event: int,
     *   maks_per_hari: int,
     *   target_per_ruang: int,
     *   prioritas: 'merata'|'urutan',
     *   hormati_pengecualian: bool,
     *   hindari_wali_kelas: bool,
     *   hindari_guru_mapel: bool,
     *   tolak_melebihi_kuota: bool,
     * }
     */
    public function aturan(SchoolEvent $event): array
    {
        return $event->aturanPengawas();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function simpanAturan(SchoolEvent $event, array $data): array
    {
        $lama = is_array($event->pengawas_settings) ? $event->pengawas_settings : [];

        $event->forceFill([
            'pengawas_settings' => array_merge($lama, [
                'maks_per_event' => max(0, (int) ($data['maks_per_event'] ?? 0)),
                'maks_per_hari' => max(0, (int) ($data['maks_per_hari'] ?? 0)),
                'target_per_ruang' => in_array((int) ($data['target_per_ruang'] ?? 1), [1, 2], true)
                    ? (int) ($data['target_per_ruang'] ?? 1)
                    : 1,
                'prioritas' => ($data['prioritas'] ?? 'merata') === 'urutan' ? 'urutan' : 'merata',
                'hormati_pengecualian' => (bool) ($data['hormati_pengecualian'] ?? true),
                'hindari_wali_kelas' => (bool) ($data['hindari_wali_kelas'] ?? false),
                'hindari_guru_mapel' => (bool) ($data['hindari_guru_mapel'] ?? false),
                'tolak_melebihi_kuota' => (bool) ($data['tolak_melebihi_kuota'] ?? false),
            ]),
        ])->save();

        return $event->aturanPengawas();
    }

    public function kosongkanSesi(ExamSession $sesi): int
    {
        $idRuang = $sesi->rooms()->pluck('id');

        if ($idRuang->isEmpty()) {
            return 0;
        }

        return SupervisionAssignment::query()
            ->whereIn('exam_session_room_id', $idRuang)
            ->delete();
    }

    /** @return array<int, ?int> */
    public function kuotaPerOrang(SchoolEvent $event): array
    {
        return $event->committee()
            ->whereNotNull('user_id')
            ->pluck('maks_mengawas', 'user_id')
            ->map(fn ($v) => $v !== null ? (int) $v : null)
            ->all();
    }

    /** @return array<string, ?string> */
    public function pengecualianEvent(SchoolEvent $event): array
    {
        return SupervisorSessionExclusion::query()
            ->where('event_id', $event->id)
            ->get(['exam_session_id', 'supervisor_id', 'reason'])
            ->mapWithKeys(fn (SupervisorSessionExclusion $e) => [
                "{$e->exam_session_id}:{$e->supervisor_id}" => $e->reason,
            ])
            ->all();
    }

    /** @return array<int, int> */
    public function bebanEvent(SchoolEvent $event): array
    {
        $idExam = $event->exams()->pluck('id');

        if ($idExam->isEmpty()) {
            return [];
        }

        $idRuang = ExamSessionRoom::query()
            ->whereIn('exam_session_id', ExamSession::query()->whereIn('exam_id', $idExam)->pluck('id'))
            ->pluck('id');

        if ($idRuang->isEmpty()) {
            return [];
        }

        return SupervisionAssignment::query()
            ->whereIn('exam_session_room_id', $idRuang)
            ->where('status', 'aktif')
            ->selectRaw('supervisor_id, COUNT(*) as total')
            ->groupBy('supervisor_id')
            ->pluck('total', 'supervisor_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Tugaskan seorang pengawas ke satu ruang-sesi.
     *
     * @param  array<string, mixed>  $data  start_at / end_at opsional.
     */
    public function tugaskan(ExamSessionRoom $ruang, User $pengawas, ?int $olehUserId = null, array $data = []): SupervisionAssignment
    {
        if (! in_array($pengawas->role, [Role::Supervisor, Role::Teacher], true)) {
            throw ValidationException::withMessages([
                'supervisor_id' => 'Hanya akun Pengawas atau Guru yang bisa ditugaskan mengawas.',
            ]);
        }

        $ruang->loadMissing('session.exam.event');
        $sesi = $ruang->session;
        $event = $sesi?->exam?->event;

        if ($event !== null && $sesi !== null) {
            $aturan = $this->aturan($event);

            if ($aturan['tolak_melebihi_kuota']) {
                if ($aturan['hormati_pengecualian']) {
                    $dikecualikan = SupervisorSessionExclusion::query()
                        ->where('event_id', $event->id)
                        ->where('exam_session_id', $sesi->id)
                        ->where('supervisor_id', $pengawas->id)
                        ->exists();

                    if ($dikecualikan) {
                        throw ValidationException::withMessages([
                            'supervisor_id' => "{$pengawas->name} dikosongkan pada sesi ini.",
                        ]);
                    }
                }

                $kuotaKhusus = $event->committee()
                    ->where('user_id', $pengawas->id)
                    ->value('maks_mengawas');
                $maksEvent = $kuotaKhusus !== null ? (int) $kuotaKhusus : $aturan['maks_per_event'];

                if ($maksEvent > 0) {
                    $idRuangEvent = ExamSessionRoom::query()
                        ->whereIn('exam_session_id', ExamSession::query()
                            ->whereIn('exam_id', $event->exams()->pluck('id'))
                            ->pluck('id'))
                        ->pluck('id');

                    $sudahEvent = SupervisionAssignment::query()
                        ->where('supervisor_id', $pengawas->id)
                        ->where('status', 'aktif')
                        ->whereIn('exam_session_room_id', $idRuangEvent)
                        ->where('exam_session_room_id', '!=', $ruang->id)
                        ->count();

                    if ($sudahEvent >= $maksEvent) {
                        throw ValidationException::withMessages([
                            'supervisor_id' => "{$pengawas->name} sudah mencapai batas {$maksEvent}× mengawas pada event ini.",
                        ]);
                    }
                }

                if ($aturan['maks_per_hari'] > 0 && $sesi->access_opens_at !== null) {
                    $tanggal = $sesi->access_opens_at->toDateString();
                    $idSesiHari = ExamSession::query()
                        ->whereIn('exam_id', $event->exams()->pluck('id'))
                        ->whereDate('access_opens_at', $tanggal)
                        ->pluck('id');
                    $idRuangHari = ExamSessionRoom::query()
                        ->whereIn('exam_session_id', $idSesiHari)
                        ->pluck('id');

                    $sudahHari = SupervisionAssignment::query()
                        ->where('supervisor_id', $pengawas->id)
                        ->where('status', 'aktif')
                        ->whereIn('exam_session_room_id', $idRuangHari)
                        ->where('exam_session_room_id', '!=', $ruang->id)
                        ->count();

                    if ($sudahHari >= $aturan['maks_per_hari']) {
                        throw ValidationException::withMessages([
                            'supervisor_id' => "{$pengawas->name} sudah mencapai batas {$aturan['maks_per_hari']}× mengawas pada hari tersebut.",
                        ]);
                    }
                }
            }
        }

        return DB::transaction(function () use ($ruang, $pengawas, $olehUserId, $data) {
            $tugas = SupervisionAssignment::query()->firstOrCreate(
                [
                    'school_id' => $ruang->school_id,
                    'exam_session_room_id' => $ruang->id,
                    'supervisor_id' => $pengawas->id,
                ],
                [
                    'assigned_by' => $olehUserId,
                    'start_at' => $data['start_at'] ?? null,
                    'end_at' => $data['end_at'] ?? null,
                    'status' => 'aktif',
                ],
            );

            // Benturan diperiksa SESUDAH barisnya ada, karena pemeriksaannya
            // membaca seluruh penugasan pengawas itu - termasuk yang baru.
            // Bila bentrok, transaksinya dibatalkan dan barisnya hilang lagi.
            if ($this->bentrok($pengawas)) {
                throw ValidationException::withMessages([
                    'supervisor_id' => "{$pengawas->name} sudah ditugaskan di ruang lain pada jam yang sama.",
                ]);
            }

            return $tugas;
        });
    }

    public function batalkan(SupervisionAssignment $tugas): void
    {
        $tugas->delete();
    }

    /**
     * Tukar dua penugasan pengawas - ditambahkan 2026-11-14.
     *
     * =====================================================================
     * Yang ditukar adalah ORANGNYA, bukan ruangnya
     * =====================================================================
     * Dua baris `supervision_assignments` bertukar `supervisor_id`. Ruang,
     * sesi, dan jadwalnya tidak bergerak sama sekali - yang berpindah adalah
     * siapa yang berdiri di masing-masing ruang.
     *
     * =====================================================================
     * Mengapa satu method, bukan "lepaskan lalu tugaskan dua kali"
     * =====================================================================
     * Urutan lepas-tugaskan lewat controller berarti tiga permintaan HTTP,
     * dan tiga permintaan yang masing-masing memeriksa bentrok bisa gagal di
     * tengahnya: orang pertama sudah dilepas, orang kedua belum masuk, dan
     * ruangnya kosong sampai Admin menyelesaikannya dengan tangan. Di sini
     * seluruh pertukaran ada di satu transaksi, dan bila bentroknya ditolak,
     * TIDAK ADA satu baris pun yang berubah.
     *
     * =====================================================================
     * Bentrok diperiksa, meski tukar di sesi yang sama tidak mungkin bentrok
     * =====================================================================
     * Tukar lintas SESI mungkin - mis. pengawas sesi pagi bertukar dengan
     * sesi siang karena sakit - dan pada kasus itu orang yang pindah bisa
     * bertabrakan dengan penugasan lain yang sudah ia pegang. Pemeriksaannya
     * dijalankan SESUDAH pertukaran sementara, di dalam transaksi yang sama,
     * sehingga penolakan mengembalikan keduanya ke keadaan semula.
     *
     * @throws ValidationException Bila hasil pertukaran membuat salah satu
     *                             orang punya dua ruang pada jam yang sama.
     */
    public function tukar(SupervisionAssignment $a, SupervisionAssignment $b): void
    {
        if ((int) $a->id === (int) $b->id) {
            throw ValidationException::withMessages([
                'tukar' => 'Dua penugasan yang sama tidak bisa ditukar dengan dirinya sendiri.',
            ]);
        }

        DB::transaction(function () use ($a, $b) {
            $idA = (int) $a->supervisor_id;
            $idB = (int) $b->supervisor_id;

            // `forceFill` + `save()`, bukan `update()`: `supervisor_id` ada di
            // fillable, tetapi jalur ini menulis kolom yang menentukan HAK
            // AKSES, dan mass-assignment dari luar tidak boleh bisa
            // menyentuhnya.
            $a->forceFill(['supervisor_id' => $idB])->save();
            $b->forceFill(['supervisor_id' => $idA])->save();

            // Sesudah kedua barisnya bertukar, baru diperiksa - bentroknya
            // hanya mungkin terlihat pada keadaan AKHIR. Penolakan di sini
            // melempar keluar dari transaksi, dan kedua `save()` di atas ikut
            // dibatalkan.
            $orangA = $a->supervisor()->first();
            $orangB = $b->supervisor()->first();

            foreach ([$orangA, $orangB] as $orang) {
                if ($orang !== null && $this->bentrok($orang)) {
                    throw ValidationException::withMessages([
                        'tukar' => "Pertukaran ini membuat {$orang->name} berada di dua ruang pada jam yang sama.",
                    ]);
                }
            }
        });
    }

    /**
     * Tugaskan otomatis: mode 'cepat' (greedy satu lintasan) atau 'expert'
     * (solver CSP dengan MRV, chain-swap repair, dan multi-iterasi ala aSc Timetables).
     *
     * @return array{ruang: int, penugasan: int, dilewati: int, total_slot?: int, terpenuhi?: int, iterasi?: int, durasi_ms?: int, mode?: string}
     */
    public function tugaskanOtomatis(
        SchoolEvent $event,
        ?int $olehUserId = null,
        ?int $khususSesiId = null,
        string $mode = 'cepat',
    ): array {
        if ($mode === 'expert') {
            return $this->tugaskanOtomatisExpert($event, $olehUserId, $khususSesiId);
        }

        $calon = $this->calonPengawas($event);

        if ($calon === []) {
            throw new DomainException(
                'Belum ada panitia berjabatan pengawas. Tambahkan pengawas di laman Kepanitiaan terlebih dahulu.',
            );
        }

        $aturan = $this->aturan($event);
        $target = $aturan['target_per_ruang'];

        $idSesiEvent = ExamSession::query()
            ->whereIn('exam_id', $event->exams()->pluck('id'))
            ->when($khususSesiId !== null, fn ($q) => $q->where('id', $khususSesiId))
            ->pluck('id');

        $ruangan = ExamSessionRoom::query()
            ->whereIn('exam_session_id', $idSesiEvent)
            ->with(['supervisors', 'session.exam'])
            ->orderBy('exam_session_id')
            ->orderBy('id')
            ->get();

        $kuotaPerOrang = $this->kuotaPerOrang($event);
        $pengecualian = $aturan['hormati_pengecualian'] ? $this->pengecualianEvent($event) : [];

        // Muat beban awal seluruh event agar penugasan per-sesi maupun penuh
        // tetap menghitung kuota event & kuota harian dengan akurat.
        $bebanEvent = [];
        $bebanHari = [];
        $semuaRuangEvent = ExamSessionRoom::query()
            ->whereIn('exam_session_id', ExamSession::query()
                ->whereIn('exam_id', $event->exams()->pluck('id'))
                ->pluck('id'))
            ->with(['session:id,access_opens_at', 'supervisors:id,exam_session_room_id,supervisor_id,status'])
            ->get();

        foreach ($semuaRuangEvent as $sr) {
            $tgl = $sr->session?->access_opens_at?->toDateString() ?? '';
            foreach ($sr->supervisors as $sup) {
                if ($sup->status !== 'aktif') {
                    continue;
                }
                $bebanEvent[$sup->supervisor_id] = ($bebanEvent[$sup->supervisor_id] ?? 0) + 1;
                if ($tgl !== '') {
                    $bebanHari[$sup->supervisor_id][$tgl] = ($bebanHari[$sup->supervisor_id][$tgl] ?? 0) + 1;
                }
            }
        }

        $guruMapel = [];
        if ($aturan['hindari_guru_mapel']) {
            TeachingAssignment::query()
                ->where('school_id', $event->school_id)
                ->get(['subject_id', 'teacher_id'])
                ->each(function ($ta) use (&$guruMapel): void {
                    $guruMapel[(int) $ta->subject_id][(int) $ta->teacher_id] = true;
                });
        }

        $waliKelasRuang = [];
        if ($aturan['hindari_wali_kelas'] && $ruangan->isNotEmpty()) {
            ExamParticipant::query()
                ->join('event_participants', 'event_participants.id', '=', 'exam_participants.event_participant_id')
                ->join('classes', 'classes.id', '=', 'event_participants.class_id')
                ->whereIn('exam_participants.exam_session_room_id', $ruangan->pluck('id'))
                ->where('exam_participants.status', ExamParticipant::STATUS_TERDAFTAR)
                ->whereNotNull('classes.homeroom_teacher_id')
                ->select('exam_participants.exam_session_room_id', 'classes.homeroom_teacher_id')
                ->distinct()
                ->get()
                ->each(function ($row) use (&$waliKelasRuang): void {
                    $waliKelasRuang[(int) $row->exam_session_room_id][(int) $row->homeroom_teacher_id] = true;
                });
        }

        $dibuat = 0;
        $tersentuh = [];
        $dilewati = [];

        // Dua putaran: pastikan setiap ruang mendapat 1 pengawas dulu sebelum
        // ruang mana pun mendapat pengawas kedua.
        for ($putaran = 1; $putaran <= $target; $putaran++) {
            foreach ($ruangan as $r) {
                if ($r->supervisors->count() >= $putaran) {
                    continue;
                }

                $tgl = $r->session?->access_opens_at?->toDateString() ?? '';
                $subjectId = (int) ($r->session?->exam?->subject_id ?? 0);

                // Urutkan calon sesuai prioritas: 'merata' mendahulukan beban terkecil,
                // sedangkan penalti lunak (wali kelas / guru mapel) ditaruh di belakang
                // agar tetap dihindari selama masih ada calon lain.
                $kandidat = collect($calon)->sortBy(function (User $orang, int $idx) use (
                    $aturan,
                    $bebanEvent,
                    $guruMapel,
                    $waliKelasRuang,
                    $r,
                    $subjectId,
                ): array {
                    $penaltiWali = ($aturan['hindari_wali_kelas'] && isset($waliKelasRuang[$r->id][$orang->id])) ? 1 : 0;
                    $penaltiMapel = ($aturan['hindari_guru_mapel'] && $subjectId > 0 && isset($guruMapel[$subjectId][$orang->id])) ? 1 : 0;
                    $beban = $aturan['prioritas'] === 'merata' ? ($bebanEvent[$orang->id] ?? 0) : 0;

                    return [$penaltiWali + $penaltiMapel, $beban, $idx];
                });

                foreach ($kandidat as $orang) {
                    if ($r->supervisors->contains('supervisor_id', $orang->id)) {
                        continue;
                    }

                    if (array_key_exists("{$r->exam_session_id}:{$orang->id}", $pengecualian)) {
                        $dilewati[$orang->id] = true;

                        continue;
                    }

                    $maksEvent = $kuotaPerOrang[$orang->id] ?? $aturan['maks_per_event'];
                    if ($maksEvent > 0 && ($bebanEvent[$orang->id] ?? 0) >= $maksEvent) {
                        $dilewati[$orang->id] = true;

                        continue;
                    }

                    if ($aturan['maks_per_hari'] > 0 && $tgl !== '' && ($bebanHari[$orang->id][$tgl] ?? 0) >= $aturan['maks_per_hari']) {
                        $dilewati[$orang->id] = true;

                        continue;
                    }

                    try {
                        $this->tugaskan($r, $orang, $olehUserId);
                        $dibuat++;
                        $tersentuh[$r->id] = true;
                        $bebanEvent[$orang->id] = ($bebanEvent[$orang->id] ?? 0) + 1;
                        if ($tgl !== '') {
                            $bebanHari[$orang->id][$tgl] = ($bebanHari[$orang->id][$tgl] ?? 0) + 1;
                        }
                        $r->load('supervisors');
                        break;
                    } catch (ValidationException) {
                        $dilewati[$orang->id] = true;
                    }
                }
            }
        }

        return [
            'ruang' => count($tersentuh),
            'penugasan' => $dibuat,
            'dilewati' => count($dilewati),
            'mode' => 'cepat',
        ];
    }

    /**
     * Mode Expert: solver penjadwalan mendalam (MRV + chain-swap ejection + multi-restart)
     * untuk memaksimalkan keterpenuhan seluruh ruang sambil menghormati kuota,
     * pengecualian, benturan waktu, dan pemerataan beban.
     *
     * @return array{ruang: int, penugasan: int, dilewati: int, total_slot: int, terpenuhi: int, iterasi: int, durasi_ms: int, mode: string}
     */
    private function tugaskanOtomatisExpert(
        SchoolEvent $event,
        ?int $olehUserId = null,
        ?int $khususSesiId = null,
    ): array {
        $mulai = microtime(true);
        $calon = $this->calonPengawas($event);

        if ($calon === []) {
            throw new DomainException(
                'Belum ada panitia berjabatan pengawas. Tambahkan pengawas di laman Kepanitiaan terlebih dahulu.',
            );
        }

        $aturan = $this->aturan($event);
        $target = $aturan['target_per_ruang'];
        $idUjianEvent = $event->exams()->pluck('id');

        $idSesiTarget = ExamSession::query()
            ->whereIn('exam_id', $idUjianEvent)
            ->when($khususSesiId !== null, fn ($q) => $q->where('id', $khususSesiId))
            ->pluck('id');

        $ruangan = ExamSessionRoom::query()
            ->whereIn('exam_session_id', $idSesiTarget)
            ->with(['supervisors', 'session.exam'])
            ->orderBy('exam_session_id')
            ->orderBy('id')
            ->get();

        // Bangun daftar slot yang harus diisi: Tier 1 (pengawas pertama per ruang) lalu Tier 2.
        $slots = [];
        $sudahDiRuang = [];

        foreach ([1, 2] as $tier) {
            if ($tier > $target) {
                continue;
            }

            foreach ($ruangan as $r) {
                $aktif = $r->supervisors->where('status', 'aktif');
                $sudahDiRuang[$r->id] = $aktif->pluck('supervisor_id')->map(fn ($v) => (int) $v)->all();

                if ($aktif->count() >= $tier) {
                    continue;
                }

                $sesi = $r->session;
                $startTs = $sesi?->access_opens_at?->getTimestamp() ?? 0;
                $endTs = $sesi?->hard_end_at?->getTimestamp() ?? ($startTs + 3600);

                $slots[] = [
                    'room_id' => (int) $r->id,
                    'school_id' => (int) $r->school_id,
                    'sesi_id' => (int) $r->exam_session_id,
                    'sesi_label' => $sesi?->label() ?? 'Sesi',
                    'tgl' => $sesi?->access_opens_at?->toDateString() ?? '',
                    'start_ts' => $startTs,
                    'end_ts' => $endTs,
                    'subject_id' => (int) ($sesi?->exam?->subject_id ?? 0),
                    'tier' => $tier,
                ];
            }
        }

        if ($slots === []) {
            return [
                'ruang' => 0,
                'penugasan' => 0,
                'dilewati' => 0,
                'total_slot' => 0,
                'terpenuhi' => 0,
                'iterasi' => 0,
                'durasi_ms' => (int) round((microtime(true) - $mulai) * 1000),
                'mode' => 'expert',
            ];
        }

        $calonIds = array_map(fn (User $u) => (int) $u->id, $calon);
        $kuotaPerOrang = $this->kuotaPerOrang($event);
        $pengecualian = $aturan['hormati_pengecualian'] ? $this->pengecualianEvent($event) : [];

        // Beban awal seluruh event.
        $bebanEventAwal = array_fill_keys($calonIds, 0);
        $bebanHariAwal = array_fill_keys($calonIds, []);
        $idSesiSemua = ExamSession::query()->whereIn('exam_id', $idUjianEvent)->pluck('id');
        $semuaRuangEvent = ExamSessionRoom::query()
            ->whereIn('exam_session_id', $idSesiSemua)
            ->with(['session:id,access_opens_at', 'supervisors:id,exam_session_room_id,supervisor_id,status'])
            ->get();

        foreach ($semuaRuangEvent as $sr) {
            $tgl = $sr->session?->access_opens_at?->toDateString() ?? '';
            foreach ($sr->supervisors as $sup) {
                $sid = (int) $sup->supervisor_id;
                if ($sup->status !== 'aktif' || ! array_key_exists($sid, $bebanEventAwal)) {
                    continue;
                }
                $bebanEventAwal[$sid]++;
                if ($tgl !== '') {
                    $bebanHariAwal[$sid][$tgl] = ($bebanHariAwal[$sid][$tgl] ?? 0) + 1;
                }
            }
        }

        // Interval sibuk bawaan lintas seluruh event sekolah agar tidak pernah bentrok waktu.
        $sibukAwal = array_fill_keys($calonIds, []);
        SupervisionAssignment::query()
            ->whereIn('supervisor_id', $calonIds)
            ->with('room.session:id,access_opens_at,hard_end_at')
            ->get()
            ->each(function (SupervisionAssignment $t) use (&$sibukAwal): void {
                $sid = (int) $t->supervisor_id;
                $s = $t->start_at?->getTimestamp() ?? $t->room?->session?->access_opens_at?->getTimestamp();
                $e = $t->end_at?->getTimestamp() ?? $t->room?->session?->hard_end_at?->getTimestamp();
                if ($s !== null && $e !== null) {
                    $sibukAwal[$sid][] = [$s, $e];
                }
            });

        // Penalti lunak: guru mapel & wali kelas.
        $guruMapel = [];
        if ($aturan['hindari_guru_mapel']) {
            TeachingAssignment::query()
                ->where('school_id', $event->school_id)
                ->get(['subject_id', 'teacher_id'])
                ->each(function ($ta) use (&$guruMapel): void {
                    $guruMapel[(int) $ta->subject_id][(int) $ta->teacher_id] = true;
                });
        }

        $waliKelasRuang = [];
        if ($aturan['hindari_wali_kelas'] && $ruangan->isNotEmpty()) {
            ExamParticipant::query()
                ->join('event_participants', 'event_participants.id', '=', 'exam_participants.event_participant_id')
                ->join('classes', 'classes.id', '=', 'event_participants.class_id')
                ->whereIn('exam_participants.exam_session_room_id', $ruangan->pluck('id'))
                ->where('exam_participants.status', ExamParticipant::STATUS_TERDAFTAR)
                ->whereNotNull('classes.homeroom_teacher_id')
                ->select('exam_participants.exam_session_room_id', 'classes.homeroom_teacher_id')
                ->distinct()
                ->get()
                ->each(function ($row) use (&$waliKelasRuang): void {
                    $waliKelasRuang[(int) $row->exam_session_room_id][(int) $row->homeroom_teacher_id] = true;
                });
        }

        // Hitung kelayakan dasar tiap (slot, pengawas) & kelangkaan pengawas (scarcity).
        $totalSlot = count($slots);
        $layakDasar = [];
        $kelangkaan = array_fill_keys($calonIds, 0);
        $maksEventMap = [];

        foreach ($calonIds as $sid) {
            $maksEventMap[$sid] = $kuotaPerOrang[$sid] ?? $aturan['maks_per_event'];
        }

        foreach ($slots as $idx => $sl) {
            $layakDasar[$idx] = [];
            foreach ($calonIds as $sid) {
                if (in_array($sid, $sudahDiRuang[$sl['room_id']] ?? [], true)) {
                    continue;
                }
                if (array_key_exists("{$sl['sesi_id']}:{$sid}", $pengecualian)) {
                    continue;
                }
                if ($this->adaIrisanInterval($sibukAwal[$sid], $sl['start_ts'], $sl['end_ts'])) {
                    continue;
                }
                $layakDasar[$idx][] = $sid;
                $kelangkaan[$sid]++;
            }
        }

        $batasWaktu = 3.5; // detik maksimal pencarian
        $maksIterasi = 60;
        $terbaik = null;
        $skorTerbaik = null;
        $iterasi = 0;

        for ($iter = 0; $iter < $maksIterasi; $iter++) {
            if ($iter > 0 && (microtime(true) - $mulai) >= $batasWaktu) {
                break;
            }

            $iterasi++;
            $acak = $iter > 0;

            $penugasan = array_fill(0, $totalSlot, null);
            $bebanEvent = $bebanEventAwal;
            $bebanHari = $bebanHariAwal;
            $ruangTerisi = $sudahDiRuang;
            $slotPerPengawas = array_fill_keys($calonIds, []);

            // Tahap 1: Konstruktif MRV (slot paling sempit kandidatnya diisi lebih dulu), per tier.
            foreach ([1, 2] as $tier) {
                if ($tier > $target) {
                    continue;
                }

                $belum = [];
                foreach ($slots as $idx => $sl) {
                    if ($sl['tier'] === $tier) {
                        $belum[$idx] = true;
                    }
                }

                while ($belum !== []) {
                    $pilihSlot = null;
                    $kandidatTerbaikSlot = [];
                    $minKandidat = PHP_INT_MAX;

                    foreach (array_keys($belum) as $idx) {
                        $kand = $this->kandidatValidExpert(
                            $idx,
                            $slots,
                            $layakDasar[$idx],
                            $ruangTerisi,
                            $bebanEvent,
                            $bebanHari,
                            $slotPerPengawas,
                            $maksEventMap,
                            $aturan['maks_per_hari'],
                        );

                        $jml = count($kand);
                        if ($jml < $minKandidat || ($jml === $minKandidat && $acak && random_int(0, 1) === 1)) {
                            $minKandidat = $jml;
                            $pilihSlot = $idx;
                            $kandidatTerbaikSlot = $kand;
                            if ($jml === 0) {
                                break;
                            }
                        }
                    }

                    unset($belum[$pilihSlot]);

                    if ($kandidatTerbaikSlot === []) {
                        continue;
                    }

                    // Variasikan strategi antar-iterasi: iterasi genap utamakan kelangkaan dulu,
                    // iterasi ganjil utamakan pemerataan beban dulu agar ruang solusi lebih luas.
                    $utamakanLangka = ($iter % 2 === 1);

                    usort($kandidatTerbaikSlot, function (int $a, int $b) use (
                        $pilihSlot,
                        $slots,
                        $aturan,
                        $waliKelasRuang,
                        $guruMapel,
                        $bebanEvent,
                        $kelangkaan,
                        $utamakanLangka,
                        $acak,
                    ): int {
                        $sl = $slots[$pilihSlot];
                        $penA = $this->penaltiLunak($sl, $a, $aturan, $waliKelasRuang, $guruMapel);
                        $penB = $this->penaltiLunak($sl, $b, $aturan, $waliKelasRuang, $guruMapel);
                        if ($penA !== $penB) {
                            return $penA <=> $penB;
                        }

                        if ($utamakanLangka && $kelangkaan[$a] !== $kelangkaan[$b]) {
                            return $kelangkaan[$a] <=> $kelangkaan[$b];
                        }

                        if ($aturan['prioritas'] === 'merata') {
                            $bebA = $bebanEvent[$a];
                            $bebB = $bebanEvent[$b];
                            if ($bebA !== $bebB) {
                                return $bebA <=> $bebB;
                            }
                        }

                        if ($kelangkaan[$a] !== $kelangkaan[$b]) {
                            return $kelangkaan[$a] <=> $kelangkaan[$b];
                        }

                        return $acak ? random_int(-1, 1) : ($a <=> $b);
                    });

                    $chosen = $kandidatTerbaikSlot[0];
                    $this->pasangSlotExpert(
                        $pilihSlot,
                        $chosen,
                        $slots,
                        $penugasan,
                        $bebanEvent,
                        $bebanHari,
                        $ruangTerisi,
                        $slotPerPengawas,
                    );
                }
            }

            // Tahap 2: Perbaikan augmenting-path / chain-swap (iterative deepening DFS
            // hingga kedalaman 4, ala aSc Timetables) untuk slot yang masih kosong.
            $masihKosong = true;
            $putaranRepair = 0;

            while ($masihKosong && $putaranRepair < 6) {
                $putaranRepair++;
                $masihKosong = false;

                foreach ($slots as $idx => $sl) {
                    if ($penugasan[$idx] !== null) {
                        continue;
                    }

                    $berhasil = false;
                    foreach ([1, 2, 3, 4] as $kedalaman) {
                        $dikunjungi = [$idx => true];
                        $sisaNode = 350;

                        if ($this->cobaPerbaikiSlotDfs(
                            $idx,
                            $kedalaman,
                            $sisaNode,
                            $dikunjungi,
                            $slots,
                            $layakDasar,
                            $penugasan,
                            $bebanEvent,
                            $bebanHari,
                            $ruangTerisi,
                            $slotPerPengawas,
                            $maksEventMap,
                            $aturan['maks_per_hari'],
                            $acak,
                        )) {
                            $berhasil = true;
                            break;
                        }
                    }

                    if ($berhasil) {
                        $masihKosong = true;
                    }
                }
            }

            // Tahap 2b: Pastikan tidak ada slot Tier 1 (ruang tanpa pengawas sama sekali)
            // yang kosong selama masih bisa meminjam pengawas dari slot Tier 2.
            foreach ($slots as $idx => $sl) {
                if ($sl['tier'] !== 1 || $penugasan[$idx] !== null) {
                    continue;
                }

                $this->cobaPinjamDariTierDua(
                    $idx,
                    $slots,
                    $layakDasar,
                    $penugasan,
                    $bebanEvent,
                    $bebanHari,
                    $ruangTerisi,
                    $slotPerPengawas,
                    $maksEventMap,
                    $aturan['maks_per_hari'],
                );
            }

            // Evaluasi kualitas solusi: (tier1 terisi, total terisi, -penalti lunak, -sebaran beban).
            $tier1Terisi = 0;
            $totalTerisi = 0;
            $totalPenalti = 0;

            foreach ($slots as $idx => $sl) {
                $sid = $penugasan[$idx];
                if ($sid === null) {
                    continue;
                }
                $totalTerisi++;
                if ($sl['tier'] === 1) {
                    $tier1Terisi++;
                }
                $totalPenalti += $this->penaltiLunak($sl, $sid, $aturan, $waliKelasRuang, $guruMapel);
            }

            $sebaran = $bebanEvent === [] ? 0 : (max($bebanEvent) - min($bebanEvent));
            $skor = [$tier1Terisi, $totalTerisi, -$totalPenalti, -$sebaran];

            if ($skorTerbaik === null || $skor > $skorTerbaik) {
                $skorTerbaik = $skor;
                $terbaik = $penugasan;
            }

            // Berhenti lebih cepat jika sudah 100% terpenuhi dengan penalti 0 dan beban merata.
            if ($totalTerisi === $totalSlot && $totalPenalti === 0 && $sebaran <= 1) {
                break;
            }
        }

        $dibuat = 0;
        $tersentuh = [];

        DB::transaction(function () use ($slots, $terbaik, $olehUserId, &$dibuat, &$tersentuh): void {
            foreach ($slots as $idx => $sl) {
                $sid = $terbaik[$idx] ?? null;
                if ($sid === null) {
                    continue;
                }

                $tugas = SupervisionAssignment::query()->firstOrCreate(
                    [
                        'school_id' => $sl['school_id'],
                        'exam_session_room_id' => $sl['room_id'],
                        'supervisor_id' => $sid,
                    ],
                    [
                        'assigned_by' => $olehUserId,
                        'start_at' => null,
                        'end_at' => null,
                        'status' => 'aktif',
                    ],
                );

                if ($tugas->wasRecentlyCreated) {
                    $dibuat++;
                    $tersentuh[$sl['room_id']] = true;
                }
            }
        });

        $durasiMs = (int) round((microtime(true) - $mulai) * 1000);
        $belumTerpenuhi = max(0, $totalSlot - $dibuat);
        $diagnosis = null;

        if ($belumTerpenuhi > 0) {
            $bagian = [];
            $takTerbatas = false;
            $sisaKapasitas = 0;

            foreach ($calonIds as $sid) {
                $maks = $maksEventMap[$sid] ?? 0;
                if ($maks === 0) {
                    $takTerbatas = true;
                    break;
                }
                $sisaKapasitas += max(0, $maks - ($bebanEventAwal[$sid] ?? 0));
            }

            if (! $takTerbatas && $sisaKapasitas < $totalSlot) {
                $kurangKuota = $totalSlot - $sisaKapasitas;
                $bagian[] = "Total butuh {$totalSlot} slot, sisa kapasitas kuota seluruh pengawas hanya {$sisaKapasitas} (kurang {$kurangKuota} kuota).";
            }

            $kosongPerSesi = [];
            foreach ($slots as $idx => $sl) {
                if (($terbaik[$idx] ?? null) === null) {
                    $lbl = (string) ($sl['sesi_label'] ?? 'Sesi');
                    $kosongPerSesi[$lbl] = ($kosongPerSesi[$lbl] ?? 0) + 1;
                }
            }

            if ($kosongPerSesi !== []) {
                $potong = array_slice($kosongPerSesi, 0, 4, true);
                $teksSesi = [];
                foreach ($potong as $lbl => $jml) {
                    $teksSesi[] = "{$lbl} ({$jml} slot)";
                }
                $sisaSesi = count($kosongPerSesi) - count($potong);
                $akhiran = $sisaSesi > 0 ? ", +{$sisaSesi} sesi lain" : '';
                $bagian[] = 'Belum terisi di: '.implode(', ', $teksSesi).$akhiran.'.';
            }

            $diagnosis = $bagian !== [] ? implode(' ', $bagian) : null;
        }

        return [
            'ruang' => count($tersentuh),
            'penugasan' => $dibuat,
            'dilewati' => $belumTerpenuhi,
            'total_slot' => $totalSlot,
            'terpenuhi' => $dibuat,
            'iterasi' => $iterasi,
            'durasi_ms' => $durasiMs,
            'diagnosis' => $diagnosis,
            'mode' => 'expert',
        ];
    }

    /**
     * @param  array<int, array{0: int, 1: int}>  $intervals
     */
    private function adaIrisanInterval(array $intervals, int $start, int $end): bool
    {
        foreach ($intervals as [$s, $e]) {
            if ($start < $e && $s < $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $slot
     * @param  array<string, mixed>  $aturan
     * @param  array<int, array<int, bool>>  $waliKelasRuang
     * @param  array<int, array<int, bool>>  $guruMapel
     */
    private function penaltiLunak(
        array $slot,
        int $supId,
        array $aturan,
        array $waliKelasRuang,
        array $guruMapel,
    ): int {
        $p = 0;
        if ($aturan['hindari_wali_kelas'] && isset($waliKelasRuang[$slot['room_id']][$supId])) {
            $p++;
        }
        if ($aturan['hindari_guru_mapel'] && $slot['subject_id'] > 0 && isset($guruMapel[$slot['subject_id']][$supId])) {
            $p++;
        }

        return $p;
    }

    /**
     * @param  array<int, array<string, mixed>>  $slots
     * @param  array<int, int>  $kandidatDasar
     * @param  array<int, array<int, int>>  $ruangTerisi
     * @param  array<int, int>  $bebanEvent
     * @param  array<int, array<string, int>>  $bebanHari
     * @param  array<int, array<int, int>>  $slotPerPengawas
     * @param  array<int, int>  $maksEventMap
     * @return array<int, int>
     */
    private function kandidatValidExpert(
        int $slotIdx,
        array $slots,
        array $kandidatDasar,
        array $ruangTerisi,
        array $bebanEvent,
        array $bebanHari,
        array $slotPerPengawas,
        array $maksEventMap,
        int $maksHari,
    ): array {
        $sl = $slots[$slotIdx];
        $hasil = [];

        foreach ($kandidatDasar as $sid) {
            if (in_array($sid, $ruangTerisi[$sl['room_id']] ?? [], true)) {
                continue;
            }

            $maksEvent = $maksEventMap[$sid] ?? 0;
            if ($maksEvent > 0 && ($bebanEvent[$sid] ?? 0) >= $maksEvent) {
                continue;
            }

            if ($maksHari > 0 && $sl['tgl'] !== '' && ($bebanHari[$sid][$sl['tgl']] ?? 0) >= $maksHari) {
                continue;
            }

            $bentrok = false;
            foreach ($slotPerPengawas[$sid] ?? [] as $lainIdx) {
                $lain = $slots[$lainIdx];
                if ($sl['start_ts'] < $lain['end_ts'] && $lain['start_ts'] < $sl['end_ts']) {
                    $bentrok = true;
                    break;
                }
            }

            if (! $bentrok) {
                $hasil[] = $sid;
            }
        }

        return $hasil;
    }

    /**
     * @param  array<int, array<string, mixed>>  $slots
     * @param  array<int, int|null>  $penugasan
     * @param  array<int, int>  $bebanEvent
     * @param  array<int, array<string, int>>  $bebanHari
     * @param  array<int, array<int, int>>  $ruangTerisi
     * @param  array<int, array<int, int>>  $slotPerPengawas
     */
    private function pasangSlotExpert(
        int $slotIdx,
        int $sid,
        array $slots,
        array &$penugasan,
        array &$bebanEvent,
        array &$bebanHari,
        array &$ruangTerisi,
        array &$slotPerPengawas,
    ): void {
        $sl = $slots[$slotIdx];
        $penugasan[$slotIdx] = $sid;
        $bebanEvent[$sid] = ($bebanEvent[$sid] ?? 0) + 1;
        if ($sl['tgl'] !== '') {
            $bebanHari[$sid][$sl['tgl']] = ($bebanHari[$sid][$sl['tgl']] ?? 0) + 1;
        }
        $ruangTerisi[$sl['room_id']][] = $sid;
        $slotPerPengawas[$sid][] = $slotIdx;
    }

    /**
     * @param  array<int, array<string, mixed>>  $slots
     * @param  array<int, int|null>  $penugasan
     * @param  array<int, int>  $bebanEvent
     * @param  array<int, array<string, int>>  $bebanHari
     * @param  array<int, array<int, int>>  $ruangTerisi
     * @param  array<int, array<int, int>>  $slotPerPengawas
     */
    private function lepasSlotExpert(
        int $slotIdx,
        array $slots,
        array &$penugasan,
        array &$bebanEvent,
        array &$bebanHari,
        array &$ruangTerisi,
        array &$slotPerPengawas,
    ): void {
        $sid = $penugasan[$slotIdx];
        if ($sid === null) {
            return;
        }
        $sl = $slots[$slotIdx];
        $penugasan[$slotIdx] = null;
        $bebanEvent[$sid] = max(0, ($bebanEvent[$sid] ?? 1) - 1);
        if ($sl['tgl'] !== '') {
            $bebanHari[$sid][$sl['tgl']] = max(0, ($bebanHari[$sid][$sl['tgl']] ?? 1) - 1);
        }
        $ruangTerisi[$sl['room_id']] = array_values(array_filter(
            $ruangTerisi[$sl['room_id']] ?? [],
            fn (int $x) => $x !== $sid,
        ));
        $slotPerPengawas[$sid] = array_values(array_filter(
            $slotPerPengawas[$sid] ?? [],
            fn (int $x) => $x !== $slotIdx,
        ));
    }

    /**
     * DFS augmenting-path / ejection-chain hingga kedalaman $sisaKedalaman:
     * mencoba mengisi $slotIdx secara langsung, atau memindahkan slot penghalang
     * milik pengawas yang layak secara berantai hingga menemukan pengawas bebas.
     *
     * @param  array<int, bool>  $dikunjungi
     * @param  array<int, array<string, mixed>>  $slots
     * @param  array<int, array<int, int>>  $layakDasar
     * @param  array<int, int|null>  $penugasan
     * @param  array<int, int>  $bebanEvent
     * @param  array<int, array<string, int>>  $bebanHari
     * @param  array<int, array<int, int>>  $ruangTerisi
     * @param  array<int, array<int, int>>  $slotPerPengawas
     * @param  array<int, int>  $maksEventMap
     */
    private function cobaPerbaikiSlotDfs(
        int $slotIdx,
        int $sisaKedalaman,
        int &$sisaNode,
        array &$dikunjungi,
        array $slots,
        array $layakDasar,
        array &$penugasan,
        array &$bebanEvent,
        array &$bebanHari,
        array &$ruangTerisi,
        array &$slotPerPengawas,
        array $maksEventMap,
        int $maksHari,
        bool $acak,
    ): bool {
        if ($sisaNode <= 0) {
            return false;
        }
        $sisaNode--;

        // Cek langsung apakah ada kandidat yang sudah bebas sekarang.
        $langsung = $this->kandidatValidExpert(
            $slotIdx,
            $slots,
            $layakDasar[$slotIdx],
            $ruangTerisi,
            $bebanEvent,
            $bebanHari,
            $slotPerPengawas,
            $maksEventMap,
            $maksHari,
        );

        if ($langsung !== []) {
            $chosen = $acak ? $langsung[array_rand($langsung)] : $langsung[0];
            $this->pasangSlotExpert($slotIdx, $chosen, $slots, $penugasan, $bebanEvent, $bebanHari, $ruangTerisi, $slotPerPengawas);

            return true;
        }

        if ($sisaKedalaman <= 0) {
            return false;
        }

        $sl = $slots[$slotIdx];
        $kandidat = $layakDasar[$slotIdx];

        if ($acak) {
            shuffle($kandidat);
        }

        foreach ($kandidat as $sid) {
            if ($sisaNode <= 0) {
                return false;
            }

            if (in_array($sid, $ruangTerisi[$sl['room_id']] ?? [], true)) {
                continue;
            }

            // Cari slot milik $sid dalam run ini yang menghalangi $sid masuk ke $slotIdx.
            $penghalang = [];
            foreach ($slotPerPengawas[$sid] ?? [] as $milikIdx) {
                $milik = $slots[$milikIdx];
                if ($sl['start_ts'] < $milik['end_ts'] && $milik['start_ts'] < $sl['end_ts']) {
                    $penghalang[] = $milikIdx;
                }
            }

            if ($penghalang === []) {
                $maksEvent = $maksEventMap[$sid] ?? 0;
                $penuhHari = $maksHari > 0 && $sl['tgl'] !== '' && ($bebanHari[$sid][$sl['tgl']] ?? 0) >= $maksHari;
                $penuhEvent = $maksEvent > 0 && ($bebanEvent[$sid] ?? 0) >= $maksEvent;

                if ($penuhHari) {
                    foreach ($slotPerPengawas[$sid] ?? [] as $milikIdx) {
                        if ($slots[$milikIdx]['tgl'] === $sl['tgl']) {
                            $penghalang[] = $milikIdx;
                        }
                    }
                } elseif ($penuhEvent) {
                    $penghalang = $slotPerPengawas[$sid] ?? [];
                }
            }

            foreach ($penghalang as $pindahIdx) {
                if (isset($dikunjungi[$pindahIdx])) {
                    continue;
                }

                // Lepas $pindahIdx dari $sid dan cek apakah $sid kini bisa mengisi $slotIdx.
                $this->lepasSlotExpert($pindahIdx, $slots, $penugasan, $bebanEvent, $bebanHari, $ruangTerisi, $slotPerPengawas);

                $sidValidSekarang = in_array(
                    $sid,
                    $this->kandidatValidExpert(
                        $slotIdx,
                        $slots,
                        [$sid],
                        $ruangTerisi,
                        $bebanEvent,
                        $bebanHari,
                        $slotPerPengawas,
                        $maksEventMap,
                        $maksHari,
                    ),
                    true,
                );

                if ($sidValidSekarang) {
                    $this->pasangSlotExpert($slotIdx, $sid, $slots, $penugasan, $bebanEvent, $bebanHari, $ruangTerisi, $slotPerPengawas);
                    $dikunjungi[$pindahIdx] = true;

                    if ($this->cobaPerbaikiSlotDfs(
                        $pindahIdx,
                        $sisaKedalaman - 1,
                        $sisaNode,
                        $dikunjungi,
                        $slots,
                        $layakDasar,
                        $penugasan,
                        $bebanEvent,
                        $bebanHari,
                        $ruangTerisi,
                        $slotPerPengawas,
                        $maksEventMap,
                        $maksHari,
                        $acak,
                    )) {
                        return true;
                    }

                    unset($dikunjungi[$pindahIdx]);
                    $this->lepasSlotExpert($slotIdx, $slots, $penugasan, $bebanEvent, $bebanHari, $ruangTerisi, $slotPerPengawas);
                }

                // Kembalikan bila cabang ini tidak berhasil.
                $this->pasangSlotExpert($pindahIdx, $sid, $slots, $penugasan, $bebanEvent, $bebanHari, $ruangTerisi, $slotPerPengawas);
            }
        }

        return false;
    }

    /**
     * Bila sebuah slot Tier 1 (ruang tanpa pengawas sama sekali) masih kosong,
     * pinjam pengawas dari slot Tier 2 agar setiap ruang minimal memiliki 1 pengawas.
     *
     * @param  array<int, array<string, mixed>>  $slots
     * @param  array<int, array<int, int>>  $layakDasar
     * @param  array<int, int|null>  $penugasan
     * @param  array<int, int>  $bebanEvent
     * @param  array<int, array<string, int>>  $bebanHari
     * @param  array<int, array<int, int>>  $ruangTerisi
     * @param  array<int, array<int, int>>  $slotPerPengawas
     * @param  array<int, int>  $maksEventMap
     */
    private function cobaPinjamDariTierDua(
        int $slotIdx,
        array $slots,
        array $layakDasar,
        array &$penugasan,
        array &$bebanEvent,
        array &$bebanHari,
        array &$ruangTerisi,
        array &$slotPerPengawas,
        array $maksEventMap,
        int $maksHari,
    ): bool {
        $sl = $slots[$slotIdx];

        foreach ($layakDasar[$slotIdx] as $sid) {
            if (in_array($sid, $ruangTerisi[$sl['room_id']] ?? [], true)) {
                continue;
            }

            foreach ($slotPerPengawas[$sid] ?? [] as $milikIdx) {
                if ($slots[$milikIdx]['tier'] !== 2) {
                    continue;
                }

                $this->lepasSlotExpert($milikIdx, $slots, $penugasan, $bebanEvent, $bebanHari, $ruangTerisi, $slotPerPengawas);

                $valid = in_array(
                    $sid,
                    $this->kandidatValidExpert(
                        $slotIdx,
                        $slots,
                        [$sid],
                        $ruangTerisi,
                        $bebanEvent,
                        $bebanHari,
                        $slotPerPengawas,
                        $maksEventMap,
                        $maksHari,
                    ),
                    true,
                );

                if ($valid) {
                    $this->pasangSlotExpert($slotIdx, $sid, $slots, $penugasan, $bebanEvent, $bebanHari, $ruangTerisi, $slotPerPengawas);

                    return true;
                }

                $this->pasangSlotExpert($milikIdx, $sid, $slots, $penugasan, $bebanEvent, $bebanHari, $ruangTerisi, $slotPerPengawas);
            }
        }

        return false;
    }

    /**
     * @return array<int, User>
     */
    public function calonPengawas(SchoolEvent $event): array
    {
        // Akun yang sudah ditautkan ke kepanitiaan berjabatan pengawas.
        $idPanitia = $event->committee()
            ->where('position', 'like', '%pengawas%')
            ->where('status', 'aktif')
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->all();

        return User::query()
            ->whereIn('id', $idPanitia)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->all();
    }

    /**
     * Apakah pengawas ini punya dua penugasan yang jendelanya bertumpang.
     *
     * Memakai KonflikJadwal sebagai sumbernya, bukan menyalin logika
     * jendelanya: penugasan yang start_at/end_at-nya kosong berarti "ikuti
     * sesi", dan salinan aturan itu di dua tempat akan berselisih diam-diam
     * pada penugasan pertama yang benar-benar mengisi kolomnya.
     */
    private function bentrok(User $pengawas): bool
    {
        $penugasan = SupervisionAssignment::query()
            ->where('supervisor_id', $pengawas->id)
            ->with(['room.session', 'room.eventRoom.room:id,code'])
            ->get();

        if ($penugasan->count() < 2) {
            return false;
        }

        return $this->konflik->adaBenturanPengawas($penugasan);
    }

    /**
     * Berapa ruang yang belum punya pengawas sama sekali.
     *
     * Dipakai KPI "Ruang tanpa pengawas" di plate 17, dan dipakai SesiService
     * untuk menolak membuka sesi. Angkanya satu, dan tempat menghitungnya satu -
     * karena KPI yang bilang "0" sementara sesinya menolak dibuka adalah layar
     * yang bertengkar dengan dirinya sendiri.
     */
    public function ruangTanpaPengawas(SchoolEvent $event): int
    {
        $idSesi = ExamSession::query()
            ->whereIn('exam_id', $event->exams()->pluck('id'))
            ->pluck('id')
            ->all();

        if ($idSesi === []) {
            return 0;
        }

        return ExamSessionRoom::query()
            ->whereIn('exam_session_id', $idSesi)
            ->whereDoesntHave('supervisors')
            ->count();
    }

    /**
     * Berapa penugasan aktif yang dimiliki satu event.
     */
    public function jumlahPenugasan(SchoolEvent $event): int
    {
        $idRuang = ExamSessionRoom::query()
            ->whereIn('exam_session_id', ExamSession::query()
                ->whereIn('exam_id', $event->exams()->pluck('id'))
                ->pluck('id'))
            ->pluck('id');

        return SupervisionAssignment::query()
            ->whereIn('exam_session_room_id', $idRuang)
            ->where('status', 'aktif')
            ->distinct()
            ->count('supervisor_id');
    }

    /**
     * Jabatan panitia yang dihitung sebagai calon pengawas.
     *
     * @return array<int, string>
     */
    public static function jabatanPengawas(): array
    {
        return EventCommitteeMember::query()
            ->where('position', 'like', '%pengawas%')
            ->distinct()
            ->pluck('position')
            ->all();
    }
}

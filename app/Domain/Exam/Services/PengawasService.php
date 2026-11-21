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
        $event->forceFill([
            'pengawas_settings' => [
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
            ],
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
     * Tugaskan otomatis: SATU pengawas dulu untuk setiap ruang, baru ditambah
     * yang kedua bila pengawasnya masih tersisa.
     *
     * ---------------------------------------------------------------------
     * Mengapa dua putaran, bukan satu
     * ---------------------------------------------------------------------
     * Versi satu-putaran mengisi tiap ruang sampai DUA orang sebelum pindah ke
     * ruang berikutnya. Pada sekolah yang pengawasnya lebih sedikit daripada
     * dua kali jumlah ruangnya, hasilnya adalah bencana yang sunyi: delapan
     * ruang pertama berisi dua orang, dan sisanya NOL. Ruang tanpa pengawas
     * adalah ruang yang tidak bisa dibuka, jadi tombol "Tugaskan otomatis" -
     * yang gunanya menolong - justru meninggalkan separuh ujian tanpa
     * pengawas, dan Admin baru menyadarinya saat sesinya menolak dibuka.
     *
     * Putaran pertama memastikan setiap ruang punya SATU orang; putaran kedua
     * baru menambah orang kedua selama calonnya masih ada. Dengan begitu
     * keadaan terburuknya adalah "semua ruang satu pengawas" - dan itu keadaan
     * yang sah, bukan kegagalan.
     *
     * Gilirannya diambil dari kepanitiaan berjabatan pengawas - itu daftar
     * orang yang sekolah sudah tetapkan untuk mengawas. Yang bentrok
     * DILEWATI, bukan dijadikan galat: seluruh gunanya tombol ini adalah
     * menyelesaikan pekerjaan yang membosankan, dan berhenti pada benturan
     * pertama berarti Admin tetap harus menugaskan sisanya satu per satu.
     *
     * @return array{ruang: int, penugasan: int, dilewati: int}
     */
    public function tugaskanOtomatis(SchoolEvent $event, ?int $olehUserId = null, ?int $khususSesiId = null): array
    {
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

        foreach ($ruangan as $r) {
            $kurang = $target - $r->supervisors->count();

            if ($kurang <= 0) {
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
                if ($kurang <= 0) {
                    break;
                }

                if ($r->supervisors->contains('supervisor_id', $orang->id)) {
                    continue;
                }

                if (isset($pengecualian["{$r->exam_session_id}:{$orang->id}"])) {
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
                    $kurang--;
                    $tersentuh[$r->id] = true;
                    $bebanEvent[$orang->id] = ($bebanEvent[$orang->id] ?? 0) + 1;
                    if ($tgl !== '') {
                        $bebanHari[$orang->id][$tgl] = ($bebanHari[$orang->id][$tgl] ?? 0) + 1;
                    }
                } catch (ValidationException) {
                    $dilewati[$orang->id] = true;
                }
            }

            $r->load('supervisors');
        }

        return [
            'ruang' => count($tersentuh),
            'penugasan' => $dibuat,
            'dilewati' => count($dilewati),
        ];
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

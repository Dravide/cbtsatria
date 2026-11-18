<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Enums\PeranTandaTangan;
use App\Domain\Attendance\Enums\StatusPresensi;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Models\SessionReport;
use App\Domain\Event\Enums\EventStatus;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Enums\StatusSesi;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Exam\Models\SupervisionAssignment;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Collection;

/**
 * Pantau publik aktivitas ujian - `/pantau/{slug}`.
 *
 * =====================================================================
 * Pertanyaan yang dijawab, dan mengapa papan pengawas tidak menjawabnya
 * =====================================================================
 * `PapanPengawas` (`/papan/{slug}`) menjawab "Bu Ani di ruang mana". Ia
 * menyebut ruang, pengawas, dan jam - tidak lebih. Ia tidak bergerak, dan
 * ia tidak tahu ada peserta yang terkunci.
 *
 * Laman ini menjawab pertanyaan yang berbeda, dan pertanyaan itu ditanyakan
 * oleh orang yang sama di lorong: "ujiannya sudah sampai mana, dan ada yang
 * tertahan tidak". Panitia di ruang tamu, kepala sekolah yang menunggu, wali
 * kelas di luar - mereka tidak butuh nama, mereka butuh angka.
 *
 * =====================================================================
 * Batas isinya: ANGKA. Tidak ada nama siswa, di mana pun
 * =====================================================================
 * Alamat ini di luar `auth`, jadi ia tayang untuk seluruh internet. Batasnya
 * sama dengan `PapanPengawas`, ditambah satu izin dan satu larangan:
 *
 *   BOLEH   jumlah peserta, mengerjakan, selesai, terputus, terkunci;
 *           kode ruang; nama pengawas; jam; dan KEADAAN laporan.
 *   TIDAK   nama siswa, NIS, nomor kursi, kelas, token, isi jawaban;
 *           isi berita acara, nama penanda tangan, foto, catatan.
 *
 * Keadaan laporan masuk karena ia satu kata per ruang-sesi - "belum",
 * "draf", "selesai" - dan pertanyaan "pengawasnya sudah mengisi belum"
 * adalah pertanyaan yang paling sering datang dari lorong. Isinya tidak:
 * yang dibutuhkan hanya apakah dokumennya sudah ditandatangani.
 *
 * =====================================================================
 * Sesi yang dibaca: BERJALAN dulu, lalu terdekat, lalu terakhir
 * =====================================================================
 * Tiga keadaan, tiga jawaban, dan ketiganya harus dibedakan:
 *
 *   1. ada sesi berjalan   -> keadaan "berjalan", papan bergerak;
 *   2. tidak ada, ada yang  -> keadaan "menunggu", angka nol dengan jam
 *      akan datang             mulai - nol yang dijelaskan bukan papan rusak;
 *   3. semuanya sudah tutup -> keadaan "selesai", angka TERAKHIR sesi
 *                              terakhir, dan papan berhenti bergerak.
 *
 * Keadaan ketiga itulah yang paling mudah dilupakan, dan yang paling
 * merugikan: papan yang menyala "langsung" pada sesi yang sudah ditutup
 * adalah janji bahwa angkanya bergerak padahal tidak.
 */
class PantauPublik
{
    /** Batas sesi berjalan yang ditampilkan, sama dengan `PapanPengawas`. */
    public const MAKS_SESI = 3;

    /** Detik tanpa kabar sebelum peserta dianggap terputus. */
    public const AMBANG_TERPUTUS = 90;

    /**
     * Potret lengkap papan - dipakai halaman pertama DAN endpoint denyut.
     *
     * Satu method untuk keduanya, dan itu bukan penghematan: dua tempat yang
     * menyusun angka ini adalah dua tempat yang suatu hari berbeda, dan
     * perbedaan itu akan terlihat sebagai angka yang melompat setiap lima
     * detik tanpa sebab.
     *
     * @return array<string, mixed>|null
     */
    public function susun(School $sekolah): ?array
    {
        if ($sekolah->papan_slug === null) {
            return null;
        }

        $sesi = $this->sesiAktif($sekolah);

        return $this->susunDariSesi($sekolah->name, null, $sesi, []);
    }

    /**
     * Potret papan pantau untuk SATU event tertentu.
     *
     * @return array<string, mixed>
     */
    public function susunEvent(SchoolEvent $event, ?int $sesiId = null): array
    {
        $event->loadMissing('school:id,name');

        $semuaSesi = $this->daftarSesiEvent($event);

        if ($sesiId !== null) {
            $terpilih = $semuaSesi->firstWhere('id', $sesiId);
            $sesi = $terpilih !== null
                ? new Collection([$terpilih])
                : $this->sesiAktifEvent($event);
        } else {
            $sesi = $this->sesiAktifEvent($event);
        }

        $daftarSesi = $semuaSesi->map(fn (ExamSession $s) => [
            'id' => (int) $s->id,
            'label' => $s->label(),
            'ujian' => $s->exam?->title,
            'mapel' => $s->exam?->subject?->name,
            'tanggal' => $s->access_opens_at?->format('d M'),
            'jam' => $s->access_opens_at->format('H:i'),
            'selesai' => $s->hard_end_at?->format('H:i'),
            'status' => $s->status->value,
            'status_label' => $s->status->label(),
            'keadaan' => $this->keadaan($s),
        ])->values()->all();

        return $this->susunDariSesi(
            $event->school?->name ?? '',
            $event,
            $sesi,
            $daftarSesi,
        );
    }

    /**
     * Detail satu ruang-sesi pada event untuk panel interaktif Layar PID:
     * daftar presensi & status pengerjaan siswa serta isi ringkas berita acara.
     *
     * @return array<string, mixed>|null
     */
    public function detailRuangEvent(SchoolEvent $event, int $ruangId): ?array
    {
        $ruang = ExamSessionRoom::withoutGlobalScopes()
            ->where('school_id', $event->school_id)
            ->whereHas('session.exam', fn ($q) => $q->withoutGlobalScopes()->where('event_id', $event->id))
            ->with([
                'eventRoom.room:id,code,name,location',
                'supervisors.supervisor:id,name',
                'session' => fn ($q) => $q->withoutGlobalScopes(),
                'session.exam' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'title', 'subject_id', 'event_id'),
                'session.exam.subject:id,name',
                'participants' => fn ($q) => $q->withoutGlobalScopes()->orderBy('id'),
                'participants.student:id,name',
                'participants.student.studentProfile:id,user_id,nis',
            ])
            ->find($ruangId);

        if ($ruang === null) {
            return null;
        }

        $anak = $ruang->participants;
        $idPeserta = $anak->pluck('id');

        $attempt = ExamAttempt::withoutGlobalScopes()
            ->whereIn('exam_participant_id', $idPeserta)
            ->orderBy('attempt_number')
            ->get()
            ->groupBy('exam_participant_id')
            ->map(fn ($baris) => $baris->last());

        $presensi = AttendanceRecord::withoutGlobalScopes()
            ->whereIn('exam_participant_id', $idPeserta)
            ->orderBy('id')
            ->get()
            ->groupBy('exam_participant_id')
            ->map(fn ($baris) => $baris->last());

        $laporan = SessionReport::withoutGlobalScopes()
            ->where('exam_session_room_id', $ruang->id)
            ->with([
                'berkas:id,session_report_id',
                'tandaTangan:id,session_report_id,peran,nama',
            ])
            ->first();

        $daftarPeserta = $anak->map(function (ExamParticipant $p) use ($attempt, $presensi) {
            /** @var ExamAttempt|null $a */
            $a = $attempt->get($p->id);
            /** @var AttendanceRecord|null $rec */
            $rec = $presensi->get($p->id);
            $statusPresensi = $rec?->attendance_status ?? StatusPresensi::Pending;

            $terhubung = $a !== null && $a->status === ExamAttempt::STATUS_BERJALAN
                ? $a->terhubung(self::AMBANG_TERPUTUS)
                : null;

            return [
                'id' => (int) $p->id,
                'nama' => $p->student?->name ?? 'Peserta',
                'nis' => $p->student?->studentProfile?->nis,
                'presensi' => $statusPresensi->value,
                'presensi_label' => $statusPresensi->label(),
                'presensi_pendek' => $statusPresensi->pendek(),
                'presensi_nada' => $statusPresensi->nada(),
                'status' => $a?->status,
                'status_label' => $a?->labelStatus() ?? 'Belum mulai',
                'status_nada' => $a?->nadaStatus() ?? 'mute',
                'terhubung' => $terhubung,
                'terkunci' => $a?->dikunci() ?? false,
                'kunci_alasan' => $a?->lock_reason,
                'pelanggaran' => (int) ($a?->violation_count ?? 0),
                'denyut' => $a?->last_heartbeat_at?->format('H:i:s'),
                'nomor' => $a?->guard_flags_json['nomor'] ?? null,
                'progres' => $a?->guard_flags_json['progres'] ?? null,
            ];
        })->values();

        $hadir = $daftarPeserta->whereIn('presensi', [StatusPresensi::Hadir->value, StatusPresensi::Terlambat->value])->count();
        $tidakHadir = $daftarPeserta->whereIn('presensi', [StatusPresensi::TidakHadir->value, StatusPresensi::Izin->value])->count();
        $belumPresensi = max(0, $daftarPeserta->count() - $hadir - $tidakHadir);

        $ttdPengawas = $laporan?->tandaTanganPeran(PeranTandaTangan::Pengawas);

        return [
            'ruang' => [
                'id' => (int) $ruang->id,
                'kode' => $ruang->eventRoom?->room?->code ?? 'R-?',
                'nama' => $ruang->eventRoom?->room?->name,
                'lokasi' => $ruang->eventRoom?->room?->location,
                'sesi' => $ruang->session?->label(),
                'ujian' => $ruang->session?->exam?->title,
                'mapel' => $ruang->session?->exam?->subject?->name,
                'jam' => $ruang->session
                    ? $ruang->session->access_opens_at->format('H:i').($ruang->session->hard_end_at ? '–'.$ruang->session->hard_end_at->format('H:i') : '')
                    : null,
                'pengawas' => $ruang->supervisors
                    ->map(fn (SupervisionAssignment $t) => $t->supervisor?->name)
                    ->filter()
                    ->values()
                    ->all(),
            ],
            'angka' => [
                'peserta' => $daftarPeserta->count(),
                'hadir' => $hadir,
                'belum_presensi' => $belumPresensi,
                'tidak_hadir' => $tidakHadir,
                'mengerjakan' => $daftarPeserta->where('status', ExamAttempt::STATUS_BERJALAN)->count(),
                'selesai' => $daftarPeserta->whereIn('status', [ExamAttempt::STATUS_DIKIRIM, ExamAttempt::STATUS_KEDALUWARSA])->count(),
                'terputus' => $daftarPeserta->where('terhubung', false)->count(),
                'terkunci' => $daftarPeserta->where('terkunci', true)->count(),
                'pelanggaran' => $daftarPeserta->sum('pelanggaran'),
            ],
            'peserta' => $daftarPeserta->all(),
            'berita_acara' => [
                'keadaan' => $this->keadaanLaporan($laporan),
                'dibuka' => $laporan !== null,
                'final' => $laporan?->final() ?? false,
                'dikirim_pada' => $laporan?->submitted_at?->format('d M Y H:i'),
                'catatan' => $laporan?->catatan,
                'pernyataan_presensi' => $laporan?->pernyataan_presensi ?? false,
                'pernyataan_keluar' => $laporan?->pernyataan_keluar ?? false,
                'foto' => $laporan?->berkas->count() ?? 0,
                'ttd_pengawas' => $ttdPengawas !== null,
                'ttd_oleh' => $ttdPengawas?->nama,
                'kurang' => $laporan?->kekuranganFinal() ?? [],
            ],
            'admin_monitoring_url' => "/admin/events/{$event->id}/monitoring/ruang/{$ruang->id}",
            'diperbarui_jam' => now()->format('H:i:s'),
        ];
    }

    /**
     * @param  Collection<int, ExamSession>  $sesi
     * @param  array<int, array<string, mixed>>  $daftarSesi
     * @return array<string, mixed>
     */
    private function susunDariSesi(string $namaSekolah, ?SchoolEvent $event, Collection $sesi, array $daftarSesi): array
    {
        $eventRingkas = $event ? [
            'id' => (int) $event->id,
            'kode' => $event->code,
            'nama' => $event->name,
        ] : null;

        if ($sesi->isEmpty()) {
            return [
                'ada' => false,
                'keadaan' => 'kosong',
                'sekolah' => $namaSekolah,
                'event' => $eventRingkas,
                'daftar_sesi' => $daftarSesi,
                'diperbarui' => now()->toIso8601String(),
                'diperbarui_jam' => now()->format('H:i:s'),
                'sesi' => null,
                'ruang' => [],
                'angka' => $this->angkaKosong(),
                'laporan' => ['selesai' => 0, 'total' => 0],
            ];
        }

        // Sesi pertama yang berjalan, atau sesi pertama (terdekat) bila tidak
        // ada yang berjalan. `sesiAktif()` sudah mengurutkannya.
        $terpilih = $sesi->first();

        $ruangan = ExamSessionRoom::withoutGlobalScopes()
            ->where('exam_session_id', $terpilih->id)
            ->with([
                'eventRoom.room:id,code,name,location',
                'supervisors.supervisor:id,name',
                'participants' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'exam_session_room_id', 'student_id', 'school_id'),
            ])
            ->orderBy('id')
            ->get();

        $idPeserta = $ruangan->flatMap->participants->pluck('id');

        // Dua kueri untuk seluruh papan, berapa pun jumlah ruangnya - sifat
        // yang sama dengan papan Admin, dan alasannya sama: laman ini dibuka
        // di jaringan sekolah pada jam paling sibuk, dan penyegaran tiap
        // lima detik dari setiap layar di lorong akan menghabiskan basis
        // data bila satu ruang satu kueri.
        $attempt = ExamAttempt::withoutGlobalScopes()
            ->whereIn('exam_participant_id', $idPeserta)
            ->orderBy('attempt_number')
            ->get()
            ->groupBy('exam_participant_id')
            ->map(fn ($baris) => $baris->last());

        $laporan = SessionReport::withoutGlobalScopes()
            ->whereIn('exam_session_room_id', $ruangan->pluck('id'))
            ->with('tandaTangan:id,session_report_id,peran')
            ->get()
            ->keyBy('exam_session_room_id');

        $barisRuang = $ruangan->map(function (ExamSessionRoom $r) use ($attempt, $laporan) {
            $anak = $r->participants;

            return [
                'id' => (int) $r->id,
                'kode' => $r->eventRoom?->room?->code ?? 'R-?',
                'nama' => $r->eventRoom?->room?->name,
                'lokasi' => $r->eventRoom?->room?->location,
                'pengawas' => $r->supervisors
                    ->map(fn (SupervisionAssignment $t) => $t->supervisor?->name)
                    ->filter()
                    ->values()
                    ->all(),

                'peserta' => $anak->count(),
                'mengerjakan' => $anak->filter(
                    fn (ExamParticipant $p) => $attempt->get($p->id)?->status === ExamAttempt::STATUS_BERJALAN,
                )->count(),
                'selesai' => $anak->filter(
                    fn (ExamParticipant $p) => in_array(
                        $attempt->get($p->id)?->status,
                        [ExamAttempt::STATUS_DIKIRIM, ExamAttempt::STATUS_KEDALUWARSA],
                        true,
                    ),
                )->count(),
                // Hanya attempt yang SUDAH dimulai bisa "terputus" - yang
                // belum mulai belum punya jam kabar sama sekali. Menghitungnya
                // sebagai terputus membuat sesi yang belum dibuka terbaca
                // sebagai sesi yang seluruh jaringannya putus.
                'terputus' => $anak->filter(function (ExamParticipant $p) use ($attempt) {
                    $a = $attempt->get($p->id);

                    return $a !== null && $a->status === ExamAttempt::STATUS_BERJALAN && ! $a->terhubung(self::AMBANG_TERPUTUS);
                })->count(),
                'terkunci' => $anak->filter(
                    fn (ExamParticipant $p) => $attempt->get($p->id)?->dikunci() === true,
                )->count(),

                'laporan' => $this->keadaanLaporan($laporan->get($r->id)),
            ];
        })->values();

        $angka = [
            'peserta' => $barisRuang->sum('peserta'),
            'mengerjakan' => $barisRuang->sum('mengerjakan'),
            'selesai' => $barisRuang->sum('selesai'),
            'terputus' => $barisRuang->sum('terputus'),
            'terkunci' => $barisRuang->sum('terkunci'),
        ];

        $angka['belum_mulai'] = max(0, $angka['peserta'] - $angka['mengerjakan'] - $angka['selesai']);

        return [
            'ada' => true,
            'keadaan' => $this->keadaan($terpilih),
            'sekolah' => $namaSekolah,
            'event' => $eventRingkas,
            'daftar_sesi' => $daftarSesi,
            'diperbarui' => now()->toIso8601String(),
            'diperbarui_jam' => now()->format('H:i:s'),
            'sesi' => [
                'id' => (int) $terpilih->id,
                'label' => $terpilih->label(),
                'ujian' => $terpilih->exam?->title,
                'mapel' => $terpilih->exam?->subject?->name,
                'tanggal' => $terpilih->access_opens_at?->format('d M'),
                'jam' => $terpilih->access_opens_at->format('H:i'),
                'selesai' => $terpilih->hard_end_at?->format('H:i'),
                'status' => $terpilih->status->value,
                'status_label' => $terpilih->status->label(),
            ],
            'ruang' => $barisRuang->all(),
            'angka' => $angka,
            'laporan' => [
                'selesai' => $barisRuang->where('laporan', 'selesai')->count(),
                'total' => $barisRuang->count(),
            ],
        ];
    }

    /**
     * Keadaan papan - tiga kata yang dibaca kepala laman.
     *
     * Bukan `ExamSession::status->value` mentah: yang dibutuhkan layar
     * adalah "apakah angka ini masih bergerak", dan itu pertanyaan yang
     * lebih sempit daripada status sesinya.
     */
    private function keadaan(ExamSession $sesi): string
    {
        // Jendela yang sudah LEWAT atau status sesi sudah selesai menang,
        // dan urutannya penting: sesi yang `hard_end_at`-nya lewat tetapi
        // statusnya masih `berjalan` tidak boleh tampil sebagai papan bergerak.
        if (
            $sesi->status === StatusSesi::Selesai
            || ($sesi->hard_end_at !== null && $sesi->hard_end_at->isPast())
        ) {
            return 'selesai';
        }

        return $sesi->status === StatusSesi::Berjalan ? 'berjalan' : 'menunggu';
    }

    /**
     * Keadaan berita acara satu ruang-sesi: satu kata, tanpa isinya.
     *
     * Lima nilai, dan `draf` dipisah dari `belum` karena keduanya menuntut
     * tindakan yang berbeda: `belum` berarti pengawasnya tidak pernah
     * membuka lamannya, `draf` berarti ia sudah membuka tetapi belum
     * mengirim. Yang pertama perlu dicari orangnya; yang kedua perlu
     * diingatkan.
     */
    private function keadaanLaporan(?SessionReport $laporan): string
    {
        if ($laporan === null) {
            return 'belum';
        }

        if ($laporan->final()) {
            return 'selesai';
        }

        return $laporan->kekuranganFinal() === [] ? 'siap' : 'draf';
    }

    /**
     * Sesi yang dibaca papan ini.
     *
     * Aturan yang sama dengan `PapanPengawas::sesiAktif()` - lihat catatan
     * panjangnya di sana. Yang berbeda hanya tambahan keadaan ketiga: bila
     * TIDAK ada satu pun sesi yang belum berakhir, sesi TERAKHIR yang sudah
     * ditutup tetap dibaca, sehingga papan bisa menunjukkan angka akhirnya
     * alih-alih kosong.
     *
     * @return Collection<int, ExamSession>
     */
    private function sesiAktif(School $sekolah): Collection
    {
        $statusAktif = array_map(fn (EventStatus $s) => $s->value, EventStatus::belumSelesai());

        return $this->pilihSesiDariQuery(
            fn ($q) => $q->withoutGlobalScopes()
                ->whereHas('event', fn ($ev) => $ev->withoutGlobalScopes()
                    ->where('school_id', $sekolah->id)
                    ->whereIn('status', $statusAktif)),
            (int) $sekolah->id,
        );
    }

    /**
     * Sesi yang dibaca papan untuk SATU event tertentu.
     *
     * Tidak menyaring `EventStatus::belumSelesai()`: bila Admin atau tautan
     * event membuka papan untuk event yang sudah selesai, papan tetap
     * menampilkan angka akhir sesi terakhirnya.
     *
     * @return Collection<int, ExamSession>
     */
    private function sesiAktifEvent(SchoolEvent $event): Collection
    {
        return $this->pilihSesiDariQuery(
            fn ($q) => $q->withoutGlobalScopes()->where('event_id', $event->id),
            (int) $event->school_id,
        );
    }

    /** @return Collection<int, ExamSession> */
    private function daftarSesiEvent(SchoolEvent $event): Collection
    {
        return ExamSession::withoutGlobalScopes()
            ->where('school_id', $event->school_id)
            ->whereHas('exam', fn ($q) => $q->withoutGlobalScopes()->where('event_id', $event->id))
            ->whereNotIn('status', [StatusSesi::Batal->value])
            ->with([
                'exam' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'title', 'subject_id', 'event_id'),
                'exam.subject:id,name',
            ])
            ->orderBy('access_opens_at')
            ->get();
    }

    /**
     * @param  \Closure(\Illuminate\Database\Eloquent\Builder<\App\Domain\Exam\Models\Exam>): void  $filterExam
     * @return Collection<int, ExamSession>
     */
    private function pilihSesiDariQuery(\Closure $filterExam, int $schoolId): Collection
    {
        $dasar = fn () => ExamSession::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->whereHas('exam', $filterExam)
            ->with([
                'exam' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'title', 'subject_id', 'event_id'),
                'exam.subject:id,name',
            ]);

        $berjalan = $dasar()
            ->where('status', StatusSesi::Berjalan->value)
            ->orderBy('access_opens_at')
            ->limit(self::MAKS_SESI)
            ->get();

        if ($berjalan->isNotEmpty()) {
            return $berjalan;
        }

        $akanDatang = $dasar()
            ->where('hard_end_at', '>=', now())
            ->whereNotIn('status', [StatusSesi::Batal->value, StatusSesi::Selesai->value])
            ->orderBy('access_opens_at')
            ->limit(self::MAKS_SESI)
            ->get();

        if ($akanDatang->isNotEmpty()) {
            return $akanDatang;
        }

        // Tidak ada yang berjalan maupun yang akan datang: sesi TERAKHIR
        // yang sudah ditutup tetap dibaca. Papan publik yang kosong pada
        // pukul sebelas pagi akan dikira rusak, padahal jawabannya memang
        // "ujiannya sudah selesai".
        return $dasar()
            ->whereNotIn('status', [StatusSesi::Batal->value])
            ->orderByDesc('hard_end_at')
            ->limit(1)
            ->get();
    }

    /** @return array<string, int> */
    private function angkaKosong(): array
    {
        return [
            'peserta' => 0,
            'mengerjakan' => 0,
            'selesai' => 0,
            'belum_mulai' => 0,
            'terputus' => 0,
            'terkunci' => 0,
        ];
    }
}

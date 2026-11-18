<?php

namespace App\Domain\Attendance\Services;

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

        return $this->susunDariSesi($sekolah->name, null, $sesi);
    }

    /**
     * Potret papan pantau untuk SATU event tertentu.
     *
     * @return array<string, mixed>
     */
    public function susunEvent(SchoolEvent $event): array
    {
        $event->loadMissing('school:id,name');

        $sesi = $this->sesiAktifEvent($event);

        return $this->susunDariSesi(
            $event->school?->name ?? '',
            $event,
            $sesi,
        );
    }

    /**
     * @param  Collection<int, ExamSession>  $sesi
     * @return array<string, mixed>
     */
    private function susunDariSesi(string $namaSekolah, ?SchoolEvent $event, Collection $sesi): array
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

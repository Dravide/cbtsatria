<?php

namespace App\Domain\Exam\Services;

use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Exam\Models\SupervisionAssignment;
use Illuminate\Support\Collection;

/**
 * Mesin validasi jadwal - PRD §9.4, §9.5, §9.6, §6.5, dan Fase 5 blueprint.
 *
 * ---------------------------------------------------------------------
 * Mengembalikan TEMUAN, bukan boolean
 * ---------------------------------------------------------------------
 * Pertanyaan yang mudah diajukan adalah "apakah jadwal ini bertentangan?"
 * dan jawabannya boolean. Bentuk itu salah untuk laman ini, dan anotasi
 * plate 16 yang menyatakannya:
 *
 *   "Baris bentrok ditandai di tabelnya sendiri, bukan hanya diringkas di
 *    panel validasi, supaya Admin bisa memperbaiki langsung dari baris yang
 *    bermasalah."
 *
 * Boolean tidak bisa menandai baris. Karena itu tiap temuan membawa id sesi,
 * ruang, peserta, dan pengawas yang bersangkutan - itulah yang membuat
 * barisnya bisa ditandai dan panelnya bisa menautkan.
 *
 * ---------------------------------------------------------------------
 * Enam pemeriksaan, dan dari mana masing-masing berasal
 * ---------------------------------------------------------------------
 * PRD menyebut kewajiban validasi di enam tempat berbeda tetapi tidak pernah
 * menetapkan algoritmanya, jadi yang berikut adalah bacaan yang paling tidak
 * mengejutkan - masing-masing menemukan sesuatu yang benar-benar bisa
 * terjadi di sekolah:
 *
 *   benturan-ruang     §9.4  satu ruang dipakai dua sesi yang bertumpang.
 *                            Inilah temuan yang tampil di plate 16.
 *   benturan-peserta   §9.5  siswa yang sama punya dua sesi bertumpang -
 *                            dan ia tidak bisa berada di dua ruang sekaligus.
 *   benturan-pengawas  §9.6  pengawas yang sama ditugaskan di dua ruang pada
 *                            jam yang sama. Risiko blueprint §23.
 *   kapasitas-ruang    §9.4  peserta yang ditempatkan melebihi kursi.
 *   ruang-tidak-aktif  §9.4  ruang berstatus perbaikan tetap dipakai.
 *   ruang-luar-event   §9.4  ruang yang tidak dialokasikan ke event ini.
 *
 * ---------------------------------------------------------------------
 * Tumpang tindih dihitung terhadap hard_end_at, bukan access_closes_at
 * ---------------------------------------------------------------------
 * Batas akhir mutlak adalah kapan ruangnya benar-benar kosong. Memakai
 * `access_closes_at` akan melaporkan aman untuk dua sesi yang jendelanya
 * tidak bertumpang di sisi pembukaan, padahal siswa sesi pertama masih
 * mengerjakan ketika sesi kedua masuk - dan itu justru bentuk benturan yang
 * paling sering terjadi di sekolah dengan ruang terbatas.
 */
class KonflikJadwal
{
    /**
     * Seluruh temuan konflik pada satu event.
     *
     * @return array<int, array{jenis: string, pesan: string, sesi_id: ?int, ruang_id: ?int, peserta_id: ?int, pengawas_id: ?int}>
     */
    public function periksa(SchoolEvent $event): array
    {
        $sesi = $this->sesiEvent($event);

        if ($sesi->isEmpty()) {
            return [];
        }

        return array_merge(
            $this->benturanRuang($sesi),
            $this->benturanPeserta($sesi),
            $this->benturanPengawas($sesi),
            $this->kapasitasRuang($event, $sesi),
            $this->ruangTidakLayak($event, $sesi),
        );
    }

    /**
     * Temuan yang menempel pada satu sesi, untuk menandai barisnya.
     *
     * Plate 16 membutuhkan peta ini. Method terpisah, bukan penyaringan di
     * halaman: pertanyaan "apa yang salah dengan baris ini" adalah
     * pertanyaan yang sama di laman mana pun, dan menjawabnya di controller
     * berarti jawabannya berbeda-beda di tiap laman.
     *
     * @return array<int, array<int, array{jenis: string, pesan: string}>>
     */
    public function perSesi(SchoolEvent $event): array
    {
        $peta = [];

        foreach ($this->periksa($event) as $temuan) {
            if ($temuan['sesi_id'] === null) {
                continue;
            }

            $peta[$temuan['sesi_id']][] = [
                'jenis' => $temuan['jenis'],
                'pesan' => $temuan['pesan'],
            ];
        }

        return $peta;
    }

    /**
     * Ringkasan untuk panel "Validasi jadwal" di plate 16.
     *
     * Tiga baris, persis seperti plate: kapasitas, benturan ruang, dan
     * benturan peserta. Ketiganya ditulis sebagai kalimat positif - "tidak
     * ada ruang dipakai dua sesi sekaligus" - karena itulah yang dibaca
     * Admin saat semuanya beres.
     *
     * @return array<int, array{kunci: string, label: string, lulus: bool, jumlah: int}>
     */
    public function ringkas(SchoolEvent $event): array
    {
        $temuan = collect($this->periksa($event));

        $hitung = fn (string ...$jenis) => $temuan->whereIn('jenis', $jenis)->count();

        return [
            [
                'kunci' => 'kapasitas',
                'label' => 'Kapasitas ruang cukup untuk jumlah peserta',
                'lulus' => $hitung('kapasitas-ruang') === 0,
                'jumlah' => $hitung('kapasitas-ruang'),
            ],
            [
                'kunci' => 'ruang',
                'label' => 'Tidak ada ruang dipakai dua sesi sekaligus',
                'lulus' => $hitung('benturan-ruang') === 0,
                'jumlah' => $hitung('benturan-ruang'),
            ],
            [
                'kunci' => 'peserta',
                'label' => 'Tidak ada peserta punya dua ujian bersamaan',
                'lulus' => $hitung('benturan-peserta') === 0,
                'jumlah' => $hitung('benturan-peserta'),
            ],
            [
                'kunci' => 'pengawas',
                'label' => 'Tidak ada pengawas ditugaskan di dua ruang bersamaan',
                'lulus' => $hitung('benturan-pengawas') === 0,
                'jumlah' => $hitung('benturan-pengawas'),
            ],
        ];
    }

    /**
     * Apakah daftar penugasan ini memuat dua jendela yang bertumpang.
     *
     * Dipakai PengawasService saat MENUGASKAN, bukan saat melaporkan: di sana
     * yang dibutuhkan hanya boleh/tidak, dan menyusun kalimat temuan untuk
     * sesuatu yang belum tersimpan tidak ada gunanya.
     *
     * Tetap di kelas ini, bukan disalin ke PengawasService, karena aturan
     * "kosong berarti ikut sesi" adalah aturan yang sama - dan dua salinannya
     * akan berselisih pada penugasan pertama yang benar-benar mengisi
     * kolomnya.
     *
     * @param  Collection<int, SupervisionAssignment>  $penugasan
     */
    public function adaBenturanPengawas(Collection $penugasan): bool
    {
        $daftar = $penugasan->values();

        for ($i = 0; $i < $daftar->count(); $i++) {
            for ($j = $i + 1; $j < $daftar->count(); $j++) {
                $a = $daftar[$i];
                $b = $daftar[$j];

                if ($a->exam_session_room_id === $b->exam_session_room_id) {
                    continue;
                }

                if ($this->jendelaBertumpang($a, $b)) {
                    return true;
                }
            }
        }

        return false;
    }

    /* ------------------------------------------------------------ Temuan */

    /**
     * Satu ruang dipakai dua sesi yang jendelanya bertumpang.
     *
     * @param  Collection<int, ExamSession>  $sesi
     * @return array<int, array<string, mixed>>
     */
    private function benturanRuang(Collection $sesi): array
    {
        $temuan = [];

        // Pemakaian ruang: pasangan (ruang-sesi) yang dikelompokkan per
        // event_room. Dua sesi bentrok hanya bila ruangnya SAMA.
        $pemakaian = ExamSessionRoom::query()
            ->whereIn('exam_session_id', $sesi->pluck('id'))
            ->with(['eventRoom:id,room_id', 'eventRoom.room:id,code,name'])
            ->get()
            ->groupBy('event_room_id');

        foreach ($pemakaian as $perRuang) {
            $daftar = $perRuang->values();

            for ($i = 0; $i < $daftar->count(); $i++) {
                for ($j = $i + 1; $j < $daftar->count(); $j++) {
                    $a = $daftar[$i];
                    $b = $daftar[$j];

                    $sesiA = $sesi->firstWhere('id', $a->exam_session_id);
                    $sesiB = $sesi->firstWhere('id', $b->exam_session_id);

                    if ($sesiA === null || $sesiB === null || ! $this->bertumpang($sesiA, $sesiB)) {
                        continue;
                    }

                    $kode = $a->eventRoom?->room?->code ?? 'R-?';

                    // Pesannya menyebut sesi lawannya, persis seperti contoh
                    // di plate 16: "R-02 sudah dipakai S-02 pada jam yang
                    // sama." Tanpa menyebut lawannya, Admin tahu ADA benturan
                    // tetapi tidak tahu dengan apa - dan ia harus mencarinya
                    // sendiri di tabel yang isinya puluhan baris.
                    $temuan[] = $this->temuan(
                        'benturan-ruang',
                        "{$kode} sudah dipakai {$sesiB->label()} pada jam yang sama.",
                        $sesiA->id,
                        $a->event_room_id,
                    );

                    $temuan[] = $this->temuan(
                        'benturan-ruang',
                        "{$kode} sudah dipakai {$sesiA->label()} pada jam yang sama.",
                        $sesiB->id,
                        $b->event_room_id,
                    );
                }
            }
        }

        return $temuan;
    }

    /**
     * Siswa yang sama punya dua sesi bertumpang.
     *
     * @param  Collection<int, ExamSession>  $sesi
     * @return array<int, array<string, mixed>>
     */
    private function benturanPeserta(Collection $sesi): array
    {
        $temuan = [];

        $penempatan = ExamParticipant::query()
            ->whereIn('exam_session_id', $sesi->pluck('id'))
            ->where('status', ExamParticipant::STATUS_TERDAFTAR)
            ->with('student:id,name')
            ->get()
            ->groupBy('student_id');

        foreach ($penempatan as $perSiswa) {
            $daftar = $perSiswa->values();

            for ($i = 0; $i < $daftar->count(); $i++) {
                for ($j = $i + 1; $j < $daftar->count(); $j++) {
                    $a = $daftar[$i];
                    $b = $daftar[$j];

                    if ($a->exam_session_id === $b->exam_session_id) {
                        continue;
                    }

                    $sesiA = $sesi->firstWhere('id', $a->exam_session_id);
                    $sesiB = $sesi->firstWhere('id', $b->exam_session_id);

                    if ($sesiA === null || $sesiB === null || ! $this->bertumpang($sesiA, $sesiB)) {
                        continue;
                    }

                    $nama = $a->student?->name ?? 'Siswa';

                    $temuan[] = $this->temuan(
                        'benturan-peserta',
                        "{$nama} punya {$sesiA->label()} dan {$sesiB->label()} pada jam yang sama.",
                        $sesiA->id,
                        null,
                        $a->student_id,
                    );

                    $temuan[] = $this->temuan(
                        'benturan-peserta',
                        "{$nama} punya {$sesiB->label()} dan {$sesiA->label()} pada jam yang sama.",
                        $sesiB->id,
                        null,
                        $b->student_id,
                    );
                }
            }
        }

        return $temuan;
    }

    /**
     * Pengawas yang sama ditugaskan di dua ruang pada jam yang sama.
     *
     * Jendela penugasannya diselesaikan lewat `jendela()`, karena kolom
     * start_at/end_at boleh kosong dan kosong berarti "ikuti sesi". Tanpa
     * penyelesaian itu setiap penugasan akan tampak tidak punya waktu, dan
     * pemeriksaan ini tidak akan pernah menemukan apa pun.
     *
     * @param  Collection<int, ExamSession>  $sesi
     * @return array<int, array<string, mixed>>
     */
    private function benturanPengawas(Collection $sesi): array
    {
        $temuan = [];

        $penugasan = SupervisionAssignment::query()
            ->whereIn('exam_session_room_id', ExamSessionRoom::query()
                ->whereIn('exam_session_id', $sesi->pluck('id'))
                ->pluck('id'))
            ->with(['room.session', 'room.eventRoom.room:id,code', 'supervisor:id,name'])
            ->get()
            ->groupBy('supervisor_id');

        foreach ($penugasan as $perOrang) {
            $daftar = $perOrang->values();

            for ($i = 0; $i < $daftar->count(); $i++) {
                for ($j = $i + 1; $j < $daftar->count(); $j++) {
                    $a = $daftar[$i];
                    $b = $daftar[$j];

                    // Dua penugasan pada ruang-sesi yang SAMA bukan benturan -
                    // itu justru aturannya: "satu ruang dapat memiliki
                    // beberapa Pengawas" (PRD §9.6).
                    if ($a->exam_session_room_id === $b->exam_session_room_id) {
                        continue;
                    }

                    if (! $this->jendelaBertumpang($a, $b)) {
                        continue;
                    }

                    $nama = $a->supervisor?->name ?? 'Pengawas';
                    $ruangA = $a->room?->eventRoom?->room?->code ?? 'R-?';
                    $ruangB = $b->room?->eventRoom?->room?->code ?? 'R-?';

                    $temuan[] = $this->temuan(
                        'benturan-pengawas',
                        "{$nama} ditugaskan di {$ruangA} dan {$ruangB} pada jam yang sama.",
                        $a->room?->exam_session_id,
                        $a->room?->event_room_id,
                        null,
                        $a->supervisor_id,
                    );

                    $temuan[] = $this->temuan(
                        'benturan-pengawas',
                        "{$nama} ditugaskan di {$ruangB} dan {$ruangA} pada jam yang sama.",
                        $b->room?->exam_session_id,
                        $b->room?->event_room_id,
                        null,
                        $b->supervisor_id,
                    );
                }
            }
        }

        return $temuan;
    }

    /**
     * Peserta yang ditempatkan melebihi kapasitas ruangnya.
     *
     * @param  Collection<int, ExamSession>  $sesi
     * @return array<int, array<string, mixed>>
     */
    private function kapasitasRuang(SchoolEvent $event, Collection $sesi): array
    {
        $temuan = [];

        $ruangan = ExamSessionRoom::query()
            ->whereIn('exam_session_id', $sesi->pluck('id'))
            // `capacity` ikut dimuat meski yang tampak dibaca hanya kode
            // ruangnya: kapasitasEfektif() menelusuri capacity_override lalu
            // room.capacity, dan kolom yang tidak dimuat bernilai null -
            // sehingga SETIAP ruang terbaca berkapasitas nol, dan pemeriksaan
            // ini melaporkan kapasitas kurang untuk jadwal yang sebenarnya
            // longgar. Daftar kolom yang tampak cukup adalah jebakan yang tidak
            // menyebut dirinya.
            ->with(['eventRoom.room:id,code,name,capacity', 'session:id,session_number'])
            ->withCount(['participants as terisi' => fn ($q) => $q
                ->where('status', ExamParticipant::STATUS_TERDAFTAR)])
            ->get();

        foreach ($ruangan as $r) {
            if ((int) $r->terisi <= $r->kapasitasEfektif()) {
                continue;
            }

            $kode = $r->eventRoom?->room?->code ?? 'R-?';

            $temuan[] = $this->temuan(
                'kapasitas-ruang',
                "{$kode} pada {$r->session?->label()} memuat {$r->terisi} peserta, kapasitasnya {$r->kapasitasEfektif()}.",
                $r->exam_session_id,
                $r->event_room_id,
            );
        }

        return $temuan;
    }

    /**
     * Ruang yang dipakai sesi tetapi tidak layak: berstatus perbaikan, atau
     * bahkan bukan alokasi event ini.
     *
     * Yang kedua seharusnya mustahil lewat composite FK - exam_session_rooms
     * menunjuk event_rooms, dan event_rooms menunjuk satu event. Tetapi
     * "mustahil lewat skema" dan "mustahil lewat kode" adalah dua hal
     * berbeda: id event_rooms milik event LAIN di sekolah yang sama tetap
     * bisa dikirim, dan FK-nya lolos. Inilah yang menangkapnya.
     *
     * @param  Collection<int, ExamSession>  $sesi
     * @return array<int, array<string, mixed>>
     */
    private function ruangTidakLayak(SchoolEvent $event, Collection $sesi): array
    {
        $temuan = [];

        $ruangan = ExamSessionRoom::query()
            ->whereIn('exam_session_id', $sesi->pluck('id'))
            ->with(['eventRoom.room:id,code,name', 'session:id,session_number'])
            ->get();

        foreach ($ruangan as $r) {
            $kode = $r->eventRoom?->room?->code ?? 'R-?';
            $label = $r->session?->label() ?? 'S-?';

            if ($r->eventRoom !== null && $r->eventRoom->event_id !== $event->id) {
                $temuan[] = $this->temuan(
                    'ruang-luar-event',
                    "{$kode} tidak dialokasikan ke event ini, tetapi dipakai {$label}.",
                    $r->exam_session_id,
                    $r->event_room_id,
                );

                continue;
            }

            if ($r->eventRoom !== null && $r->eventRoom->status !== 'aktif') {
                $temuan[] = $this->temuan(
                    'ruang-tidak-aktif',
                    "{$kode} berstatus {$r->eventRoom->status}, tetapi dipakai {$label}.",
                    $r->exam_session_id,
                    $r->event_room_id,
                );
            }
        }

        return $temuan;
    }

    /* ----------------------------------------------------------- Pembantu */

    /**
     * Seluruh sesi event ini, beserta ujiannya.
     *
     * @return Collection<int, ExamSession>
     */
    private function sesiEvent(SchoolEvent $event): Collection
    {
        return ExamSession::query()
            ->whereHas('exam', fn ($q) => $q->where('event_id', $event->id))
            ->whereNotIn('status', ['batal'])
            ->with('exam:id,event_id,title')
            ->get();
    }

    /**
     * Dua sesi bertumpang bila jendelanya bersinggungan.
     *
     * Perbandingan ketat (`<`, bukan `<=`): sesi yang selesai tepat ketika
     * sesi berikutnya dibuka TIDAK bentrok. Itu justru penyusunan jadwal
     * yang benar, dan menandainya sebagai benturan berarti menghukum sekolah
     * yang memakai ruangnya efisien.
     */
    private function bertumpang(ExamSession $a, ExamSession $b): bool
    {
        return $a->access_opens_at < $b->hard_end_at
            && $b->access_opens_at < $a->hard_end_at;
    }

    private function jendelaBertumpang(SupervisionAssignment $a, SupervisionAssignment $b): bool
    {
        [$mulaiA, $selesaiA] = $a->jendela();
        [$mulaiB, $selesaiB] = $b->jendela();

        if ($mulaiA === null || $selesaiA === null || $mulaiB === null || $selesaiB === null) {
            return false;
        }

        return $mulaiA < $selesaiB && $mulaiB < $selesaiA;
    }

    /** @return array<string, mixed> */
    private function temuan(
        string $jenis,
        string $pesan,
        ?int $sesiId = null,
        ?int $ruangId = null,
        ?int $pesertaId = null,
        ?int $pengawasId = null,
    ): array {
        return [
            'jenis' => $jenis,
            'pesan' => $pesan,
            'sesi_id' => $sesiId,
            'ruang_id' => $ruangId,
            'peserta_id' => $pesertaId,
            'pengawas_id' => $pengawasId,
        ];
    }
}

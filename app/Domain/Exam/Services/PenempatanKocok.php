<?php

namespace App\Domain\Exam\Services;

use App\Domain\Event\Models\EventParticipant;
use App\Domain\Event\Models\EventRoom;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Enums\ModePenempatan;
use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Models\ExamEventShuffle;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Models\ExamSessionRoom;
use DomainException;
use Illuminate\Support\Collection;

/**
 * Kocokan penempatan peserta berbenih di tingkat ruang event (`event_room_id`).
 * Penerjemahan ke ruang sesi dilakukan di `petaSesi()`, sedangkan penulisan tetap di `PenempatanService`.
 */
class PenempatanKocok
{
    /** Karakter benih tanpa I, O, 0, dan 1 agar tidak tertukar saat dibacakan. */
    private const HURUF_BENIH = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly PenempatanService $penempatan,
    ) {}

    /**
     * Kelompokkan peserta terdaftar event menurut kelasnya (kelas null mendapat kelompok sendiri).
     * Diambil dari `event_participants` agar satu peta berlaku untuk seluruh event.
     *
     * @return array<int, array{kelas: ?int, nama: string, peserta: array<int, EventParticipant>}>
     */
    public function kelompokKelas(SchoolEvent $event): array
    {
        $peserta = EventParticipant::query()
            ->where('event_id', $event->id)
            ->where('status', EventParticipant::STATUS_TERDAFTAR)
            // Eager-load studentProfile agar pencetakan NIS tidak memicu N+1.
            ->with(['kelas:id,name', 'student:id,name,username', 'student.studentProfile:id,user_id,nis'])
            ->orderBy('student_id')
            ->get();

        $kelompok = [];

        foreach ($peserta as $p) {
            $kunci = $p->class_id ?? 0;

            if (! isset($kelompok[$kunci])) {
                $kelompok[$kunci] = [
                    'kelas' => $p->class_id,
                    'nama' => $p->kelas?->name ?? 'Tanpa kelas',
                    'peserta' => [],
                ];
            }

            $kelompok[$kunci]['peserta'][] = $p;
        }

        // Urutkan kelas terbesar lebih dulu agar rotasi dan pembagian ruang adil saat kapasitas kurang.
        usort($kelompok, fn (array $a, array $b) => count($b['peserta']) <=> count($a['peserta'])
            ?: strcmp($a['nama'], $b['nama']));

        return array_values($kelompok);
    }

    /**
     * Acak urutan peserta di dalam tiap kelas memakai benih yang sama untuk semua mode.
     *
     * @param  array<int, array{kelas: ?int, nama: string, peserta: array<int, EventParticipant>}>  $kelompok
     * @return array<int, array{kelas: ?int, nama: string, peserta: array<int, EventParticipant>}>
     */
    private function kelompokAcak(array $kelompok, string $benih): array
    {
        mt_srand($this->benihKeAngka($benih));

        // Acak isi tiap kelompok terlebih dulu karena peserta urut berdasarkan student_id.
        $isi = array_map(function (array $k) {
            $orang = $k['peserta'];
            $this->acakSendiri($orang);
            $k['peserta'] = $orang;

            return $k;
        }, $kelompok);

        // Reset seed global agar pemanggilan acak berikutnya di proses yang sama tidak bisa ditebak.
        mt_srand();

        return $isi;
    }

    /**
     * Susun antrean datar hasil rotasi antar kelas untuk mode `campur`.
     * Parameter `$mode` dipertahankan demi kompatibilitas pemanggil lama dan tidak mengubah perilaku.
     *
     * @param  array<int, array{kelas: ?int, nama: string, peserta: array<int, EventParticipant>}>  $kelompok
     * @return Collection<int, EventParticipant>
     */
    public function antrean(array $kelompok, string $benih, ModePenempatan $mode = ModePenempatan::Campur): Collection
    {
        return collect($this->antreanRotasi(array_map(
            fn (array $k) => $k['peserta'],
            $this->kelompokAcak($kelompok, $benih),
        )));
    }

    /**
     * Rotasi ambil satu peserta per kelompok sampai habis.
     * Memakai penghitung `$sisa` karena `foreach` bekerja pada salinan array sehingga `array_shift` tidak menghentikan loop.
     *
     * @param  array<int, array<int, EventParticipant>>  $isi
     * @return array<int, EventParticipant>
     */
    private function antreanRotasi(array $isi): array
    {
        $antrean = [];
        $sisa = array_map('count', $isi);

        while (array_sum($sisa) > 0) {
            foreach ($isi as $i => $daftar) {
                if ($sisa[$i] === 0) {
                    continue;
                }

                $antrean[] = array_shift($isi[$i]);
                $sisa[$i]--;
            }
        }

        return $antrean;
    }

    /**
     * Susun antrean berurutan per kelas; kelas yang melebihi satu ruang berlanjut ke ruang berikutnya.
     *
     * @param  array<int, array<int, EventParticipant>>  $isi
     * @return array<int, EventParticipant>
     */
    private function antreanPerKelas(array $isi): array
    {
        $antrean = [];

        foreach ($isi as $daftar) {
            foreach ($daftar as $orang) {
                $antrean[] = $orang;
            }
        }

        return $antrean;
    }

    /**
     * Bagi antrean berurutan ke ruang event sampai penuh sesuai kapasitas efektif (mode `campur`).
     *
     * @param  Collection<int, EventParticipant>  $antrean
     * @param  Collection<int, EventRoom>  $ruangan
     * @return array<int, array<int, EventParticipant>> Kunci = event_room_id.
     */
    public function bagi(Collection $antrean, Collection $ruangan): array
    {
        $hasil = [];
        $posisi = 0;

        foreach ($ruangan as $r) {
            $muat = $r->kapasitasEfektif();
            $hasil[$r->id] = $antrean->slice($posisi, $muat)->values()->all();
            $posisi += $muat;
        }

        return $hasil;
    }

    /**
     * Bagi peserta per kelas (mode `kelas`): tiap kelas mulai di ruang kosong baru.
     * Kelas yang melebihi satu ruang merambat ke ruang berikutnya; sisa yang tak kebagian ruang bersih ditempatkan di kursi kosong mana pun.
     *
     * @param  array<int, array{kelas: ?int, nama: string, peserta: array<int, EventParticipant>}>  $kelompok
     * @param  Collection<int, EventRoom>  $ruangan
     * @return array<int, array<int, EventParticipant>> Kunci = event_room_id.
     */
    public function bagiPerKelas(array $kelompok, Collection $ruangan): array
    {
        $idRuang = $ruangan->pluck('id')->all();
        $kapasitas = $ruangan->mapWithKeys(fn (EventRoom $r) => [$r->id => $r->kapasitasEfektif()])->all();
        $jumlahRuang = count($idRuang);

        $hasil = array_fill_keys($idRuang, []);

        $sisa = [];

        foreach ($kelompok as $k) {
            $orang = array_values($k['peserta']);

            if ($orang === []) {
                continue;
            }

            $ke = null;

            for ($i = 0; $i < $jumlahRuang; $i++) {
                if ($hasil[$idRuang[$i]] === []) {
                    $ke = $i;
                    break;
                }
            }

            if ($ke === null) {
                $sisa = [...$sisa, ...$orang];

                continue;
            }

            foreach ($orang as $satu) {
                while ($ke < $jumlahRuang && count($hasil[$idRuang[$ke]]) >= $kapasitas[$idRuang[$ke]]) {
                    $ke++;
                }

                if ($ke >= $jumlahRuang) {
                    $sisa[] = $satu;

                    continue;
                }

                $hasil[$idRuang[$ke]][] = $satu;
            }
        }

        $this->taruhDiSisa($hasil, $idRuang, $kapasitas, $sisa);

        return $hasil;
    }

    /**
     * Pindahkan peserta bertanda butuh perangkat ke kursi ber-PC setelah pembagian selesai.
     * Pertukaran mempertahankan jumlah penghuni tiap ruang; sisa yang tidak kebagian dilaporkan di `kurang`.
     *
     * @param  array<int, array<int, EventParticipant>>  $hasil
     * @param  Collection<int, EventRoom>  $ruangan
     * @return array{per_ruang: array<int, array<int, EventParticipant>>, kurang: array<int, array{nama: string, ruang: string}>}
     */
    public function hormatiPerangkat(array $hasil, Collection $ruangan): array
    {
        $adaPc = $ruangan->mapWithKeys(
            fn (EventRoom $r) => [$r->id => $r->kursiBerPerangkat()],
        )->all();

        $kode = $ruangan->mapWithKeys(
            fn (EventRoom $r) => [$r->id => $r->room?->code ?? "R-#{$r->id}"],
        )->all();

        $bertanda = fn (EventParticipant $p) => (bool) ($p->butuh_perangkat ?? false);

        // Tukar peserta bertanda di luar jatah PC dengan peserta tanpa tanda di kursi PC ruang yang sama.
        foreach (array_keys($hasil) as $id) {
            $kursiPc = $adaPc[$id] ?? 0;

            $diPc = array_slice($hasil[$id], 0, $kursiPc, true);
            $angka = array_keys($diPc);

            foreach ($angka as $pos) {
                if ($bertanda($hasil[$id][$pos])) {
                    continue;
                }

                foreach (array_keys($hasil[$id]) as $lain) {
                    if ($lain < $kursiPc || ! $bertanda($hasil[$id][$lain])) {
                        continue;
                    }

                    [$hasil[$id][$pos], $hasil[$id][$lain]] = [$hasil[$id][$lain], $hasil[$id][$pos]];

                    continue 2;
                }
            }
        }

        // Jika kursi PC ruang ini penuh peserta bertanda, tukar sisanya ke kursi PC ruang lain.
        $kurang = [];

        foreach (array_keys($hasil) as $id) {
            $kursiPc = $adaPc[$id] ?? 0;

            $diLuar = array_filter(
                array_keys($hasil[$id]),
                fn (int $pos) => $pos >= $kursiPc && $bertanda($hasil[$id][$pos]),
            );

            foreach ($diLuar as $pos) {
                $tujuan = null;

                foreach (array_keys($hasil) as $lain) {
                    if ($lain === $id) {
                        continue;
                    }

                    $pcLain = $adaPc[$lain] ?? 0;

                    if ($pcLain === 0) {
                        continue;
                    }

                    foreach (array_keys($hasil[$lain]) as $posLain) {
                        if ($posLain >= $pcLain) {
                            break;
                        }

                        if (! $bertanda($hasil[$lain][$posLain])) {
                            $tujuan = [$lain, $posLain];

                            break 2;
                        }
                    }
                }

                if ($tujuan === null) {
                    $kurang[] = [
                        'nama' => (string) ($hasil[$id][$pos]->student?->name ?? 'Peserta'),
                        'ruang' => $kode[$id] ?? 'R-?',
                    ];

                    continue;
                }

                [$lain, $posLain] = $tujuan;

                [$hasil[$id][$pos], $hasil[$lain][$posLain]] = [$hasil[$lain][$posLain], $hasil[$id][$pos]];
            }
        }

        // Rapikan indeks array karena array_slice(..., true) mempertahankan kunci asli.
        foreach ($hasil as $id => $isi) {
            $hasil[$id] = array_values($isi);
        }

        return ['per_ruang' => $hasil, 'kurang' => $kurang];
    }

    private function taruhDiSisa(array &$hasil, array $idRuang, array $kapasitas, array $sisa): void
    {
        $pos = 0;

        foreach ($idRuang as $id) {
            while ($pos < count($sisa) && count($hasil[$id]) < $kapasitas[$id]) {
                $hasil[$id][] = $sisa[$pos++];
            }
        }
    }

    /**
     * Sebar tiap kelas merata ke seluruh ruang dengan menghormati kapasitas efektif (mode `rata`).
     *
     * @param  array<int, array{kelas: ?int, nama: string, peserta: array<int, EventParticipant>}>  $kelompok
     * @param  Collection<int, EventRoom>  $ruangan
     * @return array<int, array<int, EventParticipant>> Kunci = event_room_id.
     */
    public function bagiRata(array $kelompok, Collection $ruangan): array
    {
        $idRuang = $ruangan->pluck('id')->all();
        $kapasitas = $ruangan->mapWithKeys(fn (EventRoom $r) => [$r->id => $r->kapasitasEfektif()])->all();
        $jumlahRuang = count($idRuang);

        $hasil = array_fill_keys($idRuang, []);

        if ($jumlahRuang === 0) {
            return $hasil;
        }

        // Kursor berputar antar kelas agar distribusi tidak menumpuk di ruang pertama.
        $pos = 0;

        foreach ($kelompok as $k) {
            foreach ($k['peserta'] as $orang) {
                for ($t = 0; $t < $jumlahRuang; $t++) {
                    $idx = ($pos + $t) % $jumlahRuang;
                    $id = $idRuang[$idx];

                    if (count($hasil[$id]) < $kapasitas[$id]) {
                        $hasil[$id][] = $orang;
                        $pos = ($idx + 1) % $jumlahRuang;

                        continue 2;
                    }
                }

                break 2;
            }
        }

        return $hasil;
    }

    /**
     * Hitung hasil kocokan satu event untuk pratinjau maupun penyimpanan.
     *
     * @return array{
     *     benih: string,
     *     mode: ModePenempatan,
     *     antrean: Collection<int, EventParticipant>,
     *     per_ruang: array<int, array<int, EventParticipant>>,
     *     kapasitas: int,
     *     peserta: int,
     *     kurang: int,
     *     seisi_satu_kelas: array<int, string>,
     *     kelas_terbelah: array<int, string>,
     *     rincian_kelas: array<int, array{nama: string, jumlah: int, ruang: array<int, string>}>
     * }
     *
     * @throws DomainException Bila event belum punya ruang.
     */
    public function susun(SchoolEvent $event, ?string $benih = null, ModePenempatan $mode = ModePenempatan::Campur): array
    {
        $benih ??= $this->benihBaru();

        $ruangan = $this->ruanganEvent($event);

        if ($ruangan->isEmpty()) {
            throw new DomainException("Event {$event->name} belum punya ruang.");
        }

        $ruangKhusus = $ruangan->filter(fn (EventRoom $r) => $r->isKhususPerangkat())->values();
        $ruangReguler = $ruangan->reject(fn (EventRoom $r) => $r->isKhususPerangkat())->values();

        $kelompok = $this->kelompokKelas($event);

        // Jika tidak ada ruang khusus perangkat, seluruh ruang & peserta memakai alur standar
        // agar perilaku lab campuran (hormatiPerangkat pada PC terbatas) tetap terjaga.
        if ($ruangKhusus->isEmpty()) {
            [$perRuang, $antrean] = $this->susunKolam($kelompok, $ruangan, $benih, $mode);

            $perangkat = $this->hormatiPerangkat($perRuang, $ruangan);
            $perRuang = $perangkat['per_ruang'];

            $kapasitas = $ruangan->sum(fn (EventRoom $r) => $r->kapasitasEfektif());

            return [
                'benih' => $benih,
                'mode' => $mode,
                'antrean' => $antrean,
                'per_ruang' => $perRuang,
                'kapasitas' => $kapasitas,
                'peserta' => $antrean->count(),
                'kurang' => max(0, $antrean->count() - $kapasitas),
                'seisi_satu_kelas' => $this->seisiSatuKelas($perRuang, $ruangan),
                // Kelas terbelah hanya relevan di mode kelas.
                'kelas_terbelah' => $mode === ModePenempatan::Kelas
                    ? $this->kelasTerbelah($perRuang, $ruangan)
                    : [],
                'rincian_kelas' => $this->rincianKelas($perRuang, $ruangan),

                'perangkat_kurang' => $perangkat['kurang'],
                'kursi_berperangkat' => $ruangan->sum(fn (EventRoom $r) => $r->kursiBerPerangkat()),
                'bertanda' => $antrean->filter(fn (EventParticipant $p) => (bool) ($p->butuh_perangkat ?? false))->count(),

                'peserta_reguler' => $antrean->count(),
                'kapasitas_reguler' => $kapasitas,
                'kurang_reguler' => max(0, $antrean->count() - $kapasitas),
                'ruang_reguler' => $ruangan->count(),
                'ruang_khusus' => 0,
                'kapasitas_khusus' => 0,
                'terisi_khusus' => 0,
            ];
        }

        // Pisahkan kolam reguler vs kolam khusus perangkat.
        // Siswa reguler (butuh_perangkat = false) tidak pernah masuk ke ruang khusus perangkat,
        // sehingga kursi lab yang tersisa tetap siaga untuk pindah ruang darurat hari-H.
        [$kelompokReguler, $kelompokKhusus] = $this->pisahKelompokPerangkat($kelompok);

        $antreanKhusus = $this->antrean($kelompokKhusus, $benih, ModePenempatan::Campur);
        $kapasitasKhusus = $ruangKhusus->sum(fn (EventRoom $r) => $r->kapasitasEfektif());
        $masukKhusus = $antreanKhusus->slice(0, $kapasitasKhusus)->values();
        $sisaKhusus = $antreanKhusus->slice($kapasitasKhusus)->values();
        $perRuangKhusus = $this->bagi($masukKhusus, $ruangKhusus);

        $perangkatKurang = [];
        $kapasitasPcReguler = $ruangReguler->sum(fn (EventRoom $r) => $r->kursiBerPerangkat());

        if ($sisaKhusus->isNotEmpty()) {
            if ($kapasitasPcReguler > 0) {
                $kelompokReguler = $this->gabungSisaKeKelompok($kelompokReguler, $sisaKhusus);
            } else {
                foreach ($sisaKhusus as $p) {
                    $perangkatKurang[] = [
                        'nama' => (string) ($p->student?->name ?? 'Peserta'),
                        'ruang' => 'Lab Khusus Penuh',
                    ];
                }
            }
        }

        [$perRuangReguler, $antreanReguler] = $this->susunKolam($kelompokReguler, $ruangReguler, $benih, $mode);
        $perangkatReguler = $this->hormatiPerangkat($perRuangReguler, $ruangReguler);
        $perRuangReguler = $perangkatReguler['per_ruang'];
        $perangkatKurang = array_merge($perangkatKurang, $perangkatReguler['kurang']);

        $perRuang = [];
        foreach ($ruangan as $r) {
            $perRuang[$r->id] = $perRuangKhusus[$r->id] ?? $perRuangReguler[$r->id] ?? [];
        }

        $kapasitasReguler = $ruangReguler->sum(fn (EventRoom $r) => $r->kapasitasEfektif());
        $kapasitasTotal = $kapasitasReguler + $kapasitasKhusus;
        $antreanTotal = $antreanReguler->concat($masukKhusus)->values();
        $jumlahPesertaTotal = $antreanReguler->count() + $antreanKhusus->count();
        $ditempatkan = collect($perRuang)->sum(fn (array $isi) => count($isi));
        $kurangReguler = max(0, $antreanReguler->count() - $kapasitasReguler);
        $kurang = max(0, $jumlahPesertaTotal - $ditempatkan);

        return [
            'benih' => $benih,
            'mode' => $mode,
            'antrean' => $antreanTotal,
            'per_ruang' => $perRuang,
            'kapasitas' => $kapasitasTotal,
            'peserta' => $jumlahPesertaTotal,
            'kurang' => $kurang,
            // Temuan seisi satu kelas & kelas terbelah hanya relevan untuk ruang reguler.
            'seisi_satu_kelas' => $this->seisiSatuKelas($perRuangReguler, $ruangReguler),
            'kelas_terbelah' => $mode === ModePenempatan::Kelas
                ? $this->kelasTerbelah($perRuangReguler, $ruangReguler)
                : [],
            'rincian_kelas' => $this->rincianKelas($perRuang, $ruangan),

            'perangkat_kurang' => $perangkatKurang,
            'kursi_berperangkat' => $ruangan->sum(fn (EventRoom $r) => $r->kursiBerPerangkat()),
            'bertanda' => $antreanKhusus->count(),

            'peserta_reguler' => $antreanReguler->count(),
            'kapasitas_reguler' => $kapasitasReguler,
            'kurang_reguler' => $kurangReguler,
            'ruang_reguler' => $ruangReguler->count(),
            'ruang_khusus' => $ruangKhusus->count(),
            'kapasitas_khusus' => $kapasitasKhusus,
            'terisi_khusus' => $masukKhusus->count(),
        ];
    }

    /**
     * @param  array<int, array{kelas: ?int, nama: string, peserta: array<int, EventParticipant>}>  $kelompok
     * @param  Collection<int, EventRoom>  $ruangan
     * @return array{0: array<int, array<int, EventParticipant>>, 1: Collection<int, EventParticipant>}
     */
    private function susunKolam(array $kelompok, Collection $ruangan, string $benih, ModePenempatan $mode): array
    {
        if ($mode === ModePenempatan::Kelas || $mode === ModePenempatan::Rata) {
            $kelompokAcak = $this->kelompokAcak($kelompok, $benih);

            $perRuang = $mode === ModePenempatan::Kelas
                ? $this->bagiPerKelas($kelompokAcak, $ruangan)
                : $this->bagiRata($kelompokAcak, $ruangan);

            $antrean = collect($this->antreanPerKelas(array_map(fn (array $k) => $k['peserta'], $kelompokAcak)));
        } else {
            $antrean = $this->antrean($kelompok, $benih, $mode);
            $perRuang = $this->bagi($antrean, $ruangan);
        }

        return [$perRuang, $antrean];
    }

    /**
     * @param  array<int, array{kelas: ?int, nama: string, peserta: array<int, EventParticipant>}>  $kelompok
     * @return array{
     *     0: array<int, array{kelas: ?int, nama: string, peserta: array<int, EventParticipant>}>,
     *     1: array<int, array{kelas: ?int, nama: string, peserta: array<int, EventParticipant>}>
     * }
     */
    private function pisahKelompokPerangkat(array $kelompok): array
    {
        $reguler = [];
        $khusus = [];

        foreach ($kelompok as $k) {
            $isiReguler = [];
            $isiKhusus = [];

            foreach ($k['peserta'] as $p) {
                if ((bool) ($p->butuh_perangkat ?? false)) {
                    $isiKhusus[] = $p;
                } else {
                    $isiReguler[] = $p;
                }
            }

            if ($isiReguler !== []) {
                $reguler[] = [
                    'kelas' => $k['kelas'],
                    'nama' => $k['nama'],
                    'peserta' => $isiReguler,
                ];
            }

            if ($isiKhusus !== []) {
                $khusus[] = [
                    'kelas' => $k['kelas'],
                    'nama' => $k['nama'],
                    'peserta' => $isiKhusus,
                ];
            }
        }

        return [$reguler, $khusus];
    }

    /**
     * @param  array<int, array{kelas: ?int, nama: string, peserta: array<int, EventParticipant>}>  $kelompok
     * @param  Collection<int, EventParticipant>  $sisa
     * @return array<int, array{kelas: ?int, nama: string, peserta: array<int, EventParticipant>}>
     */
    private function gabungSisaKeKelompok(array $kelompok, Collection $sisa): array
    {
        $peta = [];
        foreach ($kelompok as $k) {
            $peta[$k['kelas'] ?? 0] = $k;
        }

        foreach ($sisa as $p) {
            $kunci = $p->class_id ?? 0;
            if (! isset($peta[$kunci])) {
                $peta[$kunci] = [
                    'kelas' => $p->class_id,
                    'nama' => $p->kelas?->name ?? 'Tanpa kelas',
                    'peserta' => [],
                ];
            }
            $peta[$kunci]['peserta'][] = $p;
        }

        $hasil = array_values($peta);
        usort($hasil, fn (array $a, array $b) => count($b['peserta']) <=> count($a['peserta'])
            ?: strcmp($a['nama'], $b['nama']));

        return $hasil;
    }

    /**
     * Terjemahkan peta ruang event ke peta ruang satu sesi tanpa menulis ke basis data.
     * Hanya memetakan peserta yang terdaftar di ujian sesi ini.
     *
     * @param  array<int, array<int, EventParticipant>>  $perRuang
     * @return array{
     *     peta: array<int, int>,
     *     lengkap: bool,
     *     ruang_hilang: array<int, string>,
     *     terlalu_penuh: array<int, string>,
     *     tidak_ditempatkan: array<int, string>
     * }
     */
    public function petaSesi(SchoolEvent $event, ExamSession $sesi, array $perRuang): array
    {
        $ruangSesi = ExamSessionRoom::query()
            ->where('exam_session_id', $sesi->id)
            // Kolom capacity wajib dimuat agar kapasitasEfektif() tidak jatuh ke 0.
            ->with('eventRoom.room:id,code,capacity')
            ->get()
            ->keyBy('event_room_id');

        $pesertaUjian = ExamParticipant::query()
            ->where('exam_id', $sesi->exam_id)
            ->where('status', ExamParticipant::STATUS_TERDAFTAR)
            ->pluck('id', 'student_id');

        $peta = [];
        $ruangHilang = [];
        $muatan = [];

        foreach ($perRuang as $idRuangEvent => $anggota) {
            $ruang = $ruangSesi->get($idRuangEvent);

            if ($ruang === null) {
                if ($anggota !== []) {
                    $ruangHilang[] = $this->kodeRuangEvent($event, $idRuangEvent);
                }

                continue;
            }

            foreach ($anggota as $p) {
                $idPesertaUjian = $pesertaUjian->get($p->student_id);

                if ($idPesertaUjian === null) {
                    continue;
                }

                $peta[$idPesertaUjian] = $ruang->id;
                $muatan[$idRuangEvent] = ($muatan[$idRuangEvent] ?? 0) + 1;
            }
        }

        $terlaluPenuh = [];
        $tidakDitempatkan = [];

        foreach ($ruangSesi as $idRuangEvent => $ruang) {
            $jumlah = $muatan[$idRuangEvent] ?? 0;

            if ($jumlah > $ruang->kapasitasEfektif()) {
                $kode = $ruang->eventRoom?->room?->code ?? "ruang #{$idRuangEvent}";
                $terlaluPenuh[] = "{$kode} ({$jumlah} orang, {$ruang->kapasitasEfektif()} kursi)";
            }
        }

        $tanpaKursi = ExamParticipant::query()
            ->where('exam_id', $sesi->exam_id)
            ->where('status', ExamParticipant::STATUS_TERDAFTAR)
            ->whereNotIn('id', $peta === [] ? [0] : array_keys($peta))
            ->count();

        if ($tanpaKursi > 0) {
            $tidakDitempatkan[] = $tanpaKursi.' peserta tanpa kursi';
        }

        return [
            'peta' => $peta,
            'lengkap' => $ruangHilang === [] && $terlaluPenuh === [] && $tidakDitempatkan === [],
            'ruang_hilang' => $ruangHilang,
            'terlalu_penuh' => $terlaluPenuh,
            'tidak_ditempatkan' => $tidakDitempatkan,
        ];
    }

    /**
     * Kocok dan terapkan satu peta ruang event ke seluruh sesi utama.
     * Seluruh sesi divalidasi lebih dulu; satu sesi bermasalah membatalkan seluruh penyebaran.
     *
     * @return array<string, mixed>
     */
    public function sebarEvent(
        SchoolEvent $event,
        ?string $benih = null,
        ?int $olehUserId = null,
        ?ModePenempatan $mode = null,
    ): array {
        // Benih dan mode yang null memakai nilai tersimpan agar sebar ulang bersifat idempoten.
        $tersimpan = $this->benihTersimpan($event);

        // Tolak penulisan ulang jika penempatan sudah dikunci (dijaga di service agar berlaku di luar HTTP).
        if ($tersimpan !== null && $tersimpan->terkunci()) {
            throw new DomainException(
                'Penempatan event ini sudah dikunci, sehingga kursi tidak bisa disebar ulang. '.
                'Buka kuncinya lebih dulu di laman Penempatan bila memang harus diubah.',
            );
        }

        $benih ??= $tersimpan?->seed;
        $mode ??= $tersimpan?->mode ?? ModePenempatan::Campur;

        $susunan = $this->susun($event, $benih, $mode);

        if ($susunan['kurang'] > 0) {
            throw new DomainException(
                "Kapasitas seluruh ruang event hanya {$susunan['kapasitas']} kursi ".
                "untuk {$susunan['peserta']} peserta.",
            );
        }

        // Tarik peserta ujian lebih dulu agar petaSesi() punya baris exam_participants untuk dipetakan.
        foreach (Exam::query()->where('event_id', $event->id)->get() as $u) {
            $this->penempatan->tarikPeserta($u);
        }

        $rencana = [];
        $bermasalah = [];
        $perUjian = $this->sesiEvent($event)->groupBy('exam_id');

        foreach ($perUjian as $idUjian => $sesiUjian) {
            // Lewati sesi susulan karena pesertanya dipindahkan manual oleh admin.
            $utama = $sesiUjian->reject(fn (ExamSession $s) => $s->susulan());

            if ($utama->isEmpty()) {
                continue;
            }

            // Satu baris exam_participants hanya menunjuk satu sesi; lebih dari satu sesi utama bersifat ambigu.
            if ($utama->count() > 1) {
                $bermasalah[] = "ujian {$idUjian} punya {$utama->count()} sesi utama - ".
                    'peta ruang event tidak bisa menentukan siswa mana di sesi mana';

                continue;
            }

            $sesi = $utama->first();
            $peta = $this->petaSesi($event, $sesi, $susunan['per_ruang']);

            if (! $peta['lengkap']) {
                $sebab = array_merge(
                    $peta['ruang_hilang'] === [] ? [] : ['ruang tidak dipakai: '.implode(', ', $peta['ruang_hilang'])],
                    $peta['terlalu_penuh'],
                    $peta['tidak_ditempatkan'],
                );

                $bermasalah[] = "{$sesi->label()} - ".implode('; ', $sebab);

                continue;
            }

            $rencana[] = ['sesi' => $sesi, 'peta' => $peta['peta']];
        }

        if ($bermasalah !== []) {
            throw new DomainException(
                'Penempatan dibatalkan, tidak satu kursi pun diubah. Sesi yang belum bisa memakai '.
                'peta ruang event: '.implode(' | ', $bermasalah).'. Samakan ruang setiap sesi dengan '.
                'ruang event di laman Jadwal & sesi, lalu sebar lagi.',
            );
        }

        if ($rencana === []) {
            throw new DomainException("Event {$event->name} belum punya sesi ujian.");
        }

        foreach ($rencana as $r) {
            $ujian = $r['sesi']->exam;

            if ($ujian === null) {
                continue;
            }

            $this->penempatan->sebarKeRuang($ujian, $r['sesi'], $r['peta']);
        }

        $this->simpan($event, $susunan['benih'], $susunan['peserta'], $olehUserId, $mode);

        return [
            'benih' => $susunan['benih'],
            'mode' => $mode,
            'peserta' => $susunan['peserta'],
            'sesi' => count($rencana),
        ];
    }

    /**
     * Daftar kelas yang terbagi ke lebih dari satu ruang (khusus mode `kelas`).
     *
     * @param  array<int, array<int, EventParticipant>>  $perRuang
     * @param  Collection<int, EventRoom>|null  $ruangan
     * @return array<int, string>
     */
    public function kelasTerbelah(array $perRuang, ?Collection $ruangan = null): array
    {
        $ruangPerKelas = [];

        foreach ($perRuang as $idRuang => $anggota) {
            $kode = $ruangan?->firstWhere('id', $idRuang)?->room?->code ?? "R-#{$idRuang}";

            foreach (collect($anggota)->map(fn (EventParticipant $p) => $p->class_id)->filter()->unique() as $idKelas) {
                $nama = collect($anggota)
                    ->first(fn (EventParticipant $p) => $p->class_id === $idKelas)
                    ?->kelas?->name ?? "kelas #{$idKelas}";

                $ruangPerKelas[$nama][] = $kode;
            }
        }

        $temuan = [];

        foreach ($ruangPerKelas as $nama => $daftar) {
            if (count($daftar) > 1) {
                $temuan[] = $nama.' terbagi ke '.implode(' + ', $daftar);
            }
        }

        return $temuan;
    }

    /**
     * Ringkasan sebaran tiap kelas beserta jumlah pesertanya per ruang.
     *
     * @param  array<int, array<int, EventParticipant>>  $perRuang
     * @param  Collection<int, EventRoom>|null  $ruangan
     * @return array<int, array{nama: string, jumlah: int, ruang: array<int, string>}>
     */
    public function rincianKelas(array $perRuang, ?Collection $ruangan = null): array
    {
        $perKelas = [];

        foreach ($perRuang as $idRuang => $anggota) {
            $kode = $ruangan?->firstWhere('id', $idRuang)?->room?->code ?? "R-#{$idRuang}";

            foreach ($anggota as $p) {
                $nama = $p->kelas?->name ?? 'Tanpa kelas';

                $perKelas[$nama] ??= ['nama' => $nama, 'jumlah' => 0, 'ruang' => []];
                $perKelas[$nama]['jumlah']++;

                $jumlahRuang = $perKelas[$nama]['ruang'][$kode] ?? 0;
                $perKelas[$nama]['ruang'][$kode] = $jumlahRuang + 1;
            }
        }

        return collect($perKelas)
            ->sortKeys()
            ->map(fn (array $k) => [
                'nama' => $k['nama'],
                'jumlah' => $k['jumlah'],
                'ruang' => collect($k['ruang'])
                    ->map(fn (int $n, string $kode) => "{$kode} · {$n} orang")
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Daftar ruang yang seluruh pesertanya berasal dari satu kelas (temuan mode `campur`).
     *
     * @param  array<int, array<int, EventParticipant>>  $perRuang
     * @param  Collection<int, EventRoom>|null  $ruangan
     * @return array<int, string> Kunci = event_room_id, isi = kode ruang.
     */
    public function seisiSatuKelas(array $perRuang, ?Collection $ruangan = null): array
    {
        $temuan = [];

        foreach ($perRuang as $idRuang => $anggota) {
            $kelas = collect($anggota)
                ->map(fn (EventParticipant $p) => $p->class_id)
                ->filter()
                ->unique();

            // Abaikan ruang kosong atau berisi 1 orang agar tidak memicu false positive.
            if (count($anggota) > 1 && $kelas->count() === 1) {
                $temuan[$idRuang] = $ruangan?->firstWhere('id', $idRuang)?->room?->code ?? 'R-?';
            }
        }

        return $temuan;
    }

    /** Simpan hasil kocokan secara idempoten (satu baris per event). */
    public function simpan(
        SchoolEvent $event,
        string $benih,
        int $peserta,
        ?int $olehUserId,
        ModePenempatan $mode = ModePenempatan::Campur,
    ): ExamEventShuffle {
        return ExamEventShuffle::query()->updateOrCreate(
            [
                'school_id' => $event->school_id,
                'event_id' => $event->id,
            ],
            [
                'seed' => $benih,
                'mode' => $mode,
                'shuffled_at' => now(),
                'shuffled_by' => $olehUserId,
                'peserta' => $peserta,
            ],
        );
    }

    /** Benih untuk satu event, atau null bila event belum pernah dikocok. */
    public function benihTersimpan(SchoolEvent $event): ?ExamEventShuffle
    {
        return ExamEventShuffle::query()
            ->where('event_id', $event->id)
            ->first();
    }

    /** Buat benih acak 6 karakter dari HURUF_BENIH. */
    public function benihBaru(): string
    {
        $benih = '';

        for ($i = 0; $i < 6; $i++) {
            $benih .= self::HURUF_BENIH[random_int(0, strlen(self::HURUF_BENIH) - 1)];
        }

        return $benih;
    }

    /**
     * Sesi utama event, urut waktu (sesi susulan dikecualikan agar penempatannya tidak tertimpa).
     *
     * @return Collection<int, ExamSession>
     */
    public function sesiEvent(SchoolEvent $event): Collection
    {
        $idUjian = Exam::query()->where('event_id', $event->id)->pluck('id');

        return ExamSession::query()
            ->whereIn('exam_id', $idUjian)
            ->where('jenis', '!=', 'susulan')
            ->with('exam:id,code,title,subject_id,event_id', 'exam.subject:id,name')
            ->orderBy('access_opens_at')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, EventRoom> */
    public function ruanganEvent(SchoolEvent $event): Collection
    {
        return EventRoom::query()
            ->where('event_id', $event->id)
            ->where('status', 'aktif')
            // Kolom capacity, device_count, dan khusus_perangkat wajib dimuat.
            ->with('room:id,code,name,capacity,device_count,khusus_perangkat')
            ->orderBy('id')
            ->get();
    }

    private function kodeRuangEvent(SchoolEvent $event, int $idRuang): string
    {
        return $this->ruanganEvent($event)->firstWhere('id', $idRuang)?->room?->code ?? "R-#{$idRuang}";
    }

    /**
     * Acak array dengan Fisher-Yates memakai mt_rand berbenih agar urutan stabil antar versi PHP.
     *
     * @param  array<int, mixed>  $isi
     */
    private function acakSendiri(array &$isi): void
    {
        for ($i = count($isi) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$isi[$i], $isi[$j]] = [$isi[$j], $isi[$i]];
        }
    }

    private function benihKeAngka(string $benih): int
    {
        // crc32 cukup karena benih bukan rahasia, hanya perlu deterministik.
        return crc32($benih);
    }
}

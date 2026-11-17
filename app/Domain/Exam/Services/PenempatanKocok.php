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
 * Kocokan penempatan peserta ke ruang - PRD §9.5, wireframe plate 16b.
 *
 * ---------------------------------------------------------------------
 * Rotasi antar kelas, BUKAN kocok rata
 * ---------------------------------------------------------------------
 * Yang diminta adalah ujian campuran: satu ruang berisi siswa dari beberapa
 * tingkatan. Kocok rata TIDAK menjamin itu. Kocok rata hanya membuatnya
 * MUNGKIN - dan pada sekolah dengan tiga kelas XII yang besar, kemungkinan
 * seisi satu ruang berisi satu kelas itu nyata. Yang paling buruk: tidak ada
 * satu pun angka di layar yang bisa membuktikan sebaliknya.
 *
 * Rotasi membuatnya menjadi aturan: peserta diambil bergiliran dari tiap kelas
 * - satu dari X-1, satu dari X-2, satu dari XI-1, dan seterusnya, lalu ulang -
 * sehingga setiap ruang pasti memuat campuran. Kelas yang pesertanya lebih
 * banyak habis belakangan, bukan lebih dulu.
 *
 * Cara yang tampak sederhana - habiskan kelas satu per satu - justru
 * menghasilkan yang sebaliknya: kelas pertama mengisi R-01 sampai penuh,
 * sehingga R-01 seisi satu kelas.
 *
 * ---------------------------------------------------------------------
 * Dan cara sederhana itu menjadi BENAR di mode `kelas`
 * ---------------------------------------------------------------------
 * Rotasi di atas adalah aturan untuk mode `campur`. Mode `kelas` meminta
 * kebalikannya, dan permintaannya sah: sebagian sekolah memang menugaskan
 * pengawas per kelas, mencetak satu lembar absen per kelas, dan mengumumkan
 * "kelas 9K di R-01" dalam satu kalimat. Karena itu rotasinya dipilih oleh
 * `antrean()`, bukan dipaksakan di sini.
 *
 * ---------------------------------------------------------------------
 * Satu peta untuk SELURUH event, bukan satu peta per sesi
 * ---------------------------------------------------------------------
 * Keputusan ini dibalik pada 07 Nov 2026, dan yang membalikkannya adalah
 * pertanyaan yang dijawab Admin di depan pintu: "anak saya di ruang berapa?".
 *
 * Dengan peta per sesi, jawabannya berbeda untuk setiap mapel - R-03 pada
 * Matematika, R-07 pada Bahasa Indonesia - dan siswa yang menghafal satu ruang
 * menemukan dirinya di ruang lain pada ujian berikutnya. Sekolah yang memakai
 * penempatan campuran memang menghendaki satu peta untuk seluruh ujian: satu
 * pengumuman, satu daftar ditempel, berlaku sampai selesai.
 *
 * ---------------------------------------------------------------------
 * Peta event disimpan sebagai RUANG EVENT, bukan ruang sesi
 * ---------------------------------------------------------------------
 * `exam_participants.exam_session_room_id` menunjuk baris `exam_session_rooms`,
 * dan baris itu milik SATU sesi: "R-01 pada Matematika sesi 1" dan "R-01 pada
 * Bahasa Indonesia sesi 1" adalah dua baris yang berbeda dengan id berbeda.
 *
 * Karena itu peta yang dihitung di sini berhenti di tingkat ruang EVENT
 * (`event_room_id`), yang berlaku untuk seluruh event. Penerjemahannya ke ruang
 * SESI dilakukan per sesi oleh `petaSesi()`, dan penulisannya tetap di
 * `PenempatanService` - satu-satunya penulis `exam_session_room_id`.
 *
 * Konsekuensinya harus disebut terang-terangan: seluruh ruang event harus
 * dipakai SETIAP sesi. Itu sudah menjadi aturan di laman jadwal (sesi memilih
 * ruang dari daftar ruang event), sehingga sesi yang ruangnya kurang adalah
 * keadaan yang perlu diperbaiki - dan `petaSesi()` mengatakannya tentang sesi
 * itu, bukan menambalnya diam-diam dengan kursi yang salah.
 *
 * ---------------------------------------------------------------------
 * Kocokan berbenih
 * ---------------------------------------------------------------------
 * `mt_srand` dengan benih dari `seed`, sehingga urutan yang sama bisa
 * dihasilkan ulang kapan saja. Tanpa ini, membuka halaman penempatan dua kali
 * menghasilkan dua daftar ruang yang berbeda - dan daftar ruang yang dicetak
 * lalu ditempel di pintu tidak akan cocok dengan layar.
 *
 * `mt_srand` dipakai, bukan `shuffle()` biasa, karena ia menerima benih dan
 * PHP menjamin urutannya stabil untuk benih yang sama pada versi yang sama.
 */
class PenempatanKocok
{
    /** Huruf yang dipakai benih - tanpa I, O, 0, dan 1. */
    private const HURUF_BENIH = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly PenempatanService $penempatan,
    ) {}

    /**
     * Kelompokkan peserta EVENT menurut kelasnya.
     *
     * Pesertanya diambil dari `event_participants`, bukan dari
     * `exam_participants`, dan itu mengikuti keputusan "satu peta per event":
     * yang ditempatkan adalah SELURUH peserta ujian, sehingga siswa yang hanya
     * ikut satu mapel pun sudah punya kursi sejak mapel pertama.
     *
     * Kelas null - peserta manual yang belum punya kelas - mendapat kelompok
     * sendiri, dan kelompok itu dirotasi seperti kelas lain. Membuangnya
     * berarti siswa pindahan tidak akan pernah punya ruang; menggabungkannya
     * ke sembarang kelas berarti laporan "seisi satu kelas" berbohong tentang
     * dirinya sendiri.
     *
     * @return array<int, array{kelas: ?int, nama: string, peserta: array<int, EventParticipant>}>
     */
    public function kelompokKelas(SchoolEvent $event): array
    {
        $peserta = EventParticipant::query()
            ->where('event_id', $event->id)
            ->where('status', EventParticipant::STATUS_TERDAFTAR)
            // `studentProfile` ikut dimuat karena NIS dicetak di denah, tablo,
            // dan daftar presensi Admin. Tanpa baris ini setiap peserta
            // memicu satu kueri profil sendiri saat namanya digambar.
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

        // Kelas dengan peserta TERBANYAK dirotasi lebih dulu. Urutannya
        // menentukan siapa yang kehabisan kursi ketika kursinya kurang, dan
        // yang paling adil dibuang terakhir adalah kelompok terbesar - bukan
        // kelompok yang kebetulan namanya berawalan A.
        usort($kelompok, fn (array $a, array $b) => count($b['peserta']) <=> count($a['peserta'])
            ?: strcmp($a['nama'], $b['nama']));

        return array_values($kelompok);
    }

    /**
     * Kelompok dengan isi tiap kelas DIACAK menurut benih - batas kelas tetap.
     *
     * Dipisahkan supaya mode `kelas` dan `campur` memakai pengacakan yang
     * sama: pratinjau di laman penempatan menghitung ulang dari benih yang
     * sama, dan dua jalur pengacakan akan menghasilkan dua susunan berbeda
     * dari satu benih.
     *
     * @param  array<int, array{kelas: ?int, nama: string, peserta: array<int, EventParticipant>}>  $kelompok
     * @return array<int, array{kelas: ?int, nama: string, peserta: array<int, EventParticipant>}>
     */
    private function kelompokAcak(array $kelompok, string $benih): array
    {
        mt_srand($this->benihKeAngka($benih));

        // Tiap kelompok DIACAK SENDIRI lebih dulu. Tanpa langkah ini, rotasi
        // tetap menghasilkan urutan yang sama setiap kali karena isinya
        // berurutan menurut student_id - dan "kocokan" yang hasilnya
        // deterministik tanpa benih adalah kocokan yang tidak mengocok apa pun.
        $isi = array_map(function (array $k) {
            $orang = $k['peserta'];
            $this->acakSendiri($orang);
            $k['peserta'] = $orang;

            return $k;
        }, $kelompok);

        // Benih dikembalikan ke keadaan acak. Tanpa ini, permintaan BERIKUTNYA
        // di proses yang sama - penerbitan token, kode pengawas - ikut memakai
        // urutan yang sama, dan seluruh aplikasi menjadi bisa ditebak dari satu
        // benih yang kebetulan terlihat.
        mt_srand();

        return $isi;
    }

    /**
     * Antrean DATAR hasil rotasi antar kelas - mode `campur`.
     *
     * Mode `kelas` TIDAK lewat sini: ia dibagi dari kelompoknya di `susun()`,
     * karena antrean yang sudah diratakan kehilangan batas kelas. Parameter
     * `$mode` tinggal untuk pemanggil lama yang menyebutnya; nilainya tidak
     * lagi mengubah perilaku.
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
     * Rotasi: ambil satu dari tiap kelompok, ulang sampai habis.
     *
     * Penghitungnya `$sisa`, BUKAN `$selesai` dari hasil array_shift, dan
     * itu bukan pilihan gaya. `foreach` bekerja atas SALINAN array-nya,
     * sehingga `array_shift($daftar)` di dalamnya memendekkan salinan yang
     * dibuat ulang setiap putaran - bukan sumbernya. Isi salinan itu tidak
     * pernah habis, dan putaran ini berjalan sampai memori proses uji
     * habis (536 MB, diukur) alih-alih menghasilkan urutan yang salah.
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
     * Berurutan per kelas: kelas pertama habis, lalu kelas berikutnya.
     *
     * Kelompoknya sudah diurutkan `kelompokKelas()` - yang terbanyak lebih
     * dulu - jadi hasilnya ruang pertama berisi kelas terbesar, dan itu
     * memang yang diharapkan Admin yang menyusun urutannya di laman Kelas &
     * peserta: kelas yang ia lihat paling atas mendapat ruang pertama.
     *
     * Konsekuensi yang harus disebut, bukan disembunyikan: bila satu kelas
     * lebih besar daripada satu ruang, kelas itu TERBELAH ke dua ruang - dan
     * ruang kedua berisi ekor kelas pertama beserta kelas berikutnya. Itu
     * tetap lebih benar daripada menolak seluruh penyebaran, karena sekolah
     * dengan kelas 40 dan ruang 36 memang harus memakai dua ruang.
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
     * Bagi antrean ke ruang EVENT menurut kapasitas efektif masing-masing.
     *
     * Ruang diisi sampai PENUH sebelum pindah - tidak seperti kocokan biasa,
     * pembagiannya sendiri BUKAN yang diacak. Yang diacak adalah urutan
     * orangnya; ruangnya diisi berurutan supaya setiap kursi terpakai dan
     * tidak ada ruang yang setengah kosong sementara ruang lain menolak
     * peserta.
     *
     * Dipakai mode `campur`. Mode `kelas` memakai `bagiPerKelas()`, yang
     * menghormati batas kelas - lihat docblock-nya.
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
     * Bagi per KELAS: satu ruang diisi oleh satu kelas saja - mode `kelas`.
     *
     * =====================================================================
     * Mengapa bukan `bagi()` yang berurutan
     * =====================================================================
     * `bagi()` mengisi ruang sampai penuh lalu pindah, tanpa peduli batas
     * kelas. Akibatnya kelas kecil tidak mendapat ruangnya sendiri: ia
     * menambal sisa kursi ruang sebelumnya, dan kelas berikutnya terbelah ke
     * dua ruang. Sekolah yang meminta "berurutan per kelas" justru mendapat
     * susunan yang mencampur dua kelas dalam satu ruang - kebalikan dari yang
     * ia minta, dan yang paling mahal karena lembar absennya jadi dua kelas.
     *
     * Di sini kelas baru SELALU mulai di ruang BARU yang belum terpakai.
     * Konsekuensi yang disebut apa adanya: kelas yang lebih kecil daripada
     * kapasitas ruangnya meninggalkan kursi kosong, karena kursi itu tidak
     * boleh diisi kelas lain. Kelas yang lebih BESAR daripada satu ruang tetap
     * merambat ke ruang berikutnya - itu satu-satunya cara memuatnya - dan
     * `kelasTerbelah()` yang melaporkannya.
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

        // Peserta yang tak mendapat ruang BERSIH - kelasnya kehabisan ruang
        // kosong, atau kelasnya lebih besar daripada sisa ruang yang ada.
        // Mereka ditempatkan belakangan, di sisa kursi mana pun, supaya tidak
        // ada siswa yang kehilangan kursi saat kapasitasnya sebenarnya cukup.
        $sisa = [];

        // Kelompok sudah urut besar-dulu (`kelompokKelas()`), dan urutan itu
        // penting: kelas terbesar mendapat ruang paling awal, dan kelas yang
        // harus merambat mendapat prioritas ruang berikutnya.
        foreach ($kelompok as $k) {
            $orang = array_values($k['peserta']);

            if ($orang === []) {
                continue;
            }

            // Ruang kosong pertama - inilah "satu kelas satu ruang". Kelas
            // baru tidak pernah menambal sisa kursi kelas sebelumnya.
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
                // Kelas yang lebih besar dari satu ruang merambat ke ruang
                // berikutnya - terbelah, dan itu satu-satunya cara memuatnya.
                while ($ke < $jumlahRuang && count($hasil[$idRuang[$ke]]) >= $kapasitas[$idRuang[$ke]]) {
                    $ke++;
                }

                if ($ke >= $jumlahRuang) {
                    // Ruang bersih habis; sisanya ditampung, bukan dibuang.
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
     * Tempatkan sisa peserta ke kursi kosong ruang mana pun - jalur darurat.
     *
     * Dipakai `bagiPerKelas()` hanya untuk peserta yang tak mendapat ruang
     * bersih. Ia sengaja MENGABAIKAN batas kelas: tujuan satu-satunya adalah
     * memastikan tidak ada siswa yang kehilangan kursi saat kapasitasnya
     * sebenarnya cukup. Peserta yang masih tak kebagian dibiarkan -
     * `kurang` di `susun()` yang melaporkannya.
     *
     * @param  array<int, array<int, EventParticipant>>  $hasil
     * @param  array<int, string>  $idRuang
     * @param  array<int, int>  $kapasitas
     * @param  array<int, EventParticipant>  $sisa
     */
    /**
     * Kurangi kursi ber-PC sebanyak jumlah peserta bertanda di satu ruang -
     * dipakai SETELAH pembagian selesai.
     *
     * =====================================================================
     * Mengapa memotong sesudahnya, bukan mengubah ketiga algoritma pembagian
     * =====================================================================
     * Tiga mode (`bagi`, `bagiPerKelas`, `bagiRata`) masing-masing punya
     * aturan pembagian sendiri, dan menambahkan "berapa kursi ber-PC" ke
     * ketiganya berarti tiga salinan aturan yang sama - dan tiga tempat yang
     * bisa salah. Yang jauh lebih kecil: sesudah pembagian, untuk tiap ruang,
     * tukar tempat antara peserta bertanda yang kebetulan mendarat di kursi
     * tanpa PC dan peserta tanpa tanda yang mendarat di kursi ber-PC.
     *
     * Pertukaran itu mempertahankan JUMLAH penghuni tiap ruang, sehingga
     * kapasitas yang sudah dihormati ketiga algoritma tetap terhormat.
     *
     * ---------------------------------------------------------------------
     * Bila ruangnya tidak cukup, peserta dipindah ke ruang lain yang punya
     * ---------------------------------------------------------------------
     * Lab campuran yang kursi ber-PC-nya lebih sedikit daripada peserta
     * bertanda tidak bisa menyelesaikannya sendiri; sisanya dipindahkan ke
     * ruang lain yang masih punya kursi ber-PC kosong. Itu memang yang
     * diinginkan: anak tanpa perangkat harus duduk di depan PC, dan ruangnya
     * mana ditentukan ketersediaan, bukan kelasnya.
     *
     * Yang benar-benar tidak kebagian tetap dibiarkan di tempatnya semula,
     * dan `perangkatKurang` di `susun()` melaporkannya dengan nama - bukan
     * diam-diam, karena yang menemukannya adalah anak itu pada pukul 07:30.
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

        // ---------------------------------------------------------- tukar
        // Satu putaran per ruang cukup: setiap pertukaran menempatkan satu
        // peserta bertanda ke kursi ber-PC, dan jumlah kursi ber-PC yang
        // terpakai tidak pernah berkurang oleh pertukaran itu sendiri.
        foreach (array_keys($hasil) as $id) {
            $kursiPc = $adaPc[$id] ?? 0;

            $diPc = array_slice($hasil[$id], 0, $kursiPc, true);
            $angka = array_keys($diPc);

            foreach ($angka as $pos) {
                if ($bertanda($hasil[$id][$pos])) {
                    continue;
                }

                // Cari peserta BERTANDA yang duduk di luar jatah kursi PC,
                // mulai dari yang paling depan supaya hasilnya tetap
                // deterministik.
                foreach (array_keys($hasil[$id]) as $lain) {
                    if ($lain < $kursiPc || ! $bertanda($hasil[$id][$lain])) {
                        continue;
                    }

                    [$hasil[$id][$pos], $hasil[$id][$lain]] = [$hasil[$id][$lain], $hasil[$id][$pos]];

                    continue 2;
                }
            }
        }

        // ------------------------------------------------------- pindahkan
        // Ruang yang kursi PC-nya sudah penuh oleh peserta BERTANDA tetapi
        // masih punya peserta bertanda di luar jatah: sisa itu dipindah ke
        // ruang lain yang kursi PC-nya masih kosong.
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

                    // Ruang tujuan masih punya kursi PC yang dihuni peserta
                    // TANPA tanda - yaitu kursi yang boleh ditukar.
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
                    // Tidak ada kursi PC mana pun yang tersisa untuknya.
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

        // Kunci array dirapikan supaya pemanggil berikutnya menerima daftar,
        // bukan peta posisi - `array_slice(..., true)` di atas mempertahankan
        // kunci aslinya.
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
     * Bagi MERATA: tiap kelas disebar ke seluruh ruang - mode `rata`.
     *
     * =====================================================================
     * Bedanya dengan `bagi()` yang berurutan
     * =====================================================================
     * `bagi()` mengisi ruang pertama sampai penuh lalu pindah, sehingga
     * ruang terakhir bisa tinggal separuh sementara yang pertama penuh.
     * Sekolah yang membagi pengawas menurut jumlah kursi terpakai - dan yang
     * mencetak tablo per ruang - meminta kebalikannya: setiap ruang hampir
     * sama besar.
     *
     * Di sini setiap kelas dibagi ke SEMUA ruang sekaligus: sisa baginya
     * (`intdiv`) ke setiap ruang, dan sisanya dibagikan satu-satu ke ruang
     * terdepan. Kelas 40 di empat ruang berkapasitas 10 menjadi 10-10-10-10,
     * bukan 10-10-10-10-0 atau 40-0-0-0.
     *
     * ---------------------------------------------------------------------
     * Kapasitas ruang tetap dihormati
     * ---------------------------------------------------------------------
     * Pembagian rata yang mengabaikan kapasitas akan menaruh 12 orang di ruang
     * 10 kursi. Yang dipakai karena itu adalah kuota hasil pembagian, DIPATOK
     * pada kapasitas - dan sisa yang tak kebagian mengalir ke ruang lain yang
     * masih punya tempat.
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

        // Kursor BERPUTAR yang berlanjut antar kelas, dan itu yang membuat
        // ruangnya benar-benar rata.
        //
        // Versi yang selalu mulai dari ruang pertama menumpuk setiap kelas di
        // R-01: tiga kelas berisi 10 di tiga ruang berkapasitas 10 menghasilkan
        // 10-9-9 pada satu contoh, tetapi 12-9-9 sebelum kapasitas memotongnya -
        // dan R-01 selalu yang paling penuh. Dengan kursor yang berputar,
        // kelas kedua mulai di R-02, kelas ketiga di R-03, sehingga isi ruang
        // berakhir hampir sama.
        $pos = 0;

        foreach ($kelompok as $k) {
            foreach ($k['peserta'] as $orang) {
                // Cari ruang pertama (mulai dari kursor) yang masih punya
                // kursi. Ruang yang penuh dilewati, bukan diisi melebihi
                // kapasitasnya.
                for ($t = 0; $t < $jumlahRuang; $t++) {
                    $idx = ($pos + $t) % $jumlahRuang;
                    $id = $idRuang[$idx];

                    if (count($hasil[$id]) < $kapasitas[$id]) {
                        $hasil[$id][] = $orang;
                        $pos = ($idx + 1) % $jumlahRuang;

                        continue 2;
                    }
                }

                // Seluruh ruang penuh: kursi benar-benar habis, dan `kurang`
                // di `susun()` yang melaporkannya - bukan diam-diam.
                break 2;
            }
        }

        return $hasil;
    }

    /**
     * Seluruh hasil kocokan satu EVENT, siap dipakai layar MAUPUN penyimpanan.
     *
     * Satu method karena keduanya harus sepakat: layar yang menampilkan
     * kocokan berbeda dari yang disimpan adalah layar yang berbohong, dan itu
     * bentuk kebohongan yang paling mahal - ia baru ketahuan saat siswa
     * berdiri di depan pintu ruang yang salah.
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
     * @throws DomainException Bila eventnya belum punya ruang sama sekali.
     */
    public function susun(SchoolEvent $event, ?string $benih = null, ModePenempatan $mode = ModePenempatan::Campur): array
    {
        $benih ??= $this->benihBaru();

        $ruangan = $this->ruanganEvent($event);

        if ($ruangan->isEmpty()) {
            throw new DomainException("Event {$event->name} belum punya ruang.");
        }

        $kelompok = $this->kelompokKelas($event);

        // Dua mode membagi dari KELOMPOK-nya, bukan dari antrean datar: batas
        // kelas harus diketahui saat pembagian, dan antrean yang sudah
        // diratakan kehilangan batas itu. Urutan orang di dalam tiap kelas
        // tetap diacak menurut benih yang sama dengan mode `campur`.
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

        // Peserta bertanda "butuh perangkat" harus duduk di kursi ber-PC.
        // Dipotong SESUDAH pembagian - lihat catatan `hormatiPerangkat()`
        // tentang mengapa bukan di dalam ketiga algoritmanya.
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
            // Hanya mode `kelas` yang punya temuan ini. Di `campur` DAN `rata`
            // kelas yang tersebar adalah TUJUANNYA - melaporkannya sebagai
            // temuan membalik arti seluruh laman.
            'kelas_terbelah' => $mode === ModePenempatan::Kelas
                ? $this->kelasTerbelah($perRuang, $ruangan)
                : [],
            'rincian_kelas' => $this->rincianKelas($perRuang, $ruangan),

            // Peserta bertanda "butuh perangkat" yang TIDAK mendapat kursi
            // ber-PC. Daftarnya, bukan jumlahnya: yang harus dicari panitia
            // adalah anaknya, dan "7 peserta" tidak menjawab itu.
            'perangkat_kurang' => $perangkat['kurang'],
            'kursi_berperangkat' => $ruangan->sum(fn (EventRoom $r) => $r->kursiBerPerangkat()),
            'bertanda' => $antrean->filter(fn (EventParticipant $p) => (bool) ($p->butuh_perangkat ?? false))->count(),
        ];
    }

    /**
     * Terjemahkan peta ruang EVENT menjadi peta ruang SESI, tanpa menulis apa pun.
     *
     * Dipisahkan dari penulisannya karena halaman penempatan harus bisa
     * MENGATAKAN bahwa sebuah sesi tidak bisa memakai seluruh peta - dan
     * mengatakannya tanpa menerapkannya lebih dulu. Sesi yang ruangnya kurang
     * adalah keadaan yang Admin perbaiki di laman jadwal.
     *
     * Hanya peserta ujian INI yang masuk peta. Siswa yang tidak mengerjakan
     * mapel ini tetap punya kursi yang sama di peta event, tetapi tidak
     * ditempatkan pada sesi yang bukan miliknya - dan itu memang yang
     * diinginkan: kursinya menunggu, sesinya tidak diisi orang yang tidak ikut.
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
            // `capacity` ruangnya WAJIB ikut dimuat meski yang tampak dibaca
            // hanya kode ruangnya: kapasitasEfektif() menelusuri
            // capacity_override -> eventRoom.capacity_override -> room.capacity,
            // dan kolom yang tidak dimuat bernilai null - sehingga setiap ruang
            // terbaca berkapasitas NOL, dan setiap sesi dilaporkan kelebihan
            // muatan. Jebakan yang sama sudah tercatat di PenempatanService.
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
                // Ruang event ini tidak dipakai sesi ini. Seluruh penghuninya
                // kehilangan kursi, dan itu disebut - bukan dibiarkan mencari
                // ruang lain yang kapasitasnya belum tentu ada.
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

        // Setiap peserta ujian ini harus mendapat kursi. Yang tidak mendapat
        // berarti ruangnya tidak dipakai sesi ini - dan itu keadaan yang lebih
        // buruk daripada kursi kurang, karena tidak terlihat sebagai angka.
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
     * Kocok lalu SEBAR seluruh event: satu peta, diterapkan ke setiap sesinya.
     *
     * ---------------------------------------------------------------------
     * Seluruh sesi diperiksa lebih dulu, baru satu baris pun ditulis
     * ---------------------------------------------------------------------
     * Sesi yang tidak bisa memuat seluruh ruang event membatalkan SELURUH
     * penyebaran, dengan menyebut sesi mana. Alternatifnya - menerapkan ke sesi
     * yang bisa, melewati yang tidak - menghasilkan event yang tampak selesai:
     * sebagian siswa punya ruang, sisanya menggantung tanpa penjelasan, dan
     * yang menemukannya adalah pengawas di hari-H. Prinsip itu sama dengan
     * "kursi kurang ditolak, bukan diisi sebagian" di PenempatanService.
     *
     * @return array<string, mixed>
     */
    public function sebarEvent(
        SchoolEvent $event,
        ?string $benih = null,
        ?int $olehUserId = null,
        ?ModePenempatan $mode = null,
    ): array {
        // Benih yang tidak diberikan dipakai dari kocokan yang SUDAH tersimpan
        // bila ada. Menyebar ulang event yang sudah dikunci karenanya tidak
        // memindahkan seorang pun - dan itulah yang membuat tombol Sebar aman
        // ditekan dua kali. "Kocok ulang" adalah satu-satunya pemanggil yang
        // selalu mengirim benih baru.
        //
        // Mode mengikuti aturan yang SAMA, dan itu yang membuat "Sebar ulang"
        // tidak diam-diam mengubah susunan: mode yang tidak diberikan diambil
        // dari kocokan tersimpan, bukan dari bawaan `campur`.
        $tersimpan = $this->benihTersimpan($event);

        // Penempatan yang DIKUNCI menolak seluruh penulisan ulang kursi.
        //
        // `sebarEvent()` adalah satu-satunya jalur yang dipakai "Sebar" DAN
        // "Kocok ulang", sehingga penjagaan di sini menutup keduanya sekaligus
        // - dan keduanya sama-sama menulis ulang kursi setiap siswa. Sesudah
        // daftar ruang dicetak dan ditempel di pintu, penulisan ulang itu
        // berarti daftar yang tercetak tidak berlaku lagi sementara layarnya
        // terlihat benar.
        //
        // Penjagaan di service, bukan hanya di controller: seeder, perintah
        // artisan, dan test memanggil method ini langsung, dan aturan yang
        // hanya hidup di pintu HTTP adalah aturan yang dilewati jalur lain.
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

        // Peserta setiap ujian ditarik LEBIH DULU, di sini, dan bukan di
        // pemanggilnya.
        //
        // Sampai 07 Nov 2026 penarikan itu hanya dikerjakan
        // `ExamJadwalController::sebar()` - dan laman Penempatan, yang tombolnya
        // berbunyi "Sebar peserta", tidak melakukannya. Akibatnya nyata dan
        // tampak: penyebaran dari laman Penempatan melaporkan
        // "1265 peserta disebar ke ruang seluruh event" sambil menulis NOL
        // baris `exam_participants`, karena `petaSesi()` hanya bisa memetakan
        // peserta ujian yang BARISNYA sudah ada - dan tidak ada satu pun.
        // Layar siswa lalu berbunyi "Belum ada ujian yang ditempatkan untuk
        // Anda" pada event yang jadwalnya sudah lengkap.
        //
        // Mengapa di sini, bukan di kedua controller: `sebarEvent()` adalah
        // satu-satunya jalur yang dipakai "Sebar" DAN "Kocok ulang", dan
        // `petaSesi()` tidak bisa tahu bahwa daftar peserta yang kosong itu
        // berarti "belum ditarik" atau "memang tidak ada yang ikut". Menaruh
        // penarikan di sini membuat keduanya benar sekaligus, dan tidak
        // bergantung pada pemanggil yang mengingatnya.
        foreach (Exam::query()->where('event_id', $event->id)->get() as $u) {
            $this->penempatan->tarikPeserta($u);
        }

        $rencana = [];
        $bermasalah = [];
        $perUjian = $this->sesiEvent($event)->groupBy('exam_id');

        foreach ($perUjian as $idUjian => $sesiUjian) {
            // Sesi susulan DILEWATI, bukan dihitung. Ia punya peserta yang
            // sengaja dipindahkan Admin satu per satu (siswa yang alpa di sesi
            // utama), dan peta ruang event - satu kursi per siswa untuk seluruh
            // event - tidak boleh menimpanya. Menghitungnya sebagai "sesi
            // kedua" akan memblokir seluruh penyebaran event begitu satu
            // susulan dibuat.
            $utama = $sesiUjian->reject(fn (ExamSession $s) => $s->susulan());

            // Ujian yang HANYA punya sesi susulan (belum ada sesi utama) tidak
            // punya apa pun untuk disebar; lewati tanpa menganggapnya masalah.
            if ($utama->isEmpty()) {
                continue;
            }

            // Satu baris `exam_participants` hanya bisa menunjuk SATU sesi, dan
            // peta event tidak tahu siswa mana yang duduk di sesi utama kedua.
            // Menyebarkannya ke semua sesi akan menulis kursi yang sama berulang
            // kali - yang terakhir menang, diam-diam - sehingga yang menentukan
            // siapa ada di sesi mana adalah urutan query, bukan keputusan Admin.
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
     * Ruang yang seluruh pesertanya dari satu kelas.
     *
     * Inilah satu-satunya angka yang membuktikan rotasinya bekerja, dan ia
     * hanya bisa dibaca SEBELUM disebar - sesudahnya, satu-satunya cara
     * memperbaikinya adalah mengocok ulang, dan itu membatalkan seluruh daftar
     * yang sudah tercetak.
     *
     * @param  array<int, array<int, EventParticipant>>  $perRuang
     * @param  Collection<int, EventRoom>|null  $ruangan  Untuk kode ruangnya.
     * @return array<int, string> Kunci = event_room_id, isi = kode ruang.
     */
    /**
     * Kelas yang terbelah ke lebih dari satu ruang - hanya untuk mode `kelas`.
     *
     * Inilah angka yang membuktikan mode itu bekerja, dan ia SAMA PENTINGNYA
     * dengan `seisiSatuKelas` di mode `campur`: keduanya satu-satunya cara
     * Admin tahu susunannya menyimpang dari yang ia minta, dan keduanya cuma
     * bisa dibaca SEBELUM disebar.
     *
     * Terbelah bukan kegagalan. Kelas 40 dengan ruang berkapasitas 36 memang
     * HARUS memakai dua ruang - menolaknya berarti sekolah itu tidak bisa
     * menyebar sama sekali. Yang berbahaya adalah terbelah tanpa ada yang
     * tahu, karena pengawas ruang kedua akan memegang lembar absen yang
     * setengahnya milik kelas lain.
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
     * Kelas mana di ruang mana - daftar yang dibaca Admin di laman penempatan.
     *
     * Mode `kelas` dijanjikan sebagai "satu kelas satu ruang", dan janji itu
     * tidak bisa diperiksa dari KPI angka saja: Admin yang menemukan "9K
     * terbagi ke R-01 + R-02" perlu tahu berapa yang di masing-masing ruang
     * untuk memutuskan apakah kapasitasnya perlu ditambah.
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
                // "R-01 · 36 orang" - angka per ruang, bukan hanya daftar
                // ruangnya. Daftar yang menyebut "R-01 + R-02" tanpa jumlah
                // tidak menjelaskan ke mana anak-anak itu sebenarnya.
                'ruang' => collect($k['ruang'])
                    ->map(fn (int $n, string $kode) => "{$kode} · {$n} orang")
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Ruang yang seluruh pesertanya dari satu kelas - temuan mode `campur`.
     *
     * Inilah satu-satunya angka yang membuktikan rotasinya bekerja, dan ia
     * hanya bisa dibaca SEBELUM disebar - sesudahnya, satu-satunya cara
     * memperbaikinya adalah mengocok ulang, dan itu membatalkan seluruh daftar
     * yang sudah tercetak.
     *
     * Di mode `kelas` angka ini memang SELALU tinggi - itu tujuannya - jadi
     * laman penempatan tidak menampilkannya sebagai peringatan di sana.
     * Lihat `kelasTerbelah()`, yang menggantikannya.
     *
     * @param  array<int, array<int, EventParticipant>>  $perRuang
     * @param  Collection<int, EventRoom>|null  $ruangan  Untuk kode ruangnya.
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

            // Ruang kosong dan ruang berisi satu orang tidak dihitung: keduanya
            // memang "satu kelas" secara teknis, dan melaporkannya sebagai
            // temuan berarti setiap pratinjau pada event yang belum lengkap
            // berbunyi seperti kegagalan.
            if (count($anggota) > 1 && $kelas->count() === 1) {
                $temuan[$idRuang] = $ruangan?->firstWhere('id', $idRuang)?->room?->code ?? 'R-?';
            }
        }

        return $temuan;
    }

    /**
     * Simpan hasil kocokan sebagai satu baris per EVENT - idempoten.
     *
     * Kocok ulang MENGGANTI barisnya, bukan menambah. Dua baris untuk satu
     * event berarti dua kebenaran tentang kursi yang sama.
     */
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

    /** Benih untuk satu event, atau null bila eventnya belum pernah dikocok. */
    public function benihTersimpan(SchoolEvent $event): ?ExamEventShuffle
    {
        return ExamEventShuffle::query()
            ->where('event_id', $event->id)
            ->first();
    }

    /**
     * Benih baru: enam huruf yang bisa dieja di telepon.
     *
     * Tanpa I/O/0/1 - keempatnya hilang saat dibacakan, dan Admin yang
     * melaporkan "kocokan #7K2M" lewat telepon tidak boleh membuat orang
     * mengetik benih yang berbeda.
     */
    public function benihBaru(): string
    {
        $benih = '';

        for ($i = 0; $i < 6; $i++) {
            $benih .= self::HURUF_BENIH[random_int(0, strlen(self::HURUF_BENIH) - 1)];
        }

        return $benih;
    }

    /* ------------------------------------------------------------ Pembantu */

    /**
     * Seluruh sesi ujian satu event, urut waktu.
     *
     * @return Collection<int, ExamSession>
     */
    /**
     * Sesi UTAMA ujian satu event, urut waktu.
     *
     * Sesi SUSULAN dikecualikan, dan itu wajib: peta ruang event menyusun
     * kursi untuk SELURUH peserta ujian, sementara peserta sesi susulan
     * sengaja dipindahkan Admin satu per satu ke sesi itu. Memasukkannya ke
     * daftar ini membuat laman penempatan menawarkan "Pakai penempatan" untuk
     * sesi susulan - dan menekannya akan menarik kembali siswa susulan ke
     * ruang sesi utama, membatalkan pemindahan yang baru saja dibuat.
     *
     * Ia juga akan membuat Sebar seluruh event mustahil: sesi susulan memakai
     * sebagian ruang, sehingga setiap event yang punya susulan selalu
     * dilaporkan "tidak bisa memakai peta ruang event".
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
            // `capacity` ikut dimuat meski yang tampak dibaca hanya kode ruang:
            // kapasitasEfektif() menelusuri capacity_override -> room.capacity,
            // dan kolom yang tidak dimuat bernilai null - sehingga setiap ruang
            // terbaca berkapasitas NOL. Jebakan yang sama sudah tercatat di
            // PenempatanService.
            // `device_count` WAJIB ikut, dan alasannya sama dengan `capacity`:
            // `kursiBerPerangkat()` membacanya, dan kolom yang tidak dimuat
            // bernilai null - sehingga setiap ruang terbaca TANPA PC, dan
            // seluruh peserta bertanda ditolak karena "lab tidak tersedia".
            ->with('room:id,code,name,capacity,device_count')
            ->orderBy('id')
            ->get();
    }

    private function kodeRuangEvent(SchoolEvent $event, int $idRuang): string
    {
        return $this->ruanganEvent($event)->firstWhere('id', $idRuang)?->room?->code ?? "R-#{$idRuang}";
    }

    /**
     * Acak satu array dengan Fisher-Yates memakai mt_rand berbenih.
     *
     * `shuffle()` tidak dipakai: ia memakai generator yang sama, tetapi
     * memanggilnya lewat fungsi yang tidak menjamin berapa kali ia menarik
     * angka. Fisher-Yates di sini menarik tepat n-1 angka, sehingga urutannya
     * stabil antar versi PHP - dan urutan yang bergeser diam-diam antar versi
     * adalah urutan yang membuat daftar cetak bulan lalu tidak cocok lagi.
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

    /** Benih teks -> bilangan bulat, stabil lintas pemanggilan. */
    private function benihKeAngka(string $benih): int
    {
        // crc32 cukup di sini: benihnya bukan rahasia dan bukan pengaman -
        // ia penanda "kocokan yang mana". Nilainya hanya perlu sama setiap
        // kali benih yang sama dibaca.
        return crc32($benih);
    }
}

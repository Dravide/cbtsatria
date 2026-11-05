<?php

namespace App\Domain\Event\Services;

use App\Domain\Event\Contracts\ButirKesiapanUjian;
use App\Domain\Event\Models\EventParticipant;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Question\Services\PemasanganPaket;

/**
 * Kesiapan event - PRD §6.5, wireframe plate 12.
 *
 * ---------------------------------------------------------------------
 * Tiga keadaan, bukan dua
 * ---------------------------------------------------------------------
 * Plate 12 menampilkan tiap butir dengan chip ok / ! / bad, dan tombol
 * "Tandai Ready" yang NONAKTIF. Yang mudah terlewat: butir yang belum bisa
 * diperiksa karena komponennya belum ada bukanlah butir yang gagal.
 *
 * Karena itu setiap butir punya tiga keadaan, dan perbedaannya menentukan
 * apakah tombolnya menyala:
 *
 *   ok       - syarat terpenuhi.
 *   belum    - bisa diperbaiki SEKARANG, di laman Fase 3. Ada tautan
 *              perbaikannya, dan gate MENOLAK transisi ke Siap.
 *   menunggu - bergantung pada komponen yang belum dibangun (jadwal, sesi,
 *              paket soal, token adalah Fase 4-5). Tidak ada tindakan yang
 *              bisa diambil Admin, jadi gate TIDAK menolak karenanya.
 *
 * ---------------------------------------------------------------------
 * Mengapa "menunggu" tidak menolak
 * ---------------------------------------------------------------------
 * Ini keputusan yang sengaja, dan alasannya bisa diperiksa: gate yang menolak
 * karena sesuatu yang tidak bisa dikerjakan bukan gate, melainkan dinding.
 * Kalau butir jadwal menolak transisi di Fase 3, maka TIDAK ADA event yang
 * pernah bisa menjadi Siap sepanjang Fase 3 - laman itu mati, dan bersamanya
 * seluruh siklus status yang justru sedang dibangun.
 *
 * Tetapi "tidak menolak" bukan berarti "dianggap lulus". Butir itu tetap
 * ditampilkan, tetap dihitung, dan ditulis apa adanya di barisnya:
 * "menunggu Fase 5". Admin melihat 9 dari 11 siap dengan dua butir menunggu,
 * bukan 11 dari 11 yang membuatnya mengira semuanya beres.
 *
 * Perubahan yang terjadi saat Fase 4-5 tiba adalah satu baris: butir itu
 * pindah dari 'menunggu' ke 'belum', dan gate mulai menolak karenanya. Itu
 * terjadi di berkas ini, bukan di seluruh konsol.
 */
class EventReadiness
{
    public function __construct(
        private readonly ButirKesiapanUjian $ujian,
    ) {}

    /**
     * Periksa seluruh syarat.
     *
     * @return array{
     *     butir: array<int, array{kunci: string, label: string, keadaan: string, keterangan: ?string, perbaikan: ?string}>,
     *     lulus: bool,
     *     total: int,
     *     gagal: int,
     *     menunggu: int,
     *     siap: int
     * }
     */
    public function periksa(SchoolEvent $event): array
    {
        // Empat butir terakhir datang dari modul ujian lewat kontrak. Urutan
        // tampilannya ditetapkan DI SINI, bukan di modul yang menghitungnya:
        // plate 12 membacanya berurutan, dan urutan itu urusan halamannya.
        $dariUjian = $this->ujian->butir($event);

        $butir = [
            $this->identitas($event),
            $this->tahunSemester($event),
            $this->kelas($event),
            $this->mapel($event),
            $this->peserta($event),
            $dariUjian['jadwal'],
            $this->paketSoal($event),
            $this->ruang($event),
            $dariUjian['pengawas'],
            $dariUjian['token'],
            $dariUjian['kapasitas'],
        ];

        $gagal = count(array_filter($butir, fn (array $b) => $b['keadaan'] === 'belum'));
        $menunggu = count(array_filter($butir, fn (array $b) => $b['keadaan'] === 'menunggu'));
        $siap = count(array_filter($butir, fn (array $b) => $b['keadaan'] === 'ok'));

        return [
            'butir' => $butir,
            'lulus' => $gagal === 0,
            'total' => count($butir),
            'gagal' => $gagal,
            'menunggu' => $menunggu,
            'siap' => $siap,
        ];
    }

    /**
     * Butir yang gagal, untuk pesan galat.
     *
     * @return array<int, string>
     */
    public function penghambat(SchoolEvent $event): array
    {
        return array_values(array_map(
            fn (array $b) => $b['label'],
            array_filter($this->periksa($event)['butir'], fn (array $b) => $b['keadaan'] === 'belum'),
        ));
    }

    /* ------------------------------------------------------------ Butir */

    /** @return array<string, mixed> */
    private function identitas(SchoolEvent $event): array
    {
        $kurang = [];

        if (trim((string) $event->name) === '') {
            $kurang[] = 'nama';
        }

        if (trim((string) $event->code) === '') {
            $kurang[] = 'kode';
        }

        if (trim((string) $event->event_type) === '') {
            $kurang[] = 'jenis';
        }

        // Periode harus lengkap dan berakhir SETELAH mulai. Tanggal terbalik
        // adalah salah ketik yang lolos validasi form dua tanggal terpisah,
        // dan tanpa pemeriksaan ini ia baru terlihat saat jadwal disusun.
        if ($event->start_at && $event->end_at && $event->end_at->lt($event->start_at)) {
            $kurang[] = 'tanggal selesai lebih awal dari tanggal mulai';
        }

        return $this->butir(
            'identitas',
            'Identitas event lengkap',
            $kurang,
            'Lengkapi: '.implode(', ', $kurang).'.',
            $kurang === [] ? null : "/admin/events/{$event->id}/ubah",
        );
    }

    /** @return array<string, mixed> */
    private function tahunSemester(SchoolEvent $event): array
    {
        if ($event->academic_year_id === null) {
            return $this->butir('tahun', 'Tahun ajaran dan semester tersedia', ['tahun ajaran'], 'Pilih tahun ajaran.', "/admin/events/{$event->id}/ubah");
        }

        // Semester boleh kosong pada event lintas semester (mis. try out),
        // sehingga ketiadaannya bukan pelanggaran. Yang diperiksa adalah
        // keberadaan tahun ajarannya.
        return $this->butir('tahun', 'Tahun ajaran dan semester tersedia', [], null, null, keteranganOk: $event->semester?->name ?? 'Tanpa semester tertentu');
    }

    /** @return array<string, mixed> */
    private function kelas(SchoolEvent $event): array
    {
        $jumlah = $event->classes()->count();

        return $this->butir(
            'kelas',
            'Kelas peserta ditetapkan',
            $jumlah === 0 ? ['kelas'] : [],
            'Belum ada kelas yang ikut event ini.',
            "/admin/events/{$event->id}/peserta", keteranganOk: $jumlah > 0 ? $jumlah.' kelas' : null,
        );
    }

    /** @return array<string, mixed> */
    private function mapel(SchoolEvent $event): array
    {
        $jumlah = $event->subjects()->count();

        return $this->butir(
            'mapel',
            'Mata pelajaran ditetapkan',
            $jumlah === 0 ? ['mapel'] : [],
            'Belum ada mata pelajaran di event ini.',
            "/admin/events/{$event->id}/mapel", keteranganOk: $jumlah > 0 ? $jumlah.' mapel' : null,
        );
    }

    /** @return array<string, mixed> */
    private function peserta(SchoolEvent $event): array
    {
        $aktif = $event->participants()
            ->where('status', EventParticipant::STATUS_TERDAFTAR)
            ->count();

        return $this->butir(
            'peserta',
            'Peserta terdaftar',
            $aktif === 0 ? ['peserta'] : [],
            'Belum ada peserta. Tarik dari kelas terlebih dahulu.',
            "/admin/events/{$event->id}/peserta", keteranganOk: $aktif > 0 ? number_format($aktif, 0, ',', '.').' siswa' : null,
        );
    }

    /**
     * Paket soal - tiga cabang, dan ketiganya menuntut tindakan yang berbeda.
     *
     * =====================================================================
     * Butir ini adalah penegak "ujian harus punya soal"
     * =====================================================================
     * Selama Fase 3-5 ia mengembalikan `menunggu('paket', ...)`, karena bank
     * soal belum ada dan butir yang menolak karena sesuatu yang tidak bisa
     * dikerjakan bukan gerbang melainkan dinding. Fase 4 tiba, dan yang
     * berubah adalah SATU baris di sini - bukan seluruh konsol.
     *
     * =====================================================================
     * Yang diperiksa: paket di MAPEL EVENT, bukan di tiap ujian
     * =====================================================================
     * Dan itu perbedaan yang menentukan. `exams.question_package_id` juga
     * ada, dan memeriksanya berarti event dengan tiga ujian menuntut tiga
     * paket - padahal satu mapel biasanya memakai SATU paket untuk semua
     * kelasnya, dan itulah yang dipasang Admin di plate 15.
     *
     * Paket di `event_subjects` adalah BAWAAAN: ujian yang dibuat dari mapel
     * itu mewarisinya. Ujian yang memilih paketnya sendiri tetap sah, dan
     * yang menjaganya adalah PembekuPaket saat snapshot - bukan butir ini.
     *
     * =====================================================================
     * Mengapa butirnya lewat SERVICE, bukan lewat model
     * =====================================================================
     * Modul Event tidak boleh mengimpor model Question - peta modul
     * (DomainBoundariesTest) melarangnya, dan pelarangan itu yang membuat
     * bank soal bisa diuji tanpa event. Yang dipakai adalah
     * `PemasanganPaket`, dan yang kembali darinya hanya angka dan teks:
     * jumlah butir, nama paket. Tidak ada satu pun model Question yang
     * menyeberang batas, sehingga laman ini tidak punya jalan untuk
     * menyimpan atau menghapusnya.
     *
     * @return array<string, mixed>
     */
    private function paketSoal(SchoolEvent $event): array
    {
        $mapel = $event->subjects()->get();

        if ($mapel->isEmpty()) {
            // Mapelnya belum ditetapkan, dan itu sudah dilaporkan butir
            // 'mapel' di atas. Melaporkannya dua kali membuat Admin melihat
            // dua kegagalan untuk satu sebab, dan yang pertama ia perbaiki
            // tidak membuat yang kedua hilang.
            return $this->butir(
                'paket',
                'Paket soal ditetapkan',
                [],
                null,
                null,
                keteranganOk: 'Belum ada mapel',
            );
        }

        $pemasangan = app(PemasanganPaket::class);

        $tanpaPaket = [];
        $kosong = [];

        foreach ($mapel as $baris) {
            $paketId = $baris->question_package_id === null ? null : (int) $baris->question_package_id;

            if ($paketId === null) {
                $nama = $baris->subject?->name ?? 'Mapel';
                $tanpaPaket[] = $nama;

                continue;
            }

            if (! $pemasangan->jumlahButir($paketId) > 0) {
                $kosong[] = $pemasangan->ringkasPaket([$paketId])[$paketId]['nama'] ?? 'Paket';
            }
        }

        // Dua pesan yang BERBEDA, karena keduanya menuntut tindakan yang
        // berbeda: yang pertama "pilih paketnya", yang kedua "hubungi
        // Gurunya, paketnya belum diisi". Pesan yang menyatukan keduanya
        // membuat Admin mencari paket yang sebenarnya sudah ia pilih.
        if ($tanpaPaket !== []) {
            return $this->butir(
                'paket',
                'Paket soal ditetapkan',
                $tanpaPaket,
                'Belum ada paket soal untuk: '.$this->daftar($tanpaPaket).'.',
                "/admin/events/{$event->id}/mapel",
            );
        }

        if ($kosong !== []) {
            return $this->butir(
                'paket',
                'Paket soal ditetapkan',
                $kosong,
                'Paket '.$this->daftar($kosong).' belum berisi butir soal. Minta Guru penulisnya melengkapinya, lalu tandai Siap.',
                "/admin/events/{$event->id}/mapel",
            );
        }

        $total = 0;

        foreach ($mapel as $baris) {
            $total += $pemasangan->jumlahButir((int) $baris->question_package_id);
        }

        return $this->butir(
            'paket',
            'Paket soal ditetapkan',
            [],
            null,
            null,
            keteranganOk: $total.' butir',
        );
    }

    /**
     * Daftar nama dengan pemotongan.
     *
     * Event dengan empat puluh mapel akan menghasilkan pesan sepanjang empat
     * puluh nama, dan pesan yang tidak muat di layar berhenti dibaca. Tiga
     * nama pertama menjelaskan polanya; sisanya cukup angkanya.
     *
     * @param  array<int, string>  $nama
     */
    private function daftar(array $nama): string
    {
        if (count($nama) <= 3) {
            return implode(', ', $nama);
        }

        return implode(', ', array_slice($nama, 0, 3)).' dan '.(count($nama) - 3).' lainnya';
    }

    /** @return array<string, mixed> */
    private function ruang(SchoolEvent $event): array
    {
        $ruang = $event->rooms()->count();

        return $this->butir(
            'ruang',
            'Ruang teralokasi',
            $ruang === 0 ? ['ruang'] : [],
            'Belum ada ruang yang dialokasikan ke event ini.',
            "/admin/events/{$event->id}/peserta", keteranganOk: $ruang > 0 ? $ruang.' ruang' : null,
        );
    }

    /* ----------------------------------------------------------- Pembantu */

    /**
     * Satu butir kesiapan.
     *
     * Dua keterangan, bukan satu: yang ditampilkan saat butirnya GAGAL
     * menjelaskan apa yang salah, dan yang ditampilkan saat LULUS menjelaskan
     * apa yang sudah ada ("6 kelas", "31 orang"). Keduanya ditulis di sini
     * supaya tiap butir tidak perlu cabang if sendiri - dan supaya butir yang
     * lulus tetap membawa angkanya, karena "ok" tanpa angka tidak memberi tahu
     * Admin apa pun tentang apa yang sudah ia kerjakan.
     *
     * @param  array<int, string>  $kurang
     * @return array<string, mixed>
     */
    private function butir(
        string $kunci,
        string $label,
        array $kurang,
        ?string $pesanGagal,
        ?string $perbaikan,
        ?string $keteranganOk = null,
    ): array {
        $gagal = $kurang !== [];

        return [
            'kunci' => $kunci,
            'label' => $label,
            'keadaan' => $gagal ? 'belum' : 'ok',
            'keterangan' => $gagal ? $pesanGagal : $keteranganOk,
            'perbaikan' => $gagal ? $perbaikan : null,
        ];
    }

    /** @return array<string, mixed> */
    private function menunggu(string $kunci, string $label, string $fase): array
    {
        return [
            'kunci' => $kunci,
            'label' => $label,
            'keadaan' => 'menunggu',
            'keterangan' => 'Menunggu '.$fase,
            'perbaikan' => null,
        ];
    }
}

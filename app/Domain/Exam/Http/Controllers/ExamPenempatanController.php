<?php

namespace App\Domain\Exam\Http\Controllers;

use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Event\Models\EventParticipant;
use App\Domain\Event\Models\EventRoom;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Enums\ModePenempatan;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Services\DenahRuang;
use App\Domain\Exam\Services\PapanKursi;
use App\Domain\Exam\Services\PenempatanKocok;
use App\Domain\Exam\Services\PenempatanService;
use App\Domain\Exam\Services\PetaKursiEvent;
use App\Domain\Exam\Services\TabloRuang;
use App\Domain\Tenancy\Models\School;
use App\Support\Cetak\KopSekolah;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Penempatan peserta ke ruang - wireframe plate 16b, PRD §9.5.
 *
 * ---------------------------------------------------------------------
 * Mengapa laman tersendiri, bukan satu tombol di laman jadwal
 * ---------------------------------------------------------------------
 * Sampai sekarang penyebaran peserta adalah tombol buta: Admin menekan
 * "Sebar", sistem menempatkan 240 orang, dan yang terlihat sesudahnya hanyalah
 * jumlah. Keputusan yang diambil di situ - siapa duduk di ruang mana - adalah
 * keputusan yang dicetak, ditempel di pintu, dan dibacakan pengawas, tetapi
 * tidak ada satu pun layar yang memperlihatkannya SEBELUM ia berlaku.
 *
 * Laman ini menaruh keputusan itu di depan: pratinjau lebih dulu, sebar
 * sesudahnya.
 *
 * ---------------------------------------------------------------------
 * Layarnya TIDAK membaca kursi yang tersimpan
 * ---------------------------------------------------------------------
 * Seluruh isi laman ini dibangun dengan MENJALANKAN ULANG kocokan dari
 * benihnya, bukan dengan membaca `exam_participants.exam_session_room_id`.
 * Dua alasan:
 *
 *   1. Yang ditampilkan adalah APA YANG AKAN terjadi, dan itu belum tersimpan.
 *      Membacanya dari kursi berarti laman ini hanya bisa menampilkan keadaan
 *      sesudah disebar - yaitu tepat ketika Admin sudah tidak bisa memilih.
 *
 *   2. Kocokan berbenih menghasilkan urutan yang sama persis setiap kali, jadi
 *      layar dan hasil sebar tidak bisa berselisih. Kalau keduanya dihitung
 *      dengan cara berbeda, yang salah baru ketahuan di depan pintu.
 *
 * ---------------------------------------------------------------------
 * Satu peta untuk SELURUH event - dan sesinya diperiksa satu per satu
 * ---------------------------------------------------------------------
 * Ruang siswa berlaku sepanjang event, bukan berganti tiap mapel: pertanyaan
 * "anak saya di ruang berapa?" dijawab sekali, dan jawabannya berlaku sampai
 * ujian selesai. Keputusan ini dibalik dari "per sesi" pada 07 Nov 2026 -
 * alasan lengkapnya di `PenempatanKocok`.
 *
 * Yang dibayar sebagai gantinya harus terlihat di laman ini, bukan tersembunyi:
 * satu peta event hanya bisa dipakai sesi yang memuat SELURUH ruang event. Daftar
 * sesi di laman ini karena itu bukan sekadar daftar jadwal - ia daftar
 * kelayakan, dan sesi yang tidak bisa memakai petanya disebutkan beserta
 * sebabnya sebelum tombol Sebar ditekan.
 */
class ExamPenempatanController
{
    public function index(
        Request $request,
        SchoolEvent $event,
        PenempatanKocok $kocok,
        PenempatanService $penempatan,
    ): Response {
        Gate::authorize('view', $event);

        $tersimpan = $kocok->benihTersimpan($event);
        // Sesi yang belum pernah dikocok memakai benih baru - dan itulah
        // pratinjaunya: apa yang AKAN terjadi bila Admin menekan Sebar. Benih
        // itu ditampilkan supaya Admin bisa mencatatnya, dan mengirimkannya
        // kembali saat menyebar sehingga yang tersimpan sama dengan yang
        // dilihatnya barusan.
        $benih = $tersimpan?->seed ?? $kocok->benihBaru();

        // Mode mengikuti aturan yang sama dengan benih: kocokan yang sudah
        // tersimpan memakai modenya sendiri, dan kocokan baru memakai yang
        // dipilih di layar (`?mode=`). Tanpa ini, membuka laman ini pada event
        // yang sudah disebar dengan mode `kelas` akan menampilkan pratinjau
        // `campur` - dan Admin melihat susunan yang tidak akan pernah
        // disebar.
        $mode = $tersimpan?->mode
            ?? ModePenempatan::tryFrom((string) $request->query('mode', ''))
            ?? ModePenempatan::Campur;

        $susunan = $this->cobaSusun($kocok, $event, $benih, $mode);
        $perRuang = $susunan['per_ruang'] ?? [];

        // Tidak ada `?sesi=` di laman ini, dan itu yang berubah 2026-11-09.
        //
        // Sampai 2026-11-09 pratinjaunya adalah peta SATU sesi, sehingga Admin
        // harus memilih sesi lebih dulu untuk melihat ruangnya - padahal yang
        // ditanyakannya adalah "anak saya di ruang berapa", dan jawabannya satu
        // untuk seluruh ujian. Angka yang berubah tiap sesi juga bukan angka
        // yang bisa ditempel di pintu.
        //
        // Yang tetap per sesi adalah KELAYAKAN di tabel bawah: satu peta event
        // hanya bisa dipakai sesi yang memuat seluruh ruang event. Itu memang
        // pertanyaan yang berbeda dari "siapa duduk di mana", dan karena itu
        // tetap dijawabkan per sesi.
        $sesi = $kocok->sesiEvent($event);

        $baris = $this->daftarSesi($kocok, $event, $sesi, $perRuang, $susunan['pesan'] ?? null);

        return Inertia::render('Admin/Event/Penempatan', [
            'event' => [
                'id' => $event->id,
                'kode' => $event->code,
                'nama' => $event->name,
                'status' => $event->status->value,
                'status_label' => $event->status->label(),
            ],
            'laman' => [
                'benih' => $benih,
                'mode' => $mode->value,
                'mode_label' => $mode->label(),
                'mode_pilihan' => ModePenempatan::options(),
                'terkunci' => $tersimpan !== null,
                'dikocok_pada' => $tersimpan?->shuffled_at?->format('d M Y H:i'),
                'dikocok_oleh' => $tersimpan?->oleh?->name,
                'peserta' => $susunan['peserta'] ?? 0,
                'kapasitas' => $susunan['kapasitas'] ?? 0,
                'kurang' => $susunan['kurang'] ?? 0,
                'ruang' => count($perRuang),
                'seisi_satu_kelas' => count($susunan['seisi_satu_kelas'] ?? []),
                // Hanya mode `kelas` yang punya temuan ini: di mode `campur`
                // kelas yang terbagi ke beberapa ruang adalah KEADAAN YANG
                // DIINGINKAN, dan melaporkannya sebagai temuan akan membalik
                // arti seluruh laman.
                'kelas_terbelah' => $susunan['kelas_terbelah'] ?? [],
                'rincian_kelas' => $susunan['rincian_kelas'] ?? [],
                'pesan' => $susunan['pesan'] ?? null,
                'bisa_sebar' => $this->bisaSebar($susunan, $baris),
            ],
            'baris' => $baris,
            // Alamat kedua laman turunan, DIKIRIM server dan tidak disusun
            // peramban. Ketiganya memuat `event->id`, dan menyusunnya di dua
            // tempat berarti dua alamat yang bisa berbeda bila bentuk
            // rutenya berubah - lihat catatan yang sama di RoomController.
            'denah_url' => "/admin/events/{$event->id}/penempatan/denah",
            'tablo_url' => "/admin/events/{$event->id}/penempatan/tablo",
            'pratinjau' => $this->pratinjau($kocok, $event, $perRuang, $benih),
            'angka' => [
                'peserta_event' => $event->participants()
                    ->where('status', EventParticipant::STATUS_TERDAFTAR)->count(),
                'belum_ditempatkan' => $penempatan->belumDitempatkan($event),
                'sesi' => $sesi->count(),
                'bisa' => count(array_filter($baris, fn (array $b) => $b['bisa'])),
            ],
        ]);
    }

    /**
     * Sebarkan seluruh peserta event ke ruang, lalu terapkan ke setiap sesinya.
     *
     * Benihnya bisa DIBERIKAN Admin - itulah yang membuat kocokan bisa diulang:
     * benih yang sama menghasilkan kursi yang sama, jadi Admin yang sudah
     * mencetak daftarnya tidak perlu mencetak ulang hanya karena halamannya
     * dibuka lagi.
     */
    public function sebar(
        Request $request,
        SchoolEvent $event,
        PenempatanKocok $kocok,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $event);

        $data = $request->validate([
            'benih' => ['nullable', 'string', 'max:32'],
            'mode' => ['nullable', 'string', 'in:'.implode(',', array_column(ModePenempatan::cases(), 'value'))],
        ]);

        try {
            $hasil = $kocok->sebarEvent(
                $event,
                // Benih dari layar, apa adanya. `null` diteruskan sebagai
                // `null` supaya service memakai benih yang sudah tersimpan -
                // bukan supaya ia membuat benih baru.
                $data['benih'] ?? null,
                (int) $request->user()->id,
                // Sama untuk mode: `null` berarti "pakai mode kocokan yang
                // sudah tersimpan". Mengirim bawaan di sini akan mengubah
                // susunan diam-diam setiap kali Admin menekan Sebar ulang.
                isset($data['mode']) ? ModePenempatan::from($data['mode']) : null,
            );
        } catch (DomainException $e) {
            return back()->with('galat', $e->getMessage());
        }

        $logger->log('Menyebar peserta ke ruang', $event, [
            'peserta' => $hasil['peserta'],
            'sesi' => $hasil['sesi'],
            'benih' => $hasil['benih'],
            'mode' => $hasil['mode']->value,
        ]);

        return back()->with(
            'sukses',
            "{$hasil['peserta']} peserta disebar ke ruang seluruh event, berlaku untuk ".
            "{$hasil['sesi']} sesi (kocokan #{$hasil['benih']}, mode {$hasil['mode']->label()}).",
        );
    }

    /**
     * Kocok ulang seluruh event: benih BARU, lalu sebar.
     *
     * Aksi tersendiri, bukan efek samping "Sebar" yang ditekan dua kali. Kocok
     * ulang membatalkan seluruh daftar ruang yang sudah dicetak - untuk SETIAP
     * mapel, bukan satu sesi - dan yang membatalkannya harus berupa keputusan
     * yang terlihat, bukan akibat dari tombol yang kebetulan ditekan lagi.
     */
    public function kocokUlang(
        Request $request,
        SchoolEvent $event,
        PenempatanKocok $kocok,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $event);

        $data = $request->validate([
            'mode' => ['nullable', 'string', 'in:'.implode(',', array_column(ModePenempatan::cases(), 'value'))],
        ]);

        try {
            // Benih BARU dikirim eksplisit. Tanpa itu, methodnya akan memakai
            // benih yang sudah tersimpan - dan "kocok ulang" yang memakai benih
            // lama adalah tombol yang tidak melakukan apa pun sambil
            // menjanjikan sebaliknya.
            //
            // Mode IKUT dikirim: kocok ulang adalah satu-satunya jalan berpindah
            // mode pada event yang sudah disebar, dan tanpa ini Admin harus
            // menghapus kocokannya lebih dulu - operasi yang tidak ada di layar
            // mana pun.
            $hasil = $kocok->sebarEvent(
                $event,
                $kocok->benihBaru(),
                (int) $request->user()->id,
                isset($data['mode']) ? ModePenempatan::from($data['mode']) : null,
            );
        } catch (DomainException $e) {
            return back()->with('galat', $e->getMessage());
        }

        $logger->log('Mengocok ulang penempatan', $event, [
            'peserta' => $hasil['peserta'],
            'benih' => $hasil['benih'],
            'mode' => $hasil['mode']->value,
        ]);

        return back()->with(
            'sukses',
            "Penempatan seluruh event dikocok ulang dengan mode {$hasil['mode']->label()} ".
            "(kocokan #{$hasil['benih']}). Daftar ruang yang sudah dicetak tidak berlaku lagi.",
        );
    }

    /**
     * Terapkan peta ruang event ke SATU sesi - tombol "Pakai penempatan".
     *
     * ---------------------------------------------------------------------
     * Bukan alamat peta yang berbeda, melainkan sasaran terapan yang dipilih
     * ---------------------------------------------------------------------
     * Peta ruangnya tetap satu untuk seluruh event - itulah keputusan yang
     * dibalik pada 07 Nov 2026, dan rute ini tidak mengubahnya. `{sesi}` di
     * lintasannya adalah SASARAN: dari peta event yang sama, hanya kursi sesi
     * ini yang ditulis. Sesi yang tidak bisa memakai peta menyertai penyebaran
     * seluruh event di `sebar()`; di sini sesi yang sama bisa dipakai SATU per
     * satu, begitu keadaannya sudah diperbaiki.
     *
     * Kelayakannya diperiksa ulang di sini, tidak dipercayai dari layar:
     * tombolnya hidup untuk setiap baris (lihat Penempatan.tsx) sehingga
     * Admin bisa menekannya untuk sesi yang belum siap - dan laman harus
     * mengatakannya sebagai penolakan, bukan diam-diam menerapkan sebagian.
     * Sesi yang tidak lengkap TIDAK menulis satu kursi pun - prinsip yang sama
     * dengan `sebarEvent()`.
     */
    public function pakaiSesi(
        Request $request,
        SchoolEvent $event,
        ExamSession $sesi,
        PenempatanKocok $kocok,
        PenempatanService $penempatan,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $event);
        $this->pastikanSesiMilikEvent($sesi, $event);

        $data = $request->validate([
            'benih' => ['nullable', 'string', 'max:32'],
        ]);

        $ujian = $sesi->exam;

        if ($ujian === null) {
            abort(404, 'Ujian sesi itu tidak ditemukan.');
        }

        // Peserta ujian ditarik lebih dulu - jebakan 07 Nov 2026 yang sama
        // dengan `sebarEvent()`: `petaSesi()` hanya bisa memetakan peserta
        // yang baris `exam_participants`-nya sudah ada, dan tidak ada satu pun
        // bila ujiannya belum pernah lewat laman Jadwal.
        $penempatan->tarikPeserta($ujian);

        try {
            $susunan = $kocok->susun(
                $event,
                $data['benih'] ?? null,
                $tersimpanMode = ($kocok->benihTersimpan($event)?->mode ?? ModePenempatan::Campur),
            );
        } catch (DomainException $e) {
            return back()->with('galat', $e->getMessage());
        }

        if ($susunan['kurang'] > 0) {
            return back()->with(
                'galat',
                "Kapasitas seluruh ruang event hanya {$susunan['kapasitas']} kursi ".
                "untuk {$susunan['peserta']} peserta.",
            );
        }

        $peta = $kocok->petaSesi($event, $sesi, $susunan['per_ruang']);

        if (! $peta['lengkap']) {
            $sebab = array_merge(
                $peta['ruang_hilang'] === [] ? [] : ['ruang event tidak dipakai sesi ini: '.implode(', ', $peta['ruang_hilang'])],
                $peta['terlalu_penuh'],
                $peta['tidak_ditempatkan'],
            );

            return back()->with(
                'galat',
                "{$sesi->label()} - ".implode('; ', $sebab),
            );
        }

        try {
            $jumlah = array_sum($penempatan->sebarKeRuang($ujian, $sesi, $peta['peta']));
        } catch (DomainException $e) {
            return back()->with('galat', $e->getMessage());
        }

        $logger->log('Menerapkan peta ruang ke satu sesi', $event, [
            'sesi' => $sesi->label(),
            'ujian' => $ujian->id,
            'peserta' => $jumlah,
            'benih' => $susunan['benih'],
        ]);

        return back()->with(
            'sukses',
            "{$sesi->label()} memakai peta ruang event: {$jumlah} peserta ditempatkan ".
            "(kocokan #{$susunan['benih']}).",
        );
    }

    /* -------------------------------------------------- 16c - Denah kursi */

    /**
     * Denah tempat duduk satu RUANG, untuk SELURUH event - plate 16c.
     *
     * ---------------------------------------------------------------------
     * Yang digambar adalah peta BENIH, sama dengan pratinjau penempatan
     * ---------------------------------------------------------------------
     * Sampai 2026-11-10 laman ini menggambar
     * `exam_participants.exam_session_room_id` apa adanya. Bentuk itu salah
     * pada event yang hanya SEBAGIAN ujiannya punya sesi: penyebaran tidak
     * pernah menulis kursi untuk ujian yang belum dijadwalkan, sehingga ruang
     * berkapasitas 39 terbaca "terisi 17" dan denahnya hanya memuat satu
     * tingkatan. Laman Penempatan, yang menghitung ulang dari benih, menyebut
     * angka yang berbeda di sebelahnya.
     *
     * Sekarang keduanya membaca peta yang sama: benih di `exam_event_shuffles`
     * diterjemahkan `PetaKursiEvent` menjadi penghuni tiap ruang event. Denah
     * karena itu selalu sama dengan pratinjau, dan tidak menunggu penyebaran
     * untuk ujian yang belum punya sesi.
     *
     * Konsekuensinya dinyatakan di laman: selama penyebaran belum selesai,
     * kursi di denah bisa berbeda dari baris yang tersimpan. Yang digambar
     * adalah susunan yang AKAN berlaku - dan itulah yang ditempel di meja.
     *
     * ---------------------------------------------------------------------
     * Tidak ada pemilih sesi, dan itu yang berubah 2026-11-09
     * ---------------------------------------------------------------------
     * Sampai 2026-11-09 laman ini adalah laman SESI: pemilih sesi di bar
     * atas, daftar ruang dari satu sesi, dan cacah terisinya dihitung dari
     * peserta ujian itu. Bentuk itu menomori kursi per mapel - dan yang
     * dilaporkan Admin adalah keadaannya sendiri: ruang yang sama terbaca
     * "terisi 11" di sini sementara peta event menyebutnya penuh.
     *
     * Meja di ruang tidak disusun ulang antar sesi, jadi yang digambar di
     * sini adalah ruang EVENT beserta seluruh penghuninya. Karena itu bar
     * atasnya kini hanya pemilih RUANG: sesi yang bisa dipilih hanyalah
     * pilihan atas nama ujian, dan nama ujian tidak mengubah satu pun kotak
     * di gambar ini.
     */
    public function denah(
        Request $request,
        SchoolEvent $event,
        PapanKursi $papan,
        PenempatanKocok $kocok,
        PetaKursiEvent $peta,
    ): Response {
        Gate::authorize('view', $event);

        $ruang = $kocok->ruanganEvent($event);
        $pilih = $this->ruangEventDiminta($request, $ruang);

        // Satu kueri untuk pengisian SELURUH ruang, bukan satu per ruang.
        // Baris atas pemilih ruang menampilkan "38/40" untuk setiap ruang, dan
        // menghitungnya satu per satu membuat laman ini tumbuh linier terhadap
        // jumlah ruang - persis N+1 yang dijaga `PemantauanQueryTest` di papan
        // sebelah, dan tidak ada alasan laman ini berbeda.
        $terisi = $peta->terisiPerRuang($event);

        return Inertia::render('Admin/Event/Denah', [
            'event' => $this->kepalaEvent($event),
            'tablo_url' => "/admin/events/{$event->id}/penempatan/tablo",
            'denah_url' => "/admin/events/{$event->id}/penempatan/denah",
            // Tautan cetak selalu ada, termasuk saat belum ada ruang yang
            // dipilih: `?ruang=` dirapatkan ke PDF yang sama, sehingga bar
            // atas tidak perlu dua tombol untuk dua keadaan.
            'pdf_url' => "/admin/events/{$event->id}/penempatan/denah/pdf",
            // Daftar ruangnya dikirim walau ruangnya sudah dipilih: pemilih
            // ruang di bar atas harus bisa menampilkan SELURUH ruang event,
            // dan ruang yang tidak dipilih tetap perlu terbaca namanya.
            'daftar_ruang' => $ruang->map(fn (EventRoom $r) => [
                'id' => (int) $r->id,
                'kode' => $r->room?->code ?? "R-{$r->id}",
                'nama' => $r->room?->name,
                'terisi' => (int) ($terisi[$r->id] ?? 0),
                'kapasitas' => $r->kapasitasEfektif(),
            ])->values()->all(),
            'ruang' => $pilih === null ? null : $papan->susunEvent($pilih),
        ]);
    }

    /* --------------------------------------- 16c-kertas - Denah (PDF) */

    /**
     * Denah tempat duduk sebagai PDF - lembar yang ditempel di meja.
     *
     * Sumber datanya SAMA dengan `denah()`: `PapanKursi::susunEvent()`, yang
     * menyusun penghuni ruang dari benih tersimpan lewat `PetaKursiEvent`.
     * Karena itu nomor kursi di kertas ini mustahil berbeda dari yang di
     * layar - dan lembar yang menyusun ulang mejanya sendiri adalah lembar
     * yang menaruh anak di kursi orang lain.
     *
     * `?ruang=` memilih satu ruang; tanpa itu seluruh ruang event dicetak,
     * pola yang sama dengan tablo. Satu rute untuk keduanya karena yang
     * membedakan hanya berapa lembar yang keluar dari printer.
     */
    public function denahPdf(
        Request $request,
        SchoolEvent $event,
        PapanKursi $papan,
        DenahRuang $denah,
        PenempatanKocok $kocok,
        ActivityLogger $logger,
    ): HttpResponse {
        Gate::authorize('view', $event);

        $ruang = $kocok->ruanganEvent($event);
        $pilih = $this->ruangEventDiminta($request, $ruang);

        $dicetak = $pilih === null ? $ruang : $ruang->where('id', $pilih->id)->values();

        $sekolah = School::query()->find($event->school_id);

        $pdf = $denah->buat(
            $dicetak->map(fn (EventRoom $r) => $denah->halaman($papan->susunEvent($r))),
            KopSekolah::dari($sekolah),
        );

        $logger->log('Mencetak denah tempat duduk', $event, [
            'ruang' => $dicetak->map(fn (EventRoom $r) => $r->room?->code)->values()->all(),
        ]);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$denah->namaBerkas().'"',
        ]);
    }

    /* ------------------------------------------------- 16d - Tablo (PDF) */
    /**
     * Tablo - daftar hadir per ruang, dicetak sebagai PDF.
     *
     * ---------------------------------------------------------------------
     * Satu ruang lewat `?ruang=`, seluruh ruang tanpa itu
     * ---------------------------------------------------------------------
     * Yang membedakan keduanya hanya berapa lembar yang keluar dari printer:
     * pengawas ruang R-01 mencetak satu, panitia yang menyiapkan hari ujian
     * mencetak enam. Karena itu keduanya satu rute - dua rute akan berarti
     * dua tempat yang harus diperbaiki bila bentuk tabelnya berubah.
     *
     * ---------------------------------------------------------------------
     * Ruangnya ruang EVENT; `?sesi=` hanya memasok judul kepala lembar
     * ---------------------------------------------------------------------
     * Kursinya tidak berpindah antar sesi, jadi penghuni tiap lembar adalah
     * penghuni ruang eventnya - sama dengan denah dan papan pengawas. Yang
     * ditanyakan sesi adalah nama ujian dan jamnya, dan itu memang harus ada
     * di lembar yang ditandatangani: berita acara tanpa menyebut ujian mana
     * tidak bisa dipertanggungjawabkan.
     *
     * Konsekuensinya harus dibaca pencetaknya: pada event yang satu ujiannya
     * hanya diikuti sebagian kelas, lembar ini memuat nama anak yang tidak
     * ikut ujian itu. Kursinya benar; kehadirannya tidak dicentang, dan
     * kolom Tanda tangan memang dikosongkan untuk itu.
     */
    public function tablo(
        Request $request,
        SchoolEvent $event,
        PapanKursi $papan,
        TabloRuang $tablo,
        PenempatanKocok $kocok,
        ActivityLogger $logger,
    ): HttpResponse {
        Gate::authorize('view', $event);

        $sesi = $this->sesiDiminta($request, $event, $kocok);
        $ruang = $kocok->ruanganEvent($event);
        $pilih = $this->ruangEventDiminta($request, $ruang);

        $dicetak = $pilih === null ? $ruang : $ruang->where('id', $pilih->id)->values();

        $sekolah = School::query()->find($event->school_id);

        $pdf = $tablo->buat(
            $dicetak->map(fn (EventRoom $r) => $tablo->halaman($papan->susunEvent($r, $sesi))),
            KopSekolah::dari($sekolah),
        );

        $logger->log('Mencetak tablo daftar hadir', $event, [
            'ruang' => $dicetak->map(fn (EventRoom $r) => $r->room?->code)->values()->all(),
        ]);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$tablo->namaBerkas().'"',
        ]);
    }

    /* ------------------------------------------------------------ Pembantu */

    /** @return array<string, mixed> */
    private function kepalaEvent(SchoolEvent $event): array
    {
        return [
            'id' => $event->id,
            'kode' => $event->code,
            'nama' => $event->name,
            'status' => $event->status->value,
            'status_label' => $event->status->label(),
        ];
    }

    /**
     * Sesi yang sedang dilihat, dari `?sesi=` atau yang pertama.
     *
     * Pilihannya lewat query, bukan state di peramban - alasan yang sama
     * dengan laman penempatan: isinya dihitung server, dan pilihan yang hidup
     * di dua tempat adalah pilihan yang bisa menampilkan sesi A dengan isi
     * sesi B. `?sesi=` juga membuat satu ruang bisa dikirim ke orang lain
     * sebagai tautan.
     *
     * @param  Collection<int, ExamSession>  $sesi
     */
    private function sesiDipilih(Request $request, Collection $sesi): ?ExamSession
    {
        return $sesi->firstWhere('id', (int) $request->query('sesi', 0)) ?? $sesi->first();
    }

    /** Sesi yang sedang dilihat oleh tablo - bentuk yang sama, satu panggilan. */
    private function sesiDiminta(Request $request, SchoolEvent $event, PenempatanKocok $kocok): ?ExamSession
    {
        return $this->sesiDipilih($request, $kocok->sesiEvent($event));
    }

    /**
     * Ruang event yang diminta lewat `?ruang=`, atau null bila tidak ada.
     */
    private function ruangEventDiminta(Request $request, Collection $ruang): ?EventRoom
    {
        $diminta = (int) $request->query('ruang', 0);

        if ($diminta <= 0) {
            return null;
        }

        // Ruang yang diminta dan tidak ada di event ini menghasilkan null,
        // bukan 404: pemanggilnya memperlakukan null sebagai "semuanya", dan
        // itu jawaban yang benar untuk tautan lama yang ruangnya sudah
        // dihapus - jauh lebih berguna daripada halaman galat.
        return $ruang->firstWhere('id', $diminta);
    }

    /* --------------------------------------- Pembantu khusus pemanggil kocok */

    /**
     * Susun kocokan, dan ubah kegagalannya menjadi pesan - bukan 500.
     *
     * Event tanpa ruang, atau kursi yang kurang, bukan kerusakan aplikasi: itu
     * keadaan yang Admin perbaiki di laman ruang dan jadwal, dan laman ini harus
     * mengatakannya apa adanya sambil tetap bisa dibuka.
     *
     * @return array<string, mixed>
     */
    private function cobaSusun(PenempatanKocok $kocok, SchoolEvent $event, string $benih, ModePenempatan $mode): array
    {
        try {
            return $kocok->susun($event, $benih, $mode);
        } catch (DomainException $e) {
            return ['pesan' => $e->getMessage()];
        }
    }

    /**
     * Sesi harus benar-benar milik event di URL.
     *
     * Binding implisit menemukan sesi di seluruh sekolah, dan scope tenant
     * tidak menutupnya karena sekolahnya sama. Tanpa pemeriksaan ini, peta
     * event A bisa diterapkan ke sesi event B lewat URL yang diutak-atik.
     * 404, bukan 403: bagi event ini sesi itu tidak ada.
     */
    private function pastikanSesiMilikEvent(ExamSession $sesi, SchoolEvent $event): void
    {
        abort_unless($sesi->exam?->event_id === $event->id, 404);
    }

    /**
     * Daftar sesi beserta kelayakannya memakai peta ruang event.
     *
     * Inilah satu-satunya tempat "sesi ini belum bisa" dinyatakan, dan ia
     * dinyatakan SEBELUM penyebaran - karena sesudahnya, satu-satunya cara
     * memperbaikinya adalah mengocok ulang, dan itu membatalkan seluruh daftar
     * yang sudah tercetak.
     *
     * @param  Collection<int, ExamSession>  $sesi
     * @param  array<int, array<int, EventParticipant>>  $perRuang
     * @param  string|null  $pesanEvent  Sebab di tingkat EVENT - ruangnya belum ada.
     * @return array<int, array<string, mixed>>
     */
    private function daftarSesi(
        PenempatanKocok $kocok,
        SchoolEvent $event,
        Collection $sesi,
        array $perRuang,
        ?string $pesanEvent = null,
    ): array {
        $baris = [];

        foreach ($sesi as $s) {
            if ($pesanEvent !== null) {
                $baris[] = [
                    'sesi_id' => $s->id,
                    'ujian_id' => $s->exam_id,
                    'ujian' => $s->exam?->subject?->name ?? $s->exam?->title,
                    'sesi' => $s->label(),
                    'tanggal' => $s->access_opens_at?->format('d M'),
                    'jam' => $s->access_opens_at?->format('H:i'),
                    'peserta' => 0,
                    'ruang' => 0,
                    'bisa' => false,
                    'sebab' => $pesanEvent,
                ];

                continue;
            }

            $peta = $kocok->petaSesi($event, $s, $perRuang);

            $sebab = array_merge(
                $peta['ruang_hilang'] === [] ? [] : ['ruang event tidak dipakai sesi ini: '.implode(', ', $peta['ruang_hilang'])],
                $peta['terlalu_penuh'],
                $peta['tidak_ditempatkan'],
            );

            $baris[] = [
                'sesi_id' => $s->id,
                'ujian_id' => $s->exam_id,
                'ujian' => $s->exam?->subject?->name ?? $s->exam?->title,
                'sesi' => $s->label(),
                'tanggal' => $s->access_opens_at?->format('d M'),
                'jam' => $s->access_opens_at?->format('H:i'),
                'peserta' => count($peta['peta']),
                // Cacah ruangnya diambil dari peta SESI, bukan dari
                // `$s->rooms()->count()`. Keduanya sama selama ruangnya sehat,
                // tetapi berbeda tepat pada sesi yang bermasalah - dan pada sesi
                // itulah angkanya dibaca Admin. Ruang yang terbaca dari peta
                // adalah ruang yang benar-benar diisi; yang terbaca dari basis
                // data adalah ruang yang ada di jadwal, termasuk yang tidak
                // kebagian peserta.
                'ruang' => count(array_unique($peta['peta'])),
                'bisa' => $peta['lengkap'],
                'sebab' => $sebab === [] ? null : implode('; ', $sebab),
            ];
        }

        return $baris;
    }

    /**
     * Boleh disebar hanya bila petanya terbentuk DAN setiap sesi bisa memakainya.
     *
     * Keduanya diperiksa di sini supaya tombolnya mati SEBELUM ditekan, dengan
     * sebab yang sudah terbaca di daftar sesi. Tombol yang hidup lalu menolak
     * dengan pesan panjang adalah tombol yang membuat Admin menebak-nebak
     * apakah dirinya salah, atau sistemnya.
     *
     * @param  array<string, mixed>  $susunan
     * @param  array<int, array<string, mixed>>  $baris
     */
    private function bisaSebar(array $susunan, array $baris): bool
    {
        if (isset($susunan['pesan']) || ($susunan['kurang'] ?? 0) > 0) {
            return false;
        }

        return $baris !== [] && count(array_filter($baris, fn (array $b) => ! $b['bisa'])) === 0;
    }

    /**
     * Pratinjau peta ruang EVENT - satu peta, berlaku sepanjang ujian.
     *
     * =====================================================================
     * Angka `terisi` adalah penghuni RUANG EVENT - dibalik 2026-11-09
     * =====================================================================
     * Versi sebelumnya menghitung `terisi` dari peta SATU sesi, dengan alasan
     * bahwa angka itu yang cocok dengan laman denah. Alasannya masuk akal dan
     * laman ini tetap salah: yang digambar denah adalah kursi yang tidak
     * berpindah antar sesi, sehingga "penghuni di sesi ini" bukan angka yang
     * berlaku sepanjang ujian. Pada ujian yang diikuti sebagian kelas, ruang
     * berkapasitas 35 terbaca sebelas - dan sebelas orang itu dinomori 1..11
     * di layar ini, lalu berganti nomor lagi saat mapel berikutnya dibuka.
     *
     * Sejak 2026-11-09 ada SATU aturan nomor kursi untuk seluruh permukaan -
     * lihat `PetaKursiEvent`. Karena itu pratinjau ini tidak lagi memilih
     * sesi: yang ditampilkan adalah peta yang akan ditulis Sebar, dan peta
     * itu sama untuk setiap sesi.
     *
     * `terisi` dan `terisi_event` kini SELALU sama, dan keduanya tetap dikirim
     * supaya bentuk propnya tidak berubah diam-diam. Yang berbeda per sesi
     * adalah KELAYAKAN, dan itu dijawab tabel sesi - bukan di sini.
     *
     * @param  array<int, array<int, EventParticipant>>  $perRuang
     * @return array<string, mixed>|null
     */
    private function pratinjau(
        PenempatanKocok $kocok,
        SchoolEvent $event,
        array $perRuang,
        string $benih,
    ): ?array {
        if ($perRuang === []) {
            return null;
        }

        $ruangEvent = $kocok->ruanganEvent($event)->keyBy('id');

        $ruang = [];

        foreach ($perRuang as $idRuang => $anggota) {
            /** @var EventRoom|null $model */
            $model = $ruangEvent->get($idRuang);

            $ruang[] = [
                'id' => $idRuang,
                'kode' => $model?->room?->code ?? "R-#{$idRuang}",
                'nama' => $model?->room?->name,
                // Kapasitas ruang EVENT - angka yang menentukan peta ini.
                'kapasitas' => $model?->kapasitasEfektif() ?? 0,
                // Tidak ada sesi di sini, jadi tidak ada kapasitas sesi yang
                // bisa berbeda - kedua kunci tetap dikirim sebagai null/false
                // supaya bentuk propnya sama dan layarnya tidak perlu bercabang.
                'kapasitas_sesi' => null,
                'dipakai_sesi' => true,
                'terisi' => count($anggota),
                'terisi_event' => count($anggota),
                // Daftar namanya dikirim UTUH, bukan hanya cacahnya. Pratinjau
                // yang hanya menampilkan "40/40" tidak membuktikan apa pun
                // tentang campurannya - dan campuran itulah yang diminta.
                'peserta' => collect($anggota)->map(fn (EventParticipant $p) => [
                    'id' => $p->id,
                    'nis' => $p->student?->username,
                    'nama' => $p->student?->name,
                    'kelas' => $p->kelas?->name ?? '—',
                ])->all(),
            ];
        }

        return [
            // Tidak ada sesi yang dipilih, jadi tidak ada id sesi yang bisa
            // dikirim. Labelnya menyebut eventnya, karena itulah cakupan
            // peta ini.
            'sesi_id' => null,
            'label' => 'seluruh event',
            'benih' => $benih,
            'ruang' => $ruang,
        ];
    }
}

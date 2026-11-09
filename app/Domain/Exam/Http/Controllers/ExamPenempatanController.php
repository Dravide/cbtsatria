<?php

namespace App\Domain\Exam\Http\Controllers;

use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Event\Models\EventParticipant;
use App\Domain\Event\Models\EventRoom;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Exam\Services\PenempatanKocok;
use App\Domain\Exam\Services\PenempatanService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $susunan = $this->cobaSusun($kocok, $event, $benih);
        $perRuang = $susunan['per_ruang'] ?? [];

        // Sesi yang diminta lewat `?sesi=`. Pilihannya lewat query, bukan state
        // di peramban, karena isinya dihitung server - dan pilihan yang hidup di
        // dua tempat (React memilih, server menghitung) adalah pilihan yang bisa
        // menampilkan sesi A dengan isi sesi B. `?sesi=` juga membuat pratinjau
        // satu sesi bisa dikirim ke orang lain sebagai tautan, dan itu yang
        // diminta pengawas ketika ia melaporkan ruang yang salah isi.
        $diminta = (int) $request->query('sesi', 0);
        $sesi = $kocok->sesiEvent($event);

        $pilih = $sesi->firstWhere('id', $diminta) ?? $sesi->first();

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
                'terkunci' => $tersimpan !== null,
                'dikocok_pada' => $tersimpan?->shuffled_at?->format('d M Y H:i'),
                'dikocok_oleh' => $tersimpan?->oleh?->name,
                'peserta' => $susunan['peserta'] ?? 0,
                'kapasitas' => $susunan['kapasitas'] ?? 0,
                'kurang' => $susunan['kurang'] ?? 0,
                'ruang' => count($perRuang),
                'seisi_satu_kelas' => count($susunan['seisi_satu_kelas'] ?? []),
                'pesan' => $susunan['pesan'] ?? null,
                'bisa_sebar' => $this->bisaSebar($susunan, $baris),
            ],
            'baris' => $baris,
            'pratinjau' => $this->pratinjau($kocok, $event, $pilih, $perRuang, $benih),
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
        ]);

        try {
            $hasil = $kocok->sebarEvent(
                $event,
                // Benih dari layar, apa adanya. `null` diteruskan sebagai
                // `null` supaya service memakai benih yang sudah tersimpan -
                // bukan supaya ia membuat benih baru.
                $data['benih'] ?? null,
                (int) $request->user()->id,
            );
        } catch (DomainException $e) {
            return back()->with('galat', $e->getMessage());
        }

        $logger->log('Menyebar peserta ke ruang', $event, [
            'peserta' => $hasil['peserta'],
            'sesi' => $hasil['sesi'],
            'benih' => $hasil['benih'],
        ]);

        return back()->with(
            'sukses',
            "{$hasil['peserta']} peserta disebar ke ruang seluruh event, berlaku untuk ".
            "{$hasil['sesi']} sesi (kocokan #{$hasil['benih']}).",
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

        try {
            // Benih BARU dikirim eksplisit. Tanpa itu, methodnya akan memakai
            // benih yang sudah tersimpan - dan "kocok ulang" yang memakai benih
            // lama adalah tombol yang tidak melakukan apa pun sambil
            // menjanjikan sebaliknya.
            $hasil = $kocok->sebarEvent(
                $event,
                $kocok->benihBaru(),
                (int) $request->user()->id,
            );
        } catch (DomainException $e) {
            return back()->with('galat', $e->getMessage());
        }

        $logger->log('Mengocok ulang penempatan', $event, [
            'peserta' => $hasil['peserta'],
            'benih' => $hasil['benih'],
        ]);

        return back()->with(
            'sukses',
            "Penempatan seluruh event dikocok ulang (kocokan #{$hasil['benih']}). ".
            'Daftar ruang yang sudah dicetak tidak berlaku lagi.',
        );
    }

    /* ------------------------------------------------------------ Pembantu */

    /**
     * Susun kocokan, dan ubah kegagalannya menjadi pesan - bukan 500.
     *
     * Event tanpa ruang, atau kursi yang kurang, bukan kerusakan aplikasi: itu
     * keadaan yang Admin perbaiki di laman ruang dan jadwal, dan laman ini harus
     * mengatakannya apa adanya sambil tetap bisa dibuka.
     *
     * @return array<string, mixed>
     */
    private function cobaSusun(PenempatanKocok $kocok, SchoolEvent $event, string $benih): array
    {
        try {
            return $kocok->susun($event, $benih);
        } catch (DomainException $e) {
            return ['pesan' => $e->getMessage()];
        }
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
     * Pratinjau peta ruang event, ditandai dengan nama ruang SESI yang dipilih.
     *
     * Yang ditampilkan adalah peta EVENT - satu daftar, karena itulah yang
     * berlaku sepanjang ujian. Sesi yang dipilih hanya mengubah dua hal: kode
     * ruangnya (id ruang sesi bisa berbeda dari id ruang event) dan kapasitas
     * efektifnya, karena sesi boleh menimpanya. Menampilkan peta event dengan
     * kapasitas sesi adalah satu-satunya bentuk yang tidak berbohong tentang
     * keduanya sekaligus.
     *
     * @param  array<int, array<int, EventParticipant>>  $perRuang
     * @return array<string, mixed>|null
     */
    private function pratinjau(
        PenempatanKocok $kocok,
        SchoolEvent $event,
        ?ExamSession $sesi,
        array $perRuang,
        string $benih,
    ): ?array {
        if ($sesi === null || $perRuang === []) {
            return null;
        }

        $ruangEvent = $kocok->ruanganEvent($event)->keyBy('id');

        $ruangSesi = ExamSessionRoom::query()
            ->where('exam_session_id', $sesi->id)
            ->with('eventRoom.room:id,code')
            ->get()
            ->keyBy('event_room_id');

        $ruang = [];

        foreach ($perRuang as $idRuang => $anggota) {
            /** @var EventRoom|null $model */
            $model = $ruangEvent->get($idRuang);
            $sesiRoom = $ruangSesi->get($idRuang);

            $ruang[] = [
                'id' => $idRuang,
                'kode' => $model?->room?->code ?? "R-#{$idRuang}",
                'nama' => $model?->room?->name,
                // Kapasitas ruang EVENT - angka yang menentukan peta ini.
                'kapasitas' => $model?->kapasitasEfektif() ?? 0,
                // Kapasitas yang berlaku di sesi ini. Berbeda dari angka di
                // atas berarti sesi ini menimpa kapasitasnya, dan Admin perlu
                // melihat kedua angkanya untuk tahu mana yang mengikat.
                'kapasitas_sesi' => $sesiRoom?->kapasitasEfektif(),
                'dipakai_sesi' => $sesiRoom !== null,
                'terisi' => count($anggota),
                // Daftar namanya dikirim UTUH, bukan hanya cacahnya. Pratinjau
                // yang hanya menampilkan "40/40" tidak membuktikan apa pun
                // tentang campurannya - dan campuran itulah yang diminta.
                'peserta' => collect($anggota)->map(fn (EventParticipant $p) => [
                    'id' => $p->id,
                    'nis' => $p->student?->username,
                    'nama' => $p->student?->name,
                    'kelas' => $p->kelas?->name ?? '—',
                ])->values()->all(),
            ];
        }

        return [
            'sesi_id' => $sesi->id,
            'label' => $sesi->label(),
            'ujian' => $sesi->exam?->subject?->name ?? $sesi->exam?->title,
            'benih' => $benih,
            'ruang' => $ruang,
        ];
    }
}

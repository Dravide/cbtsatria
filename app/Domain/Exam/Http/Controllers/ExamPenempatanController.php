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
 * Pratinjau, penyebaran, penguncian, denah, dan tablo penempatan peserta per event.
 * Pratinjau dihitung ulang dari benih (bukan dari baris kursi tersimpan) agar layar dan hasil sebar selalu identik.
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
        // Gunakan benih tersimpan jika sudah disebar; jika belum, buat benih baru untuk pratinjau.
        $benih = $tersimpan?->seed ?? $kocok->benihBaru();

        // Mode tersimpan diprioritaskan agar pratinjau event yang sudah disebar tidak berubah oleh query ?mode=.
        $mode = $tersimpan?->mode
            ?? ModePenempatan::tryFrom((string) $request->query('mode', ''))
            ?? ModePenempatan::Campur;

        $susunan = $this->cobaSusun($kocok, $event, $benih, $mode);
        $perRuang = $susunan['per_ruang'] ?? [];

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
                'mode_keterangan' => ModePenempatan::keteranganSemua(),
                'terkunci' => $tersimpan !== null,
                // terkunci = sudah disebar (mode tetap); dikunci = penulisan ulang kursi diblokir.
                'dikunci' => $tersimpan?->terkunci() ?? false,
                'dikunci_pada' => $tersimpan?->locked_at?->format('d M Y H:i'),
                'dikunci_oleh' => $tersimpan?->pengunci?->name,
                'dikocok_pada' => $tersimpan?->shuffled_at?->format('d M Y H:i'),
                'dikocok_oleh' => $tersimpan?->oleh?->name,
                'peserta' => $susunan['peserta'] ?? 0,
                'kapasitas' => $susunan['kapasitas'] ?? 0,
                'kurang' => $susunan['kurang'] ?? 0,
                'ruang' => count($perRuang),
                'seisi_satu_kelas' => count($susunan['seisi_satu_kelas'] ?? []),
                'kelas_terbelah' => $susunan['kelas_terbelah'] ?? [],
                'rincian_kelas' => $susunan['rincian_kelas'] ?? [],

                'kursi_berperangkat' => $susunan['kursi_berperangkat'] ?? 0,
                'bertanda' => $susunan['bertanda'] ?? 0,
                'perangkat_kurang' => $susunan['perangkat_kurang'] ?? [],
                'peserta_reguler' => $susunan['peserta_reguler'] ?? ($susunan['peserta'] ?? 0),
                'kapasitas_reguler' => $susunan['kapasitas_reguler'] ?? ($susunan['kapasitas'] ?? 0),
                'kurang_reguler' => $susunan['kurang_reguler'] ?? ($susunan['kurang'] ?? 0),
                'ruang_reguler' => $susunan['ruang_reguler'] ?? count($perRuang),
                'ruang_khusus' => $susunan['ruang_khusus'] ?? 0,
                'kapasitas_khusus' => $susunan['kapasitas_khusus'] ?? 0,
                'terisi_khusus' => $susunan['terisi_khusus'] ?? 0,
                'pesan' => $susunan['pesan'] ?? null,
                'bisa_sebar' => $this->bisaSebar($susunan, $baris),
            ],
            'baris' => $baris,
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

    /** Sebarkan peserta event ke ruang dan terapkan ke seluruh sesi utama. */
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
                // Null diteruskan agar service memakai benih tersimpan.
                $data['benih'] ?? null,
                (int) $request->user()->id,
                // Null diteruskan agar service memakai mode tersimpan.
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

    /** Kocok ulang seluruh event dengan benih baru lalu sebar ke seluruh sesi utama. */
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
            // Kirim benih baru secara eksplisit agar tidak memakai benih tersimpan; mode opsional untuk ganti mode.
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
     * Kunci penempatan agar `sebar()` dan `kocokUlang()` tidak bisa menulis ulang kursi.
     * `pakaiSesi()` sengaja tetap diizinkan karena hanya menerapkan peta yang sudah dibekukan ke satu sesi.
     */
    public function kunci(
        Request $request,
        SchoolEvent $event,
        PenempatanKocok $kocok,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $event);

        $tersimpan = $kocok->benihTersimpan($event);

        if ($tersimpan === null) {
            return back()->with('galat', 'Sebar penempatan lebih dulu sebelum dikunci.');
        }

        if ($tersimpan->terkunci()) {
            return back()->with('galat', 'Penempatan ini sudah dikunci.');
        }

        $tersimpan->forceFill([
            'locked_at' => now(),
            'locked_by' => $request->user()->id,
        ])->save();

        $logger->log('Mengunci penempatan peserta', $event, ['benih' => $tersimpan->seed]);

        return back()->with(
            'sukses',
            'Penempatan dikunci. Kursi tidak bisa disebar ulang sampai kuncinya dibuka.',
        );
    }

    /** Buka kunci penempatan; alasan wajib diisi untuk jejak audit perubahan daftar cetak. */
    public function bukaKunci(
        Request $request,
        SchoolEvent $event,
        PenempatanKocok $kocok,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $event);

        $tersimpan = $kocok->benihTersimpan($event);

        if ($tersimpan === null || ! $tersimpan->terkunci()) {
            return back()->with('galat', 'Penempatan ini belum dikunci.');
        }

        $data = $request->validate([
            'alasan' => ['required', 'string', 'max:200'],
        ], [
            'alasan.required' => 'Alasan membuka kunci wajib diisi - ia dicatat di log aktivitas.',
        ]);

        $tersimpan->forceFill([
            'locked_at' => null,
            'locked_by' => null,
        ])->save();

        $logger->log('Membuka kunci penempatan peserta', $event, [
            'benih' => $tersimpan->seed,
            'alasan' => $data['alasan'],
        ]);

        return back()->with('sukses', 'Kunci penempatan dibuka. Perubahan kursi kini diizinkan lagi.');
    }

    /**
     * Terapkan peta ruang event yang sudah ada ke satu sesi setelah memvalidasi kelayakannya.
     * Kunci penempatan sengaja tidak diblokir di sini agar sesi baru atau yang ruangnya baru diperbaiki tetap bisa memakai peta terkunci.
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

        // Tarik peserta ujian lebih dulu agar petaSesi() punya baris exam_participants.
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
            return back()->with(
                'galat',
                "{$sesi->label()} - ".implode('; ', $this->sebabTidakBisa($peta)).'. '
                .'Tambahkan ruang yang kurang pada sesi itu di laman Jadwal & sesi, lalu sebar lagi.',
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

    /** Denah tempat duduk satu ruang event berdasarkan peta benih tersimpan (bukan per sesi). */
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

        // Hitung pengisian seluruh ruang dalam satu kueri agar tidak N+1.
        $terisi = $peta->terisiPerRuang($event);

        return Inertia::render('Admin/Event/Denah', [
            'event' => $this->kepalaEvent($event),
            'tablo_url' => "/admin/events/{$event->id}/penempatan/tablo",
            'daftar_peserta_url' => "/admin/events/{$event->id}/penempatan/daftar-peserta",
            'denah_url' => "/admin/events/{$event->id}/penempatan/denah",
            'pdf_url' => "/admin/events/{$event->id}/penempatan/denah/pdf",
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

    /** Cetak denah tempat duduk PDF dari sumber data yang sama dengan `denah()` (`?ruang=` untuk satu ruang, kosong untuk semua). */
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

    /**
     * Cetak tablo daftar hadir PDF per ruang event (`?ruang=` opsional untuk satu ruang).
     * `?sesi=` hanya memasok judul ujian dan jadwal pada header, bukan memfilter penghuni ruang.
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

    /**
     * Cetak daftar nama siswa PDF per ruang tanpa kolom tanda tangan (`?ruang=` opsional untuk satu ruang).
     */
    public function daftarPeserta(
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

        $pdf = $tablo->buatDaftarPeserta(
            $dicetak->map(fn (EventRoom $r) => $tablo->halaman($papan->susunEvent($r, $sesi))),
            KopSekolah::dari($sekolah),
        );

        $logger->log('Mencetak daftar nama peserta', $event, [
            'ruang' => $dicetak->map(fn (EventRoom $r) => $r->room?->code)->values()->all(),
        ]);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$tablo->namaBerkasDaftarPeserta().'"',
        ]);
    }

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
     * @param  Collection<int, ExamSession>  $sesi
     */
    private function sesiDipilih(Request $request, Collection $sesi): ?ExamSession
    {
        return $sesi->firstWhere('id', (int) $request->query('sesi', 0)) ?? $sesi->first();
    }

    private function sesiDiminta(Request $request, SchoolEvent $event, PenempatanKocok $kocok): ?ExamSession
    {
        return $this->sesiDipilih($request, $kocok->sesiEvent($event));
    }

    private function ruangEventDiminta(Request $request, Collection $ruang): ?EventRoom
    {
        $diminta = (int) $request->query('ruang', 0);

        if ($diminta <= 0) {
            return null;
        }

        // Kembalikan null (bukan 404) jika ruang tidak ditemukan agar pemanggil jatuh ke semua ruang.
        return $ruang->firstWhere('id', $diminta);
    }

    /**
     * Susun kocokan dan ubah DomainException menjadi pesan agar halaman tetap bisa dibuka.
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
     * @param  array<string, mixed>  $peta
     * @return array<int, string>
     */
    private function sebabTidakBisa(array $peta): array
    {
        return array_merge(
            ($peta['ruang_hilang'] ?? []) === []
                ? []
                : ['sesi ini belum memakai ruang '.implode(', ', $peta['ruang_hilang'])
                    .' - centang ruang itu di laman Jadwal & sesi'],
            $peta['terlalu_penuh'] ?? [],
            $peta['tidak_ditempatkan'] ?? [],
        );
    }

    /** Cegah akses lintas event dalam sekolah yang sama (404 agar tidak membocorkan eksistensi sesi). */
    private function pastikanSesiMilikEvent(ExamSession $sesi, SchoolEvent $event): void
    {
        abort_unless($sesi->exam?->event_id === $event->id, 404);
    }

    /**
     * Daftar sesi beserta kelayakannya memakai peta ruang event.
     *
     * @param  Collection<int, ExamSession>  $sesi
     * @param  array<int, array<int, EventParticipant>>  $perRuang
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

            $sebab = $this->sebabTidakBisa($peta);

            $baris[] = [
                'sesi_id' => $s->id,
                'ujian_id' => $s->exam_id,
                'ujian' => $s->exam?->subject?->name ?? $s->exam?->title,
                'sesi' => $s->label(),
                'tanggal' => $s->access_opens_at?->format('d M'),
                'jam' => $s->access_opens_at?->format('H:i'),
                'peserta' => count($peta['peta']),
                // Hitung ruang dari peta sesi (ruang yang benar-benar terisi), bukan jumlah ruang di jadwal.
                'ruang' => count(array_unique($peta['peta'])),
                'bisa' => $peta['lengkap'],
                'sebab' => $sebab === [] ? null : implode('; ', $sebab),
            ];
        }

        return $baris;
    }

    /**
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
     * Pratinjau peta ruang event; `terisi` dan `terisi_event` sama-sama memakai penghuni ruang event demi kompatibilitas prop.
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
                'kapasitas' => $model?->kapasitasEfektif() ?? 0,
                'pc' => $model?->kursiBerPerangkat() ?? 0,
                'khusus_perangkat' => $model?->isKhususPerangkat() ?? false,
                // Pertahankan kunci kapasitas_sesi dan dipakai_sesi demi bentuk prop yang seragam.
                'kapasitas_sesi' => null,
                'dipakai_sesi' => true,
                'terisi' => count($anggota),
                'terisi_event' => count($anggota),
                'peserta' => collect($anggota)->map(fn (EventParticipant $p) => [
                    'id' => $p->id,
                    'nis' => $p->student?->username,
                    'nama' => $p->student?->name,
                    'kelas' => $p->kelas?->name ?? '—',
                ])->all(),
            ];
        }

        return [
            'sesi_id' => null,
            'label' => 'seluruh event',
            'benih' => $benih,
            'ruang' => $ruang,
        ];
    }
}

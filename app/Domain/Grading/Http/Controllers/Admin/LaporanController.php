<?php

namespace App\Domain\Grading\Http\Controllers\Admin;

use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Models\Subject;
use App\Domain\Event\Models\EventClass;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Grading\Exports\LaporanData;
use App\Domain\Grading\Jobs\BuatEksporLaporan;
use App\Domain\Grading\Models\ReportExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Laporan & ekspor - wireframe-admin.html plate 22.
 *
 * =====================================================================
 * Layar dan berkas membaca sumber yang SAMA
 * =====================================================================
 * `LaporanData` menyajikan lima jenis laporan, dan laman ini tidak menulis
 * kueri sendiri. Admin yang memeriksa tabel di layar lalu mengunduh XLSX-nya
 * harus menerima baris yang sama persis - dan perbedaan sekecil apa pun di
 * antara keduanya adalah laporan yang tidak bisa dipertanggungjawabkan saat
 * angkanya dipertanyakan.
 *
 * Bedanya hanya VOLUME: layar memaginasi lima puluh baris, berkas mengambil
 * semuanya. Penyaringnya identik karena keduanya berasal dari array yang sama.
 *
 * =====================================================================
 * Tombol ekspor menulis antrean, tidak mengirim berkas
 * =====================================================================
 * Kalimat di kaki plate 22: "berkas besar: disiapkan di latar belakang".
 * `mintaEkspor()` menulis satu baris `report_exports` lalu kembali; yang
 * membuat berkasnya adalah `BuatEksporLaporan` di worker. Admin akan melihat
 * barisnya muncul dengan status "Menunggu", lalu "Siap diunduh".
 *
 * Tombol yang menunggu worker selesai sebelum menjawab akan membekukan
 * perambannya selama pekerjaan yang tidak ia butuhkan untuk membaca layar
 * sebelahnya.
 *
 * =====================================================================
 * "Event" adalah penyaring yang wajib, bukan pilihan
 * =====================================================================
 * Plate 22 menulis "Event: UAS Ganjil" di bilah atasnya, bukan di daftar
 * penyaring - karena seluruh laporan di laman ini adalah laporan SATU event.
 * Nilai sekolah lintas event adalah berkas yang tidak punya arti: kelas yang
 * sama mengerjakan ujian yang berbeda dalam event yang berbeda.
 */
class LaporanController
{
    public function index(Request $request): Response
    {
        Gate::authorize('ekspor');

        $event = $this->eventAktif();
        $jenis = $this->jenisDiminta($request);
        $parameter = $this->parameter($request);

        $kolom = LaporanData::kolom($jenis);
        $baris = $event === null
            ? collect()
            : LaporanData::ambil($jenis, $event->id, $parameter);

        return Inertia::render('Admin/Laporan', [
            'event' => $event === null ? null : [
                'id' => $event->id,
                'nama' => $event->name,
                'kode' => $event->code,
                'terbit' => $event->hasilTerbit(),
            ],
            'jenis' => $jenis,
            'daftar_jenis' => collect(LaporanData::JENIS)
                ->map(fn (string $label, string $kunci) => ['kunci' => $kunci, 'label' => $label])
                ->values()
                ->all(),
            'format' => collect(LaporanData::FORMAT)
                ->map(fn (string $label, string $kunci) => ['kunci' => $kunci, 'label' => $label])
                ->values()
                ->all(),
            'kolom' => collect($kolom)
                ->map(fn (string $label, string $kunci) => ['kunci' => $kunci, 'label' => $label])
                ->values()
                ->all(),
            // `kelas` dan `mapel` adalah dua penyaring plate 22, dan isinya
            // dibatasi pada yang benar-benar ikut event ini. Menawarkan kelas
            // yang tidak ikut berarti menawarkan penyaring yang SELALU
            // menghasilkan tabel kosong.
            'pilihan_kelas' => $this->kelasEvent($event),
            'pilihan_mapel' => $this->mapelEvent($event),
            'filter' => [
                'kelas' => $parameter['kelas'] ?? '',
                'mapel' => $parameter['mapel'] ?? '',
            ],
            'tabel' => $this->halaman($baris, $request),
            'total' => $baris->count(),
            // Riwayat ekspor ikut dikirim karena ekspor berjalan di latar
            // belakang: tanpa daftar ini Admin yang menekan tombol lalu
            // berpindah laman tidak punya cara menemukan berkasnya.
            'ekspor' => ReportExport::query()
                ->with('peminta:id,name')
                ->orderByDesc('id')
                ->limit(12)
                ->get()
                ->map(fn (ReportExport $satu) => [
                    'id' => $satu->id,
                    'jenis' => LaporanData::JENIS[$satu->jenis] ?? $satu->jenis,
                    'format' => strtoupper($satu->format),
                    'status' => $satu->status,
                    'status_label' => $satu->labelStatus(),
                    'status_nada' => $satu->nadaStatus(),
                    'total_baris' => $satu->total_baris,
                    'ukuran' => $satu->ukuran,
                    'nama_berkas' => $satu->nama_berkas,
                    'pesan_gagal' => $satu->pesan_gagal,
                    'peminta' => $satu->peminta?->name,
                    'diminta_pada' => $satu->created_at?->format('d M Y H:i'),
                    'selesai_pada' => $satu->finished_at?->format('d M Y H:i'),
                ])
                ->all(),
        ]);
    }

    /**
     * Minta satu berkas. Menulis antrean lalu kembali - tidak menunggu.
     */
    public function mintaEkspor(Request $request): RedirectResponse
    {
        Gate::authorize('ekspor');

        $data = $request->validate([
            'jenis' => ['required', 'string', 'in:'.implode(',', array_keys(LaporanData::JENIS))],
            'format' => ['required', 'string', 'in:'.implode(',', array_keys(LaporanData::FORMAT))],
            'kelas' => ['nullable', 'integer'],
            'mapel' => ['nullable', 'integer'],
        ]);

        $event = $this->eventAktif();

        if ($event === null) {
            return back()->with('galat', 'Pilih event aktif lebih dulu. Laporan selalu milik satu event.');
        }

        $ekspor = new ReportExport;
        $ekspor->forceFill([
            'school_id' => $request->user()->school_id,
            'requested_by' => $request->user()->id,
            'jenis' => $data['jenis'],
            'format' => $data['format'],
            // Penyaringnya disimpan, bukan hasilnya: ekspor yang gagal harus
            // bisa diulang dengan penyaring yang persis sama.
            'parameter_json' => [
                'event_id' => $event->id,
                'kelas' => $data['kelas'] ?? null,
                'mapel' => $data['mapel'] ?? null,
            ],
            'status' => ReportExport::STATUS_MENUNGGU,
            'created_at' => now(),
        ])->save();

        // -----------------------------------------------------------------
        // Barisnya SUDAH tersimpan saat job dilempar, dan itu bukan kebetulan
        // -----------------------------------------------------------------
        // `save()` di atas berjalan di luar transaksi, jadi baris
        // `report_exports` sudah committed sebelum `dispatch()` baris ini
        // mengeksekusi job-nya. Tidak ada balapan antara "job berjalan" dan
        // "barisnya belum ada", sehingga `->afterCommit()` TIDAK diperlukan
        // di sini - dan memasangnya justru merugikan:
        //
        // `phpunit.xml` menetapkan `QUEUE_CONNECTION=sync`, dan
        // `RefreshDatabase` membungkus setiap test dalam transaksi yang tidak
        // pernah di-commit. Dengan `afterCommit`, jobnya tidak akan pernah
        // berjalan di test - sehingga `Queue::assertPushed()` gagal bukan
        // karena ada bug, melainkan karena testnya sendiri tidak bisa
        // mengamati pekerjaan yang benar.
        //
        // SIAPA PUN yang kelak membungkus blok di atas dengan
        // `DB::transaction(...)` WAJIB menambahkan `->afterCommit()` di sini.
        // Tanpa itu, worker bisa mengambil job sebelum commit selesai dan
        // menemukan barisnya tidak ada.
        BuatEksporLaporan::dispatch($ekspor->id);

        return back()->with('sukses', 'Berkas disiapkan di latar belakang. Unduh dari daftar Ekspor terakhir begitu statusnya siap.');
    }

    /**
     * Unduh satu berkas ekspor.
     *
     * Berkasnya ada di disk privat, dan satu-satunya jalan keluarnya adalah
     * rute berwenang ini - pola `BerkasLaporanController` Fase 6. Path-nya
     * tidak pernah sampai ke peramban.
     */
    public function unduh(Request $request, ReportExport $ekspor): StreamedResponse
    {
        Gate::authorize('unduhEkspor', $ekspor);

        abort_unless($ekspor->selesai(), 404);

        $disk = Storage::disk('local');

        abort_unless($ekspor->path !== null && $disk->exists($ekspor->path), 404);

        // Nama unduhan adalah nama yang dibaca Admin, bukan nama acak di disk.
        // Nama acak ada di depan nama nyaman supaya berkas tidak bisa ditebak;
        // yang sudah diunduh tetap bernama jelas di folder unduhannya.
        $nama = preg_replace('/^[a-zA-Z0-9]{20}-/', '', (string) $ekspor->nama_berkas) ?: $ekspor->nama_berkas;

        return $disk->download($ekspor->path, $nama);
    }

    /* ------------------------------------------------------------ Pembantu */

    /**
     * Paginasi memori atas koleksi `LaporanData`.
     *
     * Bukan paginasi SQL karena `LaporanData::ambil()` adalah satu bentuk yang
     * dipakai juga oleh ekspor, yang memang butuh SEMUA baris. Memecahnya
     * menjadi dua kueri - satu berpaginasi untuk layar, satu penuh untuk
     * berkas - berarti dua tempat yang harus tetap sepakat, dan itu persis
     * yang ingin dihindari kelas itu.
     *
     * Pada skala satu angkatan (ribuan baris) memorinya murah; yang mahal
     * adalah dua laporan yang angkanya berbeda.
     *
     * @param  Collection<int, array<string, mixed>>  $baris
     * @return array<string, mixed>
     */
    private function halaman($baris, Request $request): array
    {
        $perHalaman = 50;
        $nomor = max(1, (int) $request->query('halaman', 1));
        $total = $baris->count();

        $halaman = new LengthAwarePaginator(
            $baris->forPage($nomor, $perHalaman)->values(),
            $total,
            $perHalaman,
            $nomor,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return [
            'data' => $halaman->items(),
            'dari' => $total === 0 ? 0 : $halaman->firstItem(),
            'sampai' => $halaman->lastItem() ?? 0,
            'total' => $total,
            'halaman_kini' => $halaman->currentPage(),
            'halaman_akhir' => $halaman->lastPage(),
            'tautan' => $halaman->linkCollection()->toArray(),
        ];
    }

    /** Jenis laporan yang diminta; jatuh ke tab pertama bila tidak dikenal. */
    private function jenisDiminta(Request $request): string
    {
        $jenis = (string) $request->query('jenis', 'nilai');

        return array_key_exists($jenis, LaporanData::JENIS) ? $jenis : 'nilai';
    }

    /**
     * @return array{kelas?: string|null, mapel?: string|null}
     */
    private function parameter(Request $request): array
    {
        return [
            'kelas' => $request->query('kelas') ?: null,
            'mapel' => $request->query('mapel') ?: null,
        ];
    }

    /**
     * Event yang sedang aktif di sesi.
     *
     * Laporan adalah laporan SATU event, jadi tidak ada menu turun "semua
     * event" di sini - persis seperti bilah atas plate 22 yang menulis
     * "Event: UAS Ganjil" sebagai keterangan, bukan sebagai pilihan.
     */
    private function eventAktif(): ?SchoolEvent
    {
        $id = session('event_aktif');

        return $id === null ? null : SchoolEvent::query()->find($id);
    }

    /**
     * @return array<int, array{id: string, label: string}>
     */
    private function kelasEvent(?SchoolEvent $event): array
    {
        if ($event === null) {
            return [];
        }

        return SchoolClass::query()
            ->whereIn('id', EventClass::query()->where('event_id', $event->id)->select('class_id'))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (SchoolClass $k) => ['id' => (string) $k->id, 'label' => $k->name])
            ->all();
    }

    /**
     * @return array<int, array{id: string, label: string}>
     */
    private function mapelEvent(?SchoolEvent $event): array
    {
        if ($event === null) {
            return [];
        }

        return Subject::query()
            ->whereIn('id', $event->exams()->select('subject_id'))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Subject $m) => ['id' => (string) $m->id, 'label' => $m->name])
            ->all();
    }
}

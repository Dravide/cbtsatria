<?php

namespace App\Domain\Admin\Http\Controllers;

use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Facility\Models\Room;
use App\Domain\Facility\Services\KartuPintu;
use App\Domain\Facility\Services\RoomEntryLinkService;
use App\Domain\Tenancy\Models\School;
use App\Support\Cetak\KopSekolah;
use App\Support\TabelQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ruang ujian - wireframe plate 09, dan laman rinciannya.
 *
 * ---------------------------------------------------------------------
 * Mengapa laman rincian ruang ada di Fase 2, bukan Fase 6
 * ---------------------------------------------------------------------
 * Yang dipakai pengawas baru di Fase 6, tetapi yang MEMBUATNYA adalah Admin,
 * dan ia membuatnya jauh sebelum hari ujian - saat menyiapkan kartu pintu.
 * Menunda laman ini berarti menunda satu-satunya tempat tautan ruang bisa
 * diterbitkan, dan itu berarti Fase 6 tidak punya apa pun untuk dikonsumsi.
 *
 * Datanya sudah siap sejak sekarang: `room_entry_links` dibangun bersama
 * laman ini. Yang belum ada hanyalah sisi pengawasnya.
 *
 * ---------------------------------------------------------------------
 * Kode ditampilkan sekali, lewat flash
 * ---------------------------------------------------------------------
 * Service mengembalikan kode sebagai nilai kembalian - bukan menyimpannya.
 * Respons yang menerbitkan mengirimkannya lewat flash session, dan sesudah
 * itu tidak ada lagi. Muat ulang kehilangan kodenya.
 *
 * Itu memang tidak nyaman, dan justru itu yang membuatnya benar: kode yang
 * bisa dibaca kembali berarti kredensial yang bisa dibaca siapa pun yang
 * mendapat akses ke tabelnya. Yang hilang harus diterbitkan ulang - dan
 * penerbitan ulang otomatis mencabut yang lama, sehingga kartu pintu yang
 * beredar tidak menumpuk tanpa batas.
 */
class RoomController
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Room::class);

        $halaman = TabelQuery::buat(
            Room::query(),
            $request,
            cariDi: ['code', 'name', 'location'],
            bolehDiurut: ['code', 'name', 'capacity'],
            penyaring: ['status' => 'status'],
            urutBawaan: 'code',
        );

        // Ringkasan dihitung dari SELURUH ruang, bukan dari halaman ini.
        // Angka ringkasan yang berubah saat berpindah halaman bukan ringkasan.
        $semua = Room::query()->get(['capacity', 'device_count', 'status']);

        return Inertia::render('Admin/Ruang', [
            'baris' => $halaman->through(fn (Room $ruang) => [
                'id' => $ruang->id,
                'kode' => $ruang->code,
                'nama' => $ruang->name,
                'lokasi' => $ruang->location,
                'kapasitas' => $ruang->capacity,
                'pc' => $ruang->device_count,
                'byod' => $ruang->isByod(),
                'status' => $ruang->status,
            ]),
            'filter' => [
                'cari' => $request->query('cari', ''),
                'status' => $request->query('status', ''),
            ],
            'ringkasan' => [
                'kapasitas' => (int) $semua->sum('capacity'),
                'pc' => (int) $semua->sum('device_count'),
                'byod' => $semua->filter(fn (Room $r) => $r->isByod())->count(),
            ],
            'jumlah' => $halaman->total(),
        ]);
    }

    public function store(Request $request, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('create', Room::class);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'name' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'capacity' => ['required', 'integer', 'min:0', 'max:1000'],
            'device_count' => ['required', 'integer', 'min:0', 'max:1000'],
            'status' => ['required', 'in:aktif,perbaikan,nonaktif'],
        ], [], ['code' => 'kode ruang', 'device_count' => 'jumlah PC']);

        // PC lebih banyak daripada kapasitas hampir selalu salah ketik, dan
        // salah ketik di sini muncul sebagai ruang yang "cukup" pada
        // pemeriksaan kesiapan padahal kursinya tidak ada.
        if ($data['device_count'] > $data['capacity']) {
            return back()->withErrors([
                'device_count' => 'Jumlah PC tidak boleh melebihi kapasitas ruang.',
            ]);
        }

        $ruang = Room::create($data + ['school_id' => $request->user()->school_id]);

        $logger->log('Menambah ruang', $ruang, ['kode' => $ruang->code]);

        return back()->with('sukses', 'Ruang '.$ruang->code.' ditambahkan.');
    }

    public function update(Request $request, Room $ruang, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('update', $ruang);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'name' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'capacity' => ['required', 'integer', 'min:0', 'max:1000'],
            'device_count' => ['required', 'integer', 'min:0', 'max:1000'],
            'status' => ['required', 'in:aktif,perbaikan,nonaktif'],
        ]);

        if ($data['device_count'] > $data['capacity']) {
            return back()->withErrors([
                'device_count' => 'Jumlah PC tidak boleh melebihi kapasitas ruang.',
            ]);
        }

        $ruang->update($data);

        $logger->log('Memperbarui ruang', $ruang, ['kode' => $ruang->code]);

        return back()->with('sukses', 'Ruang tersimpan.');
    }

    /**
     * Setel kapasitas SELURUH ruang terpilih ke satu angka.
     *
     * ---------------------------------------------------------------------
     * Mengapa satu angka, bukan selisih
     * ---------------------------------------------------------------------
     * Yang dikerjakan Admin saat menyiapkan hari ujian adalah menyeragamkan:
     * "tahun ini setiap ruang memuat 35 orang, bukan 32". Selisih tambah/kurang
     * menuntut Admin menghitung sendiri angka akhirnya untuk tiap ruang, dan
     * justru pada ruang yang kapasitasnya BEDA ia salah hitung - padahal ruang
     * yang kapasitasnya beda itulah alasan ia membuka panel ini.
     *
     * ---------------------------------------------------------------------
     * Jumlah PC ikut turun, dan itu keputusan yang diambil sadar
     * ---------------------------------------------------------------------
     * `store()` dan `update()` MENOLAK ruang yang jumlah PC-nya melebihi
     * kapasitasnya, karena PC yang tidak punya kursi adalah salah ketik yang
     * membuat ruang terbaca "cukup" pada pemeriksaan kesiapan. Operasi massal
     * ini melakukan hal yang sama, tetapi dengan akibat yang lebih jauh: pada
     * dua puluh ruang, satu penolakan berarti Admin memperbaiki satu per satu.
     *
     * Karena itu jumlah PC-nya DITURUNKAN agar tidak melebihi kapasitas baru.
     * Yang dikorbankan bisa dibaca dari pesannya - "3 ruang: jumlah PC
     * diturunkan" - sehingga Admin yang tidak menyangka ada ruang ber-PC 40
     * tahu apa yang berubah, alih-alih menemukannya di pemeriksaan kesiapan
     * besok pagi. Ruang BYOD tidak tersentuh: 0 tidak pernah melebihi apa pun.
     */
    public function kapasitasMassal(Request $request, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('kapasitasMassal', Room::class);

        $data = $request->validate([
            'id' => ['required', 'array'],
            'id.*' => ['integer'],
            'capacity' => ['required', 'integer', 'min:0', 'max:1000'],
        ], [], ['id' => 'daftar ruang', 'capacity' => 'kapasitas baru']);

        // Ruang yang dikirim harus benar-benar milik sekolah ini. Scope tenant
        // TIDAK menolongnya: id dari sekolah lain tetap tersaring keluar oleh
        // SchoolScope, tetapi ruang sekolah yang sama dari halaman lain akan
        // lolos - dan itulah yang harus dicegah daftar ini.
        $ruang = Room::query()
            ->whereIn('id', $data['id'])
            ->get();

        if ($ruang->isEmpty()) {
            return back()->with('galat', 'Tidak ada ruang yang bisa diubah.');
        }

        $kapasitas = $data['capacity'];
        $diubah = 0;
        $pcTurun = 0;
        $tetap = 0;
        $kodeTurun = [];

        foreach ($ruang as $r) {
            $kolom = ['capacity' => $kapasitas];

            if ($r->device_count > $kapasitas) {
                $kolom['device_count'] = $kapasitas;
                $pcTurun++;
                $kodeTurun[] = $r->code;
            }

            if ((int) $r->capacity === $kapasitas && ! isset($kolom['device_count'])) {
                $tetap++;

                continue;
            }

            $r->update($kolom);
            $diubah++;
        }

        $logger->log('Menyetel kapasitas ruang secara massal', null, [
            'kapasitas' => $kapasitas,
            'jumlah' => count($data['id']),
            'diubah' => $diubah,
            'pc_diturunkan' => $pcTurun,
        ]);

        $pesan = "{$diubah} ruang disetel ke kapasitas {$kapasitas}.";

        if ($tetap > 0) {
            $pesan .= " {$tetap} ruang sudah berkapasitas {$kapasitas} - tidak berubah.";
        }

        if ($pcTurun > 0) {
            $pesan .= " {$pcTurun} ruang: jumlah PC diturunkan menjadi {$kapasitas} agar tidak melebihi kapasitasnya"
                .($kodeTurun === [] ? '.' : ' ('.implode(', ', $kodeTurun).').');
        }

        return back()->with('sukses', $pesan);
    }

    /**
     * Rincian ruang: tautan pintu, kode pengawas, dan riwayat tautannya.
     */
    public function show(Room $ruang): Response
    {
        Gate::authorize('view', $ruang);

        $tautan = $ruang->entryLinks()
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return Inertia::render('Admin/RuangDetail', [
            'ruang' => [
                'id' => $ruang->id,
                'kode' => $ruang->code,
                'nama' => $ruang->name,
                'lokasi' => $ruang->location,
                'kapasitas' => $ruang->capacity,
                'pc' => $ruang->device_count,
                'byod' => $ruang->isByod(),
                'status' => $ruang->status,
            ],
            'tautan' => $tautan->map(fn ($t) => [
                'id' => $t->id,
                'slug' => $t->slug,
                'url' => url('/p/'.$t->slug),
                'status' => $t->statusChip(),
                'berlaku' => $t->berlaku(),
                'dipakai' => $t->use_count,
                'terbit' => $t->created_at?->format('d/m H:i'),
                'kedaluwarsa' => $t->expires_at?->format('d/m H:i'),
                'dicabut' => $t->revoked_at?->format('d/m H:i'),
            ])->all(),
            'aktif' => ($a = $ruang->tautanAktif()) ? [
                'id' => $a->id,
                'url' => url('/p/'.$a->slug),
                'slug' => $a->slug,
                'kedaluwarsa' => $a->expires_at?->format('d/m H:i'),
                'dipakai' => $a->use_count,
            ] : null,
        ]);
    }

    /**
     * Terbitkan tautan pintu baru untuk satu ruang.
     *
     * ---------------------------------------------------------------------
     * Yang TIDAK lagi diterbitkan method ini: kode pengawas
     * ---------------------------------------------------------------------
     * Sampai 2026-11-07 ia mengirim kode enam angka lewat flash
     * `kode_pengawas`, dan kode itu ikut tercetak pada kartu yang sama dengan
     * tautannya di daun pintu - sehingga memfoto kartu itu sudah cukup untuk
     * masuk. Kode pengawas kini milik ORANG dan terbit di laman Kepanitiaan
     * (`EventCommitteeController::terbitkanKode()`).
     *
     * Yang tersisa di sini adalah tautan, dan tautan sendirian tidak membuka
     * apa pun: ia hanya menampilkan satu kolom kode di pintu.
     *
     * `slug_baru` juga tidak dikirim lagi. Ia dulu dipakai `RuangDetail`
     * untuk menyebut slug di bawah kode yang baru terbit - dan tanpa kode,
     * slug barunya sudah terbaca dari `aktif.url` begitu lamannya dimuat
     * ulang, sehingga yang tersisa hanyalah flash tanpa pembaca.
     */
    public function terbitkan(Room $ruang, RoomEntryLinkService $service, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('terbitkanTautan', $ruang);

        $tautan = $service->terbitkan($ruang);

        $logger->log('Menerbitkan tautan ruang', $ruang, [
            'kode' => $ruang->code,
            'slug' => $tautan->slug,
        ]);

        // Tautan lama sudah dicabut oleh service. Admin perlu tahu itu,
        // supaya ia tidak mengira kartu pintu lama masih berlaku.
        return back()->with('sukses', 'Tautan ruang diterbitkan. Tautan lama dicabut.');
    }

    public function cabut(Room $ruang, RoomEntryLinkService $service, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('terbitkanTautan', $ruang);

        $jumlah = $service->cabutSemua($ruang);

        $logger->log('Mencabut tautan ruang', $ruang, ['kode' => $ruang->code, 'jumlah' => $jumlah]);

        return back()->with('sukses', $jumlah > 0
            ? 'Tautan ruang dicabut. Kartu pintu yang beredar tidak lagi berlaku.'
            : 'Tidak ada tautan yang sedang berlaku.');
    }

    /**
     * Terbitkan tautan pintu untuk SELURUH ruang yang belum punya.
     *
     * ---------------------------------------------------------------------
     * Ruang yang tautannya sudah berlaku DILEWATI, dan itu bukan kehati-hatian
     * ---------------------------------------------------------------------
     * `terbitkan()` pada satu ruang mencabut tautan lamanya lebih dulu - dan
     * itu memang yang diinginkan Admin yang menekan tombolnya karena kartu
     * pintunya bocor. Menjalankan niat yang sama terhadap dua puluh empat
     * ruang sekaligus berarti mencabut seluruh kartu yang sudah ditempel di
     * setiap pintu, pada saat Admin justru sedang menyiapkan hari ujian.
     *
     * Jadi operasi massal ini HANYA menerbitkan yang belum ada. Yang sudah
     * berlaku dilewati dan dilaporkan sebagai "sudah punya tautan" - bukan
     * sebagai kegagalan. Untuk mengganti satu tautan tertentu, laman rincian
     * ruanglah tempatnya, karena di sana Admin melihat tautan mana yang ia
     * bunuh.
     */
    public function terbitkanMassal(
        RoomEntryLinkService $service,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('viewAny', Room::class);

        $jumlah = 0;
        $sudah = 0;

        foreach (Room::query()->orderBy('code')->get() as $ruang) {
            if ($ruang->tautanAktif() !== null) {
                $sudah++;

                continue;
            }

            $service->terbitkan($ruang);

            $jumlah++;
        }

        $logger->log('Menerbitkan tautan ruang secara massal', null, [
            'diterbitkan' => $jumlah,
            'sudah_punya' => $sudah,
        ]);

        if ($jumlah === 0) {
            return back()->with('sukses', 'Semua ruang sudah punya tautan yang berlaku. Tidak ada yang diterbitkan.');
        }

        $pesan = "{$jumlah} tautan ruang diterbitkan.";

        if ($sudah > 0) {
            $pesan .= " {$sudah} ruang sudah punya tautan - tidak diubah, kartu pintunya tetap berlaku.";
        }

        return back()->with('sukses', $pesan);
    }

    /**
     * Cetak kartu pintu: satu lembar A4 berisi QR + tautan untuk SETIAP ruang.
     *
     * ---------------------------------------------------------------------
     * Semua ruang, bukan hanya yang punya tautan aktif
     * ---------------------------------------------------------------------
     * Ruang yang tautannya belum terbit tetap mendapat halaman, dan halamannya
     * mengatakan hal itu. Menyaringnya keluar berarti Admin yang menempel
     * "semua pintu" menemukan satu pintu tanpa kertas tanpa tahu sebabnya -
     * lihat catatan di `KartuPintu`.
     *
     * ---------------------------------------------------------------------
     * Dipisah dari halaman rincian satu ruang
     * ---------------------------------------------------------------------
     * Tautan yang dirinci (`show()`) adalah milik SATU ruang. Kartu ini
     * melayani kebutuhan yang berbeda: berjalan ke setiap pintu dengan satu
     * berkas di tangan. Karena itu ia mencetak seluruh sekolah sekaligus -
     * keadaan yang sama dengan pengalaman Admin saat menyiapkan hari ujian.
     */
    public function kartu(KartuPintu $kartu, ActivityLogger $logger): HttpResponse
    {
        Gate::authorize('viewAny', Room::class);

        $ruang = Room::query()
            ->with(['entryLinks' => fn ($q) => $q->whereNull('revoked_at')])
            ->orderBy('code')
            ->get();

        $sekolah = School::query()->find($ruang->first()?->school_id ?? auth()->user()?->school_id);

        $pdf = $kartu->buat($ruang, KopSekolah::dari($sekolah));

        $logger->log('Mencetak kartu pintu ruang', null, ['jumlah' => $ruang->count()]);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$kartu->namaBerkas().'"',
        ]);
    }
}

<?php

namespace App\Domain\Admin\Http\Controllers;

use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Facility\Models\Room;
use App\Domain\Facility\Services\RoomEntryLinkService;
use App\Support\TabelQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
}

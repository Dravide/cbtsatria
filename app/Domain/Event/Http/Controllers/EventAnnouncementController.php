<?php

namespace App\Domain\Event\Http\Controllers;

use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Event\Models\EventAnnouncement;
use App\Domain\Event\Models\SchoolEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Papan pengumuman event - ditambahkan 2026-11-14.
 *
 * ---------------------------------------------------------------------
 * Mengapa laman sendiri, bukan kotak di Ringkasan
 * ---------------------------------------------------------------------
 * Pengumuman adalah satu-satunya hal di konsol Admin yang ditulis UNTUK
 * SISWA, bukan tentang siswa. Menaruhnya di Ringkasan berarti mencampur
 * "apa yang harus saya siapkan" dengan "berapa ruang sudah siap" di satu
 * layar yang dibaca dengan dua maksud berbeda.
 *
 * Laman sendiri juga memberi tempat untuk melihat pengumuman yang SUDAH
 * kedaluwarsa - dan itu penting justru pada hari-H: Admin yang menulis
 * "sesi 2 mundur" lalu ingin memperpanjangnya perlu melihat pesannya,
 * bukan menghapusnya dan menulis ulang.
 *
 * ---------------------------------------------------------------------
 * Tidak ada endpoint hapus-massal, dan itu keputusan
 * ---------------------------------------------------------------------
 * Pengumuman yang sudah dibaca siswa tidak bisa "ditarik" - ia hilang dari
 * layar mereka, tetapi kalimatnya sudah terbaca. Yang bisa dilakukan
 * adalah berhenti menayangkannya (hapus atau isi `expires_at`), dan itu
 * satu per satu dengan sengaja.
 */
class EventAnnouncementController
{
    public function index(SchoolEvent $event): Response
    {
        Gate::authorize('view', $event);

        $semua = EventAnnouncement::query()
            ->where('event_id', $event->id)
            ->with('penulis:id,name')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('Admin/Event/Pengumuman', [
            'event' => [
                'id' => $event->id,
                'kode' => $event->code,
                'nama' => $event->name,
                'status' => $event->status->value,
                'status_label' => $event->status->label(),
            ],
            'pengumuman' => $semua->map(fn (EventAnnouncement $p) => [
                'id' => $p->id,
                'judul' => $p->title,
                'isi' => $p->body,
                'nada' => $p->nada,
                'berlaku_sampai' => $p->expires_at?->format('d M Y H:i'),
                'berlaku_sampai_iso' => $p->expires_at?->format('Y-m-d\TH:i'),
                'tayang' => ! $p->kadaluwarsa(),
                'penulis' => $p->penulis?->name,
                'dibuat' => $p->created_at?->format('d M Y H:i'),
            ])->all(),
            // Ditulis server, bukan dihitung React: keadaan "sedang tayang"
            // bergantung pada jam server (APP_TIMEZONE), sementara jam
            // peramban Admin bisa berbeda zona.
            'angka' => [
                'tayang' => $semua->reject(fn (EventAnnouncement $p) => $p->kadaluwarsa())->count(),
                'berakhir' => $semua->filter(fn (EventAnnouncement $p) => $p->kadaluwarsa())->count(),
            ],
        ]);
    }

    public function store(Request $request, SchoolEvent $event, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('update', $event);

        $data = $this->validasi($request);

        $pengumuman = EventAnnouncement::create([
            'school_id' => $event->school_id,
            'event_id' => $event->id,
            'title' => $data['judul'],
            'body' => $data['isi'],
            'nada' => $data['nada'] ?? EventAnnouncement::NADA_INFO,
            'expires_at' => $data['berlaku_sampai'] ?? null,
            'created_by' => $request->user()?->id,
        ]);

        $logger->log('Menerbitkan pengumuman event', $pengumuman, [
            'event' => $event->code,
            'judul' => $pengumuman->title,
        ]);

        return back()->with('sukses', 'Pengumuman diterbitkan dan langsung tampil di beranda siswa.');
    }

    public function update(
        Request $request,
        SchoolEvent $event,
        EventAnnouncement $pengumuman,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $event);

        abort_unless((int) $pengumuman->event_id === (int) $event->id, 404);

        $data = $this->validasi($request);

        $pengumuman->forceFill([
            'title' => $data['judul'],
            'body' => $data['isi'],
            'nada' => $data['nada'] ?? EventAnnouncement::NADA_INFO,
            'expires_at' => $data['berlaku_sampai'] ?? null,
        ])->save();

        $logger->log('Memperbarui pengumuman event', $pengumuman, [
            'event' => $event->code,
            'judul' => $pengumuman->title,
        ]);

        return back()->with('sukses', 'Pengumuman tersimpan.');
    }

    public function destroy(
        SchoolEvent $event,
        EventAnnouncement $pengumuman,
        ActivityLogger $logger,
    ): RedirectResponse {
        // Menghapus pengumuman adalah tindakan atas ISI, bukan atas eventnya,
        // jadi `update` yang dipakai - bukan `delete` milik event. EventPolicy
        // memberi keduanya kepada Admin, dan garis ini menjaga arti keduanya
        // tetap terpisah bila kelak panitia diberi wewenang menulis.
        Gate::authorize('update', $event);

        abort_unless((int) $pengumuman->event_id === (int) $event->id, 404);

        $judul = $pengumuman->title;

        $pengumuman->delete();

        $logger->log('Menghapus pengumuman event', null, [
            'event' => $event->code,
            'judul' => $judul,
        ]);

        return back()->with('sukses', "Pengumuman \"{$judul}\" dihapus dari beranda siswa.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validasi(Request $request): array
    {
        return $request->validate([
            'judul' => ['required', 'string', 'max:'.EventAnnouncement::MAKS_JUDUL],
            'isi' => ['required', 'string', 'max:'.EventAnnouncement::MAKS_ISI],
            'nada' => ['nullable', Rule::in([EventAnnouncement::NADA_INFO, EventAnnouncement::NADA_PENTING])],

            // `after:now` dan bukan `after_or_equal:now`: pengumuman yang
            // berakhir pada detik ia ditulis tidak pernah tayang, dan Admin
            // yang menulisnya akan mengira tombolnya gagal.
            'berlaku_sampai' => ['nullable', 'date', 'after:now'],
        ], [
            'berlaku_sampai.after' => 'Waktu berakhirnya harus sesudah sekarang, bukan sebelumnya.',
        ], [
            'judul' => 'judul pengumuman',
            'isi' => 'isi pengumuman',
            'berlaku_sampai' => 'waktu berakhir',
        ]);
    }
}

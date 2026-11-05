<?php

namespace App\Domain\Event\Http\Controllers;

use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Event\Models\EventCommitteeMember;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Facility\Models\Room;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kepanitiaan event - wireframe plate 13, PRD §6.6.
 *
 * ---------------------------------------------------------------------
 * Kolom "Cakupan" harus terlihat di tabel, bukan disembunyikan di detail
 * ---------------------------------------------------------------------
 * Wireframe plate 13 mencatat alasannya: cakupan "membatasi apa yang boleh
 * mereka lihat dan ubah - dan itu sebabnya cakupan harus terlihat di tabel,
 * bukan tersembunyi di halaman detail."
 *
 * Konsekuensinya: kolomnya dirender sebagai daftar kode ruang yang bisa
 * dibaca sekali pandang ("R-01, R-02"), bukan sebagai jumlah ("2 ruang").
 * "2 ruang" tidak memberi tahu ruang mana, dan pertanyaan berikutnya selalu
 * ruang mana - sehingga Admin harus membuka barisnya satu per satu untuk
 * memastikan penempatan pengawas benar. Pada sekolah dengan 24 ruang dan 31
 * pengawas, itu 31 kali membuka.
 */
class EventCommitteeController
{
    public function index(SchoolEvent $event): Response
    {
        Gate::authorize('view', $event);

        $panitia = $event->committee()
            ->with(['rooms:id,code,name', 'user:id,name,username'])
            ->orderByRaw("FIELD(position, 'ketua', 'koordinator', 'pengawas', 'anggota')")
            ->orderBy('name')
            ->get();

        return Inertia::render('Admin/Event/Panitia', [
            'event' => $this->ringkas($event),
            'panitia' => $panitia->map(fn (EventCommitteeMember $p) => [
                'id' => $p->id,
                'nama' => $p->name,
                'jabatan' => $p->position,
                'cakupan' => $p->labelCakupan(),
                'cakupan_kode' => $p->scope,
                'ruang_id' => $p->rooms->pluck('id')->all(),
                'ruang_kode' => $p->rooms->pluck('code')->all(),
                'status' => $p->status,
                'akun' => $p->user?->username,
                'email' => $p->email,
                'phone' => $p->phone,
            ])->all(),

            // Akun yang bisa ditautkan: Guru dan Pengawas. Menawarkan seluruh
            // pengguna berarti menawarkan siswa sebagai ketua panitia, dan itu
            // pilihan yang tidak pernah benar - sama seperti daftar wali kelas
            // di SchoolClassController.
            'akun' => User::query()
                ->whereIn('role', [Role::Teacher, Role::Supervisor])
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'username', 'role'])
                ->map(fn (User $u) => [
                    'id' => $u->id,
                    'nama' => $u->name,
                    'username' => $u->username,
                    'peran' => $u->role->label(),
                ])->all(),

            'ruang' => Room::query()
                ->where('status', 'aktif')
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn (Room $r) => ['id' => $r->id, 'kode' => $r->code, 'nama' => $r->name])
                ->all(),

            'jabatan' => self::JABATAN,
        ]);
    }

    public function store(Request $request, SchoolEvent $event, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('update', $event);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'position' => ['required', 'string', 'max:40'],
            'user_id' => ['nullable', 'integer'],
            'email' => ['nullable', 'email', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'scope' => ['required', 'in:event,ruang'],
            'ruang_id' => ['nullable', 'array'],
            'ruang_id.*' => ['integer'],
            'status' => ['nullable', 'in:aktif,belum konfirmasi,nonaktif'],
        ]);

        // Cakupan "ruang" tanpa satu ruang pun bukan cakupan yang membatasi
        // apa pun - ia terbaca seperti panitia penuh yang diberi label salah.
        // Menolaknya di sini lebih baik daripada membiarkan Admin menemukan
        // bahwa orangnya bisa membuka semua ruang.
        if ($data['scope'] === EventCommitteeMember::SCOPE_ROOMS && empty($data['ruang_id'])) {
            return back()->with('galat', 'Cakupan "ruang tertentu" harus menyebut minimal satu ruang.');
        }

        // Akun yang ditautkan harus benar-benar ada di sekolah ini: scope
        // yang memutuskan, dan id sekolah lain berakhir 404 lewat findOrFail.
        if (! empty($data['user_id'])) {
            User::findOrFail($data['user_id']);
        }

        $panitia = EventCommitteeMember::create([
            'school_id' => $event->school_id,
            'event_id' => $event->id,
            'user_id' => $data['user_id'] ?? null,
            'name' => $data['name'],
            'position' => $data['position'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'scope' => $data['scope'],
            'status' => $data['status'] ?? 'aktif',
        ]);

        $this->simpanRuang($panitia, $data['ruang_id'] ?? []);

        $logger->log('Menambah panitia event', $event, [
            'nama' => $panitia->name,
            'jabatan' => $panitia->position,
        ]);

        return back()->with('sukses', "{$panitia->name} ditambahkan sebagai {$panitia->position}.");
    }

    public function update(
        Request $request,
        SchoolEvent $event,
        EventCommitteeMember $panitia,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $event);

        // Binding implisit pada $panitia mencari berdasarkan id di seluruh
        // sekolah, bukan di dalam event ini - sehingga id panitia milik event
        // LAIN akan lolos dan berubah di tempat yang salah. Pemeriksaan ini
        // yang menutupnya, dan 404 adalah jawabannya: bagi event ini, baris
        // itu memang tidak ada.
        abort_unless($panitia->event_id === $event->id, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'position' => ['required', 'string', 'max:40'],
            'user_id' => ['nullable', 'integer'],
            'email' => ['nullable', 'email', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'scope' => ['required', 'in:event,ruang'],
            'ruang_id' => ['nullable', 'array'],
            'ruang_id.*' => ['integer'],
            'status' => ['required', 'in:aktif,belum konfirmasi,nonaktif'],
        ]);

        if ($data['scope'] === EventCommitteeMember::SCOPE_ROOMS && empty($data['ruang_id'])) {
            return back()->with('galat', 'Cakupan "ruang tertentu" harus menyebut minimal satu ruang.');
        }

        if (! empty($data['user_id'])) {
            User::findOrFail($data['user_id']);
        }

        $panitia->update([
            'user_id' => $data['user_id'] ?? null,
            'name' => $data['name'],
            'position' => $data['position'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'scope' => $data['scope'],
            'status' => $data['status'],
        ]);

        $this->simpanRuang($panitia, $data['ruang_id'] ?? []);

        $logger->log('Memperbarui panitia event', $event, ['nama' => $panitia->name]);

        return back()->with('sukses', 'Panitia tersimpan.');
    }

    public function destroy(SchoolEvent $event, EventCommitteeMember $panitia, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('update', $event);

        abort_unless($panitia->event_id === $event->id, 404);

        $nama = $panitia->name;

        // Hapus keras, berbeda dari peserta. Panitia adalah penugasan yang
        // berakhir saat orangnya tidak lagi bertugas, dan barisnya tidak
        // menyimpan riwayat apa pun yang perlu dipertahankan - tidak seperti
        // peserta, yang kehadirannya di event adalah catatan akademik.
        $panitia->delete();

        $logger->log('Menghapus panitia event', $event, ['nama' => $nama]);

        return back()->with('sukses', "{$nama} dikeluarkan dari kepanitiaan.");
    }

    /**
     * Cakupan "seluruh event" tidak menyimpan satu baris ruang pun.
     *
     * Menyimpannya berarti dua sumber kebenaran untuk pertanyaan yang sama,
     * dan yang lebih tua akan menang diam-diam saat keduanya berbeda.
     *
     * @param  array<int, int>  $ruangId
     */
    private function simpanRuang(EventCommitteeMember $panitia, array $ruangId): void
    {
        if ($panitia->scope !== EventCommitteeMember::SCOPE_ROOMS) {
            $panitia->rooms()->detach();

            return;
        }

        // Hanya ruang yang benar-benar ada di sekolah ini yang tersimpan.
        // Scope pada Room menyaringnya, dan id sekolah lain berakhir tidak
        // ikut - bukan galat yang membocorkan keberadaannya.
        $sah = Room::query()->whereIn('id', $ruangId)->pluck('id')->all();

        $panitia->sinkronRuang($sah);
    }

    /** @return array<string, mixed> */
    private function ringkas(SchoolEvent $event): array
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
     * Jabatan panitia.
     *
     * Wireframe plate 13 menampilkan empat: ketua, koordinator, pengawas,
     * anggota. Daftar ini diambil apa adanya dari sana - bukan dikarang -
     * karena readiness gate membaca "pengawas" dari daftar ini untuk
     * menentukan apakah pengawas sudah ditugaskan.
     *
     * @var array<int, string>
     */
    public const JABATAN = ['ketua', 'koordinator', 'pengawas', 'anggota'];
}

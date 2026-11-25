<?php

namespace App\Domain\Event\Http\Controllers;

use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Event\Models\EventCommitteeMember;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Event\Services\EksporPanitia;
use App\Domain\Event\Services\KartuPengawas;
use App\Domain\Event\Services\PenerbitKodePengawas;
use App\Domain\Facility\Models\Room;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Support\Cetak\KopSekolah;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pengelolaan kepanitiaan event, ekspor daftar, kartu pengawas, dan penerbitan kode pintu ruang.
 */
class EventCommitteeController
{
    public function index(SchoolEvent $event): Response
    {
        Gate::authorize('view', $event);

        $panitia = $this->panitia($event);

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
                // Kode pengawas dikirim terbuka (cast encrypted) agar Admin bisa mencetak kartu kapan saja.
                'kode' => $p->code,
                'punya_kode' => $p->code !== null,
                'kode_terbit' => $p->code_issued_at?->format('d/m H:i'),
            ])->all(),

            // Hanya tawarkan akun Guru dan Pengawas aktif agar siswa tidak bisa dipilih sebagai panitia.
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

            'jabatan' => $this->saranJabatan($panitia),
        ]);
    }

    /**
     * Unduh daftar panitia sebagai XLSX secara langsung tanpa antrean karena jumlah baris kecil.
     */
    public function ekspor(SchoolEvent $event, EksporPanitia $ekspor): HttpResponse
    {
        Gate::authorize('view', $event);

        $isi = $ekspor->buat($event, $this->panitia($event));

        return response($isi, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$ekspor->namaBerkas($event).'"',
        ]);
    }

    /**
     * Cetak kartu panitia/pengawas (PDF) dan catat ke log audit karena berkas memuat kode pintu.
     */
    public function kartu(
        Request $request,
        SchoolEvent $event,
        KartuPengawas $kartu,
        ActivityLogger $logger,
    ): HttpResponse {
        Gate::authorize('view', $event);

        $kelompok = match ($request->query('kelompok')) {
            'panitia' => 'panitia',
            'pengawas' => 'pengawas',
            default => null,
        };

        // Muat sekali agar jumlah yang dicatat ke log selalu sama dengan isi PDF.
        $panitia = $this->panitia($event);

        if ($kelompok === 'pengawas') {
            $panitia = $panitia
                ->filter(fn (EventCommitteeMember $p) => str_contains(mb_strtolower($p->position), 'pengawas'))
                ->values();
        } elseif ($kelompok === 'panitia') {
            $panitia = $panitia
                ->reject(fn (EventCommitteeMember $p) => str_contains(mb_strtolower($p->position), 'pengawas'))
                ->values();
        }

        $pdf = $kartu->buat(
            $event,
            $panitia,
            KopSekolah::dari(School::query()->find($event->school_id)),
        );

        $logger->log('Mencetak kartu panitia', $event, [
            'kelompok' => $kelompok ?? 'semua',
            'jumlah' => $panitia->count(),
        ]);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$kartu->namaBerkas($event, $kelompok).'"',
        ]);
    }

    /**
     * Ambil panitia satu event dengan urutan konsisten untuk tabel, ekspor XLSX, dan kartu PDF.
     *
     * @return Collection<int, EventCommitteeMember>
     */
    private function panitia(SchoolEvent $event)
    {
        return $event->committee()
            ->with(['rooms:id,code,name', 'user:id,name,username'])
            ->orderByRaw("CASE LOWER(position)
                WHEN 'penanggung jawab' THEN 1
                WHEN 'ketua' THEN 2
                WHEN 'wakil ketua' THEN 3
                WHEN 'sekretaris' THEN 4
                WHEN 'bendahara' THEN 5
                WHEN 'koordinator' THEN 6
                WHEN 'proktor' THEN 7
                WHEN 'teknisi' THEN 8
                WHEN 'pengawas' THEN 9
                WHEN 'anggota' THEN 99
                ELSE 50
            END")
            ->orderBy('position')
            ->orderBy('name')
            ->get();
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

        if ($data['scope'] === EventCommitteeMember::SCOPE_ROOMS && empty($data['ruang_id'])) {
            return back()->with('galat', 'Cakupan "ruang tertentu" harus menyebut minimal satu ruang.');
        }

        // Pastikan akun berada di sekolah yang sama (SchoolScope memicu 404 untuk sekolah lain).
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

        // Terbitkan kode pengawas langsung saat ditambahkan agar kartu bisa segera dicetak.
        $penerbit = app(PenerbitKodePengawas::class);

        if ($penerbit->berlakuUntuk($panitia)) {
            $penerbit->terbitkan($panitia);
        }

        $logger->log('Menambah panitia event', $event, [
            'nama' => $panitia->name,
            'jabatan' => $panitia->position,
        ]);

        return back()->with('sukses', "{$panitia->name} ditambahkan sebagai {$panitia->position}.");
    }

    /**
     * Tambah panitia secara massal dengan satu jabatan dan satu cakupan.
     * Duplikat akun atau nama (constraint unique committee_unik) dilewati dan dilaporkan, bukan gagal 500.
     */
    public function massal(Request $request, SchoolEvent $event, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('update', $event);

        $data = $request->validate([
            'user_id' => ['required', 'array', 'min:1'],
            'user_id.*' => ['integer'],
            'position' => ['required', 'string', 'max:40'],
            'scope' => ['required', 'in:event,ruang'],
            'ruang_id' => ['nullable', 'array'],
            'ruang_id.*' => ['integer'],
        ]);

        if ($data['scope'] === EventCommitteeMember::SCOPE_ROOMS && empty($data['ruang_id'])) {
            return back()->with('galat', 'Cakupan "ruang tertentu" harus menyebut minimal satu ruang.');
        }

        // Saring ulang peran dan status aktif di server agar ID siswa atau akun nonaktif tidak lolos.
        $akun = User::query()
            ->whereIn('id', $data['user_id'])
            ->whereIn('role', [Role::Teacher, Role::Supervisor])
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $sudah = $event->committee()->pluck('name')->map(fn ($n) => mb_strtolower($n))->all();
        $sudahAkun = $event->committee()->whereNotNull('user_id')->pluck('user_id')->all();

        $jumlah = 0;
        $dilewati = [];

        $penerbit = app(PenerbitKodePengawas::class);

        DB::transaction(function () use ($data, $event, $akun, $sudah, $sudahAkun, $penerbit, &$jumlah, &$dilewati) {
            $namaDiKiriman = [];

            foreach (array_unique($data['user_id']) as $id) {
                $orang = $akun->get($id);

                if ($orang === null) {
                    // Mencakup akun sekolah lain (tersaring SchoolScope), nonaktif, atau bukan Guru/Pengawas.
                    $dilewati[] = "akun #{$id} (bukan Guru/Pengawas aktif)";

                    continue;
                }

                if (in_array($orang->id, $sudahAkun, true)) {
                    $dilewati[] = "{$orang->name} (sudah jadi panitia)";

                    continue;
                }

                $kunci = mb_strtolower($orang->name);

                if (in_array($kunci, $sudah, true) || in_array($kunci, $namaDiKiriman, true)) {
                    $dilewati[] = "{$orang->name} (nama sudah ada di kepanitiaan)";

                    continue;
                }

                $namaDiKiriman[] = $kunci;

                $panitia = EventCommitteeMember::create([
                    'school_id' => $event->school_id,
                    'event_id' => $event->id,
                    'user_id' => $orang->id,
                    'name' => $orang->name,
                    'position' => $data['position'],
                    'scope' => $data['scope'],
                    'status' => 'aktif',
                ]);

                $this->simpanRuang($panitia, $data['ruang_id'] ?? []);

                if ($penerbit->berlakuUntuk($panitia)) {
                    $penerbit->terbitkan($panitia);
                }

                $jumlah++;
            }
        });

        // Satu entri log untuk satu aksi massal agar riwayat aktivitas tidak penuh.
        $logger->log('Menambah panitia event secara massal', $event, [
            'jabatan' => $data['position'],
            'cakupan' => $data['scope'],
            'jumlah' => $jumlah,
            'dilewati' => count($dilewati),
        ]);

        if ($jumlah === 0) {
            return back()->with('galat', 'Tidak ada yang ditambahkan. '.implode('; ', $dilewati));
        }

        $pesan = "{$jumlah} panitia ditambahkan sebagai {$data['position']}.";

        if ($dilewati !== []) {
            $pesan .= ' Dilewati: '.implode('; ', $dilewati).'.';
        }

        return back()->with('sukses', $pesan);
    }

    /**
     * Ganti kode pintu ruang untuk satu pengawas (memanggil putar() sehingga kode lama langsung tidak berlaku).
     */
    public function terbitkanKode(
        SchoolEvent $event,
        EventCommitteeMember $panitia,
        PenerbitKodePengawas $penerbit,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $event);

        // Route model binding mencari lintas event di sekolah yang sama.
        abort_unless($panitia->event_id === $event->id, 404);

        if (! $penerbit->berlakuUntuk($panitia)) {
            return back()->with('galat', $this->alasanTanpaKode($panitia));
        }

        $penerbit->putar($panitia);

        $logger->log('Menerbitkan kode pengawas', $event, [
            'nama' => $panitia->name,
            'jabatan' => $panitia->position,
        ]);

        return back()->with('sukses', "Kode pengawas {$panitia->name} diganti. Kode lama berhenti berlaku.");
    }

    /**
     * Terbitkan kode untuk pengawas yang belum punya; memakai terbitkan() (bukan putar())
     * agar kartu yang sudah dicetak tidak ikut mati.
     */
    public function terbitkanKodeMassal(
        SchoolEvent $event,
        PenerbitKodePengawas $penerbit,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $event);

        $jumlah = 0;
        $sudah = 0;
        $dilewati = [];

        foreach ($this->panitia($event) as $panitia) {
            if (! $penerbit->berlakuUntuk($panitia)) {
                $hambatan = $this->hambatanKode($panitia);

                $dilewati[] = "{$panitia->name} ({$hambatan['sebab']})";

                continue;
            }

            if ($panitia->code !== null) {
                $sudah++;

                continue;
            }

            $penerbit->terbitkan($panitia);

            $jumlah++;
        }

        $logger->log('Menerbitkan kode pengawas secara massal', $event, [
            'diterbitkan' => $jumlah,
            'sudah_punya' => $sudah,
            'dilewati' => count($dilewati),
        ]);

        if ($jumlah === 0 && $dilewati === []) {
            return back()->with('sukses', 'Semua pengawas sudah punya kode. Tidak ada yang diterbitkan.');
        }

        if ($jumlah === 0) {
            return back()->with('galat', 'Tidak ada kode yang diterbitkan. '.implode('; ', $dilewati));
        }

        $pesan = "{$jumlah} kode pengawas diterbitkan.";

        if ($sudah > 0) {
            $pesan .= " {$sudah} sudah punya kode - tidak diubah, kartunya tetap berlaku.";
        }

        if ($dilewati !== []) {
            $pesan .= ' Dilewati: '.implode('; ', $dilewati).'.';
        }

        return back()->with('sukses', $pesan);
    }

    /**
     * Pisahkan sebab (untuk ringkasan massal) dan saran (untuk panel tunggal).
     *
     * @return array{sebab: string, saran: string}
     */
    private function hambatanKode(EventCommitteeMember $panitia): array
    {
        if ($panitia->user_id === null) {
            return [
                'sebab' => 'belum punya akun',
                'saran' => 'Tautkan akun Guru atau Pengawas lebih dulu - tanpa akun, ia tidak bisa ditugaskan ke ruang mana pun, sehingga kodenya tidak akan punya pintu.',
            ];
        }

        if (! str_contains(mb_strtolower($panitia->position), 'pengawas')) {
            return [
                'sebab' => "berjabatan {$panitia->position}, bukan pengawas",
                'saran' => 'Kode pintu ruang hanya untuk pengawas.',
            ];
        }

        return [
            'sebab' => "berstatus {$panitia->status}",
            'saran' => 'Aktifkan kembali sebelum menerbitkan kodenya.',
        ];
    }

    private function alasanTanpaKode(EventCommitteeMember $panitia): string
    {
        $hambatan = $this->hambatanKode($panitia);

        return "{$panitia->name} {$hambatan['sebab']}. {$hambatan['saran']}";
    }

    public function update(
        Request $request,
        SchoolEvent $event,
        EventCommitteeMember $panitia,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $event);

        // Pastikan panitia milik event ini karena route binding mencari lintas event dalam sekolah.
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

        // Hapus permanen karena panitia adalah penugasan sementara, bukan catatan akademik.
        $panitia->delete();

        $logger->log('Menghapus panitia event', $event, ['nama' => $nama]);

        return back()->with('sukses', "{$nama} dikeluarkan dari kepanitiaan.");
    }

    /**
     * Lepas seluruh relasi ruang bila cakupan adalah seluruh event agar tidak ada dua sumber kebenaran.
     *
     * @param  array<int, int>  $ruangId
     */
    private function simpanRuang(EventCommitteeMember $panitia, array $ruangId): void
    {
        if ($panitia->scope !== EventCommitteeMember::SCOPE_ROOMS) {
            $panitia->rooms()->detach();

            return;
        }

        // SchoolScope menyaring ruang milik sekolah lain secara diam-diam.
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
     * Gabungkan saran jabatan bawaan dengan jabatan kustom yang sudah dipakai di event ini.
     *
     * @param  Collection<int, EventCommitteeMember>  $panitia
     * @return array<int, string>
     */
    private function saranJabatan(Collection $panitia): array
    {
        $saran = self::JABATAN;
        $sudah = array_map(mb_strtolower(...), $saran);

        foreach ($panitia as $p) {
            $kunci = mb_strtolower($p->position);

            if (! in_array($kunci, $sudah, true)) {
                $saran[] = $p->position;
                $sudah[] = $kunci;
            }
        }

        return $saran;
    }

    /**
     * Saran jabatan panitia; jabatan yang memuat "pengawas" dibaca oleh readiness gate dan kode pintu.
     *
     * @var array<int, string>
     */
    public const JABATAN = [
        'penanggung jawab',
        'ketua',
        'wakil ketua',
        'sekretaris',
        'bendahara',
        'koordinator',
        'proktor',
        'teknisi',
        'pengawas',
        'anggota',
    ];
}

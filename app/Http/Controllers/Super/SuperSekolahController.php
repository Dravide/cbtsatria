<?php

namespace App\Http\Controllers\Super;

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Models\PlatformActivityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SuperSekolahController
{
    public function index(Request $request): Response
    {
        $cari = trim((string) $request->query('cari', ''));
        $status = trim((string) $request->query('status', ''));

        $sekolah = School::query()
            ->when($cari !== '', function ($q) use ($cari) {
                $q->where(function ($sub) use ($cari) {
                    $sub->where('name', 'like', "%{$cari}%")
                        ->orWhere('code', 'like', "%{$cari}%")
                        ->orWhere('npsn', 'like', "%{$cari}%");
                });
            })
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')
            ->get();

        $sekolahIds = $sekolah->pluck('id')->all();

        $cacahPengguna = $sekolahIds === []
            ? collect()
            : User::query()
                ->withoutGlobalScopes()
                ->whereIn('school_id', $sekolahIds)
                ->selectRaw('school_id, role, COUNT(*) as total')
                ->groupBy('school_id', 'role')
                ->get()
                ->groupBy('school_id');

        $cacahEvent = $sekolahIds === []
            ? collect()
            : SchoolEvent::query()
                ->withoutGlobalScopes()
                ->whereIn('school_id', $sekolahIds)
                ->selectRaw('school_id, COUNT(*) as total')
                ->groupBy('school_id')
                ->pluck('total', 'school_id');

        $daftar = $sekolah->map(function (School $s) use ($cacahPengguna, $cacahEvent) {
            $peran = $cacahPengguna->get($s->id, collect())->keyBy('role');

            return [
                'id' => (int) $s->id,
                'kode' => $s->code,
                'nama' => $s->name,
                'npsn' => $s->npsn,
                'status' => $s->status,
                'papan_slug' => $s->papan_slug,
                'siswa' => (int) ($peran->get(Role::Student->value)?->total ?? 0),
                'guru' => (int) ($peran->get(Role::Teacher->value)?->total ?? 0),
                'admin' => (int) ($peran->get(Role::Admin->value)?->total ?? 0),
                'event' => (int) ($cacahEvent->get($s->id) ?? 0),
                'dibuat' => $s->created_at?->format('d M Y'),
            ];
        })->values()->all();

        return Inertia::render('Super/SekolahIndex', [
            'sekolah' => $daftar,
            'filter' => [
                'cari' => $cari,
                'status' => $status,
            ],
        ]);
    }

    /**
     * Provisioning 1 klik: Sekolah + Admin pertama + TA aktif + 2 Semester.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/', 'unique:schools,code'],
            'name' => ['required', 'string', 'max:160'],
            'npsn' => ['required', 'string', 'regex:/^\d{8}$/', 'unique:schools,npsn'],
            'timezone' => ['nullable', 'string', 'max:60'],
            'email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:500'],
            'admin_name' => ['required', 'string', 'max:120'],
            'admin_username' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9._-]+$/', 'unique:users,username'],
        ], [], [
            'code' => 'kode sekolah',
            'name' => 'nama sekolah',
            'npsn' => 'NPSN',
            'admin_name' => 'nama admin pertama',
            'admin_username' => 'username admin pertama',
        ]);

        $sandiAcak = Str::password(12, symbols: false);
        $tahunSekarang = now()->month >= 7 ? now()->year : now()->year - 1;
        $namaTa = "{$tahunSekarang}/".($tahunSekarang + 1);

        $sekolah = DB::transaction(function () use ($data, $sandiAcak, $tahunSekarang, $namaTa): School {
            $sekolah = School::query()->create([
                'code' => strtoupper(trim($data['code'])),
                'name' => trim($data['name']),
                'npsn' => trim($data['npsn']),
                'timezone' => $data['timezone'] ?: 'Asia/Jakarta',
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null,
                'status' => 'aktif',
            ]);

            User::query()->withoutGlobalScopes()->create([
                'school_id' => $sekolah->id,
                'role' => Role::Admin,
                'name' => trim($data['admin_name']),
                'username' => trim($data['admin_username']),
                'password' => $sandiAcak,
                'is_active' => true,
            ]);

            $ta = AcademicYear::query()->withoutGlobalScopes()->create([
                'school_id' => $sekolah->id,
                'name' => $namaTa,
                'start_date' => "{$tahunSekarang}-07-01",
                'end_date' => ($tahunSekarang + 1).'-06-30',
                'is_active' => true,
            ]);

            Semester::query()->withoutGlobalScopes()->create([
                'school_id' => $sekolah->id,
                'academic_year_id' => $ta->id,
                'name' => 'Ganjil',
                'start_date' => "{$tahunSekarang}-07-01",
                'end_date' => "{$tahunSekarang}-12-31",
                'is_active' => true,
            ]);

            Semester::query()->withoutGlobalScopes()->create([
                'school_id' => $sekolah->id,
                'academic_year_id' => $ta->id,
                'name' => 'Genap',
                'start_date' => ($tahunSekarang + 1).'-01-01',
                'end_date' => ($tahunSekarang + 1).'-06-30',
                'is_active' => false,
            ]);

            return $sekolah;
        });

        PlatformActivityLog::catat(
            'Mendaftarkan sekolah baru',
            $request->user(),
            $sekolah,
            ['kode' => $sekolah->code, 'admin_username' => $data['admin_username']],
        );

        return redirect()
            ->route('super.sekolah.show', ['sekolah' => $sekolah->id])
            ->with('sukses', "Sekolah {$sekolah->name} berhasil didaftarkan beserta TA {$namaTa} dan Admin pertama.")
            ->with('sandi_baru', [
                'username' => trim($data['admin_username']),
                'sandi' => $sandiAcak,
            ]);
    }

    public function show(int $sekolah): Response
    {
        $model = School::query()->findOrFail($sekolah);

        $admins = User::query()
            ->withoutGlobalScopes()
            ->where('school_id', $model->id)
            ->where('role', Role::Admin->value)
            ->orderBy('id')
            ->get(['id', 'name', 'username', 'email', 'is_active', 'last_login_at'])
            ->map(fn (User $u) => [
                'id' => (int) $u->id,
                'nama' => $u->name,
                'username' => $u->username,
                'email' => $u->email,
                'aktif' => (bool) $u->is_active,
                'terakhir_masuk' => $u->last_login_at?->locale('id')->translatedFormat('d M Y, H:i'),
            ])
            ->values()
            ->all();

        $jumlahSiswa = User::query()->withoutGlobalScopes()->where('school_id', $model->id)->where('role', Role::Student->value)->count();
        $jumlahGuru = User::query()->withoutGlobalScopes()->where('school_id', $model->id)->where('role', Role::Teacher->value)->count();
        $jumlahEvent = SchoolEvent::query()->withoutGlobalScopes()->where('school_id', $model->id)->count();

        $settings = is_array($model->settings) ? $model->settings : [];
        $kuota = is_array($settings['kuota'] ?? null) ? $settings['kuota'] : [];
        $modul = is_array($settings['modul'] ?? null) ? $settings['modul'] : [];

        return Inertia::render('Super/SekolahShow', [
            'sekolah' => [
                'id' => (int) $model->id,
                'kode' => $model->code,
                'nama' => $model->name,
                'npsn' => $model->npsn,
                'email' => $model->email,
                'phone' => $model->phone,
                'address' => $model->address,
                'timezone' => $model->timezone,
                'status' => $model->status,
                'papan_slug' => $model->papan_slug,
                'kuota' => [
                    'maks_siswa' => (int) ($kuota['maks_siswa'] ?? 0),
                    'maks_storage_mb' => (int) ($kuota['maks_storage_mb'] ?? 0),
                ],
                'modul' => [
                    'ai' => (bool) ($modul['ai'] ?? true),
                    'publik' => (bool) ($modul['publik'] ?? true),
                    'waktu_luang' => (bool) ($modul['waktu_luang'] ?? true),
                ],
            ],
            'statistik' => [
                'siswa' => $jumlahSiswa,
                'guru' => $jumlahGuru,
                'event' => $jumlahEvent,
            ],
            'admins' => $admins,
        ]);
    }

    public function update(Request $request, int $sekolah): RedirectResponse
    {
        $model = School::query()->findOrFail($sekolah);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'npsn' => ['required', 'string', 'regex:/^\d{8}$/', Rule::unique('schools', 'npsn')->ignore($model->id)],
            'status' => ['required', Rule::in(['aktif', 'ditangguhkan'])],
            'timezone' => ['required', 'string', 'max:60'],
            'maks_siswa' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'maks_storage_mb' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'modul_ai' => ['required', 'boolean'],
            'modul_publik' => ['required', 'boolean'],
            'modul_waktu_luang' => ['required', 'boolean'],
        ]);

        $settings = is_array($model->settings) ? $model->settings : [];
        $settings['kuota'] = [
            'maks_siswa' => (int) ($data['maks_siswa'] ?? 0),
            'maks_storage_mb' => (int) ($data['maks_storage_mb'] ?? 0),
        ];
        $settings['modul'] = [
            'ai' => (bool) $data['modul_ai'],
            'publik' => (bool) $data['modul_publik'],
            'waktu_luang' => (bool) $data['modul_waktu_luang'],
        ];

        $model->forceFill([
            'name' => trim($data['name']),
            'npsn' => trim($data['npsn']),
            'status' => $data['status'],
            'timezone' => $data['timezone'],
            'settings' => $settings,
        ])->save();

        PlatformActivityLog::catat(
            'Memperbarui pengaturan sekolah',
            $request->user(),
            $model,
            ['status' => $model->status, 'kuota' => $settings['kuota'], 'modul' => $settings['modul']],
        );

        return back()->with('sukses', 'Pengaturan sekolah berhasil diperbarui.');
    }

    public function tambahAdmin(Request $request, int $sekolah): RedirectResponse
    {
        $model = School::query()->findOrFail($sekolah);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'username' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9._-]+$/', 'unique:users,username'],
            'email' => ['nullable', 'email', 'max:160'],
        ]);

        $sandiAcak = Str::password(12, symbols: false);

        User::query()->withoutGlobalScopes()->create([
            'school_id' => $model->id,
            'role' => Role::Admin,
            'name' => trim($data['name']),
            'username' => trim($data['username']),
            'email' => $data['email'] ?? null,
            'password' => $sandiAcak,
            'is_active' => true,
        ]);

        PlatformActivityLog::catat(
            'Menambahkan Admin sekolah',
            $request->user(),
            $model,
            ['username' => trim($data['username'])],
        );

        return back()
            ->with('sukses', 'Akun Admin baru berhasil ditambahkan.')
            ->with('sandi_baru', [
                'username' => trim($data['username']),
                'sandi' => $sandiAcak,
            ]);
    }

    public function resetSandiAdmin(Request $request, int $sekolah, int $user): RedirectResponse
    {
        $model = School::query()->findOrFail($sekolah);

        $admin = User::query()
            ->withoutGlobalScopes()
            ->where('school_id', $model->id)
            ->where('role', Role::Admin->value)
            ->findOrFail($user);

        $sandiAcak = Str::password(12, symbols: false);
        $admin->forceFill(['password' => $sandiAcak])->save();

        PlatformActivityLog::catat(
            'Mereset sandi Admin sekolah',
            $request->user(),
            $model,
            ['target_user_id' => $admin->id, 'username' => $admin->username],
        );

        return back()
            ->with('sukses', "Sandi Admin {$admin->username} berhasil direset.")
            ->with('sandi_baru', [
                'username' => $admin->username,
                'sandi' => $sandiAcak,
            ]);
    }
}

<?php

namespace App\Domain\Admin\Http\Controllers;

use App\Domain\Facility\Models\Room;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard sekolah - wireframe plate 03.
 *
 * Tesis produknya terlihat di laman ini: tanpa event aktif, sidebar kanan
 * konsol belum muncul, dan aksi utama adalah membuat event. Event dulu,
 * komponen menyusul. Fase 1 menampilkan angkanya apa adanya; kartu "Buat
 * event" baru punya tujuan di Fase 3.
 */
class DashboardController
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        Gate::authorize('viewAny', User::class);

        $school = School::findOrFail($user->school_id);

        return Inertia::render('Admin/Dashboard', [
            'sekolah' => [
                'nama' => $school->name,
                'npsn' => $school->npsn,
            ],

            // Hitungan per peran diambil dari satu query, bukan empat.
            'ringkasan' => [
                'guru' => User::query()->where('role', Role::Teacher)->count(),
                'siswa' => User::query()->where('role', Role::Student)->where('is_active', true)->count(),
                'pengawas' => User::query()->where('role', Role::Supervisor)->count(),
                // Ruang aktif saja. Ruang berstatus "perbaikan" (wireframe
                // plate 09, R-09) tidak dihitung: angka ini dipakai untuk
                // menilai kesiapan, dan ruang yang sedang diperbaiki tidak
                // menyumbang kesiapan apa pun.
                'ruang' => Room::query()->where('status', 'aktif')->count(),
            ],
        ]);
    }
}

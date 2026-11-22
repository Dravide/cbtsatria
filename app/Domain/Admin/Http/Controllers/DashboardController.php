<?php

namespace App\Domain\Admin\Http\Controllers;

use App\Domain\Attendance\Services\RingkasanDashboardEvent;
use App\Domain\Audit\Models\ActivityLog;
use App\Domain\Exam\Services\PeringatanHariH;
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
 *
 * ---------------------------------------------------------------------
 * Peringatan hari-H, dan mengapa di sini
 * ---------------------------------------------------------------------
 * Beranda adalah satu-satunya laman konsol yang dibuka Admin tanpa alasan
 * tertentu - laman lain dibuka karena ia sudah tahu ada yang harus
 * dikerjakan di sana. Karena itu hal-hal yang TIDAK diketahuinya ditaruh di
 * sini, dan hanya saat waktunya dekat: lihat `PeringatanHariH`.
 */
class DashboardController
{
    public function __construct(
        private readonly PeringatanHariH $peringatan,
        private readonly RingkasanDashboardEvent $ringkasanEvent,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        Gate::authorize('viewAny', User::class);

        $school = School::findOrFail($user->school_id);

        // Event yang peringatannya diperiksa adalah event AKTIF di sesi -
        // bukan seluruh event sekolah. Memeriksa semuanya berarti beranda
        // mengerjakan pekerjaan panel tersendiri untuk data yang sudah
        // disaring di tempat lain.
        //
        // Yang diserahkan ke service adalah ID-nya, bukan barisnya: modul
        // Admin tidak boleh menyentuh `SchoolEvent` - lihat catatan
        // `PeringatanHariH::untukEventAktif()`.
        $aktif = $request->session()->get('event_aktif');

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

            // Kosong berarti tidak ada yang perlu dikerjakan - dan beranda
            // merender tidak ada apa-apa. Panel yang selalu berbunyi akan
            // berhenti dibaca.
            'peringatan' => $this->peringatan->untukEventAktif($aktif === null ? null : (int) $aktif),
            'event_ringkasan' => $this->ringkasanEvent->untukEventAktif($aktif === null ? null : (int) $aktif),
            'aktivitas' => ActivityLog::query()
                ->with('user:id,name')
                ->orderByDesc('id')
                ->limit(6)
                ->get()
                ->map(fn (ActivityLog $log): array => [
                    'id' => $log->id,
                    'aksi' => $log->action,
                    'aktor' => $log->user?->name ?? 'Sistem',
                    'waktu' => $log->created_at?->format('d M H:i') ?? '—',
                ])
                ->values()
                ->all(),
        ]);
    }
}

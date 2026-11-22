<?php

namespace App\Domain\Attendance\Http\Controllers;

use App\Domain\Attendance\Services\JadwalPublik;
use App\Domain\Tenancy\Models\School;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Jadwal & pengumuman event publik - `/jadwal/{slug}`, TANPA login.
 *
 * Menggunakan `schools.papan_slug` yang sama dengan `/papan`, `/pantau`,
 * dan `/wali`: satu sekolah satu alamat publik. Sekolah tanpa slug
 * mengembalikan 404 (rute tidak pernah lahir).
 */
class JadwalPublikController
{
    public function __construct(private readonly JadwalPublik $jadwal) {}

    public function tampilkan(string $slug): Response
    {
        $sekolah = School::query()->where('papan_slug', $slug)->first();

        if ($sekolah === null) {
            abort(404);
        }

        $isi = $this->jadwal->susun($sekolah);

        return Inertia::render('Publik/Jadwal', [
            'sekolah' => $sekolah->name,
            'slug' => $slug,
            'events' => $isi['events'] ?? [],
            'diperbarui' => $isi['diperbarui'] ?? now()->format('H:i'),
        ]);
    }
}

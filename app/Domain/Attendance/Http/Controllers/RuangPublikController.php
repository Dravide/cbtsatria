<?php

namespace App\Domain\Attendance\Http\Controllers;

use App\Domain\Attendance\Services\CekRuangPublik;
use App\Domain\Tenancy\Models\School;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cek ruang & nomor kursi publik - `/ruang/{slug}`, TANPA login.
 *
 * Menggunakan `schools.papan_slug` yang sama dengan `/papan`, `/pantau`,
 * `/jadwal`, dan `/wali`. Sekolah tanpa slug mengembalikan 404.
 */
class RuangPublikController
{
    public function __construct(private readonly CekRuangPublik $cekRuang) {}

    public function tampilkan(string $slug): Response
    {
        $sekolah = $this->sekolah($slug);

        return Inertia::render('Publik/Ruang', [
            'sekolah' => $sekolah->name,
            'slug' => $slug,
            'kelas' => $this->cekRuang->daftarKelas($sekolah),
            'hasil' => null,
            'diperbarui' => now()->format('H:i'),
        ]);
    }

    public function cari(Request $request, string $slug): Response
    {
        $sekolah = $this->sekolah($slug);

        $data = $request->validate([
            'kelas_id' => ['required', 'integer'],
            'nis' => ['required', 'string', 'max:40'],
        ], [], [
            'kelas_id' => 'kelas',
            'nis' => 'NIS',
        ]);

        $hasil = $this->cekRuang->cari($sekolah, (int) $data['kelas_id'], (string) $data['nis']);

        if ($hasil === null) {
            throw ValidationException::withMessages([
                'nis' => 'Data peserta tidak ditemukan. Periksa kembali kelas dan NIS Anda.',
            ]);
        }

        return Inertia::render('Publik/Ruang', [
            'sekolah' => $sekolah->name,
            'slug' => $slug,
            'kelas' => $this->cekRuang->daftarKelas($sekolah),
            'hasil' => $hasil,
            'diperbarui' => now()->format('H:i'),
        ]);
    }

    private function sekolah(string $slug): School
    {
        $sekolah = School::query()->where('papan_slug', $slug)->first();

        if ($sekolah === null) {
            abort(404);
        }

        return $sekolah;
    }
}

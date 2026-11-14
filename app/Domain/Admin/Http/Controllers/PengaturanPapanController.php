<?php

namespace App\Domain\Admin\Http\Controllers;

use App\Domain\Attendance\Services\PapanPengawas;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Tenancy\Models\School;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Menyalakan dan mematikan papan publik pengawas - `/admin/papan`.
 *
 * =====================================================================
 * Mengapa rute tersendiri, bukan kolom di form Profil sekolah
 * =====================================================================
 * Form Profil mengirim SELURUH kolomnya sekaligus; menambahkan saklar di
 * sana berarti setiap penyimpanan alamat sekolah ikut memutuskan apakah
 * papannya tayang. Dua keputusan yang tidak berhubungan di satu tombol
 * adalah dua keputusan yang suatu hari bertabrakan - dan yang kedua di
 * sini menayangkan nama pengawas ke internet.
 *
 * Karena itu dua aksi terpisah, dengan `PATCH` dan konfirmasi yang
 * menyebut akibatnya.
 *
 * =====================================================================
 * Slug dibangkitkan sekali, bukan mengikuti nama sekolah
 * =====================================================================
 * Sekolah yang berganti nama tidak boleh mengganti alamat papannya:
 * tautannya sudah ditempel di lorong dan dibagikan lewat grup. Karena itu
 * menyalakan papan pada sekolah yang SUDAH punya slug memakai slug lama.
 *
 * Mematikan papan TIDAK menghapus slugnya - ia hanya berhenti tayang.
 * Menghapusnya berarti alamat lama menjadi 404 selamanya, dan menyalakan
 * kembali akan menghasilkan alamat baru yang harus ditempel ulang.
 */
class PengaturanPapanController
{
    public function update(
        Request $request,
        PapanPengawas $papan,
        ActivityLogger $logger,
    ): RedirectResponse {
        $sekolah = $this->sekolah($request);

        Gate::authorize('update', $sekolah);

        $data = $request->validate([
            'aktif' => ['required', 'boolean'],
        ], [], ['aktif' => 'status papan publik']);

        $aktif = (bool) $data['aktif'];

        if ($aktif && $sekolah->papan_slug === null) {
            // Slug lahir HANYA di sini, dan hanya dari nama sekolah - tidak
            // pernah dari input. Alamat internet yang bisa ditentukan
            // peramban adalah alamat yang suatu hari diisi sesuatu yang
            // bukan nama sekolah.
            $sekolah->forceFill(['papan_slug' => $papan->slugUnik($sekolah)])->save();

            $logger->log('Menyalakan papan publik pengawas', $sekolah, [
                'slug' => $sekolah->papan_slug,
            ]);

            return back()->with('sukses', 'Papan publik dinyalakan. Alamatnya tercantum di laman ini.');
        }

        if ($aktif) {
            return back()->with('sukses', 'Papan publik sudah menyala.');
        }

        // Mematikan TIDAK mengosongkan slug - lihat catatan kelas ini.
        $logger->log('Mematikan papan publik pengawas', $sekolah, [
            'slug' => $sekolah->papan_slug,
        ]);

        // `papan_slug` di-null-kan supaya rutenya benar-benar mati: papan
        // yang "dimatikan" tetapi masih bisa dibuka dengan alamat lama
        // adalah papan yang tidak dimatikan.
        $sekolah->forceFill(['papan_slug' => null])->save();

        return back()->with('galat', 'Papan publik dimatikan. Alamat lamanya berhenti berlaku.');
    }

    private function sekolah(Request $request): School
    {
        return School::findOrFail($request->user()->school_id);
    }
}

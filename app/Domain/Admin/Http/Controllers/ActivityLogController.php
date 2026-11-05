<?php

namespace App\Domain\Admin\Http\Controllers;

use App\Domain\Audit\Models\ActivityLog;
use App\Support\TabelQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Log aktivitas - wireframe plate 24.
 *
 * ---------------------------------------------------------------------
 * Tidak ada tombol hapus, dan tidak akan pernah ada
 * ---------------------------------------------------------------------
 * Kalimat di bawah laman itu menyebut alasannya: membuka token, mengoreksi
 * presensi, dan membuka kunci ujian semuanya dapat dipersoalkan setelah ujian
 * selesai, jadi catatannya harus tetap ada meskipun Admin-nya sudah berganti.
 *
 * Laman ini adalah satu-satunya tempat di sistem yang sengaja tidak punya
 * jalan keluar untuk datanya. Bahkan Admin tertinggi pun tidak bisa
 * menghapusnya - bukan karena izinnya belum ditulis, tetapi karena izin itu
 * tidak akan pernah ditulis.
 *
 * Kalau kelak ada kebutuhan retensi (mis. pemangkasan setelah beberapa tahun
 * sesuai kebijakan), itu perintah terjadwal dengan kebijakan tertulis - bukan
 * tombol di antarmuka operasional.
 */
class ActivityLogController
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ActivityLog::class);

        // eager load aktornya: tanpa ini, 50 baris berarti 50 query tambahan
        // untuk nama yang tampil di setiap baris.
        $query = ActivityLog::query()->with('user:id,name');

        $halaman = TabelQuery::buat(
            $query,
            $request,
            cariDi: ['action', 'entity_type'],
            bolehDiurut: ['created_at', 'action'],
            penyaring: ['aksi' => 'action', 'aktor' => 'user_id'],
            urutBawaan: 'created_at',
            arahBawaan: 'desc',
        );

        return Inertia::render('Admin/Log', [
            'baris' => $halaman->through(fn (ActivityLog $log) => [
                'id' => $log->id,
                'jam' => $log->created_at?->format('d/m H:i'),
                'tanggal' => $log->created_at?->format('Y-m-d'),
                // "Sistem" untuk baris tanpa aktor: aksi otomatis (kunci ujian
                // karena ambang terlampaui) memang punya penyebab, dan
                // penyebabnya bukan orang.
                'aktor' => $log->user?->name ?? 'Sistem',
                'aksi' => $log->action,
                'objek' => $log->entity_type,
                'objek_id' => $log->entity_id,
                'ip' => $log->ip_address,
            ]),
            'filter' => [
                'cari' => $request->query('cari', ''),
                'aksi' => $request->query('aksi', ''),
                'aktor' => $request->query('aktor', ''),
            ],
            'jumlah' => $halaman->total(),
        ]);
    }
}

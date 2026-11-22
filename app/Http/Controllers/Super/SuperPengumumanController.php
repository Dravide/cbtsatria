<?php

namespace App\Http\Controllers\Super;

use App\Models\PlatformActivityLog;
use App\Models\PlatformAnnouncement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SuperPengumumanController
{
    public function index(): Response
    {
        $daftar = PlatformAnnouncement::query()
            ->with(['penulis' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'name')])
            ->orderByDesc('created_at')
            ->limit(30)
            ->get()
            ->map(fn (PlatformAnnouncement $a) => [
                'id' => (int) $a->id,
                'judul' => $a->title,
                'isi' => $a->body,
                'nada' => $a->nada,
                'target_roles' => $a->target_roles ?? ['admin', 'teacher'],
                'berlaku' => $a->expires_at === null || $a->expires_at->isFuture(),
                'berakhir' => $a->expires_at?->locale('id')->translatedFormat('d M Y, H:i'),
                'dibuat' => $a->created_at?->locale('id')->translatedFormat('d M Y, H:i'),
                'penulis' => $a->penulis?->name,
            ])
            ->values()
            ->all();

        return Inertia::render('Super/Pengumuman', [
            'pengumuman' => $daftar,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:1500'],
            'nada' => ['required', Rule::in([PlatformAnnouncement::NADA_INFO, PlatformAnnouncement::NADA_PENTING])],
            'expires_at' => ['nullable', 'date'],
        ], [], [
            'title' => 'judul pengumuman',
            'body' => 'isi pengumuman',
            'expires_at' => 'batas waktu tayang',
        ]);

        $item = PlatformAnnouncement::query()->create([
            'title' => trim($data['title']),
            'body' => trim($data['body']),
            'nada' => $data['nada'],
            'target_roles' => ['admin', 'teacher'],
            'expires_at' => ! empty($data['expires_at']) ? $data['expires_at'] : null,
            'created_by' => $request->user()->id,
        ]);

        PlatformActivityLog::catat(
            'Menayangkan pengumuman global',
            $request->user(),
            null,
            ['id' => $item->id, 'judul' => $item->title],
        );

        return back()->with('sukses', 'Pengumuman global berhasil ditayangkan ke seluruh konsol Admin & Guru.');
    }

    public function destroy(Request $request, int $pengumuman): RedirectResponse
    {
        $item = PlatformAnnouncement::query()->findOrFail($pengumuman);
        $judul = $item->title;
        $item->delete();

        PlatformActivityLog::catat(
            'Menghapus pengumuman global',
            $request->user(),
            null,
            ['judul' => $judul],
        );

        return back()->with('sukses', 'Pengumuman global dihapus.');
    }
}

<?php

namespace App\Http\Controllers\Super;

use App\Models\ErrorLog;
use App\Models\PlatformActivityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SuperGalatController
{
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $portal = trim((string) $request->query('portal', ''));
        $status = trim((string) $request->query('status', 'terbuka'));
        $http = trim((string) $request->query('http', ''));

        $query = ErrorLog::query()
            ->with([
                'sekolah:id,code,name',
                'pengguna' => fn ($u) => $u->withoutGlobalScopes()->select('id', 'name', 'username'),
            ])
            ->when($q !== '', function ($builder) use ($q) {
                $like = '%'.$q.'%';
                $builder->where(function ($sub) use ($like) {
                    $sub->where('code', 'like', $like)
                        ->orWhere('message', 'like', $like)
                        ->orWhere('exception_class', 'like', $like)
                        ->orWhere('url', 'like', $like)
                        ->orWhere('file', 'like', $like);
                });
            })
            ->when($portal !== '' && array_key_exists($portal, ErrorLog::LABEL_PORTAL), fn ($b) => $b->where('portal', $portal))
            ->when($status === 'terbuka', fn ($b) => $b->whereNull('resolved_at'))
            ->when($status === 'selesai', fn ($b) => $b->whereNotNull('resolved_at'))
            ->when($http !== '' && is_numeric($http), fn ($b) => $b->where('status_code', (int) $http));

        $items = $query
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (ErrorLog $e) => [
                'id' => (int) $e->id,
                'code' => $e->code,
                'portal' => $e->portal,
                'portal_label' => ErrorLog::LABEL_PORTAL[$e->portal] ?? $e->portal,
                'status_code' => $e->status_code,
                'exception_class' => class_basename($e->exception_class),
                'message' => $e->message,
                'file' => $e->file,
                'line' => $e->line,
                'url' => $e->url,
                'method' => $e->method,
                'sekolah' => $e->sekolah?->name,
                'pengguna' => $e->pengguna?->name,
                'user_role' => $e->user_role,
                'occurrences' => $e->occurrences,
                'last_seen_at' => $e->last_seen_at?->locale('id')->translatedFormat('d M Y, H:i:s'),
                'last_seen_human' => $e->last_seen_at?->locale('id')->diffForHumans(),
                'resolved' => $e->resolved_at !== null,
            ])
            ->values()
            ->all();

        $awalHari = now()->startOfDay();
        $awalPekan = now()->startOfWeek();

        return Inertia::render('Super/Galat', [
            'kpi' => [
                'terbuka' => ErrorLog::query()->whereNull('resolved_at')->count(),
                'hari_ini' => (int) ErrorLog::query()->where('last_seen_at', '>=', $awalHari)->sum('occurrences'),
                'siswa_hari_ini' => ErrorLog::query()
                    ->where('portal', ErrorLog::PORTAL_SSW)
                    ->whereNull('resolved_at')
                    ->where('last_seen_at', '>=', $awalHari)
                    ->count(),
                'selesai_pekan' => ErrorLog::query()
                    ->whereNotNull('resolved_at')
                    ->where('resolved_at', '>=', $awalPekan)
                    ->count(),
            ],
            'items' => $items,
            'filter' => [
                'q' => $q,
                'portal' => $portal,
                'status' => $status,
                'http' => $http,
            ],
            'portals' => ErrorLog::LABEL_PORTAL,
        ]);
    }

    public function show(ErrorLog $galat): Response
    {
        $galat->load([
            'sekolah:id,code,name',
            'pengguna' => fn ($u) => $u->withoutGlobalScopes()->select('id', 'name', 'username'),
            'penyelesai' => fn ($u) => $u->withoutGlobalScopes()->select('id', 'name', 'username'),
        ]);

        return Inertia::render('Super/GalatShow', [
            'galat' => [
                'id' => (int) $galat->id,
                'code' => $galat->code,
                'portal' => $galat->portal,
                'portal_label' => ErrorLog::LABEL_PORTAL[$galat->portal] ?? $galat->portal,
                'status_code' => $galat->status_code,
                'exception_class' => $galat->exception_class,
                'message' => $galat->message,
                'file' => $galat->file,
                'line' => $galat->line,
                'trace' => $galat->trace,
                'url' => $galat->url,
                'method' => $galat->method,
                'route_name' => $galat->route_name,
                'sekolah' => $galat->sekolah?->name,
                'pengguna' => $galat->pengguna?->name,
                'username' => $galat->pengguna?->username,
                'user_role' => $galat->user_role,
                'ip_address' => $galat->ip_address,
                'user_agent' => $galat->user_agent,
                'context' => $galat->context,
                'occurrences' => $galat->occurrences,
                'first_seen_at' => $galat->first_seen_at?->locale('id')->translatedFormat('d M Y, H:i:s'),
                'last_seen_at' => $galat->last_seen_at?->locale('id')->translatedFormat('d M Y, H:i:s'),
                'resolved_at' => $galat->resolved_at?->locale('id')->translatedFormat('d M Y, H:i:s'),
                'penyelesai' => $galat->penyelesai?->name,
                'resolution_note' => $galat->resolution_note,
            ],
        ]);
    }

    public function resolve(Request $request, ErrorLog $galat): RedirectResponse
    {
        $data = $request->validate([
            'resolution_note' => ['nullable', 'string', 'max:500'],
        ]);

        $galat->forceFill([
            'resolved_at' => now(),
            'resolved_by' => $request->user()?->id,
            'resolution_note' => $data['resolution_note'] ?? null,
        ])->save();

        PlatformActivityLog::catat(
            'Menandai galat '.$galat->code.' selesai',
            $request->user(),
            null,
            ['error_log_id' => $galat->id, 'code' => $galat->code],
            $request->ip(),
        );

        return back()->with('sukses', "Galat {$galat->code} ditandai selesai.");
    }

    public function prune(Request $request): RedirectResponse
    {
        $terhapus = ErrorLog::query()
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '<', now()->subDays(30))
            ->delete();

        PlatformActivityLog::catat(
            'Memangkas log galat selesai (>30 hari)',
            $request->user(),
            null,
            ['terhapus' => $terhapus],
            $request->ip(),
        );

        return back()->with('sukses', "{$terhapus} catatan galat lama dibersihkan.");
    }
}

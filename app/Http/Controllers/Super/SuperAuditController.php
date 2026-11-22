<?php

namespace App\Http\Controllers\Super;

use App\Models\PlatformActivityLog;
use Inertia\Inertia;
use Inertia\Response;

class SuperAuditController
{
    public function index(): Response
    {
        $logs = PlatformActivityLog::query()
            ->with([
                'aktor' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'name', 'username'),
                'sekolah:id,code,name',
            ])
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (PlatformActivityLog $log) => [
                'id' => (int) $log->id,
                'aksi' => $log->action,
                'aktor' => $log->aktor?->name ?? 'Sistem',
                'username' => $log->aktor?->username,
                'sekolah' => $log->sekolah?->name,
                'ip' => $log->ip_address,
                'metadata' => $log->metadata,
                'waktu' => $log->created_at?->locale('id')->translatedFormat('d M Y, H:i:s'),
            ])
            ->values()
            ->all();

        return Inertia::render('Super/Audit', [
            'logs' => $logs,
        ]);
    }
}

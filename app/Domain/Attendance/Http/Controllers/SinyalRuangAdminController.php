<?php

namespace App\Domain\Attendance\Http\Controllers;

use App\Domain\Attendance\Models\RoomSignal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Penanganan sinyal darurat infrastruktur oleh Admin/Panitia.
 */
class SinyalRuangAdminController
{
    public function selesaikan(Request $request, RoomSignal $sinyal): RedirectResponse
    {
        $sinyal->forceFill([
            'status' => RoomSignal::STATUS_DITANGANI,
            'ditangani_oleh' => $request->user()?->id,
            'ditangani_at' => now(),
        ])->save();

        return back()->with('sukses', 'Sinyal kendala ruang ditandai sudah ditangani.');
    }
}

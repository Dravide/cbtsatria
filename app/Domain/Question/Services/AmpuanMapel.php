<?php

namespace App\Domain\Question\Services;

use App\Domain\Academic\Models\TeachingAssignment;
use App\Domain\Identity\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Satu-satunya tempat yang memutuskan "Guru ini mengampu mapel itu atau tidak".
 *
 * =====================================================================
 * Mengapa diekstrak, bukan disalin
 * =====================================================================
 * Aturan ini sudah dipakai jalur simpan soal, dan sejak Generate AI ia
 * dipakai jalur kedua. Menyalin lima barisnya ke controller kedua berarti
 * dua tempat yang sepakat hari ini dan bisa berbeda diam-diam besok - dan
 * yang berbeda di sini bukan pesan galat, melainkan IZIN: satu jalur yang
 * lupa memeriksa berarti Guru menyusun soal mapel yang tidak ia ampu.
 *
 * =====================================================================
 * 422, bukan 403
 * =====================================================================
 * Yang salah adalah NILAI yang dikirim, bukan izinnya: Guru memang boleh
 * menyusun soal, hanya tidak untuk mapel itu. Pesannya muncul di sebelah
 * field Mata pelajaran, tempat Guru bisa memperbaikinya.
 *
 * Admin dilewatkan. PRD §3.1 memberinya "lihat semua", dan pemasangan paket
 * ke event dikerjakan dari sisi Admin - gerbang menulisnya tetap
 * QuestionPolicy::create() yang menolak Admin, dan itu di tempat lain.
 */
class AmpuanMapel
{
    public const PESAN = 'Anda tidak mengampu mata pelajaran tersebut.';

    /**
     * @throws HttpException 422
     */
    public function pastikan(User $user, int $mapelId): void
    {
        if ($user->role->isAdmin()) {
            return;
        }

        $ada = TeachingAssignment::query()
            ->where('teacher_id', $user->id)
            ->where('subject_id', $mapelId)
            ->exists();

        if (! $ada) {
            abort(422, self::PESAN);
        }
    }
}

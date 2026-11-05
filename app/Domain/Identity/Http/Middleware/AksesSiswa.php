<?php

namespace App\Domain\Identity\Http\Middleware;

use App\Domain\Identity\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Penjaga konsol Siswa.
 *
 * ---------------------------------------------------------------------
 * Mengapa terpisah dari `auth`, padahal Siswa satu-satunya yang diloloskan
 * ---------------------------------------------------------------------
 * `auth` saja akan meloloskan Admin, Guru, dan Pengawas ke `/siswa`. Bagi
 * Admin dan Guru itu tidak berbahaya - batasnya 404 lewat penempatan yang
 * bukan miliknya - tetapi bagi Pengawas ia berbahaya dengan cara yang halus:
 * pengawas memegang tautan ruang, dan `pengawasRuang` di kepala layar akan
 * membuat laman siswa merender kepala pengawas. Satu orang yang membuka
 * ponselnya sendiri setelah memegang tautan ruang akan melihat layar yang
 * menyebut R-01 di atas jadwal ujian anak.
 *
 * Karena itu gerbangnya menyebut perannya, bukan hanya keberadaan akun.
 *
 * ---------------------------------------------------------------------
 * Yang dijawab gerbang ini, dan yang TIDAK
 * ---------------------------------------------------------------------
 * Gerbang ini menjawab "apakah laman siswa ini untukmu". Ia TIDAK menjawab
 * "apakah penempatan ini milikmu" - itu dijawab setiap controller lewat
 * `student_id`, dan jawabannya 404. Pembagiannya sama dengan pola isolasi
 * tenant: keanggotaan peran bukan rahasia (403), kepemilikan baris rahasia
 * (404).
 */
class AksesSiswa
{
    public function handle(Request $request, Closure $next): Response
    {
        $pengguna = Auth::user();

        if ($pengguna === null) {
            return redirect()->route('login');
        }

        if ($pengguna->role !== Role::Student) {
            abort(403, 'Konsol ini hanya untuk Siswa.');
        }

        return $next($request);
    }
}

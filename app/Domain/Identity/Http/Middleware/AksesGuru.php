<?php

namespace App\Domain\Identity\Http\Middleware;

use App\Domain\Identity\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Penjaga konsol Guru.
 *
 * ---------------------------------------------------------------------
 * Mengapa middleware terpisah, bukan `auth` saja
 * ---------------------------------------------------------------------
 * Grup `/guru` tidak boleh dibuka siapa pun yang punya akun. Siswa yang
 * mengetik `/guru/bank-soal` di peramban tidak boleh menerima daftar soal
 * ujiannya sendiri - dan izin membacanya bukan diperiksa di controller,
 * melainkan di sini, SEBELUM satu controller pun berjalan.
 *
 * Alasannya bukan kerapian: controller yang menerima permintaan lalu menolak
 * tetap membangun kueri, memuat model, dan menulis log - dan satu di antara
 * jalur itu selalu lupa diperiksa saat ditambahkan.
 *
 * ---------------------------------------------------------------------
 * Admin DILOLOSKAN, dan itu disengaja
 * ---------------------------------------------------------------------
 * PRD §3.1 memberi Admin "Tidak" untuk bank soal - ia tidak menyusun soal.
 * Tetapi Admin harus bisa MEMBACA-nya: ia yang memasang paket ke mapel event
 * (plate 15), dan memasang paket yang tidak bisa ia buka isinya adalah
 * pekerjaan yang menuntut iman.
 *
 * Yang membedakan keduanya bukan gerbang ini melainkan QuestionPolicy:
 * `create()`, `update()`, dan `delete()` menolak Admin. Gerbang di sini hanya
 * menjawab "boleh masuk konsolnya atau tidak" - dan jawabannya ya untuk
 * Admin, karena seluruh laman di dalamnya punya pemeriksaan sendiri.
 *
 * Pengawas dan Siswa TIDAK diloloskan: tidak satu pun laman di `/guru` yang
 * punya arti bagi mereka, dan meloloskannya berarti membuka bank soal ke
 * seluruh pengguna yang punya akun.
 *
 * ---------------------------------------------------------------------
 * Jawabannya 403, bukan 404
 * ---------------------------------------------------------------------
 * Berbeda dari isolasi tenant, di sini 403 memang benar: halaman `/guru`
 * BUKAN rahasia - keberadaannya diketahui setiap Guru, dan berpura-pura ia
 * tidak ada berarti menyembunyikan menu yang setiap orang tahu ada. Yang
 * ditolak adalah AKSESNYA.
 */
class AksesGuru
{
    public function handle(Request $request, Closure $next): Response
    {
        $pengguna = Auth::user();

        if ($pengguna === null) {
            return redirect()->route('login');
        }

        if (! in_array($pengguna->role, [Role::Admin, Role::Teacher], true)) {
            abort(403, 'Konsol ini hanya untuk Guru dan Admin.');
        }

        return $next($request);
    }
}

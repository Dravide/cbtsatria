<?php

namespace App\Domain\Attendance\Http\Controllers\Siswa;

use App\Domain\Academic\Services\PenempatanKelas;
use App\Domain\Attendance\Http\Requests\GantiSandiRequest;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Identity\Models\User;
use App\Support\PembatasLaju;
use App\Support\SesiPengguna;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Akun saya - tab keempat konsol siswa.
 *
 * =====================================================================
 * Mengapa laman ini ada, padahal tidak digambar siapa pun
 * =====================================================================
 * `wireframe-peran.html` menggambar tab "Akun" di tabbar siswa tiga kali,
 * tetapi tidak pernah menggambar LAMANNYA. Isinya karena itu ditentukan
 * pemilik produk, bukan diterka dari wireframe:
 *
 *   1. identitas (nama, username, kelas, NIS, NISN, sekolah);
 *   2. ganti sandi mandiri;
 *   3. keluar.
 *
 * PRD §4.3 hanya menyebut "Sistem menyediakan logout dan reset password" -
 * dan sampai laman ini ada, "reset password" berarti ADMIN, lewat laman
 * Pengguna. Tidak ada satu pun jalur self-service di seluruh aplikasi ini.
 *
 * =====================================================================
 * Sandi bawaan: PERINGATAN, bukan penegakan
 * =====================================================================
 * Setiap akun yang dibuat tanpa sandi memakai `User::SANDI_BAWAAN`. Laman ini
 * memberi tahu pemiliknya, dan TIDAK memblokir apa pun - keputusan yang
 * dikunci pemilik produk, dengan alasan yang layak ditulis: anak tidak boleh
 * tertahan di luar ruang ujian karena urusan sandi. Karena itu tidak ada
 * gerbang di `KelayakanUjian`, dan tidak ada kolom `must_change_password`.
 *
 * Yang dibaca di sini adalah `Hash::check` atas sandi bawaan itu - satu kali
 * per buka laman, ~100 ms bcrypt (`BCRYPT_ROUNDS=4` di test), di laman
 * pribadi yang tidak ada di jalur panas milik siapa pun. Tidak ada cara jujur
 * lain untuk mengetahuinya tanpa kolom baru: bcrypt bersalt acak, sehingga
 * dua akun bersandi sama pun punya hash yang berbeda. Tidak di-memoize di
 * sesi - state tambahan yang bisa basi.
 *
 * =====================================================================
 * "Sandi" di sini, "Password" di layar login - dan itu disengaja
 * =====================================================================
 * Laman ini memakai kosakata "Sandi"; layar login tetap "Password". Anak
 * yang baru pertama masuk melihat "Password" (kata yang dikenalnya dari
 * ponselnya), lalu di sini melihat "Sandi" sebagai hal yang sama. Ketidak-
 * samaan itu dicatat di sini supaya terbaca sebagai pilihan, bukan sebagai
 * terjemahan yang setengah jalan.
 *
 * =====================================================================
 * Tanpa Gate, dan mengapa `update` HARAM dipakai di sini
 * =====================================================================
 * Rutenya tidak memuat `{user}` - `$request->user()` sudah dirinya sendiri,
 * dan middleware `siswa` menutup peran lain dengan 403 sebelum sampai ke
 * sini. Yang tidak boleh dipakai adalah `UserPolicy::update()`: ia
 * Admin-only, jadi seorang siswa yang mengganti sandinya SENDIRI akan
 * menerima 403 dari policy yang seharusnya melindunginya.
 *
 * Modulnya Attendance, bukan Identity, karena seluruh keluarga `/siswa/*`
 * hidup di folder ini dan berbagi `SiswaDasar`; satu laman konsol siswa di
 * modul lain memecah keluarga itu.
 */
class AkunSiswaController extends SiswaDasar
{
    public function index(Request $request, PenempatanKelas $penempatan): Response
    {
        $user = $request->user();
        $profil = $user->studentProfile;

        return Inertia::render('Siswa/Akun', [
            'nama' => $user->name,
            'username' => $user->username,
            'nis' => $profil?->nis,
            'nisn' => $profil?->nisn,
            'sekolah' => $user->school?->name,
            // Kelas dibaca dari keanggotaan kelas, BUKAN dari penempatan ujian:
            // laman ini tidak terikat event, dan siswa yang belum ditempatkan
            // di ujian mana pun tetap punya kelas.
            'kelas' => $penempatan->kelasSekarang((int) $user->id)?->name,
            'sandi_bawaan' => Hash::check(User::SANDI_BAWAAN, (string) $user->password),
        ]);
    }

    /**
     * Simpan sandi baru, lalu putuskan sesi lain.
     *
     * ---------------------------------------------------------------------
     * Urutan empat langkah terakhir BUKAN selera
     * ---------------------------------------------------------------------
     * 1. `forceFill(...)->save()` - cast `hashed` pada model yang meng-hash.
     *    `forceFill`, bukan `update`: `password` ada di `#[Fillable]`, tetapi
     *    jalur ini sengaja tidak menerima mass-assignment dari luar.
     * 2. `PembatasLaju::bersihkan()` - percobaan yang salah sudah menghabiskan
     *    jatah, dan pemilik sandi yang akhirnya benar tidak boleh tetap
     *    terkunci pada percobaan berikutnya. Kuncinya dibersihkan lewat helper
     *    itu karena `ThrottleRequests` meng-hash kuncinya (md5).
     * 3. `session()->regenerate()` SEBELUM baris 4 - regenerate menutup session
     *    fixation dan MENGGANTI id sesi, sehingga id yang dibaca sesudahnya
     *    adalah id yang benar. Membacanya lebih dulu berarti menghapus baris
     *    sesi yang sedang dipakai.
     * 4. `lupakanSesiLain()` - sesi di perangkat lain diputus. Alasan
     *    sebenarnya bukan kebersihan: anak yang mengganti sandi hampir selalu
     *    karena sandinya diketahui orang lain, dan membiarkan sesi orang itu
     *    hidup berarti penggantiannya tidak mengubah apa pun.
     */
    public function gantiSandi(GantiSandiRequest $request, ActivityLogger $logger): RedirectResponse
    {
        $user = $request->user();

        $user->forceFill(['password' => $request->validated('password')])->save();

        PembatasLaju::bersihkan(PembatasLaju::SANDI, PembatasLaju::kunciSandi($user->id));

        $request->session()->regenerate();

        SesiPengguna::lupakanSesiLain((int) $user->id, (string) $request->session()->getId());

        $logger->log('Mengganti sandi sendiri', $user);

        return back()->with('sukses', 'Sandi berhasil diganti.');
    }
}

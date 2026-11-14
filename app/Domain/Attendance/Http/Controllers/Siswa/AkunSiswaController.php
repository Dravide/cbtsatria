<?php

namespace App\Domain\Attendance\Http\Controllers\Siswa;

use App\Domain\Academic\Services\PenempatanKelas;
use App\Domain\Attendance\Http\Requests\GantiSandiRequest;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Event\Models\EventRoom;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Services\NomorKursi;
use App\Domain\Exam\Services\PetaKursiEvent;
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
 *   2. ganti sandi mandiri - di laman TERPISAH sejak 2026-11-14;
 *   3. keluar.
 *
 * PRD §4.3 hanya menyebut "Sistem menyediakan logout dan reset password" -
 * dan sampai laman ini ada, "reset password" berarti ADMIN, lewat laman
 * Pengguna. Tidak ada satu pun jalur self-service di seluruh aplikasi ini.
 *
 * =====================================================================
 * Ganti sandi dipisah ke `/siswa/akun/sandi` - permintaan 2026-11-14
 * =====================================================================
 * Sampai saat itu laman Akun memuat identitas DAN formulir tiga kolom di
 * bawahnya. Bentuk itu membuat laman yang dibuka untuk "nomor induk saya
 * berapa" berisi tiga kolom sandi di setengah bawahnya, dan formulir yang
 * selalu terlihat adalah formulir yang sesekali tersentuh tanpa niat -
 * sementara yang tersentuh tanpa niat di sini adalah sandi.
 *
 * Sekarang Akun hanya memuat identitas dan satu tautan ke laman sandi. Yang
 * DIPINDAH hanya formulirnya; perilaku, validasi, pembatas laju, dan urutan
 * empat langkah di `gantiSandi()` tidak berubah satu baris pun.
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
    public function index(Request $request): Response
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
            'kelas' => app(PenempatanKelas::class)->kelasSekarang((int) $user->id)?->name,
            'sandi_bawaan' => Hash::check(User::SANDI_BAWAAN, (string) $user->password),
            'tempat_ujian' => $this->tempatUjian((int) $user->id),
        ]);
    }

    /**
     * Tempat ujian BERIKUTNYA, beserta denah kursinya - ditambahkan 2026-11-14.
     *
     * =====================================================================
     * Mengapa di Akun, bukan di beranda
     * =====================================================================
     * Beranda sudah memuat ruang dan kursi, tetapi ia menghapusnya begitu
     * ujiannya selesai - dan yang dibuka anak sambil berjalan ke ruangnya
     * adalah laman yang menyimpan tempat ujian BERIKUTNYA sepanjang hari-H.
     *
     * =====================================================================
     * Denah dikirim bersama tempatnya, bukan di rute kedua
     * =====================================================================
     * Panel posisi kursi dibuka dari tombol di blok ini, dan ia memuat
     * paling banyak empat puluh sel. Rute kedua untuk empat puluh angka
     * berarti satu perjalanan yang harus dijawab saat anak berdiri di depan
     * pintu, di jaringan sekolah yang sedang dipakai tiga ratus orang.
     * Payload laman Akun sudah cukup kecil untuk menampungnya.
     *
     * =====================================================================
     * Yang TIDAK dikirim: nama penghuni lain
     * =====================================================================
     * Denah ini untuk mencari kursi sendiri, bukan untuk tahu siapa duduk
     * di sebelah. `sel` membawa `peserta` lengkap - nama, NIS, kelas - dan
     * seluruhnya DIBUANG di sini, menyisakan kursi yang terisi atau tidak.
     * Panel yang memuat nama anak lain akan difoto lalu disebar, dan nama
     * anak di layar ponsel anak lain adalah kebocoran yang tidak dibutuhkan
     * fitur ini.
     *
     * @return array<string, mixed>|null
     */
    private function tempatUjian(int $siswaId): ?array
    {
        // =================================================================
        // TIDAK disaring menurut jam sesi, dan itu perbaikan 2026-11-14
        // =================================================================
        // Versi pertama menambahkan `whereHas('session', hard_end_at >= now())`,
        // dan akibatnya blok ini HILANG seketika sesinya lewat - termasuk pada
        // anak yang baru selesai mengerjakan ujiannya sendiri, yang justru
        // sedang berdiri di ruang itu. Kursi tidak kedaluwarsa bersama jam
        // sesi: ruang dan nomor kursi adalah tempat ujian yang ditetapkan
        // untuk anak itu, dan pertanyaan "saya duduk di mana" tetap sah
        // sesudah ujiannya berakhir.
        //
        // Yang dipilih: penempatan dengan sesi TERDEKAT YANG BELUM BERAKHIR;
        // bila tidak ada, yang paling akhir. Laman menandai keadaan itu
        // (`selesai`) supaya tidak terbaca sebagai ajakan datang.
        $semua = ExamParticipant::query()
            ->where('student_id', $siswaId)
            ->where('status', ExamParticipant::STATUS_TERDAFTAR)
            ->with([
                'exam.subject:id,name',
                'session',
                'room.eventRoom.room:id,code,name,location,capacity',
            ])
            ->get()
            // Penempatan TANPA ruang tidak bisa menunjuk kursi apa pun -
            // ia belum ditempatkan, dan itu bukan "tempat ujian".
            ->filter(fn (ExamParticipant $p) => $p->room !== null);

        if ($semua->isEmpty()) {
            return null;
        }

        $sekarang = now();

        $peserta = $semua
            ->filter(fn (ExamParticipant $p) => $p->session !== null && $p->session->hard_end_at->gte($sekarang))
            ->sortBy(fn (ExamParticipant $p) => $p->session?->access_opens_at?->timestamp ?? PHP_INT_MAX)
            ->first()
            // Tidak ada yang belum berakhir: pakai yang TERAKHIR, supaya blok
            // ini tetap menjawab "tadi saya duduk di mana" alih-alih hilang
            // tanpa jejak.
            ?? $semua->sortByDesc(fn (ExamParticipant $p) => $p->session?->access_opens_at?->timestamp ?? PHP_INT_MIN)->first();

        if ($peserta === null) {
            return null;
        }

        $kursi = $this->nomorKursi($peserta);
        $eventRoom = $peserta->room->eventRoom;
        $sesi = $peserta->session;

        return [
            'peserta_id' => (int) $peserta->id,
            'ruang' => $eventRoom?->room?->code,
            'ruang_nama' => $eventRoom?->room?->name,
            'lokasi' => $eventRoom?->room?->location,
            'kursi' => $kursi,
            'meja' => $kursi === null ? null : NomorKursi::meja($kursi),
            'baris' => $kursi === null ? null : intdiv(NomorKursi::meja($kursi) - 1, NomorKursi::MEJA_PER_BARIS) + 1,
            'kolom' => $kursi === null ? null : ((NomorKursi::meja($kursi) - 1) % NomorKursi::MEJA_PER_BARIS) + 1,
            'mapel' => $peserta->exam?->subject?->name,
            'ujian' => $peserta->exam?->title,
            'sesi' => $sesi?->label(),
            'jam' => $sesi === null
                ? null
                : $sesi->access_opens_at->format('H:i').'–'.$sesi->hard_end_at->format('H:i'),
            'tanggal' => $sesi?->access_opens_at?->format('d M'),
            // Apakah sesinya sudah berakhir. Dipakai laman untuk mengubah
            // kalimatnya - "tempat ujian" pada sesi yang sudah lewat terbaca
            // sebagai ajakan datang ke ruang yang sudah dikosongkan.
            'selesai' => $sesi !== null && $sesi->hard_end_at->lt(now()),
            'denah' => $this->denah($eventRoom, $kursi),
        ];
    }

    /**
     * Seluruh kursi ruang itu, TANPA nama penghuni.
     *
     * Dihitung `PetaKursiEvent::sel()` - sumber yang sama dengan denah Admin,
     * tablo PDF, dan papan pengawas. Denah yang menyusun ulang geometrinya
     * sendiri adalah denah yang menaruh anak di kursi orang lain.
     *
     * @return array<string, mixed>
     */
    private function denah(?EventRoom $eventRoom, ?int $kursi): array
    {
        if ($eventRoom === null) {
            return [
                'kapasitas' => 0,
                'kursi_per_meja' => NomorKursi::KURSI_PER_MEJA,
                'meja_per_baris' => NomorKursi::MEJA_PER_BARIS,
                'sel' => [],
            ];
        }

        $baris = app(PetaKursiEvent::class)->sel((int) $eventRoom->id, $eventRoom->kapasitasEfektif());

        return [
            'kapasitas' => $eventRoom->kapasitasEfektif(),
            'kursi_per_meja' => NomorKursi::KURSI_PER_MEJA,
            'meja_per_baris' => NomorKursi::MEJA_PER_BARIS,
            'sel' => collect($baris)->map(fn (array $s) => [
                'kursi' => (int) $s['kursi'],
                'meja' => (int) $s['meja'],
                'baris' => (int) $s['baris'],
                'kolom' => (int) $s['kolom'],
                'terisi' => $s['peserta'] !== null,
                // `saya` dibandingkan dengan nomor kursi yang SAMA yang
                // ditampilkan di blok di atasnya - dua angka yang menjawab
                // "kursi mana saya" tidak boleh berasal dari dua hitungan.
                'saya' => $kursi !== null && (int) $s['kursi'] === $kursi,
            ])->all(),
        ];
    }

    /**
     * Laman ganti sandi - dipisah dari Akun sejak 2026-11-14.
     *
     * ---------------------------------------------------------------------
     * `sandi_bawaan` tetap dikirim, dan itu bukan pengulangan yang lupa dibuang
     * ---------------------------------------------------------------------
     * Laman sandi memakainya untuk kalimat yang berbeda dari laman Akun: yang
     * pertama menjelaskan mengapa sebaiknya diganti sekarang, yang kedua
     * memberi satu kalimat pengingat sesudah sandinya diganti. Kalimat itu
     * hanya jujur bila ia tahu keadaan sandi yang sedang berlaku.
     */
    public function sandi(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Siswa/Sandi', [
            'nama' => $user->name,
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

        // Kembali ke LAMAN AKUN, bukan ke laman sandi: sesudah sandinya
        // berganti, laman sandi tidak punya apa pun lagi untuk dikerjakan -
        // dan `back()` akan mengembalikan anak ke formulir kosong tepat
        // sesudah ia menekan Simpan.
        return redirect()
            ->route('siswa.akun')
            ->with('sukses', 'Sandi berhasil diganti.');
    }
}

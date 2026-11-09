<?php

namespace App\Http\Middleware;

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Attendance\Services\KonteksRuang;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Tenancy\Models\School;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * Root Blade view yang membungkus setiap respons Inertia.
     */
    protected $rootView = 'app';

    /**
     * Data yang dikirim ke setiap halaman.
     *
     * Sengaja sesedikit mungkin. Yang ada di sini dikirim ke SEMUA pengguna
     * setiap permintaan, jadi menaruh daftar pengguna atau apa pun yang
     * bersifat tenant di sini berarti membocorkannya ke setiap halaman.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),

            // flash dipakai untuk umpan balik "tersimpan" - anatomi wireframe
            // butir 7: setiap aksi yang mengubah data menampilkan status singkat.
            'flash' => [
                'sukses' => fn () => $request->session()->get('sukses'),
                'galat' => fn () => $request->session()->get('galat'),

                // Kode pengawas TIDAK lagi lewat flash. Ia disimpan
                // terenkripsi di baris kepanitiaan dan SELALU dikirim sebagai
                // prop laman Kepanitiaan, karena Admin mencetaknya menjadi
                // kartu pengawas dan harus bisa membacanya kapan saja - bukan
                // sekali saja pada respons yang menerbitkannya. Lihat
                // EventCommitteeController::index() dan PenerbitKodePengawas.

                // Token sesi hidup TEPAT satu respons: ia hanya ditampilkan
                // sekali untuk dibacakan di depan kelas, dan menyimpannya di
                // props berarti ia ikut terkirim ke setiap laman berikutnya -
                // termasuk laman yang tidak berhubungan dengan ujian itu.
                //
                // Ada DUA jalur yang mengisinya: penerbitan token (Admin, belum
                // tercatat sebagai pembukaan) dan pembacaan berwenang (Pengawas,
                // tercatat satu baris di exam_token_views). Keduanya berakhir di
                // kunci yang sama karena yang dibutuhkan layar sama: kode enam
                // digit yang bisa dibacakan.
                'kode_token' => fn () => $request->session()->get('kode_token'),
                'token_sesi' => fn () => $request->session()->get('token_sesi'),
            ],

            'auth' => [
                'pengguna' => $user ? [
                    'id' => $user->id,
                    'nama' => $user->name,
                    'peran' => $user->role->value,
                    'peran_label' => $user->role->label(),
                ] : null,
            ],

            // Identitas pemegang TAUTAN RUANG, bila permintaan ini datang
            // lewat pintu itu - dan hanya nama ruang serta sekolahnya.
            //
            // Dipakai kepala kerangka ponsel: pengawas harus selalu bisa
            // melihat ruang mana yang sedang dibukanya, karena satu ponsel
            // bisa dipakai dua orang bergantian di dua ruang, dan laman yang
            // tidak menyebut ruangnya membuat orang salah menandai presensi
            // tanpa sadar.
            //
            // Sengaja hanya empat nilai: ruang, sekolah, tautannya, dan nama
            // pengawas yang membukanya. Menaruh daftar peserta di sini
            // berarti mengirim seluruh daftar kursi ke SETIAP laman, termasuk
            // laman token.
            //
            // Yang keempat ditambahkan 2026-11-07, dan ia tidak melanggar
            // batas yang sama: yang dijaga batas itu adalah hal-hal tentang
            // ORANG DI DALAM RUANGAN (daftar siswa, jumlah kursi). Nama
            // pengawas bukan salah satunya - ia justru pemegang ponsel yang
            // sedang membaca laman ini, dan satu ponsel bisa dipakai dua
            // orang bergantian.
            'pengawasRuang' => fn () => $this->pengawasRuang(),

            // Tahun ajaran aktif dikirim ke setiap laman karena bar atas
            // konsol menampilkannya di semua laman (wireframe plate 07).
            // Satu query, dan hanya untuk pengguna yang sudah masuk - tamu
            // di layar login tidak membayarnya.
            //
            // Sengaja hanya NAMA, bukan objeknya: yang dibutuhkan bar atas
            // adalah label "TA 2026/2027", dan mengirim seluruh model berarti
            // mengirim kolom yang tidak dipakai ke setiap respons.
            'tahunAktif' => fn () => $user
                ? AcademicYear::query()->where('is_active', true)->value('name')
                : null,

            // Event yang terakhir dibuka - anatomi wireframe butir 2.
            //
            // Dikirim lewat sesi, bukan dibaca ulang dari URL oleh peramban.
            // Id di URL tetap ada dan tetap dipakai untuk memuat laman;
            // yang ini menjawab pertanyaan berbeda: "sidebar kelompok event
            // harus muncul atau tidak, dan menyebut event yang mana".
            //
            // Hanya nama dan status, bukan modelnya. Yang dibutuhkan bar atas
            // adalah label "Event: UAS Ganjil", dan mengirim seluruh baris
            // berarti mengirim kolom yang tidak dipakai ke setiap respons.
            //
            // null berarti "tidak ada event yang sedang dikerjakan", dan
            // sidebar kelompok event DISEMBUNYIKAN - bukan ditampilkan
            // kosong. Kelompok yang tampil tanpa isi adalah kelompok yang
            // membuat Admin mengira ada yang rusak.
            'eventAktif' => fn () => $this->eventAktif($request),
        ];
    }

    /**
     * Ruang yang sedang dipegang pemegang tautan, atau null bila pintunya
     * lewat akun.
     *
     * Lewat akun, ruangnya BANYAK dan bukan satu - sehingga yang benar adalah
     * null, bukan "ruang pertama". Memilih satu berarti kepala laman
     * menyebut ruang yang belum tentu sedang dibuka.
     *
     * @return array<string, mixed>|null
     */
    private function pengawasRuang(): ?array
    {
        $tautan = KonteksRuang::tautanSesi();

        if ($tautan === null) {
            return null;
        }

        $data = Session::get(KonteksRuang::KUNCI_SESI);

        $ruang = ExamSessionRoom::query()
            ->with(['eventRoom.room:id,code,name', 'session.exam.event:id,name'])
            ->find($data['exam_session_room_id'] ?? null);

        if ($ruang === null) {
            return null;
        }

        $sekolah = School::query()->find($tautan->school_id);

        return [
            'ruang_id' => $ruang->id,
            'ruang' => $ruang->eventRoom?->room?->code,
            'ruang_nama' => $ruang->eventRoom?->room?->name,
            'sesi' => $ruang->session?->label(),
            'event' => $ruang->session?->exam?->event?->name,
            'sekolah' => $sekolah?->name,
            'slug' => $tautan->slug,
            // Nama pengawas yang membuka pintu ini, sejak 2026-11-07.
            // Sebelumnya null - dulu kodenya milik RUANG, jadi tidak ada
            // orang yang bisa disebut. Kini kodenya milik orang, sehingga
            // kepala laman bisa menyebut siapa yang sedang berdiri di sini
            // dan bukan hanya ruang mana.
            'pengawas' => KonteksRuang::penggunaSesi()?->name,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function eventAktif(Request $request): ?array
    {
        $id = $request->session()->get('event_aktif');

        if (! $id) {
            return null;
        }

        // Lewat scope tenant: id dari sesi tidak dipercaya begitu saja. Sesi
        // yang dibuat sebelum pengguna berpindah akun - mis. di komputer
        // bersama di ruang guru - akan menyebut event sekolah lain, dan
        // scope-lah yang menolaknya tanpa satu baris pemeriksaan tambahan.
        $event = SchoolEvent::query()->find($id);

        if (! $event) {
            // Sekaligus membersihkan sesi yang menunjuk event yang sudah
            // tidak ada. Membiarkannya berarti setiap permintaan berikutnya
            // mengulang pencarian yang sama dan gagal dengan cara yang sama.
            $request->session()->forget('event_aktif');

            return null;
        }

        return [
            'id' => $event->id,
            'kode' => $event->code,
            'nama' => $event->name,
            'status' => $event->status->value,
            'status_label' => $event->status->label(),
        ];
    }
}

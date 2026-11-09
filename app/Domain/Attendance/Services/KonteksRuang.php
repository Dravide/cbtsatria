<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Exam\Models\SupervisionAssignment;
use App\Domain\Facility\Models\RoomEntryLink;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * Siapa yang sedang berdiri di ruang ini, dan atas dasar apa.
 *
 * =====================================================================
 * INI BAGIAN TERPENTING FASE 6
 * =====================================================================
 *
 * ---------------------------------------------------------------------
 * Dua pintu, satu aturan
 * ---------------------------------------------------------------------
 * Ada DUA cara masuk ke ruang yang sama, dan keduanya harus tunduk pada
 * aturan yang sama:
 *
 *   AKUN        Pengawas yang sudah login dengan penugasan pada ruang itu,
 *               atau Admin sekolahnya.
 *   TAUTAN      Pemegang tautan pintu `/p/{slug}` yang sudah mengetik kode
 *               enam angka. Ia tidak punya akun sama sekali.
 *
 * Kalau tiap controller memeriksa sendiri-sendiri, aturannya akan benar di
 * laman presensi dan salah di laman token - dan yang salah itu adalah
 * kebocoran, bukan galat. Karena itu setiap aksi controller dimulai dengan
 * satu baris yang sama: `KonteksRuang::dari($ruang)`.
 *
 * ---------------------------------------------------------------------
 * Isolasi ruang dijalankan SEBELUM policy, bukan oleh policy
 * ---------------------------------------------------------------------
 * Blueprint :466 dan PRD §10.3: "Pengawas hanya melihat ruangnya."
 *
 * Bentuk penegakannya mengikuti pola yang sudah berlaku untuk tenant:
 * ruangnya di-resolve LEWAT penugasan itu sendiri, sehingga ruang yang bukan
 * miliknya tampak TIDAK ADA.
 *
 *     Bukan:  cari ruang, lalu periksa penugasan   -> 403, membocorkan
 *             bahwa ruangnya ada.
 *     Tetapi: cari penugasan pada ruang itu        -> ModelNotFound -> 404.
 *
 * Karena itu kelas ini melempar ModelNotFoundException, bukan
 * AuthorizationException. Konsekuensinya persis yang diminta kriteria
 * blueprint: "pengawas di ruang R-02 tidak dapat melihat atau memverifikasi
 * peserta R-03 meski menebak ID-nya" - ditegakkan di satu tempat, bukan di
 * sepuluh controller.
 *
 * ---------------------------------------------------------------------
 * Mengapa policy tetap ada, tetapi TIDAK untuk pintu tautan
 * ---------------------------------------------------------------------
 * Policy masih dipakai untuk hal yang bukan isolasi ruang: Admin boleh
 * mengoreksi presensi mana pun di sekolahnya, dan setiap koreksinya
 * menambah baris log baru.
 *
 * Untuk permintaan lewat tautan, kelas ini MENGGANTIKAN Gate. Gate
 * memerlukan User, dan mengarang satu - misalnya dengan Auth::login() pada
 * akun pengawas mana pun - berarti mengubah tiket masuk menjadi akun. Itu
 * tepat yang ditolak keputusan fase ini: pemegang tautan mendapat kunci
 * sesi, bukan identitas TERAUTENTIKASI.
 *
 * Sejak 2026-11-07 `pengguna` terisi juga untuk permintaan ber-tautan. Itu
 * tidak membatalkan keputusan di atas, dan perbedaannya penting: yang
 * dipegang pemegang tautan bukan sesi login melainkan hasil satu kali
 * pencocokan kode pengawas. `Auth::login()` tetap tidak pernah dipanggil -
 * lihat `penggunaSesi()`.
 *
 * ---------------------------------------------------------------------
 * Mengapa tiap permintaan memeriksa ULANG barisnya
 * ---------------------------------------------------------------------
 * Tautan yang dicabut Admin harus berhenti bekerja pada permintaan
 * BERIKUTNYA, bukan pada login berikutnya. Karena itu `tautanAktif()`
 * membaca ulang baris `room_entry_links` setiap kali dipanggil, bukan
 * mempercayai kunci sesi yang sudah disetel. Kunci sesi hanya menunjuk
 * BARIS MANA yang harus diperiksa; ia tidak pernah menjadi bukti bahwa
 * barisnya masih berlaku.
 */
class KonteksRuang
{
    /** Kunci sesi peramban untuk pemegang tautan ruang. */
    public const KUNCI_SESI = 'pengawas_ruang';

    private function __construct(
        public readonly ExamSessionRoom $ruang,
        public readonly ?User $pengguna,
        public readonly ?RoomEntryLink $tautan,
    ) {}

    /**
     * Bangun konteks untuk satu ruang-sesi, atau menyerah dengan 404.
     *
     * @throws ModelNotFoundException
     */
    public static function dari(ExamSessionRoom $ruang): self
    {
        $tautan = self::tautanUntuk($ruang);

        if ($tautan !== null) {
            // Penggunanya dibaca dari hibah sesi, bukan dari Auth - lihat
            // `penggunaSesi()`. Sejak 2026-11-07 nilainya memang ada, karena
            // kode pengawas menentukan ORANG dan orangnya tersimpan bersama
            // hibaknya.
            return new self($ruang, self::penggunaSesi(), $tautan);
        }

        $pengguna = Auth::user();

        if ($pengguna === null) {
            throw (new ModelNotFoundException)->setModel(ExamSessionRoom::class, [$ruang->id]);
        }

        // Admin: seluruh ruang di sekolahnya. Ini yang membuat laman koreksi
        // presensi milik Admin punya isinya, dan itu memang kewenangannya
        // menurut PRD §3.5.
        if ($pengguna->role->isAdmin()) {
            return new self($ruang, $pengguna, null);
        }

        // Pengawas: HANYA ruang yang ditugaskan kepadanya. Pemeriksaannya
        // lewat penugasan itu sendiri - lihat catatan kelas ini.
        $ditugaskan = SupervisionAssignment::query()
            ->where('supervisor_id', $pengguna->id)
            ->where('exam_session_room_id', $ruang->id)
            ->exists();

        if (! $ditugaskan) {
            throw (new ModelNotFoundException)->setModel(ExamSessionRoom::class, [$ruang->id]);
        }

        return new self($ruang, $pengguna, null);
    }

    /**
     * Ruang-sesi yang boleh dibuka oleh pemanggil saat ini.
     *
     * Dipakai laman beranda pengawas (`/pengawas`). Pemegang tautan melihat
     * TEPAT SATU kartu - ruang yang tautannya ia pegang - dan itu bukan
     * pembatasan yang ditegakkan di sini, melainkan akibat dari fakta bahwa
     * tautan ruang menunjuk satu ruang.
     *
     * @return Collection<int, ExamSessionRoom>
     */
    public static function ruangTersedia(): Collection
    {
        $data = Session::get(self::KUNCI_SESI);

        // Pemegang tautan melihat TEPAT SATU kartu - ruang yang tautannya ia
        // pegang. Itu bukan pembatasan yang ditegakkan di sini; ia akibat
        // dari fakta bahwa tautan ruang menunjuk satu ruang, dan tidak ada
        // bentuk data yang membuatnya menunjuk dua.
        if (self::tautanSesi() !== null) {
            $ruang = ExamSessionRoom::query()
                ->with(['session.exam.event', 'eventRoom.room:id,code,name,location'])
                ->find($data['exam_session_room_id'] ?? null);

            return $ruang === null ? new Collection : new Collection([$ruang]);
        }

        $pengguna = Auth::user();

        if ($pengguna === null) {
            return new Collection;
        }

        $query = ExamSessionRoom::query()->with([
            'session.exam.event',
            'eventRoom.room:id,code,name,location',
        ]);

        if (! $pengguna->role->isAdmin()) {
            $query->whereIn(
                'id',
                SupervisionAssignment::query()
                    ->where('supervisor_id', $pengguna->id)
                    ->pluck('exam_session_room_id'),
            );
        }

        return $query->orderBy('id')->get();
    }

    /**
     * Baris tautan yang dipegang pemanggil, bila ADA dan MASIH SAH.
     *
     * Inilah pemeriksaan yang membuat keputusan "hibah sesi, bukan login"
     * memenuhi kriteria blueprint: pencabutan Admin memutus permintaan
     * berikutnya, tanpa menunggu apa pun.
     */
    public static function tautanSesi(): ?RoomEntryLink
    {
        $data = Session::get(self::KUNCI_SESI);

        if (! is_array($data) || ! isset($data['room_entry_link_id'])) {
            return null;
        }

        // tanpaTenant() WAJIB: pada permintaan ber-tautan belum tentu ada
        // konteks tenant, dan SchoolScope yang melihat school_id null
        // sengaja tidak menyaring apa pun. Memanggilnya seperti biasa akan
        // "berhasil" sampai suatu hari resolver kebetulan punya konteks.
        $tautan = RoomEntryLink::tanpaTenant()->find($data['room_entry_link_id']);

        if ($tautan === null || ! $tautan->berlaku()) {
            return null;
        }

        // Tautan ruang lain tidak pernah membuka ruang ini.
        if ((int) $tautan->room_id !== (int) ($data['room_id'] ?? 0)) {
            return null;
        }

        return $tautan;
    }

    /** Buang hibah sesi - dipakai saat tautannya ditolak atau dicabut. */
    public static function lupakanTautan(): void
    {
        Session::forget(self::KUNCI_SESI);
    }

    /**
     * Pengawas yang membuka pintu ini, bila masuknya lewat kode pengawas.
     *
     * =====================================================================
     * Sejak 2026-11-07 identitasnya ADA - dan itu perubahan yang disengaja
     * =====================================================================
     * Sampai saat itu hibah sesi hanya menyimpan dua id, dan pemegangnya
     * sengaja tidak dikenali siapa pun: kode pengawas waktu itu menempel
     * pada RUANG, sehingga tidak ada orang yang bisa disebut, dan mengarang
     * satu berarti log yang berbohong. Itu sebabnya `pelakuLabel()` berbunyi
     * "R-01 (tautan ruang)".
     *
     * Kode pengawas kini menempel pada ORANG (`PenerbitKodePengawas`), dan
     * orangnya diketahui dari hasil pencocokan yang hanya bisa berhasil bagi
     * pemegang kode itu. Menyebut namanya di log bukan lagi karangan.
     *
     * ---------------------------------------------------------------------
     * Yang TIDAK dilakukan: `Auth::login()`
     * ---------------------------------------------------------------------
     * Pemegang tautan tetap TAMU bagi framework, dan itu tetap benar.
     * Identitas di sini adalah akibat satu kali pencocokan kredensial, bukan
     * sesi terautentikasi: tidak ada sandi yang tersimpan, tidak ada
     * `remember_token`, dan tidak ada apa pun yang bertahan sesudah hibaknya
     * dibuang. Yang berubah hanyalah siapa yang boleh DISEBUT, bukan siapa
     * yang boleh masuk.
     *
     * ---------------------------------------------------------------------
     * Sekolahnya diperiksa ulang, dan itu bukan kehati-hatian berlebih
     * ---------------------------------------------------------------------
     * Kunci sesi memuat `school_id` dari baris tautan, dan `User` memakai
     * SchoolScope. Pada permintaan ber-tautan SchoolResolver sudah ditetapkan
     * oleh `AksesPengawas`, jadi kueri ini tersaring seperti biasa - tetapi
     * pemeriksaan eksplisit di sini menutup satu urutan yang sulit ditemukan:
     * kode yang membaca konteks SEBELUM middleware menyetel resolver akan
     * mendapat user mana pun yang id-nya kebetulan sama. Id pengguna unik
     * global, jadi itu tidak mungkin hari ini; yang dijaga adalah hari ketika
     * ia tidak lagi begitu.
     *
     * ---------------------------------------------------------------------
     * Yang sengaja TIDAK diperiksa ulang: penugasannya
     * ---------------------------------------------------------------------
     * `supervision_assignments` diperiksa saat pintu dibuka, tidak di setiap
     * permintaan. Memeriksanya di sini berarti penugasan yang dicabut Admin
     * di tengah sesi membuat laman presensi **404** - bukan mengalihkan
     * pemegangnya ke pintu seperti yang terjadi pada tautan yang dicabut.
     * Yang pertama adalah perubahan perilaku yang tidak diminta siapa pun;
     * yang kedua sudah berlaku dan sudah diuji.
     */
    public static function penggunaSesi(): ?User
    {
        $data = Session::get(self::KUNCI_SESI);

        if (! is_array($data) || ! isset($data['pengguna_id'])) {
            return null;
        }

        $pengguna = User::query()->find($data['pengguna_id']);

        if ($pengguna === null) {
            return null;
        }

        $sekolah = $data['school_id'] ?? null;

        if ($sekolah !== null && (int) $pengguna->school_id !== (int) $sekolah) {
            return null;
        }

        return $pengguna;
    }

    /** Tautan yang membuka ruang INI, atau null bila pintunya lewat akun. */
    private static function tautanUntuk(ExamSessionRoom $ruang): ?RoomEntryLink
    {
        $tautan = self::tautanSesi();

        if ($tautan === null) {
            return null;
        }

        $data = Session::get(self::KUNCI_SESI);

        if ((int) ($data['exam_session_room_id'] ?? 0) !== (int) $ruang->id) {
            return null;
        }

        return $tautan;
    }

    /** Apakah pemanggil masuk lewat pintu tautan, bukan lewat akun. */
    public function lewatTautan(): bool
    {
        return $this->tautan !== null;
    }

    public function penggunaId(): ?int
    {
        return $this->pengguna?->getKey();
    }

    /**
     * Nama pelaku yang ditulis ke setiap baris log.
     *
     * Inilah yang dirender plate 13 sebagai "Diverifikasi 07:42 oleh R-01
     * (Anda)".
     *
     * ---------------------------------------------------------------------
     * Bagi pemegang tautan, jawabannya berbalik 2026-11-07
     * ---------------------------------------------------------------------
     * Sampai saat itu cabang ini mengembalikan "R-01 (tautan ruang)", dan
     * alasannya ditulis di sini: "memang tidak ada nama orang yang bisa
     * disebut, dan mengarang satu berarti log yang berbohong". Alasan itu
     * benar untuk bentuk datanya waktu itu - kodenya milik ruang, jadi tidak
     * ada orang yang bisa ditunjuk.
     *
     * Sekarang kodenya milik orang, dan orangnya diketahui dari pencocokan
     * yang hanya bisa berhasil bagi pemegang kode itu. Menyebut namanya
     * bukan lagi karangan; yang mengarang justru menulis "tautan ruang"
     * untuk presensi yang jelas-jelas ditandatangani seseorang.
     *
     * ---------------------------------------------------------------------
     * Fallback-nya TETAP ADA, dan itu bukan sisa yang lupa dihapus
     * ---------------------------------------------------------------------
     * `penggunaSesi()` membaca baris `users`, dan baris itu bisa berhenti
     * cocok di tengah sesi: akunnya dihapus, atau ia dipindahkan ke sekolah
     * lain sementara hibak sesinya masih memegang `school_id` yang lama.
     * Hibaknya sendiri tidak ikut mati karenanya, sehingga `$this->pengguna`
     * bisa null sementara tautannya masih sah.
     *
     * Perhatikan bahwa yang TIDAK memicu fallback ini adalah baris
     * kepanitiaannya dihapus. Itu disengaja, dan bukan kelalaian: `User`
     * dibaca langsung dari `pengguna_id` di hibak, bukan lewat baris
     * kepanitiaan - karena memeriksa ulang keterkaitan itu di setiap
     * permintaan adalah pemeriksaan yang sama dengan memeriksa ulang
     * penugasannya, dan itu justru yang ditolak di atas.
     *
     * Di keadaan itu label ruang tetap jawaban yang benar, dan log tidak
     * boleh meledak hanya karena satu baris di tabel lain hilang.
     */
    public function pelakuLabel(): string
    {
        if ($this->pengguna !== null) {
            return $this->pengguna->name;
        }

        return $this->kodeRuang().' (tautan ruang)';
    }

    /** Kode ruang master, mis. "R-01". */
    public function kodeRuang(): string
    {
        return (string) ($this->ruang->eventRoom?->room?->code ?? 'Ruang');
    }

    public function sesi(): ExamSession
    {
        return $this->ruang->session;
    }

    /**
     * Jenis pelaku untuk keperluan tampilan: `admin`, `pengawas`, `tautan`.
     */
    public function jenisPelaku(): string
    {
        if ($this->tautan !== null) {
            return 'tautan';
        }

        return $this->pengguna?->role === Role::Admin ? 'admin' : 'pengawas';
    }
}

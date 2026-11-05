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
 * sesi, bukan identitas.
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
            return new self($ruang, null, $tautan);
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
     * (Anda)". Untuk pemegang tautan, labelnya menyebut RUANGNYA dan bukan
     * nama orang - karena memang tidak ada nama orang yang bisa disebut,
     * dan mengarang satu berarti log yang berbohong.
     *
     * Bentuk "R-01 (tautan ruang)" dipilih karena ia bisa ditelusuri balik:
     * kredensial ruangnya sendiri dapat diaudit - diterbitkan Admin, tiap
     * pemakaian menaikkan use_count, tiap pembukaan tercatat di
     * activity_logs.
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

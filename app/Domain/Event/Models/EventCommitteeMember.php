<?php

namespace App\Domain\Event\Models;

use App\Domain\Facility\Models\Room;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Panitia event - wireframe plate 13, PRD §6.6.
 *
 * ---------------------------------------------------------------------
 * Panitia bukan role kelima
 * ---------------------------------------------------------------------
 * PRD §6.6 menutup pintu itu dengan tegas: "Keanggotaan panitia tidak menjadi
 * role autentikasi baru." Orang tetap Guru, Siswa, atau Pengawas di sistem;
 * yang berubah hanya apa yang boleh ia lakukan DI DALAM event ini.
 *
 * Karena itu tabelnya menyimpan cakupan, bukan peran: panitia ketua memegang
 * seluruh event, panitia pengawas memegang R-01 dan R-02. Cakupan yang
 * membatasi akses, dan itulah sebabnya ia harus terlihat di tabel plate 13 -
 * Admin perlu tahu siapa memegang apa tanpa membuka satu per satu.
 *
 * ---------------------------------------------------------------------
 * user_id boleh null
 * ---------------------------------------------------------------------
 * "Pengguna terkait JIKA anggota memiliki akun" - kata "jika" di PRD §6.6
 * dibaca apa adanya. Staf TU yang hanya membantu di hari-H boleh masuk daftar
 * panitia tanpa pernah punya akun, dan memaksanya punya akun berarti membuat
 * akun yang tidak akan pernah dipakai login.
 *
 * ---------------------------------------------------------------------
 * code: kode pengawas menempel pada ORANG, bukan pada ruang
 * ---------------------------------------------------------------------
 * Sejak 2026-11-07 kolom inilah tempat kode pengawas enam angka disimpan -
 * bukan lagi `room_entry_links.code_hash`. Pembagiannya persis seperti yang
 * ditulis blueprint `:995`: TAUTAN menentukan RUANG (boleh panjang, boleh
 * ditempel di pintu, dan sendirian tidak membuka apa pun), KODE menentukan
 * ORANG.
 *
 * Bentuk data yang lama membuat kalimat itu mustahil: kode disimpan di baris
 * ruang, sehingga satu kode membuka satu ruang untuk siapa pun yang
 * memegangnya - dan kartu pintu yang memuat tautannya sekaligus memuat
 * kodenya, sehingga memfotonya sudah cukup.
 *
 * ---------------------------------------------------------------------
 * TERENKRIPSI, bukan ter-hash - dan itu perubahan yang disengaja
 * ---------------------------------------------------------------------
 * Kolom ini sempat berupa `code_hash` (satu arah): kode hanya tampil SEKALI,
 * dan yang tersisa tidak bisa dibaca kembali. Pemilik produk kemudian meminta
 * kode pengawas SELALU terlihat di laman Kepanitiaan, karena ia mencetaknya
 * menjadi kartu pengawas - dan kode yang hanya berupa hash tidak bisa
 * ditampilkan lagi, sehingga tidak ada yang bisa dicetak.
 *
 * Karena itu ia disimpan dengan cast `encrypted` (Lihat `casts()`): aplikasi
 * bisa membacanya kembali untuk ditampilkan, tetapi di dalam basis data ia
 * tetap teks acak. Doktrinnya tidak berubah - kredensial tidak boleh ada
 * dalam bentuk yang bisa dibaca siapa pun yang memegang dump.
 *
 * NULLABLE, dan null itulah keadaan normal bagi sebagian besar baris: ketua,
 * koordinator, dan anggota tidak pernah butuh kode pintu ruang, dan panitia
 * tanpa akun tidak bisa ditugaskan ke ruang mana pun sehingga kodenya tidak
 * akan pernah punya pintu.
 *
 * Kolom `code` juga `#[Hidden]`, dan itu lapis kedua yang murah: kalau suatu
 * hari model ini ter-serialize ke array/JSON (mis. ikut terkirim sebagai
 * prop mentah), kodenya tidak ikut bocor. Ia hanya dikirim ke peramban lewat
 * prop yang SANGAT eksplisit di `EventCommitteeController::index()`, tempat
 * kodenya memang ingin ditampilkan.
 */
#[ScopedBy(SchoolScope::class)]
#[Hidden(['code'])]
#[Fillable([
    'school_id',
    'event_id',
    'user_id',
    'name',
    'position',
    'email',
    'phone',
    'code',
    'code_issued_at',
    'scope',
    'status',
])]
class EventCommitteeMember extends Model
{
    use BelongsToSchool, HasFactory;

    public const SCOPE_EVENT = 'event';

    public const SCOPE_ROOMS = 'ruang';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            // `encrypted`, bukan `hashed`: kodenya harus bisa DIBACA KEMBALI
            // untuk ditampilkan di laman Kepanitiaan dan dicetak ke kartu
            // pengawas. Cast ini yang menjamin ia tidak pernah tersimpan
            // terbaca di basis data - lihat catatan kelas.
            'code' => 'encrypted',
            'code_issued_at' => 'datetime',
        ];
    }

    /**
     * Apakah baris ini boleh punya kode pintu ruang.
     *
     * Dua syarat, dan keduanya bukan kehati-hatian berlebih:
     *
     *   - JABATANNYA memuat "pengawas". Ketua panitia memegang seluruh
     *     event, bukan satu pintu; kodenya tidak akan pernah diketik di mana
     *     pun.
     *   - ADA AKUNNYA. `PengawasService::tugaskan()` hanya menerima akun
     *     berperan Guru atau Pengawas, sehingga panitia tanpa akun tidak
     *     bisa ditugaskan ke ruang mana pun - dan kode yang tidak terhubung
     *     ke satu ruang pun adalah kode yang tidak punya pintu.
     *
     * Frasa "memuat" dan bukan "sama dengan" mengikuti `PengawasService::
     * calonPengawas()`: `position` di tabel ini adalah teks bebas sepanjang
     * 40 karakter, bukan enum, dan sekolah yang menulis "pengawas ruang"
     * tidak boleh kehilangan fiturnya karena ejaan.
     */
    public function bolehPunyaKode(): bool
    {
        return $this->user_id !== null
            && $this->status === 'aktif'
            && str_contains(mb_strtolower($this->position), 'pengawas');
    }

    /** @return BelongsTo<SchoolEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(SchoolEvent::class, 'event_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Ruang yang dipegang panitia ini, bila cakupannya "ruang".
     *
     * @return BelongsToMany<Room, $this>
     */
    public function rooms(): BelongsToMany
    {
        return $this->belongsToMany(
            Room::class,
            'event_committee_rooms',
            'committee_member_id',
            'room_id',
        )->withPivot('school_id');
    }

    /**
     * Label cakupan untuk tabel plate 13.
     *
     * Ditulis di model, bukan di controller, supaya "Seluruh event" berarti
     * hal yang sama di mana pun ia muncul - dan supaya aturan pembacaannya
     * berubah di satu tempat bila cakupan baru ditambahkan.
     */
    public function labelCakupan(): string
    {
        if ($this->scope === self::SCOPE_EVENT) {
            return 'Seluruh event';
        }

        $kode = $this->rooms->pluck('code')->all();

        return $kode === [] ? 'Belum ditentukan' : implode(', ', $kode);
    }

    /**
     * Ganti daftar ruang yang dipegang panitia ini.
     *
     * ---------------------------------------------------------------------
     * Mengapa bukan sync() langsung
     * ---------------------------------------------------------------------
     * Tabel pivot `event_committee_rooms` membawa `school_id` NOT NULL - ia
     * bagian dari composite FK yang membuat baris lintas sekolah mustahil
     * dibuat. Tetapi `sync()` hanya mengirim kedua kolom kunci relasi, dan
     * tanpa school_id MySQL menolaknya dengan "Field 'school_id' doesn't have
     * a default value".
     *
     * Kesalahan itu mudah terjadi lagi di setiap tempat yang memanggil sync()
     * pada relasi ini, jadi nilainya dipasang di satu tempat: di sini.
     *
     * @param  array<int, int>  $ruangId
     */
    public function sinkronRuang(array $ruangId): void
    {
        $this->rooms()->sync(
            collect($ruangId)
                ->mapWithKeys(fn (int $id) => [$id => ['school_id' => $this->school_id]])
                ->all(),
        );
    }
}

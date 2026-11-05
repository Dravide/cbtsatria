<?php

namespace App\Domain\Event\Models;

use App\Domain\Facility\Models\Room;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'event_id',
    'user_id',
    'name',
    'position',
    'email',
    'phone',
    'scope',
    'status',
])]
class EventCommitteeMember extends Model
{
    use BelongsToSchool, HasFactory;

    public const SCOPE_EVENT = 'event';

    public const SCOPE_ROOMS = 'ruang';

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

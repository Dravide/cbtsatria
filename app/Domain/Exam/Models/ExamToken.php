<?php

namespace App\Domain\Exam\Models;

use App\Domain\Exam\Enums\StatusToken;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Token sesi ujian - PRD §9.7, keputusan §24 #6 (satu token per sesi).
 *
 * ---------------------------------------------------------------------
 * Mengapa token_hash BUKAN bcrypt - dan ini bukan detail gaya
 * ---------------------------------------------------------------------
 * `room_entry_links.code_hash` memakai Hash::make, dan di sana itu BENAR.
 * Alasannya: yang menjadi kunci pencarian tautan adalah **slug**, bukan
 * kodenya. Kode hanya diverifikasi terhadap satu baris yang sudah ditemukan
 * lewat slug, dan verifikasi satu baris memang persis yang bisa dilakukan
 * bcrypt.
 *
 * Token tidak begitu. Siswa mengirim **hanya** token - tidak ada slug, tidak
 * ada id sesi di permintaannya. Token itulah satu-satunya kunci yang
 * dimiliki sistem untuk menemukan barisnya. Dan bcrypt bergaram: hash dari
 * string yang sama berbeda setiap kali, sehingga
 *
 *     ExamToken::where('token_hash', $token)      // tidak akan pernah cocok
 *
 * selalu mengembalikan kosong. Yang tersisa hanyalah memindai SETIAP token
 * aktif dan memanggil Hash::check satu per satu - biaya yang tumbuh seiring
 * jumlah sesi, tepat pada saat 300 siswa menekan tombol mulai bersamaan.
 *
 * Karena itu hash di sini adalah HMAC-SHA256 dengan APP_KEY sebagai kunci:
 * deterministik (bisa di-query lewat satu index), satu arah (tidak bisa
 * dikembalikan menjadi token), dan tidak bisa dihitung ulang tanpa APP_KEY.
 *
 * ---------------------------------------------------------------------
 * token_encrypted: kenapa ada dua representasi
 * ---------------------------------------------------------------------
 * PRD §9.7 menuntutnya: "Jika sistem perlu menampilkan kembali token kepada
 * Admin atau Pengawas yang berwenang, nilai tampilan disimpan dalam bentuk
 * terenkripsi."
 *
 * Tanpa itu, token yang sudah terbit menjadi tidak bisa dibaca siapa pun -
 * termasuk Admin yang menerbitkannya. Satu-satunya jalan keluar adalah
 * mencabutnya lalu menerbitkan yang baru, dan itu berarti siswa yang sedang
 * mengantre di depan pintu harus menunggu token baru tercetak. Kolom ini
 * yang membuat token bisa dibaca kembali, sementara hash tetap satu-satunya
 * yang dipakai untuk memvalidasi.
 *
 * ---------------------------------------------------------------------
 * Statusnya dihitung, bukan disimpan
 * ---------------------------------------------------------------------
 * Lihat StatusToken. Tiga dari lima keadaannya adalah fakta tentang waktu
 * dan riwayat saat dibaca, bukan nilai yang bisa disimpan tanpa penjadwal.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'exam_session_id',
    'token_hash',
    'token_encrypted',
    'valid_from',
    'valid_until',
    'revoked_at',
    'created_by',
])]
class ExamToken extends Model
{
    use BelongsToSchool, HasFactory;

    protected $table = 'exam_tokens';

    protected function casts(): array
    {
        return [
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ExamSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class, 'exam_session_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<ExamTokenView, $this> */
    public function views(): HasMany
    {
        return $this->hasMany(ExamTokenView::class);
    }

    /**
     * Status yang ditampilkan plate 18 - dihitung, tidak dibaca dari kolom.
     *
     * Urutan pemeriksaannya penting dan bukan sembarang urutan: pencabutan
     * diperiksa lebih dulu daripada kedaluwarsa, karena token yang dicabut
     * pagi ini tetapi baru kedaluwarsa besok harus terbaca "dicabut" - itu
     * tindakan orang, dan tindakan orang lebih menjelaskan daripada lewatnya
     * waktu.
     *
     * @param  bool  $pernahDibuka  Apakah token ini pernah ditampilkan.
     */
    public function status(bool $pernahDibuka = false): StatusToken
    {
        if ($this->revoked_at !== null) {
            return StatusToken::Dicabut;
        }

        if ($this->valid_until !== null && $this->valid_until->isPast()) {
            return StatusToken::Kedaluwarsa;
        }

        if ($this->valid_from !== null && $this->valid_from->isFuture()) {
            // Belum masuk jendelanya. Dibaca sebagai draft: dari sudut
            // pandang pengawas, token yang belum bisa dipakai sama saja
            // dengan token yang belum ada.
            return StatusToken::Draft;
        }

        return $pernahDibuka ? StatusToken::Dibuka : StatusToken::Aktif;
    }

    public function berlaku(): bool
    {
        return $this->revoked_at === null
            && ($this->valid_from === null || $this->valid_from->isPast())
            && ($this->valid_until === null || $this->valid_until->isFuture());
    }

    /**
     * Belum dicabut dan belum lewat batas akhirnya.
     *
     * Beda dari `berlaku()` pada satu hal saja: token yang jendelanya BELUM
     * dibuka tetap dihitung. Perbedaan itu menentukan, dan alasannya adalah
     * urutan kerja sekolah yang sebenarnya - token diterbitkan saat jadwal
     * disusun, berhari-hari sebelum sesinya dibuka, dan event harus bisa
     * dinyatakan Siap pada saat itu. Memakai `berlaku()` di gerbang kesiapan
     * berarti event hanya bisa menjadi Siap selama sesinya berlangsung, dan
     * itu kebalikan dari gunanya menyiapkan jadwal.
     *
     * Yang tetap tidak dihitung adalah token yang sudah kedaluwarsa: sesi yang
     * tokennya lewat jendelanya akan ditolak di pintu, dan melaporkannya "ok"
     * berarti gerbangnya berbohong tentang hal yang justru membuat siswa tidak
     * bisa masuk.
     */
    public function belumKedaluwarsa(): bool
    {
        return $this->revoked_at === null
            && ($this->valid_until === null || $this->valid_until->isFuture());
    }
}

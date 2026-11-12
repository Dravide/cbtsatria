<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kode pindai satu ruang-sesi - wireframe-presensi-qr.html plate A1-B6.
 *
 * ---------------------------------------------------------------------
 * Kode ini BUKAN tautan pintu ruangnya
 * ---------------------------------------------------------------------
 * QR memuat `/hadir/{kode}`, bukan `/p/{slug}`. Kalau QR memuat tautan
 * pintu, siswa yang memindainya mendarat di laman kode pengawas - dan satu
 * siswa yang menebak enam angka bisa membuka ruang orang lain. Kode ini
 * hanya menjawab "ruang dan jendela waktu mana", dan tetap menuntut login
 * siswa untuk menulis pengakuan: yang menentukan siapa yang dicatat adalah
 * sesi login itu, bukan kodenya.
 *
 * Sebab itu pula kode yang difoto dan dikirim ke teman di rumah tidak
 * berguna: teman itu tetap butuh akun siswa yang benar-benar ditempatkan di
 * ruang ini, dan akun seperti itu hanya satu.
 *
 * ---------------------------------------------------------------------
 * Disimpan hash, dan tidak pernah dibaca kembali
 * ---------------------------------------------------------------------
 * Berbeda dari `event_committee_members.code` yang harus bisa dicetak ke
 * kartu pengawas. Kode pindai tampil sebagai QR di layar yang menerbitkannya,
 * berumur 60 detik, lalu mati. Yang tersisa di basis data tidak boleh bisa
 * dikembalikan menjadi kode yang sah.
 *
 * Hash-nya HMAC-SHA256 dengan APP_KEY, sama dan dengan alasan yang sama
 * seperti `ExamToken::token_hash`: kode inilah kunci pencarian lewat
 * `/hadir/{kode}`, sehingga bcrypt yang bergaram tidak bisa dipakai.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'exam_session_room_id',
    'code_hash',
    'issued_at',
    'expires_at',
])]
class AttendanceScanCode extends Model
{
    use BelongsToSchool;

    protected $table = 'attendance_scan_codes';

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ExamSessionRoom, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(ExamSessionRoom::class, 'exam_session_room_id');
    }

    /**
     * Masih berlaku pada saat `$sekarang`.
     *
     * Batas atasnya DIPERIKSA DUA KALI saat kode dipakai - di sini dan di
     * `KodePindai::periksa()`. Bukan kerapian: yang di sini menjawab "boleh
     * ditampilkan", yang di sana menjawab "boleh diterima", dan keduanya
     * berjarak beberapa milidetik pada ponsel yang lambat. Kode yang lewat
     * batas di antara keduanya tetap harus ditolak, dengan kalimat yang
     * menyebut angkanya.
     */
    public function berlaku(): bool
    {
        return now()->between($this->issued_at, $this->expires_at);
    }

    /** Sisa detik sebelum kode mati; nol bila sudah lewat. */
    public function sisaDetik(): int
    {
        return max(0, (int) now()->diffInSeconds($this->expires_at, false));
    }
}

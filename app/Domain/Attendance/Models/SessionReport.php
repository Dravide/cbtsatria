<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Attendance\Enums\PeranTandaTangan;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Berita acara satu RUANG-SESI - PRD §12, wireframe plate 18.
 *
 * ---------------------------------------------------------------------
 * Per ruang-sesi, bukan per pengawas dan bukan per sesi
 * ---------------------------------------------------------------------
 * Tiga pilihan yang tampak mirip dan hanya satu yang benar.
 *
 * Per PENGAWAS salah karena berita acara adalah dokumen tentang ruangan,
 * bukan tentang orang: bila pengawas sakit dan digantikan, yang harus
 * ditandatangani tetap laporan ruang yang sama. Per SESI juga salah karena
 * satu sesi menempati banyak ruang sekaligus, dan masing-masing punya
 * keadaannya sendiri - ruang yang listriknya mati tidak boleh tercampur ke
 * dalam satu laporan.
 *
 * Karena itu unique-nya `(school_id, exam_session_room_id)`. Dan justru
 * kunci inilah yang membuat janji blueprint berlaku: "tanda tangan melekat
 * pada peran dan sesi, bukan pada orang, supaya pergantian pengawas tidak
 * memindahkan tanda tangan lama."
 *
 * ---------------------------------------------------------------------
 * Draf tersimpan otomatis, dan itu sebabnya tidak ada tombol Simpan
 * ---------------------------------------------------------------------
 * Satu-satunya pekerjaan yang bisa hilang saat pengawas menutup laman
 * adalah pekerjaan yang tidak diberi tombol simpan - dan pengawas menutup
 * laman di depan pintu kelas, di sela memanggil siswa, bukan di mejanya.
 * Karena itu setiap unggahan foto dan setiap tanda tangan langsung menulis
 * `draft`, dan tombol yang ada hanyalah "Kirim" (yang mengubah status
 * menjadi `final`).
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'exam_session_room_id',
    'status',
    'catatan',
    'pernyataan_presensi',
    'pernyataan_keluar',
    'submitted_at',
    'dibuat_oleh',
    'pelaku_label',
])]
class SessionReport extends Model
{
    use BelongsToSchool;

    protected $table = 'session_reports';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_FINAL = 'final';

    protected function casts(): array
    {
        return [
            'pernyataan_presensi' => 'boolean',
            'pernyataan_keluar' => 'boolean',
            'submitted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ExamSessionRoom, $this> */
    public function ruangSesi(): BelongsTo
    {
        return $this->belongsTo(ExamSessionRoom::class, 'exam_session_room_id');
    }

    /** @return BelongsTo<User, $this> */
    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }

    /** @return HasMany<SessionReportFile, $this> */
    public function berkas(): HasMany
    {
        return $this->hasMany(SessionReportFile::class);
    }

    /** @return HasMany<SessionReportSignature, $this> */
    public function tandaTangan(): HasMany
    {
        return $this->hasMany(SessionReportSignature::class);
    }

    public function berkasSisi(string $sisi): ?SessionReportFile
    {
        return $this->berkas->firstWhere('sisi', $sisi);
    }

    public function tandaTanganPeran(PeranTandaTangan $peran): ?SessionReportSignature
    {
        return $this->tandaTangan->first(
            fn (SessionReportSignature $t) => $t->peran === $peran,
        );
    }

    public function final(): bool
    {
        return $this->status === self::STATUS_FINAL;
    }

    /**
     * Syarat pengiriman final - dan daftar alasannya, bukan satu boolean.
     *
     * Dikembalikan sebagai daftar kalimat supaya halamannya bisa menampilkan
     * APA yang masih kurang. Tombol yang nonaktif tanpa alasan adalah tombol
     * yang membuat pengawas menekannya berulang kali lalu menelepon Admin.
     *
     * Tiga syaratnya: foto keadaan ruang saat ujian ada (sisi `sebelum`
     * tidak dituntut di sini karena ruang yang fotonya lupa diambil sebelum
     * peserta masuk tetap harus bisa dilaporkan), tanda tangan pengawas ada,
     * dan kedua pernyataan sudah ditandai.
     *
     * @return array<int, string>
     */
    public function kekuranganFinal(): array
    {
        $kurang = [];

        if ($this->berkasSisi('sesudah') === null) {
            $kurang[] = 'Foto keadaan ruang saat ujian belum diunggah.';
        }

        if ($this->tandaTanganPeran(PeranTandaTangan::Pengawas) === null) {
            $kurang[] = 'Tanda tangan pengawas belum ada.';
        }

        if (! $this->pernyataan_presensi) {
            $kurang[] = 'Pernyataan presensi belum dicentang.';
        }

        if (! $this->pernyataan_keluar) {
            $kurang[] = 'Pernyataan peserta keluar belum dicentang.';
        }

        return $kurang;
    }

    public function bolehFinal(): bool
    {
        return $this->kekuranganFinal() === [];
    }
}

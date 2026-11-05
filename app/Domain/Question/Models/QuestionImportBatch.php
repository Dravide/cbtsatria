<?php

namespace App\Domain\Question\Models;

use App\Domain\Academic\Models\Subject;
use App\Domain\Identity\Models\User;
use App\Domain\Question\Enums\StatusImpor;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu berkas yang sedang diimpor.
 *
 * ---------------------------------------------------------------------
 * Mengapa keadaan antar-langkah disimpan di basis data
 * ---------------------------------------------------------------------
 * Impor berjalan tiga langkah: unggah -> pratinjau -> konfirmasi. Tanpa baris
 * ini, "pratinjau" harus berarti "urai ulang berkasnya", dan Guru yang
 * menekan Konfirmasi lima menit kemudian akan menyimpan hasil yang berbeda
 * dari yang ia lihat di layar - atau tidak bisa sama sekali, karena berkasnya
 * sudah dibersihkan.
 *
 * `pratinjau_json` menyimpan hasil validasi per baris BESERTA nomor barisnya,
 * karena itulah yang diminta wireframe plate 04: Guru yang mengimpor 50 soal
 * harus tahu baris mana yang perlu diperbaiki, bukan menerima "impor gagal"
 * tanpa alamat.
 *
 * ---------------------------------------------------------------------
 * `berkas_hash` adalah satu-satunya penjaga idempotensi
 * ---------------------------------------------------------------------
 * Unik per (sekolah, guru). Mengunggah ulang berkas yang sama adalah dua kali
 * impor yang sama, dan tanpa penjagaan ini gejalanya bukan galat melainkan
 * bank soal yang berisi dua salinan setiap soal - baru ketahuan berbulan
 * kemudian, dan membereskannya berarti membandingkan isi soal satu per satu.
 *
 * ---------------------------------------------------------------------
 * Berkasnya di disk privat, dan nama di disk diacak
 * ---------------------------------------------------------------------
 * `storage/app/private`, `Str::random(40)`. Nama aslinya disimpan di kolom
 * sendiri supaya Guru mengenali berkasnya di daftar. Berkas impor memuat
 * SELURUH soal satu mapel - bahan yang belum boleh dibaca siapa pun yang
 * menebak namanya.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'teacher_id',
    'subject_id',
    'question_package_id',
    'file_path',
    'nama_asli',
    'berkas_hash',
    'pratinjau_json',
    'status',
    'total_rows',
    'success_rows',
    'failed_rows',
    'error_report_path',
    'imported_at',
])]
class QuestionImportBatch extends Model
{
    use BelongsToSchool, HasFactory;

    protected function casts(): array
    {
        return [
            'pratinjau_json' => 'array',
            'imported_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function guru(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    /**
     * Mapel tujuan berkas ini.
     *
     * Bukan turunan dari paketnya: paket bersifat opsional - wireframe plate
     * 04 menyediakan "Impor ke bank saja" - dan soal tanpa mapel tidak bisa
     * dilihat Guru mana pun, karena QuestionPolicy menyaring lewat
     * TeachingAssignment. Karena itu mapelnya dipilih Guru saat mengunggah.
     *
     * @return BelongsTo<Subject, $this>
     */
    public function mapel(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    /** @return BelongsTo<QuestionPackage, $this> */
    public function paket(): BelongsTo
    {
        return $this->belongsTo(QuestionPackage::class, 'question_package_id');
    }

    public function statusImpor(): StatusImpor
    {
        return StatusImpor::tryFrom((string) $this->status) ?? StatusImpor::Menunggu;
    }
}

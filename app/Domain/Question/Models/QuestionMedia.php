<?php

namespace App\Domain\Question\Models;

use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lampiran satu soal: gambar, audio, video, atau berkas.
 *
 * ---------------------------------------------------------------------
 * Disk privat, tanpa symlink - pola yang sama dengan berita acara Fase 6
 * ---------------------------------------------------------------------
 * Berkasnya duduk di `storage/app/private`, nama di disk diacak
 * (`Str::random(40)`), dan nama aslinya disimpan di kolom sendiri supaya Guru
 * mengunduhnya dengan nama yang ia kenali. TIDAK ada `public/storage`: soal
 * yang belum diujikan adalah bahan yang belum boleh dibaca siapa pun yang
 * menebak namanya.
 *
 * Satu-satunya jalan keluarnya adalah rute berwenang (`/guru/berkas/{media}`),
 * dan rute itu memanggil QuestionPolicy seperti rute lain.
 *
 * ---------------------------------------------------------------------
 * `option_id` nullable - media boleh melekat pada pilihan jawaban
 * ---------------------------------------------------------------------
 * Soal "manakah grafik yang benar" punya gambar DI DALAM setiap pilihan, dan
 * memaksa semuanya menempel pada soal berarti menaruh empat gambar berurutan
 * di badan soal sementara pilihannya hanya teks "A", "B", "C", "D".
 *
 * Kolomnya nullable DI DALAM composite FK, dan itu sah: MySQL melewati
 * pemeriksaan FK saat salah satu kolomnya NULL (MATCH SIMPLE), persis pola
 * `exam_tokens.created_by`.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'question_id',
    'option_id',
    'media_type',
    'mime_type',
    'storage_path',
    'nama_asli',
    'ukuran',
    'metadata_json',
])]
class QuestionMedia extends Model
{
    use BelongsToSchool, HasFactory;

    protected function casts(): array
    {
        return [
            'metadata_json' => 'array',
        ];
    }

    /** @return BelongsTo<Question, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /** @return BelongsTo<QuestionOption, $this> */
    public function option(): BelongsTo
    {
        return $this->belongsTo(QuestionOption::class, 'option_id');
    }

    public function berkasGambar(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/');
    }
}

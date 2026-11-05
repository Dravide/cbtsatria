<?php

namespace App\Domain\Exam\Models;

use App\Domain\Facility\Models\Room;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riwayat pembukaan token - append-only.
 *
 * ---------------------------------------------------------------------
 * Mengapa ini ada, padahal tokennya sudah terlindungi
 * ---------------------------------------------------------------------
 * Anotasi plate 18 menyatakannya: "setiap pembukaan token dicatat: kalau
 * token bocor, asalnya bisa ditelusuri ke satu akun, bukan berhenti pada
 * 'entah siapa'."
 *
 * Yang membuat ini bukan paranoia adalah kenyataan bahwa token HARUS bisa
 * dibaca pengawas di ruangnya - itu keputusan sadar, dan konsekuensinya
 * token memang berpindah tangan secara fisik: ditulis di papan tulis,
 * disebut lisan, dikirim lewat pesan. Sistem tidak bisa mencegahnya. Yang
 * bisa dilakukannya adalah menyimpan siapa yang pernah melihatnya, supaya
 * kalau bocor, asalnya bukan misteri.
 *
 * ---------------------------------------------------------------------
 * Hanya created_at, tanpa updated_at
 * ---------------------------------------------------------------------
 * Sama seperti activity_logs: baris ini menjawab "siapa melihat apa, kapan"
 * dan jawaban itu kehilangan nilainya kalau barisnya bisa diubah. Tidak ada
 * route yang menghapusnya, dan modelnya tidak punya UPDATED_AT.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'exam_token_id',
    'user_id',
    'pelaku_label',
    'room_id',
    'viewed_at',
    'jenis',
    'created_at',
])]
class ExamTokenView extends Model
{
    use BelongsToSchool, HasFactory;

    protected $table = 'exam_token_views';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'viewed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ExamToken, $this> */
    public function token(): BelongsTo
    {
        return $this->belongsTo(ExamToken::class, 'exam_token_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}

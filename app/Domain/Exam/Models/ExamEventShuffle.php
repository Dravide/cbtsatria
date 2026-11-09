<?php

namespace App\Domain\Exam\Models;

use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jejak kocokan satu EVENT - benih, waktu, dan siapa yang mengocok.
 *
 * ---------------------------------------------------------------------
 * Baris ini ADA supaya kocokannya bisa dibuka ulang, bukan supaya tercatat
 * ---------------------------------------------------------------------
 * Halaman penempatan membangun tampilannya dengan MENJALANKAN ULANG kocokan
 * dari benihnya, bukan dengan membaca daftar kursi yang tersimpan. Dua alasan,
 * dan yang kedua lebih penting daripada yang pertama:
 *
 *   1. Satu sumber kebenaran. Kalau layarnya membaca ulang dari kursi yang
 *      tersimpan, ada dua tempat yang bisa berbeda - dan yang salah akan
 *      ketahuan di depan pintu, bukan di layar.
 *
 *   2. Kocokannya bisa diperiksa. Siapa pun yang memegang benihnya bisa
 *      menghasilkan urutan yang sama persis, termasuk orang yang tidak
 *      memercayai layarnya.
 *
 * Karena itu benihnya teks pendek yang bisa dibaca di telepon, bukan bilangan
 * acak 64-bit: yang perlu dipindahkan antar manusia adalah "sama atau tidak",
 * dan itu tidak butuh entropi sebesar itu.
 *
 * ---------------------------------------------------------------------
 * Satu benih untuk seluruh event - dan yang dibeli dengan itu
 * ---------------------------------------------------------------------
 * Siswa yang menghafal "saya di R-03" benar untuk SETIAP mapel, bukan hanya
 * untuk mapel pertama. Itu yang membuat pengumuman di depan pintu bisa dibaca
 * sekali dan berlaku sepanjang ujian.
 *
 * Yang dibayar sebagai gantinya: setiap sesi harus memuat SELURUH ruang event,
 * karena satu peta event tidak bisa menyesuaikan diri per sesi. Sesi yang
 * ruangnya kurang tidak bisa memakai seluruh peta - dan itu dilaporkan apa
 * adanya di layar, bukan ditambal diam-diam. Lihat PenempatanKocok.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'event_id',
    'seed',
    'shuffled_at',
    'shuffled_by',
    'peserta',
])]
class ExamEventShuffle extends Model
{
    use BelongsToSchool, HasFactory;

    protected $table = 'exam_event_shuffles';

    protected function casts(): array
    {
        return [
            'shuffled_at' => 'datetime',
            'peserta' => 'integer',
        ];
    }

    /** @return BelongsTo<SchoolEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(SchoolEvent::class, 'event_id');
    }

    /** @return BelongsTo<User, $this> */
    public function oleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shuffled_by');
    }
}

<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jawaban satu butir oleh satu attempt - PRD §16.8, §11.4.
 *
 * ---------------------------------------------------------------------
 * Satu baris per butir, dan `version` adalah pengaman penyimpanannya
 * ---------------------------------------------------------------------
 * PRD §11.4 menuntut optimistic concurrency secara eksplisit. Bentuk yang
 * dipilih di sini paling sederhana yang memenuhi tuntutan itu: setiap
 * penyimpanan membawa `version` yang dibaca klien, server menolak bila
 * tidak cocok, lalu menaikkannya. Dua tab yang sama-sama menyunting soal 12
 * berakhir dengan satu simpanan diterima dan satu ditolak - bukan dengan
 * jawaban yang saling menimpa tanpa ada yang tahu.
 *
 * `version` dinaikkan DI DALAM transaksi dengan `lockForUpdate()`; lihat
 * JawabanService. Membandingkan versi di sisi klien saja akan membuat
 * klien menentukan jawaban mana yang menang.
 *
 * ---------------------------------------------------------------------
 * `is_correct` dan `score_awarded` nullable, dan null berarti BELUM DINILAI
 * ---------------------------------------------------------------------
 * Bukan "salah". Uraian lahir null dan tetap null sampai guru mengoreksi di
 * Fase 8. Default `false` akan membuat setiap jawaban uraian tercatat salah
 * sampai ada yang mengoreksinya - dan laporan yang membacanya tidak bisa
 * membedakan "belum dinilai" dari "salah".
 *
 * ---------------------------------------------------------------------
 * `ditandai` disimpan di sini, bukan di attempt_questions
 * ---------------------------------------------------------------------
 * Ragu adalah sifat JAWABAN. Menandai soal yang belum dijawab tidak
 * bermakna, dan menaruhnya di attempt_questions berarti penandaan bisa ada
 * tanpa jawaban yang ditandai - keadaan yang tidak pernah ditampilkan plate
 * 22 dan tidak perlu bisa ada.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'attempt_question_id',
    'answer_json',
    'is_correct',
    'score_awarded',
    'saved_at',
    'version',
    'ditandai',
])]
class StudentAnswer extends Model
{
    use BelongsToSchool;

    protected $table = 'student_answers';

    protected function casts(): array
    {
        return [
            'answer_json' => 'array',
            'is_correct' => 'boolean',
            'score_awarded' => 'decimal:2',
            'saved_at' => 'datetime',
            'version' => 'integer',
            'ditandai' => 'boolean',
        ];
    }

    /** @return BelongsTo<AttemptQuestion, $this> */
    public function butir(): BelongsTo
    {
        return $this->belongsTo(AttemptQuestion::class, 'attempt_question_id');
    }

    /**
     * Apakah sudah dijawab.
     *
     * Kosong bukan satu-satunya bentuk "belum dijawab": `{"opsi":[]}` dan
     * `{}` sama-sama tidak menjawab apa pun, dan menghitungnya sebagai
     * terjawab akan membuat penghitung "11/40 terjawab" di plate 22
     * meleset - angka yang justru dibaca siswa sebelum menekan Kumpulkan.
     */
    public function terjawab(): bool
    {
        $isi = $this->answer_json;

        if ($isi === null) {
            return false;
        }

        return $this->adaIsi($isi);
    }

    /**
     * Apakah ada satu nilai yang benar-benar berisi.
     *
     * Rekursif sejak isian rumpang ada. Jawabannya berbentuk
     * `{"rumpang":["gravitasi","",""]}`, dan daftar yang panjangnya tiga
     * TIDAK berarti anak sudah menjawab - yang menjawab satu lubang dari tiga
     * sudah, yang tidak mengisi apa pun belum. Tanpa rekursi ini, soal rumpang
     * yang masih kosong terhitung terjawab pada penghitung "11/40", dan anak
     * mengumpulkan ujian dengan tiga lubang kosong yang ia kira sudah diisi.
     *
     * @param  array<string|int, mixed>  $isi
     */
    private function adaIsi(array $isi): bool
    {
        foreach ($isi as $nilai) {
            if (is_array($nilai)) {
                // {"pasangan":{}} dan {"urutan":[]} sama-sama kosong.
                if ($this->adaIsi($nilai)) {
                    return true;
                }

                continue;
            }

            if ($nilai !== null && $nilai !== '') {
                return true;
            }
        }

        return false;
    }
}

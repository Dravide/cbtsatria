<?php

namespace App\Domain\Exam\Models;

use App\Domain\Question\Enums\JenisSoal;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot satu butir soal di dalam sebuah ujian - PRD §16.6, blueprint `:312`.
 *
 * ---------------------------------------------------------------------
 * Salinan, bukan rujukan - dan itu seluruh alasan tabel ini ada
 * ---------------------------------------------------------------------
 * Blueprint `:312`: "Bank soal tetap boleh diedit Guru tanpa merusak ujian
 * yang sedang berjalan. Tanpa ini, satu edit Guru bisa mengubah ujian 300
 * siswa yang sedang berlangsung."
 *
 * Karena itu membacanya tidak pernah menyentuh tabel `questions`. Seluruh isi
 * soal ada di sini sebagai JSON utuh, disalin saat paket dibekukan, dan
 * dibekukan sekali. `question_id` hanya mencatat ASAL-USULNYA, dan sengaja
 * tanpa foreign key - lihat kepala migrasinya untuk ketiga pilihan yang
 * semuanya salah.
 *
 * ---------------------------------------------------------------------
 * Yang menentukan laman siswa adalah `untukSiswa()`
 * ---------------------------------------------------------------------
 * `kunci_json`, `rubrik_json`, dan `pembahasan_json` TIDAK BOLEH sampai ke
 * peramban siswa. Aturan itu tidak ditegakkan dengan mengingat-ingat: satu
 * method, satu tempat, dan satu test yang memeriksanya sejak sekarang - jauh
 * sebelum Fase 7 membangun layarnya.
 *
 * Model ini penuh kunci jawaban; `untukSiswa()` adalah satu-satunya bentuk
 * yang boleh keluar dari sini menuju layar peserta.
 *
 * ---------------------------------------------------------------------
 * `bobot` sudah hasil akhirnya, bukan rumusnya
 * ---------------------------------------------------------------------
 * Nilainya berasal dari `QuestionPackageItem::bobotBerlaku()` - override bila
 * ada, bobot soalnya bila tidak. Disimpan hasilnya karena paket boleh dilepas
 * setelah ujian lahir, dan bobot ujian yang berjalan tidak boleh ikut berubah
 * saat itu terjadi.
 *
 * `skor_json` yang ikut tersalin adalah konfigurasi penilaian, dan itu urusan
 * Fase 8. Ia dibekukan sekarang karena membekukannya belakangan berarti
 * snapshot lama tidak punya kolom itu sama sekali.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'exam_id',
    'position',
    'bobot',
    'question_id',
    'kode',
    'jenis',
    'tingkat',
    'judul',
    'soal_json',
    'opsi_json',
    'butir_json',
    'kunci_json',
    'rubrik_json',
    'skor_json',
    'pembahasan_json',
    'media_json',
    'dibekukan_pada',
    'created_at',
])]
class ExamQuestion extends Model
{
    use BelongsToSchool, HasFactory;

    protected $table = 'exam_questions';

    /**
     * Append-only, sama seperti activity_logs dan exam_token_views.
     *
     * Snapshot yang bisa disunting bukan snapshot: ujian yang soalnya berubah
     * di tengah jalan membuat nilai yang sudah dihitung tidak bisa
     * dipertanggungjawabkan. Tabelnya tidak punya `updated_at` sama sekali,
     * sehingga ini bukan sekadar konvensi model.
     */
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'bobot' => 'decimal:2',
            'jenis' => JenisSoal::class,
            'soal_json' => 'array',
            'opsi_json' => 'array',
            'butir_json' => 'array',
            'kunci_json' => 'array',
            'rubrik_json' => 'array',
            'skor_json' => 'array',
            'pembahasan_json' => 'array',
            'media_json' => 'array',
            'dibekukan_pada' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Exam, $this> */
    public function ujian(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'exam_id');
    }

    public function bobotBerlaku(): float
    {
        return (float) $this->bobot;
    }

    public function perluKoreksiManual(): bool
    {
        return $this->jenis->perluRubrik();
    }

    /**
     * Bentuk yang boleh dilihat peserta ujian.
     *
     * ---------------------------------------------------------------------
     * Apa yang dibuang, dan mengapa bukan sekadar "kunci"
     * ---------------------------------------------------------------------
     * Tiga kolom, dan ketiganya punya alasan yang berbeda:
     *
     *   kunci_json      - jawabannya. Membocorkannya berarti soal yang bisa
     *                     dijawab tanpa berpikir.
     *   rubrik_json     - pedoman penilaian uraian. Isinya sering memuat
     *                     kata kunci jawaban yang dicari korektor.
     *   pembahasan_json - penjelasan lengkap CARA mengerjakannya. Untuk soal
     *                     pilihan ganda, membacanya sama dengan membaca
     *                     kuncinya ditambah alasannya.
     *
     * `skor_json` TETAP dikirim, dan itu disengaja: peserta berhak tahu bobot
     * tiap nomor - itu bagian dari aturan main, bukan rahasia. Yang tidak
     * boleh ia tahu adalah jawabannya.
     *
     * ---------------------------------------------------------------------
     * Kembalinya array, bukan model yang di-`makeHidden`
     * ---------------------------------------------------------------------
     * Model yang disamarkan mengandalkan pemanggilnya untuk tidak menyentuh
     * `$model->kunci_json` secara langsung - dan satu `toArray()` di tempat
     * lain akan mengembalikannya. Array biasa tidak punya kolom tersembunyi
     * yang bisa bocor, karena yang tidak ada di sini memang tidak ada.
     *
     * `id` ikut dikirim: layar siswa memakainya untuk mengirim jawaban, dan
     * id snapshot bukan rahasia apa pun.
     *
     * @return array<string, mixed>
     */
    public function untukSiswa(): array
    {
        return [
            'id' => $this->id,
            'position' => $this->position,
            'bobot' => (float) $this->bobot,
            'jenis' => $this->jenis->value,
            'jenis_label' => $this->jenis->label(),
            'judul' => $this->judul,
            'soal' => $this->soal_json,
            'opsi' => $this->opsi_json,
            'butir' => $this->butir_json,
            'skor' => $this->skor_json,
            'media' => $this->media_json,
        ];
    }
}

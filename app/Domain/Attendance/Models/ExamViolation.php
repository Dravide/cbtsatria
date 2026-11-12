<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu pelanggaran yang tercatat selama pengerjaan - PRD §11, plate 16.
 *
 * ---------------------------------------------------------------------
 * Append-only, dan itu satu-satunya alasan tabel ini ada
 * ---------------------------------------------------------------------
 * Pelanggaran yang bisa disunting bukan pelanggaran yang bisa
 * dipertanggungjawabkan kepada orang tua siswa. Karena itu `UPDATED_AT`
 * null, tidak ada route yang mengubahnya, dan yang bisa dilakukan pengawas
 * hanyalah menandai `resolved_at` - sebuah tindakan lanjutan, bukan
 * penyuntingan.
 *
 * ---------------------------------------------------------------------
 * duration_ms: pindah tab 3 detik dan 3 menit bukan peristiwa yang sama
 * ---------------------------------------------------------------------
 * Tanpa kolom ini, riwayat pelanggaran hanya berisi daftar jenis, dan
 * pengawas yang harus memutuskan "apakah ini disengaja" tidak punya satu
 * pun angka untuk bersandar. Kolomnya nullable karena jenis pelanggaran
 * yang berupa ketukan tombol - keluar dari layar penuh, menekan
 * pintasan - tidak punya durasi.
 *
 * ---------------------------------------------------------------------
 * exam_participant_id disimpan berlebih di samping exam_attempt_id
 * ---------------------------------------------------------------------
 * Attempt sudah menunjuk peserta, jadi kolom ini secara teori berlebih.
 * Yang membuatnya ada adalah plate 16: riwayat pelanggaran ditampilkan
 * PER SISWA, dan tanpa kolom ini setiap barisnya harus menembus attempt
 * untuk menemukan pemiliknya. Ini salinan yang tidak boleh berbeda, dan
 * satu-satunya penulisnya adalah Fase 7.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'exam_attempt_id',
    'exam_participant_id',
    'violation_type',
    'severity',
    'occurred_at',
    'duration_ms',
    'metadata_json',
    'reported_by_client',
    'resolved_by',
    'resolved_at',
    'created_at',
])]
class ExamViolation extends Model
{
    use BelongsToSchool;

    protected $table = 'exam_violations';

    public const UPDATED_AT = null;

    /**
     * Kosakata jenis pelanggaran yang dikenali UI.
     *
     * Dipakai untuk menerjemahkan nilai mentah menjadi label yang bisa
     * dibaca, dan untuk memberi label pada nilai yang tidak dikenal -
     * sebagaimana adanya, bukan sebagai "lain-lain". Pelanggaran yang
     * dilaporkan peramban versi baru dan belum ada di daftar ini tetap
     * harus terbaca apa adanya di riwayat, bukan disembunyikan.
     */
    public const JENIS = [
        'pindah_tab' => 'Berpindah tab',
        'keluar_layar_penuh' => 'Keluar dari layar penuh',
        'salin_tempel' => 'Menyalin / menempel',
        'klik_kanan' => 'Klik kanan',
        'pintasan_terlarang' => 'Pintasan papan tik',
        'perangkat_berubah' => 'Perangkat berubah',
        'sesi_ganda' => 'Sesi ganda',
        'jaringan' => 'Jaringan terputus',

        // Layar yang terbagi - split screen, jendela mengambang, atau jendela
        // aplikasi lain yang menutupi sebagian soal.
        //
        // Dicatat sebagai jenis TERSENDIRI, bukan dilipat ke
        // `keluar_layar_penuh`: keduanya sering terjadi bersama, tetapi yang
        // dibaca pengawas berbeda. Keluar dari layar penuh bisa berarti anak
        // menyentuh tombol kembali; layar yang terbagi berarti separuh soal
        // sudah tidak di layar itu. Menyatukannya menghapus perbedaan yang
        // justru satu-satunya alasan jenis ini ada.
        //
        // Yang TIDAK dilaporkan: jendela aplikasi lain yang menempel di atas
        // soal tanpa mengubah ukuran viewport. Peramban tidak bisa melihat
        // jendela yang bukan miliknya, dan menebaknya berarti menuduh anak
        // atas hal yang tidak diukur.
        'layar_terbagi' => 'Layar terbagi / jendela mengambang',

        // Satu-satunya jenis yang BUKAN perbuatan siswa, dan ia ada di sini
        // karena tabel ini memang satu-satunya riwayat yang dibaca plate 16
        // dan plate 29. Pembukaan kunci HARUS meninggalkan baris - catatan
        // itu yang diminta docblock PelanggaranController::buka(), dan
        // menaruhnya di tabel lain berarti riwayatnya terbelah dua dan
        // pertanyaan "apa yang terjadi pada attempt ini" tidak lagi punya
        // satu jawaban.
        //
        // Tingkatnya 0 (lihat TanggaPelanggaran::TINGKAT), sehingga
        // kesalahannya tidak terbaca sebagai pelanggaran siswa: yang
        // membedakannya adalah `labelTingkat()` di bawah.
        'kunci_dibuka' => 'Kunci dibuka pengawas',

        // Kebalikan dari baris di atas: ujian DIHENTIKAN oleh Admin, dengan
        // pesan yang ditulis Admin sendiri dan dibaca siswa di plate 25.
        //
        // Ada di tabel yang sama karena alasannya sama - "apa yang terjadi
        // pada attempt ini" harus punya satu jawaban. Tanpa baris ini,
        // penghentian manual hanya meninggalkan `lock_reason` terisi, dan
        // riwayat tidak lagi bisa membedakan penguncian oleh tangga
        // pelanggaran dari keputusan orang.
        'diblokir_admin' => 'Diblokir Admin',
    ];

    /** Jenis yang merupakan tindakan pengawas, bukan perbuatan siswa. */
    public const JENIS_PENGAWAS = ['kunci_dibuka', 'diblokir_admin'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'resolved_at' => 'datetime',
            'created_at' => 'datetime',
            'metadata_json' => 'array',
            'reported_by_client' => 'boolean',
        ];
    }

    /** @return BelongsTo<ExamAttempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(ExamAttempt::class, 'exam_attempt_id');
    }

    /** @return BelongsTo<ExamParticipant, $this> */
    public function peserta(): BelongsTo
    {
        return $this->belongsTo(ExamParticipant::class, 'exam_participant_id');
    }

    /** @return BelongsTo<User, $this> */
    public function penyelesai(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function labelJenis(): string
    {
        return self::JENIS[$this->violation_type] ?? $this->violation_type;
    }

    /**
     * Label tingkat keberatan.
     *
     * Ambang dan sanksinya ditetapkan sekolah lewat `AmbangPelanggaran` di
     * event - lihat TanggaPelanggaran, yang memakai ketiganya. Yang ada di
     * sini hanyalah namanya, supaya satu baris riwayat bisa dibaca manusia.
     *
     * Kosakatanya disalin dari wireframe plate 29: "ringan", "sedang",
     * "berat". Ambangnya 1..5 dan bukan 0..3 karena `severity` adalah
     * tingkat keberatan SATU peristiwa, sementara tangga eskalasi 0..3
     * adalah sifat RANGKAIAN - dua hal berbeda yang pernah tertukar di
     * blueprint, dan dipisahkan di sini supaya tidak tertukar lagi.
     *
     * Satu pengecualian, dan ia disengaja: tindakan pengawas tidak pernah
     * "berat". Pembukaan kunci yang terbaca "Berat" di riwayat siswa adalah
     * catatan yang menuduh anak atas perbuatan orang dewasa.
     */
    public function labelTingkat(): string
    {
        // Tindakan orang tidak pernah "Berat". Pembukaan kunci yang terbaca
        // "Berat" di riwayat siswa adalah catatan yang menuduh anak atas
        // perbuatan orang dewasa.
        //
        // Labelnya dipisah karena pelakunya memang berbeda: pembukaan kunci
        // oleh pengawas ruang, penghentian oleh Admin di papan monitoring.
        // Menyatukan keduanya menjadi satu kalimat akan menulis "tindakan
        // pengawas" pada baris yang Admin tulis dari ruang kerjanya.
        if ($this->violation_type === 'kunci_dibuka') {
            return 'tindakan pengawas';
        }

        if (in_array($this->violation_type, self::JENIS_PENGAWAS, true)) {
            return 'tindakan Admin';
        }

        return match (true) {
            $this->severity >= 5 => 'Berat',
            $this->severity >= 3 => 'Sedang',
            default => 'Ringan',
        };
    }

    public function nadaTingkat(): string
    {
        if (in_array($this->violation_type, self::JENIS_PENGAWAS, true)) {
            return 'info';
        }

        return match (true) {
            $this->severity >= 5 => 'bad',
            $this->severity >= 3 => 'warn',
            default => 'mute',
        };
    }
}

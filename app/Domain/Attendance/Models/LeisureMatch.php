<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lomba hitung cepat dua pemain - undangan lewat kode.
 *
 * =====================================================================
 * Status TIDAK disimpan, dan itu keputusan yang disengaja
 * =====================================================================
 * Empat keadaan (`menunggu`, `siap`, `berjalan`, `selesai`) seluruhnya bisa
 * dihitung dari dua kolom waktu: `mulai_pada` dan jam server. Menyimpannya
 * sebagai kolom berarti ada penjadwal yang harus memindahkannya, dan lomba
 * yang tertinggal berstatus `berjalan` sehari kemudian tidak punya gejala
 * yang bisa dilihat siapa pun - ia hanya muncul sebagai lomba yang tidak bisa
 * dimainkan lagi, tanpa satu pun pesan.
 *
 * Yang disimpan hanyalah keadaan yang TIDAK bisa dihitung dari waktu:
 * `dibatalkan_pada`.
 *
 * =====================================================================
 * Soal disimpan, bukan dibangkitkan ulang
 * =====================================================================
 * Lihat catatan migrasinya. Ringkasnya: dua generator yang harus identik
 * adalah dua generator yang suatu hari berbeda, dan lomba yang soalnya
 * berbeda bukan lomba.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'code',
    'game_type',
    'host_id',
    'guest_id',
    'soal',
    'mulai_pada',
    'berlaku_sampai',
    'dibatalkan_pada',
    'alasan_batal',
])]
class LeisureMatch extends Model
{
    use BelongsToSchool;

    protected $table = 'leisure_matches';

    /** Jeda antara pemain kedua masuk dan soal pertama muncul. */
    public const JEDA_MULAI_DETIK = 5;

    /** Lama satu lomba berjalan. */
    public const DURASI_DETIK = 60;

    /** Undangan yang tidak dipakai selama ini dianggap kedaluwarsa. */
    public const BERLAKU_MENIT = 10;

    /** Lomba berhenti sebelum waktunya - lihat `batal()`. */
    public const BATAL_KEDALUWARSA = 'kedaluwarsa';

    /** Lomba berhenti karena salah satu pemain mulai mengerjakan ujian. */
    public const BATAL_UJIAN = 'ujian_dimulai';

    /** Lomba berhenti atas permintaan pemainnya sendiri. */
    public const BATAL_PEMBUAT = 'dibatalkan_pembuat';

    /** Lomba berhenti karena lawan tidak mengirim kabar. */
    public const BATAL_LAWAN_PUTUS = 'lawan_putus';

    protected function casts(): array
    {
        return [
            'soal' => 'array',
            'mulai_pada' => 'datetime',
            'berlaku_sampai' => 'datetime',
            'dibatalkan_pada' => 'datetime',
        ];
    }

    /** Lomba sudah punya lawan dan jamnya sudah dipasang. */
    public function siap(): bool
    {
        return $this->guest_id !== null && $this->mulai_pada !== null;
    }

    /** Lomba dibatalkan sebelum waktunya - alasannya tidak relevan di sini. */
    public function dibatalkan(): bool
    {
        return $this->dibatalkan_pada !== null;
    }

    /** Undangannya sudah tidak bisa dipakai lagi. */
    public function kedaluwarsa(CarbonInterface $sekarang): bool
    {
        return $this->berlaku_sampai->lte($sekarang);
    }

    /**
     * Keadaan lomba sekarang, dihitung dari jam SERVER.
     *
     * ---------------------------------------------------------------------
     * `menunggu` dan `hitung_mundur` BUKAN keadaan yang sama
     * ---------------------------------------------------------------------
     * `menunggu` = belum ada lawan. `hitung_mundur` = lawan sudah masuk dan
     * jamnya sudah berjalan menuju soal pertama. Membedakannya penting untuk
     * layar: yang pertama menampilkan kode undangan, yang kedua menampilkan
     * angka yang berjalan. Satu status gabungan berarti pemain kedua melihat
     * kode undangan miliknya sendiri, yang tidak berguna baginya.
     *
     * ---------------------------------------------------------------------
     * Mengapa TIDAK ada nomor soal di sini
     * ---------------------------------------------------------------------
     * Kedua pemain menerima DAFTAR SOAL YANG SAMA dan menjawabnya secepat
     * masing-masing. Skornya jumlah poin dalam enam puluh detik, seperti
     * permainan sendirian - jadi tidak ada "soal ke berapa sekarang" yang
     * bisa dihitung dari waktu. Yang membuat lombanya adil adalah daftarnya
     * yang sama, bukan iramanya yang sama.
     *
     * Keadaan yang lebih rumit - satu soal untuk berdua, siapa cepat dia
     * dapat - menuntut server memutuskan pemenang tiap soal, dan itu berarti
     * satu perjalanan bolak-balik per soal. Pada jaringan lab sekolah, yang
     * kalah adalah anak dengan Wi-Fi paling lemah, bukan yang paling cepat
     * berhitung.
     *
     * @return array{fase: string, sisa: int}
     *                                        `fase`: menunggu | hitung_mundur | berjalan | selesai | batal
     *                                        `sisa`: detik sampai soal pertama (hitung_mundur) atau sampai selesai
     *                                        (berjalan). 0 pada keadaan lain.
     */
    public function keadaan(CarbonInterface $sekarang): array
    {
        if ($this->dibatalkan()) {
            return ['fase' => 'batal', 'sisa' => 0];
        }

        if ($this->mulai_pada === null) {
            return ['fase' => 'menunggu', 'sisa' => 0];
        }

        // Detik ke depan positif, ke belakang negatif (Carbon 3, argumen
        // kedua `false`). Satu-satunya tempat tanda itu dibalik menjadi
        // "sisa", supaya layar tidak perlu tahu arahnya.
        $keMulai = (int) round($sekarang->diffInSeconds($this->mulai_pada, false));

        if ($keMulai > 0) {
            return ['fase' => 'hitung_mundur', 'sisa' => $keMulai];
        }

        $sisa = max(0, self::DURASI_DETIK + $keMulai);

        return ['fase' => $sisa > 0 ? 'berjalan' : 'selesai', 'sisa' => $sisa];
    }

    /** Kedua peserta lomba ini, termasuk pembuatnya. */
    public function pemain(): HasMany
    {
        return $this->hasMany(LeisureGameScore::class, 'match_id');
    }

    /** @return BelongsTo<User, $this> */
    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    /** @return BelongsTo<User, $this> */
    public function tamu(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guest_id');
    }
}

<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Attendance\Enums\SisiFoto;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Foto berita acara - PRD §12.2, wireframe plate 18.
 *
 * ---------------------------------------------------------------------
 * Nama berkas di disk diacak, nama aslinya disimpan terpisah
 * ---------------------------------------------------------------------
 * Nama yang diunggah peramban bisa memuat apa saja - nama siswa, nomor
 * induk, judul ujian, bahkan nama berkas dari laporan lain yang keliru
 * dipilih. Menyimpannya apa adanya berarti setiap nama itu menjadi jalur
 * yang bisa ditebak, dan jalur yang bisa ditebak adalah satu langkah dari
 * berkas yang bisa dibuka orang yang tidak berhak.
 *
 * Karena itu berkasnya disimpan dengan nama acak di disk privat, dan nama
 * asli hanya dipakai saat berkasnya diunduh - di situlah ia berguna, dan
 * hanya di situ.
 *
 * ---------------------------------------------------------------------
 * diambil_pada vs diunggah_pada: dua kolom yang tidak boleh disatukan
 * ---------------------------------------------------------------------
 * Blueprint menyatakannya sebagai aturan, dan bentuk kolomnya yang
 * memenuhinya: "foto yang dipilih dari galeri setelah ujian tetap tercatat
 * kapan diunggah." `diambil_pada` diisi dari `File.lastModified` bila
 * peramban menyediakannya - waktu JPEG-nya dibuat, bukan waktu ia dipilih.
 * `diunggah_pada` selalu `now()`.
 *
 * Perbedaan keduanya adalah satu-satunya cara membedakan foto yang memang
 * diambil di ruang ujian dari foto lama yang diunggah sebagai bukti. Bila
 * keduanya disatukan, perbedaan itu tidak bisa dipulihkan lagi.
 *
 * ---------------------------------------------------------------------
 * Lokasi: dari ruang, bukan dari GPS
 * ---------------------------------------------------------------------
 * Plate 18 meminta "waktu pengambilan dan lokasi". PRD §12.4 menetapkan MVP
 * TIDAK menyediakan GPS tracking, dan itu tetap berlaku: lokasinya dibaca
 * dari `rooms.location` milik ruang-sesinya ("Gedung A lantai 2"). Data
 * yang sudah ada, tidak menuntut izin lokasi peramban, dan tidak melanggar
 * batas MVP. Ini bukan pengganti sementara yang lupa dicabut.
 */
#[ScopedBy(SchoolScope::class)]
#[Fillable([
    'school_id',
    'session_report_id',
    'sisi',
    'path',
    'nama_asli',
    'mime',
    'ukuran',
    'diambil_pada',
    'diunggah_pada',
])]
class SessionReportFile extends Model
{
    use BelongsToSchool;

    protected $table = 'session_report_files';

    protected function casts(): array
    {
        return [
            'sisi' => SisiFoto::class,
            'diambil_pada' => 'datetime',
            'diunggah_pada' => 'datetime',
            'ukuran' => 'integer',
        ];
    }

    /** @return BelongsTo<SessionReport, $this> */
    public function laporan(): BelongsTo
    {
        return $this->belongsTo(SessionReport::class, 'session_report_id');
    }

    /** Ukuran berkas dalam satuan yang bisa dibaca, untuk ditampilkan. */
    public function ukuranTerbaca(): string
    {
        $kb = (int) round($this->ukuran / 1024);

        return $kb < 1024 ? $kb.' KB' : round($kb / 1024, 1).' MB';
    }
}

<?php

namespace App\Domain\Question\Enums;

/**
 * Sepuluh jenis soal - PRD §6.5, blueprint `:1223`.
 *
 * Jenis kesepuluh, `IsianRumpang` (fill in the blank), ditambahkan
 * 2026-11-10; sembilan sisanya berasal dari blueprint. Ia sengaja diletakkan
 * tepat sesudah `IsianSingkat` karena keduanya berbagi satu gagasan - jawaban
 * berupa teks pendek - dan berbeda hanya pada JUMLAH lubangnya.
 *
 * ---------------------------------------------------------------------
 * Mengapa enum, bukan string bebas
 * ---------------------------------------------------------------------
 * `questions.question_type` adalah string di basis data, tetapi tidak ada
 * satu pun tempat di aplikasi ini yang boleh menulisnya dari luar enum ini.
 * Alasannya sama dengan `Role` dan `EventStatus`: nilai yang salah ketik
 * ("pilihan_ganda" vs "pilihanganda") tersimpan diam-diam, dan baru ketahuan
 * saat ada satu soal yang tidak bisa dikoreksi mesin enam bulan kemudian.
 *
 * Nilainya bahasa Indonesia tanpa terjemahan, konsisten dengan seluruh enum
 * lain di aplikasi ini. Yang membaca barisnya di basis data tidak perlu
 * menerjemahkan apa pun.
 *
 * ---------------------------------------------------------------------
 * `otomatis()` dan `perluRubrik()` bukan hiasan
 * ---------------------------------------------------------------------
 * Keduanya dipakai untuk MENGUNCI aturan, bukan untuk menampilkan label:
 * soal yang `otomatis()` WAJIB punya kunci, dan soal yang `perluRubrik()`
 * WAJIB punya rubrik. Yang menegakkannya adalah SkemaJenisSoal, dan yang
 * menguji setiap jenisnya adalah satu test per jenis - karena kesepuluh
 * bentuk kunci ini adalah tempat kesalahan paling mahal di seluruh Fase 4:
 * kunci yang salah bentuk tersimpan rapi, ujian berjalan lancar, dan 300
 * siswa dinilai dengan kunci yang tidak pernah cocok.
 *
 * ---------------------------------------------------------------------
 * `Media` mengambil mekanismenya dari soal, bukan dari jenisnya sendiri
 * ---------------------------------------------------------------------
 * Soal media adalah soal yang STIMULUSNYA berupa berkas - grafik, rekaman,
 * potongan video - dan mekanisme jawabannya tetap salah satu dari yang lain:
 * pilihan, isian, atau benar/salah. Karena itu `otomatis()` untuk Media
 * TIDAK BISA dijawab dari enum ini sendirian; SkemaJenisSoal yang
 * menentukannya dari `scoring_config_json.mekanisme`. Method ini
 * mengembalikan true karena mayoritas soal media berjawaban pilihan, dan
 * yang menimpanya adalah skema.
 */
enum JenisSoal: string
{
    case PilihanGanda = 'pilihan_ganda';
    case JawabanGanda = 'jawaban_ganda';
    case BenarSalah = 'benar_salah';
    case IsianSingkat = 'isian_singkat';
    case IsianRumpang = 'isian_rumpang';
    case Menjodohkan = 'menjodohkan';
    case Mengurutkan = 'mengurutkan';
    case Angka = 'angka';
    case Uraian = 'uraian';
    case Media = 'media';

    public function label(): string
    {
        return match ($this) {
            self::PilihanGanda => 'Pilihan ganda',
            self::JawabanGanda => 'Jawaban ganda',
            self::BenarSalah => 'Benar/salah',
            self::IsianSingkat => 'Isian singkat',
            self::IsianRumpang => 'Isian rumpang',
            self::Menjodohkan => 'Menjodohkan',
            self::Mengurutkan => 'Mengurutkan',
            self::Angka => 'Angka',
            self::Uraian => 'Uraian',
            self::Media => 'Media',
        };
    }

    /**
     * Label pendek untuk chip di dalam tabel - wireframe plate 02 memakai
     * "PG", "Isian", "Jodoh", "Urut".
     */
    public function pendek(): string
    {
        return match ($this) {
            self::PilihanGanda => 'PG',
            self::JawabanGanda => 'Ganda',
            self::BenarSalah => 'B/S',
            self::IsianSingkat => 'Isian',
            self::IsianRumpang => 'Rumpang',
            self::Menjodohkan => 'Jodoh',
            self::Mengurutkan => 'Urut',
            self::Angka => 'Angka',
            self::Uraian => 'Uraian',
            self::Media => 'Media',
        };
    }

    /** Apakah jawabannya dapat dinilai mesin. */
    public function otomatis(): bool
    {
        return match ($this) {
            self::Uraian => false,
            default => true,
        };
    }

    /**
     * Apakah soal ini wajib punya rubrik.
     *
     * Hanya Uraian. Soal objektif yang diberi rubrik tidak ditolak - Guru
     * boleh mencatat kriteria untuk dirinya sendiri - tetapi tidak
     * diwajibkan, dan itu perbedaan yang menentukan: mewajibkannya berarti
     * setiap soal PG menuntut satu baris rubrik yang tidak dibaca siapa pun.
     */
    public function perluRubrik(): bool
    {
        return $this === self::Uraian;
    }

    /**
     * Apakah soal ini memakai question_options.
     *
     * Dipakai BankSoalService untuk memutuskan tabel anak mana yang ditulis,
     * sehingga satu jenis soal yang salah diklasifikasikan di sini langsung
     * terlihat sebagai opsi yang hilang - bukan sebagai baris yatim yang
     * tidak ditampilkan di layar mana pun.
     */
    public function pakaiOpsi(): bool
    {
        return match ($this) {
            self::PilihanGanda, self::JawabanGanda, self::BenarSalah => true,
            // Media memakainya BILA mekanismenya pilihan. Keputusan akhirnya
            // di SkemaJenisSoal; di sini true supaya form builder tahu ia
            // harus menawarkan pilihan.
            self::Media => true,
            default => false,
        };
    }

    /** Apakah soal ini memakai question_items (jodoh/urut). */
    public function pakaiButir(): bool
    {
        return match ($this) {
            self::Menjodohkan, self::Mengurutkan => true,
            default => false,
        };
    }

    /**
     * Nama kelas untuk ikon/tab builder.
     *
     * Dipakai kunci React state di laman builder supaya tab yang aktif
     * tersimpan saat Guru berpindah jenis dan kembali. Nilainya sengaja sama
     * dengan `value` enum - permintaan dari peramban membawa string ini, dan
     * satu peta terjemahan tambahan adalah satu tempat lagi yang bisa lupa
     * diperbarui saat jenis kesepuluh ditambahkan.
     *
     * @return array<int, array{nilai: string, label: string, pendek: string}>
     */
    public static function untukTab(): array
    {
        return array_map(fn (self $j) => [
            'nilai' => $j->value,
            'label' => $j->label(),
            'pendek' => $j->pendek(),
        ], self::cases());
    }
}

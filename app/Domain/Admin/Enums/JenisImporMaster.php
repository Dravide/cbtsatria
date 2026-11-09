<?php

namespace App\Domain\Admin\Enums;

use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Models\Subject;
use App\Domain\Admin\Import\PenjajarKelas;
use App\Domain\Admin\Import\PenjajarMapel;
use App\Domain\Admin\Import\PenjajarRuang;
use App\Domain\Facility\Models\Room;
use App\Support\Impor\PenjajarKolom;
use Illuminate\Database\Eloquent\Model;

/**
 * Jenis master data yang bisa diimpor.
 *
 * =====================================================================
 * Mengapa enum, dan mengapa di Admin
 * =====================================================================
 * Impor master data hidup di modul Admin, dan enum ini menyebut tiga jenis
 * sekaligus - mapel (Academic), ruang (Facility), dan kelas (Academic). Ia
 * tidak boleh tinggal di salah satu domain itu: yang pertama berarti Admin
 * menunjuk Academic, yang kedua berarti Facility memiliki daftar jenis yang
 * bukan miliknya. Di sini ia hanya menyebut NAMA jenis, bukan modelnya.
 *
 * =====================================================================
 * `kelasModel()` mengembalikan kelas Model
 * =====================================================================
 * Itu memang penunjukan langsung, dan sengaja: Admin sudah boleh menunjuk
 * ketiganya (`ARAH_MODEL_DIIZINKAN`), dan yang membuat tiga laman impor bisa
 * memakai satu service adalah tepat ini - service tidak perlu tahu model mana
 * yang sedang disimpannya.
 *
 * =====================================================================
 * Yang berbeda antar jenis, dan di mana perbedaannya dinyatakan
 * =====================================================================
 * Tiga importer ini sama di hampir seluruh alurnya, dan yang tersisa berbeda
 * diungkapkan sebagai PERTANYAAN di sini - bukan sebagai `if ($jenis === …)`
 * yang tersebar di `ImporMasterService`:
 *
 *   - `kolomKunci()` / `bidangKunci()`: kolom apa yang mencocokkan baris
 *     dengan data yang sudah ada. Mapel dan ruang memakai `code`; kelas tidak
 *     punya `code` sama sekali dan dicocokkan lewat `name`.
 *   - `panjang()`: batas panjang per bidang, karena `classes.name` dibatasi 40
 *     huruf sementara `subjects.name` 255.
 *   - `butuhTahunAjaran()`: hanya kelas yang terikat satu tahun ajaran.
 *
 * Yang TIDAK diungkapkan di sini adalah urutan langkahnya. Unggah, pratinjau,
 * konfirmasi berjalan sama untuk ketiganya, dan begitu salah satunya butuh
 * langkah yang berbeda, itu tanda ia bukan lagi satu alur.
 */
enum JenisImporMaster: string
{
    case Mapel = 'mapel';
    case Ruang = 'ruang';
    case Kelas = 'kelas';

    public function label(): string
    {
        return match ($this) {
            self::Mapel => 'Mata pelajaran',
            self::Ruang => 'Ruang ujian',
            self::Kelas => 'Kelas',
        };
    }

    /**
     * Nama berkas template yang diunduh Admin.
     *
     * Memuat jenisnya, bukan hanya "template-impor.csv": Admin yang mengunduh
     * dua template dalam satu sesi akan menyimpannya di folder Unduhan yang
     * sama, dan berkas bernama sama diberi akhiran "(1)" oleh peramban - lalu
     * yang diunggah adalah berkas yang salah tanpa satu pun tanda di layar.
     */
    public function namaTemplate(): string
    {
        return 'template-impor-'.$this->value.'.csv';
    }

    /**
     * Nama berkas laporan galat.
     */
    public function namaLaporan(int $batchId): string
    {
        return 'laporan-impor-'.$this->value.'-'.$batchId.'.csv';
    }

    /**
     * Judul kolom yang dipakai template DAN dibaca importer.
     *
     * Diambil dari penjajarnya, bukan ditulis ulang di sini: template dan
     * importer tidak boleh berbeda diam-diam soal kolom mana yang dibaca -
     * alasan yang sama dengan impor soal dan impor pengguna.
     *
     * @return array<int, string>
     */
    public function kolom(): array
    {
        return $this->penjajar()::judul();
    }

    /**
     * Kelas penjajar kolomnya.
     *
     * @return class-string<PenjajarKolom>
     */
    public function penjajar(): string
    {
        return match ($this) {
            self::Mapel => PenjajarMapel::class,
            self::Ruang => PenjajarRuang::class,
            self::Kelas => PenjajarKelas::class,
        };
    }

    /**
     * Kelas Model yang disimpan importer ini.
     *
     * @return class-string<Model>
     */
    public function kelasModel(): string
    {
        return match ($this) {
            self::Mapel => Subject::class,
            self::Ruang => Room::class,
            self::Kelas => SchoolClass::class,
        };
    }

    /* ------------------------------------------------------------- Kunci */

    /**
     * Nama KOLOM BERKAS yang mencocokkan baris dengan data yang sudah ada.
     *
     * Mapel dan ruang dicocokkan lewat `kode`; kelas lewat `nama`. Tabel
     * `classes` tidak punya kolom `code` sama sekali - yang unik di sana
     * `['school_id','academic_year_id','name']` - sehingga menuntut kolom kode
     * berarti menuntut Admin mengarang kode untuk setiap kelas, untuk kunci
     * yang tetap saja tidak muncul di laman Kelas.
     */
    public function kolomKunci(): string
    {
        return match ($this) {
            self::Mapel, self::Ruang => 'kode',
            self::Kelas => 'nama',
        };
    }

    /**
     * Nama BIDANG basis data yang menjadi kunci pencocokan.
     *
     * Dipakai satu tempat saja: `whereIn()` dan `pluck()` saat konfirmasi.
     * Seluruh sisa service bekerja atas `kolomKunci()`, karena yang dibacanya
     * adalah berkasnya, bukan skemanya.
     */
    public function bidangKunci(): string
    {
        return match ($this) {
            self::Mapel, self::Ruang => 'code',
            self::Kelas => 'name',
        };
    }

    /** Judul kolom kunci di laman pratinjau. */
    public function labelKunci(): string
    {
        return match ($this) {
            self::Mapel, self::Ruang => 'Kode',
            self::Kelas => 'Kelas',
        };
    }

    /**
     * Pesan saat sel kuncinya kosong.
     *
     * Ditulis per jenis karena menyebut APA yang mencocokkan barisnya - dan
     * jawabannya berbeda: mapel dicocokkan kodenya, kelas dicocokkan namanya.
     */
    public function pesanKunciKosong(): string
    {
        return match ($this) {
            self::Mapel, self::Ruang => 'Kolom kode belum diisi - kode itulah yang mencocokkan baris ini dengan data yang sudah ada.',
            self::Kelas => 'Kolom nama belum diisi - nama kelas itulah yang mencocokkan baris ini dengan daftar kelas di tahun ajaran ini.',
        };
    }

    /* ---------------------------------------------------------- Lingkup */

    /**
     * Apakah jenis ini terikat pada satu tahun ajaran.
     *
     * Hanya kelas: nama kelas unik PER tahun ajaran, sehingga berkas kelas
     * selalu butuh satu tahun yang mengikatnya. Lihat `PenjajarKelas` untuk
     * mengapa tahun itu datang dari laman alih-alih dari kolom berkas.
     */
    public function butuhTahunAjaran(): bool
    {
        return $this === self::Kelas;
    }

    /**
     * Nilai `status` yang sah untuk jenis ini.
     *
     * Berbeda antar jenis, dan itu bukan kerapian: ruang punya "perbaikan"
     * yang tidak punya arti bagi mapel, dan mapel tidak pernah "perbaikan".
     * Satu daftar bersama akan meloloskan status yang tidak bisa ditampilkan
     * laman mana pun - barisnya tersimpan, tidak muncul di filter, dan Admin
     * mengira impornya gagal.
     *
     * @return array<int, string>
     */
    public function statusSah(): array
    {
        return match ($this) {
            self::Mapel, self::Kelas => ['aktif', 'nonaktif'],
            self::Ruang => ['aktif', 'perbaikan', 'nonaktif'],
        };
    }

    /** Status bawaan bila kolomnya dikosongkan. */
    public function statusBawaan(): string
    {
        return 'aktif';
    }

    /**
     * Batas panjang satu bidang, per jenis.
     *
     * Selaras dengan aturan controller masing-masing - `SubjectController`
     * membatasi `name` 255 huruf, `SchoolClassController` membatasi 40. Satu
     * batas bersama akan meloloskan nama kelas 200 huruf yang tetap ditolak
     * saat Admin menyuntingnya di layar - dan baris impor yang tidak bisa
     * disunting lagi adalah baris yang tidak bisa diperbaiki.
     *
     * @var array<string, int>
     */
    private const PANJANG_BERSAMA = [
        'status' => 20,
    ];

    private const PANJANG = [
        'mapel' => ['code' => 20, 'name' => 255, 'category' => 60, 'grade_level' => 10],
        'ruang' => ['code' => 20, 'name' => 255, 'location' => 255],
        'kelas' => ['name' => 40, 'grade_level' => 10],
    ];

    public function panjang(string $bidang): int
    {
        return self::PANJANG[$this->value][$bidang]
            ?? self::PANJANG_BERSAMA[$bidang]
            ?? 255;
    }

    /* ------------------------------------------------------------ Pesan */

    /**
     * Nama BIDANG basis data => nama KOLOM di berkasnya.
     *
     * =====================================================================
     * Mengapa peta ini ada sama sekali
     * =====================================================================
     * Validator bekerja atas nama BIDANG - `code`, `device_count` - sementara
     * yang dilihat Admin di berkasnya adalah `kode` dan `pc`. Laporan yang
     * menyebut "code" untuk berkas berjudul "kode" membuat Admin mencari kolom
     * yang tidak ada - dan kalimat "kolom code belum diisi" untuk berkas yang
     * seluruh judulnya berbahasa Indonesia terdengar seperti jawaban atas
     * pertanyaan orang lain.
     *
     * Yang membuat terjemahan ini BUKAN duplikasi daftar kolom: peta ini tidak
     * menentukan kolom mana yang dibaca - kelas `Penjajar*` yang menentukan
     * itu. Ia hanya menamai ulang satu galat supaya cocok dengan nama yang
     * sudah ditentukan di sana. Bidang yang tidak ada di peta dikembalikan apa
     * adanya, sehingga bidang baru yang lupa didaftarkan tetap muncul - dengan
     * nama yang salah, bukan hilang.
     *
     * `name` sengaja memetakan ke `nama` untuk ketiga jenis sekaligus, dan itu
     * benar untuk ketiganya - termasuk kelas, yang kolom kuncinya memang
     * bernama `nama`.
     *
     * @var array<string, string>
     */
    private const KOLOM = [
        'code' => 'kode',
        'name' => 'nama',
        'category' => 'kelompok',
        'grade_level' => 'tingkat',
        'location' => 'lokasi',
        'capacity' => 'kapasitas',
        'device_count' => 'pc',
        'status' => 'status',
    ];

    /** Nama kolom berkas untuk satu nama bidang basis data. */
    public static function kolomDari(string $bidang): string
    {
        return self::KOLOM[$bidang] ?? $bidang;
    }

    /**
     * Pesan galat per baris, dalam bahasa Indonesia.
     *
     * Tidak ada `lang/` di proyek ini, dan bawaan framework keluar dalam
     * bahasa Inggris - sementara yang membaca kalimat ini adalah Admin sekolah
     * yang sedang memperbaiki berkasnya.
     *
     * `:max` sengaja dipakai alih-alih angka mati: batas `name` berbeda antara
     * mapel (255) dan kelas (40), dan pesan yang menyebut angka yang salah
     * membuat Admin memotong namanya ke panjang yang tetap ditolak.
     *
     * @var array<string, string>
     */
    public const PESAN = [
        'code.required' => 'Kolom kode belum diisi.',
        'code.max' => 'Kodenya lebih dari :max huruf.',
        'name.required' => 'Kolom nama belum diisi.',
        'name.max' => 'Namanya lebih dari :max huruf.',
        'grade_level.max' => 'Tingkatnya lebih dari :max huruf.',
        'category.max' => 'Nama kelompoknya lebih dari :max huruf.',
        'location.max' => 'Lokasinya lebih dari :max huruf.',
        'capacity.integer' => 'Kapasitasnya bukan angka.',
        'capacity.min' => 'Kapasitasnya tidak boleh negatif.',
        'device_count.integer' => 'Jumlah PC-nya bukan angka.',
        'device_count.min' => 'Jumlah PC tidak boleh negatif.',
        'device_count.lte' => 'Jumlah PC melebihi kapasitas ruangnya.',
        'status.in' => 'Statusnya bukan salah satu nilai yang dikenal.',
    ];
}

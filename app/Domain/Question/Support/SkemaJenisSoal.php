<?php

namespace App\Domain\Question\Support;

use App\Domain\Question\Enums\JenisSoal;
use Illuminate\Validation\ValidationException;

/**
 * Bentuk kunci jawaban untuk kesepuluh jenis soal, dan satu-satunya tempat
 * yang memutuskan apakah sebuah kunci sah.
 *
 * ---------------------------------------------------------------------
 * Mengapa kunci disimpan sebagai JSON, bukan kolom
 * ---------------------------------------------------------------------
 * PRD §16.5 menetapkan `answer_key_json`. Keputusan itu benar dan alasannya
 * terlihat begitu kesepuluh jenisnya didaftar: kunci pilihan ganda adalah
 * satu huruf, kunci isian adalah daftar varian, kunci rumpang adalah DAFTAR
 * dari daftar varian, kunci mengurutkan adalah sebuah permutasi, kunci angka
 * adalah nilai + toleransi + satuan. Kolom bersama untuk kelimanya berarti
 * kolom yang isinya berbeda arti di setiap baris - dan tidak ada satu pun
 * constraint basis data yang bisa menolong.
 *
 * Yang menyelamatkannya dari menjadi kantong sampah adalah kelas ini: satu
 * tempat yang tahu bentuk yang benar untuk setiap jenis, dipanggil sebelum
 * apa pun disimpan. Tanpa itu, JSON adalah kolom yang menerima apa saja.
 *
 * ---------------------------------------------------------------------
 * Mengapa validasi di sini, bukan di FormRequest
 * ---------------------------------------------------------------------
 * Bentuk kunci bergantung pada jenis soal, dan jenis soal adalah KOLOM LAIN
 * pada baris yang sama. Aturan validasi Laravel bisa menulis `required_if`
 * pada jenis, tetapi bentuk yang berbeda-beda per jenisnya menuntut cabang
 * yang jauh lebih panjang di dalam FormRequest daripada di sini - dan
 * cabang itu hanya dipakai satu tempat.
 *
 * Karena itu FormRequest memeriksa BENTUK LUAR (ini array, jenis ini dikenal),
 * dan kelas ini memeriksa ISI. Pemisahannya disengaja: yang pertama bisa
 * dijawab tanpa tahu artinya, yang kedua tidak.
 *
 * ---------------------------------------------------------------------
 * Yang dilempar adalah ValidationException, dan itu disengaja
 * ---------------------------------------------------------------------
 * Ia bisa ditangkap Laravel dan diterjemahkan menjadi galat validasi 422
 * dengan nama kolomnya - sehingga Guru melihat pesan di sebelah field yang
 * salah, bukan halaman galat. Melemparkan RuntimeException berarti satu
 * kesalahan ketik kunci menjadi 500.
 */
class SkemaJenisSoal
{
    /** Batas ukuran satu blok teks soal, dalam karakter. */
    public const BATAS_TEKS = 20000;

    /** Huruf yang sah untuk jenis benar/salah. */
    public const HURUF_BENAR_SALAH = ['b', 's'];

    /**
     * Validasi kunci jawaban satu soal.
     *
     * @param  array<string, mixed>  $kunci  Isi answer_key_json.
     * @param  array<string, mixed>  $skor  Isi scoring_config_json; menentukan
     *                                      mekanisme untuk jenis Media.
     * @param  array<int, array<string, mixed>>  $opsi  Baris question_options.
     * @param  array<int, array<string, mixed>>  $butir  Baris question_items.
     * @param  array<int, array<string, mixed>>  $rubrik  Baris essay_rubric_criteria.
     * @param  ?string  $teksSoal  Teks soal telanjang (prompt_json.teks). Hanya
     *                             jenis IsianRumpang yang memakainya, untuk
     *                             mencocokkan jumlah penanda `{{n}}` dengan
     *                             jumlah kunci. Null berarti pemanggil tidak
     *                             punya teksnya dan pemeriksaan itu dilewati.
     *
     * @throws ValidationException
     */
    public function validasi(
        JenisSoal $jenis,
        ?array $kunci,
        float $bobot,
        array $opsi = [],
        array $butir = [],
        array $rubrik = [],
        array $skor = [],
        ?string $teksSoal = null,
    ): void {
        // Bobot nol ditolak SEMUA jenis. Soal yang tidak bernilai tidak
        // punya tempat di sebuah ujian: ia bisa ada di paket tanpa
        // mempengaruhi apa pun, dan Guru yang menambahkannya mengira ia
        // sudah menambah satu soal. Kalau memang tidak ingin dinilai, yang
        // benar adalah tidak memasukkannya.
        if ($bobot <= 0) {
            $this->tolak('bobot', 'Bobot soal harus lebih dari nol.');
        }

        if ($jenis->perluRubrik()) {
            $this->wajibkanRubrik($rubrik);
        }

        switch ($jenis) {
            case JenisSoal::PilihanGanda:
                $this->pilihanTunggal($kunci, $opsi);
                break;
            case JenisSoal::JawabanGanda:
                $this->pilihanGanda($kunci, $opsi);
                break;
            case JenisSoal::BenarSalah:
                $this->benarSalah($kunci, $opsi);
                break;
            case JenisSoal::IsianSingkat:
                $this->isian($kunci);
                break;
            case JenisSoal::IsianRumpang:
                $this->rumpang($kunci, $teksSoal);
                break;
            case JenisSoal::Menjodohkan:
                $this->jodohkan($kunci, $butir);
                break;
            case JenisSoal::Mengurutkan:
                $this->urutkan($kunci, $butir);
                break;
            case JenisSoal::Angka:
                $this->angka($kunci);
                break;
            case JenisSoal::Uraian:
                // Kuncinya ADALAH rubriknya. Kunci opsi di sini berarti Guru
                // salah memilih jenis, dan menyimpannya diam-diam berarti
                // soal yang jawabannya tidak pernah bisa dikoreksi mesin
                // tetapi terlihat seperti bisa.
                if ($this->adaIsi($kunci)) {
                    $this->tolak('kunci', 'Soal uraian dinilai manual - kuncinya adalah rubriknya, bukan jawaban benar/salah.');
                }
                break;
            case JenisSoal::Media:
                $this->media($kunci, $skor, $opsi, $butir);
                break;
        }
    }

    /* ------------------------------------------------------- Per jenis */

    /** @param array<int, array<string, mixed>> $opsi */
    private function pilihanTunggal(?array $kunci, array $opsi): void
    {
        $dipilih = $this->kunciOpsi($kunci);

        if (count($dipilih) !== 1) {
            $this->tolak('kunci', 'Pilihan ganda harus punya tepat satu jawaban benar, bukan '.count($dipilih).'.');
        }

        $this->pastikanKunciAdaDiOpsi($dipilih, $opsi);
    }

    /** @param array<int, array<string, mixed>> $opsi */
    private function pilihanGanda(?array $kunci, array $opsi): void
    {
        $dipilih = $this->kunciOpsi($kunci);

        if (count($dipilih) < 1) {
            $this->tolak('kunci', 'Jawaban ganda harus punya sekurang-kurangnya satu jawaban benar.');
        }

        $this->pastikanKunciAdaDiOpsi($dipilih, $opsi);
    }

    /** @param array<int, array<string, mixed>> $opsi */
    private function benarSalah(?array $kunci, array $opsi): void
    {
        $dipilih = $this->kunciOpsi($kunci);

        if (count($dipilih) !== 1) {
            $this->tolak('kunci', 'Soal benar/salah harus punya tepat satu jawaban.');
        }

        // Hurufnya dibatasi b/s, dan itu bukan kelonggaran yang bisa
        // ditawar: jenis ini hanya punya dua kemungkinan, dan kunci "a"
        // atau "c" berarti Guru mengira sedang menulis pilihan ganda.
        if (! in_array($dipilih[0], self::HURUF_BENAR_SALAH, true)) {
            $this->tolak('kunci', 'Kunci benar/salah harus "b" atau "s".');
        }

        $this->pastikanKunciAdaDiOpsi($dipilih, $opsi);
    }

    private function isian(?array $kunci): void
    {
        $teks = $kunci['teks'] ?? null;

        if (! is_array($teks) || $teks === []) {
            $this->tolak('kunci', 'Isian singkat butuh sekurang-kurangnya satu varian jawaban yang diterima.');
        }

        foreach ($teks as $varian) {
            if (! is_string($varian) || trim($varian) === '') {
                $this->tolak('kunci', 'Setiap varian jawaban isian harus berupa teks yang tidak kosong.');
            }
        }
    }

    /**
     * Kunci isian rumpang: SATU daftar varian untuk setiap lubang.
     *
     * Bentuknya array-dari-array, bukan objek berkunci nomor:
     *
     *     ['rumpang' => [['gravitasi', 'gaya gravitasi'], ['newton', 'N']]]
     *
     * dan itu bukan pilihan gaya. JavaScript menuliskan kunci objek sebagai
     * teks, sehingga `JSON.stringify({'10': .., '2': ..})` menghasilkan
     * "10" sebelum "2" - soal sepuluh lubang akan tersimpan dengan urutan
     * kunci yang bergeser, dan yang tertukar bukan dua lubang yang berdekatan
     * melainkan lubang 2 dengan lubang 10. Daftar tidak punya urutan yang
     * bisa berubah saat diserialisasi.
     *
     * Penanda di teks soal ditulis `{{1}}`, `{{2}}`, ... dan WAJIB berjumlah
     * sama dengan jumlah kunci. Tanpa pemeriksaan itu, soal dengan empat
     * penanda dan dua kunci tersimpan rapi, lalu dua lubang di layar siswa
     * tidak pernah bisa benar - dan nilai yang muncul terlihat seperti
     * kesalahan siswa.
     */
    private function rumpang(?array $kunci, ?string $teksSoal): void
    {
        $daftar = $kunci['rumpang'] ?? null;

        if (! is_array($daftar) || $daftar === []) {
            $this->tolak('kunci', 'Isian rumpang butuh daftar kunci - satu daftar varian untuk setiap lubang.');
        }

        if (! array_is_list($daftar)) {
            $this->tolak('kunci', 'Kunci isian rumpang harus berupa daftar berurutan, bukan objek berkunci nomor.');
        }

        foreach ($daftar as $nomor => $varian) {
            if (! is_array($varian) || $varian === []) {
                $this->tolak('kunci', 'Rumpang ke-'.($nomor + 1).' belum punya satu pun varian jawaban yang diterima.');
            }

            foreach ($varian as $satu) {
                if (! is_string($satu) || trim($satu) === '') {
                    $this->tolak('kunci', 'Setiap varian jawaban rumpang harus berupa teks yang tidak kosong.');
                }
            }
        }

        if ($teksSoal === null) {
            return;
        }

        $penanda = $this->penandaRumpang($teksSoal);

        if ($penanda === []) {
            $this->tolak('prompt_json', 'Teks soal belum punya penanda rumpang. Sisipkan {{1}}, {{2}}, dan seterusnya pada kata yang dikosongkan.');
        }

        if (count($penanda) !== count($daftar)) {
            $this->tolak('prompt_json', 'Teks soal memuat '.count($penanda).' rumpang, tetapi kuncinya ada '.count($daftar).'. Jumlah keduanya harus sama.');
        }

        // Nomornya harus tepat 1..N, masing-masing sekali. Penanda `{{3}}`
        // yang muncul dua kali berarti satu kunci dipakai dua lubang - dan
        // tidak ada cara menilai dua jawaban berbeda dari satu kunci tanpa
        // diam-diam mengabaikan salah satunya.
        $urut = $penanda;
        sort($urut);

        if ($urut !== range(1, count($daftar))) {
            $this->tolak('prompt_json', 'Nomor penanda rumpang harus berurutan dari {{1}} sampai {{'.count($daftar).'}}, masing-masing sekali.');
        }
    }

    /**
     * Nomor penanda `{{n}}` di dalam teks soal, sesuai urutan kemunculannya.
     *
     * @return array<int, int>
     */
    private function penandaRumpang(string $teks): array
    {
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/u', $teks, $cocok);

        return array_map('intval', $cocok[1]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $butir
     */
    private function jodohkan(?array $kunci, array $butir): void
    {
        $pasangan = $kunci['pasangan'] ?? null;

        if (! is_array($pasangan) || $pasangan === []) {
            $this->tolak('kunci', 'Soal menjodohkan butuh daftar pasangan yang benar.');
        }

        $kiri = $this->kunciButir($butir, grup: 'kiri');
        $kanan = $this->kunciButir($butir, grup: 'kanan');

        if ($kiri === [] || $kanan === []) {
            $this->tolak('butir', 'Soal menjodohkan butuh item di kedua sisi - kiri dan kanan.');
        }

        foreach ($pasangan as $dari => $ke) {
            // Pasangan menggantung adalah kegagalan yang paling mahal di
            // jenis ini: ia tersimpan rapi, ujian berjalan, dan baru
            // ketahuan ketika siswa melihat satu item kanan yang tidak
            // punya pasangan - atau dua item kiri yang menunjuk item kanan
            // yang sama, sehingga satu di antaranya tidak bisa benar.
            if (! in_array((string) $dari, $kiri, true)) {
                $this->tolak('kunci', "Pasangan menjodohkan menunjuk item kiri \"{$dari}\" yang tidak ada.");
            }

            if (! in_array((string) $ke, $kanan, true)) {
                $this->tolak('kunci', "Pasangan menjodohkan menunjuk item kanan \"{$ke}\" yang tidak ada.");
            }
        }

        foreach ($kiri as $k) {
            if (! array_key_exists($k, $pasangan)) {
                $this->tolak('kunci', "Item kiri \"{$k}\" belum punya pasangan.");
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $butir
     */
    private function urutkan(?array $kunci, array $butir): void
    {
        $urutan = $kunci['urutan'] ?? null;

        if (! is_array($urutan) || $urutan === []) {
            $this->tolak('kunci', 'Soal mengurutkan butuh urutan yang benar.');
        }

        $semua = $this->kunciButir($butir, grup: null);

        if (count($urutan) !== count($semua)) {
            $this->tolak('kunci', 'Urutan yang benar harus memuat seluruh '.count($semua).' item, bukan '.count($urutan).'.');
        }

        foreach ($urutan as $k) {
            if (! in_array((string) $k, $semua, true)) {
                $this->tolak('kunci', "Urutan menunjuk item \"{$k}\" yang tidak ada di soal ini.");
            }
        }

        // Duplikat berarti ada item yang muncul dua kali dan item lain yang
        // tidak pernah muncul - dan siswa yang menjawab dengan urutan benar
        // tetap dinilai salah karena panjangnya cocok.
        if (count(array_unique(array_map('strval', $urutan))) !== count($urutan)) {
            $this->tolak('kunci', 'Urutan yang benar tidak boleh memuat item yang sama dua kali.');
        }
    }

    private function angka(?array $kunci): void
    {
        $nilai = $kunci['nilai'] ?? null;

        if (! is_numeric($nilai)) {
            $this->tolak('kunci', 'Soal angka butuh nilai jawaban berupa bilangan.');
        }

        $toleransi = $kunci['toleransi'] ?? 0;

        if (! is_numeric($toleransi) || (float) $toleransi < 0) {
            $this->tolak('kunci', 'Toleransi soal angka harus berupa bilangan yang tidak negatif.');
        }
    }

    /**
     * @param  array<string, mixed>  $skor
     * @param  array<int, array<string, mixed>>  $opsi
     * @param  array<int, array<string, mixed>>  $butir
     */
    private function media(?array $kunci, array $skor, array $opsi, array $butir): void
    {
        $mekanisme = $skor['mekanisme'] ?? 'pilihan';

        // Media meminjam salah satu mekanisme yang sudah ada, bukan
        // menciptakan mekanisme kesepuluh. Yang membedakannya dari soal biasa
        // adalah STIMULUSNYA berupa berkas, dan itu urusan question_media -
        // bukan urusan cara menilainya.
        match ($mekanisme) {
            'pilihan' => $this->pilihanTunggal($kunci, $opsi),
            'isian' => $this->isian($kunci),
            'urut' => $this->urutkan($kunci, $butir),
            default => $this->tolak('skor', "Mekanisme soal media \"{$mekanisme}\" tidak dikenal."),
        };
    }

    /** @param array<int, array<string, mixed>> $rubrik */
    private function wajibkanRubrik(array $rubrik): void
    {
        if ($rubrik === []) {
            $this->tolak('rubrik', 'Soal uraian butuh rubrik - tanpa itu dua pengoreksi akan memberi nilai berbeda untuk jawaban yang sama.');
        }

        foreach ($rubrik as $i => $baris) {
            $maks = $baris['max_score'] ?? null;

            if (! is_numeric($maks) || (float) $maks <= 0) {
                $this->tolak('rubrik', 'Setiap kriteria rubrik butuh skor maksimum lebih dari nol (kriteria ke-'.($i + 1).').');
            }

            if (trim((string) ($baris['criterion'] ?? '')) === '') {
                $this->tolak('rubrik', 'Setiap kriteria rubrik butuh nama (kriteria ke-'.($i + 1).').');
            }
        }
    }

    /* ------------------------------------------------------- Pembantu */

    /**
     * Kunci opsi yang dipilih, dinormalkan menjadi huruf kecil.
     *
     * Normalisasi di sini, bukan di pemanggil: kunci yang tersimpan sebagai
     * "A" dan opsi yang tersimpan sebagai "a" adalah dua nilai yang berbeda
     * bagi basis data dan sama bagi manusia - dan perbandingan yang gagal
     * karena itu akan tampak seperti kunci yang salah.
     *
     * @return array<int, string>
     */
    private function kunciOpsi(?array $kunci): array
    {
        $opsi = $kunci['opsi'] ?? null;

        if (! is_array($opsi)) {
            return [];
        }

        return array_values(array_map(
            fn ($o) => strtolower(trim((string) $o)),
            $opsi,
        ));
    }

    /**
     * @param  array<int, string>  $dipilih
     * @param  array<int, array<string, mixed>>  $opsi
     */
    private function pastikanKunciAdaDiOpsi(array $dipilih, array $opsi): void
    {
        if ($opsi === []) {
            return; // Divalidasi terpisah oleh FormRequest; bukan urusan kunci.
        }

        $tersedia = array_map(
            fn (array $o) => strtolower((string) ($o['option_key'] ?? '')),
            $opsi,
        );

        foreach ($dipilih as $huruf) {
            if (! in_array($huruf, $tersedia, true)) {
                $this->tolak('kunci', "Kunci \"{$huruf}\" tidak ada di antara pilihan jawaban soal ini.");
            }
        }
    }

    /**
     * Kunci item, disaring per kelompok.
     *
     * @param  array<int, array<string, mixed>>  $butir
     * @return array<int, string>
     */
    private function kunciButir(array $butir, ?string $grup): array
    {
        return array_values(array_map(
            fn (array $b) => (string) ($b['item_key'] ?? ''),
            array_filter($butir, fn (array $b) => $grup === null || ($b['item_group'] ?? 'kiri') === $grup),
        ));
    }

    private function adaIsi(?array $kunci): bool
    {
        return $kunci !== null && $kunci !== [];
    }

    /**
     * @throws ValidationException
     */
    private function tolak(string $kolom, string $pesan): never
    {
        throw ValidationException::withMessages([$kolom => $pesan]);
    }
}

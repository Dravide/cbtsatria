<?php

namespace App\Domain\Question\Services;

use App\Domain\Academic\Models\Subject;
use App\Domain\Question\Enums\JenisSoal;
use App\Domain\Question\Support\PembersihHtml;
use App\Domain\Question\Support\SkemaJenisSoal;
use App\Domain\Tenancy\Services\PengaturanAiSekolah;
use App\Support\Ai\GalatPenyedia;
use App\Support\Ai\KlienAi;
use App\Support\Ai\UrlPenyedia;
use Illuminate\Validation\ValidationException;

/**
 * Menyusun satu soal dari model bahasa, dalam bentuk yang siap mengisi form
 * builder.
 *
 * =====================================================================
 * Yang dihasilkan adalah DRAF, bukan baris basis data
 * =====================================================================
 * Kelas ini tidak pernah menyimpan apa pun. Ia mengembalikan array yang
 * dipakai `Builder.tsx` untuk mengisi state formnya, dan Guru tetap harus
 * menekan Simpan seperti biasa.
 *
 * Itu keputusan, bukan kelalaian. Model bahasa menghasilkan jawaban yang
 * terdengar benar dan kadang salah - dan soal yang tersimpan tanpa ditinjau
 * adalah kunci jawaban salah yang dipakai 300 siswa, dengan nama Guru
 * tercetak sebagai penulisnya. Yang menanggung akibatnya di kelas adalah
 * Guru, bukan penyedia AI-nya.
 *
 * =====================================================================
 * Urutan: bersihkan, lalu validasi - sama dengan BankSoalService
 * =====================================================================
 * `SkemaJenisSoal` mengukur panjang teks, dan HTML dari model bisa saja
 * sangat panjang. Membersihkan lebih dulu berarti yang diukur adalah teks
 * yang benar-benar akan disimpan. Urutan sebaliknya akan meloloskan soal
 * yang setelah dibersihkan menjadi kosong.
 *
 * =====================================================================
 * Keluaran yang bentuknya salah BUKAN percobaan ulang
 * =====================================================================
 * Model yang mengembalikan dua jawaban benar untuk pilihan ganda akan
 * mengembalikan hal yang sama pada percobaan kedua, dengan biaya dua kali.
 * Yang gagal adalah keluarannya, bukan sambungannya. Kegagalan sesaat
 * (5xx, timeout, jaringan) tetap bisa diulang - oleh Guru, dengan klik
 * kedua yang ia lihat sendiri di layar.
 *
 * =====================================================================
 * Satu permintaan boleh memuat beberapa soal - dan semuanya divalidasi
 * =====================================================================
 * Meminta sepuluh soal lewat sepuluh permintaan berarti sepuluh kali biaya
 * penyambungan dan sepuluh kali menunggu di depan modal terbuka. Satu
 * permintaan yang meminta sepuluh soal adalah satu tagihan dengan keluaran
 * yang lebih panjang, dan model lebih mudah menjaga variasi antar soal
 * karena ia melihat seluruh paketnya sekaligus.
 *
 * Yang TIDAK berubah: satu soal cacat membatalkan SELURUH permintaan. Soal
 * yang lolos validasi saja lalu dikirim sebagian berarti Guru menerima
 * tujuh dari sepuluh tanpa tahu tiga yang mana yang gagal dan tanpa cara
 * mengambilnya kembali - padahal permintaannya sudah ditagih penuh. Lebih
 * jujur gagal seluruhnya dengan pesan yang bisa ia tindak lanjuti.
 *
 * =====================================================================
 * `bangun()` - jalur SIMPAN, dan mengapa ia ada di kelas ini
 * =====================================================================
 * Sejak 2026-11-10 hasil AI tidak lagi mengisi form builder satu per satu:
 * laman menampilkan pratinjau, Guru mencentang yang ia setujui, lalu yang
 * tercentang disimpan langsung ke bank soal (`POST /guru/bank-soal/ai/simpan`).
 *
 * Yang dikirim balik peramban adalah bentuk ringan yang juga dipakai
 * pratinjau - `{jenis, judul, pertanyaan, opsi, kunci, varian, ...}` - dan
 * `bangun()` yang mengubahnya menjadi payload `BankSoalService::buat()`.
 * Ia MEMAKAI ULANG `petakan()` dan `SkemaJenisSoal`, bukan aturan baru:
 * peramban bisa mengirim apa saja, dan satu-satunya cara memastikan bentuk
 * kunci yang tersimpan tetap sah adalah memetakan lalu memvalidasinya lagi
 * di sini, dengan kode yang sama yang memvalidasi keluaran model.
 */
class PenulisSoalAi
{
    /**
     * Jenis yang boleh dihasilkan AI.
     *
     * SEMBILAN, sejak 2026-11-10. Sebelumnya empat, dengan alasan yang
     * masuk akal saat itu: menjodohkan, mengurutkan, dan angka punya kunci
     * yang lebih mudah salah dihasilkan.
     *
     * Yang membuat alasan itu tidak lagi berlaku bukan model yang menjadi
     * lebih pintar, melainkan pemeriksaan yang sekarang ada di tempatnya:
     * `SkemaJenisSoal` menolak pasangan jodoh yang menggantung, permutasi
     * urut yang tidak lengkap, dan angka tanpa nilai; dan sejak hasil AI
     * lewat pratinjau + centang, soal cacat tidak bisa masuk bank soal tanpa
     * dibaca Guru lebih dulu. Menolak jenisnya di sini hanya berarti Guru
     * mengetik ulang secara manual soal yang bentuknya paling merepotkan.
     *
     * `Media` tetap TIDAK ada di sini, dan itu bukan kelalaian: soal media
     * adalah soal yang stimulusnya berupa BERKAS - gambar, audio, video -
     * dan model bahasa tidak bisa menghasilkan berkas. Soal media yang
     * dikarang di sini akan tersimpan tanpa stimulusnya, yaitu soal biasa
     * yang salah label.
     *
     * @var array<int, string>
     */
    public const JENIS_DIDUKUNG = [
        JenisSoal::PilihanGanda->value,
        JenisSoal::JawabanGanda->value,
        JenisSoal::BenarSalah->value,
        JenisSoal::IsianSingkat->value,
        JenisSoal::IsianRumpang->value,
        JenisSoal::Menjodohkan->value,
        JenisSoal::Mengurutkan->value,
        JenisSoal::Angka->value,
        JenisSoal::Uraian->value,
    ];

    /**
     * Jumlah soal terbanyak dalam satu permintaan.
     *
     * Sepuluh, bukan tiga puluh. Yang membatasi bukan kesabaran Guru melainkan
     * dua hal: `max_tokens` penyedia yang paling umum (4096) habis untuk
     * keluaran yang lebih panjang, dan keluaran panjang itulah yang paling
     * sering berakhir terpotong di tengah objek JSON - lalu seluruh permintaan
     * gagal setelah ditagih penuh. Sepuluh soal masih satu permintaan utuh;
     * di atas itu Guru lebih baik meminta dua kali.
     */
    public const BATAS_JUMLAH = 10;

    /** Huruf opsi untuk pilihan ganda. Empat, sesuai bentuk biasa soal sekolah. */
    private const HURUF_OPSI = ['a', 'b', 'c', 'd'];

    /**
     * Anggaran token per soal tambahan.
     *
     * Batas token di Pengaturan diisi Admin untuk SATU soal. Sepuluh soal
     * yang dijanjikan 1500 token akan terpotong di soal keempat, dan JSON
     * yang terpotong adalah JSON yang tidak bisa diurai - bukan soal yang
     * kurang. Batasnya dikalikan, lalu dipatok supaya penyedia pulalah yang
     * menolak lebih dulu, bukan permintaan yang menggantung.
     */
    private const BATAS_TOKEN_MAKS = 16000;

    /** Tambahan waktu tunggu per soal setelah yang pertama, detik. */
    private const TAMBAHAN_WAKTU = 20;

    /** Batas waktu tertinggi, detik. Sepuluh soal: 45 + 9 x 20 = 225. */
    private const BATAS_WAKTU_MAKS = 240;

    public function __construct(
        private readonly PengaturanAiSekolah $pengaturan,
        private readonly KlienAi $klien,
        private readonly PembersihHtml $pembersih,
        private readonly SkemaJenisSoal $skema,
    ) {}

    /**
     * Susun satu soal - atau beberapa, dalam satu permintaan.
     *
     * Pemanggil dengan `$jumlah` = 1 mendapat tepat satu soal, dan itu jalur
     * yang dipakai sejak awal. `$jumlah` > 1 meminta model membalas satu
     * larik, lalu SETIAP soalnya divalidasi sendiri-sendiri; satu yang cacat
     * membatalkan seluruh permintaan (lihat docblock kelas).
     *
     * @return array<int, array{
     *     judul: string,
     *     jenis: string,
     *     pertanyaan: string,
     *     pembahasan: string|null,
     *     opsi: array<int, array{kunci: string, teks: string}>,
     *     kunci: array<int, string>,
     *     varian: array<int, string>,
     *     rumpang: array<int, array<int, string>>,
     *     butir: array<int, array{kunci: string, grup: string, teks: string, pasangan: string|null}>,
     *     pasangan: array<string, string>,
     *     urutan: array<int, string>,
     *     nilai: float|null,
     *     toleransi: float,
     *     satuan: string|null,
     *     rubrik: array<int, array{kriteria: string, keterangan: string, skor_maks: int}>
     * }>
     *
     * @throws GalatPenyedia
     * @throws ValidationException
     */
    public function susun(int $subjectId, JenisSoal $jenis, string $topik, string $tingkat, int $jumlah = 1): array
    {
        // Dipatok di sini, bukan hanya di controller: pemanggil lain - perintah
        // artisan, test, job - tidak boleh bisa meminta lima ratus soal dan
        // menghabiskan anggaran sekolah lewat satu jalur yang lupa membatasi.
        $jumlah = max(1, min(self::BATAS_JUMLAH, $jumlah));

        $konfigurasi = $this->konfigurasi($jumlah);

        $mapel = Subject::query()->find($subjectId);

        // `UrlPenyedia::pastikan` dipanggil lagi di sini meski sudah dipanggil
        // saat menyimpan: nilai di basis data bisa berubah lewat seeder, perintah
        // artisan, atau baris yang disunting langsung. Pemeriksaan yang hanya
        // hidup di satu jalur masuk adalah pemeriksaan yang dilewati jalur lain.
        UrlPenyedia::pastikan((string) $konfigurasi['base_url']);

        $mentah = $this->klien->kirim($konfigurasi, [
            ['role' => 'system', 'content' => $this->instruksi($jenis, $jumlah)],
            ['role' => 'user', 'content' => $this->permintaan($mapel?->name ?? 'umum', $topik, $tingkat, $jumlah)],
        ]);

        $hasil = [];

        foreach ($this->daftarSoal($mentah, $jumlah) as $satu) {
            $peta = $this->petakan($jenis, $satu);

            // -----------------------------------------------------------
            // Validasi di SERVER, sebelum respons pulang
            // -----------------------------------------------------------
            // Kalau keluaran model yang tidak sah dibiarkan lewat, Guru
            // menerima form yang tampak wajar lalu galat saat menekan Simpan -
            // setelah ia mungkin menyuntingnya sepuluh menit. Yang gagal harus
            // permintaan ini, sekarang, dengan pesan yang bisa ia tindak lanjuti.
            $this->skema->validasi(
                $jenis,
                $peta['kunci_mentah'],
                $peta['bobot'],
                $peta['opsi_mentah'],
                $peta['butir_mentah'],
                $peta['rubrik_mentah'],
                ['bobot' => $peta['bobot']],
                // Rumpang membutuhkan teks soalnya: jumlah penanda `{{n}}` di
                // dalamnya harus sama dengan jumlah kuncinya. Tanpa teks ini
                // pemeriksaan itu dilewati, dan soal dengan empat penanda dan
                // dua kunci tersimpan rapi.
                $peta['kiriman']['pertanyaan'],
            );

            $hasil[] = $peta['kiriman'];
        }

        return $hasil;
    }

    /**
     * Ubah satu soal hasil AI - dalam bentuk yang dikirim ke peramban - menjadi
     * payload `BankSoalService::buat()`.
     *
     * =====================================================================
     * Mengapa peramban tidak mengirim payload simpan secara langsung
     * =====================================================================
     * Karena yang dikirim balik adalah bentuk RINGAN yang juga dipakai
     * pratinjau, bukan kolom-kolom basis data. Guru mencentang soal yang ia
     * setujui di layar pratinjau, dan yang tercentang dikirim balik apa
     * adanya. Kalau peramban yang menyusun `answer_key_json` sendiri, aturan
     * bentuk kunci akan hidup di dua tempat - TypeScript dan PHP - dan yang
     * satu tidak pernah diuji.
     *
     * Di sini bentuk itu dipetakan ULANG lewat `petakan()`, lalu divalidasi
     * `SkemaJenisSoal` dengan aturan yang sama seperti keluaran model. Yang
     * tidak sah ditolak dengan 422 di depan Guru, sebelum satu baris tersimpan.
     *
     * `kode` sengaja null: kode dibangkitkan `BankSoalService` di dalam
     * transaksi yang sama, dan soal AI tidak punya alasan memilih kodenya.
     *
     * @param  array<string, mixed>  $soal
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function bangun(array $soal, int $subjectId, string $tingkat = 'sedang'): array
    {
        $jenis = JenisSoal::tryFrom((string) ($soal['jenis'] ?? ''));

        if ($jenis === null || ! in_array($jenis->value, self::JENIS_DIDUKUNG, true)) {
            throw ValidationException::withMessages([
                'soal' => 'Ada soal yang jenisnya belum bisa disimpan dari hasil AI.',
            ]);
        }

        // Bentuk ringan -> bentuk MENTAH yang dipahami `petakan()`. Dua tingkat
        // ini disengaja: `petakan()` membaca keluaran model (teks kunci bebas,
        // huruf opsi yang bisa salah, angka di posisi nol), dan merawat satu
        // jalur normalisasi untuk kedua asal berarti tidak ada jalur yang
        // benar-benar teruji.
        $mentah = $this->mentahDariKiriman($jenis, $soal);
        $peta = $this->petakan($jenis, $mentah);

        $this->skema->validasi(
            $jenis,
            $peta['kunci_mentah'],
            $peta['bobot'],
            $peta['opsi_mentah'],
            $peta['butir_mentah'],
            $peta['rubrik_mentah'],
            ['bobot' => $peta['bobot']],
            $peta['kiriman']['pertanyaan'],
        );

        $k = $peta['kiriman'];

        return [
            'subject_id' => $subjectId,
            'code' => null,
            'title' => $k['judul'] !== '' ? $k['judul'] : mb_substr(trim(strip_tags($k['pertanyaan'])), 0, 120),
            'question_type' => $jenis->value,
            'difficulty' => in_array($tingkat, ['mudah', 'sedang', 'sulit'], true) ? $tingkat : 'sedang',
            'status' => 'draft',
            'prompt_json' => ['teks' => $k['pertanyaan']],
            'explanation_json' => $k['pembahasan'] === null ? null : ['teks' => $k['pembahasan']],
            'answer_key_json' => $peta['kunci_mentah'],
            // Bobot dari `petakan()`, bukan 1 tetap: uraian berbobot jumlah
            // rubriknya, dan `SkemaJenisSoal` menolak rubrik yang totalnya
            // berbeda dari bobotnya.
            'scoring_config_json' => ['bobot' => $peta['bobot']],
            'opsi' => $peta['opsi_mentah'],
            'butir' => $peta['butir_mentah'],
            'rubrik' => array_map(fn (array $r) => [
                'criterion' => $r['criterion'],
                'description' => $r['description'],
                'max_score' => $r['max_score'],
                'position' => $r['position'],
            ], $peta['rubrik_mentah']),
        ];
    }

    /**
     * Bentuk kiriman peramban -> bentuk mentah yang dibaca `petakan()`.
     *
     * =====================================================================
     * Mengapa pemetaan yang sebenarnya TIDAK diulang di sini
     * =====================================================================
     * Seluruh normalisasi - huruf opsi dipaksa a,b,c,d, kunci "benar" menjadi
     * `b`, kunci angka dipetakan dari posisi - tinggal di `petakan()`. Yang
     * dikerjakan method ini hanya memberi NAMA yang `petakan()` harapkan pada
     * nilai yang sudah tersusun.
     *
     * @param  array<string, mixed>  $soal
     * @return array<string, mixed>
     */
    private function mentahDariKiriman(JenisSoal $jenis, array $soal): array
    {
        $dasar = [
            'judul' => (string) ($soal['judul'] ?? ''),
            'pertanyaan' => (string) ($soal['pertanyaan'] ?? ''),
            'pembahasan' => $soal['pembahasan'] ?? null,
        ];

        return match ($jenis) {
            JenisSoal::PilihanGanda, JenisSoal::JawabanGanda, JenisSoal::BenarSalah => $dasar + [
                'opsi' => is_array($soal['opsi'] ?? null) ? $soal['opsi'] : [],
                'kunci' => is_array($soal['kunci'] ?? null) ? $soal['kunci'] : [],
            ],

            JenisSoal::IsianSingkat => $dasar + [
                'kunci' => is_array($soal['varian'] ?? null) ? $soal['varian'] : [],
            ],

            JenisSoal::IsianRumpang => $dasar + [
                'rumpang' => is_array($soal['rumpang'] ?? null) ? $soal['rumpang'] : [],
            ],

            JenisSoal::Menjodohkan => $dasar + [
                'butir' => is_array($soal['butir'] ?? null) ? $soal['butir'] : [],
                'pasangan' => is_array($soal['pasangan'] ?? null) ? $soal['pasangan'] : [],
            ],

            JenisSoal::Mengurutkan => $dasar + [
                'butir' => is_array($soal['butir'] ?? null) ? $soal['butir'] : [],
                'urutan' => is_array($soal['urutan'] ?? null) ? $soal['urutan'] : [],
            ],

            JenisSoal::Angka => $dasar + [
                'nilai' => $soal['nilai'] ?? null,
                'toleransi' => $soal['toleransi'] ?? 0,
                'satuan' => $soal['satuan'] ?? null,
            ],

            JenisSoal::Uraian => $dasar + [
                'rubrik' => is_array($soal['rubrik'] ?? null) ? $soal['rubrik'] : [],
            ],

            default => $dasar,
        };
    }

    /**
     * Konfigurasi yang siap dipakai `KlienAi`.
     *
     * `batas_token` dan `batas_waktu` diskalakan menurut jumlah soal. Batas
     * token di Pengaturan adalah anggaran satu soal, dan Admin tidak bisa
     * diminta menebak berapa yang dibutuhkan sepuluh soal - ia tidak tahu
     * Guru akan meminta berapa.
     *
     * @return array{base_url:string, model:string, api_key:string, batas_token:int, batas_waktu:int}
     */
    private function konfigurasi(int $jumlah): array
    {
        $pengaturan = $this->pengaturan->ambil();

        if ($pengaturan === null || ! $pengaturan->siap()) {
            throw GalatPenyedia::belumDiatur();
        }

        // `kunciApi()` bisa null meski `siap()` benar: ciphertext yang rusak
        // (APP_KEY diganti) membuat dekripsi gagal. Itu juga berarti "belum
        // diatur" bagi Guru, bukan 500.
        $kunci = $pengaturan->kunciApi();

        if (blank($kunci)) {
            throw GalatPenyedia::belumDiatur();
        }

        return [
            'base_url' => (string) $pengaturan->base_url,
            'model' => (string) $pengaturan->model,
            'api_key' => $kunci,
            'batas_token' => min(
                self::BATAS_TOKEN_MAKS,
                (int) ($pengaturan->batas_token ?: 1500) * $jumlah,
            ),
            'batas_waktu' => min(
                self::BATAS_WAKTU_MAKS,
                KlienAi::BATAS_WAKTU + ($jumlah - 1) * self::TAMBAHAN_WAKTU,
            ),
        ];
    }

    /**
     * Instruksi sistem, DITULIS PER JENIS.
     *
     * Satu skema umum dengan field opsional adalah godaan yang salah: model
     * yang diberi pilihan bentuk memilih bentuk yang salah kira-kira separuh
     * waktu. Skema yang menyebut persis bentuk yang diharapkan menghasilkan
     * keluaran yang bisa divalidasi, dan validasinya bermakna.
     *
     * Untuk `$jumlah` > 1, bentuk satu soal yang sama dibungkus dalam larik
     * `{"soal":[...]}`. Pembungkusnya disebut sebagai KUNCI bernama, bukan
     * larik telanjang, karena `KlienAi::urai()` menuntut objek JSON - dan
     * model yang membalas `[...]` langsung akan ditolak sebagai "bukan objek
     * JSON" meski isinya benar.
     */
    private function instruksi(JenisSoal $jenis, int $jumlah = 1): string
    {
        $awalan = <<<'TXT'
            Anda penulis soal ujian untuk sekolah menengah di Indonesia.
            Tulis dalam Bahasa Indonesia yang baku dan jelas.
            Seluruh teks boleh memuat HTML sederhana (<p>, <b>, <i>, <sup>, <sub>), tanpa CSS dan tanpa gambar.
            Balas HANYA dengan satu objek JSON, tanpa penjelasan di luar JSON.

            TXT;

        $bentuk = match ($jenis) {
            JenisSoal::PilihanGanda => <<<'TXT'
                {"judul":"...","pertanyaan":"...","pembahasan":"...",
                 "opsi":[{"kunci":"a","teks":"..."},{"kunci":"b","teks":"..."},{"kunci":"c","teks":"..."},{"kunci":"d","teks":"..."}],
                 "kunci":["a"]}
                Aturan: tepat empat opsi dengan kunci a, b, c, d; "kunci" berisi TEPAT SATU huruf;
                pengecoh harus masuk akal, bukan jawaban yang jelas salah.

                TXT,

            JenisSoal::JawabanGanda => <<<'TXT'
                {"judul":"...","pertanyaan":"...","pembahasan":"...",
                 "opsi":[{"kunci":"a","teks":"..."},{"kunci":"b","teks":"..."},{"kunci":"c","teks":"..."},{"kunci":"d","teks":"..."}],
                 "kunci":["a","c"]}
                Aturan: empat sampai lima opsi dengan kunci a, b, c, d (dan e bila perlu);
                "kunci" berisi DUA sampai TIGA huruf yang benar - tidak pernah satu, dan tidak pernah semua.
                Pertanyaannya harus menyatakan bahwa jawabannya bisa lebih dari satu
                (mis. "Manakah pernyataan berikut yang BENAR?").

                TXT,

            JenisSoal::BenarSalah => <<<'TXT'
                {"judul":"...","pertanyaan":"...","pembahasan":"...",
                 "opsi":[{"kunci":"b","teks":"Benar"},{"kunci":"s","teks":"Salah"}],
                 "kunci":["b"]}
                Aturan: "opsi" SELALU tepat dua, dengan kunci "b" (Benar) dan "s" (Salah);
                "kunci" berisi TEPAT SATU dari "b" atau "s". Pernyataan harus utuh dan
                benar-benar bisa dinilai benar atau salah, tanpa kata "tidak selalu".

                TXT,

            JenisSoal::IsianSingkat => <<<'TXT'
                {"judul":"...","pertanyaan":"...","pembahasan":"...",
                 "opsi":[],"kunci":["jawaban yang diterima","ejaan lain yang juga benar"]}
                Aturan: jawaban singkat, satu sampai tiga kata. Sebutkan bentuk alternatif yang
                sama-sama benar (singkatan, tanpa spasi, beda huruf besar) supaya siswa yang
                menjawab benar tidak dinilai salah karena ejaan.

                TXT,

            JenisSoal::IsianRumpang => <<<'TXT'
                {"judul":"...","pertanyaan":"...","pembahasan":"...","opsi":[],"kunci":[],
                 "rumpang":[["gravitasi","gaya gravitasi"],["newton"]],
                 "teksRumpang":"Gaya tarik bumi disebut {{1}}, dan satuannya adalah {{2}}."}
                Aturan: kosongkan dua sampai empat kata di dalam "pertanyaan" dengan penanda
                {{1}}, {{2}}, dan seterusnya - penanda itu HARUS berada di dalam "pertanyaan",
                bukan di kolom lain. "rumpang" berisi satu daftar varian untuk setiap lubang,
                dalam urutan nomornya, dan jumlahnya sama dengan jumlah penanda. Kolom
                "teksRumpang" hanya pengingat; yang dipakai adalah "pertanyaan".

                TXT,

            JenisSoal::Menjodohkan => <<<'TXT'
                {"judul":"...","pertanyaan":"...","pembahasan":"...",
                 "kiri":[{"kunci":"k1","teks":"..."},{"kunci":"k2","teks":"..."},{"kunci":"k3","teks":"..."}],
                 "kanan":[{"kunci":"n1","teks":"..."},{"kunci":"n2","teks":"..."},{"kunci":"n3","teks":"..."}],
                 "pasangan":{"k1":"n1","k2":"n3","k3":"n2"}}
                Aturan: "pertanyaan" memuat perintahnya (mis. "Jodohkan istilah dengan
                pengertiannya"), TIDAK memuat daftar itemnya. Tiga sampai lima item di tiap
                sisi. "pasangan" memetakan SETIAP kunci kiri ke satu kunci kanan, dan setiap
                kunci kanan dipakai tepat sekali. Susun "kanan" dalam urutan yang berbeda dari
                pasangannya - kalau urutannya sama dengan kiri, soalnya tidak menguji apa pun.

                TXT,

            JenisSoal::Mengurutkan => <<<'TXT'
                {"judul":"...","pertanyaan":"...","pembahasan":"...",
                 "butir":[{"kunci":"u1","teks":"..."},{"kunci":"u2","teks":"..."},{"kunci":"u3","teks":"..."}],
                 "urutan":["u1","u2","u3"]}
                Aturan: "pertanyaan" memuat perintahnya (mis. "Urutkan langkah berikut dari
                yang pertama"), TIDAK memuat daftarnya. Tiga sampai enam butir, masing-masing
                satu langkah/peristiwa yang berdiri sendiri. "urutan" memuat SELURUH kunci
                butir, tepat sekali, dalam urutan yang benar. Susun "butir" dalam urutan ACAK,
                bukan urutan benarnya.

                TXT,

            JenisSoal::Angka => <<<'TXT'
                {"judul":"...","pertanyaan":"...","pembahasan":"...",
                 "opsi":[],"kunci":{"nilai":42.5,"toleransi":0,"satuan":"cm"}}
                Aturan: "kunci" berupa objek dengan "nilai" bilangan (bukan teks, bukan
                pecahan campuran, gunakan titik desimal), "toleransi" bilangan tidak negatif
                (0 bila jawabannya eksak), dan "satuan" teks pendek atau null. Satuan WAJIB
                disebut juga di dalam "pertanyaan", karena layar siswa tidak membacanya dari
                kunci. Pertanyaannya harus punya satu jawaban angka yang pasti.

                TXT,

            JenisSoal::Uraian => <<<'TXT'
                {"judul":"...","pertanyaan":"...","pembahasan":"...","opsi":[],"kunci":[],
                 "rubrik":[{"kriteria":"...","keterangan":"...","skor_maks":2}]}
                Aturan: "kunci" SELALU array kosong - soal uraian dinilai manual, kuncinya adalah
                rubrik. Rubrik berisi dua sampai empat kriteria, dan jumlah skor_maks-nya adalah
                nilai penuh soal itu.

                TXT,

            default => throw new GalatPenyedia('Jenis soal itu belum bisa dibuat AI.'),
        };

        if ($jumlah === 1) {
            return $awalan.$bentuk;
        }

        return $awalan
            ."Balas dengan SATU objek JSON berbentuk {\"soal\":[ ... ]} yang memuat tepat {$jumlah} soal.\n"
            ."Tiap unsur \"soal\" berbentuk persis seperti berikut, dan setiap soal harus berdiri sendiri\n"
            ."(tidak boleh mengulang soal lain, dan tidak boleh saling bergantung):\n"
            .$bentuk;
    }

    /**
     * Pesan pengguna: mapel, topik, tingkat, dan berapa soal.
     */
    private function permintaan(string $mapel, string $topik, string $tingkat, int $jumlah = 1): string
    {
        $peta = [
            'mudah' => 'tingkat kesulitan mudah (pemahaman dasar)',
            'sedang' => 'tingkat kesulitan sedang (penerapan)',
            'sulit' => 'tingkat kesulitan sulit (analisis)',
        ];

        $sulit = $peta[$tingkat] ?? $peta['sedang'];

        $penutup = $jumlah === 1
            ? 'Buat satu soal saja.'
            : "Buat {$jumlah} soal berbeda, semuanya pada tingkat yang sama, dan jangan mengulang soal yang sama.";

        return "Mata pelajaran: {$mapel}\n"
            ."Topik: {$topik}\n"
            ."Tingkat: {$sulit}\n"
            .$penutup;
    }

    /**
     * Ambil daftar soal dari keluaran model.
     *
     * Satu soal dibalas sebagai objek langsung (bentuk lama); beberapa soal
     * dibalas sebagai `{"soal":[...]}`. Model yang tetap membalas objek
     * tunggal meski diminta sepuluh tidak boleh dianggap sepuluh soal kosong -
     * satu soal benar masih berguna, jadi ia diterima apa adanya, dan sisanya
     * memang tidak ada.
     *
     * Yang ditolak adalah jumlah yang MELEBIHI permintaan: model yang
     * membalas dua belas soal saat diminta sepuluh berarti ia tidak mengikuti
     * skema, dan biaya tokennya sudah terlanjur dibayar - tetapi memotongnya
     * tanpa pesan berarti Guru menyimpan soal yang tidak ia minta.
     *
     * @param  array<string, mixed>  $mentah
     * @return array<int, array<string, mixed>>
     */
    private function daftarSoal(array $mentah, int $jumlah): array
    {
        if ($jumlah === 1 && ! isset($mentah['soal'])) {
            return [$mentah];
        }

        $daftar = $mentah['soal'] ?? null;

        if (! is_array($daftar)) {
            throw GalatPenyedia::bukanJson('objek "soal" tidak ada pada jawaban model');
        }

        $daftar = array_values(array_filter($daftar, 'is_array'));

        if ($daftar === []) {
            throw GalatPenyedia::bukanJson('"soal" kosong pada jawaban model');
        }

        if (count($daftar) > $jumlah) {
            throw GalatPenyedia::bukanJson('model mengembalikan '.count($daftar)." soal untuk permintaan {$jumlah} soal");
        }

        return $daftar;
    }

    /**
     * Petakan keluaran model ke bentuk yang dipakai builder.
     *
     * Tiga keluaran sekaligus: `kiriman` (untuk peramban), `opsi_mentah` dan
     * `rubrik_mentah` (untuk `SkemaJenisSoal`, yang bentuknya mengikuti
     * `BankSoalService`), dan `kunci_mentah`.
     *
     * ---------------------------------------------------------------------
     * `bobot` uraian diambil dari RUBRIKNYA, bukan ditulis 1
     * ---------------------------------------------------------------------
     * `SkemaJenisSoal` menuntut jumlah skor rubrik sama dengan bobot butir -
     * rubrik 2+3 pada butir berbobot 1 adalah butir yang tidak akan pernah
     * bisa dikoreksi. Instruksi ke model sudah menyebut bahwa jumlah
     * `skor_maks` adalah nilai penuh soal, jadi angka itulah bobotnya. Menulis
     * `1` di sini berarti setiap soal uraian hasil AI ditolak validasinya
     * sendiri, dan Guru melihat "AI mengembalikan soal yang bentuknya tidak
     * sah" untuk keluaran yang sebenarnya benar.
     *
     * @param  array<string, mixed>  $mentah
     * @return array{bobot: float, kiriman: array<string, mixed>, opsi_mentah: array<int, array<string, mixed>>, butir_mentah: array<int, array<string, mixed>>, rubrik_mentah: array<int, array<string, mixed>>, kunci_mentah: array<string, mixed>|null}
     */
    private function petakan(JenisSoal $jenis, array $mentah): array
    {
        $judul = $this->bersihkanSatu($mentah['judul'] ?? null);
        $pertanyaan = $this->bersihkanSatu($mentah['pertanyaan'] ?? null);
        $pembahasan = $this->bersihkanSatu($mentah['pembahasan'] ?? null);

        // "judul" tidak wajib dipakai builder (kolom Judul ada di form, tetapi
        // boleh kosong). Model yang tidak mengirimnya bukan kegagalan, jadi
        // potongan pertama pertanyaan dipakai - dan itu yang paling berguna,
        // sebab Guru harus mengetiknya sendiri kalau kosong.
        if ($judul === '') {
            $judul = mb_substr(trim(strip_tags($pertanyaan)), 0, 120);
        }

        $kiriman = [
            'judul' => $judul,
            'jenis' => $jenis->value,
            'pertanyaan' => $pertanyaan,
            'pembahasan' => $pembahasan !== '' ? $pembahasan : null,
            'opsi' => [],
            'kunci' => [],
            'varian' => [],
            'rumpang' => [],
            'butir' => [],
            'pasangan' => [],
            'urutan' => [],
            'nilai' => null,
            'toleransi' => 0.0,
            'satuan' => null,
            'rubrik' => [],
        ];

        $opsiMentah = [];
        $butirMentah = [];
        $rubrikMentah = [];
        $kunciMentah = null;

        // Bobot butir. Satu untuk semua jenis kecuali uraian, yang bobotnya
        // adalah jumlah rubriknya - lihat catatan docblock method ini.
        $bobot = 1.0;

        switch ($jenis) {
            case JenisSoal::PilihanGanda:
                // Hurufnya DIPAKSA a,b,c,d berurutan, bukan dipakai apa
                // adanya dari model: model kadang membalas `"kunci":"0"` atau
                // mengacak huruf, dan opsi yang urutannya berbeda dari
                // tampilannya membuat kunci menunjuk jawaban yang salah di
                // layar tanpa satu pun galat.
                [$opsiMentah, $kiriman['opsi']] = $this->opsiTerurut(
                    $mentah['opsi'] ?? [],
                    self::HURUF_OPSI,
                );

                $kunciMentah = ['opsi' => $this->kunciHuruf($mentah['kunci'] ?? [])];
                $kiriman['kunci'] = $kunciMentah['opsi'];
                break;

            case JenisSoal::JawabanGanda:
                // Sama seperti pilihan ganda, tetapi jumlah opsi mengikuti apa
                // yang dikirim: empat huruf biasa, dan huruf kelima hanya bila
                // model benar-benar membalas opsi kelima.
                [$opsiMentah, $kiriman['opsi']] = $this->opsiTerurut(
                    $mentah['opsi'] ?? [],
                    [...self::HURUF_OPSI, 'e'],
                );

                $kunciMentah = ['opsi' => $this->kunciHuruf($mentah['kunci'] ?? [], [...self::HURUF_OPSI, 'e'])];
                $kiriman['kunci'] = $kunciMentah['opsi'];
                break;

            case JenisSoal::BenarSalah:
                [$opsiMentah, $kiriman['opsi']] = $this->opsiTerurut(
                    [['kunci' => 'b', 'teks' => 'Benar'], ['kunci' => 's', 'teks' => 'Salah']],
                    ['b', 's'],
                );

                // Nilai "true"/"benar"/"B" dinormalkan ke `b`/`s`. Ini satu-
                // satunya jenis di mana model paling sering keluar dari
                // bentuk, dan `SkemaJenisSoal` menolak apa pun selain b/s -
                // penolakan yang benar, tetapi tidak berguna bila asalnya cuma
                // beda kapitalisasi.
                $kunciMentah = ['opsi' => [$this->normalisasiBenarSalah($mentah['kunci'] ?? [])]];
                $kiriman['kunci'] = $kunciMentah['opsi'];
                break;

            case JenisSoal::IsianSingkat:
                $varian = $this->daftarTeks($mentah['kunci'] ?? $mentah['teks'] ?? []);

                $kunciMentah = ['teks' => $varian];
                $kiriman['varian'] = $varian;
                break;

            case JenisSoal::IsianRumpang:
                // Bentuk kuncinya array-dari-array, dan jumlahnya harus sama
                // dengan jumlah penanda di teks. `SkemaJenisSoal` yang
                // memeriksa kesamaan itu - di sini yang dikerjakan hanya
                // merapikan bentuk: tiap lubang menjadi daftar varian, satu
                // varian tetap menjadi daftar satu unsur.
                $rumpang = [];

                foreach (is_array($mentah['rumpang'] ?? null) ? $mentah['rumpang'] : [] as $lubang) {
                    $varianLubang = $this->daftarTeks($lubang);

                    // Lubang kosong TETAP dimasukkan supaya nomornya tidak
                    // bergeser - kegagalan yang akan menunjuk lubang yang
                    // salah di pesan galatnya.
                    $rumpang[] = $varianLubang;
                }

                $kunciMentah = ['rumpang' => $rumpang];
                $kiriman['rumpang'] = $rumpang;
                break;

            case JenisSoal::Menjodohkan:
                [$butirMentah, $kiriman['butir'], $pasangan] = $this->jodohDari($mentah);

                $kunciMentah = ['pasangan' => $pasangan];
                $kiriman['pasangan'] = $pasangan;
                break;

            case JenisSoal::Mengurutkan:
                [$butirMentah, $kiriman['butir'], $urutan] = $this->urutanDari($mentah);

                $kunciMentah = ['urutan' => $urutan];
                $kiriman['urutan'] = $urutan;
                break;

            case JenisSoal::Angka:
                $nilai = $mentah['nilai'] ?? $mentah['kunci']['nilai'] ?? null;

                // Koma desimal dirapikan: "3,14" adalah cara Guru dan model
                // menulis di Indonesia, dan `is_numeric('3,14')` bernilai
                // false - sehingga soal yang sebenarnya benar ditolak.
                if (is_string($nilai)) {
                    $nilai = str_replace(',', '.', trim($nilai));
                }

                $toleransi = $mentah['toleransi'] ?? $mentah['kunci']['toleransi'] ?? 0;

                if (is_string($toleransi)) {
                    $toleransi = str_replace(',', '.', trim($toleransi));
                }

                $satuan = $this->bersihkanSatu($mentah['satuan'] ?? $mentah['kunci']['satuan'] ?? null);

                $kunciMentah = [
                    // Diteruskan apa adanya bila bukan bilangan: `SkemaJenisSoal`
                    // yang menolaknya dengan pesan yang benar. Menebak di sini
                    // berarti menyimpan nilai yang mungkin kebalikan dari maksud
                    // model.
                    'nilai' => is_numeric($nilai) ? (float) $nilai : $nilai,
                    'toleransi' => is_numeric($toleransi) ? (float) $toleransi : 0.0,
                    ...($satuan !== '' ? ['satuan' => $satuan] : []),
                ];
                $kiriman['nilai'] = $kunciMentah['nilai'];
                $kiriman['toleransi'] = $kunciMentah['toleransi'];
                $kiriman['satuan'] = $satuan !== '' ? $satuan : null;
                break;

            case JenisSoal::Uraian:
                $rubrikMentah = $this->rubrikDari($mentah['rubrik'] ?? []);
                $kiriman['rubrik'] = array_map(fn (array $r) => [
                    'kriteria' => $r['criterion'],
                    'keterangan' => $r['description'] ?? '',
                    'skor_maks' => $r['max_score'],
                ], $rubrikMentah);

                // Bobot = jumlah rubrik. Rubrik kosong tetap bernilai 1 supaya
                // yang ditolak adalah "uraian butuh rubrik", bukan "bobot nol".
                $bobot = $rubrikMentah === []
                    ? 1.0
                    : array_sum(array_column($rubrikMentah, 'max_score'));

                // Kunci uraian WAJIB null: `SkemaJenisSoal` menolak kunci
                // berisi untuk jenis ini, karena soal uraian dinilai manual.
                $kunciMentah = null;
                break;

            default:
                throw new GalatPenyedia('Jenis soal itu belum bisa dibuat AI.');
        }

        return [
            'bobot' => (float) $bobot,
            'kiriman' => $kiriman,
            'opsi_mentah' => $opsiMentah,
            'butir_mentah' => $butirMentah,
            'rubrik_mentah' => $rubrikMentah,
            'kunci_mentah' => $kunciMentah,
        ];
    }

    /**
     * Item menjodohkan, sisi kirinya, sisi kanannya, dan pasangannya.
     *
     * =====================================================================
     * Kunci kanan DIBANGKITKAN, dan itu bukan pilihan gaya
     * =====================================================================
     * Model bisa membalas kunci kanan berupa apa saja - "n1", "jakarta",
     * "B". Semua bentuk itu sah selama `pasangan` menunjuk kunci yang sama,
     * dan justru itulah masalahnya: dua keluaran yang sama-sama benar akan
     * tersimpan dengan bentuk yang berbeda, dan yang membaca tabel nanti harus
     * menebak konvensinya.
     *
     * Yang dipakai: kunci kanan menjadi `p{nomor urut kanan}` - pendek, unik,
     * di bawah batas 16 karakter `question_items.item_key`, dan sama untuk
     * setiap soal. Kunci KIRI dibiarkan apa adanya dari model, karena ia yang
     * disebut `pasangan`.
     *
     * Kalau model membalas bentuk lama (satu larik `butir` bergrup
     * kiri/kanan), bentuk itu dipakai lewat `jodohDariButir()`.
     *
     * @param  array<string, mixed>  $mentah
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array{kunci: string, grup: string, teks: string, pasangan: string|null}>, 2: array<string, string>}
     */
    private function jodohDari(array $mentah): array
    {
        $kiri = $this->daftarItem($mentah['kiri'] ?? []);
        $kanan = $this->daftarItem($mentah['kanan'] ?? []);

        // Bentuk lama: `butir` berisi grup kiri/kanan. Dipakai bila `kiri`
        // dan `kanan` tidak ada sama sekali.
        if ($kiri === [] || $kanan === []) {
            return $this->jodohDariButir($mentah);
        }

        $pasanganMentah = is_array($mentah['pasangan'] ?? null) ? $mentah['pasangan'] : [];

        /** @var array<string, int> $nomorKanan kunci kanan model -> indeksnya */
        $nomorKanan = [];

        foreach ($kanan as $i => $item) {
            $nomorKanan[$item['kunci']] = $i;
        }

        // Kunci kanan yang baru, per indeks. `p1`, `p2`, ...
        $kunciKananBaru = fn (int $i): string => 'p'.($i + 1);

        $butirMentah = [];
        $butirKirim = [];
        $pasangan = [];

        foreach ($kiri as $item) {
            $kunciKiri = $item['kunci'];

            $butirMentah[] = [
                'item_key' => $kunciKiri,
                'item_group' => 'kiri',
                'content_json' => ['teks' => $item['teks']],
                'correct_match_key' => null,
                'position' => count($butirMentah),
            ];

            // Pasangan yang tidak menunjuk item kanan mana pun DILEWATKAN di
            // sini, bukan diganti tebakan: `SkemaJenisSoal` menolak item kiri
            // tanpa pasangan dengan pesan yang menyebut arti kegagalannya.
            // Menebak berarti memasangkan dua hal yang tidak dimaksud model.
            $tujuan = $pasanganMentah[$kunciKiri] ?? null;

            if (is_string($tujuan) && isset($nomorKanan[$tujuan])) {
                $pasangan[$kunciKiri] = $kunciKananBaru($nomorKanan[$tujuan]);
            }
        }

        foreach ($kanan as $i => $item) {
            $butirMentah[] = [
                'item_key' => $kunciKananBaru($i),
                'item_group' => 'kanan',
                'content_json' => ['teks' => $item['teks']],
                'correct_match_key' => null,
                'position' => count($butirMentah),
            ];
        }

        foreach ($butirMentah as $b) {
            $butirKirim[] = [
                'kunci' => $b['item_key'],
                'grup' => $b['item_group'],
                'teks' => $b['content_json']['teks'],
                // Hanya sisi kiri yang punya pasangan; sisi kanan tampil
                // sebagai pilihan, bukan sebagai jawaban.
                'pasangan' => $b['item_group'] === 'kiri' ? ($pasangan[$b['item_key']] ?? null) : null,
            ];
        }

        return [$butirMentah, $butirKirim, $pasangan];
    }

    /**
     * Bentuk lama: satu larik `butir` bergrup kiri/kanan.
     *
     * @param  array<string, mixed>  $mentah
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array{kunci: string, grup: string, teks: string, pasangan: string|null}>, 2: array<string, string>}
     */
    private function jodohDariButir(array $mentah): array
    {
        $butirMentah = [];
        $butirKirim = [];

        foreach (is_array($mentah['butir'] ?? null) ? $mentah['butir'] : [] as $i => $b) {
            if (! is_array($b)) {
                continue;
            }

            $kunci = $this->bersihkanSatu($b['kunci'] ?? $b['item_key'] ?? null);
            $teks = $this->bersihkanSatu($b['teks'] ?? null);
            $grup = ($b['grup'] ?? $b['item_group'] ?? 'kiri') === 'kanan' ? 'kanan' : 'kiri';

            if ($kunci === '' || $teks === '') {
                continue;
            }

            $butirMentah[] = [
                'item_key' => $kunci,
                'item_group' => $grup,
                'content_json' => ['teks' => $teks],
                'correct_match_key' => null,
                'position' => count($butirMentah),
            ];
            $butirKirim[] = [
                'kunci' => $kunci,
                'grup' => $grup,
                'teks' => $teks,
                'pasangan' => null,
            ];
        }

        $pasangan = [];

        foreach (is_array($mentah['pasangan'] ?? null) ? $mentah['pasangan'] : [] as $dari => $ke) {
            $pasangan[(string) $dari] = (string) $ke;
        }

        return [$butirMentah, $butirKirim, $pasangan];
    }

    /**
     * Item mengurutkan dan urutan benarnya.
     *
     * Kuncinya TIDAK dibangkitkan ulang menjadi `u1..uN`, berbeda dari
     * menjodohkan. Alasannya: di sini urutan itu SENDIRI adalah jawabannya,
     * dan membangkitkan kunci baru berarti menerjemahkan permutasi dari kunci
     * lama ke kunci baru - satu peta tambahan yang bisa salah tanpa suara.
     * Kunci model yang unik dan tidak kosong sudah cukup, dan `SkemaJenisSoal`
     * memeriksa kelengkapan serta keunikannya.
     *
     * @param  array<string, mixed>  $mentah
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array{kunci: string, grup: string, teks: string, pasangan: string|null}>, 2: array<int, string>}
     */
    private function urutanDari(array $mentah): array
    {
        $butirMentah = [];
        $butirKirim = [];
        $kunciAda = [];

        foreach (is_array($mentah['butir'] ?? null) ? $mentah['butir'] : [] as $b) {
            if (! is_array($b)) {
                continue;
            }

            $kunci = $this->bersihkanSatu($b['kunci'] ?? $b['item_key'] ?? null);
            $teks = $this->bersihkanSatu($b['teks'] ?? null);

            if ($kunci === '' || $teks === '' || in_array($kunci, $kunciAda, true)) {
                continue;
            }

            $kunciAda[] = $kunci;

            $butirMentah[] = [
                'item_key' => $kunci,
                'item_group' => 'kiri',
                'content_json' => ['teks' => $teks],
                'correct_match_key' => null,
                'correct_position' => null,
                'position' => count($butirMentah),
            ];
            $butirKirim[] = [
                'kunci' => $kunci,
                'grup' => 'kiri',
                'teks' => $teks,
                'pasangan' => null,
            ];
        }

        $urutan = array_values(array_map(
            fn ($u) => (string) $u,
            is_array($mentah['urutan'] ?? null) ? $mentah['urutan'] : [],
        ));

        return [$butirMentah, $butirKirim, $urutan];
    }

    /**
     * Larik `[{kunci, teks}]` dari keluaran model, atau dari bentuk lain yang
     * setara (`{item_key, content_json}`).
     *
     * @return array<int, array{kunci: string, teks: string}>
     */
    private function daftarItem(mixed $daftar): array
    {
        $hasil = [];

        foreach (is_array($daftar) ? $daftar : [] as $i => $satu) {
            if (! is_array($satu)) {
                continue;
            }

            $kunci = $this->bersihkanSatu($satu['kunci'] ?? $satu['item_key'] ?? null);
            $teks = $this->bersihkanSatu(
                $satu['teks'] ?? $satu['text'] ?? ($satu['content_json']['teks'] ?? null),
            );

            if ($kunci === '' || $teks === '') {
                continue;
            }

            $hasil[] = ['kunci' => $kunci, 'teks' => $teks];
        }

        return $hasil;
    }

    /**
     * Susun opsi dengan huruf tetap, dan buang yang teksnya kosong.
     *
     * @param  array<int, string>  $huruf
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array{kunci: string, teks: string}>}
     */
    private function opsiTerurut(mixed $opsi, array $huruf): array
    {
        $isi = [];

        foreach (is_array($opsi) ? $opsi : [] as $satu) {
            $teks = $this->bersihkanSatu(is_array($satu) ? ($satu['teks'] ?? $satu['text'] ?? null) : $satu);

            if ($teks !== '') {
                $isi[] = $teks;
            }
        }

        $mentah = [];
        $kiriman = [];

        foreach ($huruf as $i => $k) {
            if (! isset($isi[$i])) {
                continue;
            }

            $mentah[] = [
                'option_key' => $k,
                'content_json' => ['teks' => $isi[$i]],
                'position' => $i,
            ];
            $kiriman[] = ['kunci' => $k, 'teks' => $isi[$i]];
        }

        return [$mentah, $kiriman];
    }

    /**
     * Kunci pilihan ganda sebagai huruf.
     *
     * Menerima `["a"]`, `"a"`, `["A"]`, dan `["0"]`. Angka dipetakan ke huruf
     * lewat posisinya, karena model yang menghitung dari nol adalah kekeliruan
     * yang paling sering muncul dan paling mudah dirapikan.
     *
     * @param  array<int, string>  $huruf  Huruf yang boleh dipakai jenis ini.
     * @return array<int, string>
     */
    private function kunciHuruf(mixed $kunci, array $huruf = self::HURUF_OPSI): array
    {
        $daftar = is_array($kunci) ? $kunci : [$kunci];
        $hasil = [];

        foreach ($daftar as $satu) {
            $teks = strtolower(trim((string) $satu));

            if ($teks === '') {
                continue;
            }

            if (ctype_digit($teks)) {
                $teks = $huruf[(int) $teks] ?? $teks;
            }

            // Hanya huruf yang benar-benar ada di form yang diteruskan;
            // `SkemaJenisSoal` yang akan menolak bila hasil akhirnya bukan
            // tepat satu.
            if (in_array($teks, $huruf, true)) {
                $hasil[] = $teks;
            }
        }

        return array_values(array_unique($hasil));
    }

    private function normalisasiBenarSalah(mixed $kunci): string
    {
        $daftar = is_array($kunci) ? $kunci : [$kunci];
        $teks = strtolower(trim((string) ($daftar[0] ?? '')));

        return match ($teks) {
            'b', 'benar', 'true', '1', 'ya' => 'b',
            's', 'salah', 'false', '0', 'tidak' => 's',
            // Diteruskan apa adanya supaya `SkemaJenisSoal` yang menolaknya
            // dengan pesan yang benar - menebak di sini berarti menyimpan
            // kunci yang mungkin kebalikan dari maksud model.
            default => $teks,
        };
    }

    /**
     * @return array<int, string>
     */
    private function daftarTeks(mixed $nilai): array
    {
        $daftar = is_array($nilai) ? $nilai : [$nilai];
        $hasil = [];

        foreach ($daftar as $satu) {
            $teks = $this->bersihkanSatu(is_array($satu) ? ($satu['teks'] ?? $satu['text'] ?? null) : $satu);

            if ($teks !== '') {
                $hasil[] = $teks;
            }
        }

        return array_values(array_unique($hasil));
    }

    /**
     * Rubrik dalam bentuk `SkemaJenisSoal` dan `BankSoalService`.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rubrikDari(mixed $rubrik): array
    {
        $hasil = [];

        foreach (is_array($rubrik) ? $rubrik : [] as $i => $baris) {
            if (! is_array($baris)) {
                continue;
            }

            $kriteria = $this->bersihkanSatu($baris['kriteria'] ?? $baris['criterion'] ?? null);

            if ($kriteria === '') {
                continue;
            }

            $hasil[] = [
                'criterion' => $kriteria,
                'description' => $this->bersihkanSatu($baris['keterangan'] ?? $baris['description'] ?? null) ?: null,
                'max_score' => (float) ($baris['skor_maks'] ?? $baris['max_score'] ?? 1),
                'position' => $i,
            ];
        }

        return $hasil;
    }

    /** Bersihkan satu nilai teks, atau string kosong bila bukan teks. */
    private function bersihkanSatu(mixed $nilai): string
    {
        if (! is_scalar($nilai) && $nilai !== null) {
            return '';
        }

        return $this->pembersih->bersihkan((string) $nilai);
    }
}

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
 */
class PenulisSoalAi
{
    /**
     * Jenis yang boleh dihasilkan AI.
     *
     * Empat, bukan sepuluh. Menjodohkan, mengurutkan, dan angka punya kunci
     * yang jauh lebih mudah salah dihasilkan - dan kesalahannya baru terlihat
     * saat ujian berjalan, ketika siswa melihat item kanan yang tidak punya
     * pasangan. Jabawan ganda dan media sudah punya bentuk kunci gabungan
     * yang menuntut pemetaan tambahan tanpa manfaat yang sepadan.
     *
     * @var array<int, string>
     */
    public const JENIS_DIDUKUNG = [
        JenisSoal::PilihanGanda->value,
        JenisSoal::BenarSalah->value,
        JenisSoal::IsianSingkat->value,
        JenisSoal::Uraian->value,
    ];

    /** Huruf opsi untuk pilihan ganda. Empat, sesuai bentuk biasa soal sekolah. */
    private const HURUF_OPSI = ['a', 'b', 'c', 'd'];

    public function __construct(
        private readonly PengaturanAiSekolah $pengaturan,
        private readonly KlienAi $klien,
        private readonly PembersihHtml $pembersih,
        private readonly SkemaJenisSoal $skema,
    ) {}

    /**
     * Susun satu soal.
     *
     * @return array{
     *     judul: string,
     *     jenis: string,
     *     pertanyaan: string,
     *     pembahasan: string|null,
     *     opsi: array<int, array{kunci: string, teks: string}>,
     *     kunci: array<int, string>,
     *     varian: array<int, string>,
     *     rubrik: array<int, array{kriteria: string, keterangan: string, skor_maks: int}>
     * }
     *
     * @throws GalatPenyedia
     * @throws ValidationException
     */
    public function susun(int $subjectId, JenisSoal $jenis, string $topik, string $tingkat): array
    {
        $konfigurasi = $this->konfigurasi();

        $mapel = Subject::query()->find($subjectId);

        // `UrlPenyedia::pastikan` dipanggil lagi di sini meski sudah dipanggil
        // saat menyimpan: nilai di basis data bisa berubah lewat seeder, perintah
        // artisan, atau baris yang disunting langsung. Pemeriksaan yang hanya
        // hidup di satu jalur masuk adalah pemeriksaan yang dilewati jalur lain.
        UrlPenyedia::pastikan((string) $konfigurasi['base_url']);

        $mentah = $this->klien->kirim($konfigurasi, [
            ['role' => 'system', 'content' => $this->instruksi($jenis)],
            ['role' => 'user', 'content' => $this->permintaan($mapel?->name ?? 'umum', $topik, $tingkat)],
        ]);

        $hasil = $this->petakan($jenis, $mentah);

        // ---------------------------------------------------------------
        // Validasi di SERVER, sebelum respons pulang
        // ---------------------------------------------------------------
        // Kalau keluaran model yang tidak sah dibiarkan lewat, Guru menerima
        // form yang tampak wajar lalu galat saat menekan Simpan - setelah ia
        // mungkin menyuntingnya sepuluh menit. Yang gagal harus permintaan
        // ini, sekarang, dengan pesan yang bisa ia tindak lanjuti.
        $this->skema->validasi(
            $jenis,
            $hasil['kunci_mentah'],
            1.0,
            $hasil['opsi_mentah'],
            [],
            $hasil['rubrik_mentah'],
            ['bobot' => 1],
        );

        return $hasil['kiriman'];
    }

    /**
     * Konfigurasi yang siap dipakai `KlienAi`.
     *
     * @return array{base_url:string, model:string, api_key:string, batas_token:int}
     */
    private function konfigurasi(): array
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
            'batas_token' => (int) ($pengaturan->batas_token ?: 1500),
        ];
    }

    /**
     * Instruksi sistem, DITULIS PER JENIS.
     *
     * Satu skema umum dengan field opsional adalah godaan yang salah: model
     * yang diberi pilihan bentuk memilih bentuk yang salah kira-kira separuh
     * waktu. Skema yang menyebut persis bentuk yang diharapkan menghasilkan
     * keluaran yang bisa divalidasi, dan validasinya bermakna.
     */
    private function instruksi(JenisSoal $jenis): string
    {
        $awalan = <<<'TXT'
            Anda penulis soal ujian untuk sekolah menengah di Indonesia.
            Tulis dalam Bahasa Indonesia yang baku dan jelas.
            Seluruh teks boleh memuat HTML sederhana (<p>, <b>, <i>, <sup>, <sub>), tanpa CSS dan tanpa gambar.
            Balas HANYA dengan satu objek JSON, tanpa penjelasan di luar JSON.

            TXT;

        return $awalan.match ($jenis) {
            JenisSoal::PilihanGanda => <<<'TXT'
                Bentuk JSON yang wajib:
                {"judul":"...","pertanyaan":"...","pembahasan":"...",
                 "opsi":[{"kunci":"a","teks":"..."},{"kunci":"b","teks":"..."},{"kunci":"c","teks":"..."},{"kunci":"d","teks":"..."}],
                 "kunci":["a"]}
                Aturan: tepat empat opsi dengan kunci a, b, c, d; "kunci" berisi TEPAT SATU huruf;
                pengecoh harus masuk akal, bukan jawaban yang jelas salah.

                TXT,

            JenisSoal::BenarSalah => <<<'TXT'
                Bentuk JSON yang wajib:
                {"judul":"...","pertanyaan":"...","pembahasan":"...",
                 "opsi":[{"kunci":"b","teks":"Benar"},{"kunci":"s","teks":"Salah"}],
                 "kunci":["b"]}
                Aturan: "opsi" SELALU tepat dua, dengan kunci "b" (Benar) dan "s" (Salah);
                "kunci" berisi TEPAT SATU dari "b" atau "s". Pernyataan harus utuh dan
                benar-benar bisa dinilai benar atau salah, tanpa kata "tidak selalu".

                TXT,

            JenisSoal::IsianSingkat => <<<'TXT'
                Bentuk JSON yang wajib:
                {"judul":"...","pertanyaan":"...","pembahasan":"...",
                 "opsi":[],"kunci":["jawaban yang diterima","ejaan lain yang juga benar"]}
                Aturan: jawaban singkat, satu sampai tiga kata. Sebutkan bentuk alternatif yang
                sama-sama benar (singkatan, tanpa spasi, beda huruf besar) supaya siswa yang
                menjawab benar tidak dinilai salah karena ejaan.

                TXT,

            JenisSoal::Uraian => <<<'TXT'
                Bentuk JSON yang wajib:
                {"judul":"...","pertanyaan":"...","pembahasan":"...","opsi":[],"kunci":[],
                 "rubrik":[{"kriteria":"...","keterangan":"...","skor_maks":2}]}
                Aturan: "kunci" SELALU array kosong - soal uraian dinilai manual, kuncinya adalah
                rubrik. Rubrik berisi dua sampai empat kriteria, dan jumlah skor_maks-nya adalah
                nilai penuh soal itu.

                TXT,

            default => throw new GalatPenyedia('Jenis soal itu belum bisa dibuat AI.'),
        };
    }

    private function permintaan(string $mapel, string $topik, string $tingkat): string
    {
        $peta = [
            'mudah' => 'tingkat kesulitan mudah (pemahaman dasar)',
            'sedang' => 'tingkat kesulitan sedang (penerapan)',
            'sulit' => 'tingkat kesulitan sulit (analisis)',
        ];

        $sulit = $peta[$tingkat] ?? $peta['sedang'];

        return "Mata pelajaran: {$mapel}\n"
            ."Topik: {$topik}\n"
            ."Tingkat: {$sulit}\n"
            .'Buat satu soal saja.';
    }

    /**
     * Petakan keluaran model ke bentuk yang dipakai builder.
     *
     * Tiga keluaran sekaligus: `kiriman` (untuk peramban), `opsi_mentah` dan
     * `rubrik_mentah` (untuk `SkemaJenisSoal`, yang bentuknya mengikuti
     * `BankSoalService`), dan `kunci_mentah`.
     *
     * @param  array<string, mixed>  $mentah
     * @return array{kiriman: array<string, mixed>, opsi_mentah: array<int, array<string, mixed>>, rubrik_mentah: array<int, array<string, mixed>>, kunci_mentah: array<string, mixed>|null}
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
            'rubrik' => [],
        ];

        $opsiMentah = [];
        $rubrikMentah = [];
        $kunciMentah = null;

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

            case JenisSoal::Uraian:
                $rubrikMentah = $this->rubrikDari($mentah['rubrik'] ?? []);
                $kiriman['rubrik'] = array_map(fn (array $r) => [
                    'kriteria' => $r['criterion'],
                    'keterangan' => $r['description'] ?? '',
                    'skor_maks' => $r['max_score'],
                ], $rubrikMentah);

                // Kunci uraian WAJIB null: `SkemaJenisSoal` menolak kunci
                // berisi untuk jenis ini, karena soal uraian dinilai manual.
                $kunciMentah = null;
                break;

            default:
                throw new GalatPenyedia('Jenis soal itu belum bisa dibuat AI.');
        }

        return [
            'kiriman' => $kiriman,
            'opsi_mentah' => $opsiMentah,
            'rubrik_mentah' => $rubrikMentah,
            'kunci_mentah' => $kunciMentah,
        ];
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
     * @return array<int, string>
     */
    private function kunciHuruf(mixed $kunci): array
    {
        $daftar = is_array($kunci) ? $kunci : [$kunci];
        $hasil = [];

        foreach ($daftar as $satu) {
            $teks = strtolower(trim((string) $satu));

            if ($teks === '') {
                continue;
            }

            if (ctype_digit($teks)) {
                $teks = self::HURUF_OPSI[(int) $teks] ?? $teks;
            }

            // Hanya huruf yang benar-benar ada di form yang diteruskan;
            // `SkemaJenisSoal` yang akan menolak bila hasil akhirnya bukan
            // tepat satu.
            if (in_array($teks, self::HURUF_OPSI, true)) {
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

<?php

namespace App\Domain\Grading\Support;

use App\Domain\Exam\Models\ExamQuestion;
use App\Domain\Question\Enums\JenisSoal;

/**
 * Penilaian otomatis SATU butir - PRD §13.1-§13.3, blueprint `:1270`.
 *
 * =====================================================================
 * Kelas ini murni: tidak menyentuh basis data, tidak menyentuh jam
 * =====================================================================
 * Masukannya snapshot soal dan isi jawaban; keluarannya benar/salah dan
 * poin. Tidak ada kueri, tidak ada `now()`, tidak ada `auth()`. Itu yang
 * membuatnya bisa diuji dengan sembilan kasus dalam milidetik - dan
 * sembilan kasus itu memang harus diuji, karena kesembilan bentuk kunci ini
 * adalah tempat kesalahan paling mahal di seluruh sistem: kunci yang salah
 * bentuk tersimpan rapi, ujian berjalan lancar, dan 300 siswa dinilai dengan
 * kunci yang tidak pernah cocok.
 *
 * Yang MENULIS hasilnya adalah PenilaiOtomatis. Pemisahan itu disengaja:
 * aturan penilaian dan penyimpanannya berubah karena alasan yang berbeda.
 *
 * =====================================================================
 * Bobotnya dari SNAPSHOT, bukan dari bank soal
 * =====================================================================
 * `ExamQuestion::bobotBerlaku()` - yang sudah memperhitungkan
 * `points_override` paket saat dibekukan. Membaca bobot dari `questions` atau
 * `question_package_items` berarti nilai ujian yang sedang berjalan berubah
 * saat Guru menyunting paketnya - persis yang dicegah snapshot.
 *
 * =====================================================================
 * Skor parsial adalah KONFIGURASI, bukan sifat jenis soal
 * =====================================================================
 * Blueprint `:1270`: "termasuk mode skor parsial multiple answers sesuai
 * konfigurasi tiap soal". Karena itu `skor_json.skor_parsial === true` yang
 * memutuskannya, dan tanpa konfigurasi itu jawabannya dinilai utuh.
 *
 * Aturan parsialnya SENGAJA hanya untuk tiga jenis yang punya lebih dari satu
 * bagian - jawaban ganda, menjodohkan, mengurutkan. Pilihan ganda dan
 * benar/salah tidak punya bagian untuk diberi sebagian: satu pilihan yang
 * benar dari dua kunci tidak ada artinya ketika kuncinya hanya satu.
 *
 * =====================================================================
 * Jawaban yang KOSONG dinilai salah, dan tetap ditandai dinilai
 * =====================================================================
 * `skor = 0, benar = false`. Yang TIDAK boleh terjadi adalah `benar = null`:
 * null berarti "belum dikoreksi", dan soal objektif yang belum dikoreksi
 * adalah soal yang tidak akan pernah dikoreksi - mesinnya sudah selesai
 * bekerja saat itu juga.
 */
class PenilaiButir
{
    /** Poin untuk satu butir, menurut snapshotnya. */
    public const TOLERANSI_BAWAAN = 0.0;

    /**
     * Nilai satu butir.
     *
     * @param  array<string, mixed>|null  $jawaban  Isi `answer_json`.
     * @return array{benar: bool|null, skor: float}
     *                                              `benar` null berarti butir ini BUKAN urusan mesin (uraian).
     *                                              `skor` selalu dalam satuan poin butir, bukan persen.
     */
    public function nilai(ExamQuestion $soal, ?array $jawaban): array
    {
        // Uraian: mesin tidak menyentuhnya sama sekali. Mengembalikan
        // `benar = false, skor = 0` akan membuat setiap uraian yang belum
        // dikoreksi tercatat salah - dan laporan yang membacanya tidak bisa
        // membedakan "belum dinilai" dari "salah".
        if ($soal->jenis === JenisSoal::Uraian) {
            return ['benar' => null, 'skor' => 0.0];
        }

        if (! $this->terisi($jawaban)) {
            return ['benar' => false, 'skor' => 0.0];
        }

        $bobot = $soal->bobotBerlaku();
        $kunci = $soal->kunci_json ?? [];
        $konfigurasi = $soal->skor_json ?? [];

        $pecahan = match ($soal->jenis) {
            JenisSoal::PilihanGanda, JenisSoal::BenarSalah => $this->pilihanTunggal($jawaban, $kunci),
            JenisSoal::JawabanGanda => $this->jawabanGanda($jawaban, $kunci, $konfigurasi),
            JenisSoal::IsianSingkat => $this->isian($jawaban, $kunci),
            JenisSoal::Menjodohkan => $this->jodohkan($jawaban, $kunci, $konfigurasi),
            JenisSoal::Mengurutkan => $this->mengurutkan($jawaban, $kunci, $konfigurasi),
            JenisSoal::Angka => $this->angka($jawaban, $kunci),
            JenisSoal::Media => $this->media($jawaban, $kunci, $konfigurasi),
            default => 0.0,
        };

        $pecahan = max(0.0, min(1.0, $pecahan));

        return [
            'benar' => $pecahan >= 1.0,
            // Dibulatkan ke dua desimal, sama dengan presisi kolomnya.
            // Tanpa ini, 1/3 x 12 = 3.9999999999999996 tersimpan sebagai 4.00
            // tetapi terbaca sebagai "tidak penuh" oleh perbandingan apa pun
            // yang memakai nilai mentahnya.
            'skor' => round($pecahan * $bobot, 2),
        ];
    }

    /* ------------------------------------------------------- Per jenis */

    /**
     * Satu pilihan benar, tepat satu kunci.
     *
     * @param  array<string, mixed>  $jawaban
     * @param  array<string, mixed>  $kunci
     */
    private function pilihanTunggal(array $jawaban, array $kunci): float
    {
        $dipilih = $this->daftarOpsi($jawaban);
        $benar = $this->daftarOpsi($kunci);

        if ($benar === []) {
            return 0.0;
        }

        // Himpunan, bukan urutan: mengklik "A" lalu "B" pada soal yang
        // kuncinya "B" tidak boleh berbeda hasilnya dari mengklik "B" saja.
        sort($dipilih);
        sort($benar);

        return $dipilih === $benar ? 1.0 : 0.0;
    }

    /**
     * Jawaban ganda, dengan atau tanpa skor parsial.
     *
     * Rumus parsialnya adalah bentuk yang dipakai ujian sekolah pada umumnya:
     *
     *     (benar dipilih - salah dipilih) / jumlah kunci
     *
     * Memilih seluruh opsi menghasilkan 0, bukan nilai tinggi - dan itu yang
     * membuat rumus ini layak dipakai: strategi "centang semuanya" tidak
     * lebih baik daripada tidak menjawab. Hasil negatif dipotong ke nol oleh
     * pemanggil.
     *
     * @param  array<string, mixed>  $jawaban
     * @param  array<string, mixed>  $kunci
     * @param  array<string, mixed>  $konfigurasi
     */
    private function jawabanGanda(array $jawaban, array $kunci, array $konfigurasi): float
    {
        $dipilih = $this->daftarOpsi($jawaban);
        $benar = $this->daftarOpsi($kunci);

        if ($benar === []) {
            return 0.0;
        }

        $tepat = count(array_intersect($dipilih, $benar));
        $salah = count(array_diff($dipilih, $benar));

        if (! $this->parsial($konfigurasi)) {
            return $tepat === count($benar) && $salah === 0 ? 1.0 : 0.0;
        }

        return ($tepat - $salah) / count($benar);
    }

    /**
     * Isian singkat - dicocokkan ke DAFTAR varian.
     *
     * Perbandingannya dinormalkan, dan itu bukan kelonggaran yang diminta
     * siapa pun melainkan kenyataan yang harus diterima: siswa mengetik
     * "Jantung " dengan spasi di ujung, "JANTUNG" dengan Caps Lock yang
     * menyala, dan "jantung." karena terbiasa mengakhiri kalimat. Menolak
     * ketiganya berarti mesin menilai cara mengetik, bukan jawaban.
     *
     * Yang TIDAK dinormalkan: isinya. "jantung" dan "jantng" tetap berbeda.
     *
     * @param  array<string, mixed>  $jawaban
     * @param  array<string, mixed>  $kunci
     */
    private function isian(array $jawaban, array $kunci): float
    {
        $diketik = $this->teks($jawaban['teks'] ?? null);

        if ($diketik === '') {
            return 0.0;
        }

        $varian = $kunci['teks'] ?? [];

        if (! is_array($varian)) {
            return 0.0;
        }

        foreach ($varian as $satu) {
            if ($this->normalkanTeks((string) $satu) === $this->normalkanTeks($diketik)) {
                return 1.0;
            }
        }

        return 0.0;
    }

    /**
     * Menjodohkan - pasangan kiri ke kanan.
     *
     * @param  array<string, mixed>  $jawaban
     * @param  array<string, mixed>  $kunci
     * @param  array<string, mixed>  $konfigurasi
     */
    private function jodohkan(array $jawaban, array $kunci, array $konfigurasi): float
    {
        $dipasangkan = $jawaban['pasangan'] ?? [];
        $benar = $kunci['pasangan'] ?? [];

        if (! is_array($dipasangkan) || ! is_array($benar) || $benar === []) {
            return 0.0;
        }

        $tepat = 0;

        foreach ($benar as $kiri => $kanan) {
            if (($dipasangkan[$kiri] ?? null) === $kanan) {
                $tepat++;
            }
        }

        if (! $this->parsial($konfigurasi)) {
            return $tepat === count($benar) ? 1.0 : 0.0;
        }

        return $tepat / count($benar);
    }

    /**
     * Mengurutkan - permutasi.
     *
     * Parsialnya dihitung dari POSISI yang benar, bukan dari pasangan
     * berurutan. Itu pilihan yang lebih ketat, dan yang lebih adil: siswa
     * yang menukar dua langkah berdekatan mendapat kredit untuk langkah lain
     * yang memang sudah di tempatnya.
     *
     * @param  array<string, mixed>  $jawaban
     * @param  array<string, mixed>  $kunci
     * @param  array<string, mixed>  $konfigurasi
     */
    private function mengurutkan(array $jawaban, array $kunci, array $konfigurasi): float
    {
        $diketik = $jawaban['urutan'] ?? [];
        $benar = $kunci['urutan'] ?? [];

        if (! is_array($diketik) || ! is_array($benar) || $benar === []) {
            return 0.0;
        }

        $diketik = array_values($diketik);
        $benar = array_values($benar);

        if ($diketik === $benar) {
            return 1.0;
        }

        if (! $this->parsial($konfigurasi)) {
            return 0.0;
        }

        $tepat = 0;

        foreach ($benar as $posisi => $kunciButir) {
            if (($diketik[$posisi] ?? null) === $kunciButir) {
                $tepat++;
            }
        }

        return $tepat / count($benar);
    }

    /**
     * Angka, dengan toleransi dan satuan.
     *
     * ---------------------------------------------------------------------
     * Mengapa satuannya DIBUANG dari jawaban siswa, bukan diwajibkan
     * ---------------------------------------------------------------------
     * Kuncinya `{"nilai":3.14,"toleransi":0.01,"satuan":"cm"}`. Siswa yang
     * mengetik "3,14 cm" sudah menjawab benar - dan siswa yang mengetik
     * "3,14 m" menjawab besaran yang sama dengan satuan yang salah.
     *
     * Yang dipakai di sini adalah aturan yang paling tidak bisa
     * dipersoalkan: satuan pada jawaban DIBUANG sebelum angkanya dibandingkan,
     * sehingga keduanya dinilai benar. Menilai satuan sebagai bagian dari
     * jawaban berarti soal aritmetika menghukum kesalahan penulisan - dan
     * kalau Guru memang ingin menguji satuannya, itu pekerjaan soal uraian
     * dengan rubrik, bukan soal angka.
     *
     * ---------------------------------------------------------------------
     * Koma desimal diterima
     * ---------------------------------------------------------------------
     * Siswa Indonesia mengetik "3,14". Membacanya sebagai 3 dan 14 sekaligus
     * adalah cara paling cepat membuat soal angka selalu salah.
     *
     * @param  array<string, mixed>  $jawaban
     * @param  array<string, mixed>  $kunci
     */
    private function angka(array $jawaban, array $kunci): float
    {
        $target = $kunci['nilai'] ?? null;

        if (! is_numeric($target)) {
            return 0.0;
        }

        $diketik = $this->angkaDari($jawaban['nilai'] ?? null);

        if ($diketik === null) {
            return 0.0;
        }

        $toleransi = $kunci['toleransi'] ?? self::TOLERANSI_BAWAAN;

        if (! is_numeric($toleransi)) {
            $toleransi = self::TOLERANSI_BAWAAN;
        }

        // `+ 1e-9` menutup galat pembulatan biner: |3.14 - 3.14| bisa
        // menghasilkan 4.44e-16, dan toleransi 0.01 tidak menolong bila
        // toleransinya 0. Kasus yang paling sering justru toleransi 0.
        return abs((float) $target - $diketik) <= (float) $toleransi + 1e-9 ? 1.0 : 0.0;
    }

    /**
     * Media - mekanismenya diambil dari konfigurasi, bukan dari jenisnya.
     *
     * Soal media adalah soal yang STIMULUSNYA berupa berkas; bentuk
     * jawabannya tetap salah satu dari yang lain. `scoring_config_json`
     * menyimpan `mekanisme`, dan itu satu-satunya tempat yang tahu bentuk
     * kuncinya - lihat catatan `JenisSoal::otomatis()`.
     *
     * @param  array<string, mixed>  $jawaban
     * @param  array<string, mixed>  $kunci
     * @param  array<string, mixed>  $konfigurasi
     */
    private function media(array $jawaban, array $kunci, array $konfigurasi): float
    {
        return match ($konfigurasi['mekanisme'] ?? 'pilihan') {
            'isian' => $this->isian($jawaban, $kunci),
            'benar_salah' => $this->pilihanTunggal($jawaban, $kunci),
            default => $this->pilihanTunggal($jawaban, $kunci),
        };
    }

    /* ------------------------------------------------------------- Pembantu */

    /** @param array<string, mixed> $konfigurasi */
    private function parsial(array $konfigurasi): bool
    {
        return ($konfigurasi['skor_parsial'] ?? false) === true;
    }

    /**
     * Jawaban yang benar-benar berisi jawaban.
     *
     * `{"opsi":[]}` dan `{}` sama-sama kosong - alasan yang sama dengan
     * `StudentAnswer::terjawab()`.
     *
     * @param  array<string, mixed>|null  $jawaban
     */
    private function terisi(?array $jawaban): bool
    {
        if ($jawaban === null) {
            return false;
        }

        foreach ($jawaban as $nilai) {
            if (is_array($nilai)) {
                if ($nilai !== []) {
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

    /**
     * Daftar kunci opsi yang dipilih, sebagai string.
     *
     * Nilai non-string dibuang, bukan di-cast diam-diam: `{"opsi":[1]}` yang
     * dibaca sebagai "1" akan cocok dengan kunci "1" yang seharusnya berbeda
     * dari opsi berhuruf.
     *
     * @param  array<string, mixed>  $isi
     * @return array<int, string>
     */
    private function daftarOpsi(array $isi): array
    {
        $opsi = $isi['opsi'] ?? [];

        if (! is_array($opsi)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($satu) => is_string($satu) ? trim($satu) : null, $opsi),
            fn (?string $satu) => $satu !== null && $satu !== '',
        ));
    }

    /** Teks jawaban sebagai string; selain itu kosong. */
    private function teks(mixed $nilai): string
    {
        if (is_string($nilai)) {
            return trim($nilai);
        }

        if (is_numeric($nilai)) {
            return (string) $nilai;
        }

        return '';
    }

    /**
     * Normalisasi teks isian: huruf kecil, spasi dirapatkan, tanda baca akhir
     * dibuang.
     *
     * `mb_strtolower` dipakai, bukan `strtolower`: "JANTUNG" dan "jantung"
     * berbeda hanya karena ASCII, sementara aplikasi ini menyimpan teks
     * berbahasa Indonesia yang bisa memuat karakter beraksen.
     */
    private function normalkanTeks(string $teks): string
    {
        $teks = trim(mb_strtolower($teks, 'UTF-8'));

        // Spasi berlebih, termasuk spasi tak-terlihat yang disisipkan
        // pengetikan ponsel.
        $teks = (string) preg_replace('/\s+/u', ' ', $teks);

        // Tanda baca akhir kalimat: titik, koma, titik koma. Tidak di tengah,
        // karena "3,14" bukan kalimat yang berakhir di koma.
        return (string) preg_replace('/[.,;:!?]+$/u', '', $teks);
    }

    /**
     * Angka dari isian siswa, dengan koma desimal dan satuan dibuang.
     *
     * Urutannya menentukan: koma desimal diubah lebih dulu, baru karakter
     * selain digit dibuang. Membalik urutannya membuat "3,14" menjadi "314".
     */
    private function angkaDari(mixed $nilai): ?float
    {
        if (is_numeric($nilai)) {
            return (float) $nilai;
        }

        if (! is_string($nilai)) {
            return null;
        }

        $teks = trim($nilai);

        // Tanda minus di awal dipertahankan; seluruh koma dianggap desimal.
        // "1.234,5" adalah bentuk Indonesia yang paling mungkin diketik, dan
        // membacanya sebagai 1.234 bukan salah - angka sebesar itu tidak
        // muncul di soal sekolah tanpa ditulis lengkap.
        $negatif = str_starts_with($teks, '-');
        $teks = ltrim($teks, '+-');
        $teks = str_replace('.', '', $teks);
        $teks = str_replace(',', '.', $teks);

        // Sisakan digit dan satu titik desimal pertama.
        if (preg_match('/\d+(?:\.\d+)?/', $teks, $cocok) !== 1) {
            return null;
        }

        $angka = (float) $cocok[0];

        return $negatif ? -$angka : $angka;
    }
}

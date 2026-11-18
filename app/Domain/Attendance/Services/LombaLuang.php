<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Models\LeisureGameScore;
use App\Domain\Attendance\Models\LeisureMatch;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Identity\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Lomba hitung cepat dua pemain: membuat, menerima, dan memutuskan hasilnya.
 *
 * =====================================================================
 * Soal dibangkitkan SEKALI, di server
 * =====================================================================
 * Enam puluh soal berisi teks dan empat pilihan; kunci jawabannya disimpan
 * bersama mereka, dan hanya soal serta pilihannya yang dikirim ke peramban.
 * Lihat catatan migrasi `leisure_matches` tentang mengapa daftarnya disimpan
 * alih-alih dibangkitkan ulang dari benih.
 *
 * Bentuk soalnya sengaja TIDAK menyesuaikan level per pemain - berbeda dari
 * permainan sendirian yang menaikkan kesulitan sesuai jawaban benar. Di
 * lomba, dua pemain harus menjawab soal yang sama; permainan yang menyesuaikan
 * diri akan memberi soal mudah ke yang tertinggal, dan itu bonus yang tidak
 * diminta siapa pun.
 *
 * =====================================================================
 * Pemain kedua memasang jamnya, dan hanya sekali
 * =====================================================================
 * `mulai_pada` diisi saat pemain kedua masuk, bukan saat pembuatnya menekan
 * tombol. Kalau pembuat yang menentukan jamnya, ia bisa menunggu sampai
 * undangannya diterima lalu memundurkannya - dan lawannya masuk ke lomba yang
 * sudah setengah jalan.
 *
 * Pemain kedua juga tidak menentukan jamnya sendiri: yang dipakai adalah jam
 * server saat ia masuk, ditambah `JEDA_MULAI_DETIK`. Keduanya membaca angka
 * yang sama.
 *
 * =====================================================================
 * Hasil ditulis ke tabel skor yang SUDAH ADA
 * =====================================================================
 * `leisure_game_scores` dengan `match_id` terisi. Rekor, papan peringkat, dan
 * kuota harian bekerja tanpa perubahan - lomba hanyalah permainan yang
 * kebetulan punya lawan. Yang TIDAK ditulis adalah pemenang: pemenang dibaca
 * dari skor kedua baris, dan memilihnya di sini berarti menyimpan angka yang
 * sama di dua tempat.
 */
final class LombaLuang
{
    /**
     * Alfabet kode undangan.
     *
     * Tanpa `I`, `O`, `0`, `1`, `L`, `Z`, `2`, `5` - huruf dan angka yang
     * tertukar saat dibacakan lewat lisan atau disalin dari layar ponsel.
     * Kode ini dibagikan anak ke temannya, sering dengan mengetik ulang, jadi
     * setiap pasangan yang mirip adalah satu kesalahan yang pasti terjadi.
     */
    private const ALFABET = 'ABCDEFGHJKMNPQRSTUVWXY346789';

    /** Panjang kode undangan. 27^6 ≈ 387 juta - jauh di luar jangkauan tebak. */
    private const PANJANG_KODE = 6;

    /** Jumlah soal dalam satu lomba. Cukup untuk 60 detik dengan sisa. */
    private const JUMLAH_SOAL = 60;

    /** Poin per jawaban benar - lihat `nilai()`. */
    public const POIN_PER_BENAR = 10;

    /** Berapa kali pencarian kode diulang sebelum menyerah. */
    private const COBA_KODE = 8;

    public function __construct(private readonly PenjagaSkorLuar $penjaga) {}

    /**
     * Membuat lomba baru dan mengembalikan kodenya.
     *
     * @return LeisureMatch lomba yang tersimpan, dengan kode undangannya.
     */
    public function buat(User $host, CarbonInterface $sekarang): LeisureMatch
    {
        return DB::transaction(function () use ($host, $sekarang) {
            return LeisureMatch::create([
                'school_id' => $host->school_id,
                'code' => $this->kodeUnik((int) $host->school_id),
                'game_type' => LeisureGameScore::GAME_HITUNG,
                'host_id' => $host->id,
                'soal' => $this->bangkitkanSoal(),
                'berlaku_sampai' => $sekarang->copy()->addMinutes(LeisureMatch::BERLAKU_MENIT),
            ]);
        });
    }

    /**
     * Pemain kedua masuk lewat kode undangan.
     *
     * Mengembalikan lomba yang sudah siap, atau null bila kodenya tidak
     * menunjuk lomba yang masih bisa dimasuki. Kegagalan yang berbeda -
     * kode salah, undangan kedaluwarsa, lombanya sudah berisi dua orang -
     * sengaja dijawab sama oleh pemanggilnya: kode yang salah dan kode yang
     * benar-tetapi-terlambat tidak bisa dibedakan penebaknya, dan membedakannya
     * berarti memberitahunya kode mana yang ada.
     */
    public function masuk(string $kode, User $tamu, CarbonInterface $sekarang): ?LeisureMatch
    {
        $kode = strtoupper(trim($kode));

        return DB::transaction(function () use ($kode, $tamu, $sekarang) {
            /** @var LeisureMatch|null $lomba */
            $lomba = LeisureMatch::query()
                ->where('code', $kode)
                ->lockForUpdate()
                ->first();

            if ($lomba === null || ! $this->bisaDimasuki($lomba, $tamu, $sekarang)) {
                return null;
            }

            $lomba->update([
                'guest_id' => $tamu->id,
                'mulai_pada' => $sekarang->copy()->addSeconds(LeisureMatch::JEDA_MULAI_DETIK),
            ]);

            return $lomba->refresh();
        });
    }

    /**
     * Apakah lomba ini boleh dimasuki pemain tersebut sekarang.
     *
     * Dipisahkan dari `masuk()` supaya aturannya bisa diuji tanpa basis data,
     * dan supaya daftar sebabnya terbaca di satu tempat.
     */
    public function bisaDimasuki(LeisureMatch $lomba, User $tamu, CarbonInterface $sekarang): bool
    {
        // Lomba milik satu sekolah. Kode yang ditebak tepat pada sekolah lain
        // tetap tidak boleh menyeberang - skor yang tercatat akan lahir di
        // papan peringkat sekolah yang salah.
        if ((int) $lomba->school_id !== (int) $tamu->school_id) {
            return false;
        }

        // Pembuatnya sendiri tidak bisa masuk sebagai lawan: satu anak dengan
        // dua tab akan mendapat dua skor dari satu lomba.
        if ((int) $lomba->host_id === (int) $tamu->id) {
            return false;
        }

        if ($lomba->dibatalkan() || $lomba->guest_id !== null) {
            return false;
        }

        return ! $lomba->kedaluwarsa($sekarang);
    }

    /**
     * Membatalkan lomba yang belum selesai.
     *
     * Sengaja TIDAK menulis skor apa pun. Lomba yang batal bukan permainan
     * yang kalah - dan skor yang ditulis untuk lomba yang tidak pernah
     * dimainkan adalah skor yang tidak bisa dipertanggungjawabkan di papan
     * peringkat.
     */
    public function batal(LeisureMatch $lomba, string $alasan, CarbonInterface $sekarang): void
    {
        if ($lomba->dibatalkan()) {
            return;
        }

        $lomba->update([
            'dibatalkan_pada' => $sekarang,
            'alasan_batal' => $alasan,
        ]);
    }

    /**
     * Mencatat skor satu pemain pada satu lomba.
     *
     * `$poin` adalah skor yang dihitung di peramban - sama dengan permainan
     * sendirian, dan `PenjagaSkorLuar` yang memutuskan apakah angkanya
     * mungkin. Baris yang ditolak tetap ditulis, dengan `match_id`-nya, supaya
     * lomba yang skornya ditolak bisa ditelusuri seperti kiriman mana pun.
     *
     * @param  ExamParticipant|null  $peserta  jejak penempatan, bila ada.
     */
    public function catat(
        LeisureMatch $lomba,
        User $siswa,
        ?ExamParticipant $peserta,
        int $poin,
        int $durasiMs,
    ): LeisureGameScore {
        $alasan = $this->penjaga->periksa($poin, $durasiMs, LeisureGameScore::GAME_HITUNG);

        return LeisureGameScore::create([
            'school_id' => $siswa->school_id,
            'student_id' => $siswa->id,
            'class_id' => $peserta?->pesertaEvent?->class_id,
            'exam_participant_id' => $peserta?->id,
            'match_id' => $lomba->id,
            'game_type' => LeisureGameScore::GAME_HITUNG,
            'distance' => $poin,
            'duration_ms' => $durasiMs,
            'score' => $this->penjaga->hitungSkorHitung($poin),
            'rejected_reason' => $alasan,
            'played_at' => now(),
        ]);
    }

    /* ------------------------------------------------------------- nilai */

    /**
     * Poin dari lembar jawaban, dinilai terhadap kunci yang tersimpan.
     *
     * `$jawaban` adalah urutan pilihan pemain: elemen ke-`i` adalah angka
     * pilihan yang ia tekan untuk soal ke-`i`. Lembar yang lebih pendek dari
     * daftar soal sah - anak yang menjawab dua puluh soal dalam enam puluh
     * detik memang mengirim dua puluh angka.
     *
     * ---------------------------------------------------------------------
     * Sepuluh poin per jawaban benar, tanpa bonus rangkaian
     * ---------------------------------------------------------------------
     * Permainan sendirian memberi `10 + min(rangkaian, 10) × 2`, dan bonus
     * itu TIDAK dipakai di sini. Alasannya bukan kesederhanaan: di lomba,
     * kedua pemain menjawab soal dengan urutan yang sama, sehingga bonus
     * rangkaian akan memberi keuntungan kepada yang menjawab lebih dulu -
     * padahal yang diuji adalah ketepatan berhitung, bukan kecepatan jari.
     *
     * Sepuluh per jawaban benar juga menjaga laju poin di bawah
     * `PenjagaSkorLuar::HITUNG_POIN_PER_DETIK_MAKS` untuk setiap lembar
     * yang mungkin: enam puluh soal × 10 = 600 poin dalam enam puluh detik,
     * yaitu 10 poin per detik.
     *
     * @param  array<int, int>  $jawaban
     */
    public function nilai(LeisureMatch $lomba, array $jawaban): int
    {
        $soal = $lomba->soal ?? [];
        $benar = 0;

        foreach ($jawaban as $i => $pilihan) {
            // Lembar yang lebih panjang dari daftar soal tidak menambah
            // apa pun: soal kedelapan puluh tidak pernah ada.
            if (! isset($soal[$i])) {
                break;
            }

            if ((int) $pilihan === (int) $soal[$i]['jawaban']) {
                $benar++;
            }
        }

        return $benar * self::POIN_PER_BENAR;
    }

    /* ------------------------------------------------------------- soal */

    /**
     * Enam puluh soal, beserta kuncinya.
     *
     * Bentuk satu soal:
     *   ['teks' => '14 + 7', 'jawaban' => 21, 'pilihan' => [21, 18, 24, 20]]
     *
     * `pilihan` selalu empat angka, selalu memuat `jawaban`, dan sudah
     * diacak urutannya. Pengacakannya di sini, bukan di peramban: kalau
     * peramban yang mengacak, dua pemain bisa menerima urutan pilihan yang
     * berbeda untuk soal yang sama - dan anak yang menjawab dengan memori
     * otot ("yang kedua dari kiri") akan mendapat jawaban berbeda dari
     * lawannya tanpa satu pun angka yang salah.
     *
     * @return array<int, array{teks: string, jawaban: int, pilihan: int[]}>
     */
    public function bangkitkanSoal(): array
    {
        $soal = [];

        for ($nomor = 0; $nomor < self::JUMLAH_SOAL; $nomor++) {
            $soal[] = $this->satuSoal($nomor);
        }

        return $soal;
    }

    /**
     * Satu soal aritmetika, kesulitannya menaik sepanjang daftar.
     *
     * Kenaikannya mengikuti NOMOR SOAL, bukan jawaban benar - berbeda dari
     * `HitungCepat.tsx` yang menaikkan level sesuai prestasi. Di lomba,
     * kesulitan yang menyesuaikan diri akan memberi soal mudah kepada yang
     * tertinggal, dan itu bonus yang tidak diminta siapa pun. Soal nomor
     * sepuluh selalu soal nomor sepuluh bagi kedua pemain.
     *
     * @return array{teks: string, jawaban: int, pilihan: int[]}
     */
    private function satuSoal(int $nomor): array
    {
        $level = $nomor < 20 ? 1 : ($nomor < 45 ? 2 : 3);

        [$teks, $jawaban] = $this->hitung($level);

        return [
            'teks' => $teks,
            'jawaban' => $jawaban,
            'pilihan' => $this->pilihan($jawaban),
        ];
    }

    /**
     * Satu hitungan menurut levelnya.
     *
     * @return array{0: string, 1: int}
     */
    private function hitung(int $level): array
    {
        $operator = ['+', '-'][random_int(0, 1)];

        if ($level >= 2) {
            $operator = ['+', '-', '×'][random_int(0, 2)];
        }

        if ($level >= 3) {
            $operator = ['+', '-', '×', '÷'][random_int(0, 3)];
        }

        $maks = match ($level) {
            1 => 25,
            2 => 50,
            default => 90,
        };

        return match ($operator) {
            '+' => $this->tambah($maks),
            '-' => $this->kurang($maks),
            '×' => $this->kali($level),
            default => $this->bagi(),
        };
    }

    /** @return array{0: string, 1: int} */
    private function tambah(int $maks): array
    {
        $a = random_int(2, $maks);
        $b = random_int(2, $maks);

        return ["{$a} + {$b}", $a + $b];
    }

    /** @return array{0: string, 1: int} */
    private function kurang(int $maks): array
    {
        // `b` dijaga di bawah `a` supaya hasilnya tidak pernah negatif -
        // pilihan jawaban negatif di ponsel kecil lebih mudah salah ketuk
        // daripada salah hitung, dan itu mengukur hal yang salah.
        $b = random_int(2, intdiv($maks, 2));
        $a = random_int($b + 1, $maks);

        return ["{$a} - {$b}", $a - $b];
    }

    /** @return array{0: string, 1: int} */
    private function kali(int $level): array
    {
        $a = random_int(2, $level >= 3 ? 15 : 12);
        $b = random_int(2, 12);

        return ["{$a} × {$b}", $a * $b];
    }

    /** @return array{0: string, 1: int} */
    private function bagi(): array
    {
        $b = random_int(2, 12);
        $hasil = random_int(2, 15);

        return [($b * $hasil)." ÷ {$b}", $hasil];
    }

    /**
     * Empat pilihan jawaban, sudah diacak, selalu memuat jawabannya.
     *
     * @return int[]
     */
    private function pilihan(int $jawaban): array
    {
        $pilihan = [$jawaban => true];
        $geser = [-10, -3, -2, -1, 1, 2, 3, 10];

        // Batas percobaan: tanpa itu, jawaban 0 atau 1 dengan `$geser` yang
        // seluruhnya negatif akan berputar selamanya.
        for ($i = 0; count($pilihan) < 4 && $i < 40; $i++) {
            $kandidat = $jawaban + $geser[array_rand($geser)];

            if ($kandidat >= 0) {
                $pilihan[$kandidat] = true;
            }
        }

        // Jaring pengaman: bila geseran di atas belum cukup, ambil angka
        // berurutan di atas jawabannya.
        for ($tambah = 1; count($pilihan) < 4; $tambah++) {
            $pilihan[$jawaban + $tambah] = true;
        }

        $angka = array_keys($pilihan);
        shuffle($angka);

        return $angka;
    }

    /* ------------------------------------------------------------- kode */

    /**
     * Kode undangan yang belum dipakai di sekolah ini.
     *
     * Bentrok mungkin terjadi - 27^6 terasa besar, tetapi kode yang sama
     * dari dua lomba berbeda di sekolah yang sama akan membuat pemain kedua
     * masuk ke lomba yang salah. Pencariannya diulang beberapa kali, lalu
     * menyerah dengan pengecualian: kode yang tidak unik adalah lomba yang
     * tidak bisa dimainkan, dan lebih baik gagal di sini daripada di tangan
     * anak yang sudah membagikan kodenya.
     */
    private function kodeUnik(int $schoolId): string
    {
        for ($coba = 0; $coba < self::COBA_KODE; $coba++) {
            $kode = $this->kodeAcak();

            $terpakai = LeisureMatch::query()
                ->where('school_id', $schoolId)
                ->where('code', $kode)
                ->exists();

            if (! $terpakai) {
                return $kode;
            }
        }

        throw new \RuntimeException('Tidak bisa membuat kode undangan yang unik setelah '.self::COBA_KODE.' percobaan.');
    }

    private function kodeAcak(): string
    {
        $kode = '';

        for ($i = 0; $i < self::PANJANG_KODE; $i++) {
            $kode .= self::ALFABET[random_int(0, strlen(self::ALFABET) - 1)];
        }

        return $kode;
    }
}

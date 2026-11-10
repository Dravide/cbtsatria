<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Models\ExamAttempt;
use Illuminate\Http\Request;

/**
 * Denyut peramban siswa - PRD §12.2, blueprint `:592`.
 *
 * ---------------------------------------------------------------------
 * Satu kolom, tiga pembaca, dan itulah alasan kelas ini ada
 * ---------------------------------------------------------------------
 * `last_heartbeat_at` dibaca papan monitoring ruang SETIAP kali disegarkan,
 * dan angka "terputus" di sana berasal dari selisihnya dengan sekarang -
 * `ExamAttempt::terhubung(90)`. Yang menulisnya hanya satu tempat ini, dan
 * yang membuatnya penting justru itu: denyut yang ditulis dua tempat dengan
 * dua selang waktu menghasilkan papan yang menandai siswa terputus
 * bergantian.
 *
 * ---------------------------------------------------------------------
 * Denyut yang lewat TIDAK dicatat sebagai pelanggaran
 * ---------------------------------------------------------------------
 * Blueprint `:592` menyebut `heartbeat_gap` sebagai isyarat, dan yang
 * dipilih di sini adalah kebalikannya - dengan alasan yang menyangkut anak,
 * bukan arsitektur.
 *
 * Selang yang hilang punya terlalu banyak sebab yang bukan perbuatan siswa:
 * lift, ruang yang sinyalnya buruk, ponsel yang mengunci layar karena
 * sentuhan tidak sengaja, baterai yang berpindah ke mode hemat. Menulisnya
 * sebagai pelanggaran berarti menghukum sebagian anak atas jaringan
 * sekolahnya sendiri - dan justru di sekolah itulah fitur ini paling
 * sering menyala.
 *
 * Yang tetap dilakukan: `last_seen_at` ditulis, papan monitoring menandai
 * peserta itu terputus, dan pengawas yang memutuskan. Papan monitoring
 * ADALAH isyaratnya, dan ia bekerja tanpa satu baris pun di riwayat
 * pelanggaran.
 *
 * ---------------------------------------------------------------------
 * Perangkat yang berubah dan sesi yang ganda DICATAT
 * ---------------------------------------------------------------------
 * Keduanya berbeda dari jaringan, karena keduanya perbuatan: attempt yang
 * dibuka dari perangkat lain berarti soal yang sama ada di dua layar.
 * `perangkat_berubah` dicatat sebagai Sedang; `sesi_ganda` dikunci seketika
 * - lihat TanggaPelanggaran::SEGERA_KUNCI.
 *
 * Yang membedakan keduanya adalah apakah perangkat LAMA masih terdengar:
 * berpindah ponsel karena baterainya habis adalah perangkat berubah;
 * berpindah sementara yang lama masih berdenyut adalah sesi ganda.
 */
class DenyutService
{
    public function __construct(private readonly PencatatPelanggaran $pelanggaran) {}

    /**
     * Catat satu denyut, dan kembalikan apa yang berubah.
     *
     * ---------------------------------------------------------------------
     * `nomor` ikut dicatat, dan ia satu-satunya alasan Admin bisa bertanya
     * "sampai nomor berapa"
     * ---------------------------------------------------------------------
     * `progres` menjawab BERAPA BANYAK yang sudah dijawab; ia tidak
     * menjawab SOAL MANA yang sedang dilihat anak. Dua pertanyaan itu
     * berbeda dan jawabannya sering berbeda jauh: anak yang melompat ke
     * nomor 40 lalu kembali ke nomor 3 punya progres "1 / 40" pada dua
     * keadaan yang sama sekali berlainan.
     *
     * Yang tahu nomor itu adalah peramban - dan hanya peramban. Nomor yang
     * dilihat siswa adalah `attempt_questions.display_order`, yang TIDAK
     * selalu sama dengan `exam_questions.position`; server tidak bisa
     * menghitungnya tanpa membaca ulang seluruh butir attempt itu, dan
     * melakukannya untuk setiap peserta di setiap laman monitoring berarti
     * kueri yang tumbuh linier terhadap jumlah anak.
     *
     * Karena itu nomornya menumpang pada denyut yang toh sudah berjalan tiap
     * tiga puluh detik: satu angka, tanpa kueri tambahan, dan yang perlu
     * diketahui pembacanya adalah bahwa ia setua denyutnya - lihat
     * `denyut_pada`.
     *
     * Nilainya DIPERCAYA SEBAGAI ISYARAT, tidak sebagai kebenaran: ia
     * dilaporkan peramban siswa. Yang dijaga di sini hanyalah bentuknya
     * (bilangan positif) supaya satu laporan rusak tidak menjadikan kolomnya
     * tidak terbaca. `progres` pun demikian - dan di sana justru server yang
     * menghitungnya supaya angka yang dibaca pengawas berasal dari jawaban
     * yang benar-benar tersimpan. Untuk nomor, pilihan itu tidak ada.
     *
     * @param  array<string, mixed>  $flags  Yang dilaporkan peramban:
     *                                       `perangkat`, `layar_penuh`,
     *                                       `pelindung` (penjaga masih
     *                                       hidup), `progres`, dan `nomor`.
     * @return array<string, mixed>
     */
    public function catat(ExamAttempt $attempt, Request $request, array $flags = []): array
    {
        $sekarang = now();
        $lama = $attempt->guard_flags_json ?? [];

        $perangkat = $this->sidikPerangkat($request, $flags);
        $perangkatLama = $lama['perangkat'] ?? null;

        // Nomor soal yang sedang dilihat anak, atau null bila peramban tidak
        // melaporkannya. Disaring di sini, bukan di pemanggilnya, karena
        // kolomnya dibaca sebagai bilangan oleh papan monitoring - dan satu
        // string yang lolos akan menjadi "NaN" di layar, bukan galat.
        $nomor = $flags['nomor'] ?? null;
        $nomor = is_numeric($nomor) && (int) $nomor > 0 ? (int) $nomor : null;

        $catatan = [];

        if ($perangkatLama !== null && $perangkat !== $perangkatLama) {
            // Perangkat lama masih terdengar? Kalau ya, ini dua layar yang
            // sama-sama mengerjakan.
            $ganda = $attempt->terhubung(90);

            $catatan[] = $this->pelanggaran->catat(
                $attempt,
                $ganda ? 'sesi_ganda' : 'perangkat_berubah',
                null,
                ['perangkat_lama' => $perangkatLama, 'perangkat_baru' => $perangkat],
            );

            // Attempt dibaca ulang oleh pemanggilnya lewat objek yang sama -
            // PencatatPelanggaran sudah memperbarui atributnya.
        }

        $attempt->forceFill([
            'last_heartbeat_at' => $sekarang,
            'last_seen_at' => $sekarang,
            'device_type' => $flags['device_type'] ?? $this->jenisPerangkat($request),
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'guard_flags_json' => [
                ...$lama,
                'perangkat' => $perangkat,
                'layar_penuh' => (bool) ($flags['layar_penuh'] ?? false),
                'pelindung' => (bool) ($flags['pelindung'] ?? true),
                'progres' => $flags['progres'] ?? ($lama['progres'] ?? null),
                'nomor' => $nomor,
                'denyut_pada' => $sekarang->toIso8601String(),
            ],
        ])->save();

        return [
            'status' => $attempt->status,
            'terkunci' => $attempt->dikunci(),
            'kunci_alasan' => $attempt->lock_reason,
            'sisa_detik' => $attempt->deadline_at === null
                ? 0
                : max(0, (int) $sekarang->diffInSeconds($attempt->deadline_at, false)),
            'catatan' => array_map(fn ($t) => $t?->toArray(), array_filter($catatan)),
        ];
    }

    /**
     * Sidik perangkat yang dipakai untuk mengenali perpindahan.
     *
     * Peramban mengirim satu id acak yang ia karang sendiri dan simpan di
     * memorinya; server menambahkan sidik peramban-nya. Keduanya dipakai
     * bersama karena masing-masing bisa gagal sendiri: id klien hilang saat
     * tab ditutup, dan `userAgent` sama untuk dua ponsel merek sama.
     *
     * Sengaja BUKAN sidik yang stabil antar-perangkat - pertanyaannya
     * "apakah ini perangkat yang sama dengan tadi", bukan "siapa pemilik
     * perangkat ini".
     */
    private function sidikPerangkat(Request $request, array $flags): string
    {
        $idKlien = (string) ($flags['perangkat'] ?? '');
        $ua = substr((string) $request->userAgent(), 0, 200);

        return substr(hash('sha256', $idKlien.'|'.$ua), 0, 32);
    }

    private function jenisPerangkat(Request $request): ?string
    {
        $ua = strtolower((string) $request->userAgent());

        return match (true) {
            str_contains($ua, 'android') => 'android',
            str_contains($ua, 'iphone'), str_contains($ua, 'ipad') => 'ios',
            default => 'desktop',
        };
    }
}

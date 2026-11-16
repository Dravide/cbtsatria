<?php

namespace App\Domain\Grading\Services;

use App\Domain\Attendance\Models\StudentAnswer;
use App\Domain\Tenancy\Services\PengaturanAiSekolah;
use App\Support\Ai\GalatPenyedia;
use App\Support\Ai\KlienAi;
use App\Support\Ai\UrlPenyedia;

/**
 * Usulan koreksi uraian dari model bahasa - PRD §13.3, wireframe plate 06.
 *
 * =====================================================================
 * Yang dihasilkan adalah USULAN, bukan koreksi
 * =====================================================================
 * Kelas ini TIDAK PERNAH menulis `essay_gradings`. Ia mengembalikan daftar
 * poin per kriteria yang dibaca Guru di pratinjau, disunting bila perlu, lalu
 * disimpan lewat `PenilaiUraian::koreksi()` - pintu yang sama dengan koreksi
 * manual, lengkap dengan pemeriksaan bobot dan kuncinya.
 *
 * Itu keputusan, bukan kelalaian, dan alasannya sama persis dengan
 * `PenulisSoalAi`: model bahasa memberi poin yang terdengar masuk akal dan
 * kadang salah - dan nilai yang tersimpan tanpa dibaca Guru adalah nilai yang
 * dibaca siswa dan orang tuanya. Yang menanggung akibatnya Guru, bukan
 * penyedia AI-nya. Karena itu AI hanya MENGISI kotak yang tetap harus
 * ditekan Guru.
 *
 * =====================================================================
 * Rubrik yang dipakai adalah SNAPSHOT, bukan bank soal
 * =====================================================================
 * Poin diukur terhadap `exam_questions.rubrik_json` - salinan yang dibekukan
 * saat paket dipasang - bukan terhadap rubrik hidup di bank soal. Kalau
 * rubriknya diubah sesudah ujian berjalan, yang dinilai tetap rubrik yang
 * benar-benar dipakai siswa. Laman koreksi pun membaca kolom yang sama.
 *
 * =====================================================================
 * Nama kriteria dicocokkan, bukan dipercaya
 * =====================================================================
 * Model boleh mengembalikan kriteria dengan urutan berbeda atau penulisan
 * sedikit lain. Yang disimpan tetap kriteria dari SNAPSHOT: keluaran model
 * dipetakan ke rubrik berdasarkan namanya, dan kriteria yang tidak
 * disebut model diisi 0. Poinnya dipatok pada `maks` kriteria itu, sehingga
 * usulan AI tak pernah melebihi bobot butir - batas yang sama yang ditegakkan
 * `PenilaiUraian`.
 */
class PenilaiUraianAi
{
    /** Panjang teks jawaban yang dikirim ke model. Lebih dari ini tidak menambah apa pun. */
    public const BATAS_JAWABAN = 4000;

    /** Batas waktu per jawaban, detik. Satu batas untuk satu permintaan. */
    private const BATAS_WAKTU = 45;

    /** Anggaran token satu usulan - rubrik sekolah jarang lebih dari 8 kriteria. */
    private const BATAS_TOKEN = 1200;

    public function __construct(
        private readonly PengaturanAiSekolah $pengaturan,
        private readonly KlienAi $klien,
    ) {}

    /**
     * Satu usulan koreksi untuk satu jawaban.
     *
     * @return array{kriteria: array<int, array{kriteria: string, keterangan: ?string, maks: float, poin: float}>, total: float, bobot: float, catatan: ?string}
     *
     * @throws GalatPenyedia
     */
    public function usul(StudentAnswer $jawaban): array
    {
        $butir = $jawaban->butir;
        $soal = $butir?->soal;

        if ($soal === null) {
            throw new GalatPenyedia('Jawaban ini tidak terhubung ke soal mana pun.');
        }

        $rubrik = $this->rubrik($soal->rubrik_json ?? []);
        $bobot = (float) $soal->bobotBerlaku();

        // Tanpa rubrik tidak ada kriteria yang bisa diberi poin, dan usulan
        // yang mengarang kriterianya sendiri tidak bisa disimpan
        // `PenilaiUraian`. Lebih jujur menolak dengan kalimatnya daripada
        // menampilkan usulan yang pasti gagal saat disimpan.
        if ($rubrik === []) {
            throw new GalatPenyedia(
                'Soal ini tidak punya rubrik, sehingga AI tidak punya kriteria untuk menilai. '
                .'Tambahkan rubriknya di bank soal lebih dulu.',
            );
        }

        $konfigurasi = $this->konfigurasi();
        UrlPenyedia::pastikan((string) $konfigurasi['base_url']);

        $teksJawaban = $this->teksJawaban($jawaban);

        $mentah = $this->klien->kirim($konfigurasi, [
            ['role' => 'system', 'content' => $this->instruksi()],
            ['role' => 'user', 'content' => $this->permintaan($soal, $rubrik, $bobot, $teksJawaban)],
        ]);

        return $this->petakan($mentah, $rubrik, $bobot);
    }

    /* ------------------------------------------------------------ Pembantu */

    /**
     * Petakan keluaran model ke kriteria SNAPSHOT.
     *
     * Kriteria snapshot adalah kebenarannya: keluaran model dipakai HANYA
     * untuk mengambil poin per kriteria, dan kriteria yang tidak dikenal
     * dibuang. Poin dipatok pada `maks`, dan totalnya dihitung ulang di sini
     * supaya tidak pernah melebihi bobot - batas yang sama dengan koreksi
     * manual.
     *
     * @param  array<string, mixed>  $mentah
     * @param  array<int, array{kriteria: string, keterangan: ?string, maks: float}>  $rubrik
     * @return array{kriteria: array<int, array{kriteria: string, keterangan: ?string, maks: float, poin: float}>, total: float, bobot: float, catatan: ?string}
     */
    private function petakan(array $mentah, array $rubrik, float $bobot): array
    {
        // Peta nama kriteria -> poin usulan. Nama dinormalkan (huruf kecil,
        // spasi dirapatkan) supaya "Langkah  Benar" dan "langkah benar"
        // menunjuk kriteria yang sama.
        $usulan = [];

        foreach ((array) ($mentah['kriteria'] ?? []) as $satu) {
            if (! is_array($satu)) {
                continue;
            }

            $nama = $this->normalkan((string) ($satu['kriteria'] ?? ''));
            $poin = (float) ($satu['poin'] ?? 0);

            if ($nama !== '') {
                $usulan[$nama] = $poin;
            }
        }

        $hasil = [];
        $total = 0.0;

        foreach ($rubrik as $k) {
            $poin = $usulan[$this->normalkan($k['kriteria'])] ?? 0.0;

            // Patok pada [0, maks]. Model yang mengembalikan 5 untuk kriteria
            // bernilai 4 - atau -2 karena salah baca - tidak boleh membuat
            // usulan yang ditolak saat disimpan.
            $poin = max(0.0, min((float) $k['maks'], round($poin, 2)));

            $hasil[] = [...$k, 'poin' => $poin];
            $total += $poin;
        }

        // Jaring terakhir: total tidak boleh melebihi bobot butir. Kalau
        // rubriknya sendiri jumlahnya melebihi bobot (soal salah susun), usulan
        // ini memang tidak akan bisa disimpan - dan Guru melihatnya dari pesan
        // `PenilaiUraian`, bukan dari usulan yang tampak sah.
        if ($total > $bobot + 0.01) {
            $total = $bobot;
        }

        $catatan = trim((string) ($mentah['catatan'] ?? ''));

        return [
            'kriteria' => $hasil,
            'total' => round($total, 2),
            'bobot' => $bobot,
            'catatan' => $catatan === '' ? null : mb_substr($catatan, 0, PenilaiUraian::BATAS_FEEDBACK, 'UTF-8'),
        ];
    }

    /**
     * Rubrik snapshot menjadi daftar kriteria yang bisa dinilai.
     *
     * Bentuknya `[{"kriteria": "...", "keterangan": "...", "maks": 4}, ...]` -
     * lihat `PembekuPaket::rubrik()`. Kriterianya dibaca dari kunci `kriteria`,
     * bukan `criterion`: yang tersimpan adalah salinan berbahasa Indonesia.
     *
     * @param  array<int, mixed>  $rubrik
     * @return array<int, array{kriteria: string, keterangan: ?string, maks: float}>
     */
    private function rubrik(array $rubrik): array
    {
        $hasil = [];

        foreach ($rubrik as $r) {
            if (! is_array($r)) {
                continue;
            }

            $nama = trim((string) ($r['kriteria'] ?? ''));

            if ($nama === '') {
                continue;
            }

            $keterangan = trim((string) ($r['keterangan'] ?? ''));

            $hasil[] = [
                'kriteria' => $nama,
                'keterangan' => $keterangan === '' ? null : $keterangan,
                'maks' => (float) ($r['maks'] ?? 0),
            ];
        }

        return $hasil;
    }

    private function teksJawaban(StudentAnswer $jawaban): string
    {
        $teks = trim((string) ($jawaban->answer_json['teks'] ?? ''));

        if ($teks === '') {
            return '(siswa tidak menulis jawaban apa pun)';
        }

        return mb_substr($teks, 0, self::BATAS_JAWABAN, 'UTF-8');
    }

    /** @param  array{kriteria: array<int, array{kriteria: string, keterangan: ?string, maks: float}>}  $rubrik */
    private function permintaan(object $soal, array $rubrik, float $bobot, string $teksJawaban): string
    {
        $teksSoal = trim((string) ($soal->soal_json['teks'] ?? ''));

        $daftar = [];
        $i = 1;

        foreach ($rubrik as $k) {
            $daftar[] = $i++.'. '.$k['kriteria'].' (maksimal '.$k['maks'].' poin)'
                .($k['keterangan'] !== null ? ' - '.$k['keterangan'] : '');
        }

        return "Soal:\n".strip_tags($teksSoal)."\n\n"
            ."Rubrik penilaian (bobot butir {$bobot} poin):\n".implode("\n", $daftar)."\n\n"
            ."Jawaban siswa:\n".$teksJawaban."\n\n"
            .'Nilai jawaban itu terhadap SETIAP kriteria di atas.';
    }

    private function instruksi(): string
    {
        return <<<'TXT'
            Anda guru sekolah menengah di Indonesia yang menilai jawaban uraian.
            Beri poin untuk SETIAP kriteria pada rubrik yang diberikan, tidak lebih
            dari poin maksimal kriteria itu. Poin boleh pecahan (mis. 2.5).
            Kriteria yang tidak dipenuhi siswa diberi 0.
            Bersikaplah wajar: jawaban yang benar sebagian diberi poin sebagian,
            bukan 0 dan bukan penuh. Jawaban kosong diberi 0 pada semua kriteria.

            Balas HANYA dengan satu objek JSON, tanpa penjelasan di luar JSON:
            {"kriteria":[{"kriteria":"<nama kriteria PERSIS seperti di rubrik>","poin":2.5}],
             "catatan":"<satu kalimat umpan balik singkat untuk siswa>"}

            Kriteria yang Anda balas harus memakai nama yang sama persis dengan rubrik.
            TXT;
    }

    private function normalkan(string $nama): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim($nama)) ?? '', 'UTF-8');
    }

    /**
     * Konfigurasi AI sekolah, dalam bentuk yang dipakai `KlienAi`.
     *
     * @return array{base_url:string, model:string, api_key:string, batas_token:int, batas_waktu:int}
     *
     * @throws GalatPenyedia
     */
    private function konfigurasi(): array
    {
        $pengaturan = $this->pengaturan->ambil();

        if ($pengaturan === null || ! $pengaturan->siap()) {
            throw GalatPenyedia::belumDiatur();
        }

        $kunci = $pengaturan->kunciApi();

        if (blank($kunci)) {
            throw GalatPenyedia::belumDiatur();
        }

        return [
            'base_url' => (string) $pengaturan->base_url,
            'model' => (string) $pengaturan->model,
            'api_key' => $kunci,
            'batas_token' => self::BATAS_TOKEN,
            'batas_waktu' => self::BATAS_WAKTU,
        ];
    }
}

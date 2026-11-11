<?php

namespace App\Domain\Question\Http\Controllers;

use App\Domain\Question\Enums\JenisSoal;
use App\Domain\Question\Models\Question;
use App\Domain\Question\Services\AmpuanMapel;
use App\Domain\Question\Services\BankSoalService;
use App\Domain\Question\Services\PenulisSoalAi;
use App\Support\Ai\GalatPenyedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Generate soal dengan AI - tombol di laman builder.
 *
 * =====================================================================
 * Dua endpoint, dan yang kedua yang MENULIS
 * =====================================================================
 * `generate` menyusun soal dan mengembalikannya sebagai JSON. Ia TIDAK
 * menulis apa pun ke bank soal. Jawabannya ditampilkan sebagai PRATINJAU di
 * peramban, Guru membaca dan menyunting, lalu mencentang yang ia setujui.
 *
 * `simpan` menerima yang tercentang dan menyimpannya. Endpoint itulah satu-
 * satunya jalur tulis dari fitur AI, dan ia menulis lewat
 * `BankSoalService::buat()` - pintu yang sama dengan form builder manual,
 * lengkap dengan pembangkitan kode dan validasi `SkemaJenisSoal`.
 *
 * ---------------------------------------------------------------------
 * Sampai 2026-11-10 hasilnya mengisi form, dan itu diganti
 * ---------------------------------------------------------------------
 * Alasan lamanya masih benar - soal AI tidak boleh masuk bank soal tanpa
 * dibaca - tetapi bentuknya salah: satu permintaan berisi sepuluh soal
 * berarti sembilan di antaranya menunggu di antrean `sessionStorage`, dan
 * antrean yang tidak terlihat adalah soal yang dibayar tetapi tidak pernah
 * dipakai. Dengan pratinjau, kesepuluhnya terlihat sekaligus dan Guru
 * memutuskan pada satu layar.
 *
 * Yang TIDAK berubah satu pun: tidak ada soal yang tersimpan tanpa Guru
 * mencentangnya.
 *
 * =====================================================================
 * Jawaban JSON, dan galat pun JSON
 * =====================================================================
 * Pemanggilnya `fetch` dari modal, bukan tautan Inertia. Karena itu setiap
 * jalur keluar - berhasil maupun gagal - harus berbentuk `{...}` yang bisa
 * dibaca skripnya. `abort(422)` dari `AmpuanMapel` tetap menghasilkan JSON
 * karena permintaannya mengirim `Accept: application/json`, tetapi galat
 * penyedia ditangani sendiri di sini supaya kalimatnya sampai utuh.
 */
class SoalAiController
{
    /**
     * Jumlah soal terbanyak yang boleh disimpan dalam satu klik.
     *
     * Lebih besar dari `PenulisSoalAi::BATAS_JUMLAH` karena satu pratinjau
     * bisa memuat hasil beberapa permintaan - Guru yang meminta campuran tiga
     * jenis menerima soal dari tiga panggilan penyedia, lalu mencentang
     * seluruhnya dalam satu kiriman simpan.
     */
    private const BATAS_SIMPAN = 30;

    /**
     * Susun soal, kembalikan sebagai pratinjau. TIDAK menulis apa pun.
     */
    public function generate(Request $request, PenulisSoalAi $penulis, AmpuanMapel $ampuan): JsonResponse
    {
        // Guru saja, dan itu bukan pembatasan baru: `QuestionPolicy::create()`
        // sudah menolak Admin (PRD §3.1 - Admin tidak mengubah isi bank soal).
        // Memakai ability yang sama berarti aturannya tetap satu tempat.
        Gate::authorize('create', Question::class);

        $data = $request->validate([
            // `exists` + `school_id`, bukan sekadar `integer`. Tanpa itu,
            // `subject_id` yang tidak menunjuk mapel mana pun - dan peramban
            // memang mengirim 0 saat daftar mapel Guru masih kosong - lolos
            // validasi lalu ditolak `AmpuanMapel` dengan "Anda tidak mengampu
            // mata pelajaran tersebut.". Kalimat itu menuduh Guru atas mapel
            // yang bahkan tidak ada, dan Guru yang membacanya akan mencari
            // kesalahan di tempat yang salah.
            'subject_id' => [
                'required', 'integer',
                Rule::exists('subjects', 'id')->where('school_id', $request->user()->school_id),
            ],
            // Dua bentuk, dan keduanya sah:
            //   "jenis": "pilihan_ganda"        - satu jenis, jalur lama;
            //   "jenis": ["pilihan_ganda", ...] - mode lanjut, campuran.
            //
            // Yang pertama dipertahankan karena peramban yang belum dimuat
            // ulang masih mengirimnya, dan fitur yang mengubah bentuk
            // permintaannya tanpa alasan yang menyangkut Guru adalah fitur
            // yang rusak di tab yang tertinggal terbuka.
            //
            // Diperiksa dengan closure, BUKAN `Rule::in` pada `jenis.*`:
            // wildcard tidak berlaku untuk nilai skalar, sehingga bentuk
            // string akan lolos tanpa diperiksa satu pun - lalu
            // `JenisSoal::from()` melempar ValueError menjadi 500.
            'jenis' => ['required', function (string $attribute, mixed $nilai, \Closure $gagal): void {
                foreach ((array) $nilai as $satu) {
                    if (! is_string($satu) || ! in_array($satu, PenulisSoalAi::JENIS_DIDUKUNG, true)) {
                        $gagal('Ada jenis soal yang belum bisa dibuat AI.');

                        return;
                    }
                }
            }],
            // Lima karakter minimum: topik sependek "x" menghasilkan soal
            // acak yang lalu dibuang Guru - dan tetap ditagih.
            'topik' => ['required', 'string', 'min:5', 'max:500'],
            'tingkat' => ['required', 'string', Rule::in(['mudah', 'sedang', 'sulit'])],
            // Tidak wajib, dan bawaan 1. Peramban versi lama - atau tab yang
            // dibuka sebelum pembaruan - tidak mengirim kunci ini sama sekali,
            // dan permintaan satu soal yang bekerja kemarin harus tetap bekerja
            // hari ini.
            'jumlah' => ['sometimes', 'integer', 'min:1', 'max:'.PenulisSoalAi::BATAS_JUMLAH],
        ], [
            'subject_id.required' => 'Pilih mata pelajarannya.',
            'subject_id.exists' => 'Mata pelajaran itu tidak ada di sekolah ini.',
            'jenis.required' => 'Pilih jenis soalnya.',
            'topik.required' => 'Tulis topiknya lebih dulu.',
            'topik.min' => 'Topiknya terlalu pendek. Tulis setidaknya lima karakter.',
            'tingkat.required' => 'Pilih tingkat kesulitannya.',
            'tingkat.in' => 'Tingkat kesulitan harus mudah, sedang, atau sulit.',
            'jumlah.integer' => 'Jumlah soal harus berupa angka.',
            'jumlah.min' => 'Minta setidaknya satu soal.',
            'jumlah.max' => 'Sekali minta paling banyak '.PenulisSoalAi::BATAS_JUMLAH.' soal. Minta lagi untuk sisanya.',
        ], [
            'subject_id' => 'mata pelajaran',
            'topik' => 'topik',
            'tingkat' => 'tingkat kesulitan',
            'jumlah' => 'jumlah soal',
        ]);

        // Satu jenis atau banyak, dinormalkan di sini supaya sisa method ini
        // hanya punya satu bentuk untuk diurus.
        $jenis = array_values(array_unique(
            array_map('strval', (array) $data['jenis']),
        ));

        if ($jenis === []) {
            throw ValidationException::withMessages(['jenis' => 'Pilih setidaknya satu jenis soal.']);
        }

        // 422 dan bukan 403: Guru memang boleh menyusun soal, hanya tidak
        // untuk mapel itu. Pesannya muncul di sebelah field Mata pelajaran.
        $ampuan->pastikan($request->user(), (int) $data['subject_id']);

        $jumlah = (int) ($data['jumlah'] ?? 1);

        try {
            $daftar = $this->susunSemua($penulis, (int) $data['subject_id'], $jenis, $data['topik'], $data['tingkat'], $jumlah);
        } catch (GalatPenyedia $e) {
            // Bukan 500. Penyedia yang salah dikonfigurasi adalah keadaan
            // yang bisa diperbaiki Admin dalam satu menit; 500 membuat Guru
            // melapor "aplikasinya rusak" dan tidak ada yang mencarinya di
            // tab Pengaturan.
            return response()->json(['pesan' => $e->getMessage()], 422);
        } catch (ValidationException $e) {
            // SkemaJenisSoal menolak keluaran model. Pesan aslinya menyebut
            // kolom internal ("kunci", "butir") yang tidak berarti apa-apa
            // bagi Guru - yang ia butuhkan adalah "coba lagi atau susun
            // manual", dan itu yang dikirim.
            report($e);

            return response()->json([
                'pesan' => 'AI mengembalikan soal yang bentuknya tidak sah, dan tidak disimpan. '
                    .'Coba lagi, atau susun soalnya secara manual.',
            ], 422);
        }

        // ---------------------------------------------------------------
        // Satu soal tetap berbentuk datar, seperti sejak awal
        // ---------------------------------------------------------------
        // Bukan kelonggaran: `Builder.tsx` versi mana pun, dan semua test
        // yang sudah ada, membaca `{judul, opsi, kunci, ...}` langsung.
        // Membungkusnya dalam `{soal:[...]}` hanya karena sekarang ada
        // bentuk larik berarti seluruh pemanggil lama harus berubah untuk
        // fitur yang tidak mengubah hasil mereka satu pun.
        if (count($jenis) === 1 && $jumlah === 1) {
            return response()->json($daftar[0]);
        }

        return response()->json(['soal' => $daftar]);
    }

    /**
     * Simpan soal AI yang dicentang Guru - satu-satunya jalur tulis.
     *
     * =====================================================================
     * Validasi SEMUA lebih dulu, lalu tulis semuanya
     * =====================================================================
     * Bukan satu per satu. Guru mencentang delapan soal dan menekan satu
     * tombol; kalau soal ketiga ternyata bentuknya tidak sah, menyimpan yang
     * tujuh lain berarti ia menerima laporan "tujuh tersimpan" tanpa tahu
     * yang mana yang gagal - dan delapan-duanya sudah ia lihat dan setujui di
     * pratinjau. Yang benar: tolak seluruh kiriman, sebut nomornya, dan biarkan
     * ia memperbaiki atau membuang satu itu.
     *
     * Karena itu `bangun()` dipanggil untuk SELURUH soal sebelum satu baris
     * pun ditulis, dan penulisannya kemudian berjalan dalam satu transaksi.
     */
    public function simpan(Request $request, PenulisSoalAi $penulis, BankSoalService $bankSoal, AmpuanMapel $ampuan): RedirectResponse
    {
        Gate::authorize('create', Question::class);

        $data = $request->validate([
            'subject_id' => [
                'required', 'integer',
                Rule::exists('subjects', 'id')->where('school_id', $request->user()->school_id),
            ],
            'tingkat' => ['nullable', 'string', Rule::in(['mudah', 'sedang', 'sulit'])],
            'soal' => ['required', 'array', 'min:1', 'max:'.self::BATAS_SIMPAN],
            // Hanya bentuk LUAR tiap soal. Isinya dipetakan dan divalidasi
            // `PenulisSoalAi::bangun()` lewat `SkemaJenisSoal`, satu tempat
            // yang sama dengan yang memvalidasi keluaran model.
            'soal.*' => ['required', 'array'],
        ], [
            'subject_id.required' => 'Pilih mata pelajarannya.',
            'subject_id.exists' => 'Mata pelajaran itu tidak ada di sekolah ini.',
            'soal.required' => 'Tidak ada soal yang dicentang.',
            'soal.min' => 'Centang setidaknya satu soal.',
            'soal.max' => 'Sekali simpan paling banyak '.self::BATAS_SIMPAN.' soal.',
        ]);

        $user = $request->user();
        $ampuan->pastikan($user, (int) $data['subject_id']);

        $siap = [];

        foreach (array_values($data['soal']) as $i => $satu) {
            try {
                // Tingkat per SOAL lebih dulu, lalu tingkat kiriman, lalu
                // 'sedang'. Satu pratinjau bisa memuat hasil dua permintaan
                // dengan tingkat berbeda, dan memakai satu nilai untuk
                // semuanya berarti soal "sulit" tersimpan sebagai "sedang".
                $tingkat = (string) ($satu['tingkat'] ?? $data['tingkat'] ?? 'sedang');

                $siap[] = $penulis->bangun($satu, (int) $data['subject_id'], $tingkat);
            } catch (ValidationException $e) {
                // Nomor soalnya disebut: Guru melihat delapan kartu di layar,
                // dan "soal ketiga bermasalah" adalah satu-satunya kalimat
                // yang bisa ia tindak lanjuti.
                $pesan = collect($e->errors())->flatten()->first() ?? 'Bentuk soalnya tidak sah.';

                throw ValidationException::withMessages([
                    'soal' => 'Soal nomor '.($i + 1).' dari hasil AI tidak bisa disimpan: '.$pesan,
                ]);
            }
        }

        $tersimpan = DB::transaction(function () use ($siap, $user, $bankSoal) {
            $hasil = [];

            foreach ($siap as $payload) {
                $hasil[] = $bankSoal->buat($payload, (int) $user->school_id, (int) $user->id);
            }

            return $hasil;
        });

        $kode = array_map(fn (Question $q) => $q->code, $tersimpan);

        return redirect()
            ->route('guru.bank-soal')
            ->with('sukses', count($kode) === 1
                ? 'Soal '.$kode[0].' dari AI tersimpan ke bank soal. Periksa kembali sebelum dipakai di paket.'
                : count($kode).' soal dari AI tersimpan ke bank soal ('.implode(', ', $kode).'). Periksa kembali sebelum dipakai di paket.');
    }

    /* ------------------------------------------------------------- Pembantu */

    /**
     * Susun soal untuk satu jenis atau beberapa jenis sekaligus.
     *
     * =====================================================================
     * Campuran = beberapa permintaan, dan itu keputusan sadar
     * =====================================================================
     * Satu permintaan berisi "buatlah 3 PG, 2 menjodohkan, dan 1 angka"
     * menuntut skema yang lebih longgar di dalam satu jawaban: setiap unsur
     * larik boleh berbentuk berbeda, dan model yang diberi pilihan bentuk
     * lebih sering memilih bentuk yang salah. Skema yang satu jaminan per
     * permintaan jauh lebih bisa dipercaya - dan sudah teruji.
     *
     * Harganya disebut apa adanya: tiga jenis berarti tiga panggilan
     * penyedia. Jatah `throttle:ai-soal` dihitung per permintaan HTTP ke
     * aplikasi ini, bukan per panggilan penyedia, sehingga satu klik campuran
     * tetap satu jatah.
     *
     * @param  array<int, string>  $jenis
     * @return array<int, array<string, mixed>>
     */
    private function susunSemua(PenulisSoalAi $penulis, int $subjectId, array $jenis, string $topik, string $tingkat, int $jumlah): array
    {
        $hasil = [];

        // Pembagian rata dengan sisa ke jenis pertama: 7 soal untuk 3 jenis
        // menjadi 3 + 2 + 2, bukan 2 + 2 + 2 dan satu soal yang hilang.
        $dasar = intdiv($jumlah, count($jenis));
        $sisa = $jumlah % count($jenis);

        foreach ($jenis as $i => $satu) {
            $bagian = max(1, $dasar + ($i < $sisa ? 1 : 0));

            $hasil = [
                ...$hasil,
                ...$penulis->susun(
                    $subjectId,
                    JenisSoal::from($satu),
                    $topik,
                    $tingkat,
                    $bagian,
                ),
            ];
        }

        return $hasil;
    }
}

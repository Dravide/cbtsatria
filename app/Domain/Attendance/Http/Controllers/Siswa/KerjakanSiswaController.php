<?php

namespace App\Domain\Attendance\Http\Controllers\Siswa;

use App\Domain\Attendance\Models\AttemptQuestion;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Services\DenyutService;
use App\Domain\Attendance\Services\JawabanService;
use App\Domain\Attendance\Services\MesinUjian;
use App\Domain\Attendance\Services\PencatatPelanggaran;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamQuestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pengerjaan soal - wireframe plate 22 dan 23.
 *
 * =====================================================================
 * Yang dikirim ke peramban TIDAK PERNAH memuat kunci
 * =====================================================================
 * Seluruh butir lewat `ExamQuestion::untukSiswa()`, satu-satunya bentuk yang
 * membuang `kunci_json`, `rubrik_json`, dan `pembahasan_json`. Aturan itu
 * diuji sejak Fase 4 (`SnapshotSoalTest`) sebagai invarian bentuk payload,
 * dan laman ini adalah "lapis 2" yang dijanjikan test itu.
 *
 * Yang perlu diperhatikan: `AttemptQuestion::with('soal')` memuat MODEL
 * penuh `ExamQuestion`, dan model itu PENUH KUNCI. Satu `toArray()` di
 * jalur mana pun - mis. Inertia yang memuat relasinya - akan
 * mengirimkannya. Karena itu bentuknya disusun eksplisit di sini, satu per
 * satu, dan tidak pernah diserahkan ke serialisasi model.
 *
 * ---------------------------------------------------------------------
 * Menandai kedaluwarsa dilakukan SETIAP pembacaan, bukan oleh penjadwal
 * ---------------------------------------------------------------------
 * `MesinUjian::tutupBilaKedaluwarsa()` dipanggil di sini, di heartbeat, dan
 * di penyimpanan jawaban. Penjadwal yang mati akan membuat attempt
 * kedaluwarsa tetap "berjalan", dan papan monitoring menampilkan siswa yang
 * sudah pulang sebagai masih mengerjakan.
 */
class KerjakanSiswaController extends SiswaDasar
{
    public function __construct(
        private readonly MesinUjian $mesin,
        private readonly JawabanService $jawaban,
    ) {}

    public function tampilkan(Request $request, ExamParticipant $peserta): Response|RedirectResponse
    {
        $peserta = $this->milikSaya($peserta);

        $attempt = $this->attemptTerakhir($peserta);

        if ($attempt === null) {
            // Belum pernah mulai - gerbangnya yang benar, bukan laman ini.
            return redirect()->route('siswa.gerbang', ['peserta' => $peserta->id]);
        }

        $attempt = $this->mesin->tutupBilaKedaluwarsa($attempt);

        // Attempt yang terkunci punya lamannya sendiri (plate 25), dan
        // attempt yang sudah ditutup punya ringkasannya (plate 26-27).
        // Membiarkan anak mengetik di laman ini berarti jawaban yang tidak
        // akan pernah tersimpan.
        if ($attempt->dikunci()) {
            return redirect()->route('siswa.terkunci', ['peserta' => $peserta->id]);
        }

        if ($attempt->status !== ExamAttempt::STATUS_BERJALAN) {
            return redirect()->route('siswa.selesai', ['peserta' => $peserta->id]);
        }

        $peserta->loadMissing(['exam.subject:id,name', 'session', 'room.eventRoom.room:id,code,name']);

        return Inertia::render('Siswa/Kerjakan', [
            'peserta' => $this->ringkasPeserta($peserta),
            'attempt' => [
                'id' => $attempt->id,
                'nomor' => $attempt->attempt_number,
                'status' => $attempt->status,
                'sisa_detik' => $this->mesin->sisaDetik($attempt),
                'deadline' => $attempt->deadline_at?->toIso8601String(),
                'boleh_mundur' => (bool) ($peserta->exam?->allow_back_navigation ?? true),
            ],
            'butir' => $this->butirUntukSiswa($attempt, $peserta),
            'progres' => $this->jawaban->progres($attempt),
            // Selang simpan otomatis dibaca sekolah, bukan diketik di
            // peramban: `simpan_otomatis_detik` adalah pengaturan sekolah,
            // dan menuliskannya sebagai 20 akan membuatnya berbeda dari yang
            // tertulis di plate 21 begitu pengaturannya diubah.
            'simpan_tiap_detik' => $this->selangSimpan(),
        ]);
    }

    /**
     * Seluruh butir attempt, sudah dibuang kuncinya.
     *
     * Dipisahkan menjadi method karena bentuk inilah yang diuji - dan
     * bentuk yang diuji haruslah bentuk yang benar-benar dikirim, bukan
     * salinannya.
     *
     * @return array<int, array<string, mixed>>
     */
    private function butirUntukSiswa(ExamAttempt $attempt, ExamParticipant $peserta): array
    {
        $butir = $this->mesin->butir($attempt);

        return $butir->map(function (AttemptQuestion $b) use ($peserta) {
            $soal = $b->soal;

            if ($soal === null) {
                // Snapshot yang hilang berarti barisnya dihapus di luar
                // aplikasi. Butir yang tidak bisa ditampilkan TETAP dikirim
                // sebagai butir kosong, bukan dibuang: membuangnya akan
                // menggeser nomor seluruh butir sesudahnya, dan nomor soal
                // adalah hal yang disebut pengawas di ruang ujian.
                return [
                    'id' => $b->id,
                    'nomor' => $b->display_order,
                    'jenis' => 'pilihan_ganda',
                    'jenis_label' => 'Pilihan ganda',
                    'judul' => null,
                    'soal' => null,
                    'opsi' => [],
                    'butir' => null,
                    'skor' => null,
                    'media' => null,
                    'bobot' => 0.0,
                    'rusak' => true,
                ];
            }

            return [
                ...$soal->untukSiswa(),
                'id' => $b->id,
                'nomor' => $b->display_order,
                'soal_id' => $soal->id,
                // Urutan yang benar-benar dilihat peserta ini. Null berarti
                // "sebagaimana snapshot" - lihat AttemptQuestion.
                'urutan_opsi' => $b->option_order_json,
                'urutan_butir' => $b->item_order_json,
                // Alamat berkasnya disusun di sini, bukan disimpan di
                // snapshot: `media_json` sengaja hanya memuat penunjuk
                // (id, tipe, mime, nama) - lihat PembekuPaket::media() - dan
                // alamat yang ikut tersimpan akan menjadi alamat basi begitu
                // rutenya bernama lain.
                //
                // Rutenya menuntut id butir DAN id berkas, dan itu yang
                // membuat gambar dari soal yang belum diujikan tidak bisa
                // dibaca dengan menebak nomor idnya. Lihat
                // ExamMediaSiswaController.
                'media' => $this->mediaUntukSiswa($soal, $peserta->id),
                // Opsi dilewatkan ulang untuk menyisipkan alamat berkas GAMBAR
                // PILIHAN. Bentuknya sama dengan `opsi_json` snapshot, hanya
                // `media[]` tiap opsi yang mendapat `url`/`gambar` - supaya
                // gambar yang menempel pada pilihan B tampil DI DALAM tombol
                // B, bukan sebagai gambar lepas di atas seluruh pilihan.
                'opsi' => $this->opsiUntukSiswa($soal, $peserta->id),
                'jawaban' => $b->jawaban === null ? null : [
                    'isi' => $b->jawaban->answer_json,
                    'versi' => $b->jawaban->version,
                    'ditandai' => (bool) $b->jawaban->ditandai,
                    'tersimpan' => $b->jawaban->saved_at?->toIso8601String(),
                ],
                'rusak' => false,
            ];
        })->all();
    }

    /**
     * Opsi snapshot, dengan alamat berkas gambar pilihannya.
     *
     * Bentuknya SAMA persis dengan `opsi_json` dari `PembekuPaket::opsi()`
     * ({kunci, teks, media}), hanya `media[]` tiap opsi yang mendapat `url`
     * dan `gambar` - alasan yang sama dengan `mediaUntukSiswa`: alamat tidak
     * disimpan di snapshot supaya tidak menjadi basi saat nama rute berubah.
     *
     * Bila opsi tidak punya media, `media` tetap null - dan layar siswa
     * memperlakukannya sebagai pilihan teks biasa.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function opsiUntukSiswa(ExamQuestion $soal, int $pesertaId): ?array
    {
        $opsi = $soal->opsi_json;

        if (! is_array($opsi)) {
            return null;
        }

        return collect($opsi)
            ->map(function ($o) use ($soal, $pesertaId) {
                if (! is_array($o)) {
                    return $o;
                }

                $media = collect($o['media'] ?? [])
                    ->filter(fn ($m) => is_array($m) && isset($m['id']))
                    ->map(fn (array $m) => [
                        ...$m,
                        'url' => route('siswa.berkas', [
                            'peserta' => $pesertaId,
                            'soal' => $soal->id,
                            'media' => $m['id'],
                        ]),
                        'gambar' => str_starts_with((string) ($m['mime'] ?? ''), 'image/'),
                    ])
                    ->values()
                    ->all();

                return [
                    ...$o,
                    'media' => $media === [] ? null : $media,
                ];
            })
            ->all();
    }

    /**
     * Penunjuk media satu butir, dengan alamat yang bisa dibuka peramban.
     *
     * `gambar` dihitung di sini walau bisa juga disimpulkan peramban dari
     * `mime`: satu tempat yang menentukan apa yang dirender sebagai <img> dan
     * apa yang dirender sebagai tautan unduhan lebih mudah dipercaya daripada
     * dua tempat yang harus setuju.
     *
     * @return array<int, array<string, mixed>>
     */
    private function mediaUntukSiswa(ExamQuestion $soal, int $pesertaId): array
    {
        return collect($soal->media_json ?? [])
            ->filter(fn ($m) => is_array($m) && isset($m['id']))
            ->map(fn (array $m) => [
                ...$m,
                'url' => route('siswa.berkas', [
                    'peserta' => $pesertaId,
                    'soal' => $soal->id,
                    'media' => $m['id'],
                ]),
                'gambar' => str_starts_with((string) ($m['mime'] ?? ''), 'image/'),
            ])
            ->values()
            ->all();
    }

    /**
     * Simpan satu jawaban - PRD §11.4.
     *
     * Menjawab JSON, bukan redirect: autosave berjalan tiap 20 detik di
     * latar belakang, dan redirect pada laman yang sedang dikerjakan akan
     * memindahkan anak dari soal yang sedang ia baca.
     */
    public function simpan(Request $request, ExamParticipant $peserta, AttemptQuestion $butir): JsonResponse
    {
        $peserta = $this->milikSaya($peserta);

        $attempt = $this->attemptTerakhir($peserta);

        if ($attempt === null) {
            abort(404);
        }

        $this->mesin->tutupBilaKedaluwarsa($attempt);

        $valid = $request->validate([
            'isi' => ['array'],
            'versi' => ['nullable', 'integer', 'min:0'],
            'ditandai' => ['nullable', 'boolean'],
        ]);

        $tersimpan = $this->jawaban->simpan(
            $attempt,
            $butir,
            $valid['isi'] ?? [],
            $valid['versi'] ?? null,
            $valid['ditandai'] ?? null,
        );

        return response()->json([
            'versi' => $tersimpan->version,
            'tersimpan' => $tersimpan->saved_at?->toIso8601String(),
            'terjawab' => $tersimpan->terjawab(),
            'ditandai' => (bool) $tersimpan->ditandai,
            'progres' => $this->jawaban->progres($attempt),
            'status' => $attempt->fresh()?->status,
        ]);
    }

    /** Tandai/lepas ragu pada satu butir - tombol "Ragu" plate 22. */
    public function tandai(Request $request, ExamParticipant $peserta, AttemptQuestion $butir): JsonResponse
    {
        $peserta = $this->milikSaya($peserta);

        $attempt = $this->attemptTerakhir($peserta);

        if ($attempt === null) {
            abort(404);
        }

        $this->mesin->tutupBilaKedaluwarsa($attempt);

        $valid = $request->validate(['ditandai' => ['required', 'boolean']]);

        $tersimpan = $this->jawaban->tandai($attempt, $butir, (bool) $valid['ditandai']);

        return response()->json([
            'ditandai' => (bool) $tersimpan->ditandai,
            'progres' => $this->jawaban->progres($attempt),
        ]);
    }

    /**
     * Denyut - PRD §12.2, tiap 30 detik.
     *
     * Denyut yang tiba pada attempt yang sudah ditutup TIDAK ditolak: anak
     * yang mengumpulkan lalu menutup tab mengirim satu denyut terakhir, dan
     * jawaban "attempt sudah selesai" itulah yang membuat peramban
     * menghentikan pengirimnya sendiri.
     */
    public function denyut(Request $request, ExamParticipant $peserta, DenyutService $denyut): JsonResponse
    {
        $peserta = $this->milikSaya($peserta);

        $attempt = $this->attemptTerakhir($peserta);

        if ($attempt === null) {
            abort(404);
        }

        $this->mesin->tutupBilaKedaluwarsa($attempt);

        $valid = $request->validate([
            'perangkat' => ['nullable', 'string', 'max:64'],
            'layar_penuh' => ['nullable', 'boolean'],
            'pelindung' => ['nullable', 'boolean'],
            'device_type' => ['nullable', 'string', 'max:32'],
        ]);

        // Progres ikut dihitung di sini, bukan dikirim peramban: angka yang
        // dibaca pengawas di papan monitoring harus berasal dari jawaban
        // yang benar-benar tersimpan, bukan dari hitungan halaman yang bisa
        // berhenti diperbarui.
        //
        // Dihitung SEKALI lalu dipakai dua kali. Sebelumnya method ini
        // memanggil `progres()` di dua tempat - dan tiap panggilan membaca
        // ulang `attempt_questions` beserta jawabannya. Denyut berjalan tiap
        // tiga puluh detik dari setiap siswa yang sedang mengerjakan, jadi
        // penggandaannya bukan pada satu permintaan melainkan pada seluruh
        // ruang ujian sekaligus.
        $progres = $this->jawaban->progres($attempt);

        $hasil = $denyut->catat($attempt, $request, [
            ...$valid,
            'progres' => $progres['ringkas'],
        ]);

        return response()->json([
            ...$hasil,
            'progres' => $progres,
        ]);
    }

    /**
     * Laporan pelanggaran dari peramban siswa - blueprint `:561`, plate 24.
     *
     * =====================================================================
     * Endpoint ini TIDAK dipercaya, dan itu memang rancangannya
     * =====================================================================
     * Yang mengirimnya adalah kode yang berjalan di perangkat orang lain.
     * Permintaan yang ditempel langsung - atau peramban versi lama, atau
     * siswa yang membuka konsol - akan mengirim apa saja. Karena itu seluruh
     * keputusan dibuat di server:
     *
     *   - Jenis yang tidak dikenal diperlakukan sebagai tingkat 1, bukan
     *     ditolak: peramban versi baru yang melaporkan jenis yang belum ada
     *     di daftar harus tetap tercatat, dan menolaknya berarti kejadian
     *     itu hilang tanpa jejak.
     *   - Tingkat 0 (`klik_kanan`) tidak pernah menjadi baris, dan
     *     pemeriksaannya ada di `PencatatPelanggaran`, bukan di sini -
     *     lihat docblock-nya.
     *   - Attempt yang sudah ditutup tidak menerima laporan baru.
     *
     * ---------------------------------------------------------------------
     * Yang dikembalikan adalah TINDAKANNYA, bukan "berhasil"
     * ---------------------------------------------------------------------
     * Layar siswa harus bisa menulis "2 dari 5 sebelum terkunci" - angka itu
     * tidak bisa disusun klien dari apa pun yang sudah ia tahu, dan
     * mengirimkan ambangnya ke peramban berarti mengirim aturan main yang
     * bisa dibaca sebelum dipatuhi. Yang dikirim cukup sisanya, dan
     * `TindakanPelanggaran::toArray()` sudah menyusunnya untuk itu.
     *
     * ---------------------------------------------------------------
     * Kegagalan mengirim TIDAK boleh menahan apa pun
     * ---------------------------------------------------------------
     * Blueprint `:683`: "Kegagalan mengirim catatan pelanggaran tidak boleh
     * menahan penyimpanan jawaban, karena autosave jauh lebih penting
     * daripada log." Yang menjaganya bukan kelas ini melainkan pemanggilnya
     * di peramban - laporan dikirim lepas, dan jawabannya tidak pernah
     * ditunggu sebelum jawaban disimpan.
     */
    public function laporkan(Request $request, ExamParticipant $peserta, PencatatPelanggaran $pencatat): JsonResponse
    {
        $peserta = $this->milikSaya($peserta);

        $attempt = $this->attemptTerakhir($peserta);

        if ($attempt === null) {
            abort(404);
        }

        $valid = $request->validate([
            'jenis' => ['required', 'string', 'max:40'],
            'durasi_ms' => ['nullable', 'integer', 'min:0', 'max:3600000'],
            // Versi attempt yang dipegang klien - blueprint `:561`
            // menyebutnya, dan gunanya satu: laporan dari halaman yang sudah
            // lama mati (mis. tab yang tertinggal terbuka sesudah ujian
            // ditutup) dikenali, bukan diterima sebagai kejadian baru.
            'versi' => ['nullable', 'integer', 'min:0'],
        ], [], ['jenis' => 'jenis pelanggaran']);

        $tindakan = $pencatat->catat(
            $attempt,
            $valid['jenis'],
            $valid['durasi_ms'] ?? null,
        );

        return response()->json([
            ...$tindakan->toArray(),
            'jumlah' => $attempt->fresh()?->violation_count,
            'status' => $attempt->fresh()?->status,
        ]);
    }

    /** Selang simpan otomatis dari pengaturan sekolah, dengan batas akal. */
    private function selangSimpan(): int
    {
        $sekolah = auth()->user()?->school;

        $nilai = (int) ($sekolah?->settings['simpan_otomatis_detik'] ?? 20);

        // Batas 5..120 menutup satu kelas salah ketik yang mahal: nilai 0
        // berarti simpanan tiap milidetik, dan nilai 3600 berarti pekerjaan
        // satu jam yang hilang saat baterainya habis.
        return min(120, max(5, $nilai));
    }
}

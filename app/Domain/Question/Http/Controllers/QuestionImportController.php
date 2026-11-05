<?php

namespace App\Domain\Question\Http\Controllers;

use App\Domain\Academic\Models\Subject;
use App\Domain\Academic\Models\TeachingAssignment;
use App\Domain\Question\Enums\StatusImpor;
use App\Domain\Question\Import\PenjajarTemplate;
use App\Domain\Question\Models\Question;
use App\Domain\Question\Models\QuestionImportBatch;
use App\Domain\Question\Models\QuestionPackage;
use App\Domain\Question\Services\ImporSoalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Impor soal - wireframe-peran.html plate 04.
 *
 * =====================================================================
 * Unduhan template TIDAK punya controller sendiri
 * =====================================================================
 * Rencana awal menyebut `TemplateImporController` terpisah. Yang membuatnya
 * ditinggalkan: templatenya adalah `PenjajarTemplate::judul()` - daftar nama
 * kolom yang tinggal dicetak sebagai satu baris CSV. Controller tersendiri
 * untuk satu baris keluaran berarti satu berkas lagi yang harus dibuka
 * siapa pun yang mengubah daftar kolomnya, dan dua tempat yang bisa berbeda
 * diam-diam justru pada hal yang paling menentukan: kolom mana yang dibaca
 * importer.
 *
 * Ketiga rutenya - daftar, unggah, konfirmasi, template, laporan - tinggal di
 * sini karena semuanya berbicara tentang SATU hal. Laporan galat dan template
 * sama-sama satu berkas keluaran; yang membedakannya hanya dari mana isinya
 * datang.
 *
 * =====================================================================
 * Guru lain menerima 404, bukan 403
 * =====================================================================
 * Baris impor memuat NAMA ASLI berkas Guru lain - "soal_ganjil.xlsx" - dan
 * itu sudah cukup untuk memberi tahu apa yang sedang ia susun. Karena itu
 * jawabannya 404: bagi Guru lain, barisnya memang tidak ada. Aturan yang sama
 * dengan berkas berita acara Fase 6, dan alasannya juga sama.
 */
class QuestionImportController
{
    public function index(Request $request): Response
    {
        Gate::authorize('impor', Question::class);

        $user = $request->user();

        $riwayat = QuestionImportBatch::query()
            ->with(['mapel:id,name', 'paket:id,name'])
            ->where('teacher_id', $user->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (QuestionImportBatch $batch) => [
                'id' => $batch->id,
                'nama_asli' => $batch->nama_asli,
                'mapel' => $batch->mapel?->name,
                'paket' => $batch->paket?->name,
                'status' => $batch->statusImpor()->value,
                'status_label' => $batch->statusImpor()->label(),
                'sah' => (int) $batch->success_rows,
                'gagal' => (int) $batch->failed_rows,
                'total' => (int) $batch->total_rows,
                'ada_laporan' => $batch->error_report_path !== null,
                'diunggah_pada' => $batch->created_at?->diffForHumans(),
            ])
            ->all();

        // Berkas yang menunggu konfirmasi dibuka kembali otomatis supaya Guru
        // yang menutup peramban di tengah pratinjau tidak kehilangan 50
        // barisnya - dan supaya pratinjau yang tidak dilanjutkan terlihat,
        // bukan hilang tanpa jejak.
        $aktif = QuestionImportBatch::query()
            ->with(['mapel:id,name', 'paket:id,name'])
            ->where('teacher_id', $user->id)
            ->whereIn('status', [StatusImpor::Dipratinjau->value, StatusImpor::Gagal->value])
            ->orderByDesc('id')
            ->first();

        return Inertia::render('Guru/BankSoal/Impor', [
            'riwayat' => $riwayat,
            'aktif' => $aktif === null ? null : $this->rincian($aktif),
            'tujuan' => [
                'mapel' => $this->pilihanMapel($user),
                'paket' => $this->pilihanPaket($user),
                'paket_terpilih' => (int) ($request->query('paket') ?? 0) ?: null,
                'mapel_terpilih' => (int) ($request->query('mapel') ?? 0) ?: null,
            ],
            'kolom' => PenjajarTemplate::judul(),
        ]);
    }

    /**
     * Terima berkasnya, lalu LANGSUNG pratinjau.
     *
     * Dua langkah dalam satu permintaan, dan itu disengaja: memisahkannya
     * berarti Guru menekan "Unggah", menunggu, lalu menekan "Pratinjau" untuk
     * hal yang pasti ia inginkan berikutnya. Yang tetap terpisah adalah
     * KONFIRMASI - dan itulah satu-satunya langkah yang menyimpan soal.
     */
    public function unggah(Request $request, ImporSoalService $service): RedirectResponse
    {
        Gate::authorize('impor', Question::class);

        $data = $request->validate([
            'berkas' => ['required', 'file', 'max:2048'],
            'subject_id' => ['required', 'integer'],
            'question_package_id' => ['nullable', 'integer'],
        ], [
            'berkas.required' => 'Pilih berkasnya lebih dulu.',
            'berkas.max' => 'Berkasnya melebihi 2 MB. Pecah menjadi beberapa berkas, lalu impor satu per satu.',
            'subject_id.required' => 'Pilih mata pelajaran tujuan - seluruh soal di berkas ini akan masuk ke mapel itu.',
        ]);

        $user = $request->user();
        $mapelId = (int) $data['subject_id'];

        if (! in_array($mapelId, $this->mapelDiampu($user), true)) {
            throw ValidationException::withMessages([
                'subject_id' => 'Anda tidak mengampu mata pelajaran tersebut.',
            ]);
        }

        $batch = $service->unggah(
            $request->file('berkas'),
            (int) $user->school_id,
            (int) $user->id,
            $mapelId,
            isset($data['question_package_id']) ? (int) $data['question_package_id'] : null,
        );

        $service->pratinjau($batch);

        $batch->refresh();

        return redirect()
            ->route('guru.impor')
            ->with('sukses', $this->pesanPratinjau($batch));
    }

    /**
     * Simpan baris yang sah.
     *
     * `ke_paket` datang dari tombol yang ditekan Guru - wireframe plate 04
     * memisahkan "Impor ke bank saja" dari "Impor & tambah ke paket", dan
     * pemisahan itu bukan hiasan: menyiapkan 50 soal untuk satu ujian yang
     * sudah diketahui dan menyiapkan persediaan umum adalah dua niat yang
     * berbeda, dan yang kedua tidak ingin paketnya tersentuh.
     */
    public function konfirmasi(Request $request, QuestionImportBatch $batch, ImporSoalService $service): RedirectResponse
    {
        Gate::authorize('impor', Question::class);

        $this->pastikanMiliknya($request, $batch);

        $data = $request->validate([
            'ke_paket' => ['nullable', 'boolean'],
        ]);

        $service->konfirmasi($batch, (bool) ($data['ke_paket'] ?? true));

        $batch->refresh();

        return redirect()
            ->route('guru.impor')
            ->with('sukses', $this->pesanKonfirmasi($batch));
    }

    /**
     * Unduh template impor sebagai `.csv`.
     *
     * `.csv`, bukan `.xlsx`, dan itu keputusan yang menghapus pekerjaan alih-
     * alih menambahnya: Excel membuka `.csv` dengan sekali klik, sehingga
     * penulis XLSX - separuh pembaca yang sudah ada - tidak perlu ditulis
     * sama sekali. Isinya satu baris judul kolom ditambah tiga baris contoh,
     * karena kolom `kunci` berarti sesuatu yang berbeda di setiap jenis dan
     * contohnya menjelaskan lebih banyak daripada penjelasan.
     */
    public function template(): HttpResponse
    {
        Gate::authorize('impor', Question::class);

        $pegangan = fopen('php://temp', 'r+');

        // BOM: tanpa itu Excel berbahasa Indonesia menampilkan huruf beraksen
        // yang rusak, dan templatenya terlihat seperti berkas yang salah.
        fwrite($pegangan, "\xEF\xBB\xBF");

        fputcsv($pegangan, PenjajarTemplate::judul(), ';', '"', '\\');

        foreach ($this->contohBaris() as $contoh) {
            fputcsv($pegangan, $contoh, ';', '"', '\\');
        }

        rewind($pegangan);
        $isi = (string) stream_get_contents($pegangan);
        fclose($pegangan);

        return response($isi, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="template-impor-soal.csv"',
        ]);
    }

    /**
     * Unduh laporan galat satu berkas impor.
     *
     * Isi laporannya diambil dari disk privat, dan RUTE INI satu-satunya jalan
     * keluarnya - alasan yang sama dengan berita acara Fase 6: laporan galat
     * memuat cuplikan teks soal, dan itu bahan yang belum boleh dibaca siapa
     * pun yang menebak namanya.
     */
    public function laporan(Request $request, QuestionImportBatch $batch): StreamedResponse
    {
        Gate::authorize('impor', Question::class);

        $this->pastikanMiliknya($request, $batch);

        $jalur = $batch->error_report_path;

        if ($jalur === null || ! Storage::disk('local')->exists($jalur)) {
            abort(404, 'Laporan galat untuk berkas ini tidak ada. Berkasnya mungkin belum diimpor, atau seluruh barisnya sah.');
        }

        return Storage::disk('local')->download(
            $jalur,
            'laporan-impor-'.$batch->id.'.csv',
        );
    }

    /* ------------------------------------------------------------- Pembantu */

    /**
     * Baris yang harus dimiliki Guru yang membukanya.
     *
     * Diperiksa TERPISAH dari policy karena policy-nya menjawab "boleh
     * mengimpor atau tidak" - pertanyaan tentang perannya, bukan tentang
     * barisnya. Baris impor bukan aset sekolah yang boleh dibaca bersama:
     * ia memuat nama berkas dan cuplikan soal yang belum tentu pernah
     * disimpan ke bank soal.
     */
    private function pastikanMiliknya(Request $request, QuestionImportBatch $batch): void
    {
        if ((int) $batch->teacher_id !== (int) $request->user()->id) {
            abort(404, 'Berkas impor tidak ditemukan.');
        }
    }

    /**
     * Bentuk satu batch untuk laman pratinjau.
     *
     * Muatan soalnya DIBUANG dari props: yang dikirim hanya nomor baris,
     * ringkasannya, jenisnya, dan pesan galatnya. Guru melihat persis yang
     * dituntut blueprint - "baris mana yang perlu diperbaiki" - tanpa 50
     * muatan soal penuh melintas di jaringan dua kali.
     *
     * @return array<string, mixed>
     */
    private function rincian(QuestionImportBatch $batch): array
    {
        $pratinjau = $batch->pratinjau_json ?? [];

        return [
            'id' => $batch->id,
            'nama_asli' => $batch->nama_asli,
            'mapel' => $batch->mapel?->name,
            'paket' => $batch->paket?->name,
            'status' => $batch->statusImpor()->value,
            'status_label' => $batch->statusImpor()->label(),
            'boleh_konfirmasi' => $batch->statusImpor()->bolehDikonfirmasi(),
            'sah' => (int) ($pratinjau['sah'] ?? 0),
            'gagal' => (int) ($pratinjau['gagal'] ?? 0),
            'pesan_berkas' => $pratinjau['pesan'] ?? null,
            'kolom_terbaca' => $pratinjau['kolom'] ?? [],
            'baris' => array_map(fn (array $b) => [
                'nomor' => $b['nomor'] ?? null,
                'ringkas' => $b['ringkas'] ?? '',
                'jenis' => $b['jenis'],
                'jenis_pendek' => $b['jenis_pendek'] ?? '—',
                'sah' => (bool) ($b['sah'] ?? false),
                'kolom' => $b['kolom'] ?? null,
                'pesan' => $b['pesan'] ?? null,
            ], $pratinjau['baris'] ?? []),
        ];
    }

    private function pesanPratinjau(QuestionImportBatch $batch): string
    {
        $pratinjau = $batch->pratinjau_json ?? [];

        if ($batch->statusImpor() !== StatusImpor::Dipratinjau) {
            return 'Berkasnya tidak bisa dibaca: '.($pratinjau['pesan'] ?? 'sebabnya tidak diketahui.');
        }

        $sah = (int) ($pratinjau['sah'] ?? 0);
        $gagal = (int) ($pratinjau['gagal'] ?? 0);

        if ($gagal === 0) {
            return $sah.' baris siap diimpor. Periksa daftarnya di bawah, lalu tekan Impor.';
        }

        return $sah.' dari '.($sah + $gagal).' baris siap diimpor, '.$gagal.' baris perlu diperbaiki. '.
            'Nomor baris dan kolom yang salah ada di daftar di bawah.';
    }

    private function pesanKonfirmasi(QuestionImportBatch $batch): string
    {
        $pesan = $batch->success_rows.' soal tersimpan ke bank soal';

        if ($batch->question_package_id !== null) {
            $pesan .= ' dan ditambahkan ke paketnya';
        }

        if ((int) $batch->failed_rows > 0) {
            $pesan .= '. '.$batch->failed_rows.' baris dilewati - unduh laporan galatnya untuk melihat sebabnya.';
        }

        return $pesan.'.';
    }

    /** @return array<int, int> */
    private function mapelDiampu($user): array
    {
        return TeachingAssignment::query()
            ->where('teacher_id', $user->id)
            ->distinct()
            ->pluck('subject_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function pilihanMapel($user): array
    {
        return Subject::query()
            ->when(! $user->role->isAdmin(), fn ($q) => $q->whereIn('id', $this->mapelDiampu($user)))
            // Hanya mapel aktif: mengimpor 50 soal ke mapel yang sudah tidak
            // dipakai berarti soal yang tidak muncul di daftar mana pun.
            ->where('status', 'aktif')
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(fn (Subject $m) => ['id' => $m->id, 'nama' => $m->name, 'kode' => $m->code])
            ->all();
    }

    /**
     * Paket yang boleh menjadi tujuan impor.
     *
     * Hanya paket MILIK Guru ini, dan hanya yang masih draft. Alasannya
     * berbeda dari `viewPaket` - Guru boleh membaca paket Guru lain, tetapi
     * menambahkan 50 soal ke paket orang lain adalah menyuntingnya, dan itu
     * ditolak `ubahPaket`. Pemilih yang menawarkan sesuatu yang akan ditolak
     * adalah pemilih yang mengajari Guru untuk tidak mempercayai layarnya.
     *
     * @return array<int, array<string, mixed>>
     */
    private function pilihanPaket($user): array
    {
        return QuestionPackage::query()
            ->with('subject:id,name')
            ->where('created_by', $user->id)
            ->orderBy('name')
            ->get()
            ->map(fn (QuestionPackage $p) => [
                'id' => $p->id,
                'nama' => $p->name,
                'mapel_id' => $p->subject_id,
                'mapel' => $p->subject?->name,
                'status' => $p->status->value,
            ])
            ->all();
    }

    /**
     * Contoh baris di template unduhan.
     *
     * Tiga baris, dan ketiganya jenis yang berbeda - karena kolom `kunci`
     * berarti sesuatu yang berbeda di setiap jenis, dan satu contoh pilihan
     * ganda tidak menjelaskan bagaimana menuliskan pasangan jodoh atau
     * urutan. Tiga baris ini menjelaskan lebih banyak daripada satu halaman
     * penjelasan, dan ia ada di tempat Guru mencarinya.
     *
     * @return array<int, array<int, string>>
     */
    private function contohBaris(): array
    {
        return [
            // Pilihan ganda - kunci berisi satu huruf.
            [
                '', 'Ibu kota Indonesia', 'pilihan_ganda', 'mudah', '2',
                'Ibu kota negara Indonesia adalah…',
                'Jakarta', 'Bandung', 'Surabaya', 'Medan', '',
                'a', '', 'Jakarta adalah ibu kota Indonesia sejak 1945.',
            ],
            // Isian singkat - beberapa varian dipisah tanda pipa.
            [
                '', 'Hasil 2 + 2', 'isian_singkat', 'mudah', '1',
                'Berapa hasil dari 2 + 2?',
                '', '', '', '', '',
                '4|empat', '', '',
            ],
            // Uraian - kriterianya di kolom rubrik, dipisah tanda pipa.
            [
                '', 'Pembuktian Pythagoras', 'uraian', 'sulit', '8',
                'Buktikan teorema Pythagoras untuk segitiga siku-siku.',
                '', '', '', '', '',
                '', 'Langkah pembuktian:5 | Kesimpulan:3', '',
            ],
        ];
    }
}

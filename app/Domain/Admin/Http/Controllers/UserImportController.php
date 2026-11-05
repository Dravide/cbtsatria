<?php

namespace App\Domain\Admin\Http\Controllers;

use App\Domain\Admin\Enums\StatusImporPengguna;
use App\Domain\Admin\Import\PenjajarPengguna;
use App\Domain\Admin\Models\UserImportBatch;
use App\Domain\Admin\Services\ImporPenggunaService;
use App\Domain\Identity\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Impor pengguna - PRD §5.7, wireframe-admin.html plate 05.
 *
 * =====================================================================
 * Admin sekolah lain menerima 404, bukan 403
 * =====================================================================
 * Baris impor memuat NAMA ASLI berkasnya - "siswa-kelas-x-2026.xlsx" - dan
 * bagi sekolah lain itu sudah cukup untuk memberi tahu apa yang sedang
 * disusun sekolah lain. Karena itu jawabannya 404: bagi Admin lain, barisnya
 * memang tidak ada. Aturan dan alasan yang sama dengan impor soal Fase 4.
 *
 * =====================================================================
 * Template, dan mengapa ia `.csv`
 * =====================================================================
 * `.csv` berarti Excel membuka templatenya dengan sekali klik, sehingga
 * penulis XLSX - separuh pembaca yang sudah ada - tidak perlu ditulis sama
 * sekali. Isinya satu baris judul kolom ditambah tiga baris contoh: satu
 * siswa, satu guru, satu pengawas.
 *
 * Tiga contoh itu bukan hiasan. Templatenya SATU untuk tiga peran, dan yang
 * menjelaskan kolom mana bermakna bagi peran mana adalah baris contohnya -
 * bukan halaman penjelasan yang tidak akan dibuka siapa pun.
 */
class UserImportController
{
    /** Berapa baris riwayat yang dikirim ke laman. */
    private const RIWAYAT = 20;

    public function index(Request $request): Response
    {
        Gate::authorize('import', User::class);

        $riwayat = UserImportBatch::query()
            ->orderByDesc('id')
            ->limit(self::RIWAYAT)
            ->get()
            ->map(fn (UserImportBatch $batch) => [
                'id' => $batch->id,
                'nama_asli' => $batch->nama_asli,
                'status' => $batch->statusImpor()->value,
                'status_label' => $batch->statusImpor()->label(),
                'sah' => (int) $batch->success_rows,
                'gagal' => (int) $batch->failed_rows,
                'baru' => (int) $batch->created_rows,
                'diperbarui' => (int) $batch->updated_rows,
                'total' => (int) $batch->total_rows,
                'ada_laporan' => $batch->error_report_path !== null,
                'diunggah_pada' => $batch->created_at?->diffForHumans(),
            ])
            ->all();

        // Berkas yang menunggu konfirmasi dibuka kembali otomatis supaya
        // Admin yang menutup peramban di tengah pratinjau tidak kehilangan
        // 1.284 barisnya - dan supaya pratinjau yang tidak dilanjutkan
        // terlihat, bukan hilang tanpa jejak.
        $aktif = UserImportBatch::query()
            ->whereIn('status', [
                StatusImporPengguna::Dipratinjau->value,
                StatusImporPengguna::Gagal->value,
            ])
            ->orderByDesc('id')
            ->first();

        return Inertia::render('Admin/PenggunaImpor', [
            'riwayat' => $riwayat,
            'aktif' => $aktif === null ? null : $this->rincian($aktif),
            'kolom' => PenjajarPengguna::judul(),
        ]);
    }

    /**
     * Terima berkasnya, lalu LANGSUNG pratinjau.
     *
     * Dua langkah dalam satu permintaan: memisahkannya berarti Admin menekan
     * "Unggah", menunggu, lalu menekan "Pratinjau" untuk hal yang pasti ia
     * inginkan berikutnya. Yang tetap terpisah adalah KONFIRMASI - dan
     * itulah satu-satunya langkah yang menulis akun.
     */
    public function unggah(Request $request, ImporPenggunaService $service): RedirectResponse
    {
        Gate::authorize('import', User::class);

        $data = $request->validate([
            'berkas' => ['required', 'file', 'max:2048'],
        ], [
            'berkas.required' => 'Pilih berkasnya lebih dulu.',
            'berkas.max' => 'Berkasnya melebihi 2 MB. Pecah menjadi beberapa berkas, lalu impor satu per satu.',
        ]);

        $user = $request->user();

        $batch = $service->unggah(
            $request->file('berkas'),
            (int) $user->school_id,
            (int) $user->id,
        );

        $service->pratinjau($batch);

        $batch->refresh();

        return redirect()
            ->route('admin.pengguna.impor')
            ->with('sukses', $this->pesanPratinjau($batch));
    }

    /** Simpan baris yang sah. */
    public function konfirmasi(
        Request $request,
        UserImportBatch $batch,
        ImporPenggunaService $service,
    ): RedirectResponse {
        Gate::authorize('import', User::class);

        $this->pastikanMiliknya($request, $batch);

        $service->konfirmasi($batch);

        $batch->refresh();

        return redirect()
            ->route('admin.pengguna.impor')
            ->with('sukses', $this->pesanKonfirmasi($batch));
    }

    /**
     * Unduh template impor sebagai `.csv`.
     *
     * Isinya `PenjajarPengguna::judul()` - daftar kolom yang sama yang dibaca
     * importer, sehingga template dan importer tidak bisa berbeda diam-diam.
     */
    public function template(): HttpResponse
    {
        Gate::authorize('import', User::class);

        $pegangan = fopen('php://temp', 'r+');

        // BOM: tanpa itu Excel berbahasa Indonesia menampilkan huruf beraksen
        // yang rusak, dan templatenya terlihat seperti berkas yang salah.
        fwrite($pegangan, "\xEF\xBB\xBF");

        fputcsv($pegangan, PenjajarPengguna::judul(), ';', '"', '\\');

        foreach ($this->contohBaris() as $contoh) {
            fputcsv($pegangan, $contoh, ';', '"', '\\');
        }

        rewind($pegangan);
        $isi = (string) stream_get_contents($pegangan);
        fclose($pegangan);

        return response($isi, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="template-impor-pengguna.csv"',
        ]);
    }

    /**
     * Unduh laporan galat satu berkas impor.
     *
     * Isinya diambil dari disk privat, dan RUTE INI satu-satunya jalan
     * keluarnya: laporan galat memuat nama dan nomor identitas orang, dan itu
     * bahan yang belum boleh dibaca siapa pun yang menebak namanya.
     */
    public function laporan(Request $request, UserImportBatch $batch): StreamedResponse
    {
        Gate::authorize('import', User::class);

        $this->pastikanMiliknya($request, $batch);

        $jalur = $batch->error_report_path;

        if ($jalur === null || ! Storage::disk('local')->exists($jalur)) {
            abort(404, 'Laporan galat untuk berkas ini tidak ada. Berkasnya mungkin belum diimpor, atau seluruh barisnya sah.');
        }

        return Storage::disk('local')->download($jalur, 'laporan-impor-pengguna-'.$batch->id.'.csv');
    }

    /* ------------------------------------------------------------- Pembantu */

    /**
     * Baris yang harus dimiliki Admin yang membukanya.
     *
     * Diperiksa TERPISAH dari policy karena policy-nya menjawab "boleh
     * mengimpor atau tidak" - pertanyaan tentang perannya, bukan tentang
     * barisnya. Baris impor memuat nama berkas dan nomor identitas orang,
     * dan itu bukan aset sekolah yang boleh dibaca bersama.
     */
    private function pastikanMiliknya(Request $request, UserImportBatch $batch): void
    {
        if ((int) $batch->user_id !== (int) $request->user()->id) {
            abort(404, 'Berkas impor tidak ditemukan.');
        }
    }

    /**
     * Bentuk satu batch untuk laman pratinjau.
     *
     * Muatan barisnya DIBUANG dari props: yang dikirim hanya nomor, peran,
     * nama, nomor identitas, kelas, dan pesannya. Admin melihat persis yang
     * ia butuhkan - "baris mana yang perlu diperbaiki" - tanpa 1.284 muatan
     * akun penuh melintas di jaringan dua kali.
     *
     * @return array<string, mixed>
     */
    private function rincian(UserImportBatch $batch): array
    {
        $pratinjau = $batch->pratinjau_json ?? [];

        return [
            'id' => $batch->id,
            'nama_asli' => $batch->nama_asli,
            'status' => $batch->statusImpor()->value,
            'status_label' => $batch->statusImpor()->label(),
            'boleh_konfirmasi' => $batch->statusImpor()->bolehDikonfirmasi(),
            'sah' => (int) ($pratinjau['sah'] ?? 0),
            'gagal' => (int) ($pratinjau['gagal'] ?? 0),
            'baru' => (int) ($pratinjau['baru'] ?? 0),
            'diperbarui' => (int) ($pratinjau['diperbarui'] ?? 0),
            'pesan_berkas' => $pratinjau['pesan'] ?? null,
            'kolom_terbaca' => $pratinjau['kolom'] ?? [],
            'baris' => array_map(fn (array $b) => [
                'nomor' => $b['nomor'] ?? null,
                'peran' => $b['peran_label'] ?? null,
                'nama' => $b['nama'] ?? '',
                'nomor_identitas' => $b['nomor_identitas'] ?? null,
                'aksi' => $b['aksi'] ?? 'gagal',
                'kelas' => $b['kelas'] ?? null,
                'kelas_aksi' => $b['kelas_aksi'] ?? null,
                'kelas_dari' => $b['kelas_dari'] ?? null,
                'sah' => (bool) ($b['sah'] ?? false),
                'kolom' => $b['kolom'] ?? null,
                'pesan' => $b['pesan'] ?? null,
            ], $pratinjau['baris'] ?? []),
        ];
    }

    private function pesanPratinjau(UserImportBatch $batch): string
    {
        $pratinjau = $batch->pratinjau_json ?? [];

        if ($batch->statusImpor() !== StatusImporPengguna::Dipratinjau) {
            return 'Berkasnya tidak bisa dibaca: '.($pratinjau['pesan'] ?? 'sebabnya tidak diketahui.');
        }

        $baru = (int) ($pratinjau['baru'] ?? 0);
        $diperbarui = (int) ($pratinjau['diperbarui'] ?? 0);
        $gagal = (int) ($pratinjau['gagal'] ?? 0);

        $pesan = $baru.' akun baru dan '.$diperbarui.' akun lama akan diperbarui. ';

        if ($gagal > 0) {
            $pesan .= $gagal.' baris perlu diperbaiki - nomor barisnya ada di daftar di bawah. ';
        }

        return $pesan.'Periksa daftarnya, lalu tekan Impor.';
    }

    private function pesanKonfirmasi(UserImportBatch $batch): string
    {
        $pesan = $batch->created_rows.' akun dibuat, '.$batch->updated_rows.' akun diperbarui';

        if ((int) $batch->failed_rows > 0) {
            $pesan .= '. '.$batch->failed_rows.' baris dilewati - unduh laporan galatnya untuk melihat sebabnya';
        }

        return $pesan.'.';
    }

    /**
     * Contoh baris di template unduhan.
     *
     * Satu siswa (dengan kelas), satu guru (dengan NIP), satu pengawas (dengan
     * kode pegawai). Ketiganya peran yang berbeda, dan itulah yang menjelaskan
     * kolom mana bermakna bagi siapa tanpa satu halaman penjelasan pun.
     *
     * Kolom `aktif` diisi "ya" di ketiganya; ia boleh dikosongkan, dan
     * dikosongkan berarti aktif.
     *
     * @return array<int, array<int, string>>
     */
    private function contohBaris(): array
    {
        return [
            // peran, nama, nis, nisn, nip, kode_pegawai, kelas, email,
            // telepon, jenis_kelamin, alamat, aktif
            [
                'siswa', 'Ani Mulyani', '2024001', '0012345678', '', '', 'X-1',
                '', '081234567890', 'P', 'Jl. Melati No. 3', 'ya',
            ],
            [
                'guru', 'Budi Santoso', '', '', '1987654321', '', '',
                'budi@sekolah.sch.id', '081298765432', 'L', 'Jl. Kenanga No. 7', 'ya',
            ],
            [
                'pengawas', 'Citra Dewi', '', '', '', 'PGW-001', '',
                '', '081211112222', '', 'Jl. Anggrek No. 12', 'ya',
            ],
        ];
    }
}

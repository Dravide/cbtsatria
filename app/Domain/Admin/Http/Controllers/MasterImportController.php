<?php

namespace App\Domain\Admin\Http\Controllers;

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Admin\Enums\JenisImporMaster;
use App\Domain\Admin\Enums\StatusImporPengguna;
use App\Domain\Admin\Models\MasterImportBatch;
use App\Domain\Admin\Services\ImporMasterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Impor master data - mata pelajaran dan ruang ujian.
 *
 * =====================================================================
 * Mengapa SATU controller untuk dua laman
 * =====================================================================
 * Alurnya identik - unggah, pratinjau per baris, konfirmasi - dan yang
 * berbeda hanya jenisnya. Dua controller kembar berarti dua tempat yang harus
 * tetap sepakat soal siapa yang boleh mengunduh laporan dan bagaimana baris
 * milik orang lain dijawab; dan tempat kedua selalu yang terlupakan.
 *
 * Jenisnya datang dari RUTE, bukan dari body. `{jenis}` di URL divalidasi
 * menjadi enum sebelum satu baris pun dibaca, sehingga URL yang menyebut jenis
 * yang tidak ada menerima 404 - bukan impor yang berjalan atas jenis yang
 * ditebak dari berkasnya.
 *
 * =====================================================================
 * Admin sekolah lain menerima 404, bukan 403
 * =====================================================================
 * Baris impor memuat NAMA ASLI berkasnya - "mapel-smkn1-2026.xlsx" - dan bagi
 * sekolah lain itu sudah cukup memberi tahu apa yang sedang disusun sekolah
 * lain. Karena itu jawabannya 404: bagi Admin lain, barisnya memang tidak
 * ada. Aturan dan alasan yang sama dengan impor pengguna dan impor soal.
 *
 * =====================================================================
 * Template `.csv`, dan mengapa bukan `.xlsx`
 * =====================================================================
 * `.csv` berarti Excel membuka templatenya dengan sekali klik, sehingga
 * penulis XLSX - separuh pembaca yang sudah ada - tidak perlu ditulis sama
 * sekali. Isinya satu baris judul kolom ditambah DUA baris contoh: baris yang
 * seluruh kolomnya terisi, dan baris yang hanya memuat kolom wajibnya.
 *
 * Dua baris itu bukan hiasan. Yang pertama menjelaskan bentuk lengkapnya, dan
 * yang kedua menjelaskan bahwa kolom sisanya memang boleh dikosongkan -
 * pertanyaan yang paling sering muncul, dan satu-satunya yang tidak bisa
 * dijawab oleh satu baris contoh.
 */
class MasterImportController
{
    /** Berapa baris riwayat yang dikirim ke laman. */
    private const RIWAYAT = 20;

    public function index(Request $request, string $jenis): Response
    {
        $j = $this->jenis($jenis);

        Gate::authorize(self::ABILITY, $j->kelasModel());

        // Tahun ajaran yang dituju - hanya berarti bagi jenis yang membutuhkan
        // -nya. Id dari query diturunkan lewat `AcademicYear::find()` (bukan
        // `findOrFail`), karena laman ini tetap harus terbuka pada jenis yang
        // tidak memakai tahun ajaran sama sekali, dan pada jenis yang
        // memakainya tetapi sekolahnya belum punya satu pun.
        $tahun = $j->butuhTahunAjaran()
            ? $this->tahunDipilih($request)
            : null;

        $riwayat = MasterImportBatch::query()
            ->where('jenis', $j->value)
            ->orderByDesc('id')
            ->limit(self::RIWAYAT)
            ->get()
            ->map(fn (MasterImportBatch $batch) => [
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
        // 200 barisnya - dan supaya pratinjau yang tidak dilanjutkan terlihat,
        // bukan hilang tanpa jejak.
        $aktif = MasterImportBatch::query()
            ->where('jenis', $j->value)
            ->whereIn('status', [
                StatusImporPengguna::Dipratinjau->value,
                StatusImporPengguna::Gagal->value,
            ])
            ->orderByDesc('id')
            ->first();

        return Inertia::render('Admin/MasterImpor', [
            'jenis' => $j->value,
            'jenis_label' => $j->label(),
            'kembali' => $this->kembali($j, $tahun),
            'riwayat' => $riwayat,
            'aktif' => $aktif === null ? null : $this->rincian($aktif),
            'kolom' => $j->kolom(),
            'kolom_kunci' => $j->kolomKunci(),
            'label_kunci' => $j->labelKunci(),
            'status_sah' => $j->statusSah(),
            'butuh_tahun' => $j->butuhTahunAjaran(),
            'tahun' => $tahun === null ? null : [
                'id' => $tahun->id,
                'nama' => $tahun->name,
                'aktif' => $tahun->is_active,
            ],
            'daftar_tahun' => $j->butuhTahunAjaran() ? $this->daftarTahun() : [],
        ]);
    }

    /**
     * Terima berkasnya, lalu LANGSUNG pratinjau.
     *
     * Dua langkah dalam satu permintaan: memisahkannya berarti Admin menekan
     * "Unggah", menunggu, lalu menekan "Pratinjau" untuk hal yang pasti ia
     * inginkan berikutnya. Yang tetap terpisah adalah KONFIRMASI - dan itulah
     * satu-satunya langkah yang menulis barisnya.
     */
    public function unggah(
        Request $request,
        string $jenis,
        ImporMasterService $service,
    ): RedirectResponse {
        $j = $this->jenis($jenis);

        Gate::authorize(self::ABILITY, $j->kelasModel());

        $request->validate([
            'berkas' => ['required', 'file', 'max:2048'],
            // Wajib hanya bagi jenis yang terikat tahun ajaran - lihat
            // `JenisImporMaster::butuhTahunAjaran()`. Pesannya menyebut apa
            // yang harus dilakukan, bukan sekadar "kolom wajib".
            'tahun_ajaran_id' => $j->butuhTahunAjaran()
                ? ['required', 'integer']
                : ['nullable', 'integer'],
        ], [
            'berkas.required' => 'Pilih berkasnya lebih dulu.',
            'berkas.max' => 'Berkasnya melebihi 2 MB. Pecah menjadi beberapa berkas, lalu impor satu per satu.',
            'tahun_ajaran_id.required' => 'Pilih tahun ajarannya lebih dulu. Kelas selalu milik satu tahun ajaran, dan nama yang sama boleh muncul di tahun yang berbeda.',
        ]);

        // Tahun ajarannya diverifikasi ADA dan MILIK sekolah ini - bukan
        // sekadar integer. Scope `AcademicYear` yang memutuskan: id sekolah
        // lain tidak ditemukan, dan hasilnya 404 yang jujur. Tanpa ini,
        // `academic_year_id` dari body adalah id mana pun yang dikirim.
        $tahun = $j->butuhTahunAjaran()
            ? AcademicYear::findOrFail($request->integer('tahun_ajaran_id'))
            : null;

        $user = $request->user();

        $batch = $service->unggah(
            $request->file('berkas'),
            $j,
            (int) $user->school_id,
            (int) $user->id,
            $tahun?->id,
        );

        $service->pratinjau($batch);

        $batch->refresh();

        return redirect()
            ->route('admin.master.impor', $this->rute($j, $tahun?->id))
            ->with('sukses', $this->pesanPratinjau($j, $batch));
    }

    /** Simpan baris yang sah. */
    public function konfirmasi(
        Request $request,
        string $jenis,
        MasterImportBatch $batch,
        ImporMasterService $service,
    ): RedirectResponse {
        $j = $this->jenis($jenis);

        Gate::authorize(self::ABILITY, $j->kelasModel());

        $this->pastikanMiliknya($request, $j, $batch);

        $service->konfirmasi($batch);

        $batch->refresh();

        // Tahun ajarannya ikut di alamat kembali, dan itu diambil dari
        // BARISNYA, bukan dari query yang dikirim peramban. Impor bisa
        // dikonfirmasi sesudah Admin berganti tahun ajaran di tab lain, dan
        // mengembalikannya ke tahun yang sedang terbuka akan menampilkan
        // daftar yang tidak memuat baris yang baru saja ia impor.
        return redirect()
            ->route('admin.master.impor', $this->rute($j, $batch->academic_year_id === null ? null : (int) $batch->academic_year_id))
            ->with('sukses', $this->pesanKonfirmasi($j, $batch));
    }

    /**
     * Unduh template impor sebagai `.csv`.
     *
     * Isinya `JenisImporMaster::kolom()` - daftar kolom yang sama yang dibaca
     * importer, sehingga template dan importer tidak bisa berbeda diam-diam.
     */
    public function template(string $jenis): HttpResponse
    {
        $j = $this->jenis($jenis);

        Gate::authorize(self::ABILITY, $j->kelasModel());

        $pegangan = fopen('php://temp', 'r+');

        // BOM: tanpa itu Excel berbahasa Indonesia menampilkan huruf beraksen
        // yang rusak, dan templatenya terlihat seperti berkas yang salah.
        fwrite($pegangan, "\xEF\xBB\xBF");

        fputcsv($pegangan, $j->kolom(), ';', '"', '\\');

        foreach ($this->contohBaris($j) as $contoh) {
            fputcsv($pegangan, $contoh, ';', '"', '\\');
        }

        rewind($pegangan);
        $isi = (string) stream_get_contents($pegangan);
        fclose($pegangan);

        return response($isi, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$j->namaTemplate().'"',
        ]);
    }

    /**
     * Unduh laporan galat satu berkas impor.
     *
     * Isinya diambil dari disk privat, dan RUTE INI satu-satunya jalan
     * keluarnya: laporan galat memuat nama berkas dan isi barisnya, dan itu
     * bahan yang belum boleh dibaca siapa pun yang menebak namanya.
     */
    public function laporan(
        Request $request,
        string $jenis,
        MasterImportBatch $batch,
    ): StreamedResponse {
        $j = $this->jenis($jenis);

        Gate::authorize(self::ABILITY, $j->kelasModel());

        $this->pastikanMiliknya($request, $j, $batch);

        $jalur = $batch->error_report_path;

        if ($jalur === null || ! Storage::disk('local')->exists($jalur)) {
            abort(404, 'Laporan galat untuk berkas ini tidak ada. Berkasnya mungkin belum diimpor, atau seluruh barisnya sah.');
        }

        return Storage::disk('local')->download($jalur, $j->namaLaporan((int) $batch->id));
    }

    /* ------------------------------------------------------------- Pembantu */

    /**
     * Jenis dari segmen URL, atau 404.
     *
     * Segmen URL yang tidak dikenal dijawab 404, bukan 422: yang memintanya
     * bukan Admin yang salah mengisi form - ia mengetik atau menempel alamat
     * yang tidak ada.
     */
    private function jenis(string $jenis): JenisImporMaster
    {
        return JenisImporMaster::tryFrom($jenis)
            ?? abort(404, 'Jenis impor "'.$jenis.'" tidak ada.');
    }

    /**
     * Ability yang diperiksa untuk jenis ini.
     *
     * `create`, bukan `import`: hanya impor PENGGUNA yang punya ability
     * `UserPolicy::import`. Master data memakai ability yang sudah ada di
     * `AcademicPolicy` dan `FacilityPolicy`, dan keduanya menuntut hal yang
     * sama - peran Admin. Menambah ability baru berarti izin kedua untuk
     * tindakan yang sama, dan izin yang ditulis dua kali adalah izin yang
     * diam-diam berbeda.
     */
    private const ABILITY = 'create';

    /** Laman yang dibuka Admin setelah impornya selesai. */
    private function kembali(JenisImporMaster $jenis, ?AcademicYear $tahun = null): string
    {
        return match ($jenis) {
            JenisImporMaster::Mapel => '/admin/mapel',
            JenisImporMaster::Ruang => '/admin/ruang',
            // Tahun ajarannya ikut di alamat, supaya "Kembali" membuka daftar
            // kelas yang MEMUAT baris yang baru saja diimpor. Tanpa itu, Admin
            // yang mengimpor ke tahun tidak aktif akan mendarat di tahun
            // berjalan dan mendapati kelasnya "tidak ada".
            JenisImporMaster::Kelas => '/admin/kelas'.($tahun === null ? '' : '?ta='.$tahun->id),
        };
    }

    /**
     * Parameter rute laman ini, lengkap dengan tahun ajarannya bila jenisnya
     * membutuhkan.
     *
     * Satu tempat, bukan disusun ulang di setiap `redirect()`: alamat yang
     * kehilangan `?ta=` mengembalikan Admin ke laman impor tanpa tahun
     * terpilih, dan laman itu lalu menolak setiap unggahan berikutnya.
     *
     * @return array<string, mixed>
     */
    private function rute(JenisImporMaster $jenis, ?int $tahunAjaranId): array
    {
        $parameter = ['jenis' => $jenis->value];

        if ($jenis->butuhTahunAjaran() && $tahunAjaranId !== null) {
            $parameter['ta'] = $tahunAjaranId;
        }

        return $parameter;
    }

    /**
     * Tahun ajaran yang dibuka di laman impor kelas.
     *
     * Mengikuti alur yang sama dengan `SchoolClassController::index()`: dari
     * URL bila ada, jika tidak yang aktif, jika tidak yang terbaru. Tiga
     * langkah itu membuat laman ini selalu punya jawaban yang masuk akal meski
     * Admin belum pernah mengaktifkan apa pun - dan yang pertama kali membuka
     * impor biasanya memang belum.
     */
    private function tahunDipilih(Request $request): ?AcademicYear
    {
        $id = $request->query('ta');

        if ($id !== null) {
            // `find`, bukan `findOrFail`: id yang tidak dikenal - termasuk id
            // sekolah lain, yang disembunyikan scope - jatuh ke bawah dan
            // mendapat tahun bawaan. 404 di sini akan membuat laman impor
            // tidak bisa dibuka sama sekali karena satu query yang salah,
            // padahal yang perlu dilakukan Admin cuma memilih tahun lain.
            $diminta = AcademicYear::find((int) $id);

            if ($diminta !== null) {
                return $diminta;
            }
        }

        return AcademicYear::query()->where('is_active', true)->first()
            ?? AcademicYear::query()->orderByDesc('start_date')->first();
    }

    /**
     * Seluruh tahun ajaran sekolah ini, untuk pemilih di laman impor.
     *
     * @return array<int, array<string, mixed>>
     */
    private function daftarTahun(): array
    {
        return AcademicYear::query()
            ->orderByDesc('start_date')
            ->get(['id', 'name', 'is_active'])
            ->map(fn (AcademicYear $t) => [
                'id' => $t->id,
                'nama' => $t->name,
                'aktif' => (bool) $t->is_active,
            ])
            ->all();
    }

    /**
     * Baris yang harus dimiliki Admin yang membukanya.
     *
     * Diperiksa TERPISAH dari policy karena policy-nya menjawab "boleh
     * mengimpor atau tidak" - pertanyaan tentang perannya, bukan tentang
     * barisnya. Baris impor memuat nama berkas orang lain, dan itu bukan aset
     * sekolah yang boleh dibaca bersama.
     */
    private function pastikanMiliknya(
        Request $request,
        JenisImporMaster $jenis,
        MasterImportBatch $batch,
    ): void {
        if ((int) $batch->user_id !== (int) $request->user()->id
            || $batch->jenis !== $jenis->value) {
            abort(404, 'Berkas impor tidak ditemukan.');
        }
    }

    /**
     * Bentuk satu batch untuk laman pratinjau.
     *
     * Muatan barisnya DIBUANG dari props: yang dikirim hanya nomor, kode,
     * nama, dan pesannya. Admin melihat persis yang ia butuhkan - "baris mana
     * yang perlu diperbaiki" - tanpa seluruh isi berkasnya melintas di jaringan
     * dua kali.
     *
     * @return array<string, mixed>
     */
    private function rincian(MasterImportBatch $batch): array
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
                'kode' => $b['kode'] ?? '',
                'nama' => $b['nama'] ?? '',
                'aksi' => $b['aksi'] ?? 'gagal',
                'sah' => (bool) ($b['sah'] ?? false),
                'kolom' => $b['kolom'] ?? null,
                'pesan' => $b['pesan'] ?? null,
            ], $pratinjau['baris'] ?? []),
        ];
    }

    private function pesanPratinjau(JenisImporMaster $jenis, MasterImportBatch $batch): string
    {
        $pratinjau = $batch->pratinjau_json ?? [];

        if ($batch->statusImpor() !== StatusImporPengguna::Dipratinjau) {
            return 'Berkasnya tidak bisa dibaca: '.($pratinjau['pesan'] ?? 'sebabnya tidak diketahui.');
        }

        $baru = (int) ($pratinjau['baru'] ?? 0);
        $diperbarui = (int) ($pratinjau['diperbarui'] ?? 0);
        $gagal = (int) ($pratinjau['gagal'] ?? 0);

        $pesan = $baru.' '.$jenis->label().' baru dan '.$diperbarui.' yang lama akan diperbarui. ';

        if ($gagal > 0) {
            $pesan .= $gagal.' baris perlu diperbaiki - nomor barisnya ada di daftar di bawah. ';
        }

        return $pesan.'Periksa daftarnya, lalu tekan Impor.';
    }

    private function pesanKonfirmasi(JenisImporMaster $jenis, MasterImportBatch $batch): string
    {
        $pesan = $batch->created_rows.' '.$jenis->label().' dibuat, '
            .$batch->updated_rows.' diperbarui';

        if ((int) $batch->failed_rows > 0) {
            $pesan .= '. '.$batch->failed_rows.' baris dilewati - unduh laporan galatnya untuk melihat sebabnya';
        }

        return $pesan.'.';
    }

    /**
     * Contoh baris di template unduhan.
     *
     * Dua baris, dan yang kedua sengaja hanya memuat kolom wajibnya: ia
     * menjawab pertanyaan yang tidak bisa dijawab baris pertama - "boleh
     * dikosongkan tidak?".
     *
     * @return array<int, array<int, string>>
     */
    private function contohBaris(JenisImporMaster $jenis): array
    {
        return match ($jenis) {
            // kode, nama, kelompok, tingkat, status
            JenisImporMaster::Mapel => [
                ['MTK', 'Matematika', 'Wajib', 'X', 'aktif'],
                ['BIN', 'Bahasa Indonesia', '', '', ''],
            ],
            // kode, nama, lokasi, kapasitas, pc, status
            JenisImporMaster::Ruang => [
                ['R-01', 'Ruang 01', 'Gedung A lantai 1', '32', '32', 'aktif'],
                ['R-02', 'Ruang 02', '', '40', '0', 'aktif'],
            ],
            // nama, tingkat, status
            JenisImporMaster::Kelas => [
                ['X-1', 'X', 'aktif'],
                ['X-2', '', ''],
            ],
        };
    }
}

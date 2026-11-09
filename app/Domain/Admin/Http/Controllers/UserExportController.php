<?php

namespace App\Domain\Admin\Http\Controllers;

use App\Domain\Admin\Jobs\BuatEksporPengguna;
use App\Domain\Admin\Models\UserExport;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ekspor pengguna - PRD §5.7, wireframe-admin.html plate 05.
 *
 * =====================================================================
 * Tidak ada `index`, dan itu disengaja
 * =====================================================================
 * Riwayat ekspor tinggal di laman Pengguna bersama tabelnya - dikirim
 * sebagai prop dari `UserController@index`. Halaman riwayat tersendiri berarti
 * Admin harus berpindah laman untuk menjawab "berkas saya jadi belum?", dan
 * pertanyaan itu muncul tepat setelah ia menekan tombolnya.
 *
 * Pola yang sama dipakai plate 22 Fase 8: `LaporanController@index` mengirim
 * `ekspor`, dan tidak ada rute GET untuk daftarnya.
 *
 * =====================================================================
 * Sepuluh menit, bukan sekali lihat
 * =====================================================================
 * Dua rute di sini adalah SATU-SATUNYA jalan berkasnya keluar dari disk
 * privat. `path` tidak pernah sampai ke peramban, dan nama di disk acak -
 * sehingga tebakan nama tidak menghasilkan apa pun bahkan bila CSP-nya
 * dilonggarkan suatu hari.
 */
class UserExportController
{
    /**
     * Minta satu berkas. Menulis antrean lalu kembali - tidak menunggu.
     *
     * =====================================================================
     * Penyaringnya disimpan, bukan hasilnya
     * =====================================================================
     * `parameter_json` memuat peran (tab), kata kunci, dan status persis
     * seperti yang sedang aktif di layar Admin. Dua gunanya: berkasnya memuat
     * baris yang ia LIHAT saat menekan tombol - bukan seluruh sekolah, yang
     * akan membuat "Ekspor" pada tab Guru mengirim 1.284 siswa - dan ekspor
     * yang gagal bisa diulang dengan penyaring yang sama.
     */
    public function minta(Request $request): RedirectResponse
    {
        Gate::authorize('ekspor', User::class);

        $data = $request->validate([
            'format' => ['required', 'string', 'in:'.implode(',', UserExport::FORMAT)],
            // Kosong berarti "seluruh peran" - dan itu memang keadaan tab
            // Admin, yang tidak punya tab untuk dirinya sendiri di laman ini.
            'role' => ['nullable', 'string', 'in:'.implode(',', array_column(Role::cases(), 'value'))],
            'cari' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:0,1'],
        ]);

        $ekspor = new UserExport;
        $ekspor->forceFill([
            'school_id' => $request->user()->school_id,
            'requested_by' => $request->user()->id,
            'format' => $data['format'],
            'parameter_json' => [
                'role' => $data['role'] ?? null,
                'cari' => $data['cari'] ?? '',
                'status' => $data['status'] ?? '',
            ],
            'status' => UserExport::STATUS_MENUNGGU,
            'created_at' => now(),
        ])->save();

        // Barisnya sudah committed saat job dilempar - `save()` di atas
        // berjalan di luar transaksi, jadi tidak ada balapan antara "job
        // berjalan" dan "barisnya belum ada". Karena itu `->afterCommit()`
        // TIDAK diperlukan, dan memasangnya justru merugikan: `phpunit.xml`
        // memakai `QUEUE_CONNECTION=sync`, dan `RefreshDatabase` membungkus
        // setiap test dalam transaksi yang tidak pernah commit - sehingga
        // jobnya tidak akan pernah berjalan di test dan `assertPushed()`
        // gagal karena testnya sendiri, bukan karena ada bug.
        //
        // Siapa pun yang kelak membungkus blok di atas dengan
        // `DB::transaction(...)` WAJIB menambahkan `->afterCommit()` di sini.
        BuatEksporPengguna::dispatch($ekspor->id);

        return back()->with('sukses', 'Berkas disiapkan di latar belakang. Unduh dari daftar Ekspor terakhir begitu statusnya siap.');
    }

    /**
     * Unduh satu berkas ekspor.
     *
     * Empat pemeriksaan, dan tiga di antaranya menjawab 404 dengan sengaja:
     * ekspor belum selesai, `path` kosong (ekspor gagal sebelum menulis), dan
     * berkasnya hilang dari disk (sudah dipangkas `pengguna:pangkas-ekspor`).
     * Ketiganya adalah "berkasnya tidak ada" - dan bagi Admin yang menekan
     * tautannya, itu memang benar.
     */
    public function unduh(Request $request, UserExport $ekspor): StreamedResponse
    {
        Gate::authorize('ekspor', User::class);

        abort_unless($ekspor->selesai(), 404);

        $disk = Storage::disk('local');

        abort_unless($ekspor->path !== null && $disk->exists($ekspor->path), 404);

        // Nama unduhan adalah nama yang dibaca Admin, bukan nama acak di
        // disk: nomor acaknya dibuang dari depan supaya berkas di folder
        // unduhan bernama jelas, sementara berkas di server tetap tidak bisa
        // ditebak.
        $nama = preg_replace('/^[a-zA-Z0-9]{20}-/', '', (string) $ekspor->nama_berkas) ?: $ekspor->nama_berkas;

        return $disk->download($ekspor->path, $nama);
    }
}

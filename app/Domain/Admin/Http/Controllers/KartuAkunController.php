<?php

namespace App\Domain\Admin\Http\Controllers;

use App\Domain\Admin\Services\KartuAkunGuru;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Kartu akun guru - satu PDF, atau satu ZIP berisi banyak PDF.
 *
 * =====================================================================
 * Satu alamat untuk dua bentuk, dan yang menentukan adalah JUMLAHNYA
 * =====================================================================
 * Satu guru berarti satu lembar yang bisa langsung dicetak; tiga puluh guru
 * berarti tiga puluh berkas yang tidak bisa disatukan menjadi satu PDF -
 * Admin yang membagikannya lewat WhatsApp butuh satu berkas per guru. Yang
 * membedakan keduanya bukan tombol yang berbeda, melainkan berapa id yang
 * dikirim, sehingga tidak ada dua jalur yang harus tetap sepakat.
 *
 * =====================================================================
 * Hanya guru, bukan seluruh pengguna
 * =====================================================================
 * Kartu ini mencetak USERNAME dan, untuk akun yang belum mengganti sandinya,
 * SANDI. Siswa sudah memegang kartu loginnya dari sekolah dan kartunya
 * dicetak per kelas; pengawas memegang kartu panitia dengan kode pintu.
 * Karena itu role disaring di sini, bukan di layar: id yang diutak-atik dari
 * URL tidak boleh menghasilkan kartu akun siswa.
 *
 * =====================================================================
 * Mencetaknya dicatat
 * =====================================================================
 * Berkas yang keluar dari sini memuat kredensial. "Dicetak lalu dibagikan"
 * dan "diunduh diam-diam" terlihat sama dari sisi basis data, dan hanya yang
 * pertama yang diinginkan - pertanyaan "kartu ini dari mana" harus punya
 * jawaban. Pola yang sama dipakai kartu panitia.
 */
class KartuAkunController
{
    /** Batas satu unduhan. Lebih dari ini berarti Admin menyaring dulu. */
    public const MAKSIMAL = 500;

    public function unduh(
        Request $request,
        KartuAkunGuru $kartu,
        ActivityLogger $logger,
    ): HttpResponse|BinaryFileResponse|RedirectResponse {
        Gate::authorize('ekspor', User::class);

        $data = $request->validate([
            // Dua cara memilih, dan keduanya sah: baris yang dicentang di
            // layar, atau seluruh guru yang sedang tersaring. Yang kedua tidak
            // dikirim sebagai ratusan id - Admin yang menyaring "cari: budi"
            // lalu menekan "Semua pada filter ini" tidak sedang memilih
            // daftar id, ia sedang memilih DAFTAR YANG IA LIHAT.
            'guru' => ['required_without:semua', 'array', 'max:'.self::MAKSIMAL],
            'guru.*' => ['integer'],
            'semua' => ['nullable', 'boolean'],
            'cari' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:0,1'],
        ]);

        $semua = (bool) ($data['semua'] ?? false);

        if (! $semua && empty($data['guru'] ?? [])) {
            return back()->with('galat', 'Pilih dulu guru yang kartunya dicetak.');
        }

        // Urutannya SAMA dengan tabel di layar - nama. Kartu yang keluar
        // dengan urutan berbeda dari daftar yang sedang dibaca Admin membuat
        // ia membandingkan dua daftar yang seharusnya satu.
        $query = User::query()
            ->where('role', Role::Teacher)
            ->with('teacherProfile:id,user_id,nip');

        if ($semua) {
            // Aturan pencarian yang sama dengan laman Pengguna - kata kunci
            // di tiga kolom, status persis. Disalin dari `TabelQuery::saring`
            // akan berbeda suatu hari, dan yang berbeda adalah daftar yang
            // dicetak Admin.
            $cari = trim((string) ($data['cari'] ?? ''));

            if ($cari !== '') {
                $query->where(function ($q) use ($cari) {
                    foreach (['name', 'username', 'email'] as $kolom) {
                        $q->orWhere($kolom, 'like', '%'.$cari.'%');
                    }
                });
            }

            if (($data['status'] ?? '') !== '') {
                $query->where('is_active', $data['status']);
            }
        } else {
            $query->whereIn('id', $data['guru']);
        }

        $guru = $query->orderBy('name')->limit(self::MAKSIMAL + 1)->get();

        if ($guru->isEmpty()) {
            return back()->with('galat', 'Tidak ada guru pada pilihan itu.');
        }

        // Batasnya diperiksa sebagai penolakan, bukan potongan diam-diam:
        // ZIP yang berisi separuh kartu dari yang dijanjikan adalah arsip
        // yang Admin bagikan dengan asumsi ia lengkap.
        if ($guru->count() > self::MAKSIMAL) {
            return back()->with(
                'galat',
                'Pilihan itu memuat lebih dari '.self::MAKSIMAL.' guru. Saring dulu, lalu cetak lagi.',
            );
        }

        $kartuGuru = $kartu->susun($guru);
        $sekolah = $kartu->kop(School::query()->find($request->user()->school_id));

        $logger->log('Mencetak kartu akun guru', null, ['jumlah' => count($kartuGuru)]);

        if (count($kartuGuru) === 1) {
            $satu = $kartuGuru[0];

            return response($kartu->pdf($satu, $sekolah), 200, [
                'Content-Type' => 'application/pdf',
                // `inline`, bukan `attachment`: satu kartu hampir selalu
                // ingin dilihat dulu sebelum dicetak - dan pratinjau PDF
                // adalah satu klik lebih dekat daripada folder unduhan.
                'Content-Disposition' => 'inline; filename="'.$kartu->namaBerkas($satu).'"',
            ]);
        }

        $jalur = $kartu->zip($kartuGuru, $sekolah);

        return response()
            ->download($jalur, 'kartu-akun-guru-'.now()->format('Ymd').'.zip', [
                'Content-Type' => 'application/zip',
            ])
            // Arsipnya berkas sementara. Tanpa ini setiap unduhan menumpuk
            // satu ZIP berisi ratusan PDF di folder temp server.
            ->deleteFileAfterSend(true);
    }
}

<?php

namespace App\Providers;

use App\Support\PembatasLaju;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->daftarkanPenemuanFactory();
        $this->daftarkanPembatasLaju();
    }

    /**
     * Model hidup di App\Domain\*\Models, jadi penemuan factory bawaan
     * Laravel akan mencari Database\Factories\Domain\Identity\Models\UserFactory
     * dan tidak menemukan apa pun.
     *
     * Dipetakan ke pola datar Database\Factories\UserFactory supaya factory
     * tetap di satu folder seperti biasanya - model yang berpindah ke dalam
     * domain tidak perlu menyeret strukturnya ke dalam factories.
     */
    private function daftarkanPenemuanFactory(): void
    {
        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Database\\Factories\\'.class_basename($modelName).'Factory',
        );
    }

    /**
     * Pembatas laju tiga titik masuk berkredensial - PRD §19.2 dan §19.5.
     *
     * ---------------------------------------------------------------------
     * Mengapa responsnya FLASH, bukan halaman 429
     * ---------------------------------------------------------------------
     * Kode pengawas diketik sambil berdiri di depan pintu kelas dengan 32
     * siswa menunggu di belakang, token ujian diketik di ponsel anak yang
     * baru saja menekan "Mulai", dan sandi lama diketik ulang oleh siswa yang
     * sedang mengganti sandinya. Halaman "429 Too Many Requests" telanjang
     * bukan jawaban yang bisa dipakai ketiganya: yang pertama tidak tahu
     * apakah ia salah kartu, yang kedua akan menyangka ujiannya rusak, dan
     * yang ketiga menyangka akunnya rusak karena sandinya benar.
     *
     * Karena itu `->response()` mengembalikan redirect kembali ke laman yang
     * sama dengan flash `galat` - bentuk pesan yang SUDAH dipakai ketiga
     * laman itu untuk kegagalan kredensial biasa (`PintuRuangController::buka`,
     * `GerbangSiswaController::mulai`). Hasilnya: pengawas yang kehabisan
     * jatah melihat pesan yang bentuknya sama dengan kode yang salah ketik,
     * siswa melihatnya di atas tombol yang sama, dan siswa yang mengganti
     * sandi melihatnya di atas form yang sama.
     *
     * ---------------------------------------------------------------------
     * Alamat tujuannya disebut eksplisit, bukan back()
     * ---------------------------------------------------------------------
     * `back()` bergantung pada alamat sebelumnya di sesi, yang tidak selalu
     * ada - permintaan pertama dari klien tanpa riwayat navigasi akan
     * mendarat di beranda, dan pengawas yang terkunci di beranda adalah
     * pengawas yang terkunci di luar pintunya. Ketiga rute di bawah punya
     * nama, sehingga tujuan redirect-nya pasti.
     */
    private function daftarkanPembatasLaju(): void
    {
        // ----------------------------------------------------------------
        // Pintu ruang - POST /p/{slug}
        // ----------------------------------------------------------------
        // Dua limit berdampingan. Lihat `PembatasLaju` untuk alasan lengkap
        // masing-masing angkanya.
        RateLimiter::for(PembatasLaju::PINTU, fn (Request $request) => [
            Limit::perMinute(PembatasLaju::PINTU_PER_PASANGAN)
                ->by(PembatasLaju::kunciPintuPasangan(
                    (string) $request->route('slug'),
                    (string) $request->ip(),
                ))
                ->response(fn () => redirect()
                    ->route('pintu.ruang', ['slug' => $request->route('slug')])
                    ->with('galat', PembatasLaju::PESAN_PINTU)),

            Limit::perMinute(PembatasLaju::PINTU_PER_TAUTAN)
                ->by(PembatasLaju::kunciPintuTautan((string) $request->route('slug')))
                ->response(fn () => redirect()
                    ->route('pintu.ruang', ['slug' => $request->route('slug')])
                    ->with('galat', PembatasLaju::PESAN_PINTU_TAUTAN)),
        ]);

        // ----------------------------------------------------------------
        // Gerbang ujian - POST /siswa/ujian/{peserta}/gerbang
        // ----------------------------------------------------------------
        // Per SISWA, bukan per penempatan: satu siswa yang mencoba ulang
        // tokennya sepuluh kali adalah satu orang yang salah baca papan
        // tulis, dan yang harus menunggu adalah ia - bukan siswa di kursi
        // sebelahnya yang mengetik token yang sama.
        //
        // Plafon per-IP sengaja longgar (600/menit): 300 siswa memakai token
        // yang sama dari satu NAT sekolah, dan pembatas ketat di sini akan
        // memblokir start serentak pada menit pertama ujian.
        RateLimiter::for(PembatasLaju::GERBANG, fn (Request $request) => [
            Limit::perMinute(PembatasLaju::GERBANG_PER_SISWA)
                ->by(PembatasLaju::kunciGerbangSiswa((int) $request->user()?->id))
                ->response(fn () => redirect()
                    ->route('siswa.gerbang', ['peserta' => $request->route('peserta')])
                    ->with('galat', PembatasLaju::PESAN_GERBANG)),

            Limit::perMinute(PembatasLaju::GERBANG_PER_IP)
                ->by(PembatasLaju::kunciGerbangIp((string) $request->ip()))
                ->response(fn () => redirect()
                    ->route('siswa.gerbang', ['peserta' => $request->route('peserta')])
                    ->with('galat', PembatasLaju::PESAN_GERBANG_IP)),
        ]);

        // ----------------------------------------------------------------
        // Generate AI - POST /guru/bank-soal/ai
        // ----------------------------------------------------------------
        // Satu limit, per akun. Ia pembatas UANG: setiap permintaan ditagih
        // ke akun penyedia milik sekolah.
        //
        // Jawabannya JSON, bukan redirect. Endpoint ini dipanggil `fetch`
        // dari modal builder, dan redirect akan diterima sebagai HTML yang
        // lalu gagal diurai - Guru melihat "kesalahan" tanpa sebab, padahal
        // yang terjadi hanyalah ia menekan tombol terlalu cepat.
        RateLimiter::for(PembatasLaju::AI, fn (Request $request) => [
            Limit::perMinute(PembatasLaju::AI_PER_GURU)
                ->by(PembatasLaju::kunciAi((int) $request->user()?->id))
                ->response(fn () => response()
                    ->json(['pesan' => PembatasLaju::PESAN_AI], 429)),
        ]);

        // ----------------------------------------------------------------
        // Ganti sandi - PUT /siswa/akun/sandi
        // ----------------------------------------------------------------
        // Satu limit, per AKUN, tanpa pasangan per-IP. Pelakunya sudah
        // tersahihkan, dan justru itu skenarionya: sesi yang dicuri dipakai
        // mempelajari sandi korban. Lihat `PembatasLaju::SANDI_PER_SISWA`.
        RateLimiter::for(PembatasLaju::SANDI, fn (Request $request) => [
            Limit::perMinute(PembatasLaju::SANDI_PER_SISWA)
                ->by(PembatasLaju::kunciSandi((int) $request->user()?->id))
                ->response(fn () => redirect()
                    ->route('siswa.akun')
                    ->with('galat', PembatasLaju::PESAN_SANDI)),
        ]);

        // ----------------------------------------------------------------
        // Pindai presensi - POST /hadir/{kode}
        // ----------------------------------------------------------------
        // Hanya POST-nya, dan alasannya DUA:
        //
        //   1. GET laman itu dimuat ulang berkali-kali oleh anak yang sedang
        //      menunggu pengawas - plate B5 menaruh tombol "Muat ulang" di
        //      sana. Membatasi GET berarti menghukum perilaku yang diminta
        //      laman itu sendiri.
        //   2. Kalau GET ikut dibatasi, `->response()` di bawah akan
        //      mengalihkan ke `/hadir/{kode}` - yang juga dibatasi, dan
        //      habisnya jatah berubah menjadi lingkaran alihan tanpa akhir.
        //      Karena itu tujuannya laman jadwal, yang tidak dijaga limiter
        //      mana pun.
        //
        // GET tetap aman tanpa pembatas: kodenya enam belas karakter dari
        // alfabet tiga puluh satu (31^16), jauh di luar jangkauan tebak -
        // lihat `KodePindai::PANJANG_KODE`.
        RateLimiter::for(PembatasLaju::PINDAI, fn (Request $request) => [
            Limit::perMinute(PembatasLaju::PINDAI_PER_IP)
                ->by(PembatasLaju::kunciPindaiIp((string) $request->ip()))
                ->response(fn () => redirect()
                    ->route('siswa.jadwal')
                    ->with('galat', PembatasLaju::PESAN_PINDAI)),
        ]);
    }
}

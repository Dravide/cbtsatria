<?php

/*
| Helper bersama untuk test.
|
| Berkas ini dulunya tinggal DI DALAM berkas test-nya sendiri - `masukSebagai`
| di ExamTest, `siapkanPresensi` di AttendanceTest, dan seterusnya. Fungsi PHP
| bersifat global, jadi selama suite dijalankan SELURUHNYA, helper itu saling
| terlihat dan semua hijau.
|
| Yang rusak adalah menjalankan satu berkas sendirian. `php artisan test
| tests/Feature/PenjadwalTest.php` menjawab "Call to undefined function
| siapkanUjianSiswa()" - bukan karena ada yang salah dengan PenjadwalTest,
| tetapi karena helper yang ia pakai kebetulan didefinisikan di berkas lain
| yang tidak ikut dimuat. Tujuh berkas terkena, dan itu menjebak justru saat
| orang paling butuh jawabannya: suite penuh hijau, sementara menunjuk satu
| berkas yang sedang diperiksa hasilnya galat yang menyesatkan.
|
| Karena itu helper-nya pindah ke sini. `tests/Pest.php` memuat seluruh isi
| direktori ini lebih dulu, jadi setiap berkas test melihatnya - dijalankan
| sendiri maupun bersama-sama.
*/

use App\Domain\Event\Services\EventRegistrar;
use App\Domain\Exam\Services\SesiService;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Services\SchoolResolver;
use Tests\TestCase;

/**
 * Menyiapkan konteks tenant lalu masuk sebagai pengguna itu.
 *
 * ---------------------------------------------------------------------
 * Mengapa `forget()` ada di sini, dan mengapa itu bukan kerapian
 * ---------------------------------------------------------------------
 * SchoolResolver di-cache selama satu siklus hidup - `$this->app->scoped()`
 * di TenancyServiceProvider. Di produksi itu benar: satu permintaan HTTP,
 * satu konteks. Di test, keadaan itu berbahaya justru karena pembangunannya
 * berjalan TANPA pengguna yang masuk.
 *
 * Helper di berkas ini memanggil service (EventRegistrar, SesiService, ...)
 * sebelum ada pengguna, dan service itu membaca SchoolScope - sehingga
 * resolver menyimpan `null` sebagai jawaban finalnya. Permintaan HTTP
 * sesudahnya memakai instance yang sama, scope tenant menjadi no-op, dan
 * binding implisit menemukan baris sekolah LAIN. Yang gagal adalah test
 * isolasi tenant, dengan pesan "expected 404, got 200" - dan pesan itu
 * menunjuk ke controller, bukan ke sebabnya.
 *
 * `forget()` dihapus hanya di sini, tidak di aplikasinya: di produksi urutan
 * itu memang tidak bisa terjadi, karena middleware tenant berjalan sebelum
 * controller mana pun.
 */
function masukSebagai(TestCase $test, User $user): TestCase
{
    app(SchoolResolver::class)->forget();

    // Mengembalikan test case-nya supaya pemanggilnya bisa langsung
    // merantai ->get()/->post() - actingAs() sendiri mengembalikan $this,
    // dan helper yang menelan nilai baliknya memaksa setiap test menulis
    // tiga baris untuk satu permintaan.
    return $test->actingAs($user);
}

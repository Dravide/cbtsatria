<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Helper bersama
|--------------------------------------------------------------------------
|
| Seluruh berkas di `tests/Support/` dimuat lebih dulu, sekali, sebelum berkas
| test mana pun. Pest TIDAK melakukannya sendiri: tanpa blok ini, isi direktori
| itu hanya ter-autoload sebagai KELAS, bukan sebagai fungsi global.
|
| Alasannya ada di masing-masing berkasnya - ringkasnya: fungsi PHP bersifat
| global, sehingga helper yang ditulis di dalam satu berkas test terlihat oleh
| berkas lain selama suite dijalankan SELURUHNYA, lalu hilang begitu berkasnya
| dijalankan sendirian. Tujuh berkas bergantung pada kebetulan itu.
|
| Urutan `sort()` disengaja: hasil `glob()` bergantung berkas sistem, dan
| helper di sini boleh saling memanggil - jadi urutannya harus tetap sama di
| mesin mana pun.
*/

$berkasHelper = glob(__DIR__.'/Support/*.php');
sort($berkasHelper);

foreach ($berkasHelper as $berkas) {
    require_once $berkas;
}

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Ambil seluruh pesan galat dari session sebagai array biasa.
 *
 * Laravel menyimpan 'errors' di session sebagai array biasa berbentuk
 * ['default' => ['format' => ..., 'messages' => ['username' => [...]]]],
 * bukan sebagai MessageBag. Helper ini menerima kedua bentuk supaya test
 * tidak rapuh terhadap detail internal session.
 *
 * @return array<string, array<int, string>>
 */
function pesanGalat(TestResponse $respons): array
{
    $errors = $respons->getSession()->get('errors');

    if (is_array($errors)) {
        return $errors['default']['messages'] ?? [];
    }

    return $errors?->getBag('default')->getMessages() ?? [];
}

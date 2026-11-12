<?php

/**
 * Identitas aplikasi di kepala dokumen - favicon, manifest, dan meta.
 *
 * Yang diuji bukan "tag ini ada", melainkan dua janji yang bisa rusak tanpa
 * ada yang menyadarinya:
 *
 *   1. Setiap halaman menyatakan `noindex`. Aplikasi ini memuat nilai,
 *      presensi, dan jawaban ujian siswa; halaman yang lolos ke indeks mesin
 *      pencari membocorkan data anak ke mesin yang menyimpannya bertahun-tahun.
 *      `public/robots.txt` saja tidak cukup - ia hanya dibaca perayap yang
 *      menghormatinya, dan berkasnya bisa hilang saat deploy tanpa satu test
 *      pun gagal.
 *
 *   2. Berkas ikon yang DITUNJUK benar-benar ada. Tag yang menunjuk berkas
 *      tidak ada menghasilkan ikon kosong di tab dan permintaan 404 di setiap
 *      pembukaan halaman - kegagalan yang tidak terlihat di lokal karena
 *      peramban menyimpan kegagalannya.
 */
it('menyatakan noindex di setiap halaman', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('<meta name="robots" content="noindex, nofollow, noarchive">', false);
});

it('menunjuk berkas ikon yang benar-benar ada', function () {
    $halaman = $this->get('/login')->assertOk()->getContent();

    preg_match_all('#(?:href|content)="(/(?:favicon[^"]*|apple-touch-icon\.png|icon-\d+\.png|site\.webmanifest))"#', $halaman, $cocok);

    $dirujuk = array_values(array_unique($cocok[1]));

    expect($dirujuk)->not->toBeEmpty();

    foreach ($dirujuk as $jalur) {
        expect(public_path(ltrim($jalur, '/')))->toBeFile();
    }
});

it('menyajikan manifest yang sah dan menyebut nama aplikasi', function () {
    $isi = json_decode(file_get_contents(public_path('site.webmanifest')), true);

    expect($isi)->toBeArray()
        ->and($isi['name'])->toBe(config('app.name'))
        ->and($isi['short_name'])->not->toBeEmpty()
        ->and($isi['start_url'])->toBe('/')
        ->and($isi['icons'])->not->toBeEmpty();

    foreach ($isi['icons'] as $ikon) {
        expect(public_path(ltrim($ikon['src'], '/')))->toBeFile();
    }
});

it('melarang seluruh perayap lewat robots.txt', function () {
    $isi = file_get_contents(public_path('robots.txt'));

    // `Disallow: /` dengan garis miringnya, bukan `Disallow:` kosong -
    // yang kedua berarti SELURUH situs boleh dirayapi.
    expect($isi)->toMatch('/^User-agent: \*$/m')
        ->and($isi)->toMatch('/^Disallow: \/$/m');
});

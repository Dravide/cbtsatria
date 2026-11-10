<?php

use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Services\NomorKursi;
use App\Domain\Identity\Models\StudentProfile;
use App\Domain\Identity\Models\User;
use Tests\TestCase;

/*
 * Berkas ini BUTUH aplikasi ter-boot, dan `uses()` di bawah adalah satu-satunya
 * alasan baris itu ada.
 *
 * Bukan karena ia menyentuh basis data - ia tidak menyentuh sama sekali.
 * Alasannya adalah `forceFill` pada model Eloquent melewati `$fillable` lewat
 * facade, dan tanpa aplikasi ter-boot seluruh berkas ini gagal dengan
 * "A facade root has not been set" - galat yang menunjuk ke dalam pustaka
 * Laravel dan tidak menyebut NomorKursi sama sekali.
 *
 * RefreshDatabase SENGAJA tidak dipakai: seluruh yang diuji di sini adalah
 * fungsi murni atas model yang dibangun di memori.
 */
uses(TestCase::class);

/**
 * Satu penempatan di memori, lengkap dengan relasi yang dibaca NomorKursi.
 *
 * Relasinya DISETEL, bukan dibiarkan kosong: `$p->student` pada relasi yang
 * belum dimuat akan menjalankan kueri, dan berkas ini tidak punya basis data
 * untuk menjawabnya. Yang diuji di sini adalah urutannya, bukan pengambilan
 * namanya.
 *
 * `pesertaEvent` dan `studentProfile` disetel ke null dengan alasan yang sama -
 * `denah()` membacanya untuk kolom kelas dan NIS.
 */
function anakKursi(int $id, string $nama, ?string $nis = null): ExamParticipant
{
    $profil = (new StudentProfile)->forceFill(['nis' => $nis]);

    $siswa = (new User)->forceFill(['id' => $id, 'name' => $nama]);
    $siswa->setRelation('studentProfile', $profil);

    $anak = (new ExamParticipant)->forceFill(['id' => $id]);
    $anak->setRelation('student', $siswa);
    $anak->setRelation('pesertaEvent', null);

    return $anak;
}

/* ------------------------------------------------------- Urutan yang mengikat */

it('menomori menurut abjad nama, bukan menurut urutan id', function () {
    // Inilah perbedaan yang menutup celah 2026-11-09: `SiswaDasar` menomori
    // dengan urutan id, sementara pengawas membacakan urutan abjad. Anak
    // bernama Ani dengan id terbesar mendapat kursi 1 - dan angka itu yang
    // dibaca pengawas, dicetak di tablo, dan digambar di denah.
    $kursi = (new NomorKursi)->peta(collect([
        anakKursi(1, 'Budi'),
        anakKursi(2, 'Cahya'),
        anakKursi(3, 'Ani'),
    ]));

    expect($kursi)->toBe([3 => 1, 1 => 2, 2 => 3]);
});

it('mengabaikan besar-kecil huruf dan spasi tepi', function () {
    // Nama datang dari impor pengguna, dan " ani " dengan "Ani" adalah anak
    // yang sama. Dua kunci berbeda untuk satu orang berarti kursinya berubah
    // setiap kali halamannya dimuat ulang.
    $kursi = (new NomorKursi)->peta(collect([
        anakKursi(1, '  ZULFA  '),
        anakKursi(2, 'ani'),
        anakKursi(3, 'Ani'),
    ]));

    expect($kursi)->toBe([2 => 1, 3 => 2, 1 => 3]);
});

it('memutus nama kembar dengan id, bukan dengan urutan kueri', function () {
    // Dua anak bernama persis sama harus mendapat nomor yang STABIL. Tanpa
    // pemutus seri, nomornya bergantung urutan baris dari basis data - dan
    // urutan itu boleh berubah kapan saja tanpa satu pun galat.
    $kursi = (new NomorKursi)->peta(collect([
        anakKursi(9, 'Siti'),
        anakKursi(4, 'Siti'),
    ]));

    expect($kursi)->toBe([4 => 1, 9 => 2]);
});

it('membandingkan id sebagai angka, bukan sebagai teks', function () {
    // `str_pad` di `kunci()` ada persis untuk ini. Tanpa pad, "10" < "9"
    // sebagai teks - dan anak ke-sepuluh berdiri di kursi kesembilan.
    $kursi = (new NomorKursi)->peta(collect([
        anakKursi(10, 'Sama'),
        anakKursi(9, 'Sama'),
        anakKursi(100, 'Sama'),
    ]));

    expect($kursi)->toBe([9 => 1, 10 => 2, 100 => 3]);
});

it('tidak membiarkan nama pendek menyusup ke nama yang mengawalinya', function () {
    // Tanpa pemisah `|`, "ania" akan berbunyi sebagai "ani" + "a" dan
    // menyusup di antara "ani" dan "ani|...". Pemisahnya membuat namanya
    // dibandingkan secara utuh sebelum id ikut dibaca.
    expect(NomorKursi::kunci('ani', 1))->toBeLessThan(NomorKursi::kunci('ania', 1));
});

it('menomori peserta yang namanya kosong tanpa galat', function () {
    // Nama null datang dari baris yang gagal impor. Pengawas tetap perlu
    // kursinya, dan yang menolak baris itu adalah impor - bukan penomoran.
    $kursi = (new NomorKursi)->peta(collect([
        anakKursi(2, 'Budi'),
        anakKursi(1, ''),
    ]));

    expect($kursi)->toBe([1 => 1, 2 => 2]);
});

/* ------------------------------------------------------------------- Meja */

it('menempatkan dua kursi di setiap meja', function () {
    expect(NomorKursi::meja(1))->toBe(1)
        ->and(NomorKursi::meja(2))->toBe(1)
        ->and(NomorKursi::meja(3))->toBe(2)
        ->and(NomorKursi::meja(4))->toBe(2)
        ->and(NomorKursi::meja(39))->toBe(20)
        ->and(NomorKursi::meja(40))->toBe(20);
});

/* ------------------------------------------------------------------ Denah */

it('membentangkan kursi kosong sampai kapasitas ruang', function () {
    // Kursi yang belum terisi tetap digambar: ruangan yang tampak penuh
    // membuat pengawas menyusuri meja yang sebenarnya kosong.
    $sel = (new NomorKursi)->denah(collect([
        anakKursi(1, 'Ani'),
        anakKursi(2, 'Budi'),
    ]), 6);

    expect($sel)->toHaveCount(6)
        ->and($sel[0]['peserta']['nama'])->toBe('Ani')
        ->and($sel[1]['peserta']['nama'])->toBe('Budi')
        ->and($sel[2]['peserta'])->toBeNull()
        ->and($sel[5]['kursi'])->toBe(6);
});

it('membentangkan bentuk Z: empat kolom meja, lalu turun satu baris', function () {
    // Kursi 1..10 berurutan sepanjang satu jalur masuk, sehingga pengawas
    // yang menyusur dari pintu tidak menyeberang ruangan. Yang MENENTUKAN
    // letaknya server, karena nomor yang sama juga tercetak di tablo.
    //
    // Sepuluh kursi, bukan delapan: delapan kursi tepat memenuhi satu baris,
    // dan denah yang seluruhnya satu baris tidak membuktikan apa pun tentang
    // perpindahan barisnya.
    $sel = (new NomorKursi)->denah(collect(), 10);
    $perKursi = collect($sel)->keyBy('kursi');

    expect($perKursi[1]['meja'])->toBe(1)
        ->and($perKursi[1]['baris'])->toBe(1)
        ->and($perKursi[1]['kolom'])->toBe(1)
        // Kursi 7 dan 8 berbagi meja keempat - meja terakhir baris pertama.
        ->and($perKursi[8]['meja'])->toBe(4)
        ->and($perKursi[8]['baris'])->toBe(1)
        ->and($perKursi[8]['kolom'])->toBe(4)
        // Kursi 9 dan 10 satu-satunya yang turun ke baris kedua.
        ->and($perKursi[9]['meja'])->toBe(5)
        ->and($perKursi[9]['baris'])->toBe(2)
        ->and($perKursi[9]['kolom'])->toBe(1)
        ->and($perKursi[10]['baris'])->toBe(2)
        ->and($perKursi[10]['kolom'])->toBe(1);
});

it('tetap menggambar kursi yang terisi di luar kapasitas', function () {
    // Keadaannya seharusnya tidak terjadi - `sebarKeRuang()` menolaknya -
    // tetapi denah yang menyembunyikan anak yang benar-benar duduk di sana
    // adalah denah yang berbohong tentang ruangannya.
    $sel = (new NomorKursi)->denah(collect([
        anakKursi(1, 'Ani'),
        anakKursi(2, 'Budi'),
        anakKursi(3, 'Cahya'),
    ]), 2);

    expect($sel)->toHaveCount(3)
        ->and($sel[2]['peserta']['nama'])->toBe('Cahya');
});

it('mengirim NIS dan kelas di dalam kotak kursi', function () {
    $sel = (new NomorKursi)->denah(collect([
        anakKursi(1, 'Ani', '24001'),
    ]), 1);

    expect($sel[0]['peserta'])->toBe([
        'id' => 1,
        'nama' => 'Ani',
        'nis' => '24001',
        'kelas' => null,
    ]);
});

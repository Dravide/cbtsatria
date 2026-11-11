<?php

use App\Domain\Grading\Http\Controllers\Guru\AnalisisController;
use App\Domain\Grading\Http\Controllers\Guru\KoreksiController;
use App\Domain\Grading\Http\Controllers\Guru\NilaiController;
use App\Domain\Question\Http\Controllers\DashboardGuruController;
use App\Domain\Question\Http\Controllers\MediaSoalController;
use App\Domain\Question\Http\Controllers\MediaUnggahController;
use App\Domain\Question\Http\Controllers\PratinjauController;
use App\Domain\Question\Http\Controllers\QuestionController;
use App\Domain\Question\Http\Controllers\QuestionImportController;
use App\Domain\Question\Http\Controllers\QuestionPackageController;
use App\Domain\Question\Http\Controllers\SoalAiController;
use Illuminate\Support\Facades\Route;

/*
|==========================================================================
| Konsol Guru - middleware `guru`, BUKAN `auth` saja
|==========================================================================
| `auth` saja tidak cukup, dan itu yang paling mudah terlewat: Siswa yang
| mengetik /guru/bank-soal di peramban punya akun yang SAH, dan tanpa gerbang
| ini ia akan menerima daftar soal ujiannya sendiri - lengkap dengan kuncinya
| di laman builder.
|
| AksesGuru meloloskan Admin dan Guru, menolak Pengawas dan Siswa dengan 403.
| 403 dan bukan 404: keberadaan /guru bukan rahasia - setiap Guru tahu
| menunya ada - sehingga yang ditolak adalah AKSESNYA, bukan keberadaannya.
|
| Bank soal berdiri di luar event (wireframe plate 02), sehingga tidak satu
| pun rute di bawah ini memuat id event. Yang membatasi cakupan Guru adalah
| TeachingAssignment di dalam QuestionPolicy, bukan URL.
*/

Route::middleware(['auth', 'guru'])->prefix('guru')->name('guru.')->group(function () {
    // Plate 01
    Route::get('/', DashboardGuruController::class)->name('dashboard');

    // Plate 02 - daftar. POST-nya satu rute dengan GET-nya: yang membedakan
    // keduanya method, dan nama rute terpisah untuk tujuan yang sama berarti
    // dua nama yang harus diingat pemanggilnya.
    Route::get('/bank-soal', [QuestionController::class, 'index'])->name('bank-soal');
    Route::post('/bank-soal', [QuestionController::class, 'store']);

    // Plate 03 - builder. `/baru` didaftarkan SEBELUM `/{soal}/ubah`, dan
    // urutannya menentukan: tanpa itu "baru" dicoba sebagai {soal} lebih dulu.
    Route::get('/bank-soal/baru', [QuestionController::class, 'create'])->name('bank-soal.baru');

    /*
    |----------------------------------------------------------------------
    | Generate AI - laman builder
    |----------------------------------------------------------------------
    | Didaftarkan SEBELUM seluruh segmen dinamis `/bank-soal/{soal}/...`,
    | dengan alasan yang sama seperti `/bank-soal/baru` di atas: "ai" adalah
    | segmen yang sah-sah saja dicocokkan sebagai id soal.
    |
    | DUA rute, dan pembagiannya menentukan:
    |   /bank-soal/ai        menyusun soal, TIDAK menulis apa pun (JSON)
    |   /bank-soal/ai/simpan menulis yang dicentang (redirect Inertia)
    |
    | Rute simpan didaftarkan lebih dulu supaya bentuk yang lebih spesifik
    | tidak pernah dicoba di belakang yang umum - meski keduanya statis dan
    | Laravel memilih yang cocok, urutannya dinyatakan di sini supaya tidak
    | perlu ditebak pembaca berikutnya.
    |
    | `throttle:ai-soal` bukan hiasan - setiap permintaan `ai` ditagih ke
    | akun penyedia milik sekolah. Lihat PembatasLaju::AI_PER_GURU. Rute
    | `simpan` TIDAK dibatasi di sana: ia tidak menyentuh penyedia sama
    | sekali, dan jatah yang terpakai untuk menekan Simpan adalah jatah yang
    | hilang dari permintaan berikutnya.
    */
    Route::post('/bank-soal/ai/simpan', [SoalAiController::class, 'simpan'])->name('bank-soal.ai.simpan');

    Route::post('/bank-soal/ai', [SoalAiController::class, 'generate'])
        ->middleware('throttle:ai-soal')
        ->name('bank-soal.ai');

    /*
    |----------------------------------------------------------------------
    | Unggah media soal - gambar/audio/video pada soal DAN pilihan jawaban
    |----------------------------------------------------------------------
    | Didaftarkan SEBELUM seluruh segmen dinamis `/bank-soal/{soal}/...`,
    | dengan alasan yang sama seperti `/bank-soal/baru` dan `/bank-soal/ai`:
    | "media" adalah segmen yang sah-sah saja dicocokkan sebagai id soal.
    |
    | Keduanya menjawab JSON, bukan redirect Inertia, karena dipanggil dari
    | dalam builder lewat `fetch` TANPA memuat ulang laman - lihat docblock
    | MediaUnggahController. Path-nya statis; soal tujuan disebut di body
    | (`soal_id`), dan boleh kosong untuk media draf soal baru.
    */
    Route::post('/bank-soal/media', [MediaUnggahController::class, 'unggah'])->name('bank-soal.media');
    Route::delete('/bank-soal/media/{media}', [MediaUnggahController::class, 'hapus'])->name('bank-soal.media.hapus');

    /*
    |----------------------------------------------------------------------
    | Plate 04 - impor bertahap
    |----------------------------------------------------------------------
    | Seluruh blok ini didaftarkan SEBELUM `/bank-soal/{soal}/...`, dengan
    | alasan yang sama seperti `/bank-soal/baru` di atas: rute dinamis
    | mencoba mencocokkan segmen apa pun, dan "impor" adalah segmen yang
    | sah-sah saja sebagai id soal bagi pencocoknya. Yang membuatnya tidak
    | sekadar teori: `/bank-soal/impor/template` akan dicoba sebagai
    | `{soal}/ubah` lebih dulu bila urutannya dibalik - dan Guru yang
    | menekan "Unduh template" menerima 404 yang tidak menjelaskan apa pun.
    |
    | Urutan DI DALAM blok ini juga menentukan: `/impor/template` harus
    | mendahului `/impor/{batch}/laporan`, supaya "template" tidak dicoba
    | sebagai id batch.
    */
    Route::get('/bank-soal/impor', [QuestionImportController::class, 'index'])->name('impor');
    Route::post('/bank-soal/impor/unggah', [QuestionImportController::class, 'unggah'])->name('impor.unggah');
    Route::get('/bank-soal/impor/template', [QuestionImportController::class, 'template'])->name('impor.template');
    Route::post('/bank-soal/impor/{batch}/konfirmasi', [QuestionImportController::class, 'konfirmasi'])->name('impor.konfirmasi');
    Route::get('/bank-soal/impor/{batch}/laporan', [QuestionImportController::class, 'laporan'])->name('impor.laporan');

    Route::get('/bank-soal/{soal}/ubah', [QuestionController::class, 'edit'])->name('bank-soal.ubah');
    Route::put('/bank-soal/{soal}', [QuestionController::class, 'update']);
    Route::post('/bank-soal/{soal}/duplikat', [QuestionController::class, 'duplikat'])->name('bank-soal.duplikat');
    Route::post('/bank-soal/{soal}/arsip', [QuestionController::class, 'arsip'])->name('bank-soal.arsip');

    /*
    |----------------------------------------------------------------------
    | Pratinjau - plate 09, dan satu rute berkas
    |----------------------------------------------------------------------
    | Ketiganya diletakkan SESUDAH seluruh rute statis dan dinamis lain, dan
    | itu bukan kerapian: `{soal}` mencocokkan segmen apa pun, sehingga
    | `/bank-soal/impor/pratinjau` akan dicoba sebagai id soal bila urutannya
    | dibalik - persis masalah yang sudah dijelaskan di blok impor di atas.
    |
    | `/{media}` sengaja diberi awalan `/berkas`, bukan `/media`: satu segmen
    | telanjang di bawah `/guru` akan bertabrakan dengan setiap halaman
    | tingkat atas yang ditambahkan Fase 8 nanti, dan benturan pertama baru
    | ketahuan setelah menu itu terpasang.
    |
    | Berkasnya tetap di disk privat dengan nama acak - rute ini satu-satunya
    | jalan keluarnya, dan ia memanggil Gate seperti rute lain.
    */
    Route::get('/bank-soal/{soal}/pratinjau', [PratinjauController::class, 'soal'])->name('bank-soal.pratinjau');
    Route::get('/berkas/{media}', [MediaSoalController::class, 'tampilkan'])->name('media');

    /*
    |----------------------------------------------------------------------
    | Plate 05 - paket soal
    |----------------------------------------------------------------------
    | `/paket` didaftarkan sebelum `/paket/{paket}`; dengan pola yang sama
    | seperti `/bank-soal/baru` di atas.
    |
    | Rute butir bersarang di bawah paketnya, dan itu bukan kerapian: id butir
    | unik lintas paket, sehingga `PUT /paket/butir/{butir}` akan menyunting
    | butir paket Guru lain lewat URL paket sendiri. Controller memeriksa
    | kepemilikannya lagi - sarang URL saja tidak cukup, karena keduanya
    | sah-sah saja sebagai baris.
    */
    Route::get('/paket', [QuestionPackageController::class, 'index'])->name('paket');
    Route::post('/paket', [QuestionPackageController::class, 'store']);
    Route::get('/paket/{paket}', [QuestionPackageController::class, 'show'])->name('paket.detail');
    Route::put('/paket/{paket}', [QuestionPackageController::class, 'update']);
    Route::post('/paket/{paket}/butir', [QuestionPackageController::class, 'tambahButir'])->name('paket.butir');
    Route::put('/paket/{paket}/urutan', [QuestionPackageController::class, 'susunUlang'])->name('paket.urutan');
    Route::put('/paket/{paket}/butir/{butir}', [QuestionPackageController::class, 'ubahButir'])->name('paket.butir.ubah');
    Route::delete('/paket/{paket}/butir/{butir}', [QuestionPackageController::class, 'buangButir'])->name('paket.butir.buang');
    Route::get('/paket/{paket}/pratinjau', [PratinjauController::class, 'paket'])->name('paket.pratinjau');

    /*
    |----------------------------------------------------------------------
    | Plate 06-08 - penilaian, Fase 8
    |----------------------------------------------------------------------
    | Tiga laman, tiga pertanyaan yang berbeda: plate 06 "uraian mana yang
    | belum dikoreksi", plate 07 "berapa nilai kelas ini", plate 08 "soal
    | mana yang bermasalah". Ketiganya membaca `exam_results` dan
    | `question_analyses` hasil hitungan `PenghitungHasil` /
    | `PenghitungAnalisis`, bukan menghitung ulang di setiap pembacaan -
    | alasan yang sama seperti plate 21 dan 28.
    |
    | `/koreksi/jawaban/...` didaftarkan SEBELUM `/koreksi/{ujian}`, dan
    | urutannya menentukan: tanpa itu "jawaban" dicoba sebagai id ujian lebih
    | dulu, dan tombol "Koreksi" di daftar antrean membuka 404.
    */
    Route::get('/koreksi', [KoreksiController::class, 'index'])->name('koreksi');
    Route::get('/koreksi/jawaban/{jawaban}', [KoreksiController::class, 'jawaban'])->name('koreksi.jawaban');
    Route::post('/koreksi/jawaban/{jawaban}', [KoreksiController::class, 'simpan'])->name('koreksi.simpan');
    Route::delete('/koreksi/jawaban/{jawaban}', [KoreksiController::class, 'batalkan'])->name('koreksi.batal');
    Route::get('/koreksi/{ujian}', [KoreksiController::class, 'ujian'])->name('koreksi.ujian');

    Route::get('/nilai', [NilaiController::class, 'index'])->name('nilai');
    Route::get('/nilai/{ujian}', [NilaiController::class, 'ujian'])->name('nilai.ujian');

    Route::get('/analisis', [AnalisisController::class, 'index'])->name('analisis');
    Route::post('/analisis/{ujian}/hitung-ulang', [AnalisisController::class, 'hitungUlang'])
        ->name('analisis.hitung-ulang');
    Route::get('/analisis/{ujian}', [AnalisisController::class, 'ujian'])->name('analisis.ujian');
});

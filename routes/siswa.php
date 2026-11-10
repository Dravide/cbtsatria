<?php

use App\Domain\Attendance\Http\Controllers\Siswa\AkunSiswaController;
use App\Domain\Attendance\Http\Controllers\Siswa\GerbangSiswaController;
use App\Domain\Attendance\Http\Controllers\Siswa\JadwalSiswaController;
use App\Domain\Attendance\Http\Controllers\Siswa\KerjakanSiswaController;
use App\Domain\Attendance\Http\Controllers\Siswa\NilaiSiswaController;
use App\Domain\Attendance\Http\Controllers\Siswa\PelanggaranSiswaController;
use App\Domain\Attendance\Http\Controllers\Siswa\SelesaiSiswaController;
use App\Domain\Attendance\Http\Controllers\Siswa\TerkunciSiswaController;
use App\Domain\Exam\Http\Controllers\ExamMediaSiswaController;
use Illuminate\Support\Facades\Route;

/*
|==========================================================================
| Konsol siswa - middleware `siswa`, BUKAN `auth` saja
|==========================================================================
| Alasan yang sama dengan `guru`, dari arah sebaliknya: Pengawas yang
| mengetik /siswa punya akun yang SAH, dan tanpa gerbang ini ia akan
| menerima laman siswa - sementara `KerangkaPonsel` membaca `pengawasRuang`
| dari shared props dan akan menampilkan kode ruangnya di atas jadwal ujian
| seorang anak.
|
| AksesSiswa menolak Admin, Guru, dan Pengawas dengan 403. 403 dan bukan 404:
| keberadaan /siswa bukan rahasia. Yang dijaga di sini adalah PERANNYA;
| kepemilikan baris dijaga terpisah, dan jawabannya 404 - lihat SiswaDasar.
|
| Seluruh alamatnya memuat {peserta}, id `exam_participants`. Itu disengaja:
| guru mengirim tautan gerbang lewat pesan ("buka ini"), dan tautan yang
| tidak menyebut penempatan mana akan membuka penempatan yang salah begitu
| seorang siswa punya lebih dari satu ujian di event yang sama.
|
| Rute `{peserta}` di-bind implisit dan TIDAK dipersempit per-siswa di sini -
| pemeriksaannya ada di SiswaDasar::milikSaya(), di setiap controller, karena
| route model binding tidak punya akses ke `student_id` tanpa menggandakan
| kueri di setiap rute.
*/

Route::middleware(['auth', 'siswa'])->prefix('siswa')->name('siswa.')->group(function () {
    // Plate 19 - jadwal. Beranda konsol siswa.
    Route::get('/', [JadwalSiswaController::class, 'index'])->name('dashboard');
    Route::get('/jadwal', [JadwalSiswaController::class, 'index'])->name('jadwal');

    // Plate 20-21 - gerbang. SATU alamat untuk dua laman: yang membedakannya
    // keadaan presensinya, bukan URL-nya. Lihat GerbangSiswaController.
    Route::get('/ujian/{peserta}/gerbang', [GerbangSiswaController::class, 'tampilkan'])->name('gerbang');

    // `throttle:gerbang-ujian` - PRD §19.2. Yang ditebak di sini adalah TOKEN
    // enam angka, dan token itu ditulis di papan tulis - jadi ia terlihat oleh
    // siapa pun di ruangan itu. Pembatasnya per SISWA (5/menit), bukan per
    // penempatan, supaya anak yang salah baca tidak menghukum kursi sebelahnya
    // yang memakai token yang sama. Lihat App\Support\PembatasLaju.
    Route::post('/ujian/{peserta}/gerbang', [GerbangSiswaController::class, 'mulai'])
        ->middleware('throttle:gerbang-ujian')
        ->name('mulai');

    // Plate 22-23 - pengerjaan. Rute tulisnya menjawab JSON, bukan redirect:
    // autosave berjalan di latar belakang. Lihat KerjakanSiswaController.
    Route::get('/ujian/{peserta}/kerjakan', [KerjakanSiswaController::class, 'tampilkan'])->name('kerjakan');
    Route::post('/ujian/{peserta}/butir/{butir}', [KerjakanSiswaController::class, 'simpan'])->name('butir.simpan');
    Route::post('/ujian/{peserta}/butir/{butir}/tandai', [KerjakanSiswaController::class, 'tandai'])->name('butir.tandai');
    Route::post('/ujian/{peserta}/denyut', [KerjakanSiswaController::class, 'denyut'])->name('denyut');

    // Plate 24 - laporan pelanggaran dari peramban siswa. Jenisnya TIDAK
    // dipercaya: seluruh keputusan dibuat `PencatatPelanggaran`, termasuk
    // bahwa tingkat 0 tidak pernah menjadi baris. Lihat docblock endpoint-nya.
    Route::post('/ujian/{peserta}/lapor', [KerjakanSiswaController::class, 'laporkan'])->name('lapor');

    // Media soal yang dibaca peserta. Rutenya memuat {soal} - id snapshot -
    // dan bukan sekadar {media}, supaya berkas dari soal yang belum diujikan
    // tidak bisa dibaca dengan menebak nomor idnya. Dijaga
    // ExamMediaSiswaController, bukan QuestionPolicy: Siswa tidak dan tidak
    // akan pernah punya TeachingAssignment.
    Route::get('/ujian/{peserta}/berkas/{soal}/{media}', [ExamMediaSiswaController::class, 'tampilkan'])->name('berkas');

    // Plate 25 - terkunci. Laman ini TIDAK membaca ulang pelanggarannya lewat
    // websocket; ia disegarkan sendiri, sama seperti gerbang.
    Route::get('/ujian/{peserta}/terkunci', [TerkunciSiswaController::class, 'tampilkan'])->name('terkunci');

    // Plate 26-27 - konfirmasi dan sesudah dikumpulkan, satu alamat.
    Route::get('/ujian/{peserta}/selesai', [SelesaiSiswaController::class, 'tampilkan'])->name('selesai');
    Route::post('/ujian/{peserta}/selesai', [SelesaiSiswaController::class, 'kumpulkan'])->name('kumpulkan');

    // Plate 28-29 - nilai dan riwayat pelanggaran sendiri.
    Route::get('/nilai', [NilaiSiswaController::class, 'index'])->name('nilai');
    Route::get('/pelanggaran', [PelanggaranSiswaController::class, 'index'])->name('pelanggaran');

    // Tab keempat konsol siswa - identitas, ganti sandi, dan keluar. Tidak
    // digambar wireframe mana pun; isinya dikunci pemilik produk 2026-11-07
    // (identitas + ganti sandi + keluar, peringatan sandi bawaan TANPA
    // penegakan). Lihat AkunSiswaController.
    Route::get('/akun', [AkunSiswaController::class, 'index'])->name('akun');

    // `throttle:ganti-sandi` - pembatas laju ketiga. Yang dijaga bukan tebakan
    // enam angka melainkan biaya `Hash::check` bcrypt: sesi yang dicuri dipakai
    // mempelajari sandi korban. Per AKUN, tanpa pasangan per-IP. Lihat
    // App\Support\PembatasLaju.
    Route::put('/akun/sandi', [AkunSiswaController::class, 'gantiSandi'])
        ->middleware('throttle:ganti-sandi')->name('akun.sandi');
});

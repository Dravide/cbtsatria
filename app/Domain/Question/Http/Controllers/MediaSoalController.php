<?php

namespace App\Domain\Question\Http\Controllers;

use App\Domain\Question\Models\QuestionMedia;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Berkas media satu soal - gambar, audio, video - wireframe plate 03.
 *
 * =====================================================================
 * Disk PRIVAT, dan rute inilah satu-satunya jalan keluarnya
 * =====================================================================
 * Lihat catatan di QuestionMedia: berkasnya duduk di `storage/app/private`,
 * namanya di disk diacak `Str::random(40)`, dan symlink `public/storage`
 * sengaja tidak dibuat. Akibatnya tidak ada URL yang bisa ditebak, dan
 * satu-satunya cara membacanya adalah lewat sini.
 *
 * Alasan berkas soal tidak boleh publik bukan kerahasiaan soal - soal ujian
 * memang akan dilihat siswa. Yang dijaga adalah SOAL YANG BELUM DIUJIKAN:
 * satu mapel bisa punya 300 soal di bank soal, dan hanya 40 di antaranya
 * yang masuk ujian. Berkas yang bisa dibaca siapa pun yang menebak namanya
 * berarti seluruh bank soal satu sekolah bocor lewat gambar-gambarnya.
 *
 * ---------------------------------------------------------------------
 * Gate-nya `view` pada SOALNYA, bukan pada media
 * ---------------------------------------------------------------------
 * Satu QuestionPolicy diikat ke sembilan model (lihat QuestionServiceProvider),
 * termasuk QuestionMedia - sehingga sebenarnya Gate::authorize('view', $media)
 * sudah menjawab. Yang dipakai di sini adalah SOAL pemiliknya, dan alasannya
 * menentukan: izinnya adalah izin MEMBACA SOAL, bukan izin membaca baris
 * media. Guru yang mapelnya berbeda tidak boleh membaca soalnya, dan karena
 * itu tidak boleh membaca gambarnya juga - walaupun baris medianya sendiri
 * tidak punya mapel.
 *
 * Konsekuensinya sama dengan berkas berita acara Fase 6: yang disaring bukan
 * URL-nya melainkan barisnya, sehingga media sekolah lain berakhir 404
 * lewat SchoolScope di binding implisit.
 *
 * ---------------------------------------------------------------------
 * Nama aslinya dikirim DI SINI, dan hanya di sini
 * ---------------------------------------------------------------------
 * Di disk namanya acak; yang mengenali berkasnya bagi Guru adalah kolom
 * `nama_asli`. `Content-Disposition: inline` dipilih karena yang membuka
 * laman ini ingin MELIHAT gambarnya - memaksa unduhan akan mengotori folder
 * unduhan setiap kali soal dibuka di builder.
 *
 * Berkas yang hilang dari disk berakhir 404, bukan 500: itu keadaan yang
 * bisa terjadi (pemulihan cadangan, pembersihan manual), dan galat server
 * untuk keadaan itu tidak memberi tahu siapa pun apa yang sebenarnya terjadi.
 */
class MediaSoalController
{
    public function tampilkan(QuestionMedia $media): StreamedResponse
    {
        $soal = $media->question()->first();

        if ($soal === null) {
            abort(404, 'Berkas media tidak ditemukan.');
        }

        // 404, bukan 403 - pola yang sama dengan seluruh QuestionPolicy:
        // bagi Guru yang mapelnya berbeda, soalnya memang tidak ada.
        if (! Gate::allows('view', $soal)) {
            abort(404, 'Berkas media tidak ditemukan.');
        }

        $jalur = $media->storage_path;

        if ($jalur === null || ! Storage::disk('local')->exists($jalur)) {
            abort(404, 'Berkasnya tidak ada di penyimpanan. Soal mungkin diimpor dari sekolah lain.');
        }

        return Storage::disk('local')->response(
            $jalur,
            $media->nama_asli ?? basename($jalur),
            ['Content-Type' => $media->mime_type ?? 'application/octet-stream'],
        );
    }
}

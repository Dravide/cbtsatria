<?php

namespace App\Domain\Question\Http\Controllers;

use App\Domain\Question\Enums\JenisSoal;
use App\Domain\Question\Models\Question;
use App\Domain\Question\Services\AmpuanMapel;
use App\Domain\Question\Services\PenulisSoalAi;
use App\Support\Ai\GalatPenyedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Generate soal dengan AI - tombol di laman builder.
 *
 * =====================================================================
 * Endpoint ini TIDAK menulis apa pun ke bank soal
 * =====================================================================
 * Dan itu keputusan yang membentuk seluruh kelas ini. Jawabannya adalah
 * JSON yang mengisi form di peramban; Guru memeriksa, menyunting, lalu
 * menekan Simpan seperti soal yang ia tulis sendiri.
 *
 * Alasannya bukan kehati-hatian berlebih. Model bahasa menghasilkan jawaban
 * yang terdengar benar dan kadang salah. Kalau soal itu langsung tersimpan,
 * kunci yang salah dicetak atas nama Guru, dipakai ratusan siswa, dan yang
 * harus menjelaskannya di kelas adalah Guru yang tidak pernah membacanya.
 *
 * =====================================================================
 * Jawaban JSON, dan galat pun JSON
 * =====================================================================
 * Pemanggilnya `fetch` dari modal, bukan tautan Inertia. Karena itu setiap
 * jalur keluar - berhasil maupun gagal - harus berbentuk `{...}` yang bisa
 * dibaca skripnya. `abort(422)` dari `AmpuanMapel` tetap menghasilkan JSON
 * karena permintaannya mengirim `Accept: application/json`, tetapi galat
 * penyedia ditangani sendiri di sini supaya kalimatnya sampai utuh.
 */
class SoalAiController
{
    public function generate(Request $request, PenulisSoalAi $penulis, AmpuanMapel $ampuan): JsonResponse
    {
        // Guru saja, dan itu bukan pembatasan baru: `QuestionPolicy::create()`
        // sudah menolak Admin (PRD §3.1 - Admin tidak mengubah isi bank soal).
        // Memakai ability yang sama berarti aturannya tetap satu tempat.
        Gate::authorize('create', Question::class);

        $data = $request->validate([
            // `exists` + `school_id`, bukan sekadar `integer`. Tanpa itu,
            // `subject_id` yang tidak menunjuk mapel mana pun - dan peramban
            // memang mengirim 0 saat daftar mapel Guru masih kosong - lolos
            // validasi lalu ditolak `AmpuanMapel` dengan "Anda tidak mengampu
            // mata pelajaran tersebut.". Kalimat itu menuduh Guru atas mapel
            // yang bahkan tidak ada, dan Guru yang membacanya akan mencari
            // kesalahan di tempat yang salah.
            'subject_id' => [
                'required', 'integer',
                Rule::exists('subjects', 'id')->where('school_id', $request->user()->school_id),
            ],
            'jenis' => ['required', 'string', Rule::in(PenulisSoalAi::JENIS_DIDUKUNG)],
            // Lima karakter minimum: topik sependek "x" menghasilkan soal
            // acak yang lalu dibuang Guru - dan tetap ditagih.
            'topik' => ['required', 'string', 'min:5', 'max:500'],
            'tingkat' => ['required', 'string', Rule::in(['mudah', 'sedang', 'sulit'])],
        ], [
            'subject_id.required' => 'Pilih mata pelajarannya.',
            'subject_id.exists' => 'Mata pelajaran itu tidak ada di sekolah ini.',
            'jenis.required' => 'Pilih jenis soalnya.',
            'jenis.in' => 'Jenis soal itu belum bisa dibuat AI. Pilih pilihan ganda, benar/salah, isian singkat, atau uraian.',
            'topik.required' => 'Tulis topiknya lebih dulu.',
            'topik.min' => 'Topiknya terlalu pendek. Tulis setidaknya lima karakter.',
            'tingkat.required' => 'Pilih tingkat kesulitannya.',
            'tingkat.in' => 'Tingkat kesulitan harus mudah, sedang, atau sulit.',
        ], [
            'subject_id' => 'mata pelajaran',
            'jenis' => 'jenis soal',
            'topik' => 'topik',
            'tingkat' => 'tingkat kesulitan',
        ]);

        // 422 dan bukan 403: Guru memang boleh menyusun soal, hanya tidak
        // untuk mapel itu. Pesannya muncul di sebelah field Mata pelajaran.
        $ampuan->pastikan($request->user(), (int) $data['subject_id']);

        try {
            $hasil = $penulis->susun(
                (int) $data['subject_id'],
                JenisSoal::from($data['jenis']),
                $data['topik'],
                $data['tingkat'],
            );
        } catch (GalatPenyedia $e) {
            // Bukan 500. Penyedia yang salah dikonfigurasi adalah keadaan
            // yang bisa diperbaiki Admin dalam satu menit; 500 membuat Guru
            // melapor "aplikasinya rusak" dan tidak ada yang mencarinya di
            // tab Pengaturan.
            return response()->json(['pesan' => $e->getMessage()], 422);
        } catch (ValidationException $e) {
            // SkemaJenisSoal menolak keluaran model. Pesan aslinya menyebut
            // kolom internal ("kunci", "butir") yang tidak berarti apa-apa
            // bagi Guru - yang ia butuhkan adalah "coba lagi atau susun
            // manual", dan itu yang dikirim.
            report($e);

            return response()->json([
                'pesan' => 'AI mengembalikan soal yang bentuknya tidak sah, dan tidak disimpan. '
                    .'Coba lagi, atau susun soalnya secara manual.',
            ], 422);
        }

        return response()->json($hasil);
    }
}

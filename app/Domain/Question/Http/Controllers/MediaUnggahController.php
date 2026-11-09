<?php

namespace App\Domain\Question\Http\Controllers;

use App\Domain\Question\Models\Question;
use App\Domain\Question\Models\QuestionMedia;
use App\Domain\Question\Models\QuestionOption;
use App\Domain\Question\Services\MediaSoal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Unggah dan hapus media soal - gambar/audio/video pada SOAL dan pada PILIHAN
 * JAWABAN. Wireframe plate 03 ("Berkas media diunggah, bukan ditautkan").
 *
 * =====================================================================
 * Mengapa JSON, bukan redirect Inertia
 * =====================================================================
 * Unggahan terjadi di dalam builder, TANPA memuat ulang laman: Guru menempel
 * gambar pada pilihan B dan harus tetap melihat pilihan A, C, D yang sedang
 * ia tulis. Redirect Inertia akan memasang ulang seluruh builder dan
 * membuang draf yang belum disimpan. Karena itu endpoint ini menjawab JSON,
 * persis seperti autosave di laman ujian, dan builder memanggilnya lewat
 * `fetch` ber-CSRF.
 *
 * =====================================================================
 * Soal BARU: media "draf" tanpa question_id
 * =====================================================================
 * Pada soal yang belum tersimpan, tidak ada id soal untuk menggantungkan
 * berkasnya. Unggahan karena itu menghasilkan baris dengan `question_id`
 * NULL (lihat MediaSoal::simpan dan migrasi 0014), dan builder mengirim
 * daftar id-nya sebagai `media_draf` saat soal disimpan - lalu `tautkanDraf`
 * menautkannya. Selama berupa draf, berkasnya hanya bisa dibaca
 * pengunggahnya lewat rute `guru.media` (cabang `bolehLihat`).
 *
 * =====================================================================
 * Izin: `update` pada SOAL, bukan peran
 * =====================================================================
 * Yang menentukan adalah apakah Guru ini berhak MENGUBAH soal itu - yaitu
 * penulisnya (QuestionPolicy). Admin boleh melihat soal tetapi TIDAK boleh
 * menyunting isinya (PRD §3.1), dan menempel gambar ke pilihan jawaban
 * adalah menyunting isi. Karena itu Admin yang mencoba mengunggah menerima
 * 403 yang sama dengan yang ia terima saat menyimpan perubahan soal.
 */
class MediaUnggahController
{
    /**
     * Unggah satu berkas.
     *
     * `soal` disebut eksplisit di body, bukan lewat binding rute, dan boleh
     * kosong (dra). Saat kosong, pemanggilnya WAJIB Guru yang berhak
     * menyusun soal - diperiksa lewat `create`.
     */
    public function unggah(Request $request, MediaSoal $media): JsonResponse
    {
        $data = $request->validate([
            'berkas' => ['required', 'file', 'max:'.MediaSoal::BATAS_UNGGAH_KB],
            'soal_id' => ['nullable', 'integer'],
            'option_key' => ['nullable', 'string', 'max:8'],
        ], [
            'berkas.required' => 'Pilih berkasnya lebih dulu.',
            'berkas.max' => 'Berkasnya melebihi 20 MB. Kecilkan lebih dulu, lalu unggah lagi.',
        ]);

        $user = $request->user();

        // Soal tujuan: milik sekolah ini (SchoolScope pada binding), dan Guru
        // ini berhak mengubahnya. `soal_id` kosong berarti draf untuk soal
        // baru - Guru saja yang boleh menyusun soal.
        $soalId = isset($data['soal_id']) ? (int) $data['soal_id'] : null;
        $soal = null;

        if ($soalId !== null) {
            $soal = Question::findOrFail($soalId);

            if (! Gate::allows('update', $soal)) {
                abort(403, 'Hanya penulis soal yang dapat menambahkan gambar.');
            }
        } else {
            Gate::authorize('create', Question::class);
        }

        // Pilihan jawaban tujuan, bila ada. Dicari pada SOAL yang sama - tanpa
        // itu, satu request bisa menempelkan gambar ke pilihan milik soal lain.
        $optionId = null;
        $optionKey = isset($data['option_key']) && $data['option_key'] !== ''
            ? (string) $data['option_key']
            : null;

        // Soal tujuan media ini. Untuk pilihan yang BELUM tersimpan (Guru baru
        // menambahkannya di builder) soal tetap ada tetapi baris opsinya belum -
        // di situlah `questionId` diturunkan menjadi NULL, sehingga media
        // tersimpan sebagai DRAF dan ditautkan `tautkanOpsiDraf()` saat soal
        // disimpan. Tanpa cabang ini, menambah pilihan baru pada soal yang
        // sudah ada lalu mengunggah gambarnya akan ditolak 422 padahal itu
        // persis alur "unggah kapan saja" yang diminta.
        $questionId = $soal?->id;

        if ($soal !== null && $optionKey !== null) {
            $opsi = QuestionOption::query()
                ->where('question_id', $soal->id)
                ->where('option_key', strtolower($optionKey))
                ->first();

            if ($opsi !== null) {
                $optionId = (int) $opsi->id;
            } else {
                $questionId = null;
            }
        }

        $baris = $media->simpan(
            $request->file('berkas'),
            (int) $user->school_id,
            $questionId,
            $optionId,
            (int) $user->id,
            $optionKey,
        );

        return response()->json([
            'media' => [
                'id' => $baris->id,
                'tipe' => $baris->media_type,
                'mime' => $baris->mime_type,
                'nama' => $baris->nama_asli,
                'ukuran' => (int) $baris->ukuran,
                'url' => route('guru.media', $baris->id),
                'gambar' => $baris->berkasGambar(),
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * Hapus satu berkas media.
     *
     * Izin: `update` pada SOAL pemiliknya. Media draf (belum bertuan soal)
     * boleh dihapus pengunggahnya sendiri - kalau tidak, gambar yang salah
     * pilih pada soal baru tidak akan pernah bisa dibuang sebelum disimpan.
     */
    public function hapus(Request $request, QuestionMedia $media, MediaSoal $layanan): JsonResponse
    {
        $user = $request->user();
        $soal = $media->question_id === null ? null : Question::find($media->question_id);

        $boleh = $soal !== null
            ? Gate::allows('update', $soal)
            : ((int) ($media->metadata_json['diunggah_oleh'] ?? 0) === (int) $user->id
                && (int) $media->school_id === (int) $user->school_id);

        if (! $boleh) {
            abort(403, 'Anda tidak berhak menghapus berkas ini.');
        }

        $layanan->hapus($media);

        return response()->json(['ok' => true]);
    }
}

<?php

namespace App\Domain\Question\Http\Controllers;

use App\Domain\Question\Models\Question;
use App\Domain\Question\Models\QuestionMedia;
use App\Domain\Question\Models\QuestionPackage;
use App\Domain\Question\Models\QuestionPackageItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pratinjau soal dan paket - wireframe-peran.html plate 09.
 *
 * =====================================================================
 * Mengapa pratinjau TIDAK memakai layar siswa yang sesungguhnya
 * =====================================================================
 * Plate 09 menggambar pratinjau di dalam kerangka siswa - penghitung waktu,
 * pemilih nomor, opsi yang bisa ditekan - dan catatannya berbunyi: "pratinjau
 * yang tampil beda dari ujian sebenarnya akan meloloskan soal yang di layar
 * siswa justru terpotong".
 *
 * Layar siswa itu sendiri adalah Fase 7, dan ia belum ada. Yang dibangun di
 * sini karena itu adalah yang benar-benar bisa dibangun sekarang tanpa
 * berbohong: butirnya dirender dengan bentuk yang SAMA dengan yang nanti
 * dikirim ke siswa - `ExamQuestion::untukSiswa()` membuang kunci, rubrik, dan
 * pembahasan, dan pratinjau ini membuang ketiganya juga.
 *
 * Bedanya satu, dan itu disengaja: pratinjau TIDAK menyembunyikan kunci dari
 * GURU. Yang menyusun soal perlu melihat apa yang ia tetapkan sebagai jawaban
 * benar - itu seluruh gunanya memeriksa sebelum menyimpan. Yang dijaga adalah
 * bentuk SOALNYA, bukan kerahasiaan kunci dari penulisnya.
 *
 * Karena itu pratinjau ini menyebut dirinya apa adanya: kerangka soal dalam
 * ukuran baca, bukan tiruan ujian. Layar yang mengaku sebagai ujian padahal
 * penghitung waktunya tidak berjalan dan tombolnya tidak menyimpan apa pun
 * adalah layar yang membuat Guru mengira ia sudah menguji hal yang belum ia
 * uji.
 *
 * =====================================================================
 * Satu controller untuk soal dan paket, bukan dua
 * =====================================================================
 * Rencananya menyebut `PratinjauController` dengan dua rute, dan keduanya
 * tinggal di sini karena keduanya menjawab pertanyaan yang sama - "seperti
 * apa ini terlihat" - atas dua satuan yang berbeda besar. Yang berbeda hanya
 * izinnya: soal disaring `view`, paket disaring `viewPaket`.
 *
 * Rute Admin tidak ada di sini: Admin memasang paket ke mapel event (plate
 * 15), dan untuk itu ia cukup melihat daftar paket beserta jumlah butirnya.
 * Admin TIDAK menyunting isi bank soal (PRD §3.1), dan memberinya laman
 * pratinjau butir-per-butir berarti memberinya pintu ke pekerjaan yang bukan
 * miliknya.
 */
class PratinjauController
{
    /**
     * Pratinjau satu soal.
     *
     * Rutenya ada supaya Guru bisa memeriksa satu soal tanpa membuka builder
     * - dan itu bukan kenyamanan: builder menampilkan soal sebagai NILAI
     * FIELD, sehingga HTML yang tersimpan tidak pernah terlihat sebagai HTML
     * sampai ujiannya berjalan. Soal yang tabelnya berantakan atau rumusnya
     * rusak baru ketahuan di layar siswa, dan saat itu sudah terlambat.
     */
    public function soal(Request $request, Question $soal): Response
    {
        // 404, bukan 403 - pola yang sama dengan seluruh QuestionPolicy.
        if (! Gate::allows('view', $soal)) {
            abort(404, 'Soal tidak ditemukan.');
        }

        $soal->load(['options.media', 'items', 'rubrik', 'media', 'penulis:id,name', 'subject:id,name']);

        return Inertia::render('Guru/BankSoal/Pratinjau', [
            'soal' => $this->bentukSoal($soal),
            'bolehUbah' => Gate::allows('update', $soal),
        ]);
    }

    /**
     * Pratinjau seluruh isi satu paket - plate 09.
     *
     * Butirnya dikirim SELURUHNYA, tanpa paginasi, dan itu disengaja: yang
     * diperiksa Guru di sini adalah paketnya sebagai satu kesatuan - apakah
     * bobotnya berjumlah 100, apakah jenisnya berimbang, apakah ada soal yang
     * tersalin dua kali. Memaginasi berarti memaksa Guru menekan "berikutnya"
     * sambil mengingat angka yang ia lihat di halaman sebelumnya.
     */
    public function paket(Request $request, QuestionPackage $paket): Response
    {
        if (! Gate::allows('viewPaket', $paket)) {
            abort(404, 'Paket tidak ditemukan.');
        }

        $paket->load([
            'subject:id,name',
            'penulis:id,name',
            'butir.question.options.media',
            'butir.question.items',
            'butir.question.rubrik',
            'butir.question.media',
            'butir.question.penulis:id,name',
            'butir.question.subject:id,name',
        ]);

        $butir = $paket->butir
            ->sortBy('position')
            ->values()
            ->map(function (QuestionPackageItem $b, int $i) {
                $soal = $b->question;

                return $soal === null
                    ? null
                    : $this->bentukSoal($soal) + [
                        'nomor' => $i + 1,
                        'poin' => $b->bobotBerlaku(),
                        'poin_override' => $b->points_override === null ? null : (float) $b->points_override,
                    ];
            })
            ->filter()
            ->values()
            ->all();

        return Inertia::render('Guru/Paket/Pratinjau', [
            'paket' => [
                'id' => $paket->id,
                'nama' => $paket->name,
                'mapel' => $paket->subject?->name,
                'tingkat' => $paket->grade_level,
                'keterangan' => $paket->description,
                'status' => $paket->status->value,
                'status_label' => $paket->status->label(),
                'penulis' => $paket->penulis?->name,
            ],
            'butir' => $butir,
            'bolehUbah' => Gate::allows('ubahPaket', $paket),
        ]);
    }

    /* ------------------------------------------------------------- Pembantu */

    /**
     * Bentuk satu soal untuk layar pratinjau.
     *
     * Kunci, rubrik, dan pembahasan IKUT dikirim - dan itu satu-satunya
     * perbedaan dengan `ExamQuestion::untukSiswa()`. Yang membacanya adalah
     * Guru penyusunnya lewat rute ber-Gate `view`, bukan siswa lewat payload
     * ujian.
     *
     * Media dikirim sebagai TAUTAN ke rute pembacanya (`guru.media`), bukan
     * sebagai jalur simpan. Jalur di disk adalah nama acak yang tidak berarti
     * apa pun bagi peramban, dan mengirimkannya berarti peramban mencoba
     * membukanya sebagai URL relatif dan gagal tanpa galat yang terlihat.
     *
     * @return array<string, mixed>
     */
    private function bentukSoal(Question $soal): array
    {
        return [
            'id' => $soal->id,
            'kode' => $soal->code,
            'judul' => $soal->title,
            'jenis' => $soal->question_type->value,
            'jenis_label' => $soal->question_type->label(),
            'jenis_pendek' => $soal->question_type->pendek(),
            'tingkat' => $soal->difficulty,
            'status' => $soal->status,
            'mapel' => $soal->subject?->name,
            'penulis' => $soal->penulis?->name,
            'prompt' => $soal->prompt_json,
            'kunci' => $soal->answer_key_json,
            'skor' => $soal->scoring_config_json,
            'pembahasan' => $soal->explanation_json,
            'opsi' => $soal->options->map(fn ($o) => [
                'option_key' => $o->option_key,
                'content_json' => $o->content_json,
                'is_correct' => (bool) $o->is_correct,
                'position' => $o->position,
                'media' => $o->media->map(fn (QuestionMedia $m) => $this->bentukMedia($m))->all(),
            ])->all(),
            'butir' => $soal->items->map(fn ($b) => [
                'item_key' => $b->item_key,
                'item_group' => $b->item_group,
                'content_json' => $b->content_json,
                'position' => $b->position,
            ])->all(),
            'rubrik' => $soal->rubrik->map(fn ($r) => [
                'criterion' => $r->criterion,
                'description' => $r->description,
                'max_score' => (float) $r->max_score,
                'position' => $r->position,
            ])->all(),
            'media' => $soal->media
                ->whereNull('option_id')
                ->map(fn (QuestionMedia $m) => $this->bentukMedia($m))
                ->values()
                ->all(),
        ];
    }

    /**
     * Satu penunjuk media untuk pratinjau: metadata + URL rute pembacanya.
     *
     * `url` menunjuk `guru.media`, bukan jalur disk - lihat catatan
     * `bentukSoal`. Dipakai media tingkat soal DAN media pilihan jawaban
     * supaya keduanya dirender dengan bentuk yang sama.
     *
     * @return array<string, mixed>
     */
    private function bentukMedia(QuestionMedia $m): array
    {
        return [
            'id' => $m->id,
            'tipe' => $m->media_type,
            'mime' => $m->mime_type,
            'nama' => $m->nama_asli,
            'ukuran' => (int) $m->ukuran,
            'metadata' => $m->metadata_json,
            'url' => route('guru.media', $m->id),
            'gambar' => $m->berkasGambar(),
        ];
    }
}

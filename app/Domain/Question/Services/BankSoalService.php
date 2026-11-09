<?php

namespace App\Domain\Question\Services;

use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Question\Enums\JenisSoal;
use App\Domain\Question\Enums\StatusSoal;
use App\Domain\Question\Models\Question;
use App\Domain\Question\Models\QuestionItem;
use App\Domain\Question\Models\QuestionOption;
use App\Domain\Question\Support\PembersihHtml;
use App\Domain\Question\Support\SkemaJenisSoal;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya penulis `questions` dan seluruh tabel anaknya.
 *
 * =====================================================================
 * Mengapa harus satu pintu
 * =====================================================================
 * Satu soal adalah SATU fakta yang tersebar di lima tabel: `questions`,
 * `question_options`, `question_items`, `question_media`,
 * `essay_rubric_criteria`. Tidak ada satu pun constraint basis data yang bisa
 * menegakkan "kunci di answer_key_json harus sama dengan is_correct di
 * question_options" - keduanya kolom di tabel berbeda.
 *
 * Yang menjaga keduanya tetap sama adalah berkas ini, dan hanya ini. Setiap
 * tempat lain yang menulis salah satunya sendirian menghasilkan soal yang
 * tampil dengan kunci berbeda dari yang dinilai mesin - dan gejalanya bukan
 * galat, melainkan 300 siswa dengan nilai yang salah.
 *
 * ---------------------------------------------------------------------
 * Tiga hal yang dikerjakan setiap penyimpanan, dalam urutan ini
 * ---------------------------------------------------------------------
 *   1. BERSIHKAN  - seluruh `_json` lewat PembersihHtml.
 *   2. VALIDASI   - bentuk kunci lewat SkemaJenisSoal.
 *   3. TULIS      - semuanya dalam satu transaksi.
 *
 * Urutannya menentukan. Pembersihan lebih dulu karena validasi kunci mengukur
 * panjang teks - dan teks yang belum dibersihkan bisa melebihi batas lalu
 * dipotong setelah dinyatakan sah. Validasi sebelum tulis karena transaksi
 * yang dibatalkan setelah separuh barisnya masuk tetap membatalkan semuanya,
 * tetapi lebih murah menolak sebelum menyentuh basis data sama sekali.
 *
 * ---------------------------------------------------------------------
 * Menghapus opsi lama lalu menulis ulang, bukan mencocokkan satu per satu
 * ---------------------------------------------------------------------
 * Guru yang mengubah jumlah pilihan dari 4 menjadi 5, atau mengubah urutannya,
 * memaksa setiap pendekatan "update yang cocok" untuk menebak mana yang mana.
 * Menghapus dan menulis ulang seluruh anaknya tidak menebak apa pun - dan
 * satu-satunya yang bisa hilang karenanya adalah id baris anak, yang tidak
 * pernah dipakai siapa pun sebagai rujukan (snapshot menyalin ISI, bukan id).
 *
 * `question_media` adalah pengecualiannya: id-nya ADA di URL rute berkas, jadi
 * barisnya tidak boleh dihapus sembarangan saat soal disunting. Media
 * dikelola lewat jalur terpisah (unggah/hapus berkas), bukan lewat payload
 * penyuntingan soal.
 */
class BankSoalService
{
    public function __construct(
        private readonly SkemaJenisSoal $skema,
        private readonly PembersihHtml $pembersih,
        private readonly ActivityLogger $logger,
        private readonly MediaSoal $media,
    ) {}

    /**
     * Simpan soal baru.
     *
     * @param  array<string, mixed>  $data  Payload yang sudah tervalidasi bentuk luarnya.
     */
    public function buat(array $data, int $schoolId, int $penulisId): Question
    {
        return DB::transaction(function () use ($data, $schoolId, $penulisId) {
            $jenis = JenisSoal::from($data['question_type']);
            $bersih = $this->bersihkan($data);

            $this->skema->validasi(
                $jenis,
                $bersih['answer_key_json'],
                (float) ($bersih['scoring_config_json']['bobot'] ?? 1),
                $this->opsiDari($bersih),
                $this->butirDari($bersih),
                $this->rubrikDari($bersih),
                $bersih['scoring_config_json'] ?? [],
            );

            $soal = Question::create([
                'school_id' => $schoolId,
                'created_by' => $penulisId,
                'subject_id' => $data['subject_id'],
                // Kode dibangkitkan DI SINI, bukan setelah barisnya tersimpan.
                // Versi pertama membiarkan controller menulis kode pada
                // transaksi kedua, dan itu tidak bisa bekerja: kolomnya NOT
                // NULL, sehingga `create()` dengan kode kosong ditolak basis
                // data sebelum controller sempat memperbaikinya. `kodeBaru()`
                // membaca kode terbesar yang sudah ada, bukan jumlah baris -
                // jadi ia tidak butuh id soal yang baru lahir.
                'code' => ($data['code'] ?? null) ?: $this->kodeBaru($schoolId, (int) $data['subject_id']),
                'title' => $data['title'],
                'question_type' => $data['question_type'],
                'difficulty' => $data['difficulty'] ?? 'sedang',
                'prompt_json' => $bersih['prompt_json'],
                'answer_key_json' => $bersih['answer_key_json'],
                'scoring_config_json' => $bersih['scoring_config_json'],
                'explanation_json' => $bersih['explanation_json'] ?? null,
                'status' => $data['status'] ?? StatusSoal::Draft->value,
                'published_at' => ($data['status'] ?? null) === StatusSoal::Siap->value ? now() : null,
            ]);

            $this->tulisAnak($soal, $jenis, $bersih);

            // Gambar/audio/video yang diunggah SEBELUM soal ini punya id
            // masih berupa draf (`question_id` NULL). Menautkannya di sini -
            // di dalam transaksi yang sama - berarti tidak pernah ada soal
            // tersimpan yang medianya menggantung. Lihat MediaSoal::tautkanDraf.
            //
            // Daftar yang ditautkan adalah GABUNGAN media soal dan media opsi:
            // media opsi juga lahir sebagai draf (`question_id` NULL), dan
            // tautkanOpsiDraf() di bawah menyaring `question_id = soal ini` -
            // tanpa menautkan question_id-nya lebih dulu, gambar pilihan
            // jawaban yang baru diunggah tidak akan pernah ditemukan dan
            // lenyap diam-diam.
            $this->media->tautkanDraf(
                array_merge(
                    (array) ($data['media_draf'] ?? []),
                    (array) ($data['media_opsi_draf'] ?? []),
                ),
                $schoolId,
                (int) $soal->id,
                $penulisId,
            );

            // Media yang melekat pada PILIHAN JAWABAN ditautkan SESUDAH
            // tulisAnak(), karena baris opsinya baru ada sekarang - dan hanya
            // di sini id opsinya bisa ditanyakan. Lihat tautkanOpsiDraf.
            $this->media->tautkanOpsiDraf(
                (array) ($data['media_opsi_draf'] ?? []),
                $schoolId,
                (int) $soal->id,
                $penulisId,
            );

            $this->logger->log('Menambah soal', $soal, [
                'kode' => $soal->code,
                'jenis' => $jenis->value,
            ]);

            return $soal->fresh(['options', 'items', 'rubrik', 'media']);
        });
    }

    /**
     * Perbarui soal yang sudah ada.
     *
     * Izin TIDAK diperiksa di sini - itu tugas QuestionPolicy, dan memeriksanya
     * dua kali berarti dua tempat yang bisa berbeda diam-diam. Service ini
     * mengasumsikan pemanggilnya sudah berhak.
     */
    public function perbarui(Question $soal, array $data): Question
    {
        return DB::transaction(function () use ($soal, $data) {
            $jenis = JenisSoal::from($data['question_type'] ?? $soal->question_type->value);
            $bersih = $this->bersihkan($data);

            $kunci = $bersih['answer_key_json'] ?? $soal->answer_key_json;
            $skor = $bersih['scoring_config_json'] ?? $soal->scoring_config_json ?? [];

            $this->skema->validasi(
                $jenis,
                $kunci,
                (float) ($skor['bobot'] ?? 1),
                $this->opsiDari($bersih),
                $this->butirDari($bersih),
                $this->rubrikDari($bersih),
                $skor,
            );

            $status = $data['status'] ?? $soal->status;

            $soal->update([
                'subject_id' => $data['subject_id'] ?? $soal->subject_id,
                'code' => $data['code'] ?? $soal->code,
                'title' => $data['title'] ?? $soal->title,
                'question_type' => $jenis->value,
                'difficulty' => $data['difficulty'] ?? $soal->difficulty,
                'prompt_json' => $bersih['prompt_json'] ?? $soal->prompt_json,
                'answer_key_json' => $kunci,
                'scoring_config_json' => $skor,
                // Tidak dikirim berarti TIDAK DIUBAH, bukan dikosongkan.
                // Perbedaan itu menentukan: Guru yang menyunting judulnya
                // saja tidak boleh kehilangan pembahasan yang ia tulis
                // kemarin, dan `?? null` akan melakukannya diam-diam.
                'explanation_json' => $bersih['explanation_json'] ?? $soal->explanation_json,
                'status' => $status,
                'published_at' => $status === StatusSoal::Siap->value
                    ? ($soal->published_at ?? now())
                    : null,
            ]);

            // Media pilihan jawaban dilepas SEBELUM tulisAnak() menghapus lalu
            // menulis ulang opsi: `question_media.option_id` memakai cascade,
            // sehingga tanpa langkah ini setiap penyuntingan menghapus gambar
            // pilihan jawabannya. Yang dikembalikan adalah peta
            // [media_id => option_key], dan ia disambungkan lagi sesudahnya.
            $petaOpsiLama = $this->media->lepasMediaOpsi((int) $soal->school_id, (int) $soal->id);

            $this->tulisAnak($soal, $jenis, $bersih);

            if ($petaOpsiLama !== []) {
                $this->media->tautkanUlangMediaOpsi($petaOpsiLama, (int) $soal->school_id, (int) $soal->id);
            }

            // Daftar yang ditautkan adalah GABUNGAN media soal dan media opsi:
            // media opsi baru juga masih draf (`question_id` NULL), sedangkan
            // tautkanOpsiDraf() menyaring `question_id = soal ini`. Menautkan
            // keduanya lebih dulu memastikan gambar pilihan yang baru diunggah
            // ditemukan, bukan lenyap diam-diam.
            $this->media->tautkanDraf(
                array_merge(
                    (array) ($data['media_draf'] ?? []),
                    (array) ($data['media_opsi_draf'] ?? []),
                ),
                (int) $soal->school_id,
                (int) $soal->id,
                (int) $soal->created_by,
            );

            $this->media->tautkanOpsiDraf(
                (array) ($data['media_opsi_draf'] ?? []),
                (int) $soal->school_id,
                (int) $soal->id,
                (int) $soal->created_by,
            );

            return $soal->fresh(['options', 'items', 'rubrik', 'media']);
        });
    }

    /**
     * Duplikat soal menjadi salinan milik Guru lain.
     *
     * Salinan memakai kode baru yang dibangkitkan, bukan kode aslinya: kode
     * unik per (sekolah, mapel), dan menyalin kode yang sama akan ditolak
     * constraint - dengan galat basis data yang tidak berarti apa pun bagi
     * Guru yang menekan tombol Duplikat.
     */
    public function duplikat(Question $soal, int $penulisId): Question
    {
        return DB::transaction(function () use ($soal, $penulisId) {
            $salinan = $soal->replicate([
                'created_by',
                'code',
                'status',
                'published_at',
            ]);

            $salinan->created_by = $penulisId;
            $salinan->code = $this->kodeBaru((int) $soal->school_id, (int) $soal->subject_id);
            $salinan->title = mb_substr($soal->title.' (salinan)', 0, 255);
            $salinan->status = StatusSoal::Draft->value;
            $salinan->published_at = null;
            $salinan->save();

            foreach ($soal->options as $opsi) {
                QuestionOption::create($opsi->only([
                    'school_id', 'option_key', 'content_json', 'is_correct', 'position',
                ]) + ['question_id' => $salinan->id]);
            }

            foreach ($soal->items as $butir) {
                QuestionItem::create($butir->only([
                    'school_id', 'item_group', 'item_key', 'content_json',
                    'correct_match_key', 'correct_position', 'position',
                ]) + ['question_id' => $salinan->id]);
            }

            foreach ($soal->rubrik as $kriteria) {
                $salinan->rubrik()->create($kriteria->only([
                    'school_id', 'criterion', 'description', 'max_score', 'position',
                ]));
            }

            // Media SENGAJA tidak disalin: berkasnya milik soal asal, dan dua
            // baris yang menunjuk satu berkas berarti menghapus salah satunya
            // akan memutus yang lain. Guru yang butuh medianya mengunggah
            // ulang - dan itu langkah yang terlihat.
            $this->logger->log('Menduplikat soal', $salinan, [
                'asal' => $soal->code,
                'kode' => $salinan->code,
            ]);

            return $salinan->fresh(['options', 'items', 'rubrik']);
        });
    }

    /**
     * Arsipkan soal.
     *
     * TIDAK dihapus, dan itu keputusan yang menentukan: soal yang pernah
     * dipakai ujian masih dirujuk snapshot serta laporan nilai, dan
     * menghapusnya memutus penelusuran "soal ini dari mana". Yang diarsipkan
     * hilang dari daftar bawaan tetapi tetap ada bila disaring.
     */
    public function arsipkan(Question $soal): Question
    {
        $soal->update([
            'status' => StatusSoal::Arsip->value,
            'published_at' => null,
        ]);

        $this->logger->log('Mengarsipkan soal', $soal, ['kode' => $soal->code]);

        return $soal;
    }

    /* ------------------------------------------------------- Mekanisme */

    /**
     * Bersihkan seluruh kolom teks berformat, dan bentuk ulang kuncinya.
     *
     * Yang dibersihkan BUKAN hanya prompt: isi opsi, isi butir jodoh, rubrik,
     * dan pembahasan semuanya bisa memuat HTML dari tempelan Guru. Membiarkan
     * satu di antaranya berarti meninggalkan satu jalur masuk XSS terbuka -
     * dan yang diperiksa mata selalu kolom pertama di daftar.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function bersihkan(array $data): array
    {
        $hasil = $data;

        foreach (['prompt_json', 'answer_key_json', 'scoring_config_json', 'explanation_json'] as $kolom) {
            if (array_key_exists($kolom, $hasil) && $hasil[$kolom] !== null) {
                $hasil[$kolom] = $this->pembersih->bersihkanDalam($hasil[$kolom]);
            }
        }

        foreach (['opsi', 'butir', 'rubrik'] as $kolom) {
            if (! empty($hasil[$kolom]) && is_array($hasil[$kolom])) {
                $hasil[$kolom] = $this->pembersih->bersihkanDalam($hasil[$kolom]);
            }
        }

        return $hasil;
    }

    /**
     * @param  array<string, mixed>  $bersih
     * @return array<int, array<string, mixed>>
     */
    private function opsiDari(array $bersih): array
    {
        return is_array($bersih['opsi'] ?? null) ? $bersih['opsi'] : [];
    }

    /** @return array<int, array<string, mixed>> */
    private function butirDari(array $bersih): array
    {
        return is_array($bersih['butir'] ?? null) ? $bersih['butir'] : [];
    }

    /** @return array<int, array<string, mixed>> */
    private function rubrikDari(array $bersih): array
    {
        return is_array($bersih['rubrik'] ?? null) ? $bersih['rubrik'] : [];
    }

    /**
     * Tulis ulang seluruh baris anak.
     *
     * @param  array<string, mixed>  $bersih
     */
    private function tulisAnak(Question $soal, JenisSoal $jenis, array $bersih): void
    {
        $kunci = $bersih['answer_key_json'] ?? $soal->answer_key_json ?? [];

        if ($jenis->pakaiOpsi() || array_key_exists('opsi', $bersih)) {
            $soal->options()->delete();

            foreach ($this->opsiDari($bersih) as $i => $opsi) {
                QuestionOption::create([
                    'school_id' => $soal->school_id,
                    'question_id' => $soal->id,
                    'option_key' => strtolower((string) $opsi['option_key']),
                    'content_json' => $opsi['content_json'],
                    // Diturunkan dari KUNCI, bukan dari payload: itulah cara
                    // keduanya dijamin sepakat. Kalau Guru mengirim
                    // is_correct sendiri, nilai itu diabaikan - dan itu
                    // benar, karena kunci adalah yang dinilai mesin.
                    'is_correct' => in_array(
                        strtolower((string) $opsi['option_key']),
                        array_map('strval', $kunci['opsi'] ?? []),
                        true,
                    ),
                    'position' => $opsi['position'] ?? $i,
                ]);
            }
        }

        if ($jenis->pakaiButir() || array_key_exists('butir', $bersih)) {
            $soal->items()->delete();

            foreach ($this->butirDari($bersih) as $i => $butir) {
                QuestionItem::create([
                    'school_id' => $soal->school_id,
                    'question_id' => $soal->id,
                    'item_group' => $butir['item_group'] ?? 'kiri',
                    'item_key' => (string) $butir['item_key'],
                    'content_json' => $butir['content_json'],
                    'correct_match_key' => $butir['correct_match_key'] ?? null,
                    'correct_position' => $butir['correct_position'] ?? null,
                    'position' => $butir['position'] ?? $i,
                ]);
            }
        }

        if ($jenis->perluRubrik() || array_key_exists('rubrik', $bersih)) {
            $soal->rubrik()->delete();

            foreach ($this->rubrikDari($bersih) as $i => $kriteria) {
                $soal->rubrik()->create([
                    'school_id' => $soal->school_id,
                    'criterion' => $kriteria['criterion'],
                    'description' => $kriteria['description'] ?? null,
                    'max_score' => $kriteria['max_score'],
                    'position' => $kriteria['position'] ?? $i,
                ]);
            }
        }
    }

    /**
     * Kode soal berikutnya untuk satu mapel.
     *
     * Berformat `Q-{mapel}-{urut}`, dan pencariannya memakai kode terbesar
     * yang sudah ada - bukan jumlah barisnya. Menghitung baris berarti
     * menghapus satu soal lama membuat kode berikutnya menabrak kode yang
     * sudah dipakai, dan galatnya muncul sebagai pelanggaran unique di layar
     * Guru yang tidak melakukan apa pun yang salah.
     */
    public function kodeBaru(int $schoolId, int $subjectId): string
    {
        $awalan = 'Q-'.$subjectId.'-';

        $terakhir = Question::tanpaTenant()
            ->where('school_id', $schoolId)
            ->where('subject_id', $subjectId)
            ->where('code', 'like', $awalan.'%')
            ->orderByDesc('id')
            ->value('code');

        $urut = $terakhir === null
            ? 1
            : ((int) mb_substr((string) $terakhir, mb_strlen($awalan))) + 1;

        // Satu percobaan ulang bila kode itu sudah ada: dua Guru yang menekan
        // Simpan pada saat yang sama bisa membaca kode terakhir yang sama,
        // dan satu di antaranya harus mendapat nomor berikutnya - bukan galat.
        while (Question::tanpaTenant()
            ->where('school_id', $schoolId)
            ->where('subject_id', $subjectId)
            ->where('code', $awalan.$urut)
            ->exists()
        ) {
            $urut++;
        }

        return $awalan.$urut;
    }
}

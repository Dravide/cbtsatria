<?php

namespace App\Domain\Question\Http\Requests;

use App\Domain\Question\Enums\JenisSoal;
use App\Domain\Question\Models\Question;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Bentuk LUAR satu soal - dan hanya bentuk luarnya.
 *
 * =====================================================================
 * Pembagian tugas dengan SkemaJenisSoal
 * =====================================================================
 * Berkas ini memeriksa yang bisa dijawab TANPA TAHU ARTINYA:
 *
 *   - `question_type` adalah salah satu dari sembilan nilai enum;
 *   - `prompt_json` adalah array;
 *   - `opsi` adalah array berisi objek dengan `option_key` dan `content_json`;
 *   - `bobot` adalah bilangan lebih dari nol.
 *
 * SkemaJenisSoal memeriksa ISI, dan isinya berbeda per jenis: kunci pilihan
 * ganda harus satu huruf, kunci mengurutkan harus permutasi lengkap, kunci
 * menjodohkan tidak boleh punya pasangan menggantung. Aturan itu tidak bisa
 * ditulis di sini tanpa sembilan cabang `required_if` yang hanya dipakai satu
 * tempat.
 *
 * Pemisahannya bukan kerapian: bentuk luar bisa divalidasi sebelum controller
 * menyentuh apa pun, dan bentuk dalam menuntut jenisnya sudah diketahui.
 *
 * =====================================================================
 * Batas panjang ditulis di sini DAN di PembersihHtml - dan itu disengaja
 * =====================================================================
 * Dua tempat, dua tugas: validasi menolak lebih awal dengan pesan yang rapi
 * di sebelah field, sementara pembersih memotong sebagai jaring terakhir.
 * Menghapus salah satunya berarti salah satu dari dua hal: Guru mengirim
 * 30.000 karakter dan menerima galat setelah tiga detik pembersihan, atau
 * teks sepanjang itu lolos ke basis data lewat jalur yang melewatkan
 * validator.
 */
class SimpanSoalRequest extends FormRequest
{
    /**
     * Izin diperiksa controller lewat Gate, bukan di sini.
     *
     * `authorize()` pada FormRequest dijalankan SEBELUM controller, dan
     * disimpan di sini berarti aturan "siapa boleh apa" hidup di dua tempat -
     * berkas ini dan QuestionPolicy. Satu tempat yang benar, satu tempat yang
     * bisa lupa diperbarui.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Route model binding memberi instance Question saat update; saat
        // create ia null, dan `code` wajib diisi sendiri oleh Guru.
        $soal = $this->route('soal');
        $soalId = $soal instanceof Question ? $soal->id : null;
        $mapelId = $this->input('subject_id', $soal instanceof Question ? $soal->subject_id : null);

        return [
            'subject_id' => ['required', 'integer'],
            'code' => [
                'nullable', 'string', 'max:40',
                // Kode unik per (sekolah, mapel) - constraint `soal_kode_unik`.
                // Divalidasi di sini juga supaya galatnya muncul sebagai pesan
                // di sebelah field, bukan sebagai pelanggaran unique 500.
                Rule::unique('questions', 'code')
                    ->where(fn ($q) => $q->where('subject_id', $mapelId)
                        ->where('school_id', $this->user()->school_id))
                    ->ignore($soalId),
            ],
            'title' => ['required', 'string', 'max:255'],
            'question_type' => ['required', Rule::enum(JenisSoal::class)],
            'difficulty' => ['nullable', Rule::in(['mudah', 'sedang', 'sulit'])],
            'status' => ['nullable', Rule::in(['draft', 'siap', 'arsip'])],

            // Bentuk luar saja. Isinya diperiksa SkemaJenisSoal.
            'prompt_json' => ['required', 'array'],
            'prompt_json.teks' => ['required', 'string', 'max:20000'],
            'answer_key_json' => ['nullable', 'array'],
            'scoring_config_json' => ['nullable', 'array'],
            'scoring_config_json.bobot' => ['nullable', 'numeric', 'gt:0'],
            'scoring_config_json.mekanisme' => ['nullable', Rule::in(['pilihan', 'isian', 'urut'])],
            'explanation_json' => ['nullable', 'array'],

            'opsi' => ['nullable', 'array', 'max:26'],
            'opsi.*.option_key' => ['required', 'string', 'max:8'],
            'opsi.*.content_json' => ['required', 'array'],
            'opsi.*.content_json.teks' => ['required', 'string', 'max:20000'],
            'opsi.*.position' => ['nullable', 'integer', 'min:0'],

            'butir' => ['nullable', 'array', 'max:60'],
            'butir.*.item_key' => ['required', 'string', 'max:16'],
            'butir.*.item_group' => ['nullable', Rule::in(['kiri', 'kanan'])],
            'butir.*.content_json' => ['required', 'array'],
            'butir.*.content_json.teks' => ['required', 'string', 'max:20000'],
            'butir.*.correct_match_key' => ['nullable', 'string', 'max:16'],
            'butir.*.correct_position' => ['nullable', 'integer', 'min:0'],
            'butir.*.position' => ['nullable', 'integer', 'min:0'],

            'rubrik' => ['nullable', 'array', 'max:20'],
            'rubrik.*.criterion' => ['required', 'string', 'max:255'],
            'rubrik.*.description' => ['nullable', 'string', 'max:1000'],
            'rubrik.*.max_score' => ['required', 'numeric', 'gt:0'],
            'rubrik.*.position' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'subject_id' => 'mata pelajaran',
            'code' => 'kode soal',
            'title' => 'judul',
            'question_type' => 'jenis soal',
            'prompt_json.teks' => 'teks soal',
            'scoring_config_json.bobot' => 'bobot',
            'rubrik.*.criterion' => 'nama kriteria',
            'rubrik.*.max_score' => 'skor maksimum',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'question_type' => 'Jenis soal tidak dikenal. Pilih salah satu dari sembilan jenis yang tersedia.',
            'answer_key_json.array' => 'Kunci jawaban harus berupa objek.',
            'prompt_json.teks.required' => 'Teks soal wajib diisi.',
            'prompt_json.teks.max' => 'Teks soal melebihi 20.000 karakter.',
            'opsi.max' => 'Satu soal tidak boleh punya lebih dari 26 pilihan.',
            'rubrik.*.max_score.gt' => 'Setiap kriteria rubrik harus berskor lebih dari nol.',
        ];
    }
}

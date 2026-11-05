<?php

namespace App\Domain\Exam\Services;

use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Models\ExamQuestion;
use App\Domain\Question\Enums\JenisSoal;
use App\Domain\Question\Models\Question;
use App\Domain\Question\Models\QuestionPackage;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Membekukan isi paket soal ke dalam sebuah ujian - PRD §16.6, blueprint `:312`.
 *
 * =====================================================================
 * Mengapa ia ada di modul Exam, bukan di Question
 * =====================================================================
 * Rencana awal menempatkannya di `Question\Services`, dan peta modul
 * membantahnya. Papan peta berbaris satu arah:
 *
 *   Exam     -> Question   boleh
 *   Question -> Exam       TIDAK ADA
 *
 * `PembekuPaket` menerima `Exam` dan menulis `exam_questions` - keduanya
 * milik modul Exam. Menaruhnya di Question berarti Question menyentuh ujian,
 * dan baris peta yang selama ini menjamin bank soal bisa diuji tanpa event
 * harus dibuka. Yang menyeberang dari Question ke sini adalah MODEL-nya, dan
 * arah itu memang yang tercatat di peta.
 *
 * =====================================================================
 * Salinan penuh, bukan rujukan - dan itu satu-satunya alasan kelas ini ada
 * =====================================================================
 * Blueprint `:312`: "Bank soal tetap boleh diedit Guru tanpa merusak ujian
 * yang sedang berjalan. Tanpa ini, satu edit Guru bisa mengubah ujian 300
 * siswa yang sedang berlangsung."
 *
 * Yang disalin BUKAN hanya teks soalnya. Kunci, bobot yang berlaku,
 * konfigurasi skor, rubrik, dan penunjuk media ikut - sebab snapshot yang
 * kehilangan kuncinya adalah ujian yang tidak bisa dinilai, dan snapshot yang
 * kehilangan bobotnya adalah ujian yang nilainya berbeda dari yang disusun
 * Gurunya tanpa satu pun orang menyadarinya sampai nilai dibagikan.
 *
 * ---------------------------------------------------------------------
 * Dibuang dulu, lalu ditulis - dan itu bukan pemborosan
 * ---------------------------------------------------------------------
 * Memasang paket baru ke mapel event lalu menjalankan ulang pembekuan pada
 * ujian yang sama harus menghasilkan snapshot yang BARU, bukan campuran dua
 * paket. Karena itu seluruh baris ujian itu dihapus lebih dulu di dalam
 * transaksi yang sama: kalau penulisannya gagal separuh jalan, yang kembali
 * adalah snapshot lama yang utuh, bukan snapshot setengah baru.
 *
 * ---------------------------------------------------------------------
 * Paket kosong DITOLAK
 * ---------------------------------------------------------------------
 * `ValidationException`, bukan snapshot kosong. Ujian tanpa soal adalah ujian
 * yang bisa dijadwalkan, ditempati 300 siswa, dan baru ketahuan kosong saat
 * tokennya dibuka. Satu-satunya tempat penolakan itu berguna adalah di sini -
 * sebelum ujiannya tersimpan.
 *
 * Paket yang belum berstatus Siap TIDAK diperiksa di sini: itu tugas
 * `PemasanganPaket` saat paket dipasang. Memeriksanya dua kali berarti dua
 * pesan galat yang mungkin berbeda untuk satu keadaan yang sama.
 */
class PembekuPaket
{
    /**
     * Salin seluruh butir paket ke `exam_questions`.
     *
     * Dipanggil dari dalam transaksi `ExamRegistrar::buat()`. Ia tidak membuka
     * transaksinya sendiri supaya pembuatan ujian dan pembekuan soalnya tidak
     * pernah berbeda nasib: ujian yang lahir tanpa snapshotnya adalah ujian
     * yang tidak punya soal.
     */
    public function bekukan(Exam $ujian): int
    {
        if ($ujian->question_package_id === null) {
            return 0;
        }

        $paket = QuestionPackage::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('id', $ujian->question_package_id)
            ->first();

        if ($paket === null) {
            return 0;
        }

        // Dimuat lengkap di muka, bukan satu kueri per butir: paket berisi 40
        // soal berarti 40 kueri soal ditambah 40 kueri opsi bila dimuat malas.
        $butir = $paket->butir()
            ->with(['question.options', 'question.items', 'question.rubrik', 'question.media'])
            ->orderBy('position')
            ->get();

        if ($butir->isEmpty()) {
            throw ValidationException::withMessages([
                'question_package_id' => 'Paket "'.$paket->name.'" belum berisi butir soal. Isi paketnya lebih dulu sebelum ujian dibuat.',
            ]);
        }

        return DB::transaction(function () use ($ujian, $butir) {
            // Pelaksanaan ulang = snapshot baru. Lihat catatan di kepala
            // berkas tentang mengapa dibuang lebih dulu, bukan ditambahi.
            ExamQuestion::query()->where('exam_id', $ujian->id)->delete();

            $nomor = 0;

            foreach ($butir as $baris) {
                $soal = $baris->question;

                // Butir yang soalnya sudah terhapus dari bank soal: barisnya
                // seharusnya tidak mungkin ada (FK-nya restrictOnDelete), tetapi
                // snapshot yang buta terhadap keadaan itu akan menulis baris
                // kosong alih-alih melewatinya.
                if ($soal === null) {
                    continue;
                }

                $nomor++;

                ExamQuestion::create([
                    'school_id' => $ujian->school_id,
                    'exam_id' => $ujian->id,
                    'position' => $baris->position ?? $nomor,

                    // Bobot BERLAKU, bukan bobot mentah: points_override paket
                    // sudah diperhitungkan. Lihat QuestionPackageItem::bobotBerlaku().
                    'bobot' => $baris->bobotBerlaku(),

                    // Rujukan lunak, murni penelusuran asal-usul.
                    'question_id' => $soal->id,
                    'kode' => $soal->code,
                    'jenis' => $soal->question_type,
                    'tingkat' => $soal->difficulty,
                    'judul' => $soal->title,

                    'soal_json' => $soal->prompt_json,
                    'opsi_json' => $this->opsi($soal),
                    'butir_json' => $this->butir($soal),
                    'kunci_json' => $soal->answer_key_json,
                    'rubrik_json' => $this->rubrik($soal),
                    'skor_json' => $soal->scoring_config_json,
                    'pembahasan_json' => $soal->explanation_json,
                    'media_json' => $this->media($soal),

                    'dibekukan_pada' => now(),
                ]);
            }

            return $nomor;
        });
    }

    /**
     * Opsi sebagai JSON.
     *
     * Bentuknya mengikuti payload builder, bukan baris basis datanya, supaya
     * bentuk yang dibaca layar siswa sama dengan bentuk yang dikirim Guru saat
     * menyusun - dan `untukSiswa()` tidak perlu menerjemahkan apa pun.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function opsi(Question $soal): ?array
    {
        $opsi = $soal->options;

        if ($opsi->isEmpty()) {
            return null;
        }

        return $opsi->map(fn ($o) => [
            'kunci' => $o->option_key,
            'teks' => $o->content_json,
        ])->all();
    }

    /** @return array<int, array<string, mixed>>|null */
    private function butir(Question $soal): ?array
    {
        $butir = $soal->items;

        if ($butir->isEmpty()) {
            return null;
        }

        return $butir->map(fn ($b) => [
            'kunci' => $b->item_key,
            'grup' => $b->item_group,
            'teks' => $b->content_json,
            'pasangan' => $b->correct_match_key,
            'posisi' => $b->correct_position,
        ])->all();
    }

    /** @return array<int, array<string, mixed>>|null */
    private function rubrik(Question $soal): ?array
    {
        $rubrik = $soal->rubrik;

        if ($rubrik->isEmpty()) {
            return null;
        }

        return $rubrik->map(fn ($r) => [
            'kriteria' => $r->criterion,
            'keterangan' => $r->description,
            'maks' => (float) $r->max_score,
        ])->all();
    }

    /**
     * Penunjuk media, bukan berkasnya.
     *
     * Yang disalin adalah metadata - tipe, mime, jalur simpan, nama asli. Berkas
     * fisiknya tidak digandakan, dan itu disengaja: media milik soal, bukan
     * milik ujian, dan dua salinan berkas berarti menghapus salah satunya akan
     * memutus yang lain.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function media(Question $soal): ?array
    {
        $media = $soal->media;

        if ($media->isEmpty()) {
            return null;
        }

        return $media->map(fn ($m) => [
            'id' => $m->id,
            'tipe' => $m->media_type,
            'mime' => $m->mime_type,
            'nama' => $m->nama_asli,
            'ukuran' => $m->ukuran === null ? null : (int) $m->ukuran,
            'metadata' => $m->metadata_json,
        ])->all();
    }

    /**
     * Jenis soal yang ada di sebuah ujian - dipakai uji dan laporan.
     *
     * @return array<int, string>
     */
    public function jenisDiUjian(Exam $ujian): array
    {
        return ExamQuestion::query()
            ->where('exam_id', $ujian->id)
            ->orderBy('position')
            ->pluck('jenis')
            ->map(fn ($j) => $j instanceof JenisSoal ? $j->value : (string) $j)
            ->unique()
            ->values()
            ->all();
    }
}

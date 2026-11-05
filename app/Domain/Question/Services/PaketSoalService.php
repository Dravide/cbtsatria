<?php

namespace App\Domain\Question\Services;

use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Question\Enums\StatusPaket;
use App\Domain\Question\Models\Question;
use App\Domain\Question\Models\QuestionPackage;
use App\Domain\Question\Models\QuestionPackageItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Satu-satunya penulis `question_packages` dan `question_package_items`.
 *
 * ---------------------------------------------------------------------
 * Paket tidak memiliki soal - ia hanya menunjuknya
 * ---------------------------------------------------------------------
 * Blueprint `:861`: "menghapus paket tidak pernah menghapus soal." Itulah
 * yang membuat bank soal berguna lintas tahun ajaran: satu soal bagus dipakai
 * di tiga ujian berbeda, dan paket yang membawa soalnya pergi saat dibuang
 * akan menghabiskan bank soal satu sekolah dalam dua tahun.
 *
 * Karena itu tidak ada satu baris pun di berkas ini yang menyentuh tabel
 * `questions` selain membacanya.
 *
 * ---------------------------------------------------------------------
 * Paket kosong tidak boleh berstatus siap
 * ---------------------------------------------------------------------
 * Ia ditolak di sini, dan itu penjagaan yang paling murah di seluruh Fase 4.
 * Paket kosong yang berstatus siap akan lolos sampai ke pemasangan event,
 * lalu ke snapshot - dan snapshot-lah yang menolaknya, dengan pesan yang
 * datang jauh setelah Guru menekan tombol yang tampak berhasil.
 */
class PaketSoalService
{
    public function __construct(private readonly ActivityLogger $logger) {}

    /**
     * Buat paket baru.
     *
     * @param  array<string, mixed>  $data
     */
    public function buat(array $data, int $schoolId, int $penulisId): QuestionPackage
    {
        $paket = QuestionPackage::create([
            'school_id' => $schoolId,
            'created_by' => $penulisId,
            'subject_id' => $data['subject_id'],
            'name' => $data['name'],
            'grade_level' => $data['grade_level'] ?? null,
            'description' => $data['description'] ?? null,
            'status' => StatusPaket::Draft->value,
        ]);

        $this->logger->log('Membuat paket soal', $paket, ['nama' => $paket->name]);

        return $paket;
    }

    /**
     * Ubah identitas paket - nama, mapel, tingkat, keterangan.
     *
     * @param  array<string, mixed>  $data
     */
    public function perbarui(QuestionPackage $paket, array $data): QuestionPackage
    {
        $paket->update([
            'subject_id' => $data['subject_id'] ?? $paket->subject_id,
            'name' => $data['name'] ?? $paket->name,
            'grade_level' => $data['grade_level'] ?? $paket->grade_level,
            'description' => $data['description'] ?? $paket->description,
        ]);

        $this->logger->log('Memperbarui paket soal', $paket, ['nama' => $paket->name]);

        return $paket;
    }

    /**
     * Tambahkan sekumpulan soal ke paket.
     *
     * ---------------------------------------------------------------------
     * Mengapa sekumpulan, bukan satu per satu
     * ---------------------------------------------------------------------
     * Wireframe plate 05 menampilkan tombol "Tambah 3 butir" - Guru menandai
     * beberapa soal di panel bank soal lalu menambahkannya sekaligus. Rute
     * satu-per-satu berarti tiga permintaan HTTP, tiga kali daftar paket
     * dibangun ulang, dan tiga kali posisi dihitung ulang. Yang ketiga bisa
     * menghasilkan posisi yang sama untuk dua butir bila keduanya dihitung
     * dari jumlah baris yang sama.
     *
     * @param  array<int, int>  $questionIds
     * @return int Berapa butir yang BENAR-BENAR ditambahkan.
     */
    public function tambahButir(QuestionPackage $paket, array $questionIds, ?array $poin = null): int
    {
        return DB::transaction(function () use ($paket, $questionIds, $poin) {
            // Soal diambil lewat kueri ter-scope, bukan lewat findOrFail
            // berulang: satu kueri untuk semuanya, dan soal dari sekolah lain
            // tidak ditemukan sama sekali - bukan ditolak setelah ditemukan.
            $soal = Question::query()
                ->whereIn('id', $questionIds)
                ->where('subject_id', $paket->subject_id)
                ->get()
                ->keyBy('id');

            $ditolak = array_values(array_diff($questionIds, $soal->keys()->all()));

            if ($ditolak !== []) {
                // Dibedakan: soal yang tidak ada sama sekali (mungkin milik
                // sekolah lain) versus soal yang ada tetapi mapelnya berbeda.
                // Pesan yang menyatukan keduanya membuat Guru mencari-cari
                // soal yang sebenarnya ada di daftarnya.
                $adaDiSekolah = Question::query()->whereIn('id', $ditolak)->count();

                throw ValidationException::withMessages([
                    'question_ids' => $adaDiSekolah > 0
                        ? 'Sebagian soal berasal dari mata pelajaran lain. Paket hanya boleh berisi soal dari '.($paket->subject?->name ?? 'mapel paket ini').'.'
                        : 'Sebagian soal tidak ditemukan.',
                ]);
            }

            $sudahAda = $paket->butir()->pluck('question_id')->all();
            $posisi = (int) $paket->butir()->max('position');

            $ditambah = 0;

            foreach ($questionIds as $id) {
                if (in_array((int) $id, array_map('intval', $sudahAda), true)) {
                    // Duplikat DILEWATI, bukan ditolak: Guru yang menandai
                    // lima soal dan satu di antaranya sudah ada tidak boleh
                    // kehilangan empat yang lain karena satu duplikat.
                    continue;
                }

                QuestionPackageItem::create([
                    'school_id' => $paket->school_id,
                    'question_package_id' => $paket->id,
                    'question_id' => $id,
                    'points_override' => $poin[$id] ?? null,
                    'position' => ++$posisi,
                ]);

                $ditambah++;
            }

            $this->logger->log('Menambah butir ke paket', $paket, [
                'nama' => $paket->name,
                'ditambah' => $ditambah,
            ]);

            // Paket yang baru kehilangan seluruh butirnya tidak boleh tetap
            // berstatus siap - lihat catatan kelas ini.
            $this->turunkanBilaKosong($paket);

            return $ditambah;
        });
    }

    /**
     * Ubah poin satu butir.
     *
     * `null` berarti "kembali ke bobot soalnya", dan itu permintaan yang
     * berbeda dari nol - lihat catatan QuestionPackageItem.
     */
    public function ubahButir(QuestionPackageItem $butir, ?float $poin, ?int $urutan = null): QuestionPackageItem
    {
        $butir->update([
            'points_override' => $poin,
            'position' => $urutan ?? $butir->position,
        ]);

        return $butir;
    }

    /**
     * Susun ulang urutan butir.
     *
     * @param  array<int, int>  $urutan  Id butir, berurutan dari yang pertama.
     */
    public function susunUlang(QuestionPackage $paket, array $urutan): void
    {
        DB::transaction(function () use ($paket, $urutan) {
            $milikPaket = $paket->butir()->pluck('id')->map(fn ($id) => (int) $id)->all();

            $posisi = 0;

            foreach ($urutan as $idButir) {
                if (! in_array((int) $idButir, $milikPaket, true)) {
                    continue;
                }

                QuestionPackageItem::whereKey($idButir)->update(['position' => ++$posisi]);
            }

            $this->logger->log('Menyusun ulang butir paket', $paket, ['nama' => $paket->name]);
        });
    }

    /** Keluarkan satu butir dari paket - soalnya tetap ada di bank soal. */
    public function buangButir(QuestionPackageItem $butir): void
    {
        $paket = $butir->paket;

        $butir->delete();

        if ($paket !== null) {
            $this->logger->log('Mengeluarkan butir dari paket', $paket, ['nama' => $paket->name]);

            $this->turunkanBilaKosong($paket);
        }
    }

    /**
     * Tandai paket siap dipakai - dan tolak bila kosong.
     *
     * @throws ValidationException
     */
    public function tandaiSiap(QuestionPackage $paket): QuestionPackage
    {
        if ($paket->butir()->count() === 0) {
            throw ValidationException::withMessages([
                'status' => 'Paket tanpa soal tidak bisa ditandai siap. Tambahkan butir terlebih dahulu.',
            ]);
        }

        $paket->update(['status' => StatusPaket::Siap->value]);

        $this->logger->log('Menandai paket siap', $paket, ['nama' => $paket->name]);

        return $paket;
    }

    /** Total bobot paket - angka yang dikejar Guru untuk mencapai 100. */
    public function totalBobot(QuestionPackage $paket): float
    {
        return (float) $paket->butir()
            ->with('question:id,scoring_config_json')
            ->get()
            ->sum(fn (QuestionPackageItem $b) => $b->bobotBerlaku());
    }

    private function turunkanBilaKosong(QuestionPackage $paket): void
    {
        if ($paket->status === StatusPaket::Siap && $paket->butir()->count() === 0) {
            $paket->update(['status' => StatusPaket::Draft->value]);
        }
    }
}

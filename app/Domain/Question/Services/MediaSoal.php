<?php

namespace App\Domain\Question\Services;

use App\Domain\Question\Models\QuestionMedia;
use App\Domain\Question\Models\QuestionOption;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Satu-satunya penulis `question_media` - berkas gambar/audio/video soal.
 *
 * =====================================================================
 * Disk PRIVAT, nama diacak, dan mengapa bukan `public`
 * =====================================================================
 * Alasan yang sama dengan `MediaSoalController` dan `QuestionMedia`: satu
 * mapel bisa punya 300 soal di bank soal, dan hanya 40 di antaranya masuk
 * ujian. Berkas yang bisa dibaca siapa pun yang menebak namanya berarti
 * seluruh bank soal satu sekolah bocor lewat gambarnya. Karena itu berkasnya
 * duduk di disk `local` dengan nama `Str::random(40)` + ekstensi yang
 * DIPUTUSKAN server, dan satu-satunya jalan keluarnya adalah rute ber-Gate.
 *
 * ---------------------------------------------------------------------
 * Jenis berkas ditentukan SERVER dari isinya, bukan dari nama unggahan
 * ---------------------------------------------------------------------
 * `UploadedFile::guessExtension()` membaca isi berkas (finfo), bukan
 * ekstensi yang diketik peramban. Nama asli tetap disimpan - tetapi hanya di
 * kolom `nama_asli` untuk ditampilkan ke Guru, tidak pernah sebagai nama di
 * disk. Unggahan bernama `logo.png` yang isinya PHP mendapat ekstensi `.php`
 * dari `guessExtension()` dan langsung ditolak daftar putih di bawah, alih-alih
 * tersimpan dengan ekstensi palsu.
 *
 * ---------------------------------------------------------------------
 * Batas ukuran berbeda per jenis, dan itu bukan kerapian
 * ---------------------------------------------------------------------
 * Gambar pilihan jawaban 2 MB, audio 5 MB, video 20 MB. Satu batas bersama
 * akan membuat gambar 2 MB yang wajar ditolak karena video butuh 20, atau
 * video 20 MB lolos ke soal gambar dan memperlambat laman siswa. Jenisnya
 * sudah diketahui sebelum batasnya diperiksa, jadi batas itu bisa tepat.
 */
class MediaSoal
{
    /**
     * Daftar putih ekstensi, dan jenis + mime yang dipakai untuk menyimpannya.
     *
     * Kuncinya adalah ekstensi hasil `guessExtension()` - yaitu ekstensi yang
     * diturunkan dari ISI berkas. Nilainya `[media_type, mime_type]`.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const JENIS = [
        'jpg' => ['gambar', 'image/jpeg'],
        'jpeg' => ['gambar', 'image/jpeg'],
        'png' => ['gambar', 'image/png'],
        'webp' => ['gambar', 'image/webp'],
        'gif' => ['gambar', 'image/gif'],
        'mp3' => ['audio', 'audio/mpeg'],
        'wav' => ['audio', 'audio/wav'],
        'ogg' => ['audio', 'audio/ogg'],
        'm4a' => ['audio', 'audio/mp4'],
        'aac' => ['audio', 'audio/aac'],
        'mp4' => ['video', 'video/mp4'],
        'webm' => ['video', 'video/webm'],
    ];

    /** Batas ukuran per jenis, dalam kilobyte. */
    private const BATAS_KB = [
        'gambar' => 2048,
        'audio' => 5120,
        'video' => 20480,
    ];

    /** Batas ukuran unggahan mentah sebelum jenisnya diketahui. */
    public const BATAS_UNGGAH_KB = 20480;

    /**
     * Simpan satu berkas untuk satu soal (dan opsional satu pilihan).
     *
     * @param  int|null  $questionId  NULL berarti media DRAF - soal baru yang
     *                                belum tersimpan. Lihat migrasi
     *                                `0014_..._make_question_id_nullable` dan
     *                                `BankSoalService::tautkanDraf()`.
     *
     * @throws ValidationException
     */
    public function simpan(
        UploadedFile $berkas,
        int $schoolId,
        ?int $questionId,
        ?int $optionId,
        ?int $diunggahOleh,
        ?string $optionKey = null,
    ): QuestionMedia {
        $ekstensi = strtolower((string) $berkas->guessExtension());
        $jenis = self::JENIS[$ekstensi] ?? null;

        if ($jenis === null) {
            throw ValidationException::withMessages([
                'berkas' => 'Berkas itu bukan gambar, audio, atau video yang didukung. '
                    .'Gambar: JPG, PNG, WEBP, GIF. Audio: MP3, WAV, OGG, M4A, AAC. Video: MP4, WEBM.',
            ]);
        }

        [$tipe, $mime] = $jenis;

        $batasKb = self::BATAS_KB[$tipe];
        $ukuranKb = (int) ceil($berkas->getSize() / 1024);

        if ($ukuranKb > $batasKb) {
            throw ValidationException::withMessages([
                'berkas' => 'Berkas '.$tipe.' melebihi '.self::ukuranManusia($batasKb).'. Kecilkan berkasnya lebih dulu.',
            ]);
        }

        // Nama di disk: acak + ekstensi yang DIPUTUSKAN server. Nama asli
        // tidak pernah menyentuh disk - lihat catatan kelas.
        $namaDisk = Str::random(40).'.'.$ekstensi;
        $folder = 'soal/'.$schoolId.'/'.($questionId === null ? 'draf' : $questionId);

        $jalur = $berkas->storeAs($folder, $namaDisk, 'local');

        return QuestionMedia::create([
            'school_id' => $schoolId,
            'question_id' => $questionId,
            'option_id' => $optionId,
            'media_type' => $tipe,
            'mime_type' => $mime,
            'storage_path' => $jalur,
            'nama_asli' => $berkas->getClientOriginalName(),
            'ukuran' => (int) $berkas->getSize(),
            'metadata_json' => [
                'diunggah_oleh' => $diunggahOleh,
                // `option_key` ikut dicatat, dan itu yang membuat media draf
                // pilihan bisa ditautkan SEUSAI opsinya ditulis: saat unggahan
                // dilakukan, baris `question_options` belum ada (dan id-nya
                // belum bisa diketahui), jadi id opsi belum bisa dipakai -
                // yang stabil hanyalah huruf pilihannya. Lihat
                // tautkanOpsiDraf() dan BankSoalService.
                'option_key' => $optionKey === null ? null : strtolower($optionKey),
            ],
        ]);
    }

    /**
     * Buang satu berkas beserta barisnya.
     *
     * Baris dihapus DULU, berkas di disk SESUDAH. Urutan sebaliknya
     * meninggalkan baris yang menunjuk berkas yang sudah tidak ada bila
     * penghapusan barisnya gagal - dan baris yatim itu tampil sebagai gambar
     * rusak di builder, bukan sebagai galat yang jelas.
     */
    public function hapus(QuestionMedia $media): void
    {
        $jalur = $media->storage_path;

        $media->delete();

        if ($jalur !== null && Storage::disk('local')->exists($jalur)) {
            Storage::disk('local')->delete($jalur);
        }
    }

    /**
     * Tautkan seluruh media DRAF satu Guru ke soal yang baru disimpan.
     *
     * Dipanggil `BankSoalService::buat()` di dalam transaksi yang sama dengan
     * penyimpanan soalnya, sehingga tidak pernah ada soal tersimpan yang
     * medianya masih menggantung sebagai draf.
     *
     * Yang ditautkan adalah media draf milik SEKOLAH dan yang diunggah oleh
     * Guru itu - dan hanya yang `question_id`-nya masih NULL. Id media yang
     * disebut dikirim dari builder, tetapi diperiksa ulang di sini: tanpa
     * pemeriksaan, Guru yang menebak id media draf sekolahnya sendiri bisa
     * menautkan gambar ke soal yang bukan dibangunnya. Karena draf hanya
     * dibaca lewat rute ber-Gate milik pengunggahnya, ini lapisan kedua.
     *
     * @param  array<int, int|string>  $idMedia
     */
    public function tautkanDraf(array $idMedia, int $schoolId, int $soalId, int $guruId): void
    {
        $id = collect($idMedia)
            ->map(fn ($v) => (int) $v)
            ->filter(fn (int $v) => $v > 0)
            ->unique()
            ->values()
            ->all();

        if ($id === []) {
            return;
        }

        QuestionMedia::tanpaTenant()
            ->where('school_id', $schoolId)
            ->whereNull('question_id')
            ->whereIn('id', $id)
            ->where('metadata_json->diunggah_oleh', $guruId)
            ->update(['question_id' => $soalId]);
    }

    /**
     * Tautkan media draf ke OPSI yang sudah ditulis.
     *
     * Dipanggil `BankSoalService` SESUDAH `tulisAnak()` - karena baris
     * `question_options` baru ada sesudah itu, dan id opsinya baru bisa
     * ditanyakan sekarang. Saat unggahan terjadi, opsi belum punya id; yang
     * dicatat MediaSoal::simpan karenanya `option_key` di metadata, dan di
     * sinilah huruf itu diterjemahkan menjadi `option_id` yang sebenarnya.
     *
     * `question_id` sudah ditautkan `tautkanDraf()` lebih dulu; di sini hanya
     * `option_id` yang diisi. Keduanya tetap difilter per sekolah dan
     * pengunggah, dan `option_id` masih NULL - sehingga media yang sudah
     * tertaut ke opsi lain tidak bisa dipindahkan diam-diam oleh payload.
     *
     * @param  array<int, int|string>  $idMedia
     */
    public function tautkanOpsiDraf(array $idMedia, int $schoolId, int $soalId, int $guruId): void
    {
        $id = collect($idMedia)
            ->map(fn ($v) => (int) $v)
            ->filter(fn (int $v) => $v > 0)
            ->unique()
            ->values()
            ->all();

        if ($id === []) {
            return;
        }

        // Peta option_key -> id untuk soal ini saja. Satu kueri, bukan satu
        // per baris media.
        $petaOpsi = QuestionOption::tanpaTenant()
            ->where('school_id', $schoolId)
            ->where('question_id', $soalId)
            ->pluck('id', 'option_key');

        $media = QuestionMedia::tanpaTenant()
            ->where('school_id', $schoolId)
            ->where('question_id', $soalId)
            ->whereNull('option_id')
            ->whereIn('id', $id)
            ->where('metadata_json->diunggah_oleh', $guruId)
            ->get();

        foreach ($media as $satu) {
            $kunci = strtolower((string) ($satu->metadata_json['option_key'] ?? ''));
            $opsiId = $petaOpsi[$kunci] ?? null;

            if ($opsiId !== null) {
                $satu->update(['option_id' => $opsiId]);
            }
        }
    }

    /**
     * Lepaskan sementara seluruh media OPSI satu soal dari baris opsinya.
     *
     * Dipanggil `BankSoalService::perbarui()` SEBELUM `tulisAnak()`, dan
     * alasannya adalah constraint: `question_media.option_id` memakai
     * `ON DELETE CASCADE` terhadap `question_options`. Menyunting soal
     * menghapus lalu menulis ulang seluruh opsinya, sehingga tanpa langkah ini
     * setiap gambar pilihan jawaban ikut terhapus diam-diam pada setiap
     * penyuntingan - barisnya hilang lewat cascade, berkasnya tertinggal yatim
     * di disk, dan yang tersisa adalah soal bergambar yang gambarnya lenyap.
     *
     * Kembaliannya peta [media_id => option_key]; `tautkanUlangMediaOpsi()`
     * memakainya untuk menyambung lagi ke baris opsi yang baru.
     *
     * @return array<int, string>
     */
    public function lepasMediaOpsi(int $schoolId, int $soalId): array
    {
        $opsi = QuestionOption::tanpaTenant()
            ->where('school_id', $schoolId)
            ->where('question_id', $soalId)
            ->pluck('option_key', 'id');

        if ($opsi->isEmpty()) {
            return [];
        }

        $media = QuestionMedia::tanpaTenant()
            ->where('school_id', $schoolId)
            ->where('question_id', $soalId)
            ->whereIn('option_id', $opsi->keys())
            ->get();

        $peta = [];

        foreach ($media as $satu) {
            $kunci = $opsi->get($satu->option_id);

            if ($kunci === null) {
                continue;
            }

            $peta[(int) $satu->id] = strtolower((string) $kunci);
            $satu->update(['option_id' => null]);
        }

        return $peta;
    }

    /**
     * Sambungkan lagi media opsi yang dilepas ke baris opsi yang BARU.
     *
     * Dicocokkan lewat `option_key`, bukan id: id opsi berubah setiap kali
     * soal disunting (tulis ulang, bukan cocokkan). Media yang huruf
     * opsinya sudah tidak ada - karena Guru membuang pilihan itu - ikut
     * dibuang; kalau dibiarkan dengan `option_id` NULL ia akan tampil sebagai
     * gambar tingkat soal di pratinjau, di tempat yang tidak pernah Guru
     * maksudkan.
     *
     * @param  array<int, string>  $peta  [media_id => option_key]
     */
    public function tautkanUlangMediaOpsi(array $peta, int $schoolId, int $soalId): void
    {
        if ($peta === []) {
            return;
        }

        $opsiBaru = QuestionOption::tanpaTenant()
            ->where('school_id', $schoolId)
            ->where('question_id', $soalId)
            ->pluck('id', 'option_key');

        foreach ($peta as $mediaId => $optionKey) {
            $media = QuestionMedia::tanpaTenant()
                ->where('school_id', $schoolId)
                ->where('id', (int) $mediaId)
                ->whereNull('option_id')
                ->first();

            if ($media === null) {
                continue;
            }

            $opsiId = $opsiBaru[strtolower((string) $optionKey)] ?? null;

            if ($opsiId === null) {
                $this->hapus($media);

                continue;
            }

            $media->update(['option_id' => $opsiId]);
        }
    }

    private static function ukuranManusia(int $kb): string
    {
        return $kb >= 1024 ? round($kb / 1024, 1).' MB' : $kb.' KB';
    }
}

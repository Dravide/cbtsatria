<?php

namespace App\Domain\Exam\Services;

use App\Domain\Event\Contracts\ButirSoalPerMapel;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Models\ExamQuestion;
use App\Domain\Question\Enums\JenisSoal;
use App\Domain\Question\Enums\StatusSoal;
use App\Domain\Question\Models\Question;
use App\Domain\Question\Models\QuestionPackage;
use App\Domain\Question\Models\QuestionPackageItem;
use App\Domain\Tenancy\Scopes\SchoolScope;
use Illuminate\Support\Collection;

/**
 * Soal yang akan dikerjakan siswa, dikelompokkan per mata pelajaran.
 *
 * =====================================================================
 * Mengapa kelas ini ada
 * =====================================================================
 * Sampai 2026-11-14 `event_subjects` hanya menjawab "paket mana terpasang"
 * dan "berapa butir" - dua angka. Ia tidak pernah menjawab pertanyaan yang
 * sebenarnya dibawa Admin ke laman itu: soal APA yang akan dikerjakan 240
 * anak. Admin menandai event siap, membuka sesi, dan mencetak tablo tanpa
 * sekali pun melihat isi paket yang ia setujui.
 *
 * Kelas ini menyusun jawaban itu. Ia tinggal di Exam karena hanya Exam yang
 * boleh melihat `exam_questions` - lihat `ButirSoalPerMapel` tentang mengapa
 * bentuknya kontrak, bukan pemanggilan langsung dari Event.
 *
 * =====================================================================
 * Sumber barisnya: SNAPSHOT bila ada, paket hidup bila belum
 * =====================================================================
 * Yang dikerjakan siswa adalah `exam_questions` - salinan yang dibekukan saat
 * paket dipasang. Tapi belum tentu salinan itu ada: paket boleh dipasang
 * sebelum ujiannya dibuat, dan pada keadaan itu yang berlaku adalah isi paket
 * HIDUP.
 *
 * Keduanya ditampilkan, dan perbedaannya disebut di layar (`keadaan`). Yang
 * tidak boleh terjadi adalah menampilkan isi paket hidup pada event yang
 * ujiannya SUDAH dibekukan: Admin akan menyetujui satu paket dan siswa
 * mengerjakan yang lain.
 *
 * ---------------------------------------------------------------------
 * Satu ujian mewakili satu mapel, dan itu pilihan yang disengaja
 * ---------------------------------------------------------------------
 * Satu mapel bisa punya beberapa ujian di event yang sama (susulan, remedial
 * kelas). Yang dipakai adalah ujian PERTAMA yang punya snapshot: seluruh ujian
 * dari paket yang sama membekukan isi yang sama, sehingga memilih salah
 * satunya tidak mengubah jawaban "soal apa yang dikerjakan".
 *
 * Kalau kelak dua ujian satu mapel memakai paket BERBEDA, laman ini akan
 * menampilkan yang pertama - dan itu terlihat dari kolom paketnya, bukan
 * tersembunyi.
 */
class ButirSoalEvent implements ButirSoalPerMapel
{
    public function susun(SchoolEvent $event): array
    {
        // Mapel event dibaca lewat kolom, bukan relasi - EventSubject sengaja
        // tidak punya relasi `questionPackage()`; lihat docblock-nya.
        $mapel = $event->subjects()->with('subject:id,code,name')->get();

        $paketId = $mapel
            ->pluck('question_package_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $paket = $this->ringkasPaket($paketId->all());
        $snapshot = $this->snapshotPerMapel($event, $mapel->pluck('subject_id')->all());
        $hidup = $this->soalHidup($paketId->all());

        $kelompok = $mapel
            ->map(fn ($baris) => $this->satuKelompok($baris, $paket, $snapshot, $hidup))
            ->sortBy(fn (array $k) => $k['mapel']['kode'] ?? '')
            ->values();

        return [
            'kelompok' => $kelompok->all(),
            'angka' => [
                'mapel' => $kelompok->count(),
                'butir' => (int) $kelompok->sum('butir'),
                'poin' => round((float) $kelompok->sum('poin'), 2),
                'uraian' => (int) $kelompok->sum('uraian'),
                'diarsipkan' => (int) $kelompok->sum('diarsipkan'),
                'basi' => (int) $kelompok->sum('basi'),
            ],
        ];
    }

    /**
     * Satu mapel - dan seluruh percabangannya.
     *
     * @param  array<int, array{nama: string, status: string, butir: int}>  $paket
     * @param  Collection<int, Collection<int, ExamQuestion>>  $snapshot
     * @param  Collection<int, Collection<int, Question>>  $hidup
     * @return array<string, mixed>
     */
    private function satuKelompok($baris, array $paket, Collection $snapshot, Collection $hidup): array
    {
        $idMapel = (int) $baris->subject_id;
        $idPaket = $baris->question_package_id === null ? null : (int) $baris->question_package_id;
        $ringkas = $idPaket === null ? null : ($paket[$idPaket] ?? null);

        $soalSnapshot = $snapshot->get($idMapel);
        $soalHidup = $idPaket === null ? collect() : ($hidup->get($idPaket) ?? collect());

        // Tiga cabang, dan urutannya menentukan: snapshot lebih dulu, karena
        // itulah yang benar-benar dikerjakan siswa pada ujian yang sudah
        // dibekukan.
        [$keadaan, $barisSoal] = match (true) {
            $idPaket === null => ['belum', collect()],
            $soalSnapshot !== null => [
                'terpasang',
                $soalSnapshot->values()->map(fn (ExamQuestion $s, int $i) => $this->dariSnapshot($s, $i + 1)),
            ],
            $soalHidup->isNotEmpty() => [
                'belum_dibekukan',
                $soalHidup->values()->map(fn (QuestionPackageItem $b, int $i) => $this->dariBankSoal($b, $i + 1)),
            ],
            default => ['kosong', collect()],
        };

        $barisSoal = $barisSoal->values();

        return [
            'mapel' => [
                'id' => $idMapel,
                'kode' => $baris->subject?->code,
                'nama' => $baris->subject?->name,
            ],
            'paket' => $ringkas === null ? null : [
                'nama' => $ringkas['nama'],
                'status' => $ringkas['status'],
            ],
            'keadaan' => $keadaan,
            'butir' => $barisSoal->count(),
            'poin' => round((float) $barisSoal->sum('poin'), 2),
            'uraian' => $barisSoal->where('perlu_koreksi', true)->count(),
            'diarsipkan' => $barisSoal->where('diarsipkan', true)->count(),
            'basi' => $barisSoal->where('basi', true)->count(),
            'jenis' => $this->sebaran($barisSoal),
            'baris' => $barisSoal->all(),
        ];
    }

    /**
     * Satu butir SNAPSHOT - yang benar-benar dikerjakan siswa.
     *
     * @return array<string, mixed>
     */
    private function dariSnapshot(ExamQuestion $soal, int $nomor): array
    {
        $jenis = $soal->jenis;

        return $this->bentuk($nomor, [
            'kode' => $soal->kode,
            'judul' => $soal->judul,
            'jenis' => $jenis,
            'tingkat' => $soal->tingkat,
            'poin' => (float) $soal->bobot,
            'status' => null,
            'kunci' => $soal->kunci_json,
            'rubrik' => $soal->rubrik_json,
            'pembahasan' => $soal->pembahasan_json,
            'media' => ($soal->media_json ?? []) !== [] || $this->mediaDiOpsi($soal->opsi_json ?? []),
            'diarsipkan' => false,
            // Basi: bank soal berubah SESUDAH snapshot dibekukan. Yang
            // dikerjakan siswa tetap snapshot lama, sehingga Admin perlu
            // memutuskan - bekukan ulang, atau terima versi lama.
            'basi' => $soal->question_id !== null && $this->basi($soal),
        ]);
    }

    /**
     * Satu butir dari paket HIDUP - event yang ujiannya belum dibekukan.
     *
     * Bentuk barisnya sengaja sama dengan snapshot: laman menampilkan satu
     * daftar, dan yang berbeda hanya dari mana datanya. Tiga nilai di bawah
     * datang dari relasi, karena `Question` menyimpan isinya di tabel anak
     * (`options`, `media`, `rubrik`), bukan di kolom JSON seperti snapshot.
     *
     * Bobotnya diambil dari BUTIR PAKETNYA, bukan dari soalnya: paket boleh
     * menimpa bobot lewat `points_override`, dan pembekuan memakai aturan yang
     * sama - lihat `QuestionPackageItem::bobotBerlaku()`. Membaca bobot soal
     * berarti laman ini menampilkan angka yang berbeda dari yang tertulis di
     * naskah.
     *
     * `basi` selalu false di sini, dan itu bukan kelalaian: soal hidup tidak
     * bisa basi terhadap dirinya sendiri.
     *
     * @return array<string, mixed>
     */
    private function dariBankSoal(QuestionPackageItem $butir, int $nomor): array
    {
        $soal = $butir->question;
        $jenis = $soal->question_type;

        return $this->bentuk($nomor, [
            'kode' => $soal->code,
            'judul' => $soal->title,
            'jenis' => $jenis,
            'tingkat' => $soal->difficulty,
            'poin' => $butir->bobotBerlaku(),
            'status' => $soal->status,
            'kunci' => $soal->answer_key_json,
            'rubrik' => $soal->rubrik->all(),
            'pembahasan' => $soal->explanation_json,
            'media' => $soal->media->isNotEmpty(),
            // Diarsipkan berarti "tidak dipakai lagi untuk ujian berikutnya",
            // bukan "hilang" - soal yang sudah dibekukan tetap sah.
            'diarsipkan' => $soal->statusSoal() === StatusSoal::Arsip,
            'basi' => false,
        ]);
    }

    /**
     * Bentuk baris yang sama untuk kedua sumber.
     *
     * Dipisahkan supaya laman tidak perlu tahu dari mana barisnya datang -
     * dan supaya menambah satu keterangan baru hanya menyentuh satu tempat.
     *
     * @param  array<string, mixed>  $nilai
     * @return array<string, mixed>
     */
    private function bentuk(int $nomor, array $nilai): array
    {
        /** @var JenisSoal $jenis */
        $jenis = $nilai['jenis'];

        return [
            'nomor' => $nomor,
            'kode' => $nilai['kode'],
            'judul' => $nilai['judul'],
            'jenis' => $jenis->value,
            'jenis_label' => $jenis->label(),
            'jenis_pendek' => $jenis->pendek(),
            'tingkat' => $nilai['tingkat'],
            'poin' => round((float) $nilai['poin'], 2),
            'perlu_koreksi' => $jenis->perluRubrik(),
            'status' => $nilai['status'],

            // ----------------------------------------------------------
            // Keterangan yang mengubah "daftar" menjadi "layak diujikan"
            // ----------------------------------------------------------
            // Tanpa ini, laman hanya pratinjau. Dengan ini ia menjawab
            // pertanyaan yang membuat Admin berhenti dan memeriksa.
            'punya_kunci' => ($nilai['kunci'] ?? []) !== [],
            'jumlah_rumpang' => $jenis === JenisSoal::IsianRumpang
                ? count($nilai['kunci']['rumpang'] ?? [])
                : null,
            'punya_media' => (bool) $nilai['media'],
            'punya_rubrik' => ($nilai['rubrik'] ?? []) !== [],
            'punya_pembahasan' => ($nilai['pembahasan'] ?? []) !== [],
            'diarsipkan' => (bool) $nilai['diarsipkan'],
            'basi' => (bool) $nilai['basi'],
        ];
    }

    /**
     * Apakah bank soal berubah sesudah butir ini dibekukan.
     *
     * Satu kueri per butir yang punya `question_id`, dan itu dapat diterima:
     * satu mapel berisi paling banyak empat puluh butir, dan laman ini dibuka
     * Admin - bukan tiga ratus siswa pada menit yang sama. Kalau kelak ia
     * menjadi lambat, yang menggantikannya adalah satu kueri `whereIn` di luar
     * loop, bukan tebakan.
     */
    private function basi(ExamQuestion $soal): bool
    {
        $diperbarui = Question::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->where('id', $soal->question_id)
            ->value('updated_at');

        if ($diperbarui === null || $soal->dibekukan_pada === null) {
            return false;
        }

        return strtotime((string) $diperbarui) > $soal->dibekukan_pada->getTimestamp();
    }

    /**
     * Sebaran jenis untuk batang di kepala kelompok.
     *
     * @param  Collection<int, array<string, mixed>>  $baris
     * @return array<int, array{jenis: string, pendek: string, jumlah: int}>
     */
    private function sebaran(Collection $baris): array
    {
        return $baris
            ->groupBy('jenis')
            ->map(fn (Collection $g, string $jenis) => [
                'jenis' => $jenis,
                'pendek' => $g->first()['jenis_pendek'],
                'jumlah' => $g->count(),
            ])
            ->sortByDesc('jumlah')
            ->values()
            ->all();
    }

    /**
     * Snapshot butir setiap mapel, bila sudah ada.
     *
     * Satu kueri untuk seluruh ujian event, bukan satu per mapel. Kuncinya
     * `subject_id` dari UJIAN, karena `exam_questions` tidak menyimpannya -
     * butir mewarisi mapel dari ujiannya.
     *
     * @param  array<int, int>  $idMapel
     * @return Collection<int, Collection<int, ExamQuestion>>
     */
    private function snapshotPerMapel(SchoolEvent $event, array $idMapel): Collection
    {
        $ujian = Exam::query()
            ->where('event_id', $event->id)
            ->whereIn('subject_id', $idMapel)
            ->whereNotNull('question_package_id')
            ->orderBy('id')
            ->get(['id', 'subject_id']);

        if ($ujian->isEmpty()) {
            return collect();
        }

        $perUjian = ExamQuestion::query()
            ->whereIn('exam_id', $ujian->pluck('id'))
            ->orderBy('position')
            ->get()
            ->groupBy('exam_id');

        // Satu mapel = satu ujian PERTAMA yang punya snapshot - lihat catatan
        // kepala kelas tentang mengapa memilih salah satunya tidak mengubah
        // jawaban "soal apa yang dikerjakan".
        $peta = [];

        foreach ($ujian as $u) {
            $isi = $perUjian->get($u->id);

            if ($isi === null || $isi->isEmpty()) {
                continue;
            }

            $peta[(int) $u->subject_id] ??= $isi;
        }

        return collect($peta);
    }

    /**
     * Isi paket hidup, untuk event yang ujiannya belum dibekukan.
     *
     * Yang dikembalikan adalah BARIS PAKET, bukan soalnya, dan itu disengaja:
     * bobot yang berlaku hidup di butir paket (`points_override`), sehingga
     * memetakannya ke `Question` lebih dulu akan membuang satu-satunya angka
     * yang membuat laman ini sama dengan naskah.
     *
     * Soal yang sudah terhapus dari bank soal dilewati - FK-nya restrict,
     * sehingga seharusnya tidak mungkin, tetapi daftar yang buta terhadap
     * keadaan itu akan menampilkan baris kosong.
     *
     * @param  array<int, int>  $idPaket
     * @return Collection<int, Collection<int, QuestionPackageItem>>
     */
    private function soalHidup(array $idPaket): Collection
    {
        if ($idPaket === []) {
            return collect();
        }

        return QuestionPackageItem::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->whereIn('question_package_id', $idPaket)
            // Rubrik dan media dimuat bersama, karena keduanya relasi per
            // soal: tanpa ini, empat puluh butir berarti delapan puluh kueri.
            ->with(['question.rubrik', 'question.media'])
            ->orderBy('position')
            ->get()
            ->filter(fn (QuestionPackageItem $b) => $b->question !== null)
            ->groupBy('question_package_id');
    }

    /**
     * Nama dan status paket, diindeks id.
     *
     * @param  array<int, int>  $idPaket
     * @return array<int, array{nama: string, status: string, butir: int}>
     */
    private function ringkasPaket(array $idPaket): array
    {
        if ($idPaket === []) {
            return [];
        }

        return QuestionPackage::query()
            ->withoutGlobalScope(SchoolScope::class)
            ->whereIn('id', $idPaket)
            ->withCount('butir')
            ->get()
            ->keyBy('id')
            ->map(fn (QuestionPackage $p) => [
                'nama' => $p->name,
                'status' => $p->status->value,
                'butir' => (int) $p->butir_count,
            ])
            ->all();
    }

    /** @param  array<int, array<string, mixed>>  $opsi */
    private function mediaDiOpsi(array $opsi): bool
    {
        foreach ($opsi as $satu) {
            if (($satu['media'] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }
}

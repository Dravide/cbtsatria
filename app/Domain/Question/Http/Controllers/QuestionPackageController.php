<?php

namespace App\Domain\Question\Http\Controllers;

use App\Domain\Academic\Models\Subject;
use App\Domain\Academic\Models\TeachingAssignment;
use App\Domain\Question\Enums\JenisSoal;
use App\Domain\Question\Models\Question;
use App\Domain\Question\Models\QuestionPackage;
use App\Domain\Question\Models\QuestionPackageItem;
use App\Domain\Question\Services\PaketSoalService;
use App\Support\TabelQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Paket soal - wireframe-peran.html plate 05.
 *
 * =====================================================================
 * Paket adalah KEMASAN, soal adalah ISINYA
 * =====================================================================
 * Blueprint `:861`: "menghapus paket tidak pernah menghapus soal." Karena itu
 * tidak ada satu pun method di sini yang menghapus soal. `buangButir()` hanya
 * memutus rujukannya, dan soalnya tetap ada di bank soal Guru.
 *
 * =====================================================================
 * Panel "Bank soal" di laman detail, dan mengapa bukan halaman terpisah
 * =====================================================================
 * Wireframe plate 05 menaruh daftar butir dan bank soal BERDAMPINGAN, dengan
 * catatan yang menjelaskan mengapa: tanpa itu Guru harus keluar ke bank soal,
 * memilih, menandai paket tujuan, lalu kembali - tiga perpindahan halaman
 * untuk satu pekerjaan yang ia lakukan puluhan kali saat menyusun satu paket.
 *
 * Yang membuat itu mungkin: laman detail mengirim 50 soal pertama bank soal
 * DARI MAPEL PAKET ITU. Paket Matematika tidak menawarkan soal Fisika, karena
 * PaketSoalService::tambahButir() akan menolaknya - dan pemilih yang
 * menawarkan sesuatu yang akan ditolak adalah pemilih yang mengajari Guru
 * untuk tidak mempercayai layarnya.
 */
class QuestionPackageController
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Question::class);

        $user = $request->user();

        $query = QuestionPackage::query()
            ->with(['subject:id,name', 'penulis:id,name'])
            ->withCount('butir')
            // Paket Guru lain TIDAK disembunyikan: ia boleh dibaca, dan Guru
            // yang menyusun ujian paralel ingin tahu paket apa yang sudah ada
            // untuk mapelnya. Yang tidak boleh adalah mengubahnya.
            ->when(
                ! $user->role->isAdmin(),
                fn ($q) => $q->whereIn('subject_id', $this->mapelDiampu($user)),
            );

        $halaman = TabelQuery::buat(
            $query,
            $request,
            cariDi: ['name', 'description'],
            bolehDiurut: ['name', 'status'],
            penyaring: ['mapel' => 'subject_id', 'status' => 'status'],
            urutBawaan: 'id',
            arahBawaan: 'desc',
        );

        return Inertia::render('Guru/Paket/Index', [
            'baris' => $halaman->through(fn (QuestionPackage $paket) => [
                'id' => $paket->id,
                'nama' => $paket->name,
                'mapel' => $paket->subject?->name,
                'tingkat' => $paket->grade_level,
                'status' => $paket->status->value,
                'status_label' => $paket->status->label(),
                'butir' => (int) $paket->butir_count,
                'penulis' => $paket->penulis?->name,
                'milik_saya' => (int) $paket->created_by === (int) $user->id,
            ]),
            'filter' => [
                'cari' => $request->query('cari', ''),
                'mapel' => $request->query('mapel', ''),
                'status' => $request->query('status', ''),
            ],
            'mapel' => $this->pilihanMapel($user),
            'bolehMenulis' => $user->role->value === 'teacher',
        ]);
    }

    public function show(Request $request, QuestionPackage $paket): Response
    {
        if (! Gate::allows('viewPaket', $paket)) {
            abort(404, 'Paket tidak ditemukan.');
        }

        $bolehUbah = Gate::allows('ubahPaket', $paket);

        $paket->load(['subject:id,name', 'penulis:id,name', 'butir.question.penulis:id,name']);

        return Inertia::render('Guru/Paket/Detail', [
            'paket' => [
                'id' => $paket->id,
                'nama' => $paket->name,
                'mapel_id' => $paket->subject_id,
                'mapel' => $paket->subject?->name,
                'tingkat' => $paket->grade_level,
                'keterangan' => $paket->description,
                'status' => $paket->status->value,
                'penulis' => $paket->penulis?->name,
            ],
            'butir' => $paket->butir->map(fn (QuestionPackageItem $b) => [
                'id' => $b->id,
                'question_id' => $b->question_id,
                'kode' => $b->question?->code,
                'judul' => $b->question?->title,
                'jenis_pendek' => $b->question?->question_type->pendek(),
                'jenis_label' => $b->question?->question_type->label(),
                'objektif' => (bool) $b->question?->question_type->otomatis(),
                'poin' => $b->bobotBerlaku(),
                'poin_override' => $b->points_override !== null ? (float) $b->points_override : null,
                'urutan' => $b->position,
                // Soal Guru lain boleh ada di paket ini - Guru A memakai soal
                // Guru B. Tetapi MILIK siapa soalnya harus terbaca: yang
                // hendak menyuntingnya perlu tahu ke siapa ia bertanya.
                'penulis' => $b->question?->penulis?->name,
                'milik_saya' => (int) $b->question?->created_by === (int) $request->user()->id,
            ])->all(),
            'ringkasan' => $this->ringkasan($paket),
            // Panel bank soal - hanya mapel paket ini.
            'bank' => $this->bankSejenis($paket),
            'jenis' => JenisSoal::untukTab(),
            'bolehUbah' => $bolehUbah,
        ]);
    }

    public function store(Request $request, PaketSoalService $service): RedirectResponse
    {
        Gate::authorize('create', Question::class);

        $data = $request->validate([
            'subject_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'grade_level' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = $request->user();

        if ($user->role->value === 'teacher' && ! in_array((int) $data['subject_id'], $this->mapelDiampu($user), true)) {
            throw ValidationException::withMessages([
                'subject_id' => 'Anda tidak mengampu mata pelajaran tersebut.',
            ]);
        }

        $paket = $service->buat($data, (int) $user->school_id, (int) $user->id);

        return redirect()
            ->route('guru.paket.detail', $paket->id)
            ->with('sukses', 'Paket '.$paket->name.' dibuat. Tambahkan butir dari panel bank soal.');
    }

    public function update(Request $request, QuestionPackage $paket, PaketSoalService $service): RedirectResponse
    {
        Gate::authorize('ubahPaket', $paket);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'grade_level' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:1000'],
            // Mapel TIDAK boleh berpindah: butirnya sudah dipilih dari mapel
            // lama, dan memindahkan paket ke mapel lain akan meninggalkan
            // butir yang mapelnya tidak cocok - pelanggaran yang baru
            // ketahuan saat snapshot menolaknya.
            'status' => ['nullable', Rule::in(['draft', 'siap'])],
        ]);

        $service->perbarui($paket, $data);

        if (($data['status'] ?? null) === 'siap') {
            $service->tandaiSiap($paket);
        }

        return back()->with('sukses', 'Paket '.$paket->name.' tersimpan.');
    }

    /**
     * Tambah beberapa butir sekaligus.
     *
     * Rute-nya satu karena wireframe plate 05 memang begitu: "Tambah 3 butir",
     * bukan tiga kali "Tambah 1 butir".
     */
    public function tambahButir(Request $request, QuestionPackage $paket, PaketSoalService $service): RedirectResponse
    {
        Gate::authorize('ubahPaket', $paket);

        $data = $request->validate([
            'question_ids' => ['required', 'array', 'min:1'],
            'question_ids.*' => ['integer'],
            'poin' => ['nullable', 'array'],
        ]);

        $jumlah = $service->tambahButir($paket, $data['question_ids'], $data['poin'] ?? null);

        return back()->with(
            'sukses',
            $jumlah === 0
                ? 'Tidak ada butir baru - semuanya sudah ada di paket ini.'
                : $jumlah.' butir ditambahkan ke paket.',
        );
    }

    public function ubahButir(Request $request, QuestionPackage $paket, QuestionPackageItem $butir, PaketSoalService $service): RedirectResponse
    {
        Gate::authorize('ubahPaket', $paket);

        // Butir harus benar-benar milik paket di URL. Tanpa pemeriksaan ini,
        // id butir paket Guru lain bisa disunting lewat URL paket sendiri -
        // dan binding implisit tidak menolong, karena keduanya sah-sah saja
        // sebagai baris.
        if ((int) $butir->question_package_id !== (int) $paket->id) {
            abort(404, 'Butir tidak ada di paket ini.');
        }

        $data = $request->validate([
            'poin' => ['nullable', 'numeric', 'gt:0'],
            'urutan' => ['nullable', 'integer', 'min:1'],
        ]);

        $service->ubahButir(
            $butir,
            array_key_exists('poin', $data) && $data['poin'] !== null ? (float) $data['poin'] : null,
            $data['urutan'] ?? null,
        );

        return back()->with('sukses', 'Butir diperbarui.');
    }

    public function buangButir(QuestionPackage $paket, QuestionPackageItem $butir, PaketSoalService $service): RedirectResponse
    {
        Gate::authorize('ubahPaket', $paket);

        if ((int) $butir->question_package_id !== (int) $paket->id) {
            abort(404, 'Butir tidak ada di paket ini.');
        }

        $service->buangButir($butir);

        return back()->with('sukses', 'Butir dikeluarkan dari paket. Soalnya tetap ada di bank soal.');
    }

    public function susunUlang(Request $request, QuestionPackage $paket, PaketSoalService $service): RedirectResponse
    {
        Gate::authorize('ubahPaket', $paket);

        $data = $request->validate([
            'urutan' => ['required', 'array'],
            'urutan.*' => ['integer'],
        ]);

        $service->susunUlang($paket, $data['urutan']);

        return back()->with('sukses', 'Urutan butir disimpan.');
    }

    /* ------------------------------------------------------------- Pembantu */

    /** @return array<int, int> */
    private function mapelDiampu($user): array
    {
        return TeachingAssignment::query()
            ->where('teacher_id', $user->id)
            ->distinct()
            ->pluck('subject_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function pilihanMapel($user): array
    {
        return Subject::query()
            ->when(! $user->role->isAdmin(), fn ($q) => $q->whereIn('id', $this->mapelDiampu($user)))
            ->where('status', 'aktif')
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(fn (Subject $m) => ['id' => $m->id, 'nama' => $m->name, 'kode' => $m->code])
            ->all();
    }

    /**
     * Angka-angka di kepala laman detail - wireframe plate 05.
     *
     * @return array<string, mixed>
     */
    private function ringkasan(QuestionPackage $paket): array
    {
        $butir = $paket->butir;

        return [
            'butir' => $butir->count(),
            'poin' => round((float) $butir->sum(fn (QuestionPackageItem $b) => $b->bobotBerlaku()), 2),
            // Objektif dan uraian dipisah karena keduanya menuntut pekerjaan
            // yang berbeda setelah ujian selesai: yang satu dinilai mesin,
            // yang lain menunggu Guru di laman koreksi.
            'objektif' => $butir->filter(fn (QuestionPackageItem $b) => $b->question?->question_type->otomatis())->count(),
            'uraian' => $butir->filter(fn (QuestionPackageItem $b) => ! $b->question?->question_type->otomatis())->count(),
        ];
    }

    /**
     * Bank soal untuk panel samping - mapel paket ini saja.
     *
     * Dibatasi 50 dan TIDAK dipaginasi: panel ini adalah pemilih cepat, dan
     * pemilih yang butuh berpindah halaman berarti Guru menyusun paket sambil
     * menekan "berikutnya" - pekerjaan yang ia lakukan puluhan kali. Yang
     * mencari lebih dari 50 soal memakai kotak cari di panel itu.
     *
     * @return array<int, array<string, mixed>>
     */
    private function bankSejenis(QuestionPackage $paket): array
    {
        $sudah = $paket->butir->pluck('question_id')->all();

        return Question::query()
            ->where('subject_id', $paket->subject_id)
            ->whereNotIn('id', $sudah === [] ? [0] : $sudah)
            ->with('penulis:id,name')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (Question $soal) => [
                'id' => $soal->id,
                'kode' => $soal->code,
                'judul' => $soal->title,
                'jenis_pendek' => $soal->question_type->pendek(),
                'bobot' => (float) ($soal->scoring_config_json['bobot'] ?? 1),
                'penulis' => $soal->penulis?->name,
            ])
            ->all();
    }
}

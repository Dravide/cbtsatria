<?php

namespace App\Domain\Question\Http\Controllers;

use App\Domain\Academic\Models\Subject;
use App\Domain\Academic\Models\TeachingAssignment;
use App\Domain\Question\Enums\JenisSoal;
use App\Domain\Question\Enums\StatusSoal;
use App\Domain\Question\Http\Requests\SimpanSoalRequest;
use App\Domain\Question\Models\Question;
use App\Domain\Question\Services\AmpuanMapel;
use App\Domain\Question\Services\BankSoalService;
use App\Domain\Question\Services\PenulisSoalAi;
use App\Domain\Tenancy\Services\PengaturanAiSekolah;
use App\Support\TabelQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bank soal - wireframe plate 02 dan 03.
 *
 * =====================================================================
 * Daftar ini TIDAK menyaring `created_by`
 * =====================================================================
 * Dan itu yang paling mudah salah. Bank soal adalah ASET SEKOLAH (keputusan
 * PRD §24 #5): Guru A melihat soal Guru B bila mereka mengampu mapel yang
 * sama. Filter `where('created_by', auth()->id())` adalah baris yang tampak
 * benar, masuk akal, dan justru membatalkan keputusan itu - bank soal
 * kembali menjadi catatan pribadi, dan soal yang sama ditulis ulang empat
 * kali oleh empat Guru.
 *
 * Yang menyaring adalah MAPEL: hanya mapel yang diampu Guru yang muncul.
 * Admin melihat semuanya, karena ia yang memasang paket ke event.
 *
 * ---------------------------------------------------------------------
 * Kunci jawaban TIDAK PERNAH ikut ke props daftar
 * ---------------------------------------------------------------------
 * Bukan karena Guru tidak boleh melihatnya - ia boleh, di laman builder.
 * Yang dibuang di sini adalah `answer_key_json` dari payload DAFTAR, karena
 * satu halaman berisi 50 soal berarti 50 kunci yang melintas di jaringan
 * setiap kali Guru menekan Enter di kotak pencarian. Kunci yang tidak
 * dikirim tidak bisa bocor.
 */
class QuestionController
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Question::class);

        $user = $request->user();
        $mapelDiampu = $this->mapelDiampu($user);

        $query = Question::query()
            ->with(['subject:id,name', 'penulis:id,name'])
            // Hitungan pemakaian di paket - kolom "Dipakai" di plate 02.
            ->withCount('pemakaian');

        // Guru hanya melihat soal dari mapel yang ia ampu. Admin melihat
        // seluruh sekolah - lihat catatan kelas ini.
        if (! $user->role->isAdmin()) {
            $query->whereIn('subject_id', $mapelDiampu);
        }

        $halaman = TabelQuery::buat(
            $query,
            $request,
            // Judul dan kode saja. Mencari di dalam `prompt_json` berarti
            // LIKE atas JSON, yang tidak bisa memakai indeks dan mencocokkan
            // potongan markup - "align" akan menemukan setiap soal yang
            // perataannya disetel.
            cariDi: ['title', 'code'],
            bolehDiurut: ['title', 'code', 'question_type', 'status'],
            penyaring: [
                'mapel' => 'subject_id',
                'jenis' => 'question_type',
                'tingkat' => 'difficulty',
                'status' => 'status',
            ],
            urutBawaan: 'id',
            arahBawaan: 'desc',
        );

        return Inertia::render('Guru/BankSoal/Index', [
            'baris' => $halaman->through(fn (Question $soal) => [
                'id' => $soal->id,
                'kode' => $soal->code,
                'judul' => $soal->title,
                // Cuplikan teks soal: dibersihkan dari markup supaya kolom
                // "Soal" menampilkan kalimatnya, bukan tag-nya.
                'cuplikan' => $this->cuplikan($soal),
                'jenis' => $soal->question_type->value,
                'jenis_label' => $soal->question_type->label(),
                'jenis_pendek' => $soal->question_type->pendek(),
                'tingkat' => $soal->difficulty,
                'mapel' => $soal->subject?->name,
                'penulis' => $soal->penulis?->name,
                'dipakai' => (int) $soal->pemakaian_count,
                'status' => $soal->status,
                'milik_saya' => (int) $soal->created_by === (int) $user->id,
            ]),
            'filter' => [
                'cari' => $request->query('cari', ''),
                'mapel' => $request->query('mapel', ''),
                'jenis' => $request->query('jenis', ''),
                'tingkat' => $request->query('tingkat', ''),
                'status' => $request->query('status', ''),
            ],
            'jumlah' => $halaman->total(),
            'mapel' => Subject::query()
                ->when(! $user->role->isAdmin(), fn ($q) => $q->whereIn('id', $mapelDiampu))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Subject $m) => ['id' => $m->id, 'nama' => $m->name])
                ->all(),
            'jenis' => JenisSoal::untukTab(),
            'status' => StatusSoal::untukFilter(),
            'bolehMenulis' => $user->role->value === 'teacher',
            // Generate AI hidup di laman ini sejak 2026-11-10 - lihat
            // Resources/js/Pages/Guru/BankSoal/GenerateAi.tsx. `jenisAi`
            // dibaca dari PenulisSoalAi::JENIS_DIDUKUNG dan bukan ditulis
            // ulang di TypeScript: daftar salinan berhenti diperbarui diam-
            // diam begitu jenis yang didukung bertambah.
            'jenisAi' => $this->jenisAi(),
            'ai' => $this->aiAktif(),
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Question::class);

        $user = $request->user();

        return Inertia::render('Guru/BankSoal/Builder', [
            'soal' => null,
            'mapel' => $this->pilihanMapel($user),
            'jenis' => JenisSoal::untukTab(),
            // Paket tujuan, bila builder dibuka dari laman paket (plate 03:
            // tombol "Simpan & tambah ke paket" hanya muncul di sini).
            'paketTujuan' => $request->integer('paket') ?: null,
        ]);
    }

    public function edit(Request $request, Question $soal): Response
    {
        // 404, BUKAN 403 - dan pembedaan itu yang paling mudah salah.
        //
        // Bank soal kini bersama: Guru A melihat soal Guru B untuk mapel yang
        // sama. Karena soalnya TAMPIL di daftar, "seolah tidak ada" hanya benar
        // untuk Guru yang mapelnya berbeda - dan bagi dia, soalnya memang tidak
        // pernah muncul di daftar. Guru yang membuka tautan soal yang baru saja
        // ia lihat lalu menerima 403 akan mengira datanya rusak, bukan bahwa
        // ia tidak berhak.
        if (! Gate::allows('view', $soal)) {
            abort(404, 'Soal tidak ditemukan.');
        }

        // Mengubah hanya boleh penulisnya - Admin pun tidak (PRD §3.1).
        // Diperiksa TERPISAH dari `view` supaya Guru yang membuka soal Guru
        // lain menerima laman yang sama dengan penandanya, bukan 403: ia
        // berhak melihatnya, dan yang tidak boleh adalah menyimpannya.
        $bolehUbah = Gate::allows('update', $soal);

        // Media tingkat soal dan media per pilihan dimuat lebih dulu supaya
        // builder bisa menampilkannya sebagai gambar, bukan nama berkas.
        $soal->loadMissing(['options.media', 'media']);

        return Inertia::render('Guru/BankSoal/Builder', [
            'soal' => [
                'id' => $soal->id,
                'kode' => $soal->code,
                'judul' => $soal->title,
                'jenis' => $soal->question_type->value,
                'tingkat' => $soal->difficulty,
                'status' => $soal->status,
                'mapel_id' => $soal->subject_id,
                'prompt' => $soal->prompt_json,
                'kunci' => $soal->answer_key_json,
                'skor' => $soal->scoring_config_json,
                'pembahasan' => $soal->explanation_json,
                'media' => $soal->media
                    ->whereNull('option_id')
                    ->map(fn ($m) => [
                        'id' => $m->id,
                        'tipe' => $m->media_type,
                        'mime' => $m->mime_type,
                        'nama' => $m->nama_asli,
                        'ukuran' => (int) $m->ukuran,
                        'url' => route('guru.media', $m->id),
                        'gambar' => $m->berkasGambar(),
                    ])
                    ->values()
                    ->all(),
                'opsi' => $soal->options->map(fn ($o) => [
                    'option_key' => $o->option_key,
                    'content_json' => $o->content_json,
                    'position' => $o->position,
                    'media' => $o->media->map(fn ($m) => [
                        'id' => $m->id,
                        'tipe' => $m->media_type,
                        'mime' => $m->mime_type,
                        'nama' => $m->nama_asli,
                        'ukuran' => (int) $m->ukuran,
                        'url' => route('guru.media', $m->id),
                        'gambar' => $m->berkasGambar(),
                    ])->all(),
                ])->all(),
                'butir' => $soal->items->map(fn ($b) => [
                    'item_key' => $b->item_key,
                    'item_group' => $b->item_group,
                    'content_json' => $b->content_json,
                    'correct_match_key' => $b->correct_match_key,
                    'correct_position' => $b->correct_position,
                    'position' => $b->position,
                ])->all(),
                'rubrik' => $soal->rubrik->map(fn ($r) => [
                    'criterion' => $r->criterion,
                    'description' => $r->description,
                    'max_score' => (float) $r->max_score,
                    'position' => $r->position,
                ])->all(),
                'penulis' => $soal->penulis?->name,
            ],
            'bolehUbah' => $bolehUbah,
            'mapel' => $this->pilihanMapel($request->user()),
            'jenis' => JenisSoal::untukTab(),
            'paketTujuan' => $request->integer('paket') ?: null,
        ]);
    }

    public function store(SimpanSoalRequest $request, BankSoalService $service, AmpuanMapel $ampuan): RedirectResponse
    {
        Gate::authorize('create', Question::class);

        $user = $request->user();
        $data = $request->validated();

        $ampuan->pastikan($user, (int) $data['subject_id']);

        // Kode dibangkitkan service bila Guru tidak menentukannya - dan itu
        // yang paling sering terjadi. Lihat BankSoalService::buat() untuk
        // mengapa ia dibangkitkan di dalam transaksi yang sama, bukan dari
        // sini lewat update() kedua.
        $soal = $service->buat($data, (int) $user->school_id, (int) $user->id);

        return redirect()
            ->route('guru.bank-soal')
            ->with('sukses', 'Soal '.$soal->code.' tersimpan ke bank soal.');
    }

    public function update(SimpanSoalRequest $request, Question $soal, BankSoalService $service, AmpuanMapel $ampuan): RedirectResponse
    {
        Gate::authorize('update', $soal);

        $ampuan->pastikan($request->user(), (int) $request->validated()['subject_id']);

        $soal = $service->perbarui($soal, $request->validated());

        return redirect()
            ->route('guru.bank-soal')
            ->with('sukses', 'Soal '.$soal->code.' tersimpan.');
    }

    /**
     * Duplikat soal menjadi salinan MILIK PENGGUNA.
     *
     * Jalan keluar yang disediakan sengaja: Guru A yang ingin memakai soal
     * Guru B dengan perubahan tidak perlu meminta izin - ia menduplikatnya.
     */
    public function duplikat(Question $soal, BankSoalService $service): RedirectResponse
    {
        Gate::authorize('duplikat', $soal);

        $salinan = $service->duplikat($soal, (int) request()->user()->id);

        return redirect()
            ->route('guru.bank-soal.ubah', $salinan->id)
            ->with('sukses', 'Salinan dibuat sebagai '.$salinan->code.'. Salinan ini milik Anda.');
    }

    public function arsip(Question $soal, BankSoalService $service): RedirectResponse
    {
        Gate::authorize('delete', $soal);

        $service->arsipkan($soal);

        return back()->with('sukses', 'Soal '.$soal->code.' diarsipkan. Soalnya tetap ada dan bisa dicari dengan filter Arsip.');
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

    /**
     * Pilihan mapel di form builder.
     *
     * HANYA mapel yang diampu, dan itu yang membuat aturan izin terlihat di
     * layar alih-alih hanya ditegakkan di server: Guru tidak menemukan mapel
     * yang tidak ia ampu di dalam menu, sehingga permintaan yang ditolak
     * adalah permintaan yang dikirim di luar UI.
     *
     * @return array<int, array<string, mixed>>
     */
    private function pilihanMapel($user): array
    {
        return Subject::query()
            ->when(! $user->role->isAdmin(), fn ($q) => $q->whereIn('id', $this->mapelDiampu($user)))
            ->where('status', 'aktif')
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(fn (Subject $m) => [
                'id' => $m->id,
                'nama' => $m->name,
                'kode' => $m->code,
            ])
            ->all();
    }

    /**
     * Jenis soal yang boleh dihasilkan AI, dengan labelnya.
     *
     * Cermin `PenulisSoalAi::JENIS_DIDUKUNG`, dibaca dari enum yang sama -
     * bukan daftar tetap di sini. Satu daftar yang dipakai modal Generate
     * dan satu yang diperiksa endpoint; kalau keduanya ditulis terpisah,
     * yang pertama akan menawarkan jenis yang ditolak yang kedua.
     *
     * @return array<int, array{nilai: string, label: string, pendek: string}>
     */
    private function jenisAi(): array
    {
        return collect(JenisSoal::untukTab())
            ->whereIn('nilai', PenulisSoalAi::JENIS_DIDUKUNG)
            ->values()
            ->all();
    }

    /**
     * Apakah Generate AI bisa dipakai sekolah ini.
     *
     * Dikirim sebagai boolean saja supaya builder tahu apakah tombolnya
     * dirender. Sekolah yang belum mengatur AI tidak melihat tombol yang
     * mati - mereka melihat satu kalimat yang menyebut apa yang harus
     * dilakukan Admin.
     */
    private function aiAktif(): bool
    {
        return (bool) app(PengaturanAiSekolah::class)->ambil()?->siap();
    }

    /** Cuplikan teks, dibersihkan dari markup. */
    private function cuplikan(Question $soal): string
    {
        $teks = (string) ($soal->prompt_json['teks'] ?? '');

        return mb_substr(trim(strip_tags($teks)), 0, 120);
    }
}

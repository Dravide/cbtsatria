<?php

namespace App\Domain\Question\Http\Controllers;

use App\Domain\Academic\Models\Subject;
use App\Domain\Question\Enums\JenisSoal;
use App\Domain\Question\Enums\StatusSoal;
use App\Domain\Question\Models\Question;
use App\Domain\Question\Models\QuestionMedia;
use App\Support\TabelQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bank soal versi ADMIN - baca saja. `/admin/bank-soal`, 2026-11-14.
 *
 * =====================================================================
 * Mengapa laman ini ada, padahal `/guru/bank-soal` sudah ada
 * =====================================================================
 * Admin yang memasang paket ke event (plate 15) dan menandai event Siap
 * bertanggung jawab atas isi paket yang ia setujui - tetapi pintu untuk
 * melihatnya ada di konsol Guru, dengan sidebar Guru, menu Builder, Impor,
 * dan Generate AI. Admin yang menekan "Buka bank soal" dari laman soal per
 * mapel mendarat di konsol yang bukan miliknya, dan di sana ia melihat
 * tombol-tombol yang server tolak (403) - sebab PRD §3.1 memberi Admin
 * "lihat semua" tetapi bukan "tulis soal".
 *
 * Laman ini adalah pintu itu tanpa konsol Guru: daftar soal sekolah, dengan
 * panel pratinjau. TIDAK ada builder, impor, AI, atau tombol ubah.
 *
 * =====================================================================
 * Mengapa controllernya di modul Question, bukan Admin
 * =====================================================================
 * Peta modul (`DomainBoundariesTest`) tidak punya panah `Admin -> Question`,
 * dan menambahkannya berarti modul Admin berhenti bisa diuji tanpa bank soal.
 * Yang menyeberang batas karena itu tetap rutenya saja: alamatnya
 * `/admin/bank-soal`, tetapi controllernya milik Question - sama seperti
 * `Attendance` yang menyajikan laman siswa di bawah `/siswa/*`.
 *
 * =====================================================================
 * Izinnya `viewAny` + `view`, bukan `update`
 * =====================================================================
 * `QuestionPolicy::view()` sudah memberi Admin seluruh sekolah (PRD §3.1:
 * "lihat semua"). Yang TIDAK dikirim ke laman ini adalah `bolehUbah` - bukan
 * karena Admin tidak berhak, melainkan karena laman ini memang tidak punya
 * satu pun tombol tulis. Laman yang mengirim izin yang tidak dipakainya
 * adalah laman yang mengundang tombol itu ditambahkan nanti tanpa
 * memikirkan policy-nya.
 */
class BankSoalAdminController
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Question::class);

        $cari = trim((string) $request->query('cari', ''));

        $semuaMapel = Subject::query()
            ->when($cari !== '', function ($q) use ($cari): void {
                $pola = '%'.$cari.'%';
                $q->where(fn ($sub) => $sub
                    ->where('name', 'like', $pola)
                    ->orWhere('code', 'like', $pola));
            })
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        $agregatPerMapel = Question::query()
            ->selectRaw('subject_id, COUNT(*) as total, COUNT(DISTINCT created_by) as guru')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as siap', [StatusSoal::Siap->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as draft', [StatusSoal::Draft->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as arsip', [StatusSoal::Arsip->value])
            ->groupBy('subject_id')
            ->get()
            ->keyBy('subject_id');

        $dipakaiPerMapel = Question::query()
            ->has('pemakaian')
            ->selectRaw('subject_id, COUNT(*) as dipakai')
            ->groupBy('subject_id')
            ->pluck('dipakai', 'subject_id');

        $jenisPerMapel = Question::query()
            ->selectRaw('subject_id, question_type, COUNT(*) as jumlah')
            ->groupBy('subject_id', 'question_type')
            ->get()
            ->groupBy('subject_id')
            ->map(fn ($items) => $items->mapWithKeys(fn ($row) => [
                $row->question_type instanceof JenisSoal ? $row->question_type->value : (string) $row->question_type => (int) $row->jumlah,
            ])->all());

        $daftarMapel = $semuaMapel->map(function (Subject $m) use ($agregatPerMapel, $dipakaiPerMapel, $jenisPerMapel): array {
            $agg = $agregatPerMapel->get($m->id);
            $total = (int) ($agg->total ?? 0);

            return [
                'id' => $m->id,
                'kode' => $m->code,
                'nama' => $m->name,
                'total' => $total,
                'siap' => (int) ($agg->siap ?? 0),
                'draft' => (int) ($agg->draft ?? 0),
                'arsip' => (int) ($agg->arsip ?? 0),
                'guru' => (int) ($agg->guru ?? 0),
                'dipakai' => (int) ($dipakaiPerMapel->get($m->id) ?? 0),
                'sebaran' => $jenisPerMapel->get($m->id, []),
            ];
        })->values()->all();

        $ringkasan = [
            'mapel' => Subject::query()->count(),
            'soal' => (int) $agregatPerMapel->sum('total'),
            'siap' => (int) $agregatPerMapel->sum('siap'),
            'dipakai' => (int) $dipakaiPerMapel->sum(),
        ];

        return Inertia::render('Admin/BankSoal', [
            'mapel' => $daftarMapel,
            'ringkasan' => $ringkasan,
            'filter' => [
                'cari' => $cari,
            ],
            'pengguna' => $request->user()->name,
        ]);
    }

    public function mapel(Request $request, Subject $mapel): Response
    {
        Gate::authorize('viewAny', Question::class);

        $idTerpilih = (int) $request->query('soal', 0);

        $halaman = TabelQuery::buat(
            Question::query()
                ->where('subject_id', $mapel->id)
                ->with(['subject:id,name', 'penulis:id,name'])
                ->withCount('pemakaian'),
            $request,
            cariDi: ['title', 'code'],
            bolehDiurut: ['title', 'code', 'question_type', 'status'],
            penyaring: [
                'jenis' => 'question_type',
                'tingkat' => 'difficulty',
                'status' => 'status',
            ],
            urutBawaan: 'id',
            arahBawaan: 'desc',
        );

        $terpilih = null;

        if ($idTerpilih > 0) {
            $soal = Question::query()
                ->where('subject_id', $mapel->id)
                ->find($idTerpilih);

            if ($soal !== null && Gate::allows('view', $soal)) {
                $terpilih = $this->pratinjau($soal);
            }
        }

        $idTerbuka = $terpilih === null ? null : $idTerpilih;

        return Inertia::render('Admin/BankSoalMapel', [
            'mapel' => [
                'id' => $mapel->id,
                'kode' => $mapel->code,
                'nama' => $mapel->name,
            ],
            'baris' => $halaman->through(fn (Question $soal) => [
                'id' => $soal->id,
                'kode' => $soal->code,
                'judul' => $soal->title,
                'cuplikan' => mb_substr(trim(strip_tags((string) ($soal->prompt_json['teks'] ?? ''))), 0, 120),
                'jenis' => $soal->question_type->value,
                'jenis_label' => $soal->question_type->label(),
                'jenis_pendek' => $soal->question_type->pendek(),
                'tingkat' => $soal->difficulty,
                'penulis' => $soal->penulis?->name,
                'dipakai' => (int) $soal->pemakaian_count,
                'status' => $soal->status,
                'status_label' => $soal->statusSoal()->label(),
            ]),
            'terpilih' => $terpilih,
            'filter' => [
                'cari' => $request->query('cari', ''),
                'jenis' => $request->query('jenis', ''),
                'tingkat' => $request->query('tingkat', ''),
                'status' => $request->query('status', ''),
                'soal' => $idTerbuka,
            ],
            'jumlah' => $halaman->total(),
            'jenis' => JenisSoal::untukTab(),
            'status' => StatusSoal::untukFilter(),
            'pengguna' => $request->user()->name,
        ]);
    }

    /**
     * Bentuk satu soal untuk panel pratinjau.
     *
     * Kunci, rubrik, dan pembahasan IKUT - dan itu yang membedakannya dari
     * payload siswa. Yang membaca di sini adalah Admin yang menilai apakah
     * paket ini layak diujikan, dan pertanyaan "kuncinya ada atau tidak"
     * tidak bisa dijawab tanpa melihatnya.
     *
     * Media dikirim sebagai TAUTAN ke rute pembacanya, bukan jalur disk:
     * jalur di disk adalah nama acak yang tidak berarti apa pun bagi
     * peramban, dan mengirimkannya berarti peramban mencoba membukanya
     * sebagai URL relatif lalu gagal tanpa galat yang terlihat.
     *
     * @return array<string, mixed>
     */
    private function pratinjau(Question $soal): array
    {
        $soal->load(['options.media', 'items', 'rubrik', 'media', 'penulis:id,name', 'subject:id,name']);

        return [
            'id' => $soal->id,
            'kode' => $soal->code,
            'judul' => $soal->title,
            'jenis' => $soal->question_type->value,
            'jenis_label' => $soal->question_type->label(),
            'tingkat' => $soal->difficulty,
            'status' => $soal->status,
            'status_label' => $soal->statusSoal()->label(),
            'mapel' => $soal->subject?->name,
            'penulis' => $soal->penulis?->name,
            'prompt' => $soal->prompt_json,
            'kunci' => $soal->answer_key_json,
            'skor' => $soal->scoring_config_json,
            'pembahasan' => $soal->explanation_json,
            'pemakaian' => $soal->pemakaian()->count(),
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
            // Rute MEDIA GURU, dan itu satu-satunya tautan lintas konsol yang
            // tersisa di laman ini - ia rute BERKAS, bukan laman. Membuat
            // rute pembaca kedua berarti dua tempat yang menyajikan berkas
            // yang sama, dengan dua izin yang bisa berbeda.
            'url' => route('guru.media', $m->id),
            'gambar' => $m->berkasGambar(),
        ];
    }
}

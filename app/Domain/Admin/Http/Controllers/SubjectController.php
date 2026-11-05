<?php

namespace App\Domain\Admin\Http\Controllers;

use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Models\Subject;
use App\Domain\Academic\Models\TeachingAssignment;
use App\Domain\Academic\Services\AcademicYearService;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Support\TabelQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mata pelajaran + penugasan mengajar - wireframe plate 08.
 *
 * ---------------------------------------------------------------------
 * Mengapa penugasan mengajar ada di laman ini, bukan di laman Guru
 * ---------------------------------------------------------------------
 * Ini keputusan yang tampak seperti pilihan tata letak, dan bukan.
 *
 * Satu baris di `teaching_assignments` adalah satu IZIN: guru A boleh menyusun
 * soal untuk mapel B di kelas C. Tabel itu milik mapel - ia tidak punya arti
 * tanpa mapel dan kelas, sementara guru hanya salah satu kolomnya.
 *
 * Kalau penugasan dipindahkan ke laman Guru, Admin harus membuka dua layar
 * untuk menjawab satu pertanyaan yang sebenarnya satu: "siapa yang mengajar
 * MTK di X-1?" - dan jawabannya harus dibaca dari dua tempat yang bisa tidak
 * sinkron. Di laman ini Admin melihat mapel dan penugasannya berdampingan.
 *
 * Akibatnya di Fase 4: bank soal Guru dibatasi oleh tabel ini, bukan oleh
 * daftar yang disimpan di laman Guru. Sumber kebenarannya satu.
 */
class SubjectController
{
    public function index(Request $request, AcademicYearService $service): Response
    {
        Gate::authorize('viewAny', Subject::class);

        $query = Subject::query();

        $halaman = TabelQuery::buat(
            $query,
            $request,
            cariDi: ['code', 'name', 'category'],
            bolehDiurut: ['code', 'name', 'category'],
            penyaring: ['status' => 'status'],
            urutBawaan: 'code',
        );

        // Penugasan hanya untuk mapel yang tampil di halaman ini.
        $mapelIds = $halaman->pluck('id');

        $penugasan = TeachingAssignment::query()
            ->whereIn('subject_id', $mapelIds)
            ->with(['teacher:id,name', 'kelas:id,name'])
            ->get()
            ->groupBy('subject_id');

        return Inertia::render('Admin/Mapel', [
            'baris' => $halaman->through(fn (Subject $mapel) => [
                'id' => $mapel->id,
                'kode' => $mapel->code,
                'nama' => $mapel->name,
                'kelompok' => $mapel->category,
                'tingkat' => $mapel->grade_level,
                'status' => $mapel->status,
                'penugasan' => ($penugasan[$mapel->id] ?? collect())
                    ->map(fn (TeachingAssignment $p) => [
                        'id' => $p->id,
                        'guru' => $p->teacher?->name,
                        'kelas' => $p->kelas?->name,
                    ])->values()->all(),
            ]),
            'filter' => [
                'cari' => $request->query('cari', ''),
                'status' => $request->query('status', ''),
            ],
            'jumlah' => $halaman->total(),
            'guru' => User::query()
                ->where('role', Role::Teacher)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $u) => ['id' => $u->id, 'nama' => $u->name])
                ->all(),
            // Kelas dari tahun ajaran aktif saja: menugaskan guru ke kelas
            // tahun lalu adalah kesalahan yang mudah terjadi dan sulit
            // terlihat, jadi pilihannya pun dibatasi.
            'kelas' => SchoolClass::query()
                ->where('academic_year_id', optional($service->aktif())->id)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (SchoolClass $k) => ['id' => $k->id, 'nama' => $k->name])
                ->all(),
            // Peringatan bila belum ada tahun ajaran aktif: tanpa itu,
            // daftar kelas kosong dan penugasan tidak bisa dibuat - dan
            // Admin perlu tahu mengapa, bukan menebak.
            'tahunAjaranAktif' => optional($service->aktif())->name,
        ]);
    }

    public function store(Request $request, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('create', Subject::class);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:60'],
            'grade_level' => ['nullable', 'string', 'max:10'],
            'status' => ['required', 'in:aktif,nonaktif'],
        ], [], ['code' => 'kode mapel', 'name' => 'nama mapel']);

        $mapel = Subject::create($data + ['school_id' => $request->user()->school_id]);

        $logger->log('Menambah mata pelajaran', $mapel, ['kode' => $mapel->code]);

        return back()->with('sukses', 'Mata pelajaran '.$mapel->name.' ditambahkan.');
    }

    public function update(Request $request, Subject $mapel, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('update', $mapel);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:60'],
            'grade_level' => ['nullable', 'string', 'max:10'],
            'status' => ['required', 'in:aktif,nonaktif'],
        ]);

        $mapel->update($data);

        $logger->log('Memperbarui mata pelajaran', $mapel, ['kode' => $mapel->code]);

        return back()->with('sukses', 'Mata pelajaran tersimpan.');
    }

    /**
     * Tugaskan guru pada satu mapel di satu kelas.
     */
    public function tugaskan(Request $request, Subject $mapel, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('update', $mapel);

        $data = $request->validate([
            'teacher_id' => ['required', 'integer'],
            'class_id' => ['required', 'integer'],
        ]);

        // Keduanya dicari dulu, bukan diserahkan ke basis data. Composite FK
        // akan menolak kombinasi lintas sekolah, tetapi galatnya adalah
        // "foreign key constraint fails" - dan itu bukan kalimat yang bisa
        // ditindaklanjuti Admin. findOrFail menghasilkan 404, yang benar:
        // guru atau kelas itu memang tidak ada di sekolah ini.
        $guru = User::query()
            ->where('role', Role::Teacher)
            ->findOrFail($data['teacher_id']);

        $kelas = SchoolClass::findOrFail($data['class_id']);

        $penugasan = TeachingAssignment::firstOrCreate(
            [
                'school_id' => $mapel->school_id,
                'teacher_id' => $guru->id,
                'subject_id' => $mapel->id,
                'class_id' => $kelas->id,
            ],
            ['academic_year_id' => $kelas->academic_year_id],
        );

        if ($penugasan->wasRecentlyCreated) {
            $logger->log('Menambah penugasan mengajar', $penugasan, [
                'guru' => $guru->name,
                'mapel' => $mapel->name,
                'kelas' => $kelas->name,
            ]);
        }

        return back()->with('sukses', $guru->name.' ditugaskan pada '.$mapel->name.' di '.$kelas->name.'.');
    }

    public function batalkan(TeachingAssignment $penugasan, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('update', $penugasan);

        $keterangan = [
            'guru' => $penugasan->teacher?->name,
            'mapel' => $penugasan->subject?->name,
        ];

        // Baris penugasan memang boleh hilang: ia menyatakan siapa mengajar
        // sekarang, bukan siapa pernah mengajar. Riwayatnya ada di log
        // aktivitas, yang justru tidak boleh hilang.
        $penugasan->delete();

        $logger->log('Membatalkan penugasan mengajar', null, $keterangan);

        return back()->with('sukses', 'Penugasan dibatalkan.');
    }
}

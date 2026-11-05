<?php

namespace App\Domain\Admin\Http\Controllers;

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Academic\Services\AcademicYearService;
use App\Domain\Audit\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tahun ajaran & semester - wireframe plate 06.
 *
 * ---------------------------------------------------------------------
 * Peringatan penutupan ditulis di laman ini, bukan di dialog konfirmasi
 * ---------------------------------------------------------------------
 * "Menutup tahun ajaran tidak menghapus data. Kelas, penugasan, dan event lama
 * diarsipkan dan tetap dapat dibuka sebagai arsip."
 *
 * Kalimat itu ada di badan laman, bukan di kotak konfirmasi yang dibaca
 * sambil mengklik. Alasannya: keputusan paling berisiko di laman ini bukan
 * klik "Tutup" - melainkan keyakinan Admin bahwa menutup berarti menghapus.
 * Keyakinan itu terbentuk sebelum ia mengklik apa pun, dan karena itu
 * penjelasannya harus ada sebelum ia mengklik apa pun.
 *
 * ---------------------------------------------------------------------
 * Semester tampil sebagai kotak bersarang
 * ---------------------------------------------------------------------
 * Wireframe menampilkan "Semester di 2026/2027" di dalam laman yang sama,
 * bukan sebagai laman terpisah. Itu mengikuti bentuk datanya: semester selalu
 * milik satu tahun ajaran, dan memisahkannya menjadi laman sendiri berarti
 * menawarkan semester yang tidak punya induk.
 */
class AcademicYearController
{
    public function index(): Response
    {
        Gate::authorize('viewAny', AcademicYear::class);

        $tahun = AcademicYear::query()
            ->with(['semesters' => fn ($q) => $q->orderBy('start_date')])
            // Urut menurun: yang sedang dipakai ada di atas, dan tahun ajaran
            // baru selalu lebih relevan daripada yang lama.
            ->orderByDesc('start_date')
            ->get();

        return Inertia::render('Admin/TahunAjaran', [
            'tahun' => $tahun->map(fn (AcademicYear $t) => [
                'id' => $t->id,
                'nama' => $t->name,
                'mulai' => $t->start_date?->format('d/m/Y'),
                'selesai' => $t->end_date?->format('d/m/Y'),
                'aktif' => $t->is_active,
                // "Arsip" bukan kolom: ia keadaan yang diturunkan. Tahun
                // ajaran yang sudah lewat tanggal selesainya dan tidak aktif
                // adalah arsip, dan tidak ada cara menyatakannya secara
                // keliru lewat sebuah flag.
                'arsip' => ! $t->is_active && $t->end_date?->isPast(),
                'semester' => $t->semesters->map(fn (Semester $s) => [
                    'id' => $s->id,
                    'nama' => $s->name,
                    'mulai' => $s->start_date?->format('d/m/Y'),
                    'selesai' => $s->end_date?->format('d/m/Y'),
                    'aktif' => $s->is_active,
                ])->all(),
            ])->all(),
        ]);
    }

    public function store(Request $request, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('create', AcademicYear::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:20', 'regex:/^\d{4}\/\d{4}$/'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
        ], [], [
            'name' => 'nama tahun ajaran',
            'start_date' => 'tanggal mulai',
            'end_date' => 'tanggal selesai',
        ]);

        $tahun = AcademicYear::create($data + ['school_id' => $request->user()->school_id]);

        $logger->log('Menambah tahun ajaran', $tahun, ['nama' => $tahun->name]);

        return back()->with('sukses', 'Tahun ajaran '.$tahun->name.' ditambahkan.');
    }

    public function update(Request $request, AcademicYear $tahunAjaran, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('update', $tahunAjaran);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:20', 'regex:/^\d{4}\/\d{4}$/'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
        ]);

        $tahunAjaran->update($data);

        $logger->log('Memperbarui tahun ajaran', $tahunAjaran);

        return back()->with('sukses', 'Tahun ajaran tersimpan.');
    }

    public function aktifkan(AcademicYear $tahunAjaran, AcademicYearService $service, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('update', $tahunAjaran);

        $service->aktifkan($tahunAjaran);

        $logger->log('Mengaktifkan tahun ajaran', $tahunAjaran, ['nama' => $tahunAjaran->name]);

        return back()->with('sukses', 'Tahun ajaran '.$tahunAjaran->name.' kini aktif.');
    }

    public function tutup(AcademicYear $tahunAjaran, AcademicYearService $service, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('update', $tahunAjaran);

        $service->tutup($tahunAjaran);

        $logger->log('Menutup tahun ajaran', $tahunAjaran, ['nama' => $tahunAjaran->name]);

        return back()->with('sukses', 'Tahun ajaran ditutup. Data lama tetap dapat dibuka sebagai arsip.');
    }

    public function storeSemester(Request $request, AcademicYear $tahunAjaran, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('update', $tahunAjaran);

        $data = $request->validate([
            'name' => ['required', Rule::in(['Ganjil', 'Genap'])],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
        ]);

        $semester = $tahunAjaran->semesters()->create($data + [
            'school_id' => $tahunAjaran->school_id,
        ]);

        $logger->log('Menambah semester', $semester, ['nama' => $semester->name]);

        return back()->with('sukses', 'Semester '.$semester->name.' ditambahkan.');
    }

    public function aktifkanSemester(Semester $semester, AcademicYearService $service, ActivityLogger $logger): RedirectResponse
    {
        // Semester tidak punya Policy sendiri: ia selalu dilihat lewat tahun
        // ajaran induknya, dan induk itulah yang punya izin. Memeriksa
        // induknya di sini menjaga jumlah Policy tetap sebanding dengan
        // jumlah keputusan yang benar-benar berbeda.
        Gate::authorize('update', $semester->academicYear);

        $service->aktifkanSemester($semester);

        $logger->log('Mengaktifkan semester', $semester, ['nama' => $semester->name]);

        return back()->with('sukses', 'Semester '.$semester->name.' kini aktif.');
    }
}

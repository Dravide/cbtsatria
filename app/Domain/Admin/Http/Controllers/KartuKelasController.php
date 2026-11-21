<?php

namespace App\Domain\Admin\Http\Controllers;

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Services\AcademicYearService;
use App\Domain\Admin\Services\KartuWaliKelas;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Tenancy\Models\School;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Kartu pantau wali kelas - satu PDF, atau satu ZIP berisi banyak PDF.
 *
 * Mengikuti pola `KartuAkunController`: satu kelas menghasilkan satu PDF
 * inline (siap pratinjau/cetak), lebih dari satu kelas menghasilkan arsip
 * ZIP berisi satu PDF per kelas.
 */
class KartuKelasController
{
    public const MAKSIMAL = 500;

    public function unduh(
        Request $request,
        KartuWaliKelas $kartu,
        AcademicYearService $tahunService,
        ActivityLogger $logger,
    ): HttpResponse|BinaryFileResponse|RedirectResponse {
        Gate::authorize('viewAny', SchoolClass::class);

        $data = $request->validate([
            'kelas' => ['required_without:semua', 'array', 'max:'.self::MAKSIMAL],
            'kelas.*' => ['integer'],
            'semua' => ['nullable', 'boolean'],
            'ta' => ['nullable', 'integer'],
            'cari' => ['nullable', 'string', 'max:255'],
        ]);

        $semua = (bool) ($data['semua'] ?? false);

        if (! $semua && empty($data['kelas'] ?? [])) {
            return back()->with('galat', 'Pilih dulu kelas yang kartunya dicetak.');
        }

        $query = SchoolClass::query()->with([
            'academicYear:id,name',
            'homeroomTeacher:id,name',
        ]);

        if ($semua) {
            $taId = $data['ta'] ?? null;

            if ($taId === null) {
                $tahunAktif = $tahunService->aktif()
                    ?? AcademicYear::query()->orderByDesc('start_date')->first();
                $taId = $tahunAktif?->id;
            }

            if ($taId !== null) {
                $query->where('academic_year_id', (int) $taId);
            }

            $cari = trim((string) ($data['cari'] ?? ''));

            if ($cari !== '') {
                $query->where(function ($q) use ($cari) {
                    $q->where('name', 'like', '%'.$cari.'%')
                        ->orWhere('grade_level', 'like', '%'.$cari.'%');
                });
            }
        } else {
            $query->whereIn('id', $data['kelas']);
        }

        $kelas = $query->orderBy('name')->limit(self::MAKSIMAL + 1)->get();

        if ($kelas->isEmpty()) {
            return back()->with('galat', 'Tidak ada kelas pada pilihan itu.');
        }

        if ($kelas->count() > self::MAKSIMAL) {
            return back()->with(
                'galat',
                'Pilihan itu memuat lebih dari '.self::MAKSIMAL.' kelas. Saring dulu, lalu cetak lagi.',
            );
        }

        $sekolahModel = School::query()->find($request->user()->school_id);
        $kartuKelas = $kartu->susun($kelas, $sekolahModel?->papan_slug);
        $sekolah = $kartu->kop($sekolahModel);

        $logger->log('Mencetak kartu pantau wali kelas', null, ['jumlah' => count($kartuKelas)]);

        if (count($kartuKelas) === 1) {
            $satu = $kartuKelas[0];

            return response($kartu->pdf($satu, $sekolah), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$kartu->namaBerkas($satu).'"',
            ]);
        }

        if (count($kartuKelas) > 1 && function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        $jalur = $kartu->zip($kartuKelas, $sekolah);

        return response()
            ->download($jalur, 'kartu-pantau-wali-'.now()->format('Ymd').'.zip', [
                'Content-Type' => 'application/zip',
            ])
            ->deleteFileAfterSend(true);
    }
}

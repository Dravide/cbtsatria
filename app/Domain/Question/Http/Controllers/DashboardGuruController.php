<?php

namespace App\Domain\Question\Http\Controllers;

use App\Domain\Academic\Models\TeachingAssignment;
use App\Domain\Question\Models\Question;
use App\Domain\Question\Models\QuestionPackage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Beranda konsol Guru - wireframe-peran.html plate 01.
 *
 * =====================================================================
 * Empat KPI di wireframe, dua di antaranya tidak dirender - dan itu disengaja
 * =====================================================================
 * Plate 01 menampilkan empat kotak: "Soal saya 412", "Paket 9", "Perlu
 * koreksi 112", "Kelas diampu 4".
 *
 * Dua yang pertama bisa dihitung Fase 4, dan dihitung di sini. Dua yang
 * terakhir tidak:
 *
 *   - "Perlu koreksi" menghitung jawaban uraian yang belum dinilai. Jawaban
 *     siswa baru ada setelah mesin ujian (Fase 7), dan koreksinya Fase 8.
 *     Angkanya akan benar-benar nol - bukan karena tidak ada pekerjaan,
 *     melainkan karena belum ada yang bisa dikerjakan.
 *   - "Jadwal mengajar hari ini" menuntut jam ujian, dan itu properti SESI,
 *     bukan properti Guru. Seorang Guru tidak "mengajar pukul 07:30" di
 *     aplikasi ini; ia mengawas ruang yang jamnya ditentukan Admin di
 *     jadwal event.
 *
 * =====================================================================
 * Mengapa angka nol yang BENAR lebih berbahaya daripada angka yang salah
 * =====================================================================
 * "Perlu koreksi: 0" pada Fase 4 berarti "tidak ada yang perlu dikoreksi".
 * Yang sebenarnya terjadi: belum ada yang BISA dikoreksi. Dua keadaan itu
 * berbentuk sama di layar, dan Guru yang membacanya menyimpulkan kelasnya
 * sudah beres - kesimpulan yang salah, dari angka yang benar. Karena itu
 * kotaknya tidak dirender sama sekali sampai Fase 8.
 *
 * KPI yang tetap dirender semuanya dihitung benar sejak Fase 4. Tidak ada
 * satu pun yang di-hardcode nol.
 */
class DashboardGuruController
{
    public function __invoke(Request $request): Response
    {
        // Gate-nya `viewAny` atas Question, bukan pemeriksaan peran yang
        // ditulis ulang di sini: middleware `guru` sudah menyaring perannya
        // sebelum controller ini berjalan, dan menulis ulang aturannya berarti
        // aturan yang sama hidup di dua tempat.
        Gate::authorize('viewAny', Question::class);

        $user = $request->user();

        return Inertia::render('Guru/Dashboard', [
            'ringkasan' => [
                // "Soal SAYA" - dan di sinilah `created_by` memang memfilter.
                // Bank soal bersama, tetapi KPI ini menjawab pertanyaan
                // "berapa yang saya tulis", bukan "berapa yang bisa saya
                // pakai". Dua angka yang berbeda, dan yang pertama yang
                // diminta plate 01.
                'soal' => Question::query()->where('created_by', $user->id)->count(),

                // Paket yang saya susun. Paket Guru lain ada dan bisa dipakai
                // (lewat pemasangan Admin), tetapi tidak dihitung di sini
                // dengan alasan yang sama.
                'paket' => QuestionPackage::query()->where('created_by', $user->id)->count(),

                // Kelas yang saya ampu - bukan jumlah penugasan. Guru yang
                // mengajar Matematika dan Fisika di kelas X-1 mengampu SATU
                // kelas, bukan dua, dan `distinct` itulah bedanya.
                'kelas' => TeachingAssignment::query()
                    ->where('teacher_id', $user->id)
                    ->distinct()
                    ->count('class_id'),
            ],
        ]);
    }
}

<?php

namespace App\Domain\Admin\Http\Controllers;

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\ClassEnrollment;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Services\AcademicYearService;
use App\Domain\Academic\Services\PenempatanKelas;
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
 * Kelas - wireframe plate 07.
 *
 * ---------------------------------------------------------------------
 * Pemilih tahun ajaran ada di bar atas, bukan di dalam form
 * ---------------------------------------------------------------------
 * Wireframe menaruh `TA 2026/2027` di bar atas laman, dan alasannya bukan
 * estetika:
 *
 *   "Berganti TA berarti berganti daftar kelas, bukan menyaring daftar
 *    yang sama."
 *
 * Perbedaan itu menentukan bentuk request-nya. Pemilih TA mengubah URL
 * (?ta=12) dan memuat ulang daftar dari server; ia bukan penyaring di sisi
 * klien yang menyembunyikan sebagian baris. Kalau ia diletakkan di dalam form
 * bersama pencarian, ia akan tampak seperti penyaring - dan Admin akan
 * mengira "X-1 tidak ada" padahal X-1 hanya milik tahun ajaran lain.
 *
 * ---------------------------------------------------------------------
 * Kelas tanpa tahun ajaran tidak ada
 * ---------------------------------------------------------------------
 * Laman ini tidak menawarkan pilihan "semua tahun ajaran". Daftar kelas
 * kosong pada tahun ajaran yang kosong adalah jawaban yang benar, dan itu
 * yang membuat perbedaan antar tahun terlihat.
 */
class SchoolClassController
{
    public function index(Request $request, AcademicYearService $service): Response
    {
        Gate::authorize('viewAny', SchoolClass::class);

        $daftarTahun = AcademicYear::query()->orderByDesc('start_date')->get();

        // Tahun ajaran yang dibuka: dari URL bila ada, jika tidak yang aktif,
        // jika tidak yang terbaru. Tiga langkah itu membuat laman ini selalu
        // punya jawaban meski sekolah belum pernah mengaktifkan apa pun.
        $taId = $request->query('ta');
        $tahunDipilih = $taId
            ? $daftarTahun->firstWhere('id', (int) $taId)
            : ($service->aktif() ?? $daftarTahun->first());

        if ($tahunDipilih === null) {
            return Inertia::render('Admin/Kelas', [
                'tahunDipilih' => null,
                'daftarTahun' => [],
                'baris' => ['data' => [], 'total' => 0],
                'wali' => [],
                'jumlahSiswa' => [],
            ]);
        }

        $query = SchoolClass::query()
            ->where('academic_year_id', $tahunDipilih->id)
            ->with('homeroomTeacher:id,name');

        $halaman = TabelQuery::buat(
            $query,
            $request,
            cariDi: ['name', 'grade_level'],
            bolehDiurut: ['name', 'grade_level'],
            urutBawaan: 'name',
        );

        // Satu query untuk jumlah anggota seluruh kelas di halaman ini,
        // bukan satu query per baris.
        $jumlahSiswa = ClassEnrollment::query()
            ->whereIn('class_id', $halaman->pluck('id'))
            ->where('status', 'aktif')
            ->selectRaw('class_id, count(*) as jumlah')
            ->groupBy('class_id')
            ->pluck('jumlah', 'class_id');

        return Inertia::render('Admin/Kelas', [
            'tahunDipilih' => [
                'id' => $tahunDipilih->id,
                'nama' => $tahunDipilih->name,
                'aktif' => $tahunDipilih->is_active,
            ],
            'daftarTahun' => $daftarTahun->map(fn (AcademicYear $t) => [
                'id' => $t->id,
                'nama' => $t->name,
                'aktif' => $t->is_active,
            ])->all(),
            'baris' => $halaman->through(fn (SchoolClass $kelas) => [
                'id' => $kelas->id,
                'nama' => $kelas->name,
                'tingkat' => $kelas->grade_level,
                'wali' => $kelas->homeroomTeacher?->name,
                'siswa' => (int) ($jumlahSiswa[$kelas->id] ?? 0),
                'status' => $kelas->status,
            ]),
            // Daftar wali kelas dibatasi peran Teacher: menawarkan seluruh
            // pengguna berarti menawarkan siswa sebagai wali kelas, dan itu
            // pilihan yang tidak pernah benar.
            'wali' => User::query()
                ->where('role', Role::Teacher)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $u) => ['id' => $u->id, 'nama' => $u->name])
                ->all(),
            'filter' => ['cari' => $request->query('cari', '')],
        ]);
    }

    public function store(Request $request, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('create', SchoolClass::class);

        $data = $request->validate([
            'academic_year_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:40'],
            'grade_level' => ['nullable', 'string', 'max:10'],
            'homeroom_teacher_id' => ['nullable', 'integer'],
            'status' => ['required', 'in:aktif,nonaktif'],
        ]);

        // findOrFail, bukan find: id tahun ajaran dari request harus
        // benar-benar ada di sekolah ini. Scope-nya yang memutuskan - id
        // sekolah lain tidak ditemukan, dan hasilnya 404.
        $tahun = AcademicYear::findOrFail($data['academic_year_id']);

        $kelas = SchoolClass::create([
            ...$data,
            'school_id' => $tahun->school_id,
        ]);

        $logger->log('Menambah kelas', $kelas, ['nama' => $kelas->name]);

        return back()->with('sukses', 'Kelas '.$kelas->name.' ditambahkan.');
    }

    public function update(Request $request, SchoolClass $kelas, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('update', $kelas);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:40'],
            'grade_level' => ['nullable', 'string', 'max:10'],
            'homeroom_teacher_id' => ['nullable', 'integer'],
            'status' => ['required', 'in:aktif,nonaktif'],
        ]);

        $kelas->update($data);

        $logger->log('Memperbarui kelas', $kelas, ['nama' => $kelas->name]);

        return back()->with('sukses', 'Kelas tersimpan.');
    }

    /**
     * Kelola anggota kelas.
     *
     * Yang ditampilkan hanya siswa yang AKTIF dan belum berada di kelas lain
     * pada tahun ajaran yang sama. Menawarkan seluruh siswa berarti
     * menawarkan pilihan yang pasti ditolak unique constraint, dan galat
     * basis data bukan cara yang baik menjelaskan aturan kepada Admin.
     */
    public function anggota(SchoolClass $kelas): Response
    {
        Gate::authorize('view', $kelas);

        $terdaftar = ClassEnrollment::query()
            ->where('class_id', $kelas->id)
            ->where('status', 'aktif')
            ->pluck('student_id');

        // Siswa yang sudah punya kelas di tahun ajaran ini, di kelas mana pun.
        $sudahAdaKelas = ClassEnrollment::query()
            ->where('academic_year_id', $kelas->academic_year_id)
            ->where('status', 'aktif')
            ->pluck('student_id');

        $kandidat = User::query()
            ->where('role', Role::Student)
            ->where('is_active', true)
            ->whereNotIn('id', $sudahAdaKelas)
            ->orderBy('name')
            ->get(['id', 'name', 'username']);

        return Inertia::render('Admin/KelasAnggota', [
            'kelas' => [
                'id' => $kelas->id,
                'nama' => $kelas->name,
                'tahun' => $kelas->academicYear?->name,
            ],
            'anggota' => User::query()
                ->whereIn('id', $terdaftar)
                ->orderBy('name')
                ->get(['id', 'name', 'username'])
                ->map(fn (User $u) => ['id' => $u->id, 'nama' => $u->name, 'username' => $u->username])
                ->all(),
            'kandidat' => $kandidat->map(fn (User $u) => [
                'id' => $u->id,
                'nama' => $u->name,
                'username' => $u->username,
            ])->all(),
        ]);
    }

    /**
     * Simpan daftar anggota kelas.
     *
     * ---------------------------------------------------------------------
     * Penulisannya lewat PenempatanKelas, dan itu memperbaiki satu lubang
     * ---------------------------------------------------------------------
     * Sampai Fase 10 method ini adalah SATU-SATUNYA kode yang menulis
     * `class_enrollments`, dan ia menulis sendiri: siswa yang keluar ditandai
     * `pindah`, siswa yang masuk ditulis `updateOrCreate`. Yang tidak
     * dilakukannya adalah menutup keanggotaan AKTIF siswa itu di kelas LAIN
     * pada tahun ajaran yang sama - sehingga memindahkan siswa dari X-1 ke
     * X-2 lewat layar ini meninggalkan X-1 tetap aktif, dan siswa yang sama
     * muncul di dua daftar hadir.
     *
     * `PenempatanKelas` melakukan keduanya dalam satu transaksi, dan impor
     * pengguna memakai jalur yang sama - jadi aturannya tinggal di satu
     * tempat. Perubahan perilakunya disengaja: perpindahan lewat layar kini
     * ikut menutup keanggotaan lama, sama seperti perpindahan lewat impor.
     */
    public function simpanAnggota(
        Request $request,
        SchoolClass $kelas,
        ActivityLogger $logger,
        PenempatanKelas $penempatan,
    ): RedirectResponse {
        Gate::authorize('update', $kelas);

        $data = $request->validate([
            'id' => ['required', 'array'],
            'id.*' => ['integer'],
        ]);

        // Timpa seluruh keanggotaan kelas ini dengan daftar yang dikirim.
        // Bentuk "simpan daftar" lebih mudah dipercaya daripada tambah/hapus
        // terpisah: sesudah request ini, isi kelas persis sama dengan yang
        // terlihat di layar, tanpa perlu menebak apa yang tadi tidak jadi
        // dikirim.
        //
        // Yang dibandingkan adalah anggota AKTIF, bukan seluruh baris. Versi
        // lama memakai semua baris, dan akibatnya siswa yang pernah keluar
        // dari kelas ini lalu didaftarkan kembali tidak pernah benar-benar
        // aktif lagi - barisnya ada, jadi ia tidak dianggap "baru", tetapi
        // statusnya tetap `pindah`.
        $aktif = ClassEnrollment::query()
            ->where('class_id', $kelas->id)
            ->where('status', 'aktif')
            ->pluck('student_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $dikirim = array_values(array_unique(array_map('intval', $data['id'])));

        $masuk = array_values(array_diff($dikirim, $aktif));
        $keluar = array_values(array_diff($aktif, $dikirim));

        $penempatan->keluarkan($kelas, $keluar);

        // Hanya yang BARU ditempatkan. Siswa yang sudah aktif di kelas ini
        // tidak butuh penulisan apa pun, dan memang tidak boleh diberi satu:
        // `tempatkan()` untuk mereka akan menjadi UPDATE yang tidak mengubah
        // apa-apa, satu per siswa, di setiap penekanan Simpan.
        foreach ($masuk as $studentId) {
            $penempatan->tempatkan($studentId, $kelas);
        }

        $logger->log('Memperbarui anggota kelas', $kelas, [
            'nama' => $kelas->name,
            'masuk' => count($masuk),
            'keluar' => count($keluar),
        ]);

        return back()->with('sukses', count($dikirim).' siswa terdaftar di '.$kelas->name.'.');
    }
}

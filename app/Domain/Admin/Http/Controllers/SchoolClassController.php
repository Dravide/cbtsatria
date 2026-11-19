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
use App\Domain\Tenancy\Models\School;
use App\Support\TabelQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
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

        // Slug SEKOLAH dipakai bersama `/papan` dan `/pantau`: satu sekolah
        // satu alamat publik. Dibaca SEKALI, bukan per baris - seluruh kelas
        // di halaman ini milik sekolah yang sama, dan `papan_slug` bisa
        // NULL (sekolah yang tidak pernah menyalakan papannya), sehingga
        // tautan wali pun tidak punya alamat yang bisa disusun.
        $papanSlug = School::query()->whereKey($request->user()->school_id)->value('papan_slug');

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

                // Laman pantau wali - `wireframe-pantau-wali.html`.
                //
                // Tiga prop, dan yang ketiga bukan hiasan: saklar foto
                // HANYA benar bila sekolah mengizinkannya. Laman yang
                // menyalakan tombolnya sendiri akan menampilkan tombol yang
                // server tolak - dan tombol yang ditolak lebih buruk
                // daripada tombol yang tidak ada.
                'wali_slug' => $kelas->wali_slug,
                'wali_tayang' => $kelas->wali_slug !== null,
                'wali_foto' => (bool) $kelas->wali_foto,

                // ALAMAT dan KODE-nya ikut dikirim, dan keduanya bukan
                // kelonggaran.
                //
                // Alamat: tautan yang tidak bisa dibaca Admin adalah tautan
                // yang harus ia susun sendiri dari potongan yang ia ingat -
                // dan `/wali/x-1-a3f9` tidak bisa disusun dari ingatan.
                //
                // Kode: kolomnya `[Hidden]` supaya tidak pernah ikut
                // serialisasi model mana pun, TETAPI prop ini ditulis
                // eksplisit. Alasannya sama dengan
                // `EventCommitteeController::index()` yang mengirim kode
                // pengawas setiap kali: Admin mencetaknya menjadi kartu dan
                // harus bisa membacanya kapan saja. Kode yang hanya muncul
                // SEKALI pada respons penerbitannya memaksa Admin
                // menerbitkan ulang setiap kali ia lupa menyalinnya - dan
                // penerbitan ulang mengganti kode yang mungkin sudah
                // beredar di grup wali kelas.
                'wali_url' => $kelas->wali_slug === null || $papanSlug === null
                    ? null
                    : url("/wali/{$papanSlug}/kelas/{$kelas->id}"),
                'wali_kode' => $kelas->wali_code,
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

    /**
     * Nyalakan atau matikan pantau wali untuk satu kelas.
     *
     * =====================================================================
     * Slug lahir di sini, dan hanya dari nama kelas
     * =====================================================================
     * Alamat internet yang bisa ditentukan peramban adalah alamat yang suatu
     * hari diisi sesuatu yang bukan nama kelas. Pola dan alasannya sama
     * dengan `PengaturanPapanController::update()` yang membangkitkan
     * `schools.papan_slug`.
     *
     * Mematikan TIDAK menghapus slug maupun kodenya. Menghapusnya berarti
     * alamat lama menjadi 404 selamanya, dan menyalakan kembali menghasilkan
     * alamat baru yang harus ditempel ulang di grup wali kelas.
     *
     * Saklar foto diperiksa TERHADAP IZIN SEKOLAH: kelas tidak boleh
     * menyalakan foto yang sekolahnya belum izinkan. Pesan penolakannya
     * menyebut laman mana yang harus dibuka lebih dulu.
     */
    public function pantau(Request $request, SchoolClass $kelas, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('update', $kelas);

        $data = $request->validate([
            'tayang' => ['required', 'boolean'],
            'foto' => ['nullable', 'boolean'],
        ]);

        $sekolah = School::findOrFail($kelas->school_id);
        $tayang = (bool) $data['tayang'];
        $foto = (bool) ($data['foto'] ?? false);

        if ($foto && ! (bool) $sekolah->wali_foto_boleh) {
            return back()->with(
                'galat',
                'Sekolah ini belum mengizinkan foto berita acara tayang di laman wali. '.
                'Nyalakan izinnya lebih dulu di Pengaturan → Papan publik.',
            );
        }

        $kolom = ['wali_foto' => $tayang && $foto];

        if ($tayang && $kelas->wali_slug === null) {
            $kolom['wali_slug'] = $this->slugUnik($kelas);
        }

        $kelas->forceFill($kolom)->save();

        $logger->log($tayang ? 'Menyalakan pantau wali' : 'Mematikan pantau wali', $kelas, [
            'kelas' => $kelas->name,
            'foto' => $kolom['wali_foto'],
        ]);

        return back()->with('sukses', $tayang
            ? "Pantau wali kelas {$kelas->name} dinyalakan."
            : "Pantau wali kelas {$kelas->name} dimatikan.");
    }

    /**
     * Terbitkan ulang kode kelas - enam angka, terenkripsi.
     *
     * Menerbitkan ulang TIDAK mengganti slug: tautan yang sudah ditempel di
     * grup tetap berlaku, dan hanya kodenya yang berganti. Mengganti
     * keduanya berarti tiga puluh orang tua harus menemukan tautan barunya
     * di tengah ujian.
     */
    public function kodeWali(SchoolClass $kelas, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('update', $kelas);

        if ($kelas->wali_slug === null) {
            return back()->with('galat', 'Nyalakan pantau wali lebih dulu sebelum menerbitkan kode.');
        }

        $kode = $this->kodeBaru();

        $kelas->forceFill(['wali_code' => $kode, 'wali_code_at' => now()])->save();

        $logger->log('Menerbitkan kode pantau wali', $kelas, ['kelas' => $kelas->name]);

        // Kode dikirim balik lewat flash supaya Admin bisa langsung
        // menyalinnya. Ia TIDAK ikut di props daftar: kolomnya `[Hidden]`
        // dan laman tidak pernah menerimanya dalam keadaan biasa.
        return back()->with('sukses', "Kode pantau wali {$kelas->name}: {$kode}");
    }

    /**
     * Kode kelas enam angka.
     *
     * Satu method, bukan `str_pad(random_int(...))` yang disalin ke dua
     * tempat: kode ini dibagikan lewat grup wali kelas dan diketik ulang
     * orang tua, jadi bentuknya - enam digit, nol di depan dipertahankan -
     * adalah kontrak. Dua salinan berarti dua bentuk yang suatu hari
     * berbeda.
     */
    private function kodeBaru(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Slug unik untuk satu kelas.
     *
     * Empat huruf acak di belakang, pola yang sama dengan
     * `PapanPengawas::slugUnik()`: slug yang hanya dibangkitkan dari nama
     * kelas akan bertabrakan pada dua sekolah dengan "X-1", dan rute publik
     * ini di-resolve TANPA konteks tenant - sehingga tabrakan berarti laman
     * sekolah lain yang tayang.
     */
    private function slugUnik(SchoolClass $kelas): string
    {
        $awalan = Str::slug($kelas->name) ?: 'kelas';

        do {
            $slug = Str::limit($awalan, 40, '').'-'.Str::lower(Str::random(4));
        } while (SchoolClass::query()->withoutGlobalScopes()->where('wali_slug', $slug)->exists());

        return $slug;
    }

    /**
     * Nyalakan pantau wali untuk SELURUH kelas di satu tahun ajaran.
     *
     * =====================================================================
     * Per kelas di transaksinya sendiri, dan hasilnya BERPASANGAN
     * =====================================================================
     * Satu transaksi untuk tiga puluh kelas berarti satu slug yang gagal
     * bentrok membatalkan dua puluh sembilan yang sah - dan Admin melihat
     * "gagal" tanpa tahu yang mana. Pola yang sama dengan
     * `SesiService::siapkanBanyak()` dan `ImporPenggunaService`.
     *
     * =====================================================================
     * Foto TIDAK ikut dinyalakan
     * =====================================================================
     * Aksi massal ini hanya membuka tautan dan menerbitkan kode. Foto
     * berita acara memuat wajah siswa lain, dan ia TIDAK BOLEH menyala
     * sebagai akibat sampingan dari tombol yang berbunyi "nyalakan semua" -
     * yang ditekan orang yang sedang memikirkan tautan, bukan wajah.
     *
     * =====================================================================
     * Kode yang sudah ada TIDAK diganti
     * =====================================================================
     * Kelas yang sudah tayang dilewati seluruhnya, bukan diterbitkan ulang
     * kodenya. Kode yang sudah beredar di grup wali kelas harus tetap
     * berlaku; aksi massal yang menggantinya akan memutus tiga puluh
     * percakapan yang sudah selesai.
     */
    public function pantauMassal(Request $request, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('create', SchoolClass::class);

        $data = $request->validate([
            'academic_year_id' => ['required', 'integer'],
        ], [], ['academic_year_id' => 'tahun ajaran']);

        $tahun = AcademicYear::findOrFail($data['academic_year_id']);

        $kelas = SchoolClass::query()
            ->where('academic_year_id', $tahun->id)
            ->orderBy('name')
            ->get();

        if ($kelas->isEmpty()) {
            return back()->with('galat', "Tidak ada kelas di tahun ajaran {$tahun->name}.");
        }

        $dinyalakan = 0;
        $dilewati = 0;
        $gagal = [];

        foreach ($kelas as $satu) {
            if ($satu->wali_slug !== null) {
                $dilewati++;

                continue;
            }

            try {
                $satu->forceFill([
                    'wali_slug' => $this->slugUnik($satu),
                    'wali_code' => $this->kodeBaru(),
                    'wali_code_at' => now(),
                ])->save();

                $dinyalakan++;
            } catch (\Throwable $e) {
                report($e);

                $gagal[] = $satu->name.': '.mb_substr($e->getMessage(), 0, 120, 'UTF-8');
            }
        }

        $logger->log('Menyalakan pantau wali secara massal', $tahun, [
            'tahun' => $tahun->name,
            'dinyalakan' => $dinyalakan,
            'dilewati' => $dilewati,
            'gagal' => count($gagal),
        ]);

        $pesan = "{$dinyalakan} kelas dinyalakan.";

        if ($dilewati > 0) {
            $pesan .= " {$dilewati} kelas sudah tayang dan dilewati - kodenya tidak diganti.";
        }

        if ($gagal !== []) {
            $pesan .= ' '.count($gagal).' kelas gagal: '.implode('; ', array_slice($gagal, 0, 3))
                .(count($gagal) > 3 ? ' dan '.(count($gagal) - 3).' lainnya' : '').'.';
        }

        return back()->with($dinyalakan > 0 || $gagal === [] ? 'sukses' : 'galat', $pesan);
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

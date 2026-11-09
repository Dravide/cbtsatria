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
use Illuminate\Support\Collection;
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
 *
 * ---------------------------------------------------------------------
 * Mengapa dikelompokkan per tingkat, dan mengapa TIDAK dipaginasi
 * ---------------------------------------------------------------------
 * Sekolah menengah punya tiga tingkat dan puluhan mapel - "Tingkat 7, 8, 9"
 * dengan dua belas mapel masing-masing adalah bentuk yang biasa. Daftar
 * rata membuatnya tiga ribu piksel ke bawah untuk pekerjaan yang sebenarnya
 * tiga keputusan, dan yang keempat biasanya terlewat.
 *
 * Karena itu barisnya dikelompokkan `grade_level`, dan setiap kelompok bisa
 * dilipat.
 *
 * Paginasi justru DILEPAS di sini, dan itu bukan kelalaian: "Tingkat 7 · 12
 * mapel" adalah angka yang harus benar. Menghitungnya dari satu halaman
 * menghasilkan kelompok yang berkepala "12 mapel" sementara di bawahnya ada
 * tiga baris - angka yang salah, dan Admin membacanya sebagai data hilang.
 * Yang menggantikan paginasi di sini adalah AKORDEONNYA: yang membuat daftar
 * seratus mapel bisa dijelajahi adalah kelompok yang bisa ditutup, bukan
 * halaman kedua yang harus ditemukan lebih dulu.
 *
 * Batasnya dinyatakan jujur: seluruh mapel sekolah dimuat. Pada skala satu
 * sekolah (puluhan sampai ratusan baris) itu tetap satu query yang murah;
 * bila suatu saat menjadi puluhan ribu, yang menggantikannya adalah
 * paginasi DI DALAM kelompok, bukan paginasi yang memotong kelompoknya.
 */
class SubjectController
{
    public function index(Request $request, AcademicYearService $service): Response
    {
        Gate::authorize('viewAny', Subject::class);

        // Penyaringan lewat `TabelQuery::saring()` - aturan "cari" yang sama
        // persis dengan laman master lain. Disalin ke sini berarti dua
        // salinan `%kata%` di tiga kolom yang akan berbeda suatu hari, dan
        // yang berbeda adalah tempat Admin mencari.
        $mapel = TabelQuery::saring(
            Subject::query(),
            $request,
            cariDi: ['code', 'name', 'category'],
            penyaring: ['status' => 'status'],
        )
            ->orderBy('code')
            ->get();

        // Penugasan hanya untuk mapel yang benar-benar tampil. Satu query
        // untuk seluruh halaman, bukan satu query per baris.
        $penugasan = TeachingAssignment::query()
            ->whereIn('subject_id', $mapel->pluck('id'))
            ->with(['teacher:id,name', 'kelas:id,name'])
            ->get()
            ->groupBy('subject_id');

        // Kelas dari tahun ajaran aktif saja: menugaskan guru ke kelas tahun
        // lalu adalah kesalahan yang mudah terjadi dan sulit terlihat, jadi
        // pilihannya pun dibatasi. Query-nya dipakai dua kali di bawah -
        // sekali untuk daftar kelas, sekali untuk katalog tingkat - dan
        // dibaca SEKALI ke memori karena jumlahnya puluhan, bukan ribuan.
        $daftarKelas = SchoolClass::query()
            ->where('academic_year_id', optional($service->aktif())->id)
            ->orderBy('name')
            ->get(['id', 'name', 'grade_level']);

        return Inertia::render('Admin/Mapel', [
            // Daftar rata, dan ia TETAP dikirim meski layarnya memakai
            // `kelompok`. Bentuk inilah yang dibaca test dan ekspor, dan
            // menurunkannya kembali dari kelompok berarti mengurai kembali
            // apa yang baru saja disusun.
            'baris' => $mapel
                ->map(fn (Subject $s) => $this->ringkas($s, $penugasan))
                ->values()
                ->all(),

            // Baris yang sama, dikelompokkan tingkatnya. Dihitung di sini,
            // bukan di React: `grade_level` nullable, dan mapel tanpa tingkat
            // harus JATUH ke kelompoknya sendiri - bukan menghilang dari
            // layar karena tidak cocok dengan kelompok mana pun.
            'kelompok' => $this->kelompokMapel($mapel, $penugasan),

            'filter' => [
                'cari' => $request->query('cari', ''),
                'status' => $request->query('status', ''),
            ],
            'jumlah' => $mapel->count(),
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
            'kelas' => $daftarKelas
                ->map(fn (SchoolClass $k) => ['id' => $k->id, 'nama' => $k->name])
                ->values()
                ->all(),
            // Katalog tingkat untuk penugasan massal. Dihitung di sini, bukan
            // dikelompokkan di React: `grade_level` nullable, dan kelas tanpa
            // tingkat harus JATUH dari pilihan tingkat - bukan muncul sebagai
            // opsi kosong yang menugaskan "tingkat hampa". Aturan itu milik
            // data, jadi tempatnya di sini.
            'tingkat' => $this->katalogTingkat($daftarKelas),
            // Peringatan bila belum ada tahun ajaran aktif: tanpa itu,
            // daftar kelas kosong dan penugasan tidak bisa dibuat - dan
            // Admin perlu tahu mengapa, bukan menebak.
            'tahunAjaranAktif' => optional($service->aktif())->name,
        ]);
    }

    /**
     * Satu baris mapel seperti yang dibaca layar.
     *
     * @param  Collection<int, Collection<int, TeachingAssignment>>  $penugasan
     * @return array<string, mixed>
     */
    private function ringkas(Subject $mapel, Collection $penugasan): array
    {
        return [
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
        ];
    }

    /**
     * Mapel dikelompokkan tingkatnya, untuk akordeon plate 08.
     *
     * Setiap kelompok membawa JUMLAH MAPEL dan JUMLAH PENUGASANNYA sendiri,
     * dan keduanya dihitung dari seluruh isi kelompok - bukan dari yang
     * kebetulan tampil. Kepala kelompok yang berbunyi "12 mapel" di atas tiga
     * baris adalah angka yang salah, dan yang membacanya menyimpulkan datanya
     * hilang.
     *
     * Yang dikelompokkan adalah baris SESUDAH penyaringan, sehingga pencarian
     * "MTK" menghasilkan satu kelompok berisi satu baris - bukan tiga
     * kelompok yang dua di antaranya kosong. Tingkat yang tidak punya mapel
     * yang cocok memang tidak sedang ditanyakan.
     *
     * Mapel tanpa `grade_level` membentuk kelompok "Tanpa tingkat" yang
     * TERPISAH, dan sengaja tidak dilebur ke salah satu tingkat: ia bukan
     * tingkat yang belum diisi namanya, ia mapel yang memang belum punya
     * tingkat - dan meleburnya berarti menyembunyikan bahwa datanya belum
     * lengkap.
     *
     * @param  Collection<int, Subject>  $mapel
     * @param  Collection<int, Collection<int, TeachingAssignment>>  $penugasan
     * @return array<int, array<string, mixed>>
     */
    private function kelompokMapel(Collection $mapel, Collection $penugasan): array
    {
        $tanpaTingkat = $mapel->filter(fn (Subject $s) => blank($s->grade_level));

        $kelompok = $mapel
            ->reject(fn (Subject $s) => blank($s->grade_level))
            ->groupBy(fn (Subject $s) => trim((string) $s->grade_level))
            // Urutan alami: "9" sebelum "10". Urutan abjad menaruh "10" di
            // antara "1" dan "2", dan daftar tingkat yang terbaca 1, 10, 2, 3
            // tampak seperti kesalahan ketik.
            //
            // `(string)` di bawah BUKAN hiasan: kunci array PHP yang berbentuk
            // angka menjadi `int`, sehingga tingkat "7" sampai ke sini sebagai
            // `7` - dan layar yang membandingkannya dengan angka tingkatnya
            // sendiri (yang berupa string di JSON) akan gagal mencocokkannya.
            ->sortKeys(SORT_NATURAL)
            ->map(fn (Collection $isi, $tingkat) => $this->satuKelompok(
                'Tingkat '.$tingkat,
                (string) $tingkat,
                $isi,
                $penugasan,
            ))
            ->values()
            ->all();

        // "Tanpa tingkat" SELALU ada, bahkan ketika kosong atau ketika
        // pencariannya tidak menyisakan satu pun mapel tanpa tingkat.
        //
        // Dua alasan, dan keduanya tentang bentuk daftarnya yang harus bisa
        // dipegang: menyembunyikannya saat kosong menghilangkan satu-satunya
        // tempat Admin menyadari bahwa tidak ada mapel yang datanya belum
        // lengkap, dan menampilkannya hanya kadang-kadang membuat daftar
        // tingkat berubah-ubah mengikuti kata kunci - sehingga Admin yang
        // mengetik "MTK" lalu menghapusnya kembali mendapati kelompoknya
        // berpindah tempat.
        $kelompok[] = $this->satuKelompok('Tanpa tingkat', null, $tanpaTingkat, $penugasan);

        return $kelompok;
    }

    /**
     * @param  Collection<int, Subject>  $isi
     * @param  Collection<int, Collection<int, TeachingAssignment>>  $penugasan
     * @return array<string, mixed>
     */
    private function satuKelompok(
        string $label,
        ?string $tingkat,
        Collection $isi,
        Collection $penugasan,
    ): array {
        return [
            'kunci' => $tingkat ?? 'tanpa',
            'tingkat' => $tingkat,
            'label' => $label,
            'jumlah' => $isi->count(),
            // Jumlah PENUGASAN, bukan jumlah mapel yang punya penugasan.
            // Pertanyaan kepalanya "berapa izin mengajar di tingkat ini" -
            // dan satu mapel dengan sebelas kelas berarti sebelas.
            'penugasan' => $isi->sum(
                fn (Subject $s) => ($penugasan[$s->id] ?? collect())->count(),
            ),
            'baris' => $isi
                ->map(fn (Subject $s) => $this->ringkas($s, $penugasan))
                ->values()
                ->all(),
        ];
    }

    /**
     * Katalog tingkat beserta kelas yang tercakup, untuk penugasan massal.
     *
     * Bentuknya `[{ tingkat: '9', jumlah: 11, kelas: ['9A', …] }, …]`, bukan
     * `string[]`. Angka dan daftar kelasnya dibawa ke layar supaya Admin bisa
     * MELIHAT bahwa "tingkat 9" berarti sebelas kelas SEBELUM menekan
     * Tugaskan. Penugasan massal yang salah tebak jauh lebih mahal daripada
     * satu baris: sebelas izin yang harus dibatalkan satu per satu.
     *
     * Kelas tanpa `grade_level` sengaja TIDAK membentuk tingkat "kosong".
     * Mengelompokkannya jadi satu opsi berarti satu klik menugaskan guru ke
     * setiap kelas yang datanya belum lengkap - dan kelas itu tidak punya
     * kesamaan apa pun selain itu.
     *
     * @param  Collection<int, SchoolClass>  $daftarKelas
     * @return array<int, array{tingkat: string, jumlah: int, kelas: array<int, string>}>
     */
    private function katalogTingkat($daftarKelas): array
    {
        return $daftarKelas
            ->filter(fn (SchoolClass $k) => filled($k->grade_level))
            ->groupBy(fn (SchoolClass $k) => trim((string) $k->grade_level))
            // Urutan alami: "9" sebelum "10". Urutan abjad menaruh "10" di
            // antara "1" dan "2", dan daftar tingkat yang terbaca 1, 10, 2, 3
            // tampak seperti kesalahan ketik - Admin lalu meragukan datanya,
            // padahal datanya benar.
            ->sortKeys(SORT_NATURAL)
            ->map(fn ($grup, $tingkat) => [
                'tingkat' => (string) $tingkat,
                'jumlah' => $grup->count(),
                'kelas' => $grup->pluck('name')->values()->all(),
            ])
            ->values()
            ->all();
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
     * Tugaskan guru pada satu mapel - satu kelas, atau seluruh satu tingkat.
     *
     * =====================================================================
     * Mengapa satu rute untuk dua lingkup, bukan dua rute
     * =====================================================================
     * Yang dihasilkan keduanya sama: baris `teaching_assignments`. Menambah
     * rute kedua berarti dua tempat yang harus tetap sepakat soal peran guru,
     * tahun ajaran, dan penjagaan lintas sekolah - dan tempat kedua selalu
     * yang terlupakan saat aturannya berubah.
     *
     * Yang berbeda hanya cara KELASNYA ditentukan, dan itu satu percabangan.
     *
     * =====================================================================
     * Mengapa `tingkat` ada sama sekali
     * =====================================================================
     * Sekolah menugaskan satu guru pada seluruh tingkat sekaligus - "Bu Ani
     * mengajar Matematika di semua kelas 9". Tanpa ini Admin menekan Tugaskan
     * sebelas kali untuk satu keputusan, dan yang keempat biasanya terlewat.
     * Kelas yang terlewat tidak berbunyi apa pun: guru itu hanya tidak bisa
     * menyusun soal untuknya, dan baru ketahuan saat ujiannya disiapkan.
     *
     * Tingkatnya dibaca dari `classes.grade_level`, bukan dari huruf pertama
     * namanya. Nama kelas bebas diketik ("X TKJ 2", "9A", "IX-3"), dan
     * menebak tingkat dari namanya berarti satu salah ketik memindahkan
     * kelasnya ke tingkat yang salah tanpa ada yang tahu.
     */
    public function tugaskan(
        Request $request,
        Subject $mapel,
        ActivityLogger $logger,
        AcademicYearService $service,
    ): RedirectResponse {
        Gate::authorize('update', $mapel);

        $data = $request->validate([
            'lingkup' => ['required', 'in:kelas,tingkat'],
            // `required_if` + `nullable`: kuncinya SELALU ikut terkirim dari
            // form (satu state untuk dua lingkup), jadi yang menentukan bukan
            // ada-tidaknya kunci melainkan nilai `lingkup`.
            'class_id' => ['nullable', 'required_if:lingkup,kelas', 'integer'],
            'tingkat' => ['nullable', 'required_if:lingkup,tingkat', 'string', 'max:10'],
            'teacher_id' => ['required', 'integer'],
        ], [
            'class_id.required_if' => 'Pilih kelasnya.',
            'tingkat.required_if' => 'Pilih tingkatnya.',
        ]);

        // Guru dicari dulu, bukan diserahkan ke basis data. Composite FK akan
        // menolak kombinasi lintas sekolah, tetapi galatnya adalah
        // "foreign key constraint fails" - dan itu bukan kalimat yang bisa
        // ditindaklanjuti Admin. findOrFail menghasilkan 404, yang benar:
        // guru itu memang tidak ada di sekolah ini.
        $guru = User::query()
            ->where('role', Role::Teacher)
            ->findOrFail($data['teacher_id']);

        $kelasnya = $this->kelasSasaran($data, $service, $mapel);

        if ($kelasnya->isEmpty()) {
            return back()->with('galat', $this->pesanKosong($data));
        }

        $baru = 0;
        $pertama = null;

        foreach ($kelasnya as $kelas) {
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
                $baru++;

                // Baris yang baru dibuat, disimpan untuk dirujuk log. Kalau
                // dibaca ulang lewat relasi sesudah loop, yang didapat belum
                // tentu baris yang BARU SAJA dibuat - penugasan guru yang
                // sama di kelas itu bisa jadi sudah ada sejak tahun lalu.
                $pertama ??= $penugasan;
            }
        }

        $sudah = $kelasnya->count() - $baru;

        $this->catat($logger, $mapel, $guru, $data, $kelasnya, $baru, $pertama);

        return back()->with('sukses', $this->pesanSukses($data, $mapel, $guru, $kelasnya, $baru, $sudah));
    }

    /* ------------------------------------------------------------- Pembantu */

    /**
     * Kelas yang menjadi sasaran penugasan.
     *
     * Penugasan per tingkat SELALU dibatasi tahun ajaran aktif, dan itu bukan
     * kehati-hatian berlebihan: "tingkat 9" hanya punya arti di dalam satu
     * tahun ajaran. Tanpa pembatasan ini, kelas 9 tahun lalu ikut tertugaskan
     * - dan barisnya sah menurut skema, tidak muncul di layar mana pun, lalu
     * membuat guru terlihat berhak atas kelas yang sudah bubar.
     *
     * @param  array<string, mixed>  $data
     * @return Collection<int, SchoolClass>
     */
    private function kelasSasaran(array $data, AcademicYearService $service, Subject $mapel)
    {
        if ($data['lingkup'] === 'kelas') {
            return SchoolClass::query()->whereKey($data['class_id'])->get();
        }

        $tahun = $service->aktif();

        if ($tahun === null) {
            return collect();
        }

        return SchoolClass::query()
            ->where('academic_year_id', $tahun->id)
            ->where('grade_level', $data['tingkat'])
            ->orderBy('name')
            ->get();
    }

    /**
     * Satu baris log untuk satu tindakan Admin.
     *
     * Bukan satu baris per kelas: sebelas baris log untuk satu keputusan
     * membuat riwayatnya tidak terbaca, dan yang perlu ditelusuri kelak adalah
     * "siapa menugaskan Bu Ani ke seluruh tingkat 9", bukan sebelas kejadian
     * terpisah. Nama kelasnya tetap dicatat, karena itulah yang dicari saat
     * membatalkan satu per satu.
     *
     * @param  array<string, mixed>  $data
     * @param  Collection<int, SchoolClass>  $kelasnya
     * @param  ?TeachingAssignment  $pertama  Baris yang baru dibuat, bila ada -
     *                                        dipakai sebagai entitas log untuk
     *                                        lingkup satu kelas.
     */
    private function catat(
        ActivityLogger $logger,
        Subject $mapel,
        User $guru,
        array $data,
        $kelasnya,
        int $baru,
        ?TeachingAssignment $pertama,
    ): void {
        if ($baru === 0) {
            return;
        }

        $keterangan = [
            'guru' => $guru->name,
            'mapel' => $mapel->name,
            'jumlah' => $baru,
        ];

        if ($data['lingkup'] === 'tingkat') {
            $keterangan['tingkat'] = $data['tingkat'];
        } else {
            $keterangan['kelas'] = $kelasnya->first()?->name;
        }

        // Entitasnya mapel saat lingkupnya satu tingkat: tidak ada SATU baris
        // penugasan yang mewakilinya, dan menunjuk baris pertama berarti log
        // menyebut kelas pertama seolah-olah hanya kelas itu yang tersentuh.
        $logger->log(
            'Menambah penugasan mengajar',
            $data['lingkup'] === 'tingkat' ? $mapel : $pertama,
            $keterangan,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function pesanKosong(array $data): string
    {
        if ($data['lingkup'] === 'kelas') {
            return 'Kelas itu tidak ada di sekolah ini.';
        }

        return 'Tidak ada kelas tingkat '.$data['tingkat']
            .' di tahun ajaran aktif. Periksa tingkat kelasnya di laman Kelas, '
            .'atau tugaskan per kelas.';
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  Collection<int, SchoolClass>  $kelasnya
     */
    private function pesanSukses(
        array $data,
        Subject $mapel,
        User $guru,
        $kelasnya,
        int $baru,
        int $sudah,
    ): string {
        if ($data['lingkup'] === 'kelas') {
            return $guru->name.' ditugaskan pada '.$mapel->name.' di '
                .$kelasnya->first()?->name.'.';
        }

        $pesan = $guru->name.' ditugaskan pada '.$mapel->name.' di '.$baru
            .' kelas tingkat '.$data['tingkat'].'.';

        // Angka "sudah ada" disebut supaya Admin yang menekan tombolnya dua
        // kali tidak menyimpulkan bahwa penugasan pertama gagal.
        if ($sudah > 0) {
            $pesan .= ' '.$sudah.' kelas sudah ditugaskan sebelumnya dan dilewati.';
        }

        return $pesan;
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

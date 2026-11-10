<?php

namespace Database\Seeders;

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Academic\Models\Semester;
use App\Domain\Academic\Models\Subject;
use App\Domain\Academic\Models\TeachingAssignment;
use App\Domain\Attendance\Enums\StatusPresensi;
use App\Domain\Attendance\Models\AttemptQuestion;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Models\StudentAnswer;
use App\Domain\Attendance\Services\DenyutService;
use App\Domain\Attendance\Services\JawabanService;
use App\Domain\Attendance\Services\KehadiranService;
use App\Domain\Attendance\Services\KonteksRuang;
use App\Domain\Attendance\Services\LaporanSesiService;
use App\Domain\Attendance\Services\MesinUjian;
use App\Domain\Attendance\Services\PencatatPelanggaran;
use App\Domain\Event\Enums\EventStatus;
use App\Domain\Event\Models\EventClass;
use App\Domain\Event\Models\EventCommitteeMember;
use App\Domain\Event\Models\EventRoom;
use App\Domain\Event\Models\EventSubject;
use App\Domain\Event\Models\EventTeacherAssignment;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Event\Services\EventParticipantService;
use App\Domain\Event\Services\EventRegistrar;
use App\Domain\Event\Services\EventStatusService;
use App\Domain\Event\Services\PenerbitKodePengawas;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamQuestion;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Exam\Services\ExamRegistrar;
use App\Domain\Exam\Services\PenempatanService;
use App\Domain\Exam\Services\PengawasService;
use App\Domain\Exam\Services\SesiService;
use App\Domain\Exam\Services\TokenSesi;
use App\Domain\Facility\Models\Room;
use App\Domain\Facility\Models\RoomEntryLink;
use App\Domain\Facility\Services\RoomEntryLinkService;
use App\Domain\Grading\Jobs\BuatEksporLaporan;
use App\Domain\Grading\Models\ReportExport;
use App\Domain\Grading\Services\PenghitungAnalisis;
use App\Domain\Grading\Services\PenghitungHasil;
use App\Domain\Grading\Services\PenilaiUraian;
use App\Domain\Grading\Services\PublikasiHasil;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\StudentProfile;
use App\Domain\Identity\Models\User;
use App\Domain\Question\Enums\JenisSoal;
use App\Domain\Question\Enums\StatusSoal;
use App\Domain\Question\Models\QuestionImportBatch;
use App\Domain\Question\Models\QuestionMedia;
use App\Domain\Question\Models\QuestionPackage;
use App\Domain\Question\Services\BankSoalService;
use App\Domain\Question\Services\ImporSoalService;
use App\Domain\Question\Services\PaketSoalService;
use App\Domain\Question\Services\PemasanganPaket;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Data contoh yang menyerupai satu sekolah sungguhan.
 *
 * Sengaja tidak memakai factory acak untuk master data: laman-laman Fase 2
 * baru bisa dinilai setelah diisi, dan data yang mirip kenyataan adalah cara
 * termurah menemukan tata letak yang tidak muat. "X TKJ 2" lebih panjang
 * daripada "X-1", dan kolom yang muat untuk yang pendek belum tentu muat
 * untuk yang panjang.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $sekolah = School::factory()->create([
            'code' => 'SMKN1',
            'name' => 'SMK Negeri 1',
            'npsn' => '20512345',
            'address' => 'Jl. Pendidikan No. 12, Kecamatan Sukamaju, Kabupaten Bogor',
            'email' => 'info@smkn1.sch.id',
            'phone' => '021-8765432',
        ]);

        $admin = User::factory()->admin()->untukSekolah($sekolah)->create([
            'name' => 'Admin SMK Negeri 1',
            'username' => 'admin',
            'email' => 'admin@smkn1.sch.id',
        ]);

        $tahun = AcademicYear::factory()->untukSekolah($sekolah)->aktif()->create([
            'name' => '2026/2027',
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
        ]);

        $ganjil = Semester::create([
            'school_id' => $sekolah->id,
            'academic_year_id' => $tahun->id,
            'name' => 'Ganjil',
            'start_date' => '2026-07-01',
            'end_date' => '2026-12-31',
            'is_active' => true,
        ]);

        Semester::create([
            'school_id' => $sekolah->id,
            'academic_year_id' => $tahun->id,
            'name' => 'Genap',
            'start_date' => '2027-01-01',
            'end_date' => '2027-06-30',
            'is_active' => false,
        ]);

        $guru = collect(['Ani Mulyani', 'Budi Santoso', 'Rina Wati', 'Dedi Kurniawan'])
            ->map(fn (string $nama, int $i) => User::factory()->guru()->untukSekolah($sekolah)->create([
                'name' => $nama,
                'username' => 'guru'.($i + 1),
                'email' => 'guru'.($i + 1).'@smkn1.sch.id',
            ]));

        $pengawas = collect(['Sri Handayani', 'Joko Prasetyo'])
            ->map(fn (string $nama, int $i) => User::factory()->pengawas()->untukSekolah($sekolah)->create([
                'name' => $nama,
                'username' => 'pengawas'.($i + 1),
            ]));

        foreach ($pengawas as $i => $p) {
            $p->supervisorProfile()->create([
                'school_id' => $sekolah->id,
                'staff_code' => 'PGW-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
            ]);
        }

        $mapel = collect([
            ['MTK', 'Matematika', 'Wajib'],
            ['BIN', 'Bahasa Indonesia', 'Wajib'],
            ['BIG', 'Bahasa Inggris', 'Wajib'],
            ['FIS', 'Fisika', 'Peminatan'],
            ['KIM', 'Kimia', 'Peminatan'],
            ['TKJ', 'Jaringan Dasar', 'Kejuruan'],
        ])->map(fn (array $m) => Subject::create([
            'school_id' => $sekolah->id,
            'code' => $m[0],
            'name' => $m[1],
            'category' => $m[2],
            'grade_level' => 'X',
            'status' => 'aktif',
        ]));

        // Dua kelas. Yang kedua sengaja memakai nama panjang - lihat catatan
        // di atas tentang mengapa itu berguna.
        $kelas = collect([
            ['X-1', $guru[0]->id],
            ['X TKJ 2', $guru[1]->id],
        ])->map(fn (array $k) => SchoolClass::create([
            'school_id' => $sekolah->id,
            'academic_year_id' => $tahun->id,
            'name' => $k[0],
            'grade_level' => 'X',
            'homeroom_teacher_id' => $k[1],
            'status' => 'aktif',
        ]));

        // 12 siswa, tersebar di dua kelas.
        $nomor = 1;

        foreach ($kelas as $k) {
            foreach (range(1, 6) as $i) {
                $nis = '2024'.str_pad((string) $nomor, 3, '0', STR_PAD_LEFT);

                $siswa = User::factory()->siswa()->untukSekolah($sekolah)->create([
                    'name' => 'Siswa '.$nomor,
                    'username' => $nis,
                ]);

                StudentProfile::create([
                    'school_id' => $sekolah->id,
                    'user_id' => $siswa->id,
                    'nis' => $nis,
                    'nisn' => '00'.str_pad((string) $nomor, 8, '0', STR_PAD_LEFT),
                    'gender' => $nomor % 2 === 0 ? 'P' : 'L',
                ]);

                $k->enrollments()->create([
                    'school_id' => $sekolah->id,
                    'student_id' => $siswa->id,
                    'academic_year_id' => $tahun->id,
                    'enrollment_date' => '2026-07-15',
                    'status' => 'aktif',
                ]);

                $nomor++;
            }
        }

        // Penugasan mengajar: satu guru per mapel, dan guru terakhir merangkap
        // tiga mapel kejuruan.
        //
        // Sejak Fase 4 keenam mapel WAJIB punya pengampu, dan bukan karena
        // kerapian: `QuestionPolicy` menyaring bank soal lewat
        // `TeachingAssignment`, sehingga mapel tanpa pengampu berarti paket
        // soalnya ditulis atas nama guru yang tidak mengampunya - dan Guru
        // yang membuka mapel itu melihat bank soal kosong.
        //
        // Guru keempat memegang tiga mapel karena di sekolah sebesar ini
        // memang begitu keadaannya, dan keadaan itu justru berguna: daftar
        // bank soal lalu berisi baris dengan penulis yang berbeda-beda.
        $pengajar = [];

        foreach ($mapel as $i => $m) {
            $orang = $guru[min($i, $guru->count() - 1)];

            $pengajar[$m->id] = $orang;

            foreach ($kelas as $k) {
                TeachingAssignment::create([
                    'school_id' => $sekolah->id,
                    'academic_year_id' => $tahun->id,
                    'teacher_id' => $orang->id,
                    'subject_id' => $m->id,
                    'class_id' => $k->id,
                ]);
            }
        }

        // Ruang: campuran ber-PC, BYOD, dan perbaikan.
        $ruang = [
            ['R-01', 'Lab Komputer 1', 'Gedung A lantai 2', 32, 32, 'aktif'],
            ['R-02', 'Lab Komputer 2', 'Gedung A lantai 2', 32, 32, 'aktif'],
            ['R-03', 'Ruang Teori 3', 'Gedung B lantai 1', 36, 0, 'aktif'],
            ['R-04', 'Aula Serbaguna', 'Gedung C', 60, 0, 'aktif'],
            ['R-09', 'Lab Komputer 3', 'Gedung A lantai 3', 28, 28, 'perbaikan'],
        ];

        foreach ($ruang as $r) {
            Room::create([
                'school_id' => $sekolah->id,
                'code' => $r[0],
                'name' => $r[1],
                'location' => $r[2],
                'capacity' => $r[3],
                'device_count' => $r[4],
                'status' => $r[5],
            ]);
        }

        // ---------------------------------------------------------------
        // Bank soal (Fase 4) - dibangun SEBELUM eventnya, dan itu wajib
        // ---------------------------------------------------------------
        // Bukan urutan yang disukai melainkan urutan yang diharuskan gerbang:
        // `bangunUjian()` memindahkan event ke Siap lewat EventStatusService,
        // dan sejak Fase 4 gerbang kesiapan menolak event yang mapelnya belum
        // dipasangi paket. Membangun bank soal belakangan berarti seeder ini
        // gagal di tengah - dan kegagalan itu justru benar.
        //
        // Yang dibangun di sini: enam paket (satu per mapel), masing-masing
        // berisi kesepuluh jenis soal, ditulis lewat service yang sama dengan
        // yang dipakai Guru. Menulis barisnya langsung akan melewati
        // SkemaJenisSoal - dan seluruh gunanya data contoh ini adalah
        // membuktikan bahwa bentuk yang sah memang bisa disimpan.
        $paket = $this->bangunBankSoal($sekolah->id, $mapel, $pengajar);

        // ---------------------------------------------------------------
        // Dua event. Yang pertama lengkap, yang kedua baru draft.
        // ---------------------------------------------------------------
        // Bukan hiasan: daftar event plate 10 punya empat tab, dan tab yang
        // tidak pernah berisi apa pun tidak pernah teruji. Yang kedua juga
        // memperlihatkan laman kesiapan dalam keadaan gagal - dan laman itu
        // justru paling perlu dilihat dalam keadaan gagal, karena itulah
        // keadaannya selama event disusun.
        $registrar = app(EventRegistrar::class);

        $ujian = $registrar->buat([
            'academic_year_id' => $tahun->id,
            'semester_id' => $ganjil->id,
            'name' => 'UAS Ganjil 2026/2027',
            'event_type' => 'Ujian akhir semester',
            'description' => 'Penilaian akhir semester ganjil untuk seluruh tingkat X.',
            'start_at' => '2026-12-01 07:30:00',
            'end_at' => '2026-12-12 12:00:00',
            'ambang_peringatkan' => 3,
            'ambang_tandai_pengawas' => 5,
            'ambang_kunci_ujian' => 7,
            'enforce_fullscreen' => true,
        ], $sekolah->id, $admin->id, []);

        foreach ($kelas as $k) {
            EventClass::create([
                'school_id' => $sekolah->id,
                'event_id' => $ujian->id,
                'class_id' => $k->id,
            ]);
        }

        foreach ($mapel as $m) {
            EventSubject::create([
                'school_id' => $sekolah->id,
                'event_id' => $ujian->id,
                'subject_id' => $m->id,
            ]);
        }

        // ------------------------------------------------------------------
        // Penugasan guru per mapel event, DAN pemasangan paketnya
        // ------------------------------------------------------------------
        // Fase 3 melewatkan `event_teacher_assignments`, dan sejak Fase 4
        // akibatnya terlihat: Guru membuka `/guru`, melihat bank soal mapel
        // yang ia ampu sepanjang tahun, tetapi tidak melihat satu pun tugas
        // penyusunan untuk event ini - padahal justru itu yang diminta PRD
        // §6.8 ("guru hanya memperoleh akses ke tugas yang secara eksplisit
        // diberikan kepadanya").
        //
        // Pasangannya memakai `PemasanganPaket::pasang()` - satu pintu yang
        // sama dengan yang dipakai Admin di plate 15 - sehingga baris
        // riwayatnya ikut tertulis. Memasangnya langsung ke kolom akan
        // menghasilkan halaman riwayat yang kosong untuk data yang jelas
        // pernah dipasang.
        $pemasangan = app(PemasanganPaket::class);

        foreach ($mapel as $m) {
            $baris = EventSubject::query()
                ->where('event_id', $ujian->id)
                ->where('subject_id', $m->id)
                ->first();

            if ($baris === null) {
                continue;
            }

            $pemasangan->pasang(
                (int) $baris->id,
                $paket[$m->id]->id ?? null,
                $admin->id,
                'Data contoh Fase 4.',
            );

            foreach ($kelas as $k) {
                EventTeacherAssignment::create([
                    'school_id' => $sekolah->id,
                    'event_id' => $ujian->id,
                    'teacher_id' => $pengajar[$m->id]->id,
                    'subject_id' => $m->id,
                    'class_id' => $k->id,
                ]);
            }
        }

        foreach (Room::whereIn('code', ['R-01', 'R-02', 'R-04'])->get() as $r) {
            EventRoom::create([
                'school_id' => $sekolah->id,
                'event_id' => $ujian->id,
                'room_id' => $r->id,
                'status' => 'aktif',
            ]);
        }

        // Peserta ditarik lewat service, bukan ditulis langsung ke tabel.
        // Menulisnya langsung berarti seeder mengarang sendiri jawaban atas
        // pertanyaan "siapa yang ikut", dan jawaban itu tidak lagi melewati
        // pemeriksaan status aktif siswa yang sama dengan yang dilewati Admin.
        app(EventParticipantService::class)->tarikDariKelas($ujian);

        // Tiga pengawas dengan cakupan berbeda - inilah yang membuat kolom
        // Cakupan di plate 13 punya isi yang benar-benar berbeda per baris.
        $pengawas->each(function (User $p, int $i) use ($sekolah, $ujian) {
            $panitia = EventCommitteeMember::create([
                'school_id' => $sekolah->id,
                'event_id' => $ujian->id,
                'user_id' => $p->id,
                'name' => $p->name,
                'position' => 'pengawas',
                'scope' => 'ruang',
                'status' => 'aktif',
            ]);

            $ruangnya = Room::whereIn('code', $i === 0 ? ['R-01', 'R-02'] : ['R-04'])->pluck('id');

            $panitia->sinkronRuang($ruangnya->all());

            // Kode pengawas terbit sejak orangnya jadi panitia, supaya laman
            // Kepanitiaan tidak kosong di kolom "Kode pengawas" dan kartunya
            // bisa langsung dicetak. Kodenya tersimpan terenkripsi dan STABIL
            // (tidak berganti tiap kali terbitkan dipanggil).
            app(PenerbitKodePengawas::class)->terbitkan($panitia);
        });

        EventCommitteeMember::create([
            'school_id' => $sekolah->id,
            'event_id' => $ujian->id,
            'user_id' => $guru[0]->id,
            'name' => $guru[0]->name,
            'position' => 'ketua',
            'scope' => 'event',
            'status' => 'aktif',
        ]);

        // Panitia tanpa akun: perwakilan komite sekolah. Ia ada supaya kolom
        // "tanpa akun" di plate 13 terlihat, dan supaya batas kepanitiaan
        // terlihat - panitia bukan peran, jadi tidak setiap panitia punya akun.
        EventCommitteeMember::create([
            'school_id' => $sekolah->id,
            'event_id' => $ujian->id,
            'name' => 'Hendra Wijaya (Komite)',
            'position' => 'anggota',
            'email' => 'komite@smkn1.sch.id',
            'phone' => '0812-3456-7890',
            'scope' => 'event',
            'status' => 'aktif',
        ]);

        // Status dipindahkan lewat service, bukan ditulis ke kolomnya. Kalau
        // gerbang kesiapan salah, seeder gagal di sini - dan kegagalan itu
        // lebih berguna daripada event yang tampak "siap" tanpa pernah
        // melewati gerbangnya sekali pun.
        //
        // Sejak Fase 5 gerbangnya memeriksa jadwal, pengawas, dan token yang
        // SEBENARNYA, sehingga urutannya tidak bisa dibalik: ujian, sesi,
        // penempatan, penugasan, dan token dibangun lebih dulu. Event yang
        // ditandai siap tanpa satu sesi pun adalah persis keadaan yang
        // gerbangnya ada untuk mencegah.
        //
        // Sejak Fase 6 pemindahan status itu ada DI DALAM bangunUjian(),
        // karena satu langkah lagi menyusul sesudahnya: sesi pertama dibuka
        // lewat SesiService::mulai(), dan method itulah yang memindahkan
        // event ke Berjalan. Memanggil pindah() di sini sesudahnya berarti
        // memindahkan event dari Berjalan kembali ke Persiapan - transisi
        // yang grafnya memang tidak izinkan, dan seeder akan gagal.
        $this->bangunUjian($ujian, $mapel, $admin, $pengawas, $paket);

        // Event kedua: sengaja dibiarkan kosong, dan statusnya Persiapan.
        //
        // Persiapan, bukan Draft, karena di sinilah gerbang kesiapan punya
        // bentuknya: dari Persiapan transisi ke Siap ADA di graf tetapi
        // terhalang, sehingga tombolnya muncul dalam keadaan NONAKTIF
        // beserta alasan dan tautan perbaikannya. Dari Draft maupun Siap,
        // transisi itu tidak ada sama sekali - dan tombol yang tidak dirender
        // tidak membuktikan apa pun tentang platenya.
        $draft = $registrar->buat([
            'academic_year_id' => $tahun->id,
            'semester_id' => $ganjil->id,
            'name' => 'Try Out UTBK',
            'event_type' => 'Try out',
        ], $sekolah->id, $admin->id, []);

        app(EventStatusService::class)->pindah($draft, EventStatus::Preparation);

        // ------------------------------------------------------------------
        // Dua berkas impor: satu bersih, satu bergalat
        // ------------------------------------------------------------------
        // Laporan galat hanya berguna bila ada isinya, dan Guru yang membuka
        // plate 04 pertama kali tidak punya berkas apa pun untuk diunggah.
        // Karena itu keduanya dibuat lewat `ImporSoalService` yang sama -
        // unggah, pratinjau, konfirmasi - sehingga baris riwayatnya memuat
        // nomor baris yang benar-benar dihasilkan pembacanya, bukan angka yang
        // ditulis tangan.
        $impor = $this->bangunImporSoal($sekolah->id, $mapel->first(), $guru[0]->id);

        $this->command->info('Login: admin / password');
        $this->command->info('Master: '.$mapel->count().' mapel, '.$kelas->count().' kelas, '.$nomor - 1 .' siswa, '.count($ruang).' ruang.');
        $this->command->info('Semester aktif: '.$ganjil->name.' di '.$tahun->name);
        $this->command->info('Admin sekolah: '.$admin->username);
        $this->command->info('Event: '.$ujian->code.' ('.$ujian->status->label().', '.$ujian->participants()->count().' peserta) + 1 draft.');
        $this->command->info('Ujian: '.$ujian->exams()->count().' ujian, '.$ujian->exams()->withCount('sessions')->get()->sum('sessions_count').' sesi, '.$pengawas->count().' pengawas bertoken.');
        $this->command->info('Bank soal: '.count($paket).' paket x '.count(JenisSoal::cases()).' jenis; impor '.$impor['bersih']->success_rows.' bersih + '.$impor['beralat']->failed_rows.' baris bergalat.');
    }

    /**
     * Satu ujian, tiga sesi, penempatan, penugasan pengawas, dan token.
     *
     * Dibangun lewat service, bukan ditulis langsung ke tabel - alasan yang
     * sama dengan seluruh seeder ini: menulis langsung berarti seeder
     * mengarang jawaban atas pertanyaan "apakah jadwal ini bisa dijalankan",
     * dan jawabannya tidak lagi melewati pemeriksaan yang sama dengan yang
     * dilewati Admin.
     *
     * Tiga sesi dipakai karena itulah bentuk yang blueprint Fase 5 sebut
     * sebagai kriteria selesai - "satu ujian dengan 3 sesi x 8 ruang x 300
     * peserta" - dan karena satu sesi tidak pernah memperlihatkan apa pun
     * tentang benturan: dua sesi di ruang yang sama pada jam yang berbeda
     * adalah keadaan normal, dan justru itu yang perlu terlihat benar.
     *
     * Jadwalnya tiga hari berturut-turut, bukan tiga sesi dalam satu hari.
     * Alasannya bukan kesederhanaan: sesi yang tidak bertumpang adalah
     * satu-satunya bentuk yang membuat dua pengawas bisa melayani sembilan
     * ruang-sesi tanpa satu pun benturan - dan pengawas yang bentrok adalah
     * temuan yang gerbangnya tolak, sehingga seeder ini gagal bila salah.
     *
     * @param  Collection<int, Subject>  $mapel
     * @param  Collection<int, User>  $pengawas
     * @param  array<int, QuestionPackage>  $paket  Diindeks id mapel.
     */
    private function bangunUjian(
        SchoolEvent $event,
        Collection $mapel,
        User $admin,
        Collection $pengawas,
        array $paket = [],
    ): void {
        $mtk = $mapel->firstWhere('code', 'MTK') ?? $mapel->first();

        $ujian = app(ExamRegistrar::class)->buat($event, [
            'subject_id' => $mtk->id,
            // Sejak Fase 4 ujian LAHIR dengan paketnya, dan itu yang membuat
            // `bekukanPaket()` benar-benar berjalan saat `db:seed`.
            //
            // Sebelumnya kolom ini kosong di seluruh data contoh, sehingga
            // `exam_questions` tidak pernah berisi satu baris pun - dan mesin
            // ujian Fase 7 akan mewarisi tabel kosong dari seeder yang tampak
            // berhasil. Snapshot yang tidak pernah dijalankan adalah snapshot
            // yang tidak pernah diuji.
            'question_package_id' => $paket[$mtk->id]->id ?? null,
            'title' => 'Ujian Akhir Semester '.$mtk->name,
            'exam_type' => 'Ujian akhir semester',
            'instructions' => 'Kerjakan seluruh soal. Perangkat hanya boleh dipakai untuk ujian.',
            'duration_minutes' => 90,
            'max_attempts' => 1,
            'shuffle_questions' => true,
            'shuffle_options' => true,
            'allow_back_navigation' => true,
            'allow_late_entry' => true,
            // Tidak ada `publish_results` di sini. Sejak Fase 8 publikasi
            // hasil adalah properti EVENT (`school_events.results_published_at`),
            // dan dikerjakan lewat `PublikasiHasil::terbitkan()` - bukan lewat
            // seeder yang menulis kolom. Data contoh yang "sudah terbit" tanpa
            // melewati gerbang koreksi akan meloloskan tombol publikasi yang
            // seharusnya masih terkunci.
            //
            // Kelas sasaran dikosongkan dengan sengaja: kosong berarti SELURUH
            // peserta event, dan itulah yang membuat baris ketiganya di plate 16
            // berbunyi "Matematika · semua kelas" - kalimat yang tidak akan
            // pernah terlihat bila seluruh data contoh mencentang semua kelas.
            'class_id' => [],
        ], $admin->id);

        $penempatan = app(PenempatanService::class);
        $penempatan->tarikPeserta($ujian);

        $ruangEvent = $event->rooms()->orderBy('id')->take(2)->get();

        // ------------------------------------------------------------------
        // Sesi pertama dibuka HARI INI, dan itu bukan hiasan
        // ------------------------------------------------------------------
        // Ketiga sesi sempat bertanggal tetap 2026-12-01/02/03, dan sejak
        // Fase 6 tanggal mati itu membuat SELURUH laman pengawas kosong:
        // `boleh_buka` di plate 11 bernilai false, pintu `/p/{slug}` menolak
        // dengan "sesi ini belum dibuka", AksesPengawas menolak permintaan
        // ber-tautan, dan token sesinya belum `berlaku()` sehingga tombol
        // Tampilkan di plate 12 tidak pernah bisa membuka apa pun.
        //
        // Laman yang kosong tidak membuktikan apa pun - dan yang tampak
        // "berhasil" justru paling berbahaya, karena ia lulus pemeriksaan
        // tanpa satu pun jalur Fase 6 pernah dilalui.
        //
        // Karena itu sesi 1 dihitung dari sekarang, bukan dari tanggal tetap:
        // jendelanya dibuka tiga puluh menit lalu dan berakhir sembilan puluh
        // menit lagi, sehingga hari mana pun seeder ini dijalankan, sesi 1
        // selalu berada di tengah jendelanya sendiri. Sesi 2 dan 3 tetap di
        // hari berikutnya, dan itu disengaja - keduanya menjadi pembanding
        // yang jendelanya memang belum terbuka, sehingga dua perilaku yang
        // berbeda bisa dibuktikan berdampingan.
        //
        // Jendelanya juga tidak boleh MENYENTUH `now()`: `hard_end_at` sesi 1
        // adalah `valid_until` tokennya, dan token yang batas akhirnya tepat
        // sekarang sudah `kedaluwarsa()` saat gerbang kesiapan membacanya -
        // sehingga event ditolak dengan "1 sesi belum punya token yang
        // berlaku" untuk token yang baru saja diterbitkan seeder ini sendiri.
        foreach ([1, 2, 3] as $nomorSesi) {
            // `addDays($nomorSesi - 1)`, BUKAN `addDay()`.
            //
            // Versi pertama menulis `addDay()` di kedua cabang, dan sejak
            // sesi 1 dipindahkan ke "dua jam lalu" itu berarti sesi 2 dan
            // sesi 3 menempati jendela yang PERSIS sama. Akibatnya bukan
            // jadwal yang aneh di layar melainkan seeder yang gagal di
            // tengah: `PengawasService::tugaskan()` menolak pengawas yang
            // bentrok, dan dengan giliran yang dibalik setiap sesi, kedua
            // pengawas memang ditugaskan di kedua sesi itu.
            //
            // Tiga hari berturut-turut adalah yang diminta docblock di atas
            // dan yang membuat sembilan ruang-sesi bisa dilayani dua orang
            // tanpa satu pun benturan.
            $buka = $nomorSesi === 1
                ? now()->subMinutes(30)
                : now()->addDays($nomorSesi - 1)->startOfDay()->addHours(7)->addMinutes(30);

            $sesi = app(SesiService::class)->buat($ujian, [
                'session_number' => $nomorSesi,
                'access_opens_at' => $buka,
                'access_closes_at' => $buka->copy()->addMinutes(90),
                'hard_end_at' => $buka->copy()->addHours(2),
                'duration_minutes' => 90,
                'event_room_id' => $ruangEvent->pluck('id')->all(),
            ]);

            // ------------------------------------------------------------------
            // Sebar HANYA ke sesi 1, dan itu bukan penghematan
            // ------------------------------------------------------------------
            // `PenempatanService::sebar()` memindahkan SELURUH peserta ujian ke
            // sesi yang diberikan - ia "sebar ulang", bukan "tambahkan", dan
            // itulah yang dibutuhkan tombol di plate 17 saat kapasitas ruang
            // berubah.
            //
            // Karena itu memanggilnya di dalam loop ini menempatkan seluruh
            // peserta ke sesi TERAKHIR, dan meninggalkan sesi 1 kosong. Sesi 1
            // justru satu-satunya yang jendelanya sedang terbuka, sehingga
            // seluruh laman Fase 6 dan Fase 7 - papan presensi, monitoring,
            // pengerjaan - membaca ruang yang tidak berisi siapa pun. Keadaannya
            // tampak "berhasil" karena seedernya tidak gagal; yang kosong cuma
            // layarnya.
            //
            // Sesi 2 dan 3 tetap dibangun lengkap dengan ruang, pengawas, dan
            // tokennya - ketiganya memang diperiksa gerbang kesiapan, dan
            // jendelanya yang belum terbuka adalah pembanding yang disengaja.
            // Yang tidak diberikan kepada keduanya adalah peserta.
            if ($nomorSesi === 1) {
                $penempatan->sebar($ujian, $sesi);
            }

            // Satu pengawas per ruang, dan gilirannya DIBALIK setiap sesi.
            // Keduanya bukan hiasan:
            //
            //   - satu per ruang karena satu orang tidak bisa berdiri di dua
            //     ruang pada jendela yang sama, dan PengawasService menolaknya
            //     - sehingga dua ruang per sesi memang menuntut dua orang;
            //   - dibalik setiap sesi supaya kolom "Pengawas 1" dan
            //     "Pengawas 2" di plate 17 benar-benar berisi orang yang
            //     berbeda per baris. Mengisi keduanya dengan urutan yang sama
            //     membuat kolom kedua tidak pernah teruji.
            foreach ($sesi->rooms()->orderBy('id')->get() as $i => $ruangSesi) {
                $orang = $pengawas[($i + $nomorSesi) % $pengawas->count()];

                app(PengawasService::class)->tugaskan($ruangSesi, $orang, $admin->id);
            }

            app(TokenSesi::class)->terbitkan($sesi, $admin->id);

            // Sesi disiapkan, bukan dibiarkan Draft: draft berarti jendelanya
            // masih boleh diubah, dan event yang menandai dirinya siap dengan
            // sesi yang masih bisa digeser adalah event yang jadwalnya belum
            // final. Dipanggil lewat service, bukan `forceFill`, supaya seeder
            // menempuh graf yang sama dengan Admin - dulu `forceFill` di sini
            // menutupi kenyataan bahwa tidak ada kode aplikasi yang bisa
            // menghasilkan status Siap.
            app(SesiService::class)->siapkan($sesi);
        }

        // Eventnya dipindahkan PERSIS dua langkah sebelum sesi pertama dibuka,
        // dan urutan itulah yang diminta gerbangnya: dari Draft langsung ke
        // Berjalan tidak ada di graf, sementara gerbang kesiapan hanya
        // menggerbangi jalur menuju Siap. Kedua langkah ini bukan basa-basi -
        // keduanya jalur yang sama dengan yang dilalui Admin.
        app(EventStatusService::class)->pindah($event, EventStatus::Preparation);
        app(EventStatusService::class)->pindah($event, EventStatus::Ready);

        $this->jalankanSesiPertama($event);
    }

    /**
     * Fase 6: keadaan hari-H yang membuat sembilan laman pengawas berisi.
     *
     * ---------------------------------------------------------------------
     * Mengapa seeder ikut mengisi, bukan hanya menyiapkan jadwal
     * ---------------------------------------------------------------------
     * Seluruh laman Fase 6 membaca keadaan SESI YANG SEDANG BERJALAN -
     * presensi yang sebagian sudah diverifikasi, satu attempt yang sedang
     * berjalan, satu berita acara yang masih draf. Menyiapkan jadwalnya saja
     * menghasilkan sembilan laman yang ter-render tetapi kosong, dan laman
     * kosong tidak membuktikan apa pun tentang presensi, token, atau berita
     * acara.
     *
     * ---------------------------------------------------------------------
     * Mengapa lewat KonteksRuang, bukan lewat Auth atau penulisan langsung
     * ---------------------------------------------------------------------
     * Presensi punya pelaku, dan pelakunya tercatat di setiap baris log.
     * Seeder tidak punya pengguna yang sedang masuk, jadi pertanyaannya
     * adalah "atas nama siapa" - dan jawabannya diambil dari bentuk data yang
     * sudah ada: TAUTAN RUANG plus PENGAWAS yang ditugaskan di ruang itu,
     * persis seperti pengawas di pintu kelas.
     *
     * Sejak 2026-11-07 pemegang tautan memang punya identitas di log, karena
     * kode pengawas menentukan ORANG. Seeder tidak mengetik kode apa pun, jadi
     * orangnya dibaca dari penugasannya (`masukLewatTautan()`), dan label yang
     * tertulis di lognya adalah NAMA pengawas itu - bukan lagi
     * "R-01 (tautan ruang)". Fallback label ruang tetap ada untuk kasus yang
     * masih mungkin: ruang yang belum punya pengawas sama sekali.
     *
     * Kunci sesinya ditulis langsung ke Session, bukan lewat request: seeder
     * berjalan di console, dan tidak ada permintaan HTTP yang bisa membawanya.
     * KonteksRuang membaca dari store yang sama.
     */
    private function jalankanSesiPertama(SchoolEvent $event): void
    {
        $ruangSesi = $this->ruangSesiPertama($event);

        if ($ruangSesi === null) {
            return;
        }

        $sesi = $ruangSesi->session;

        app(SesiService::class)->mulai($sesi);

        // Dibaca ULANG dari basis data, dan itu wajib: SesiService::mulai()
        // memindahkan eventnya lewat `$sesi->exam->event` - instance yang
        // dihidrasi relasinya, bukan objek $event yang dipegang pemanggil.
        // Keduanya menunjuk baris yang sama, tetapi hanya satu di antaranya
        // yang kolom statusnya ikut berubah.
        $event->refresh();

        $ruang = $ruangSesi->eventRoom?->room;

        if ($ruang === null) {
            return;
        }

        // ------------------------------------------------------------------
        // Tautan pintu untuk ruang sesi pertama
        // ------------------------------------------------------------------
        // Diterbitkan lewat service. Tautan hanya menentukan RUANG - ia tidak
        // lagi membawa kode apa pun (sejak 2026-11-07 kodenya milik ORANG),
        // dan sejak 2026-11-09 ia juga tidak lagi berumur pendek.
        $tautan = app(RoomEntryLinkService::class)->terbitkan($ruang);

        $this->masukLewatTautan($ruangSesi, $tautan->id, $ruang->id);

        $this->verifikasiSebagian($ruangSesi);

        $pengerjaan = $this->jalankanPengerjaanContoh($ruangSesi);

        // Berita acara dibuat lewat service, bukan ditulis ke tabelnya -
        // alasannya sama dengan seluruh seeder ini: `untuk()` yang memutuskan
        // bentuk drafnya (dua pernyataan `false`, status `draft`), dan
        // menuliskannya langsung berarti seeder mengarang jawaban atas
        // pertanyaan "seperti apa draf yang sah".
        $laporan = app(LaporanSesiService::class)->untuk($ruangSesi);

        // Kode pengawas ruang ini dibaca dari baris kepanitiaan orang yang
        // ditugaskan di sini - bukan dari tautannya. Kodenya tersimpan
        // terenkripsi dan selalu tampil di laman Kepanitiaan; menyebutkannya
        // di konsol hanya memudahkan uji manual tanpa membuka laman itu.
        $kodePengawas = $this->kodePengawasRuang($ruangSesi);

        $this->command->info(
            'Pengawas: buka '.url('/p/'.$tautan->slug)
            .($kodePengawas === null ? '' : ' kode '.$kodePengawas)
            .' (ruang '.$ruang->code.').'
        );
        $this->command->info('Sesi 1 berjalan; berita acara draf '.$ruang->code.' #'.$laporan->id.'; pengerjaan contoh '.count($pengerjaan).' peserta.');

        // Fase 8 menyusul sesudah pengerjaannya ada - lihat catatan di
        // `jalankanPenilaianContoh()`.
        $admin = User::query()
            ->where('school_id', $event->school_id)
            ->where('role', Role::Admin)
            ->orderBy('id')
            ->first();

        if ($admin !== null) {
            $this->jalankanPenilaianContoh($event, $admin);
        }
    }

    /**
     * Fase 8: koreksi uraian, publikasi hasil, analisis butir, dan ekspor.
     *
     * ---------------------------------------------------------------------
     * Mengapa seeder ikut mengoreksi, bukan hanya menyediakan jawaban
     * ---------------------------------------------------------------------
     * Enam laman Fase 8 membaca EMPAT tabel yang tidak ditulis fase mana pun
     * sebelumnya: `exam_results`, `essay_gradings`, `question_analyses`, dan
     * `report_exports`. Menyiapkan pengerjaan tanpa menilai berarti plate 07,
     * 08, 21, dan 22 ter-render KOSONG - dan laman kosong tidak membuktikan
     * apa pun tentang rumus nilai, ambang KKM, daya beda, atau tombol
     * publikasi.
     *
     * ---------------------------------------------------------------------
     * Seluruhnya lewat SERVICE, dan itu yang membuat angkanya sah
     * ---------------------------------------------------------------------
     * `PenilaiUraian` yang menulis `essay_gradings` - bukan `EssayGrading`
     * yang ditulis langsung - karena service itulah yang menghitung ulang
     * `exam_results` sesudah setiap koreksi. Mengisi `exam_results` sendiri
     * akan menghasilkan nilai yang tidak pernah dilihat rumus PRD §13.4, dan
     * seeder yang mengarang nilai adalah seeder yang membuktikan kebalikan
     * dari yang diuji berkas `PenilaianHasilTest`.
     *
     * Poin per kriteria diambil dari RUBRIK SNAPSHOT, dan bervariasi menurut
     * urutan peserta: kelas dengan nilai yang sama persis untuk semua orang
     * tidak pernah memperlihatkan kolom remedial, rata-rata, maupun daya beda
     * yang benar-benar dihitung.
     */
    private function jalankanPenilaianContoh(SchoolEvent $event, User $admin): void
    {
        $ujian = $event->exams()->orderBy('id')->first();

        if ($ujian === null) {
            return;
        }

        $penilai = app(PenilaiUraian::class);
        $sekolahId = $event->school_id;

        // Guru yang mengampu mapel ujian ini - pelaku koreksinya. Dibaca dari
        // `EventTeacherAssignment` (bukan `TeachingAssignment`) karena inilah
        // baris yang seeder ini sendiri tulis di atas, dan Guru yang tidak
        // tercatat di situ akan ditolak `UjianGuru` bila ia membuka plate 06.
        $guru = User::query()
            ->whereIn('id', EventTeacherAssignment::query()
                ->where('event_id', $event->id)
                ->where('subject_id', $ujian->subject_id)
                ->select('teacher_id'))
            ->first();

        if ($guru === null) {
            $this->command->warn('Fase 8 dilewati: tidak ada guru pengampu untuk ujian ini.');

            return;
        }

        // Hanya jawaban uraian yang SUDAH DIJAWAB. Uraian yang dikosongkan
        // siswa tidak punya barisnya sama sekali (lihat `PenghitungHasil`),
        // jadi tidak ada yang bisa - dan tidak ada yang perlu - dikoreksi.
        $jawaban = StudentAnswer::query()
            ->whereHas('butir', fn ($q) => $q->whereHas('soal', fn ($s) => $s
                ->where('exam_id', $ujian->id)
                ->where('jenis', JenisSoal::Uraian->value)))
            ->whereHas('butir.attempt', fn ($q) => $q->whereIn('status', [
                ExamAttempt::STATUS_DIKIRIM,
                ExamAttempt::STATUS_KEDALUWARSA,
                ExamAttempt::STATUS_DIKUNCI,
            ]))
            ->orderBy('id')
            ->get()
            ->filter(fn (StudentAnswer $j) => $j->terjawab());

        $dinilai = 0;

        foreach ($jawaban as $i => $j) {
            // Poin diambil dari rubrik snapshot, dibatasi bobot butirnya -
            // aturan yang sama dengan yang ditegakkan `PenilaiUraian`, supaya
            // seeder ini gagal di sini bila rubriknya tidak konsisten dengan
            // bobotnya.
            $butir = $j->butir?->soal;
            $bobot = (float) ($butir?->bobotBerlaku() ?? 0);
            $rubrik = $butir?->rubrik_json ?? [];

            // Bergeser menurut urutan jawaban, sehingga satu kelas berisi
            // nilai yang berbeda-beda - dan kolom remedial di plate 07
            // benar-benar terisi.
            $bagian = [1.0, 0.75, 0.5, 0.9][$i % 4];

            $kriteria = [];

            foreach ($rubrik as $krit) {
                $maks = (float) ($krit['maks'] ?? 0);

                if ($maks <= 0) {
                    continue;
                }

                $kriteria[] = [
                    'kriteria' => (string) ($krit['kriteria'] ?? 'Kriteria'),
                    'poin' => round($maks * $bagian, 2),
                ];
            }

            // Rubrik kosong tetap harus menghasilkan koreksi: jawaban yang
            // dinilai tanpa satu pun kriteria berpoin sah - ia berarti
            // "dinilai, tidak dapat poin" - dan itulah satu-satunya cara
            // `pending_essays` turun ke nol tanpa mengarang poin.
            $total = array_sum(array_column($kriteria, 'poin'));

            if ($total > $bobot + 0.01 && $bobot > 0) {
                $kriteria = [];
            }

            $penilai->koreksi($j, $guru, $kriteria, 'Data contoh Fase 8.');
            $dinilai++;
        }

        // Hasil yang belum dihitung - attempt yang ditutup fase lain -
        // dihitung di sini supaya tidak ada baris `exam_results` yang kosong
        // saat plate 21 membacanya.
        $hasil = app(PenghitungHasil::class)->hitungUlangUjian($ujian->id);

        // Analisis butir disimpan, bukan dihitung saat laman dibuka - alasan
        // yang sama dengan plate 08. Tanpa baris di sini, plate 08 selalu
        // kosong pada data contoh.
        $butirDianalisis = app(PenghitungAnalisis::class)->hitungUlang($ujian->id);

        // Publikasi HANYA bila memang tidak ada yang menunggu. Seeder yang
        // menerbitkan hasil yang belum final akan membuktikan kebalikan dari
        // gerbang yang baru saja diuji `PenilaianHasilTest` - dan gerbang itu
        // menolaknya juga di sini, lewat `ValidationException` yang sama.
        $publikasi = app(PublikasiHasil::class);
        $sisa = $publikasi->sisaKoreksi($event->refresh());

        if ($sisa === 0) {
            $publikasi->terbitkan($event, $admin);
            $event->refresh();

            // Satu ekspor contoh, lewat jalur yang sama dengan tombol plate
            // 22: baris antrean lebih dulu, baru jobnya. Menulis berkasnya
            // langsung akan melewati `report_exports`, dan daftar riwayat
            // ekspor di laman itu tidak akan pernah berisi apa pun.
            $ekspor = new ReportExport;
            $ekspor->forceFill([
                'school_id' => $sekolahId,
                'requested_by' => $admin->id,
                'jenis' => 'nilai',
                'format' => 'csv',
                'parameter_json' => ['event_id' => $event->id, 'kelas' => null, 'mapel' => null],
                'status' => ReportExport::STATUS_MENUNGGU,
                'created_at' => now(),
            ])->save();

            // Dijalankan SEKARANG, bukan didorong ke antrean: seeder berjalan
            // di console, dan worker tidak selalu hidup di mesin
            // pengembangan - baris "Menunggu" yang tidak pernah selesai
            // adalah data contoh yang menyesatkan.
            (new BuatEksporLaporan($ekspor->id))->handle();
        }

        $this->command->info(
            'Fase 8: '.$dinilai.' uraian dikoreksi; '.$hasil.' hasil dihitung; '
            .$butirDianalisis.' butir dianalisis; publikasi '
            .($sisa === 0 ? 'dijalankan' : "ditahan ({$sisa} uraian menunggu)").'.'
        );
    }

    /** Ruang-sesi pertama dari sesi pertama event ini. */
    private function ruangSesiPertama(SchoolEvent $event): ?ExamSessionRoom
    {
        $sesi = ExamSession::query()
            ->whereIn('exam_id', $event->exams()->pluck('id'))
            ->orderBy('session_number')
            ->first();

        if ($sesi === null) {
            return null;
        }

        return ExamSessionRoom::query()
            ->where('exam_session_id', $sesi->id)
            // `school_id` WAJIB ikut di-select meski tidak dibaca layar mana
            // pun: `RoomEntryLinkService::terbitkan()` menyalinnya dari
            // `$ruang->school_id`, dan kolom `rooms.school_id` memang NOT
            // NULL. Memilih empat kolom saja membuat `$ruang->school_id`
            // bernilai null pada model yang dihidrasi sebagian, dan
            // kegagalannya datang sebagai "Column 'school_id' cannot be null"
            // dari insert yang tidak menyebut room sama sekali.
            ->with(['session', 'eventRoom.room:id,school_id,code,name,location'])
            ->orderBy('id')
            ->first();
    }

    /**
     * Pasang kunci sesi ruang, sehingga KonteksRuang memakai pintu tautan.
     *
     * Bentuk array-nya harus sama persis dengan yang ditulis
     * PintuRuangController::buka() - `tautanSesi()` mencocokkan
     * `room_entry_link_id` dan `room_id`, dan salah satu kunci yang hilang
     * membuat tautannya terbaca tidak sah, tanpa satu pun galat.
     */
    private function masukLewatTautan(ExamSessionRoom $ruangSesi, int $tautanId, int $roomId): void
    {
        $tautan = RoomEntryLink::tanpaTenant()->find($tautanId);

        Session::put(KonteksRuang::KUNCI_SESI, [
            'exam_session_room_id' => $ruangSesi->id,
            'room_id' => $roomId,
            'room_entry_link_id' => $tautanId,
            // Sejak 2026-11-07 pemegang tautan punya IDENTITAS: kode pengawas
            // menentukan orangnya. Seeder tidak mengetik kode apa pun, jadi
            // orangnya dibaca dari PENUGASANNYA di ruang ini - dan itulah yang
            // membuat presensi hasil seed menyebut nama pengawas, sama seperti
            // presensi yang ditandatangani pengawas sungguhan di pintu kelas.
            'school_id' => $tautan?->school_id,
            'pengguna_id' => $ruangSesi->supervisors()->value('supervisor_id'),
            'sejak' => now()->toIso8601String(),
        ]);
    }

    /**
     * Kode pengawas orang yang ditugaskan di satu ruang-sesi, bila ada.
     *
     * Kodenya menempel pada baris KEPANITIAAN orangnya, bukan pada tautan
     * ruang - jadi dicari lewat penugasan (`supervisor_id`) lalu baris
     * kepanitiaan pada event yang sama. Hanya untuk keperluan pesan konsol;
     * laman Kepanitiaan membacanya sendiri.
     */
    private function kodePengawasRuang(ExamSessionRoom $ruangSesi): ?string
    {
        $pengawasIds = $ruangSesi->supervisors()->pluck('supervisor_id');

        if ($pengawasIds->isEmpty()) {
            return null;
        }

        $eventId = $ruangSesi->session?->exam?->event_id;

        return EventCommitteeMember::query()
            ->where('event_id', $eventId)
            ->whereIn('user_id', $pengawasIds)
            ->where('status', 'aktif')
            ->whereNotNull('code')
            ->value('code');
    }

    /**
     * Verifikasi sebagian peserta, supaya papan presensi berisi dua keadaan.
     *
     * Satu terlambat, satu izin, satu tidak hadir, sisanya hadir - dan sengaja
     * diambil dari UJUNG daftar, bukan dari awalnya: daftarnya urut abjad,
     * sehingga yang tercatat berbeda adalah siswa dengan nama terakhir,
     * sementara baris pertama tetap `pending` dan kolom "Belum diverifikasi"
     * beserta tombol kembalikannya di plate 13 punya isinya.
     *
     * Diverifikasi lewat KehadiranService, bukan lewat forceFill - dan itu
     * bukan kerapian: setiap perubahan presensi WAJIB meninggalkan satu baris
     * `attendance_logs`, dan baris log itulah yang membedakan laman ini dari
     * tabel yang bisa disunting siapa saja.
     */
    private function verifikasiSebagian(ExamSessionRoom $ruangSesi): void
    {
        $konteks = KonteksRuang::dari($ruangSesi);
        $kehadiran = app(KehadiranService::class);

        $daftar = $kehadiran->daftarRuang($ruangSesi)->values();

        foreach ([StatusPresensi::TidakHadir, StatusPresensi::Izin, StatusPresensi::Terlambat] as $status) {
            $record = $daftar->last();

            if ($record === null) {
                break;
            }

            $kehadiran->catat($record, $status, $konteks, 'Data contoh Fase 6.');
            $daftar = $daftar->slice(0, $daftar->count() - 1)->values();
        }

        // Dua baris terakhir dibiarkan `pending` - lihat catatan method ini.
        foreach ($daftar->slice(0, max(0, $daftar->count() - 2)) as $record) {
            $kehadiran->catat($record, StatusPresensi::Hadir, $konteks);
        }
    }

    /**
     * Pengerjaan contoh: attempt, urutan soal, jawaban, dan pelanggaran.
     *
     * ---------------------------------------------------------------------
     * Seluruhnya lahir dari SERVICE, dan itu yang membuatnya sah
     * ---------------------------------------------------------------------
     * Fase 6 menulis satu baris `exam_attempts` langsung, karena penulisnya
     * belum ada. Fase 7 penulisnya sudah ada, dan sejak itu menulis barisnya
     * langsung berarti melewati empat hal sekaligus: `lockForUpdate()` yang
     * mencegah attempt ganda, `deadline_at = MIN(durasi, hard_end_at)`,
     * penyalinan urutan soal ke `attempt_questions`, dan hitungan
     * `violation_count` yang dipakai papan monitoring setiap kali disegarkan.
     *
     * Yang paling menentukan adalah yang terakhir: `violation_count` bukan
     * kolom yang boleh dikarang, karena ia yang memutuskan apakah seorang
     * anak terkunci. Pelanggaran di bawah ini ditulis lewat
     * `PencatatPelanggaran`, sehingga hitungannya, tingkat terberatnya, dan
     * label tindakannya konsisten dengan apa yang akan terjadi sungguhan.
     *
     * ---------------------------------------------------------------------
     * Berempat, dengan empat keadaan yang berbeda
     * ---------------------------------------------------------------------
     * Papan monitoring plate 15 punya empat kolom keadaan, dan satu attempt
     * hanya membuktikan satu di antaranya. Karena itu:
     *
     *   1. Mengerjakan, separuh terjawab, dua pelanggaran tingkat 1.
     *   2. Mengerjakan, hampir selesai, satu pelanggaran tingkat 2.
     *   3. Terkunci - tingkat 5, dan terkunci SEKETIKA karena `sesi_ganda`.
     *   4. Selesai dikumpulkan, dengan denyut yang sudah basi.
     *
     * Keempatnya peserta yang presensinya SUDAH diverifikasi, dan itu wajib:
     * `KelayakanUjian` menolak attempt milik peserta yang belum diverifikasi,
     * sehingga attempt di sini akan bertentangan dengan presensi di
     * `verifikasiSebagian()` - data contoh yang saling membantah lebih buruk
     * daripada data contoh yang kosong.
     *
     * ---------------------------------------------------------------------
     * Kunci jawaban TIDAK ditulis, dan itu disengaja
     * ---------------------------------------------------------------------
     * Jawaban yang diisi di sini adalah jawaban yang akan dibaca layar
     * pengerjaan. Menyilangkannya dengan `kunci_json` berarti seeder ini
     * menghitung nilai - dan penilaian adalah Fase 8, dengan aturannya
     * sendiri tentang skor parsial dan toleransi angka. Yang diisi karena itu
     * jawaban yang BENTUKNYA benar (satu huruf untuk PG, daftar untuk ganda,
     * peta untuk jodohkan), bukan jawaban yang isinya benar.
     *
     * @return array<int, ExamAttempt>
     */
    private function jalankanPengerjaanContoh(ExamSessionRoom $ruangSesi): array
    {
        $konteks = KonteksRuang::dari($ruangSesi);

        // Presensi yang sudah diverifikasi saja: attempt milik peserta yang
        // masih `pending` adalah keadaan yang tidak bisa terjadi sungguhan -
        // `KelayakanUjian` menolaknya di gerbang.
        $penempatan = ExamParticipant::query()
            ->where('exam_session_room_id', $ruangSesi->id)
            ->whereHas('student', fn ($q) => $q->whereNotNull('id'))
            ->with('student:id,name')
            ->get()
            ->filter(function (ExamParticipant $p) {
                $record = AttendanceRecord::query()
                    ->where('exam_participant_id', $p->id)
                    ->first();

                return $record !== null
                    && $record->verified_at !== null
                    && $record->attendance_status->membukaGerbang(true);
            })
            ->sortBy(fn (ExamParticipant $p) => $p->student?->name)
            ->values()
            ->take(4);

        if ($penempatan->isEmpty()) {
            return [];
        }

        $mesin = app(MesinUjian::class);
        $jawaban = app(JawabanService::class);
        $pelanggaran = app(PencatatPelanggaran::class);
        $denyut = app(DenyutService::class);

        $attempt = [];

        foreach ($penempatan as $i => $p) {
            // ---------------------------------------------------------------
            // Attempt dibuat lewat mesin, bukan lewat ExamAttempt::create()
            // ---------------------------------------------------------------
            // Tokennya `null` karena sesi 1 menerbitkan token yang siswanya
            // ketik di gerbang - dan `periksaToken()` akan menolak attempt
            // tanpa token. Karena itu token sesi 1 diberikan ke sini, dan
            // itulah sebabnya `jalankanSesiPertama()` menerimanya.
            $a = $mesin->mulai($p, $this->kodeTokenSesi($ruangSesi->session));

            // Perlahan-lahan memundurkan jam mulai, supaya sisa waktunya
            // berbeda-beda dan kolom "sisa waktu" papan monitoring tidak
            // berisi empat angka yang sama.
            $a->forceFill([
                'started_at' => now()->subMinutes(12 + ($i * 9)),
                'deadline_at' => now()->addMinutes(78 - ($i * 9)),
            ])->save();

            $attempt[] = $a;

            $this->isiJawabanContoh($a, $jawaban, $i);
        }

        // -------------------------------------------------------------------
        // Pelanggaran - dan hanya untuk dua attempt
        // -------------------------------------------------------------------
        // Attempt ketiga sengaja DIBIARKAN bersih sampai di sini, lalu dikunci
        // oleh `sesi_ganda` di bawah: satu-satunya jenis yang mengunci
        // seketika, dan membuktikan bahwa jalur "kunci tanpa menunggu
        // hitungan" benar-benar bekerja pada data contoh.
        if (isset($attempt[0])) {
            $pelanggaran->catat($attempt[0], 'pindah_tab', 4000);
            $pelanggaran->catat($attempt[0], 'keluar_layar_penuh');
        }

        if (isset($attempt[1])) {
            // Tingkat 2: pengawas ditandai, ujian BERJALAN TERUS - dan itu
            // yang membuat kolom "pengawas ditandai" di plate 16 berisi.
            $pelanggaran->catat($attempt[1], 'pintasan_terlarang');
        }

        if (isset($attempt[2])) {
            $pelanggaran->catat($attempt[2], 'sesi_ganda');
        }

        if (isset($attempt[3])) {
            // URAIAN WAJIB TERJAWAB DI ATTEMPT YANG DIKUMPULKAN.
            //
            // `isiJawabanContoh()` menjawab makin sedikit seiring naiknya
            // urutan attempt (`$batas = jumlah - urutan * 2`) supaya kolom
            // Progres tidak berisi empat angka yang sama. Akibatnya attempt
            // keempat - satu-satunya yang benar-benar dikumpulkan - hanya
            // menjawab tiga butir pertama, sementara butir uraian ada di
            // posisi kedelapan. Tidak ada jawaban uraian yang lahir, dan
            // akibatnya `essay_gradings` kosong: antrean koreksi plate 06
            // tidak berisi apa pun, plate 07 menampilkan "Uraian" kosong di
            // seluruh baris, dan seeder melaporkan "0 uraian dikoreksi" pada
            // data contoh yang seharusnya memperlihatkan pekerjaan Fase 8.
            //
            // Karena itu attempt yang dikumpulkan dilengkapi khusus di sini,
            // BUKAN dengan menyeragamkan `$batas`: keragaman progres itu
            // sengaja, dan yang salah bukan keragamannya melainkan tidak ada
            // satu pun attempt yang menjawab uraian lalu dikumpulkan.
            $this->lengkapiUraianContoh($attempt[3], $jawaban);

            $mesin->kumpulkan($attempt[3]);

            // Denyut yang BASI, dan itu bukan kelalaian: kolom "terputus" di
            // plate 15 dihitung dari selisih `last_heartbeat_at`, dan tanpa
            // satu attempt yang basi, kolom itu selalu berisi nol pada data
            // contoh yang seharusnya memperlihatkan keempat keadaannya.
            $attempt[3]->forceFill([
                'last_heartbeat_at' => now()->subMinutes(7),
                'last_seen_at' => now()->subMinutes(7),
            ])->save();
        }

        // Denyut yang masih segar untuk yang masih mengerjakan - kalau tidak,
        // seluruh ruang terbaca "terputus" pada data contoh yang bersih.
        foreach (array_slice($attempt, 0, 3) as $a) {
            $a->forceFill([
                'last_heartbeat_at' => now()->subSeconds(20),
                'last_seen_at' => now()->subSeconds(20),
                'device_type' => 'desktop',
                'ip_address' => '10.10.20.'.(30 + $a->id % 20),
                'user_agent' => 'Seeder Fase 7',
                'guard_flags_json' => [
                    ...($a->guard_flags_json ?? []),
                    'layar_penuh' => true,
                    'pelindung' => true,
                    'denyut_pada' => now()->subSeconds(20)->toIso8601String(),
                ],
            ])->save();
        }

        // Progres dan NOMOR ditulis ke `guard_flags_json`, meniru
        // `DenyutService` - dan itu satu-satunya tempat papan monitoring
        // membacanya (plate 15, kolom "Progres"; papan Admin, kolom "Sampai
        // nomor"). Menghitungnya di controller papan berarti agregasi per
        // peserta di setiap penyegaran, tepat saat basis data paling sibuk;
        // lihat catatan `ExamAttempt`.
        //
        // Nomornya bukan progres. `progres()` menjawab berapa BANYAK yang
        // sudah dijawab, dan di sini keempat attempt menjawab dari butir
        // PERTAMA berturut-turut - sehingga nomor yang masuk akal bagi
        // mereka adalah nomor butir TERAKHIR yang terjawab, bukan
        // `terjawab + 1` (anak yang menjawab 1, 2, 3 sedang membuka nomor 4,
        // bukan nomor 4 sudah dijawab). Tanpa baris ini kolom "Sampai nomor"
        // berisi tanda pisah di seluruh data contoh, dan laman yang justru
        // dibuka untuk melihatnya tampak belum jadi.
        foreach ($attempt as $a) {
            // Butir terjawab yang paling jauh, disaring dengan `terjawab()`
            // dan BUKAN dengan `answer_json` yang tidak null: bentuk
            // `{"opsi":[]}` tersimpan sebagai baris yang ada isinya tetapi
            // berarti "belum dijawab", dan menghitungnya akan menaruh nomor
            // yang lebih jauh daripada yang benar-benar dilihat anak.
            $terjauh = AttemptQuestion::query()
                ->where('exam_attempt_id', $a->id)
                ->with('jawaban')
                ->orderByDesc('display_order')
                ->get()
                ->first(fn (AttemptQuestion $b) => $b->jawaban?->terjawab() ?? false);

            $a->forceFill([
                'guard_flags_json' => [
                    ...($a->guard_flags_json ?? []),
                    'progres' => $jawaban->progres($a)['ringkas'],
                    'nomor' => $terjauh?->display_order,
                ],
            ])->save();
        }

        $this->command->info('Token sesi 1: '.($this->kodeTokenSesi($ruangSesi->session) ?? '-'));

        return $attempt;
    }

    /**
     * Kode token sesi, dibaca kembali lewat jalur berwenang.
     *
     * `terbitkan()` mengembalikan kode mentah SEKALI, dan seeder ini tidak
     * menyimpannya di properti - ia menerbitkan tokennya di dalam
     * `bangunUjian()` dan lupa kodenya begitu method itu selesai. Yang
     * tersisa hanya `token_encrypted`, dan justru itulah gunanya kolom itu:
     * PRD §9.7 menuntutnya supaya token yang sudah terbit tetap bisa dibaca
     * Admin, bukan hanya dicabut lalu diterbitkan ulang.
     *
     * `bacaBerwenang()` menulis baris riwayat pembukaan setiap kali
     * dipanggil - dan itu memang yang terjadi, bukan efek samping yang
     * ditoleransi: seeder ini MEMBACA tokennya, dan pembacaannya layak
     * tercatat sama seperti pembacaan pengawas di pintu.
     */
    private function kodeTokenSesi(ExamSession $sesi): ?string
    {
        $token = $sesi->tokenAktif();

        if ($token === null) {
            return null;
        }

        return app(TokenSesi::class)->bacaBerwenang($token, pelakuLabel: 'Seeder Fase 7');
    }

    /**
     * Isi sebagian jawaban satu attempt, supaya progresnya tidak nol.
     *
     * Bentuk isinya mengikuti yang dikirim `Kerjakan.tsx`, dan itu bukan
     * kerapian: jawaban yang tersimpan dengan bentuk lain akan tampil kosong
     * di laman pengerjaan - anak membuka ujiannya, melihat nomor 1 sampai 4
     * kosong padahal ia sudah mengerjakannya, lalu mengulanginya.
     *
     * Setiap attempt menjawab SEBAGIAN, dan bagiannya bergeser menurut
     * urutannya: empat attempt yang menjawab jumlah soal yang sama membuat
     * kolom Progres berisi satu angka yang diulang empat kali, dan kolom
     * yang selalu sama tidak pernah memperlihatkan apakah ia benar-benar
     * dihitung.
     */
    private function isiJawabanContoh(ExamAttempt $attempt, JawabanService $jawaban, int $urut): void
    {
        $butir = AttemptQuestion::query()
            ->where('exam_attempt_id', $attempt->id)
            ->with('soal')
            ->orderBy('display_order')
            ->get();

        if ($butir->isEmpty()) {
            return;
        }

        // Attempt pertama menjawab lebih banyak daripada yang keempat, dan
        // yang keempat sudah dikumpulkan - ia tidak menerima simpanan lagi.
        $batas = max(1, $butir->count() - ($urut * 2));

        foreach ($butir->take($batas) as $i => $b) {
            $isi = $this->jawabanUntuk($b);

            if ($isi === null) {
                continue;
            }

            $jawaban->simpan($attempt, $b, $isi, null, $i % 4 === 0);
        }
    }

    /**
     * Jawab setiap butir uraian yang belum terjawab pada satu attempt.
     *
     * Dipakai hanya untuk attempt yang akan dikumpulkan. Ia sengaja TIDAK
     * masuk `isiJawabanContoh()`: attempt yang masih berjalan tidak perlu
     * menjawab uraian (ujiannya belum selesai), sedangkan attempt yang
     * dikumpulkan tanpanya menghasilkan antrean koreksi kosong di plate 06.
     */
    private function lengkapiUraianContoh(ExamAttempt $attempt, JawabanService $jawaban): void
    {
        $butir = AttemptQuestion::query()
            ->where('exam_attempt_id', $attempt->id)
            ->with(['soal', 'jawaban'])
            ->orderBy('display_order')
            ->get()
            ->filter(fn (AttemptQuestion $b) => $b->soal?->jenis === JenisSoal::Uraian);

        foreach ($butir as $b) {
            if ($b->jawaban !== null && $b->jawaban->terjawab()) {
                continue;
            }

            $isi = $this->jawabanUntuk($b);

            if ($isi === null) {
                continue;
            }

            // `false` = bukan jawaban otomatis. `is_correct` tetap null di
            // dalamnya, sesuai catatan di `jawabanUntuk()`.
            $jawaban->simpan($attempt, $b, $isi, null, false);
        }
    }

    /**
     * Jawaban satu butir, bentuknya menurut jenis soalnya.
     *
     * @return array<string, mixed>|null
     */
    private function jawabanUntuk(AttemptQuestion $butir): ?array
    {
        $soal = $butir->soal;

        if ($soal === null) {
            return null;
        }

        $jenis = $soal->jenis;
        $kunci = collect($soal->opsi_json ?? [])->pluck('kunci')->filter()->values();

        return match ($jenis) {
            JenisSoal::PilihanGanda, JenisSoal::BenarSalah => $kunci->isEmpty()
                ? null
                : ['opsi' => [$kunci->first()]],

            JenisSoal::JawabanGanda => $kunci->isEmpty()
                ? null
                : ['opsi' => $kunci->take(2)->all()],

            // Media meminjam mekanisme salah satu jenis lain; yang dipakai
            // data contoh adalah `pilihan`, sesuai `resepSoal()`.
            JenisSoal::Media => $kunci->isEmpty()
                ? null
                : ['opsi' => [$kunci->first()]],

            JenisSoal::IsianSingkat => ['teks' => 'Jawaban contoh siswa.'],

            // Isi lubang pertamanya benar dan lubang kedua sengaja salah:
            // jawaban contoh yang seluruhnya benar tidak pernah membuktikan
            // bahwa penilaian per lubang bekerja, dan itu satu-satunya hal
            // yang membedakan jenis ini dari isian singkat.
            JenisSoal::IsianRumpang => $this->rumpangContoh($soal),

            JenisSoal::Angka => ['nilai' => '12'],

            // Uraian diisi, dan `is_correct`-nya dibiarkan null: null berarti
            // BELUM DINILAI, dan itu memang keadaannya sampai Fase 8. Mengisi
            // `false` di sini akan menandai jawaban uraian setiap siswa
            // sebagai salah, dan laporan yang membacanya tidak lagi bisa
            // membedakan "belum dinilai" dari "salah".
            JenisSoal::Uraian => ['teks' => 'Menurut saya, jawabannya dapat dijelaskan sebagai berikut. Pertama, langkah awal dilakukan dengan mengidentifikasi besaran yang diketahui. Kedua, rumus yang sesuai diterapkan. Ketiga, hasilnya disimpulkan.'],

            JenisSoal::Menjodohkan => $this->pasanganContoh($soal),
            JenisSoal::Mengurutkan => $this->urutanContoh($soal),

            default => null,
        };
    }

    /**
     * Jawaban rumpang contoh: lubang 1 benar, lubang 2 dibiarkan kosong.
     *
     * `kunci_json` pada snapshot TIDAK selalu ada - snapshot yang lahir dari
     * paket memuatnya, dan yang dibuat langsung tanpa paket tidak. Karena itu
     * jawabannya disusun dari BENTUK kunci, bukan dari isinya: yang dibutuhkan
     * hanya berapa lubang yang harus diisi.
     *
     * @return array<string, mixed>|null
     */
    private function rumpangContoh(ExamQuestion $soal): ?array
    {
        $kunci = $soal->kunci_json['rumpang'] ?? null;

        if (! is_array($kunci) || $kunci === []) {
            return null;
        }

        $isi = [];

        foreach (array_values($kunci) as $nomor => $varian) {
            // Lubang kedua sengaja kosong. Jawaban contoh yang seluruhnya
            // benar membuat nilai 100 terlihat wajar, dan kesalahan
            // pembagian per lubang tidak pernah muncul di data contoh.
            $isi[] = $nomor === 0 && is_array($varian) ? (string) ($varian[0] ?? '') : '';
        }

        return ['rumpang' => $isi];
    }

    /** @return array<string, mixed>|null */
    private function pasanganContoh(ExamQuestion $soal): ?array
    {
        $kiri = collect($soal->butir_json ?? [])->where('grup', 'kiri')->pluck('kunci')->all();
        $kanan = collect($soal->butir_json ?? [])->where('grup', 'kanan')->pluck('kunci')->values()->all();

        if ($kiri === [] || $kanan === []) {
            return null;
        }

        $pasangan = [];

        foreach ($kiri as $i => $k) {
            // Sengaja TIDAK selalu menunjuk pasangan yang benar: menjodohkan
            // seluruhnya benar membuat kolom "ditandai" dan skor parsial tidak
            // pernah terlihat bekerja. Yang salah tetap bentuknya sah.
            $pasangan[$k] = $kanan[$i] ?? $kanan[0];
        }

        return ['pasangan' => $pasangan];
    }

    /** @return array<string, mixed>|null */
    private function urutanContoh(ExamQuestion $soal): ?array
    {
        $semua = collect($soal->butir_json ?? [])->pluck('kunci')->filter()->values()->all();

        if ($semua === []) {
            return null;
        }

        // Diputar satu langkah, bukan dibiarkan urut: urutan yang kebetulan
        // sama dengan urutan tampilnya tidak membedakan siswa yang
        // mengurutkan dari yang membiarkannya apa adanya.
        $urut = $semua;
        $pertama = array_shift($urut);
        $urut[] = $pertama;

        return ['urutan' => array_values($urut)];
    }

    /* =================================================================
     | Fase 4 - bank soal, paket, dan impor
     |================================================================= */

    /**
     * Enam paket - satu per mapel - masing-masing berisi kesepuluh jenis.
     *
     * ---------------------------------------------------------------------
     * Mengapa ENAM paket, sementara rencana menyebut tiga
     * ---------------------------------------------------------------------
     * Rencananya berbunyi "tiga paket" karena tiga mapel pertama adalah
     * satu-satunya yang punya pengampu saat itu. Fase 4 mengubah dua hal
     * sekaligus, dan keduanya menuntut enam:
     *
     *   1. `PaketSoalService::tambahButir()` menolak soal dari mapel lain,
     *      sehingga satu paket tidak bisa melayani dua mapel - dan
     *      `question_packages.subject_id` memang satu kolom.
     *   2. Gerbang kesiapan menolak event yang mapelnya belum dipasangi paket,
     *      dan `$ujian` punya ENAM mapel. Tiga paket berarti seeder ini gagal
     *      di `pindah($ujian, Ready)`.
     *
     * Isi yang kaya tetap ada di tiga tempat yang disebut rencana - Matematika
     * memakai pangkat dan rumus, Bahasa Indonesia memakai tabel, Fisika memakai
     * gambar lewat `question_media` - karena justru isi itulah yang membuat
     * tata letak yang tidak muat terlihat.
     *
     * ---------------------------------------------------------------------
     * Mengapa lewat service, bukan tulis langsung
     * ---------------------------------------------------------------------
     * `BankSoalService` adalah satu-satunya penulis yang tahu bahwa kunci
     * harus sama dengan `is_correct` di tabel anak, dan `SkemaJenisSoal` satu-
     * satunya yang tahu bentuk kunci tiap jenis. Seeder yang menulis barisnya
     * langsung akan menghasilkan soal yang tampak benar sampai Fase 7, saat
     * mesin penilaian menemukan kuncinya berbentuk lain.
     *
     * @param  Collection<int, Subject>  $mapel
     * @param  array<int, User>  $pengajar  Diindeks id mapel.
     * @return array<int, QuestionPackage> Diindeks id mapel.
     */
    private function bangunBankSoal(int $schoolId, Collection $mapel, array $pengajar): array
    {
        $bankSoal = app(BankSoalService::class);
        $paketSoal = app(PaketSoalService::class);
        $tema = $this->temaPerMapel();

        $paket = [];

        foreach ($mapel as $m) {
            $penulis = $pengajar[$m->id];
            $t = $tema[$m->code] ?? $tema['MTK'];

            $soalIds = [];

            foreach ($this->resepSoal($t) as $resep) {
                $media = $resep['media'] ?? null;
                unset($resep['media']);

                $soal = $bankSoal->buat(
                    $resep + [
                        'subject_id' => $m->id,
                        // Siap, bukan draf: soal yang masih draf tidak muncul
                        // di paket Guru lain, dan data contoh yang seluruhnya
                        // draf tidak memperlihatkan bank soal yang benar-benar
                        // dipakai bersama.
                        'status' => StatusSoal::Siap->value,
                    ],
                    $schoolId,
                    (int) $penulis->id,
                );

                $soalIds[] = (int) $soal->id;

                if ($media !== null) {
                    $this->simpanMedia($soal->id, $schoolId, $media);
                }
            }

            // Satu paket per mapel, dan butirnya SELURUH soal yang baru saja
            // dibuat - sehingga "berisi kesepuluh jenis" bukan janji di
            // komentar melainkan akibat dari cara paketnya diisi.
            $paketnya = $paketSoal->buat([
                'subject_id' => $m->id,
                'name' => 'UAS Ganjil '.$m->name.' X',
                'grade_level' => 'X',
                'description' => 'Paket contoh Fase 4 untuk '.$m->name.' kelas X.',
            ], $schoolId, (int) $penulis->id);

            $paketSoal->tambahButir($paketnya, $soalIds);

            // `tandaiSiap()` menolak paket kosong, dan itu bukan basa-basi:
            // paket Siap yang kosong baru ditolak jauh di `PembekuPaket` saat
            // ujian dibuat. Kalau jumlah butirnya salah, seeder ini gagal di
            // sini - bukan di layar Admin tiga langkah kemudian.
            $paketSoal->tandaiSiap($paketnya);

            $paket[$m->id] = $paketnya->fresh();
        }

        return $paket;
    }

    /**
     * Bobot kesepuluh soal, berjumlah 100.
     *
     * Angka bulat seratus dipilih karena itulah yang dikejar Guru di plate 05
     * ("total bobot 100"), dan paket contoh yang totalnya 108 akan membuat
     * empat Guru pertama mengira penjumlahannya salah.
     *
     * @var array<int, float>
     */
    private const BOBOT_CONTOH = [12, 10, 10, 9, 10, 10, 9, 10, 10, 10];

    /**
     * Sepuluh payload soal - satu untuk setiap jenis, dalam urutan enum.
     *
     * ---------------------------------------------------------------------
     * Mengapa dibangkitkan dari satu tema, bukan ditulis satu per satu
     * ---------------------------------------------------------------------
     * Lima puluh empat soal yang ditulis tangan berarti lima puluh empat
     * tempat yang bisa salah, dan yang paling mungkin salah adalah BENTUK
     * KUNCINYA - persis bagian yang tidak diperiksa mata saat membaca ulang.
     * Yang berbeda antar mapel adalah ISI (istilah, konteks, angka), bukan
     * bentuk; karena itu isi yang diserahkan ke tema, dan bentuk tinggal di
     * sini sekali.
     *
     * @param  array<string, mixed>  $t
     * @return array<int, array<string, mixed>>
     */
    private function resepSoal(array $t): array
    {
        $bobot = self::BOBOT_CONTOH;
        $kiri = $t['istilah'];
        $kanan = $t['arti'];

        return [
            // 1 ------------------------------------------------------- PG
            [
                'code' => null,
                'title' => 'Pilihan ganda - '.$t['pokok'],
                'question_type' => JenisSoal::PilihanGanda->value,
                'difficulty' => 'sedang',
                'prompt_json' => ['teks' => $t['kaya'] ?? $t['pg']],
                'answer_key_json' => ['opsi' => [$t['kunci']]],
                'scoring_config_json' => ['bobot' => $bobot[0]],
                'explanation_json' => ['teks' => $t['pembahasan']],
                'opsi' => $this->opsiAbjad($t['opsi']),
            ],

            // 2 ---------------------------------------------------- Ganda
            [
                'code' => null,
                'title' => 'Jawaban ganda - '.$t['pokok'],
                'question_type' => JenisSoal::JawabanGanda->value,
                'difficulty' => 'sulit',
                'prompt_json' => ['teks' => $t['ganda']],
                'answer_key_json' => ['opsi' => $t['ganda_kunci']],
                'scoring_config_json' => ['bobot' => $bobot[1], 'skor_parsial' => true],
                'explanation_json' => ['teks' => $t['pembahasan']],
                'opsi' => $this->opsiAbjad($t['opsi']),
            ],

            // 3 ----------------------------------------------- Benar/salah
            [
                'code' => null,
                'title' => 'Benar/salah - '.$t['pokok'],
                'question_type' => JenisSoal::BenarSalah->value,
                'difficulty' => 'mudah',
                'prompt_json' => ['teks' => $t['pernyataan']],
                'answer_key_json' => ['opsi' => [$t['bs']]],
                'scoring_config_json' => ['bobot' => $bobot[2]],
                'explanation_json' => ['teks' => $t['pembahasan']],
                // Dua pilihan dibangkitkan sendiri: jenis ini tidak punya
                // pilihan yang ditulis Guru, dan opsi yang kosong akan membuat
                // `pastikanKunciAdaDiOpsi()` melewati pemeriksaannya.
                'opsi' => [
                    ['option_key' => 'b', 'content_json' => ['teks' => 'Benar'], 'position' => 0],
                    ['option_key' => 's', 'content_json' => ['teks' => 'Salah'], 'position' => 1],
                ],
            ],

            // 4 ---------------------------------------------------- Isian
            [
                'code' => null,
                'title' => 'Isian singkat - '.$t['pokok'],
                'question_type' => JenisSoal::IsianSingkat->value,
                'difficulty' => 'sedang',
                'prompt_json' => ['teks' => $t['isian']],
                // Dua varian, dan itu bukan hiasan: varian kedua adalah alasan
                // kolomnya array, dan kunci bervarian tunggal tidak pernah
                // membuktikan bahwa daftarnya benar-benar dibaca.
                'answer_key_json' => ['teks' => $t['jawaban']],
                'scoring_config_json' => ['bobot' => $bobot[3]],
                'opsi' => [],
            ],

            // 5 --------------------------------------------- Isian rumpang
            [
                'code' => null,
                'title' => 'Isian rumpang - '.$t['pokok'],
                'question_type' => JenisSoal::IsianRumpang->value,
                'difficulty' => 'sedang',
                // Teksnya WAJIB memuat {{1}}..{{n}} sebanyak lubangnya -
                // SkemaJenisSoal menolak yang tidak cocok, dan soal contoh
                // yang tidak bisa disimpan bukan soal contoh.
                'prompt_json' => ['teks' => $t['rumpang']],
                // Dua lubang, salah satunya bervarian dua: varian adalah
                // alasan kuncinya daftar, dan lubang bervarian tunggal tidak
                // pernah membuktikan bahwa daftarnya dibaca.
                'answer_key_json' => ['rumpang' => $t['rumpang_kunci']],
                'scoring_config_json' => ['bobot' => $bobot[4]],
                'explanation_json' => ['teks' => $t['rumpang_pembahasan']],
                'opsi' => [],
            ],

            // 6 ------------------------------------------------ Menjodohkan
            [
                'code' => null,
                'title' => 'Menjodohkan - '.$t['pokok'],
                'question_type' => JenisSoal::Menjodohkan->value,
                'difficulty' => 'sedang',
                'prompt_json' => ['teks' => $t['jodoh']],
                'answer_key_json' => ['pasangan' => ['k1' => 'a', 'k2' => 'b', 'k3' => 'c']],
                'scoring_config_json' => ['bobot' => $bobot[5]],
                'butir' => [
                    ['item_key' => 'k1', 'item_group' => 'kiri', 'content_json' => ['teks' => $kiri[0]], 'position' => 0],
                    ['item_key' => 'k2', 'item_group' => 'kiri', 'content_json' => ['teks' => $kiri[1]], 'position' => 1],
                    ['item_key' => 'k3', 'item_group' => 'kiri', 'content_json' => ['teks' => $kiri[2]], 'position' => 2],
                    ['item_key' => 'a', 'item_group' => 'kanan', 'content_json' => ['teks' => $kanan[0]], 'position' => 3],
                    ['item_key' => 'b', 'item_group' => 'kanan', 'content_json' => ['teks' => $kanan[1]], 'position' => 4],
                    ['item_key' => 'c', 'item_group' => 'kanan', 'content_json' => ['teks' => $kanan[2]], 'position' => 5],
                ],
            ],

            // 7 ------------------------------------------------ Mengurutkan
            [
                'code' => null,
                'title' => 'Mengurutkan - '.$t['pokok'],
                'question_type' => JenisSoal::Mengurutkan->value,
                'difficulty' => 'sulit',
                'prompt_json' => ['teks' => $t['urut']],
                // Urutannya SENGAJA bukan k1,k2,k3: kunci yang kebetulan sama
                // dengan urutan tampilnya tidak membedakan soal yang benar-
                // benar mengurutkan dari soal yang menyimpan urutannya apa
                // adanya.
                'answer_key_json' => ['urutan' => ['k3', 'k1', 'k2']],
                'scoring_config_json' => ['bobot' => $bobot[6]],
                'butir' => [
                    ['item_key' => 'k1', 'item_group' => 'kiri', 'content_json' => ['teks' => $t['langkah'][0]], 'position' => 0],
                    ['item_key' => 'k2', 'item_group' => 'kiri', 'content_json' => ['teks' => $t['langkah'][1]], 'position' => 1],
                    ['item_key' => 'k3', 'item_group' => 'kiri', 'content_json' => ['teks' => $t['langkah'][2]], 'position' => 2],
                ],
            ],

            // 8 ---------------------------------------------------- Angka
            [
                'code' => null,
                'title' => 'Angka - '.$t['pokok'],
                'question_type' => JenisSoal::Angka->value,
                'difficulty' => 'sedang',
                'prompt_json' => ['teks' => $t['angka_soal']],
                'answer_key_json' => [
                    'nilai' => $t['angka'],
                    'toleransi' => $t['toleransi'],
                    'satuan' => $t['satuan'],
                ],
                'scoring_config_json' => ['bobot' => $bobot[7]],
                'opsi' => [],
            ],

            // 9 --------------------------------------------------- Uraian
            [
                'code' => null,
                'title' => 'Uraian - '.$t['pokok'],
                'question_type' => JenisSoal::Uraian->value,
                'difficulty' => 'sulit',
                'prompt_json' => ['teks' => $t['uraian']],
                // Kunci uraian adalah RUBRIKNYA - SkemaJenisSoal menolak kunci
                // berisi untuk jenis ini, dan penolakan itu yang mencegah soal
                // yang terlihat bisa dinilai mesin padahal tidak.
                'answer_key_json' => null,
                'scoring_config_json' => ['bobot' => $bobot[8]],
                'rubrik' => [
                    ['criterion' => 'Ketepatan konsep', 'description' => 'Memakai konsep yang sesuai dengan pertanyaan.', 'max_score' => 5, 'position' => 0],
                    ['criterion' => 'Kelengkapan langkah', 'description' => 'Menuliskan langkah pengerjaan, bukan hanya hasilnya.', 'max_score' => 3, 'position' => 1],
                    ['criterion' => 'Kesimpulan', 'description' => 'Menutup jawaban dengan kesimpulan yang menjawab pertanyaan.', 'max_score' => 2, 'position' => 2],
                ],
            ],

            // 10 --------------------------------------------------- Media
            [
                'code' => null,
                'title' => 'Media - '.$t['pokok'],
                'question_type' => JenisSoal::Media->value,
                'difficulty' => 'sedang',
                'prompt_json' => ['teks' => $t['media_soal']],
                'answer_key_json' => ['opsi' => [$t['media_kunci']]],
                // `mekanisme` yang menentukan cara menilai soal media, bukan
                // jenisnya - lihat catatan JenisSoal dan SkemaJenisSoal::media().
                'scoring_config_json' => ['bobot' => $bobot[9], 'mekanisme' => 'pilihan'],
                'opsi' => $this->opsiAbjad($t['media_opsi']),
                'media' => $t['media'],
            ],
        ];
    }

    /** Empat pilihan jawaban a-d dari daftar teks. */
    private function opsiAbjad(array $teks): array
    {
        $hasil = [];

        foreach (array_values($teks) as $i => $satu) {
            $hasil[] = [
                'option_key' => chr(97 + $i),
                'content_json' => ['teks' => $satu],
                'position' => $i,
            ];
        }

        return $hasil;
    }

    /**
     * Tulis berkas media contoh dan barisnya.
     *
     * ---------------------------------------------------------------------
     * Satu-satunya tabel anak yang ditulis LANGSUNG oleh seeder ini
     * ---------------------------------------------------------------------
     * Seluruh tabel anak soal yang lain ditulis `BankSoalService`, karena
     * kuncinya harus sepakat dengan `is_correct`. Media adalah pengecualiannya,
     * dan alasannya tertulis di docblock `BankSoalService`: id baris media ADA
     * di URL rute berkas, sehingga ia dikelola lewat jalur unggah/hapus
     * tersendiri, bukan lewat payload penyuntingan soal. Jalur itu belum ada di
     * Fase 4 (unggahan berkas menyusul), dan seeder yang menunggunya berarti
     * paket contoh tanpa satu pun gambar.
     *
     * ---------------------------------------------------------------------
     * Berkasnya benar-benar ditulis, bukan sekadar barisnya
     * ---------------------------------------------------------------------
     * Baris `question_media` tanpa berkas di disk adalah penunjuk yang menunjuk
     * ke tempat kosong, dan laman yang membukanya gagal dengan cara yang tidak
     * terlihat di daftar mana pun. Gambarnya dibangkitkan GD - satu-satunya
     * cara mendapat PNG sungguhan tanpa menambahkan berkas biner ke repo.
     */
    private function simpanMedia(int $questionId, int $schoolId, array $media): void
    {
        $png = $this->pngContoh();
        $jalur = 'soal/'.$schoolId.'/'.$questionId.'/'.Str::random(40).'.png';

        Storage::disk('local')->put($jalur, $png);

        QuestionMedia::create([
            'school_id' => $schoolId,
            'question_id' => $questionId,
            'media_type' => $media['tipe'],
            'mime_type' => 'image/png',
            'storage_path' => $jalur,
            'nama_asli' => $media['nama'],
            'ukuran' => strlen($png),
            'metadata_json' => ['judul' => $media['judul']],
        ]);
    }

    /**
     * Grafik batang sederhana, dibangkitkan GD.
     *
     * Sengaja bergaris dan berlabel, bukan kotak berwarna rata: yang diuji
     * laman soal adalah gambar yang punya ukuran dan bentuk nyata, dan gambar
     * satu warna tidak akan memperlihatkan apa pun tentang tata letak yang
     * tidak muat.
     */
    private function pngContoh(): string
    {
        $lebar = 560;
        $tinggi = 280;

        $kanvas = imagecreatetruecolor($lebar, $tinggi);
        $putih = imagecolorallocate($kanvas, 255, 255, 255);
        $biru = imagecolorallocate($kanvas, 30, 64, 124);
        $abu = imagecolorallocate($kanvas, 180, 186, 196);

        imagefill($kanvas, 0, 0, $putih);

        // Sumbu.
        imageline($kanvas, 60, 20, 60, $tinggi - 40, $biru);
        imageline($kanvas, 60, $tinggi - 40, $lebar - 20, $tinggi - 40, $biru);

        // Batang.
        foreach ([70, 130, 190, 110] as $i => $tinggiBatang) {
            $x = 100 + ($i * 100);
            imagefilledrectangle(
                $kanvas,
                $x,
                $tinggi - 40 - $tinggiBatang,
                $x + 56,
                $tinggi - 40,
                $i % 2 === 0 ? $biru : $abu,
            );
        }

        ob_start();
        imagepng($kanvas);
        $png = (string) ob_get_clean();
        imagedestroy($kanvas);

        return $png;
    }

    /**
     * Dua berkas impor: satu tersimpan bersih, satu bergalat tepat barisnya.
     *
     * ---------------------------------------------------------------------
     * Mengapa keduanya BENAR-BENAR diimpor, bukan ditulis ke tabelnya
     * ---------------------------------------------------------------------
     * Laporan galat menyimpan nomor baris yang dihasilkan PEMBACA berkas.
     * Baris yang ditulis tangan di seeder akan memuat nomor yang karangan -
     * dan laporan yang menunjuk baris yang salah lebih buruk daripada laporan
     * yang kosong, karena Guru memperbaiki baris yang tidak bermasalah.
     *
     * Lewat jalur unggah -> pratinjau -> konfirmasi, nomor barisnya dihitung
     * dari berkas CSV yang benar-benar ditulis di sini.
     *
     * @return array{bersih: QuestionImportBatch, beralat: QuestionImportBatch}
     */
    private function bangunImporSoal(int $schoolId, Subject $mapel, int $guruId): array
    {
        $impor = app(ImporSoalService::class);

        $bersih = $impor->konfirmasi(
            $impor->pratinjau(
                $impor->unggah(
                    $this->berkasImpor($this->barisBersih($mapel->name), 'impor-bersih.csv'),
                    $schoolId,
                    $guruId,
                    (int) $mapel->id,
                ),
            ),
            // `false` = ke bank soal saja. Paket contoh sudah berisi tepat
            // sepuluh jenis, dan menambahinya dengan hasil impor akan membuat
            // "berisi kesepuluh jenis" berhenti menjadi pernyataan yang bisa
            // diperiksa.
            false,
        );

        $beralat = $impor->konfirmasi(
            $impor->pratinjau(
                $impor->unggah(
                    $this->berkasImpor($this->barisBeralat($mapel->name), 'impor-beralat.csv'),
                    $schoolId,
                    $guruId,
                    (int) $mapel->id,
                ),
            ),
            false,
        );

        return ['bersih' => $bersih, 'beralat' => $beralat];
    }

    /**
     * Baris CSV yang seluruhnya sah.
     *
     * @return array<int, array<int, string>>
     */
    private function barisBersih(string $nama): array
    {
        return [
            $this->judulImpor(),
            ['', 'Pengertian dasar '.$nama, 'pilihan_ganda', 'mudah', '1',
                'Pernyataan berikut yang paling tepat menggambarkan '.$nama.' adalah...',
                'Pilihan pertama', 'Pilihan kedua', 'Pilihan ketiga', 'Pilihan keempat', '', 'a', '', ''],
            ['', 'Istilah kunci '.$nama, 'isian_singkat', 'sedang', '2',
                'Sebutkan istilah yang dipakai untuk menyebut '.$nama.' pada tingkat dasar.',
                '', '', '', '', '', 'istilah|istilahnya', '', ''],
            ['', 'Pernyataan '.$nama, 'benar_salah', 'mudah', '1',
                'Seluruh materi '.$nama.' di tingkat X bersifat hafalan.',
                '', '', '', '', '', 's', '', ''],
            ['', 'Uraian '.$nama, 'uraian', 'sulit', '4',
                'Jelaskan langkah-langkah menyelesaikan persoalan '.$nama.' beserta alasannya.',
                '', '', '', '', '', '', 'Langkah pengerjaan:3 | Alasan:1', ''],
        ];
    }

    /**
     * Baris CSV yang sebagian gagal - dan gagalnya di baris yang jauh dari atas.
     *
     * Dua galat sengaja diletakkan di baris 5 dan 6, bukan di baris 2: laporan
     * yang menunjuk baris pertama selalu benar secara kebetulan, dan justru
     * kasus "galat di tengah berkas" yang membuktikan nomor barisnya dihitung
     * dan bukan dikarang.
     *
     * @return array<int, array<int, string>>
     */
    private function barisBeralat(string $nama): array
    {
        return [
            $this->judulImpor(),
            ['', 'Soal angka '.$nama, 'angka', 'sedang', '2',
                'Berapa hasil perhitungan pada soal '.$nama.' berikut?', '', '', '', '', '', '12,5', '', ''],
            ['', 'Soal urut '.$nama, 'mengurutkan', 'sedang', '2',
                'Urutkan langkah penyelesaian '.$nama.' dari yang pertama.',
                'Langkah pertama', 'Langkah kedua', 'Langkah ketiga', '', '', 'c, a, b', '', ''],
            // Galat 1: item kiri 'a' tidak punya pasangan di kolom kunci.
            ['', 'Soal jodoh '.$nama, 'menjodohkan', 'sedang', '2',
                'Jodohkan istilah '.$nama.' dengan artinya.',
                'Istilah satu', 'Istilah dua', 'Istilah tiga', '', '', 'a=Arti satu; b=Arti dua', '', ''],
            // Galat 2: kolom teks soal kosong.
            ['', 'Soal tanpa teks '.$nama, 'pilihan_ganda', 'mudah', '1',
                '', 'Pilihan satu', 'Pilihan dua', '', '', '', 'a', '', ''],
        ];
    }

    /** @return array<int, string> */
    private function judulImpor(): array
    {
        return [
            'kode', 'judul', 'jenis', 'tingkat', 'bobot', 'soal',
            'opsi_a', 'opsi_b', 'opsi_c', 'opsi_d', 'opsi_e',
            'kunci', 'rubrik', 'pembahasan',
        ];
    }

    /**
     * Berkas CSV bertitik koma dari baris-baris sel.
     *
     * Titik koma dan BOM, dan keduanya bukan pilihan gaya: itulah yang
     * dihasilkan Excel berbahasa Indonesia, dan pembaca di aplikasi ini memang
     * menanganinya. Berkas contoh yang ditulis dengan koma berarti seeder ini
     * menguji bentuk yang tidak dipakai siapa pun.
     *
     * @param  array<int, array<int, string>>  $baris
     */
    private function berkasImpor(array $baris, string $nama): UploadedFile
    {
        $isi = "\xEF\xBB\xBF";

        foreach ($baris as $satu) {
            $isi .= implode(';', array_map(
                fn (string $sel) => '"'.str_replace('"', '""', $sel).'"',
                $satu,
            ))."\r\n";
        }

        $jalur = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cbt-seeder-'.$nama;

        file_put_contents($jalur, $isi);

        // Argumen terakhir `true` adalah "test mode": tanpa itu
        // `UploadedFile` menolak berkas yang tidak datang dari unggahan HTTP
        // sungguhan, dan seeder tidak punya unggahan.
        return new UploadedFile($jalur, $nama, 'text/csv', null, true);
    }

    /**
     * Isi contoh per mapel.
     *
     * Enam mapel, satu tema masing-masing - dan yang membedakan bukan panjang
     * teksnya melainkan BENTUK isinya: Matematika memakai pangkat dan blok
     * `rumus`, Bahasa Indonesia memakai tabel bacaan, Fisika memakai gambar.
     * Ketiganya ada supaya tata letak yang tidak muat ketahuan dari data
     * contoh, bukan dari laporan Guru.
     *
     * `rumus` dibawa sebagai kunci tersendiri meski hari ini yang dibaca layar
     * hanya `teks`: bentuk JSON-nya sudah disebut `PembersihHtml` sebagai nama
     * bidang yang sah, dan rumusnya ditulis ulang di dalam `teks` sehingga
     * tidak ada satu pun kalimat soal yang bergantung padanya.
     *
     * @return array<string, array<string, mixed>>
     */
    private function temaPerMapel(): array
    {
        return [
            'MTK' => [
                'pokok' => 'barisan dan deret aritmetika',
                'pg' => 'Diketahui barisan aritmetika 4, 7, 10, 13, ... Berapakah suku ke-10?',
                'kaya' => '<p>Diketahui barisan aritmetika <b>4, 7, 10, 13, ...</b></p>'
                    .'<p>Rumus suku ke-n: <b>U<sub>n</sub> = a + (n − 1)b</b></p>'
                    .'<p>Berapakah suku ke-10 dari barisan tersebut?</p>',
                'rumus' => 'Un = a + (n - 1)b',
                'opsi' => ['31', '30', '34', '28'],
                'kunci' => 'a',
                'pembahasan' => '<p>a = 4 dan b = 3, sehingga U<sub>10</sub> = 4 + 9 × 3 = 31.</p>',
                'ganda' => '<p>Manakah pernyataan yang <b>benar</b> tentang barisan aritmetika? (Pilih lebih dari satu)</p>',
                'ganda_kunci' => ['a', 'c'],
                'pernyataan' => 'Beda pada barisan aritmetika selalu bernilai tetap.',
                'bs' => 'b',
                'isian' => '<p>Suku ke-5 dari barisan 4, 7, 10, ... adalah ...</p>',
                'jawaban' => ['16', 'enam belas'],
                'rumpang' => '<p>Pada barisan 4, 7, 10, ... suku pertamanya adalah {{1}} dan bedanya {{2}}.</p>',
                'rumpang_kunci' => [['4', 'empat'], ['3', 'tiga']],
                'rumpang_pembahasan' => '<p>Suku pertama a = 4, dan beda b = 7 − 4 = 3.</p>',
                'jodoh' => '<p>Jodohkan istilah pada barisan aritmetika dengan lambangnya.</p>',
                'istilah' => ['Suku pertama', 'Beda', 'Suku ke-n'],
                'arti' => ['a', 'b', 'U<sub>n</sub>'],
                'langkah' => ['Menuliskan suku pertama dan beda', 'Menyusun rumus suku ke-n', 'Menghitung suku yang diminta'],
                'urut' => '<p>Urutkan langkah menyelesaikan soal barisan aritmetika dari yang pertama.</p>',
                'angka_soal' => '<p>Pada barisan 4, 7, 10, ... berapakah bedanya?</p>',
                'angka' => 3.0,
                'toleransi' => 0.0,
                'satuan' => '',
                'uraian' => '<p>Sebuah gedung pertemuan memiliki 20 baris kursi. Baris pertama berisi 12 kursi, dan setiap baris berikutnya bertambah 3 kursi. Berapa banyak kursi di baris terakhir? Tuliskan langkah pengerjaanmu.</p>',
                'media_soal' => '<p>Perhatikan grafik hasil pengukuran pada gambar, lalu jawab pertanyaannya.</p>',
                'media_opsi' => ['Nilai tertinggi ada di batang ketiga', 'Nilai tertinggi ada di batang pertama', 'Seluruh batang sama tinggi', 'Nilai terendah ada di batang kedua'],
                'media_kunci' => 'a',
                'media' => [
                    'tipe' => 'gambar',
                    'nama' => 'grafik-batang-matematika.png',
                    'judul' => 'Grafik hasil pengukuran',
                ],
            ],

            'BIN' => [
                'pokok' => 'teks eksposisi',
                'pg' => 'Apa gagasan utama paragraf pertama teks eksposisi tersebut?',
                'kaya' => '<p>Bacalah tabel berikut.</p>'
                    .'<table><thead><tr><th>Bagian</th><th>Isi</th></tr></thead>'
                    .'<tbody><tr><td>Paragraf 1</td><td>Pernyataan umum</td></tr>'
                    .'<tr><td>Paragraf 2</td><td>Argumen pendukung</td></tr>'
                    .'<tr><td>Paragraf 3</td><td>Penegasan ulang</td></tr></tbody></table>'
                    .'<p>Apa gagasan utama paragraf pertama teks eksposisi tersebut?</p>',
                'opsi' => ['Pernyataan umum tentang topik', 'Argumen pendukung penulis', 'Penegasan ulang', 'Contoh dari penulis lain'],
                'kunci' => 'a',
                'pembahasan' => '<p>Paragraf pertama teks eksposisi memuat pernyataan umum.</p>',
                'ganda' => '<p>Manakah yang termasuk ciri teks eksposisi? (Pilih lebih dari satu)</p>',
                'ganda_kunci' => ['a', 'b'],
                'pernyataan' => 'Teks eksposisi selalu memuat unsur cerita fiktif.',
                'bs' => 's',
                'isian' => '<p>Bagian akhir teks eksposisi yang memuat penegasan ulang disebut ...</p>',
                'jawaban' => ['penegasan ulang', 'kesimpulan'],
                'rumpang' => '<p>Teks eksposisi dibuka dengan {{1}} tentang topik, lalu diakhiri dengan {{2}} ulang.</p>',
                'rumpang_kunci' => [['pernyataan umum', 'pernyataan'], ['penegasan']],
                'rumpang_pembahasan' => '<p>Struktur teks eksposisi: pernyataan umum, argumen, lalu penegasan ulang.</p>',
                'jodoh' => '<p>Jodohkan bagian teks eksposisi dengan isinya.</p>',
                'istilah' => ['Paragraf pembuka', 'Paragraf isi', 'Paragraf penutup'],
                'arti' => ['Pernyataan umum', 'Argumen pendukung', 'Penegasan ulang'],
                'langkah' => ['Membaca judul', 'Menemukan gagasan utama', 'Menyimpulkan isi teks'],
                'urut' => '<p>Urutkan langkah memahami teks eksposisi dari yang pertama.</p>',
                'angka_soal' => '<p>Berapa jumlah paragraf pada teks eksposisi di atas?</p>',
                'angka' => 3.0,
                'toleransi' => 0.0,
                'satuan' => 'paragraf',
                'uraian' => '<p>Tulislah satu paragraf eksposisi tentang kebersihan lingkungan sekolah. Sertakan pernyataan umum dan satu argumen pendukung.</p>',
                'media_soal' => '<p>Amati gambar berikut, lalu jawab pertanyaannya.</p>',
                'media_opsi' => ['Gambar menunjukkan kegiatan membaca', 'Gambar menunjukkan kegiatan menulis', 'Gambar menunjukkan kegiatan berdiskusi', 'Gambar menunjukkan kegiatan olahraga'],
                'media_kunci' => 'a',
                'media' => [
                    'tipe' => 'gambar',
                    'nama' => 'ilustrasi-bahasa-indonesia.png',
                    'judul' => 'Ilustrasi kegiatan literasi',
                ],
            ],

            'BIG' => [
                'pokok' => 'simple present tense',
                'pg' => 'Choose the correct form: "She ___ to school every day."',
                'opsi' => ['goes', 'go', 'going', 'gone'],
                'kunci' => 'a',
                'pembahasan' => '<p>Subjek orang ketiga tunggal memakai bentuk "goes".</p>',
                'ganda' => '<p>Which sentences use the simple present tense? (Choose more than one)</p>',
                'ganda_kunci' => ['a', 'b'],
                'pernyataan' => 'The simple present tense is used for habits and routines.',
                'bs' => 'b',
                'isian' => '<p>Complete: "They ___ football every Sunday." (play)</p>',
                'jawaban' => ['play'],
                'rumpang' => '<p>Complete: "She {{1}} to school every day, and they {{2}} football every Sunday."</p>',
                'rumpang_kunci' => [['goes'], ['play']],
                'rumpang_pembahasan' => '<p>"She" memakai bentuk "goes"; "they" memakai bentuk dasar "play".</p>',
                'jodoh' => '<p>Match each subject with its correct verb form.</p>',
                'istilah' => ['I', 'She', 'They'],
                'arti' => ['write', 'writes', 'write'],
                'langkah' => ['Identify the subject', 'Choose the verb form', 'Complete the sentence'],
                'urut' => '<p>Put the steps for building a simple present sentence in order.</p>',
                'angka_soal' => '<p>How many sentences in the text use the simple present tense?</p>',
                'angka' => 4.0,
                'toleransi' => 0.0,
                'satuan' => 'kalimat',
                'uraian' => '<p>Write a short paragraph about your daily routine using the simple present tense. Use at least five sentences.</p>',
                'media_soal' => '<p>Look at the picture, then answer the question.</p>',
                'media_opsi' => ['The picture shows a morning routine', 'The picture shows an evening routine', 'The picture shows a holiday', 'The picture shows a classroom'],
                'media_kunci' => 'a',
                'media' => [
                    'tipe' => 'gambar',
                    'nama' => 'ilustrasi-english.png',
                    'judul' => 'Daily routine',
                ],
            ],

            'FIS' => [
                'pokok' => 'gerak lurus berubah beraturan',
                'pg' => 'Sebuah benda bergerak dengan kecepatan awal 4 m/s dan percepatan 2 m/s². Berapa kecepatannya setelah 3 sekon?',
                'kaya' => '<p>Sebuah benda bergerak dengan kecepatan awal <b>4 m/s</b> dan percepatan tetap <b>2 m/s<sup>2</sup></b>.</p>'
                    .'<p>Berapa kecepatannya setelah 3 sekon?</p>',
                'rumus' => 'v = v0 + a t',
                'opsi' => ['10 m/s', '6 m/s', '12 m/s', '8 m/s'],
                'kunci' => 'a',
                'pembahasan' => '<p>v = 4 + 2 × 3 = 10 m/s.</p>',
                'ganda' => '<p>Manakah besaran yang termasuk besaran turunan? (Pilih lebih dari satu)</p>',
                'ganda_kunci' => ['b', 'c'],
                'pernyataan' => 'Percepatan pada gerak lurus berubah beraturan selalu tetap.',
                'bs' => 'b',
                'isian' => '<p>Satuan percepatan dalam SI adalah ...</p>',
                'jawaban' => ['m/s2', 'meter per sekon kuadrat'],
                'rumpang' => '<p>Kecepatan bersatuan {{1}}, sedangkan percepatan bersatuan {{2}}.</p>',
                'rumpang_kunci' => [['m/s', 'meter per sekon'], ['m/s2', 'meter per sekon kuadrat']],
                'rumpang_pembahasan' => '<p>Percepatan adalah perubahan kecepatan per satuan waktu, sehingga satuannya m/s dibagi sekon.</p>',
                'jodoh' => '<p>Jodohkan besaran dengan satuannya.</p>',
                'istilah' => ['Kecepatan', 'Percepatan', 'Perpindahan'],
                'arti' => ['m/s', 'm/s<sup>2</sup>', 'm'],
                'langkah' => ['Mencatat besaran yang diketahui', 'Memilih rumus yang sesuai', 'Menghitung dan menuliskan satuan'],
                'urut' => '<p>Urutkan langkah menyelesaikan soal gerak lurus dari yang pertama.</p>',
                'angka_soal' => '<p>Berapakah percepatan benda pada soal tersebut (dalam m/s²)?</p>',
                'angka' => 2.0,
                'toleransi' => 0.01,
                'satuan' => 'm/s2',
                'uraian' => '<p>Sebuah mobil dipercepat dari keadaan diam hingga 20 m/s dalam 8 sekon, lalu bergerak dengan kecepatan tetap selama 5 sekon. Hitunglah percepatan mobil dan jarak total yang ditempuh.</p>',
                // Soal media di Fisika memakai GAMBAR, dan itu satu-satunya
                // tempat `question_media` benar-benar berisi di seluruh data
                // contoh - sehingga `PembekuPaket` menyalin `media_json` yang
                // tidak kosong ke snapshot.
                'media_soal' => '<p>Perhatikan grafik kecepatan terhadap waktu pada gambar, lalu jawab pertanyaannya.</p>',
                'media_opsi' => ['Batang tertinggi ada di batang ketiga', 'Batang tertinggi ada di batang pertama', 'Semua batang sama tinggi', 'Batang terendah ada di batang ketiga'],
                'media_kunci' => 'a',
                'media' => [
                    'tipe' => 'gambar',
                    'nama' => 'grafik-kecepatan-fisika.png',
                    'judul' => 'Grafik kecepatan terhadap waktu',
                ],
            ],

            'KIM' => [
                'pokok' => 'struktur atom',
                'pg' => 'Berapa jumlah proton pada atom karbon dengan nomor atom 6?',
                'opsi' => ['6', '12', '7', '14'],
                'kunci' => 'a',
                'pembahasan' => '<p>Nomor atom menyatakan jumlah proton, yaitu 6.</p>',
                'ganda' => '<p>Manakah yang termasuk partikel penyusun atom? (Pilih lebih dari satu)</p>',
                'ganda_kunci' => ['a', 'b'],
                'pernyataan' => 'Nomor massa sama dengan jumlah proton dan neutron.',
                'bs' => 'b',
                'isian' => '<p>Partikel atom yang bermuatan negatif disebut ...</p>',
                'jawaban' => ['elektron'],
                'rumpang' => '<p>Atom tersusun dari {{1}} yang bermuatan positif dan {{2}} yang bermuatan negatif.</p>',
                'rumpang_kunci' => [['proton'], ['elektron']],
                'rumpang_pembahasan' => '<p>Proton bermuatan positif, elektron bermuatan negatif, neutron netral.</p>',
                'jodoh' => '<p>Jodohkan partikel atom dengan muatannya.</p>',
                'istilah' => ['Proton', 'Elektron', 'Neutron'],
                'arti' => ['Positif', 'Negatif', 'Netral'],
                'langkah' => ['Menuliskan lambang atom', 'Menentukan nomor atom', 'Menghitung jumlah partikel'],
                'urut' => '<p>Urutkan langkah menentukan jumlah partikel atom dari yang pertama.</p>',
                'angka_soal' => '<p>Berapa jumlah elektron pada atom netral dengan nomor atom 6?</p>',
                'angka' => 6.0,
                'toleransi' => 0.0,
                'satuan' => 'elektron',
                'uraian' => '<p>Jelaskan perbedaan nomor atom dan nomor massa, beserta contohnya pada atom karbon.</p>',
                'media_soal' => '<p>Amati gambar berikut, lalu jawab pertanyaannya.</p>',
                'media_opsi' => ['Gambar menunjukkan susunan partikel atom', 'Gambar menunjukkan reaksi kimia', 'Gambar menunjukkan tabel periodik', 'Gambar menunjukkan alat laboratorium'],
                'media_kunci' => 'a',
                'media' => [
                    'tipe' => 'gambar',
                    'nama' => 'ilustrasi-kimia.png',
                    'judul' => 'Susunan partikel atom',
                ],
            ],

            'TKJ' => [
                'pokok' => 'pengalamatan IP',
                'pg' => 'Berapa jumlah host yang tersedia pada jaringan dengan prefix /24?',
                'kaya' => '<p>Sebuah jaringan memakai prefix <b>/24</b>.</p>'
                    .'<p>Berapa jumlah alamat host yang tersedia untuk perangkat?</p>',
                'opsi' => ['254', '256', '255', '252'],
                'kunci' => 'a',
                'pembahasan' => '<p>256 alamat dikurangi alamat jaringan dan broadcast menjadi 254.</p>',
                'ganda' => '<p>Manakah yang termasuk perangkat jaringan? (Pilih lebih dari satu)</p>',
                'ganda_kunci' => ['a', 'c'],
                'pernyataan' => 'Alamat 192.168.1.255 pada jaringan /24 dipakai sebagai alamat host.',
                'bs' => 's',
                'isian' => '<p>Alamat yang dipakai untuk mengirim data ke seluruh perangkat dalam jaringan disebut alamat ...</p>',
                'jawaban' => ['broadcast'],
                'rumpang' => '<p>Pada jaringan /24, alamat {{1}} dipakai untuk broadcast, dan jumlah host yang bisa dipakai adalah {{2}}.</p>',
                'rumpang_kunci' => [['192.168.1.255', '255'], ['254']],
                'rumpang_pembahasan' => '<p>Dari 256 alamat, satu dipakai jaringan dan satu untuk broadcast, sehingga tersisa 254 host.</p>',
                'jodoh' => '<p>Jodohkan istilah jaringan dengan pengertiannya.</p>',
                'istilah' => ['Gateway', 'Subnet mask', 'DNS'],
                'arti' => ['Pintu keluar jaringan', 'Penentu batas jaringan', 'Penerjemah nama domain'],
                'langkah' => ['Menentukan prefix jaringan', 'Menghitung jumlah host', 'Menetapkan rentang alamat'],
                'urut' => '<p>Urutkan langkah perencanaan pengalamatan jaringan dari yang pertama.</p>',
                'angka_soal' => '<p>Berapa nilai oktet terakhir dari subnet mask /24?</p>',
                'angka' => 0.0,
                'toleransi' => 0.0,
                'satuan' => '',
                'uraian' => '<p>Sebuah lab komputer berisi 60 perangkat. Rancang pembagian alamat IP-nya, lalu jelaskan mengapa prefix yang kamu pilih mencukupi.</p>',
                'media_soal' => '<p>Perhatikan gambar berikut, lalu jawab pertanyaannya.</p>',
                'media_opsi' => ['Gambar menunjukkan topologi jaringan', 'Gambar menunjukkan rak server', 'Gambar menunjukkan kabel listrik', 'Gambar menunjukkan papan tulis'],
                'media_kunci' => 'a',
                'media' => [
                    'tipe' => 'gambar',
                    'nama' => 'topologi-jaringan.png',
                    'judul' => 'Topologi jaringan lab',
                ],
            ],
        ];
    }
}

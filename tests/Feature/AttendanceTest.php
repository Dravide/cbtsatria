<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Attendance\Enums\PeranTandaTangan;
use App\Domain\Attendance\Enums\SisiFoto;
use App\Domain\Attendance\Enums\StatusPresensi;
use App\Domain\Attendance\Models\AttendanceLog;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\SessionReportSignature;
use App\Domain\Attendance\Services\LaporanSesiService;
use App\Domain\Event\Models\EventCommitteeMember;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Enums\StatusSesi;
use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Exam\Models\ExamTokenView;
use App\Domain\Exam\Services\PenempatanService;
use App\Domain\Exam\Services\PengawasService;
use App\Domain\Exam\Services\SesiService;
use App\Domain\Exam\Services\TokenSesi;
use App\Domain\Facility\Services\RoomEntryLinkService;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

/**
 * Fase 6 - Portal pengawas & presensi. Wireframe plate 11-18.
 *
 * =====================================================================
 * Satu test per kalimat kriteria blueprint, dan kalimatnya NEGATIF
 * =====================================================================
 * Kriteria selesai Fase 6 bukan daftar fitur, melainkan daftar KALIMAT
 * PENOLAKAN: "pengawas R-02 tidak dapat melihat peserta R-03", "kode ruang
 * lain ditolak meski sah", "tidak ada kredensial yang hidup setelah sesinya
 * berakhir". Yang membuktikan sistemnya benar karena itu adalah test yang
 * MENCOBA PENOLAKAN ITU, bukan test yang membuktikan pembukaannya berhasil.
 *
 * Bentuk yang sama dipakai EventTest dan ExamTest, dengan alasan yang sudah
 * tertulis di keduanya: sistem yang tidak memeriksa apa pun akan lulus
 * setiap test "bahagia".
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create(['name' => 'SMK Negeri 1']);
    $this->lain = School::factory()->create(['name' => 'SMK Negeri 2']);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.presensi',
    ]);

    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create([
        'name' => '2026/2027',
    ]);

    $this->semester = Semester::factory()->create([
        'school_id' => $this->sekolah->id,
        'academic_year_id' => $this->tahun->id,
        'name' => 'Ganjil',
    ]);
});

/**
 * Event + satu ujian + satu sesi + beberapa ruang + penugasan + token.
 *
 * ---------------------------------------------------------------------
 * Jendela sesinya dihitung dari SEKARANG, bukan dari tanggal tetap
 * ---------------------------------------------------------------------
 * Seluruh laman Fase 6 menolak sebelum jendela sesinya terbuka: pintu ruang
 * bilang "ruang ini belum dipakai sesi mana pun", AksesPengawas bilang "sesi
 * ruang ini sudah berakhir", dan `RuangTugasController` merender `boleh_buka`
 * false. Test yang memakai tanggal tetap karena itu akan lulus pada satu
 * minggu dan gagal pada minggu berikutnya - dan kegagalannya menunjuk ke
 * laman, bukan ke tanggal di dalam testnya.
 *
 * ---------------------------------------------------------------------
 * Kapasitas ruang dihitung dari jumlah siswanya, dan itu WAJIB
 * ---------------------------------------------------------------------
 * `PenempatanService::sebar()` mengisi ruang pertama sampai PENUH sebelum
 * pindah ke ruang berikutnya. Ruang berkapasitas 60 dengan enam siswa karena
 * itu menempatkan keenam-enamnya di R-01 dan meninggalkan R-02 serta R-03
 * kosong - sehingga seluruh test isolasi ruang di berkas ini akan gagal pada
 * `firstOrFail()`, dengan pesan yang menunjuk ke peserta yang tidak ada
 * alih-alih ke kapasitasnya.
 *
 * ---------------------------------------------------------------------
 * Status sesinya dijadikan `siap` lebih dulu
 * ---------------------------------------------------------------------
 * `StatusSesi` hanya mengizinkan Draft -> Siap -> Berjalan; Draft -> Berjalan
 * DITOLAK. Pemindahan itu dilakukan lewat `SesiService::siapkan()` - jalur
 * yang sama dengan Admin - sejak metode itu ada (2026-11-07). Sebelumnya di
 * sini ada `forceFill`, dan justru itulah yang menyembunyikan bugnya: aplikasi
 * tidak punya penghasil `Siap` sama sekali, sehingga tombol "Buka" di laman
 * jadwal selalu ditolak sementara test-test ini hijau.
 *
 * ---------------------------------------------------------------------
 * Kode ruang memuat id eventnya
 * ---------------------------------------------------------------------
 * Sama seperti helper Fase 5, dan dengan alasan yang sama: `unique` pada kode
 * ruang dan nama kelas berarti dua event di sekolah yang sama pada tahun
 * ajaran yang sama tidak boleh memakai nama yang sama. Tanpa akhiran ini,
 * test yang menyiapkan event KEDUA gagal dengan pelanggaran integritas yang
 * tidak menyebut sebabnya sama sekali.
 *
 * @param  array<int, string>  $namaRuang
 * @param  array<int, User>|null  $pengawasPerRuang  Satu orang per ruang, berurutan.
 * @return array{event: SchoolEvent, ujian: Exam, sesi: ExamSession, ruang: Collection<int, ExamSessionRoom>, siswa: array<int, User>}
 */

/** Peserta ke-N satu ruang - untuk menebak ID yang bukan milik kita. */
function pesertaRuang(ExamSessionRoom $ruang, int $nomor = 0): ExamParticipant
{
    return ExamParticipant::query()
        ->where('exam_session_room_id', $ruang->id)
        ->orderBy('id')
        ->skip($nomor)
        ->firstOrFail();
}

/** Nama pengawas yang ditugaskan di satu ruang-sesi. */
function pengawasRuang(ExamSessionRoom $ruang): User
{
    return $ruang->supervisors()->first()->supervisor;
}

/* =====================================================================
 | Isolasi ruang - satu tempat, bukan sepuluh controller
 |===================================================================== */

it('pengawas R-02 menebak ID peserta R-03 dan menerima 404', function () {
    $pengawas = collect(range(1, 3))->map(
        fn (int $i) => User::factory()->pengawas()->untukSekolah($this->sekolah)->create([
            'username' => 'pengawas.r0'.$i,
        ]),
    );

    $data = siapkanPresensi(
        $this->sekolah, $this->tahun, $this->semester, $this->admin,
        ['R-01', 'R-02', 'R-03'],
        6,
        $pengawas->all(),
    );

    [$r01, $r02, $r03] = [$data['ruang'][0], $data['ruang'][1], $data['ruang'][2]];

    $pesertaR03 = pesertaRuang($r03);

    // Ruangnya sendiri harus benar-benar bisa dibuka, supaya test ini
    // membuktikan penolakan atas ID YANG SALAH - bukan atas halaman yang
    // memang tidak pernah bisa dibuka siapa pun.
    masukSebagai($this, $pengawas[1])
        ->get("/pengawas/ruang/{$r02->id}/presensi")
        ->assertOk();

    // Tiga bentuk tebakan, dan ketiganya harus berakhir sama.
    // (1) Peserta ruang lain, pada rute ruang sendiri.
    masukSebagai($this, $pengawas[1])
        ->get("/pengawas/ruang/{$r02->id}/presensi/{$pesertaR03->id}")
        ->assertNotFound();

    // (2) Ruang orang lain, langsung.
    masukSebagai($this, $pengawas[1])
        ->get("/pengawas/ruang/{$r03->id}/presensi")
        ->assertNotFound();

    // (3) Menandai peserta ruang lain lewat record_id - jalur yang tidak
    //     menyebut peserta di URL sama sekali. Yang menahannya adalah
    //     penyaringan ulang di dalam KehadiranService, dan itulah sebabnya
    //     penyaringan itu ada di dua tempat, bukan satu.
    $recordR03 = AttendanceRecord::query()
        ->where('exam_participant_id', $pesertaR03->id)
        ->firstOrFail();

    masukSebagai($this, $pengawas[1])
        ->post("/pengawas/ruang/{$r02->id}/presensi/verifikasi", [
            'record_id' => $recordR03->id,
            'status' => StatusPresensi::Hadir->value,
        ])
        ->assertNotFound();

    expect($recordR03->fresh()->attendance_status)->toBe(StatusPresensi::Pending)
        ->and(AttendanceLog::query()->count())->toBe(0);

    // R-01 pun tidak boleh menyentuhnya: hanya R-03 yang punya peserta itu.
    masukSebagai($this, $pengawas[0])
        ->get("/pengawas/ruang/{$r01->id}/presensi/{$pesertaR03->id}")
        ->assertNotFound();
});

it('permintaan lewat tautan ruang tidak pernah melihat baris sekolah lain', function () {
    $adminLain = User::factory()->admin()->untukSekolah($this->lain)->create();
    $tahunLain = AcademicYear::factory()->untukSekolah($this->lain)->aktif()->create(['name' => '2026/2027']);
    $semesterLain = Semester::factory()->create([
        'school_id' => $this->lain->id,
        'academic_year_id' => $tahunLain->id,
        'name' => 'Ganjil',
    ]);

    $lain = siapkanPresensi($this->lain, $tahunLain, $semesterLain, $adminLain, ['R-01'], 2);

    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);

    $tautan = app(RoomEntryLinkService::class)->terbitkan(ruangMaster($data['ruang'][0]), berlakuJam: 4);

    // Masuk lewat pintunya, seperti pengawas di depan kelas. Kodenya milik
    // pengawas yang ditugaskan di ruang itu - bukan milik tautannya.
    $this->post("/p/{$tautan->slug}", ['kode' => $data['kode'][$data['ruang'][0]->id]])
        ->assertRedirect(route('pengawas.dashboard'));

    // ------------------------------------------------------------------
    // Yang diperiksa: APAKAH resolver punya konteks sekolah sama sekali
    // ------------------------------------------------------------------
    // `tetapkan()` di AksesPengawas adalah satu-satunya yang mengisinya pada
    // permintaan ber-tautan. Kalau ia terlupa, satu-satunya gejalanya adalah
    // `id()` yang tetap null - dan null berarti "jangan saring", sehingga
    // seluruh query di laman pengawas berjalan lintas sekolah tanpa satu pun
    // galat. Test ini gagal justru pada bentuk kegagalan itu.
    $this->get('/pengawas')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Pengawas/RuangTugas')
            ->where('pelaku', 'tautan')
            // TEPAT SATU kartu - ruang yang tautannya dipegang, bukan dua,
            // dan bukan ruang milik sekolah lain.
            ->has('ruang', 1)
        );

    // Ruang sekolah lain tetap 404 meski id-nya ditebak. Ini tempat isolasi
    // tenant dan isolasi ruang bertemu.
    $this->get("/pengawas/ruang/{$lain['ruang'][0]->id}/presensi")->assertNotFound();
    $this->get("/pengawas/ruang/{$lain['ruang'][0]->id}/token")->assertNotFound();
});

/* =====================================================================
 | Presensi append-only
 |===================================================================== */

it('setiap perubahan presensi menulis satu baris attendance_logs', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin);

    $ruang = $data['ruang'][0];
    $pengawas = pengawasRuang($ruang);

    $records = AttendanceRecord::query()
        ->whereIn('exam_participant_id', ExamParticipant::query()
            ->where('exam_session_room_id', $ruang->id)
            ->pluck('id'))
        ->orderBy('id')
        ->get();

    expect($records->count())->toBeGreaterThanOrEqual(2);

    // (1) Verifikasi massal DUA peserta dengan SATU permintaan - dan lognya
    //     tetap dua baris. Kalau verifikasiMassal menulis satu baris untuk dua
    //     perubahan, lognya berhenti menjawab pertanyaan per siswa, dan
    //     pertanyaan itu memang ditanyakan per siswa.
    masukSebagai($this, $pengawas)
        ->post("/pengawas/ruang/{$ruang->id}/presensi/massal", [
            'record_ids' => [$records[0]->id, $records[1]->id],
            'status' => StatusPresensi::Hadir->value,
        ])
        ->assertRedirect();

    expect(AttendanceLog::query()->count())->toBe(2)
        ->and($records[0]->fresh()->attendance_status)->toBe(StatusPresensi::Hadir)
        ->and($records[0]->fresh()->pelaku_label)->toBe($pengawas->name);

    // (2) Status yang SAMA tidak menulis log. Menandai hadir dua kali karena
    //     tombolnya tersentuh dua kali bukan perubahan.
    masukSebagai($this, $pengawas)
        ->post("/pengawas/ruang/{$ruang->id}/presensi/massal", [
            'record_ids' => [$records[0]->id],
            'status' => StatusPresensi::Hadir->value,
        ])
        ->assertRedirect();

    expect(AttendanceLog::query()->count())->toBe(2);

    // (3) Koreksi menulis baris BARU yang menyebut status lama dan alasannya.
    masukSebagai($this, $pengawas)
        ->post("/pengawas/ruang/{$ruang->id}/koreksi/{$records[0]->exam_participant_id}", [
            'status' => StatusPresensi::Terlambat->value,
            'alasan' => 'Datang pukul 07:45.',
        ])
        ->assertRedirect();

    $log = AttendanceLog::query()->orderByDesc('id')->first();

    expect(AttendanceLog::query()->count())->toBe(3)
        ->and($log->old_status)->toBe(StatusPresensi::Hadir)
        ->and($log->new_status)->toBe(StatusPresensi::Terlambat)
        ->and($log->reason)->toBe('Datang pukul 07:45.');

    // (4) Dikembalikan ke `pending` - dan yang TIDAK ada adalah menghapus
    //     jejaknya. Empat baris, bukan tiga, dan baris terakhirnya menyatakan
    //     bahwa peserta ini pernah ditandai lalu dikembalikan.
    masukSebagai($this, $pengawas)
        ->post("/pengawas/ruang/{$ruang->id}/kembalikan/{$records[0]->exam_participant_id}", [
            'alasan' => 'Salah baris saat daftarnya bergeser.',
        ])
        ->assertRedirect();

    expect(AttendanceLog::query()->count())->toBe(4)
        ->and($records[0]->fresh()->attendance_status)->toBe(StatusPresensi::Pending);
});

it('tidak ada rute yang mengubah atau menghapus attendance_logs', function () {
    // ------------------------------------------------------------------
    // Append-only ditegakkan di tiga lapis, dan ketiganya diperiksa di sini
    // ------------------------------------------------------------------
    // (1) Modelnya tidak punya `updated_at`, sehingga barisnya tidak bisa
    //     disunting lewat Eloquent tanpa memalsukan kolom yang tidak ada.
    expect(AttendanceLog::UPDATED_AT)->toBeNull();

    // (2) Tidak ada rute tulis selain GET dan POST di portal pengawas. DELETE
    //     dan PUT adalah dua kata kerja yang tidak punya arti di sini:
    //     membatalkan sesuatu berarti menulis baris baru, bukan menghapus
    //     yang lama.
    $terlarang = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'pengawas/')
            || str_starts_with($r->uri(), 'laporan/'))
        ->flatMap(fn ($r) => collect($r->methods())
            ->filter(fn (string $m) => ! in_array($m, ['GET', 'HEAD', 'POST'], true))
            ->map(fn (string $m) => $m.' '.$r->uri()))
        ->values()
        ->all();

    expect($terlarang)->toBe([]);

    // (3) Tidak ada satu berkas pun di aplikasi yang menyunting atau
    //     menghapus barisnya. Pemeriksaan atas kode sumber ini yang paling
    //     kasar, dan justru itu gunanya: ia menangkap pelanggaran yang
    //     ditulis di tempat yang tidak dilewati rute mana pun - seeder,
    //     perintah artisan, atau job.
    $pelanggar = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'))) as $berkas) {
        if (! $berkas->isFile() || $berkas->getExtension() !== 'php') {
            continue;
        }

        $isi = file_get_contents($berkas->getPathname());

        if ($isi === false) {
            continue;
        }

        foreach (['AttendanceLog::query()->update', 'AttendanceLog::query()->delete'] as $pola) {
            if (str_contains($isi, $pola)) {
                $pelanggar[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $berkas->getPathname());
            }
        }
    }

    expect(array_unique($pelanggar))->toBe([]);
});

/* =====================================================================
 | Token sesi
 |===================================================================== */

it('pengawas sesi 2 membaca token sesinya, dan pengawas sesi lain ditolak', function () {
    $pengawasSesi1 = User::factory()->pengawas()->untukSekolah($this->sekolah)->create([
        'username' => 'pengawas.sesi1',
    ]);
    $pengawasSesi2 = User::factory()->pengawas()->untukSekolah($this->sekolah)->create([
        'username' => 'pengawas.sesi2',
    ]);

    $data = siapkanPresensi(
        $this->sekolah, $this->tahun, $this->semester, $this->admin,
        ['R-01', 'R-02'], 4, [$pengawasSesi1],
    );

    // Sesi 2 di ruang yang sama, pada jendela yang JUGA sedang terbuka.
    //
    // Jendelanya harus terbuka, dan alasannya ada di dalam TokenSesi: token
    // mewarisi `valid_from = access_opens_at` sesinya, sehingga token sesi
    // yang belum dibuka memang BELUM berlaku - dan `baca()` menolaknya dengan
    // pesan yang berbeda ("token sudah tidak berlaku"). Test yang menaruh
    // sesi 2 di jendela berikutnya karena itu menguji penolakan yang salah.
    //
    // Jendela yang bertumpang tidak membuat pengawasnya bentrok, karena
    // pengawas sesi 2 adalah ORANG YANG BERBEDA - dan itulah bentuk yang
    // memang dipakai sekolah: satu ruang, dua sesi, dua pengawas.
    $sesi2 = app(SesiService::class)->buat($data['ujian'], [
        'session_number' => 2,
        'access_opens_at' => now()->modify('-30 minutes'),
        'access_closes_at' => now()->modify('+30 minutes'),
        'hard_end_at' => now()->modify('+1 hour'),
        'event_room_id' => $data['event']->rooms()->pluck('id')->all(),
    ]);

    $ruangSesi2 = $sesi2->rooms()->orderBy('id')->get();

    EventCommitteeMember::create([
        'school_id' => $this->sekolah->id,
        'event_id' => $data['event']->id,
        'user_id' => $pengawasSesi2->id,
        'name' => $pengawasSesi2->name,
        'position' => 'pengawas',
        'scope' => 'event',
        'status' => 'aktif',
    ]);

    app(PengawasService::class)->tugaskan($ruangSesi2[0], $pengawasSesi2, $this->admin->id);
    app(PengawasService::class)->tugaskan(
        $ruangSesi2[1],
        User::factory()->pengawas()->untukSekolah($this->sekolah)->create(),
        $this->admin->id,
    );

    app(TokenSesi::class)->terbitkan($sesi2, $this->admin->id);
    $sesi2->forceFill(['status' => StatusSesi::Siap])->save();

    $ruang = $ruangSesi2[0];

    // (1) Pengawas sesi 2 MEMBACA token sesinya.
    masukSebagai($this, $pengawasSesi2)
        ->get("/pengawas/ruang/{$ruang->id}/token")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Pengawas/Token')
            ->where('token.berlaku', true)
            // Bentuk terbacanya BUKAN prop: plate 12 menampilkannya tertutup
            // sampai tombolnya ditekan, dan token yang selalu ada di dalam
            // props adalah token yang ikut tersimpan di riwayat peramban.
            ->missing('kode_token')
        );

    masukSebagai($this, $pengawasSesi2)
        ->post("/pengawas/ruang/{$ruang->id}/token/baca")
        ->assertRedirect()
        ->assertSessionHas('kode_token');

    // Pembacaannya TERCATAT, dan tercatat ATAS RUANGNYA - itu yang membuat
    // "token bocor, asalnya bisa ditelusuri" punya jawaban.
    $view = ExamTokenView::query()->orderByDesc('id')->first();

    expect($view)->not->toBeNull()
        ->and($view->user_id)->toBe($pengawasSesi2->id)
        ->and($view->room_id)->toBe(ruangMaster($ruang)->id);

    // (2) Pengawas sesi 1 ditolak membaca token sesi 2 - lewat ruang sesi 2,
    //     yang memang bukan ruangnya. 404, bukan 403: ruang orang lain harus
    //     tampak tidak ada, karena 403 justru membuktikan bahwa ia ada.
    masukSebagai($this, $pengawasSesi1)
        ->get("/pengawas/ruang/{$ruang->id}/token")
        ->assertNotFound();

    masukSebagai($this, $pengawasSesi1)
        ->post("/pengawas/ruang/{$ruang->id}/token/baca")
        ->assertNotFound();

    expect(ExamTokenView::query()->count())->toBe(1);
});

/* =====================================================================
 | Berita acara
 |===================================================================== */

it('pengawas R-03 tidak dapat membuka berita acara R-02', function () {
    $pengawas = collect(range(1, 3))->map(
        fn (int $i) => User::factory()->pengawas()->untukSekolah($this->sekolah)->create(),
    );

    $data = siapkanPresensi(
        $this->sekolah, $this->tahun, $this->semester, $this->admin,
        ['R-01', 'R-02', 'R-03'], 6, $pengawas->all(),
    );

    [$r02, $r03] = [$data['ruang'][1], $data['ruang'][2]];

    // R-03 membuka berita acaranya sendiri: berhasil.
    masukSebagai($this, $pengawas[2])
        ->get("/pengawas/ruang/{$r03->id}/berita-acara")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Pengawas/BeritaAcara'));

    // R-02: tidak ada, dalam bentuk apa pun.
    masukSebagai($this, $pengawas[2])
        ->get("/pengawas/ruang/{$r02->id}/berita-acara")
        ->assertNotFound();

    masukSebagai($this, $pengawas[2])
        ->post("/pengawas/ruang/{$r02->id}/berita-acara/ttd", [
            'peran' => PeranTandaTangan::Pengawas->value,
            'nama' => 'Pengawas R-03',
            'tanda_tangan' => 'data:image/png;base64,'.base64_encode('ttd'),
        ])
        ->assertNotFound();

    // Membuka laman berita acara R-03 membuat DRAF kosong di R-03, dan itu
    // memang yang dijanjikan `untuk()`: Admin yang menunggu laporan bisa
    // melihat ruang mana yang sudah dibuka. Yang TIDAK boleh ada adalah tanda
    // tangan atas nama R-03 yang ditulis ke atas R-02.
    expect(SessionReportSignature::query()->where('nama', 'Pengawas R-03')->exists())->toBeFalse();
});

it('admin mengunduh berita acara, dan ditolak saat menandatangani', function () {
    Storage::fake('local');

    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $ruang = $data['ruang'][0];
    $pengawas = pengawasRuang($ruang);

    $laporan = app(LaporanSesiService::class)->untuk($ruang);

    // Foto disimpan lewat service yang sama dengan yang dipakai laman, supaya
    // yang diuji adalah berkas yang benar-benar tersimpan di disk privat -
    // bukan baris tabel yang menunjuk berkas yang tidak ada.
    app(LaporanSesiService::class)->simpanFoto(
        $laporan,
        SisiFoto::Sesudah,
        UploadedFile::fake()->image('ruang.jpg'),
    );

    $berkas = $laporan->fresh()->berkas()->firstOrFail();

    // (1) Admin BOLEH membaca dan mengunduh - PRD §3.5 memberinya kolom
    //     "Presensi: monitoring/koreksi", dan Admin yang menyelesaikan
    //     laporan ruang yang pengawasnya sakit adalah Admin yang menolong.
    masukSebagai($this, $this->admin)
        ->get("/pengawas/ruang/{$ruang->id}/berita-acara")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pelaku', 'admin')
            // Tombolnya tidak dirender bagi orang yang permintaannya pasti
            // ditolak.
            ->where('boleh_ttd', false)
            // Satu foto dan satu tanda tangan: keadaannya sendiri yang
            // dipersempit, bukan hanya tampilannya.
            ->has('foto', 1)
            ->where('foto.0.sisi', SisiFoto::Sesudah->value)
            ->has('tanda_tangan', 1)
            ->where('tanda_tangan.0.peran', PeranTandaTangan::Pengawas->value)
        );

    masukSebagai($this, $this->admin)
        ->get(route('laporan.foto', $berkas))
        ->assertOk();

    // (2) Admin TIDAK boleh menandatangani - dan keduanya ditulis
    //     berdampingan dengan sengaja, karena perbedaannya itulah aturannya:
    //     tanda tangan atas ruang yang tidak pernah ia masuki adalah
    //     keterangan palsu.
    masukSebagai($this, $this->admin)
        ->post("/pengawas/ruang/{$ruang->id}/berita-acara/ttd", [
            'peran' => PeranTandaTangan::Pengawas->value,
            'nama' => 'Admin SMK Negeri 1',
            'tanda_tangan' => 'data:image/png;base64,'.base64_encode('ttd'),
        ])
        ->assertRedirect()
        ->assertSessionHas('galat');

    expect(SessionReportSignature::query()->count())->toBe(0);

    // Pengawas ruangnya sendiri menandatangani dengan data yang sama persis -
    // sehingga yang menolak memang pelakunya, bukan isi permintaannya.
    masukSebagai($this, $pengawas)
        ->post("/pengawas/ruang/{$ruang->id}/berita-acara/ttd", [
            'peran' => PeranTandaTangan::Pengawas->value,
            'nama' => $pengawas->name,
            'tanda_tangan' => 'data:image/png;base64,'.base64_encode('ttd'),
        ])
        ->assertRedirect()
        ->assertSessionHas('sukses');

    expect(SessionReportSignature::query()->count())->toBe(1);
});

it('pengiriman final ditolak tanpa foto sesudah, dan tanpa tanda tangan pengawas', function () {
    Storage::fake('local');

    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin);
    $ruang = $data['ruang'][0];
    $pengawas = pengawasRuang($ruang);

    $sisi = app(LaporanSesiService::class);
    $laporan = $sisi->untuk($ruang);

    // (1) Ditolak, dan penolakannya menyebut APA yang kurang - bukan sekadar
    //     "belum lengkap". Tombol nonaktif tanpa alasan adalah tombol yang
    //     membuat pengawas menekannya berulang kali lalu menelepon Admin.
    masukSebagai($this, $pengawas)
        ->post("/pengawas/ruang/{$ruang->id}/berita-acara/kirim")
        ->assertRedirect()
        ->assertSessionHas('galat');

    expect($laporan->fresh()->final())->toBeFalse()
        ->and($laporan->fresh()->kekuranganFinal())->toContain(
            'Foto sesudah ujian belum diunggah.',
            'Tanda tangan pengawas belum ada.',
        );

    // (2) Laman berita acara hanya menawarkan SATU foto - keadaan ruang
    //     sesudah ujian. Sisi `sebelum` masih ada di enumnya (baris lama di
    //     disk bisa memakainya) tetapi unggahannya ditolak dengan kalimat,
    //     bukan dengan galat 500 dan bukan dengan menyimpannya diam-diam.
    masukSebagai($this, $pengawas)
        ->post("/pengawas/ruang/{$ruang->id}/berita-acara/foto", [
            'sisi' => SisiFoto::Sebelum->value,
            'foto' => UploadedFile::fake()->image('sebelum.jpg'),
        ])
        ->assertRedirect()
        ->assertSessionHas('galat');

    expect($laporan->fresh()->berkas()->count())->toBe(0)
        ->and($laporan->fresh()->kekuranganFinal())->toContain(
            'Foto sesudah ujian belum diunggah.',
            'Tanda tangan pengawas belum ada.',
        );

    // (3) Tanda tangan PANITIA ditolak: yang menandatangani berita acara ini
    //     hanya pengawas ruang. Peran `panitia` tetap ada di enumnya karena
    //     baris lama bisa memakainya, tetapi lamannya tidak menawarkannya -
    //     dan yang tidak ditawarkan tidak boleh bisa diselundupkan lewat
    //     permintaan langsung.
    masukSebagai($this, $pengawas)
        ->post("/pengawas/ruang/{$ruang->id}/berita-acara/ttd", [
            'peran' => PeranTandaTangan::Panitia->value,
            'nama' => 'Panitia Sekolah',
            'tanda_tangan' => 'data:image/png;base64,'.base64_encode('ttd'),
        ])
        ->assertSessionHas('galat');

    expect(SessionReportSignature::query()->count())->toBe(0)
        ->and($laporan->fresh()->kekuranganFinal())->toContain('Tanda tangan pengawas belum ada.');

    // (4) Lengkap: foto sesudah, tanda tangan pengawas, dan kedua pernyataan.
    $sisi->simpanFoto($laporan, SisiFoto::Sesudah, UploadedFile::fake()->image('sesudah.jpg'));

    masukSebagai($this, $pengawas)
        ->post("/pengawas/ruang/{$ruang->id}/berita-acara/ttd", [
            'peran' => PeranTandaTangan::Pengawas->value,
            'nama' => $pengawas->name,
            'tanda_tangan' => 'data:image/png;base64,'.base64_encode('ttd'),
        ])
        ->assertSessionHas('sukses');

    masukSebagai($this, $pengawas)
        ->post("/pengawas/ruang/{$ruang->id}/berita-acara/catatan", [
            'catatan' => 'Seluruh peserta keluar ruang pukul 09:31.',
            'pernyataan_presensi' => true,
            'pernyataan_keluar' => true,
        ])
        ->assertSessionHas('sukses');

    masukSebagai($this, $pengawas)
        ->post("/pengawas/ruang/{$ruang->id}/berita-acara/kirim")
        ->assertRedirect()
        ->assertSessionHas('sukses');

    $final = $laporan->fresh();

    expect($final->final())->toBeTrue()
        ->and($final->submitted_at)->not->toBeNull()
        ->and($final->pelaku_label)->toBe($pengawas->name);

    // Berita acara yang sudah final tidak bisa diubah isinya - kalau bisa,
    // seluruh janji "draf tersimpan otomatis" berubah menjadi lubang. Perannya
    // sengaja `pengawas` supaya yang menolak benar-benar status finalnya,
    // bukan penjaga peran di controller.
    masukSebagai($this, $pengawas)
        ->post("/pengawas/ruang/{$ruang->id}/berita-acara/ttd", [
            'peran' => PeranTandaTangan::Pengawas->value,
            'nama' => 'Orang Lain',
            'tanda_tangan' => 'data:image/png;base64,'.base64_encode('ttd'),
        ])
        ->assertSessionHas('galat');

    expect(SessionReportSignature::query()->where('nama', 'Orang Lain')->exists())->toBeFalse();
});

/* =====================================================================
 | Gerbang presensi -> attempt (blueprint :1413)
 |===================================================================== */

it('menutup gerbang attempt untuk setiap status selain hadir', function () {
    // Blueprint :1413 memerintahkan hal yang tepat, dan mengapa-nya ditulis di
    // sana: "Jangan menunggu Fase 7 untuk menguji attendance gating. Buat test
    // penolakannya sejak Fase 6 agar integrasi presensi-attempt tidak jadi
    // kejutan di akhir."
    //
    // Endpoint `start`-nya milik Fase 7. Yang dikunci di sini adalah ATURANNYA,
    // sendirian - dan itu sebabnya fungsinya menerima saklar alih-alih
    // membacanya.
    expect(StatusPresensi::Pending->membukaGerbang())->toBeFalse()
        ->and(StatusPresensi::TidakHadir->membukaGerbang())->toBeFalse()
        ->and(StatusPresensi::Izin->membukaGerbang())->toBeFalse()
        ->and(StatusPresensi::Hadir->membukaGerbang())->toBeTrue();

    // `terlambat` berdiri di antara keduanya: ia hadir DAN terlambat, dan yang
    // menentukan apakah gerbangnya terbuka adalah keputusan ujiannya - bukan
    // keputusan statusnya.
    expect(StatusPresensi::Terlambat->membukaGerbang(false))->toBeFalse()
        ->and(StatusPresensi::Terlambat->membukaGerbang(true))->toBeTrue();

    // Dan saklarnya benar-benar dibaca dari ujiannya, bukan dari nilai yang
    // ditanam di dalam enumnya.
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin);

    expect($data['ujian']->allow_late_entry)->toBeTrue();
});

/* =====================================================================
 | Hibah ruang: pencabutan dan akhir sesi
 |===================================================================== */

it('mencabut tautan R-01 tidak menyentuh R-02', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01', 'R-02']);

    [$ruangR01, $ruangR02] = [$data['ruang'][0], $data['ruang'][1]];

    $layanan = app(RoomEntryLinkService::class);
    $tautanR01 = $layanan->terbitkan(ruangMaster($ruangR01), berlakuJam: 4);
    $tautanR02 = $layanan->terbitkan(ruangMaster($ruangR02), berlakuJam: 4);

    $layanan->cabutSemua(ruangMaster($ruangR01));

    // Pencabutan menempel pada RUANGNYA, bukan pada sekolahnya: setiap ruang
    // punya barisnya sendiri, sehingga memutus satu pintu tidak memutus pintu
    // lain - tanpa satu baris kode tambahan.
    expect($tautanR01->fresh()->berlaku())->toBeFalse()
        ->and($tautanR02->fresh()->berlaku())->toBeTrue();
});

it('mencabut tautan memutus permintaan berikutnya di tengah sesi', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01']);
    $ruang = $data['ruang'][0];

    $tautan = app(RoomEntryLinkService::class)->terbitkan(ruangMaster($ruang), berlakuJam: 4);

    // Masuk lewat pintunya, lalu buktikan hibahnya memang bekerja.
    $this->post("/p/{$tautan->slug}", ['kode' => $data['kode'][$ruang->id]])
        ->assertRedirect(route('pengawas.dashboard'));

    $this->get("/pengawas/ruang/{$ruang->id}/presensi")->assertOk();

    // Admin mencabutnya. Yang penting: pencabutan itu berlaku pada permintaan
    // BERIKUTNYA, bukan pada login berikutnya - sehingga tidak ada kredensial
    // yang bertahan lebih lama daripada kehendak Admin yang mencabutnya.
    app(RoomEntryLinkService::class)->cabutSemua(ruangMaster($ruang));

    // Yang gagal di sini bukan izinnya, melainkan tiketnya: tautan yang sudah
    // dicabut tidak lagi dikenali `KonteksRuang::tautanSesi()`, sehingga
    // pemegangnya jatuh kembali ke pintu akun - dan karena ia memang tidak
    // punya akun, jawabannya halaman masuk.
    $this->get("/pengawas/ruang/{$ruang->id}/presensi")
        ->assertRedirect(route('login'));
});

it('sesi ditutup memutus hibah ruang yang sedang berjalan', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01']);
    $ruang = $data['ruang'][0];

    $tautan = app(RoomEntryLinkService::class)->terbitkan(ruangMaster($ruang), berlakuJam: 4);

    $this->post("/p/{$tautan->slug}", ['kode' => $data['kode'][$ruang->id]])
        ->assertRedirect(route('pengawas.dashboard'));

    $this->get("/pengawas/ruang/{$ruang->id}/presensi")->assertOk();

    // Sesi ditutup lewat service yang sama dengan yang dipakai Admin.
    $sesi = app(SesiService::class);
    $sesi->mulai($data['sesi']->fresh());
    $sesi->tutup($data['sesi']->fresh());

    expect($data['sesi']->fresh()->status)->toBe(StatusSesi::Selesai);

    // Kredensial yang benar untuk ruang yang sesinya sudah berakhir tidak
    // membuka apa pun. Tanpa pemeriksaan ini, pengawas yang ponselnya masih
    // terbuka bisa memverifikasi presensi untuk ujian yang sudah selesai -
    // dan presensi yang ditulis sesudah ujiannya berakhir adalah presensi
    // yang tidak bisa dipertanggungjawabkan.
    $this->get("/pengawas/ruang/{$ruang->id}/presensi")
        ->assertRedirect(route('pintu.ruang', $tautan->slug));
});

it('menolak permintaan ber-tautan yang sesinya belum dibuka', function () {
    // Jendela yang dimulai satu jam dari sekarang - bentuk yang paling sering
    // terjadi di hari-H: kartu pintunya sudah tertempel, sesinya belum dibuka.
    $data = siapkanPresensi(
        $this->sekolah, $this->tahun, $this->semester, $this->admin,
        ['R-01'], 2, null, '+1 hour', '+2 hours', '+3 hours',
    );

    $tautan = app(RoomEntryLinkService::class)->terbitkan(ruangMaster($data['ruang'][0]), berlakuJam: 4);

    // Pintu ruangnya sendiri memilih ruang-sesi lewat jendela yang SEDANG
    // terbuka, sehingga yang ditampilkan adalah "ruang ini belum dipakai sesi
    // mana pun" - dan itu memang jawaban yang benar bagi pertanyaan "boleh
    // masuk sekarang?". Tautan yang sah untuk sesi yang belum dibuka bukan
    // kegagalan izin, melainkan waktu yang belum tiba.
    $this->post("/p/{$tautan->slug}", ['kode' => $data['kode'][$data['ruang'][0]->id]])
        ->assertRedirect()
        ->assertSessionHas('galat');

    // Dan tanpa hibah, portal pengawas menolak seperti tamu biasa.
    $this->get('/pengawas')->assertRedirect(route('login'));
});

it('menolak tautan pintu yang tidak pernah diterbitkan', function () {
    $this->get('/p/pintu-yang-tidak-pernah-diterbitkan')->assertNotFound();
});

it('menolak kode pengawas yang salah meski tautannya sah', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01'], 2);

    $tautan = app(RoomEntryLinkService::class)->terbitkan(ruangMaster($data['ruang'][0]), berlakuJam: 4);

    $this->post("/p/{$tautan->slug}", ['kode' => '000000'])
        ->assertRedirect()
        ->assertSessionHas('galat');

    // Dan pemakaiannya TIDAK dihitung. Ini yang membedakan urutan benar dari
    // urutan terbalik di `PintuRuangController::buka()`: kalau
    // `catatPemakaian()` dipanggil sebelum kodenya dicocokkan, Admin akan
    // melihat "1 pemakaian" pada tautan yang belum pernah dipakai siapa pun.
    expect($tautan->fresh()->use_count)->toBe(0);

    $this->get('/pengawas')->assertRedirect(route('login'));
});

/* =====================================================================
 | Kode pengawas ruang lain ditolak meski sah
 |===================================================================== */

it('kode pengawas ruang lain ditolak meski sah', function () {
    $data = siapkanPresensi($this->sekolah, $this->tahun, $this->semester, $this->admin, ['R-01', 'R-02']);

    [$ruangR01, $ruangR02] = [$data['ruang'][0], $data['ruang'][1]];

    $tautanR01 = app(RoomEntryLinkService::class)->terbitkan(ruangMaster($ruangR01), berlakuJam: 4);

    // Kode R-02 dimasukkan pada pintu R-01.
    //
    // Sampai 2026-11-07 penolakannya berbunyi "kodenya milik baris ruang
    // lain". Sebabnya sekarang berbeda, dan akibatnya lebih baik: yang
    // ditolak bukan kodenya, melainkan ORANGNYA - pengawas R-02 memang tidak
    // ditugaskan di R-01, sehingga kodenya tidak pernah sampai ke
    // perbandingan sama sekali. Kandidatnya disaring penugasan ruang
    // (`PencocokKodePengawas`), bukan dicocokkan lalu ditolak.
    //
    // Kode itu sendiri SAH: baru saja diterbitkan, belum kedaluwarsa, dan
    // cocok dengan baris kepanitiaan pengawas R-02.
    $this->post("/p/{$tautanR01->slug}", ['kode' => $data['kode'][$ruangR02->id]])
        ->assertRedirect()
        ->assertSessionHas('galat');

    // Hibahnya tidak diberikan, sehingga portal pengawas tetap tertutup, dan
    // ruang R-02 tidak ikut terbuka lewat tautan R-01.
    $this->get('/pengawas')->assertRedirect(route('login'));
    $this->get("/pengawas/ruang/{$ruangR02->id}/presensi")->assertRedirect(route('login'));

    // Satu percobaan yang gagal tidak menaikkan pemakaian tautannya.
    expect($tautanR01->fresh()->use_count)->toBe(0);
});

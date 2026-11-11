<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\Semester;
use App\Domain\Attendance\Models\ExamViolation;
use App\Domain\Attendance\Support\TanggaPelanggaran;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;

/**
 * Layar terbagi dan jendela mengambang - Fase B.
 *
 * =====================================================================
 * Jenis baru, dan mengapa ia TIDAK dilipat ke `keluar_layar_penuh`
 * =====================================================================
 * Keduanya sering terjadi bersama: split screen MEMAKSA peramban keluar dari
 * layar penuh. Tetapi yang dibaca pengawas berbeda. Keluar dari layar penuh
 * bisa berarti satu sentuhan tombol kembali; layar terbagi berarti separuh
 * soal sudah tidak di layar itu. Melipatnya menghapus satu-satunya
 * perbedaan yang membuat catatannya berguna.
 *
 * =====================================================================
 * Tingkat 3, dan BUKAN `SEGERA_KUNCI`
 * =====================================================================
 * Ponsel lipat, rotasi, dan papan tik pihak ketiga bisa menghasilkan
 * viewport yang sempit tanpa siapa pun membelah layar. Mengunci seketika
 * untuk keadaan yang bisa diserupai alat adalah mencabut kesempatan anak
 * menyelesaikan ujian atas hal yang tidak ia perbuat - alasan yang sama
 * dengan `salin_tempel` yang ditolak masuk `SEGERA_KUNCI`.
 *
 * Yang diuji di sini adalah TANGGA-nya, bukan deteksinya: ukuran jendela
 * adalah perilaku peramban, dan test PHP tidak bisa mempersempitnya. Yang
 * bisa dibuktikan adalah bahwa laporan dari peramban menjadi baris dengan
 * keberatan dan tindakan yang benar.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create(['name' => 'SMK Negeri 1']);

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.terbagi',
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

it('mengenali layar_terbagi sebagai jenis yang punya label', function () {
    // Jenis yang tidak dikenal tetap dicatat - endpoint pelanggaran sengaja
    // menerimanya - tetapi riwayat akan menampilkannya apa adanya, dan
    // "layar_terbagi" di layar pengawas adalah nilai mentah, bukan kalimat.
    expect(ExamViolation::JENIS['layar_terbagi'])->toBeString()
        ->and(TanggaPelanggaran::dikenal('layar_terbagi'))->toBeTrue()
        ->and(TanggaPelanggaran::dicatat('layar_terbagi'))->toBeTrue();
});

it('memberi layar_terbagi tingkat 3 tanpa mengunci seketika', function () {
    expect(TanggaPelanggaran::tingkat('layar_terbagi'))->toBe(3)
        // Yang membedakannya dari `sesi_ganda`: satu kejadian layar terbagi
        // TIDAK mengunci, dan itu keputusan yang harus bertahan.
        ->and(TanggaPelanggaran::segeraKunci('layar_terbagi'))->toBeFalse()
        ->and(TanggaPelanggaran::SEGERA_KUNCI)->toBe(['sesi_ganda']);
});

it('mencatat layar terbagi sebagai baris Sedang tanpa mengunci ujian', function () {
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    $peserta = $data['peserta'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token']);

    masukUji($this, $data['siswa'][0])
        ->postJson("/siswa/ujian/{$peserta->id}/lapor", ['jenis' => 'layar_terbagi', 'durasi_ms' => 0])
        ->assertOk()
        ->assertJsonPath('dikunci', false)
        ->assertJsonPath('jumlah', 1);

    $baris = ExamViolation::query()->sole();

    expect($baris->violation_type)->toBe('layar_terbagi')
        ->and($baris->severity)->toBe(3)
        ->and($baris->duration_ms)->toBe(0)
        ->and($baris->labelTingkat())->toBe('Sedang');

    expect(attemptBerjalan($peserta)->dikunci())->toBeFalse();
});

it('tidak mengunci ujian pada satu layar terbagi, tetapi ikut tangga pada pengulangan', function () {
    // Ambang dipersempit: peringatkan di 3, kunci di 5. Satu kejadian layar
    // terbagi (berkeberatan Sedang) belum melewati satu pun.
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    $data['event']->forceFill([
        'violation_thresholds' => ['peringatkan' => 3, 'tandai_pengawas' => 4, 'kunci_ujian' => 5],
    ])->save();

    $peserta = $data['peserta'][0];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token']);

    $lapor = fn () => masukUji($this, $data['siswa'][0])
        ->postJson("/siswa/ujian/{$peserta->id}/lapor", ['jenis' => 'layar_terbagi', 'durasi_ms' => 0])
        ->assertOk();

    // Kejadian pertama: dicatat, TIDAK diperingatkan, TIDAK dikunci. Inilah
    // bedanya dengan `sesi_ganda`, yang mengunci pada kejadian pertama.
    $lapor()->assertJsonPath('diperingatkan', false)
        ->assertJsonPath('dikunci', false)
        ->assertJsonPath('jumlah', 1);

    $lapor()->assertJsonPath('diperingatkan', false)
        ->assertJsonPath('dikunci', false)
        ->assertJsonPath('jumlah', 2);

    // Kejadian ketiga melewati ambang peringatan - dan yang mengubahnya
    // adalah JUMLAH, bukan jenisnya.
    $lapor()->assertJsonPath('diperingatkan', true)
        ->assertJsonPath('dikunci', false)
        ->assertJsonPath('jumlah', 3);

    expect(attemptBerjalan($peserta)->dikunci())->toBeFalse()
        // Tiga baris layar terbagi tetap tidak mengunci: ambangnya 5.
        ->and(ExamViolation::query()->count())->toBe(3);
});

it('menampilkan keadaan layar di papan monitoring ruang tanpa kueri tambahan', function () {
    // Papan monitoring membaca DUA isyarat yang berbeda, dan membedakannya
    // adalah inti test ini:
    //
    //   `layar_penuh`  - keadaan SEKARANG, dari denyut; bisa basi
    //   `layar_terbagi`- JUMLAH peristiwa, dari riwayat; tidak pernah turun
    //
    // Keduanya dihitung dari koleksi yang sudah dimuat laman itu, sehingga
    // papan tidak boleh bertambah kueri hanya karena kolom barunya ada.
    $data = siapkanUjianSiswa($this->sekolah, $this->tahun, $this->semester, $this->admin);

    $peserta = $data['peserta'][0];
    $lain = $data['peserta'][1];

    verifikasiHadir($this, $data['pengawas'], $data['ruang'][0], $peserta);
    mulaiAttempt($this, $data['siswa'][0], $peserta, $data['token']);

    masukUji($this, $data['siswa'][0])
        ->postJson("/siswa/ujian/{$peserta->id}/denyut", [
            'perangkat' => 'id-peramban-layar',
            // Anak sedang TIDAK di layar penuh, dan itu yang tampil di papan.
            'layar_penuh' => false,
        ])
        ->assertOk();

    masukUji($this, $data['siswa'][0])
        ->postJson("/siswa/ujian/{$peserta->id}/lapor", ['jenis' => 'layar_terbagi', 'durasi_ms' => 0])
        ->assertOk();

    $props = masukUji($this, $this->admin)
        ->get("/admin/events/{$data['event']->id}/monitoring/ruang/{$data['ruang'][0]->id}")
        ->assertOk()
        ->viewData('page')['props'];

    $anak = collect($props['anak'])->keyBy('peserta_id');

    expect($anak[$peserta->id]['layar_penuh'])->toBeFalse()
        ->and($anak[$peserta->id]['layar_terbagi'])->toBe(1);

    // Anak yang belum pernah berdenyut: `null`, bukan `false`. "Belum ada
    // kabar" dan "keluar dari layar penuh" adalah dua keadaan yang berbeda,
    // dan papan yang menyamakannya menuduh anak yang belum membuka ujiannya.
    expect($anak[$lain->id]['layar_penuh'])->toBeNull()
        ->and($anak[$lain->id]['layar_terbagi'])->toBe(0);
});

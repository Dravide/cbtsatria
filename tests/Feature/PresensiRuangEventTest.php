<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Services\SchoolResolver;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Laman presensi Admin menghitung penghuni ruang dari KOCOKAN BENIH.
 *
 * =====================================================================
 * Bug yang ditutup berkas ini
 * =====================================================================
 * Sampai 2026-11-10 laman `/admin/events/{event}/presensi` dibangun dari
 * `exam_session_rooms` beserta baris `exam_participants` yang menunjuknya.
 * Pada event yang hanya SEBAGIAN ujiannya punya sesi - keadaan yang paling
 * umum, karena jadwal disusun bertahap - akibatnya tampak di layar: hanya
 * kelas yang ujiannya sudah terjadwal yang muncul. Laporan yang masuk
 * berbunyi "hanya kelas 7 saja yang tampil tidak semua", dan yang disembunyikan
 * laman itu adalah 847 anak.
 *
 * Sekarang penghuninya dihitung dari `exam_event_shuffles.seed` lewat
 * `PetaKursiEvent::rosterEvent()`, sumber yang sama dengan denah, tablo, dan
 * papan pengawas - sehingga "39 peserta" di sini sama dengan "39" di laman
 * Penempatan, dan tidak menunggu Sebar.
 *
 * =====================================================================
 * Yang diuji BUKAN angkanya, melainkan sumbernya
 * =====================================================================
 * Test yang hanya memeriksa `baris.0.rekap.total` akan hijau juga pada
 * fixture yang kebetulan seluruh ujiannya punya sesi. Karena itu fixture di
 * sini sengaja membuat keadaan yang MEMBEDAKAN kedua sumber: kursi yang
 * tersimpan dikosongkan sesudah benihnya tersimpan. Kalau lamanya masih
 * membaca baris tersimpan, seluruh test di berkas ini merah.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create();

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.presensi.ruang',
    ]);

    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create([
        'name' => '2026/2027',
    ]);
});

/** Jumlah kueri yang dijalankan satu permintaan GET. */
function hitungKueriPresensi(TestCase $test, string $url): int
{
    $kueri = 0;

    DB::listen(function () use (&$kueri) {
        $kueri++;
    });

    masukUji($test, $test->admin)->get($url)->assertOk();

    return $kueri;
}

it('menampilkan seluruh penghuni ruang walau kursinya belum tersimpan', function () {
    // Inti perbaikannya. Benihnya tersimpan (lewat "Sebar"), lalu kursi yang
    // tersimpan dikosongkan - bentuk yang SAMA dengan ujian yang belum punya
    // sesi: kocokannya ada, kursinya belum ditulis.
    $d = siapkanKocok($this->admin, $this->tahun);

    masukSebagai($this, $this->admin)
        ->post("/admin/events/{$d['event']->id}/penempatan", ['benih' => 'PRESENSI1'])
        ->assertSessionMissing('galat');

    ExamParticipant::query()
        ->where('exam_id', $d['ujian']->id)
        ->update(['exam_session_room_id' => null, 'exam_session_id' => null]);

    // Baris presensinya ikut dibuang: pada keadaan yang sesungguhnya (ujian
    // kelas 8 di dev, 432 peserta tanpa sesi) `sebar` TIDAK PERNAH berjalan,
    // sehingga `attendance_records`-nya memang tidak ada. Membiarkannya ada
    // akan menguji keadaan yang tidak pernah terjadi - dan membuat `pending`
    // terbaca dari baris `pending` alih-alih dari ketiadaan barisnya.
    AttendanceRecord::query()->delete();

    masukSebagai($this, $this->admin)
        ->get("/admin/events/{$d['event']->id}/presensi")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Event/Presensi')
            // Dua ruang EVENT, bukan dua ruang-sesi - dan jumlahnya tetap dua
            // walau kursinya sudah dikosongkan.
            ->has('baris', 2)
            ->where('baris.0.rekap.total', 4)
            ->where('baris.1.rekap.total', 4)
            ->has('baris.0.peserta', 4)
            // Seluruh delapan peserta event muncul, dan masing-masing punya
            // nomor kursi - urutan yang sama dengan denah.
            ->where('baris.0.peserta.0.kursi', 1)
            ->where('baris.1.peserta.3.kursi', 4)
            // Tanpa baris presensi, statusnya bukan "belum diverifikasi":
            // ujiannya memang belum dijadwalkan untuk anak ini.
            ->where('baris.0.peserta.0.status_label', 'Belum ada sesi')
            ->where('baris.0.peserta.0.record_id', null)
            ->where('angka.peserta', 8));
});

it('menghitung peserta tanpa baris presensi sebagai belum, bukan mengecilkan total', function () {
    // `total` yang diambil dari jumlah BARIS PRESENSI akan terbaca "0
    // peserta" pada ruang yang ujiannya belum dijadwalkan - dan Admin
    // menyimpulkan ruangannya kosong alih-alih melihat 39 anak yang menunggu
    // jadwal. Karena itu `total` = penghuni ruang, dan yang tanpa baris masuk
    // `pending`.
    $d = siapkanKocok($this->admin, $this->tahun);

    masukSebagai($this, $this->admin)
        ->post("/admin/events/{$d['event']->id}/penempatan", ['benih' => 'PRESENSI2'])
        ->assertSessionMissing('galat');

    ExamParticipant::query()
        ->where('exam_id', $d['ujian']->id)
        ->update(['exam_session_room_id' => null, 'exam_session_id' => null]);

    AttendanceRecord::query()->delete();

    masukSebagai($this, $this->admin)
        ->get("/admin/events/{$d['event']->id}/presensi")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('baris.0.rekap.total', 4)
            ->where('baris.0.rekap.pending', 4)
            ->where('baris.0.rekap.hadir', 0)
            // "Belum tuntas" menghitung ruang, bukan peserta.
            ->where('angka.belum_tuntas', 2));
});

it('menautkan baris presensi yang SUDAH ada ke peserta hasil kocokan', function () {
    // Kebalikannya: kursinya tersimpan, dan presensinya sudah ditandai
    // pengawas. Daftar anaknya harus tetap memakai baris presensi itu -
    // `record_id`-nya yang dipakai form koreksi, bukan `peserta_id`.
    $d = siapkanKocok($this->admin, $this->tahun);

    masukSebagai($this, $this->admin)
        ->post("/admin/events/{$d['event']->id}/penempatan", ['benih' => 'PRESENSI3'])
        ->assertSessionMissing('galat');

    $peserta = ExamParticipant::query()
        ->where('exam_id', $d['ujian']->id)
        ->whereNotNull('exam_session_room_id')
        ->firstOrFail();

    $record = AttendanceRecord::query()
        ->where('exam_participant_id', $peserta->id)
        ->firstOrFail();

    $record->forceFill(['attendance_status' => 'hadir', 'verified_at' => now()])->save();

    $props = masukSebagai($this, $this->admin)
        ->get("/admin/events/{$d['event']->id}/presensi")
        ->viewData('page')['props'];

    $baris = collect($props['baris'])->firstWhere(fn (array $b) => collect($b['peserta'])->contains('record_id', $record->id));

    expect($baris)->not->toBeNull();

    $anak = collect($baris['peserta'])->firstWhere('record_id', $record->id);

    expect($anak['status_label'])->toBe('Hadir')
        ->and($anak['status'])->toBe('hadir');

    expect($baris['rekap']['hadir'])->toBe(1);
});

it('tidak menambah kueri saat ruangnya ditambah - presensi per ruang event', function () {
    // Sifat yang sama yang dijaga `PemantauanQueryTest`: menambah ruang tidak
    // menambah kueri. Bentuk per-ruang-event membaca roster SELURUH event
    // lebih dulu, jadi selisihnya harus nol dan bukan sebanding jumlah ruang.
    // Kapasitas dipasang supaya muat, dan jumlah RUANGNYA sengaja BEDA:
    // yang kecil 8 peserta di 2 ruang, yang besar 16 peserta di 4 ruang.
    // Tanpa itu `sebar` menolak karena kursinya kurang, benihnya tidak
    // tersimpan, dan laman yang diukur bukan jalur per-ruang-event sama
    // sekali - test yang hijau tanpa membuktikan apa pun.
    $kecil = siapkanKocok($this->admin, $this->tahun, 3, 5, 4, 2);

    $besar = siapkanKocok($this->admin, $this->tahun, 6, 10, 4, 4);

    foreach ([$kecil, $besar] as $d) {
        masukSebagai($this, $this->admin)
            ->post("/admin/events/{$d['event']->id}/penempatan", ['benih' => 'KUERI'])
            ->assertSessionMissing('galat');
    }

    $kueriKecil = hitungKueriPresensi($this, "/admin/events/{$kecil['event']->id}/presensi");

    // `forget()` wajib: tanpa itu `SchoolResolver` memakai hasil resolusi
    // permintaan sebelumnya, dan yang diukur bukan lagi dua laman yang sama.
    app(SchoolResolver::class)->forget();

    $kueriBesar = hitungKueriPresensi($this, "/admin/events/{$besar['event']->id}/presensi");

    expect($kueriBesar - $kueriKecil)->toBe(0);
});

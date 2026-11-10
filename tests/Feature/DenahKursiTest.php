<?php

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Denah kursi (plate 16c) dan tablo daftar hadir (plate 16d).
 *
 * ---------------------------------------------------------------------
 * Yang diuji di sini adalah KESAMAAN nomor kursi, bukan gambarnya
 * ---------------------------------------------------------------------
 * Nomor kursi dihitung empat tempat - layar pengawas, monitoring, laman
 * siswa, dan denah - dan sampai 2026-11-09 tiga di antaranya memakai urutan
 * abjad sementara satu memakai urutan id. Selisihnya tidak pernah muncul
 * sebagai galat: yang terjadi hanyalah anak berdiri di kursi orang lain.
 *
 * Karena itu test terpenting di berkas ini bukan yang memeriksa bentuk
 * denahnya, melainkan yang membandingkan nomor kursi SATU peserta lintas
 * ketiga permukaan. Denah yang benar tetapi bernomor lain dari daftar hadir
 * adalah denah yang menyesatkan.
 *
 * ---------------------------------------------------------------------
 * Fixture-nya `siapkanKocok()`, bukan `siapkanPresensi()`
 * ---------------------------------------------------------------------
 * `siapkanPresensi()` membuat satu kelas berisi empat siswa dan membagi
 * delapan kursi menjadi dua ruang - sehingga denah tiap ruang hanya punya
 * dua kotak, dan bentuk barisnya tidak terbaca. `siapkanKocok()` memakai dua
 * kelas berukuran beda (3 dan 5) dengan kapasitas ruang empat, jadi dua ruang
 * benar-benar terisi penuh dan denahnya punya empat kotak.
 */
beforeEach(function () {
    $this->sekolah = School::factory()->create();

    $this->admin = User::factory()->admin()->untukSekolah($this->sekolah)->create([
        'username' => 'admin.denah',
    ]);

    $this->tahun = AcademicYear::factory()->untukSekolah($this->sekolah)->aktif()->create([
        'name' => '2026/2027',
    ]);
});

/** Alamat laman denah satu event. */
function jalurDenah(int $eventId): string
{
    return "/admin/events/{$eventId}/penempatan/denah";
}

/** Alamat tablo PDF satu event. */
function jalurTablo(int $eventId): string
{
    return "/admin/events/{$eventId}/penempatan/tablo";
}

/** Alamat denah PDF satu event - lembar yang ditempel di meja. */
function jalurDenahPdf(int $eventId): string
{
    return "/admin/events/{$eventId}/penempatan/denah/pdf";
}

/**
 * Sebarkan kursi lewat jalur yang sama dengan Admin, supaya tersimpan.
 *
 * Lewat endpoint, bukan `forceFill`: yang diuji seluruh berkas ini adalah
 * nomor kursi yang BENAR-BENAR berlaku, dan nomor itu ditetapkan
 * `sebarEvent()` saat menyebar. Menulis kolomnya sendiri akan menguji aturan
 * yang tidak dipakai aplikasi.
 */
function sebarKursi(TestCase $test, User $admin, int $eventId, string $benih = 'DENAH1'): void
{
    masukSebagai($test, $admin)->post("/admin/events/{$eventId}/penempatan", ['benih' => $benih]);
}

/** Ruang-sesi pertama yang benar-benar terisi. */
function ruangTerisi(int $sessionId): ExamSessionRoom
{
    return ExamSessionRoom::query()
        ->where('exam_session_id', $sessionId)
        ->with('eventRoom.room:id,code,name,capacity')
        ->orderBy('id')
        ->get()
        ->first(fn (ExamSessionRoom $r) => ExamParticipant::query()
            ->where('exam_session_room_id', $r->id)
            ->exists());
}

/**
 * Id RUANG EVENT dari satu ruang-sesi.
 *
 * Sejak 2026-11-09 `?ruang=` menunjuk ruang EVENT, bukan ruang-sesi: kursi
 * anak tidak berpindah antar sesi, jadi yang dipilih di denah dan dicetak
 * tablo adalah ruangnya, bukan ruangnya-pada-sesi-tertentu. Kedua id itu
 * berbeda meski kodenya sama, dan menukarnya menghasilkan "pilih satu ruang"
 * yang membingungkan - bukan galat.
 */
function idRuangEvent(ExamSessionRoom $ruang): int
{
    return (int) $ruang->event_room_id;
}

/* -------------------------------------------------------------- Laman denah */

it('menggambar kursi satu ruang sesuai kapasitasnya', function () {
    $d = siapkanKocok($this->admin, $this->tahun);
    sebarKursi($this, $this->admin, $d['event']->id);

    $ruang = ruangTerisi($d['sesi']->id);
    $idRuang = idRuangEvent($ruang);

    masukSebagai($this, $this->admin)
        ->get(jalurDenah($d['event']->id)."?ruang={$idRuang}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Event/Denah')
            // Ruang EVENT, bukan ruang-sesi: denah ini berlaku sepanjang
            // ujian, jadi yang dipilih dan digambar adalah ruangnya.
            ->where('ruang.id', $idRuang)
            // Kapasitas ruang EVENT (4), bukan kapasitas master (40) - dan
            // bukan jumlah peserta yang kebetulan ada. Ruangan yang digambar
            // sebesar isinya akan selalu tampak penuh.
            ->where('ruang.kapasitas', 4)
            ->where('ruang.terisi', 4)
            ->where('ruang.kursi_per_meja', 2)
            ->where('ruang.meja_per_baris', 4)
            ->has('ruang.sel', 4)
            ->where('ruang.sel.0.kursi', 1)
            ->where('ruang.sel.0.meja', 1)
            ->where('ruang.sel.0.baris', 1)
            ->where('ruang.sel.0.kolom', 1)
            ->where('ruang.sel.3.kursi', 4)
            ->where('ruang.sel.3.meja', 2)
            ->where('ruang.sel.3.baris', 1)
            ->where('ruang.sel.3.kolom', 2));
});

it('mengirim setiap ruang event beserta jumlah terisinya', function () {
    // Baris atas pemilih ruang menampilkan "4/4" untuk SETIAP ruang, dan
    // angka itu dihitung satu kueri untuk seluruh ruang - bukan satu kueri
    // per ruang. Yang diuji di sini isinya, bukan jumlah kuerinya; jumlahnya
    // dijaga `PemantauanQueryTest` untuk papan yang sebelah.
    $d = siapkanKocok($this->admin, $this->tahun);
    sebarKursi($this, $this->admin, $d['event']->id);

    masukSebagai($this, $this->admin)
        ->get(jalurDenah($d['event']->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Event/Denah')
            ->has('daftar_ruang', 2)
            ->where('daftar_ruang.0.terisi', 4)
            ->where('daftar_ruang.0.kapasitas', 4)
            ->where('daftar_ruang.1.terisi', 4)
            // Ruang yang tidak diminta tidak digambar: `ruang` null, dan
            // lamannya tetap terbuka dengan pesan "pilih satu ruang".
            ->where('ruang', null));
});

it('menggambar kursi kosong sebelum kursinya disebar', function () {
    // Denah yang menunggu penyebaran akan menjadi laman yang tidak berguna
    // justru saat paling dibutuhkan: Admin yang baru menyusun ruangnya perlu
    // melihat bentuk ruangannya lebih dulu. Yang tidak boleh terjadi adalah
    // denah yang MENGAKU kursinya sudah berlaku.
    $d = siapkanKocok($this->admin, $this->tahun);
    // Ruang EVENT, bukan ruang-sesi: `?ruang=` menunjuk ruangnya, dan itu id
    // yang berbeda dari id ruangnya-pada-sesi - meski kodenya sama.
    $idRuang = idRuangEvent($d['ruang']->first());

    masukSebagai($this, $this->admin)
        ->get(jalurDenah($d['event']->id)."?ruang={$idRuang}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('ruang.terisi', 0)
            ->has('ruang.sel', 4)
            ->where('ruang.sel.0.peserta', null));
});

it('mengabaikan ruang yang bukan milik event ini tanpa galat', function () {
    // Tautan lama yang ruangnya sudah dihapus harus tetap terbuka. Yang
    // dilakukannya adalah menampilkan "pilih satu ruang", bukan halaman galat
    // - pilihan itu sudah diambil di `ruangEventDiminta()`.
    $d = siapkanKocok($this->admin, $this->tahun);

    masukSebagai($this, $this->admin)
        ->get(jalurDenah($d['event']->id).'?ruang=999999')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('ruang', null)
            ->has('daftar_ruang', 2));
});

/* ------------------------------------------------- Nomor kursi yang mengikat */

it('memberi nomor kursi yang SAMA di denah dan di daftar hadir pengawas', function () {
    // Inilah test yang menutup celah 2026-11-09. Denah dan daftar hadir
    // dihitung dua controller berbeda dengan dua kueri berbeda; kalau
    // urutannya berbeda, pengawas memanggil nomor yang tidak ada di denah
    // yang ditempel di meja - dan tidak satu pun galat muncul.
    $d = siapkanKocok($this->admin, $this->tahun);
    sebarKursi($this, $this->admin, $d['event']->id);

    $ruang = ruangTerisi($d['sesi']->id);
    $idRuang = idRuangEvent($ruang);

    $propsDenah = masukSebagai($this, $this->admin)
        ->get(jalurDenah($d['event']->id)."?ruang={$idRuang}")
        ->viewData('page')['props'];

    $propsPresensi = masukSebagai($this, $this->admin)
        ->get("/pengawas/ruang/{$ruang->id}/presensi")
        ->viewData('page')['props'];

    $dariDenah = collect($propsDenah['ruang']['sel'])
        ->filter(fn (array $s) => $s['peserta'] !== null)
        ->mapWithKeys(fn (array $s) => [$s['peserta']['id'] => $s['kursi']])
        ->sortKeys()
        ->all();

    $dariPresensi = collect($propsPresensi['kursi'])
        ->mapWithKeys(fn (array $b) => [(int) $b['peserta_id'] => $b['kursi']])
        ->sortKeys()
        ->all();

    expect($dariDenah)->toHaveCount(4)
        ->and($dariDenah)->toBe($dariPresensi);
});

it('memberi nomor kursi yang SAMA di denah dan di jadwal siswa', function () {
    // Permukaan ketiga. Laman siswa dulu memakai urutan id, sehingga anak
    // melihat nomor yang berbeda dari yang dibacakan pengawas di ruang yang
    // sama - dan selisihnya baru terlihat saat seseorang berdiri di kursi
    // orang lain.
    $d = siapkanKocok($this->admin, $this->tahun);
    sebarKursi($this, $this->admin, $d['event']->id);

    $ruang = ruangTerisi($d['sesi']->id);
    $idRuang = idRuangEvent($ruang);

    $propsDenah = masukSebagai($this, $this->admin)
        ->get(jalurDenah($d['event']->id)."?ruang={$idRuang}")
        ->viewData('page')['props'];

    $dariDenah = collect($propsDenah['ruang']['sel'])
        ->filter(fn (array $s) => $s['peserta'] !== null)
        ->mapWithKeys(fn (array $s) => [$s['peserta']['id'] => $s['kursi']])
        ->all();

    // `student` dimuat UTUH, bukan `student:id,name`. Model yang dipangkas
    // kolomnya tidak membawa `role`, dan `AksesSiswa` membacanya - sehingga
    // permintaan berakhir 403 dan bukan karena nomor kursinya salah.
    foreach ($ruang->participants()->with('student')->get() as $peserta) {
        $propsJadwal = masukSebagai($this, $peserta->student)
            ->get('/siswa/jadwal')
            ->viewData('page')['props'];

        $baris = collect($propsJadwal['jadwal'])->firstWhere('peserta_id', $peserta->id);

        expect($baris)->not->toBeNull()
            ->and($baris['kursi'])->toBe($dariDenah[$peserta->id]);
    }
});

it('menomori kursi menurut abjad nama, bukan menurut urutan id', function () {
    // Aturan yang mengikat, diuji lewat dua permukaan sekaligus. Yang
    // dibandingkan adalah urutan KURSI terhadap urutan NAMA: anak yang
    // namanya paling awal berdiri di kursi 1.
    $d = siapkanKocok($this->admin, $this->tahun);
    sebarKursi($this, $this->admin, $d['event']->id);

    $ruang = ruangTerisi($d['sesi']->id);
    $idRuang = idRuangEvent($ruang);

    $props = masukSebagai($this, $this->admin)
        ->get(jalurDenah($d['event']->id)."?ruang={$idRuang}")
        ->viewData('page')['props'];

    $berurut = collect($props['ruang']['sel'])
        ->filter(fn (array $s) => $s['peserta'] !== null)
        ->values();

    $namaDiKursi = $berurut->map(fn (array $s) => $s['peserta']['nama'])->all();
    $abjad = $namaDiKursi;
    sort($abjad, SORT_STRING | SORT_FLAG_CASE);

    expect($namaDiKursi)->toBe($abjad);
});

/* --------------------------------------------------------------- Denah PDF */

it('mencetak denah seluruh ruang sebagai PDF', function () {
    // Lembar yang ditempel di meja. Yang diuji bentuk keluarannya, bukan
    // gambarnya: DomPDF menghasilkan biner, dan yang membedakan "berkas PDF
    // yang benar" dari "galat PHP yang terkirim sebagai HTML" hanya
    // empat byte pertama.
    $d = siapkanKocok($this->admin, $this->tahun);
    sebarKursi($this, $this->admin, $d['event']->id);

    $respon = masukSebagai($this, $this->admin)->get(jalurDenahPdf($d['event']->id));

    $respon->assertOk();

    expect($respon->headers->get('content-type'))->toContain('application/pdf')
        ->and(substr($respon->getContent(), 0, 4))->toBe('%PDF')
        ->and($respon->headers->get('content-disposition'))->toContain('denah-tempat-duduk.pdf');
});

it('mencetak denah satu ruang lewat ?ruang= | denah pdf', function () {
    // Satu ruang harus menghasilkan lembar yang LEBIH RINGKAS daripada
    // seluruh ruang, pola yang sama dengan tablo: kalau panjangnya sama,
    // `?ruang=` tidak berpengaruh dan pengawas R-01 mencetak semuanya.
    $d = siapkanKocok($this->admin, $this->tahun);
    sebarKursi($this, $this->admin, $d['event']->id);

    $ruang = ruangTerisi($d['sesi']->id);
    $idRuang = idRuangEvent($ruang);

    $satu = masukSebagai($this, $this->admin)
        ->get(jalurDenahPdf($d['event']->id)."?ruang={$idRuang}");

    $semua = masukSebagai($this, $this->admin)->get(jalurDenahPdf($d['event']->id));

    $satu->assertOk();

    expect(substr($satu->getContent(), 0, 4))->toBe('%PDF')
        ->and(strlen($satu->getContent()))->toBeLessThan(strlen($semua->getContent()));
});

it('mencetak denah untuk ruang yang tidak ada sebagai seluruh ruang', function () {
    // Perlakuan yang sama dengan tablo dan laman denah: tautan lama yang
    // ruangnya sudah dihapus menghasilkan seluruh ruang, bukan galat.
    $d = siapkanKocok($this->admin, $this->tahun);
    sebarKursi($this, $this->admin, $d['event']->id);

    $respon = masukSebagai($this, $this->admin)
        ->get(jalurDenahPdf($d['event']->id).'?ruang=999999');

    $respon->assertOk();

    expect(substr($respon->getContent(), 0, 4))->toBe('%PDF');
});

it('mengirim tautan denah pdf ke laman denah', function () {
    // Tombolnya tidak ditulis di React sebagai string: alamatnya datang dari
    // server lewat `pdf_url`, sama dengan `tablo_url`. Diuji di sini supaya
    // perubahan rute tidak meninggalkan tombol yang menunjuk alamat lama.
    $d = siapkanKocok($this->admin, $this->tahun);

    masukSebagai($this, $this->admin)
        ->get(jalurDenah($d['event']->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pdf_url', "/admin/events/{$d['event']->id}/penempatan/denah/pdf"));
});

/* ------------------------------------------------------------------ Tablo */
it('mencetak tablo seluruh ruang sebagai PDF', function () {
    $d = siapkanKocok($this->admin, $this->tahun);
    sebarKursi($this, $this->admin, $d['event']->id);

    $respon = masukSebagai($this, $this->admin)->get(jalurTablo($d['event']->id));

    $respon->assertOk();

    expect($respon->headers->get('content-type'))->toContain('application/pdf')
        ->and(substr($respon->getContent(), 0, 4))->toBe('%PDF')
        ->and($respon->headers->get('content-disposition'))->toContain('tablo-daftar-hadir.pdf');
});

it('mencetak tablo satu ruang lewat ?ruang=', function () {
    // Yang membedakan "satu ruang" dari "seluruh ruang" hanya berapa lembar
    // yang keluar dari printer - bukan rutenya.
    $d = siapkanKocok($this->admin, $this->tahun);
    sebarKursi($this, $this->admin, $d['event']->id);

    $ruang = ruangTerisi($d['sesi']->id);
    $idRuang = idRuangEvent($ruang);

    $satu = masukSebagai($this, $this->admin)
        ->get(jalurTablo($d['event']->id)."?ruang={$idRuang}");

    $semua = masukSebagai($this, $this->admin)
        ->get(jalurTablo($d['event']->id));

    $satu->assertOk();

    expect(substr($satu->getContent(), 0, 4))->toBe('%PDF')
        // Satu ruang harus menghasilkan lembar yang LEBIH RINGKAS daripada
        // seluruh ruang. Kalau keduanya sama panjang, `?ruang=` tidak
        // berpengaruh - dan pengawas R-01 mencetak enam lembar.
        ->and(strlen($satu->getContent()))->toBeLessThan(strlen($semua->getContent()));
});

it('mencetak tablo untuk ruang yang tidak ada sebagai seluruh ruang', function () {
    // Perlakuan yang sama dengan denah: tautan lama yang ruangnya sudah
    // dihapus menghasilkan seluruh ruang, bukan halaman galat.
    $d = siapkanKocok($this->admin, $this->tahun);
    sebarKursi($this, $this->admin, $d['event']->id);

    $respon = masukSebagai($this, $this->admin)
        ->get(jalurTablo($d['event']->id).'?ruang=999999');

    $respon->assertOk();

    expect(substr($respon->getContent(), 0, 4))->toBe('%PDF');
});

/* ------------------------------------------------------------------ Akses */

it('menolak denah dan tablo event sekolah lain', function () {
    // Kedua laman baru memakai gerbang yang sama dengan laman penempatan.
    // Diuji supaya tidak ada satu pun dari keduanya yang lupa memanggilnya.
    $d = siapkanKocok($this->admin, $this->tahun);

    $sekolahLain = School::factory()->create();
    $adminLain = User::factory()->admin()->untukSekolah($sekolahLain)->create([
        'username' => 'admin.lain.denah',
    ]);

    masukSebagai($this, $adminLain)->get(jalurDenah($d['event']->id))->assertNotFound();
    masukSebagai($this, $adminLain)->get(jalurTablo($d['event']->id))->assertNotFound();
});

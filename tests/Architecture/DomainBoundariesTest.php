<?php

/**
 * Batas modul domain, ditegakkan mekanis.
 *
 * Aturan blueprint: "satu modul hanya boleh mengakses modul lain melalui
 * service resminya, bukan lewat model langsung milik domain lain."
 *
 * Test ini menjaga GRAF KETERGANTUNGAN yang sudah diputuskan, bukan
 * mengklaim tidak ada ketergantungan sama sekali - klaim itu tidak benar,
 * dan test yang tidak benar akan dimatikan orang pertama yang kena.
 *
 * Arah yang memang ada dan disengaja:
 *
 *   Identity -> Tenancy   User milik satu School.
 *   Audit    -> Identity  Policy menyebut aktornya (User) pada signature.
 *   Tenancy  -> Identity  SchoolPolicy menyebut aktornya (User).
 *   Academic -> Identity, Tenancy
 *                         Wali kelas, siswa anggota kelas, dan guru pemegang
 *                         penugasan semuanya adalah User.
 *   Facility -> Identity, Tenancy
 *                         Belum dipakai; dinyatakan lebih awal supaya peta
 *                         ini tidak berubah di tengah Fase 3.
 *   Admin    -> Identity, Tenancy, Academic, Facility, Audit
 *                         Admin adalah composition root konsol admin; ia
 *                         merangkai modul lain, seperti sebuah controller
 *                         aplikasi merangkai domain. Audit ikut karena konsol
 *                         inilah yang MEMBACA log aktivitas (plate 24) dan
 *                         yang menulisinya lewat ActivityLogger.
 *
 * Yang DILARANG dan ditangkap test ini:
 *   - arah yang tidak ada di peta di atas (mis. Audit -> Tenancy);
 *   - modul mana pun menyentuh Http modul lain (controller memanggil
 *     controller lain adalah jalan pintas yang membuat batas luntur).
 *
 * Berkas Providers/ dikecualikan: provider memang tempat merangkai modul
 * lewat container. Itu tugasnya, bukan pelanggaran.
 */

/** Arah impor Models yang disengaja: dari modul => daftar modul tujuan. */
const ARAH_MODEL_DIIZINKAN = [
    'Identity' => ['Tenancy'],
    'Audit' => ['Identity'],
    'Tenancy' => ['Identity'],
    // Academic menunjuk User untuk wali kelas, siswa di kelas, dan guru
    // pemegang penugasan mengajar. Itu identitas, bukan data akademik.
    'Academic' => ['Identity', 'Tenancy'],
    // Facility belum menunjuk modul lain, tetapi arahnya dinyatakan sejak
    // sekarang supaya Fase 3 (penempatan ruang per event) tidak perlu
    // mengubah peta ini sambil buru-buru.
    'Facility' => ['Identity', 'Tenancy'],
    'Admin' => ['Identity', 'Tenancy', 'Academic', 'Facility', 'Audit'],

    // Event adalah modul kelima, dan ia menunjuk hampir semua yang lain:
    // tahun ajaran dan semester (Academic), peserta dan guru (Identity),
    // ruang yang dialokasikan (Facility), dan pengaturan sekolah yang
    // menjadi bawaan ambang pelanggaran (Tenancy).
    //
    // Ia TIDAK menunjuk Admin, meski seluruh laman event hidup di bawah
    // /admin/events. Yang membuat itu mungkin adalah dua pemindahan yang
    // dilakukan sengaja di Fase 3:
    //
    //   TabelQuery      Admin\Support   -> App\Support
    //   PengaturanBawaan Admin\Http const -> Tenancy\Support
    //
    // Keduanya bukan kode Admin. TabelQuery adalah daftar-filter-tabel
    // generik yang tidak menyebut satu model pun; PengaturanBawaan adalah
    // nilai milik tabel `schools`. Menahannya di Admin berarti setiap modul
    // yang butuh tabel atau butuh bawaan sekolah harus mengimpor Admin\Http -
    // dan itu tepat yang dilarang test ini.
    'Event' => ['Identity', 'Tenancy', 'Academic', 'Facility'],

    // Exam adalah modul keenam, dan arahnya SATU ARAH - itu yang membuatnya
    // aman meski Event dan Exam saling membutuhkan:
    //
    //   Exam  -> Event   ujian milik event, ruang sesi milik event_rooms, dan
    //                    sesi pertama memindahkan status eventnya.
    //   Event -> Exam    TIDAK ADA. Gerbang kesiapan menerima jawabannya lewat
    //                    Event\Contracts\ButirKesiapanUjian, yang diikat ke
    //                    implementasi Exam di ExamServiceProvider.
    //
    // Kalau arah kedua ditambahkan ke peta ini, lingkaran di peta modul sudah
    // terjadi - dan Factory gaya "satu modul satu peta" berhenti menjelaskan
    // apa pun tentang modul mana yang boleh berdiri sendiri.
    //
    // Fase 4 menambahkan Question ke daftar ini, dan arahnya sekali lagi satu.
    // Ujian menyalin soal paketnya menjadi exam_questions (PembekuPaket), dan
    // penyalinan itu memang urusan Exam: yang dibekukan adalah bentuk ujian
    // pada saat itu, bukan bank soalnya. Question tidak pernah menunjuk balik
    // - ia tidak tahu ujian mana yang memakai paketnya.
    'Exam' => ['Identity', 'Tenancy', 'Academic', 'Facility', 'Event', 'Question'],

    // Attendance adalah modul ketujuh - nomor 12 di peta blueprint, dan ia
    // duduk TEPAT DI TENGAH. Ia bergantung pada Exam (penempatan, ruang-sesi,
    // sesi, token) dan pada Event (nama kegiatan di kepala laman pengawas),
    // dan Exam bergantung padanya untuk satu panggilan saja.
    //
    // Lingkaran itu diputus dengan cara yang sama seperti Event<->Exam:
    // arahnya satu. Attendance MENDEKLARASIKAN kontraknya
    // (Attendance\Contracts\PencatatKehadiran), Exam MEMANGGILNYA lewat
    // container, dan yang menyambungkan keduanya adalah provider. Karena itu
    // peta ini tidak punya baris `'Exam' => [... 'Attendance']`, dan justru
    // ketiadaannya yang membuat modul ini bisa diuji sendirian.
    //
    // Academic ikut karena kelas siswa ditampilkan di daftar kursi, dan
    // Facility karena lokasi ruang dipakai sebagai keterangan lokasi foto
    // berita acara (PRD §12.4: MVP tanpa GPS).
    'Attendance' => ['Identity', 'Tenancy', 'Academic', 'Facility', 'Event', 'Exam'],

    // Question adalah modul kedelapan (Fase 4). Ia menunjuk Identity (penulis
    // soal adalah User), Tenancy (seluruh tabelnya membawa school_id),
    // Academic (setiap soal milik satu Subject, dan izin menulisnya dibaca
    // dari TeachingAssignment), dan Audit (BankSoalService menulis log lewat
    // ActivityLogger).
    //
    // Ia TIDAK menunjuk Event maupun Exam, dan itu yang membuat bank soal
    // bisa diuji sendirian: paket soal tidak pernah tahu event mana yang
    // memakainya, karena yang mengetahui hal itu adalah
    // `event_subjects.question_package_id` - kolom di sisi Event.
    //
    // Arah sebaliknya yang ada: Exam -> Question. Ujian menyalin paket soal
    // menjadi snapshot (PembekuPaket), dan itu memang tugas Exam - lihat
    // catatan di bawah.
    'Question' => ['Identity', 'Tenancy', 'Academic', 'Audit'],

    // Grading adalah modul kesembilan (Fase 8) - nomor 15 di PRD §15.3. Ia
    // menunjuk HAMPIR SEMUA modul lain, dan itu bukan kelalaian: nilai
    // dihitung dari jawaban (Attendance), butir snapshot (Exam), event yang
    // menerbitkannya (Event), siswa dan guru (Identity), mapel dan kelas
    // untuk laporan (Academic), ruang untuk laporan per ruang (Facility),
    // dan ActivityLogger untuk setiap aksi yang mengubah nilai (Audit).
    //
    // Arahnya SATU, dan itu yang membuat modul ini bisa berdiri:
    //
    //   Grading    -> Attendance   membaca dan menulis `student_answers`
    //                              (is_correct/score_awarded), membaca
    //                              `exam_attempts`.
    //
    //   Attendance -> Grading      TIDAK ADA, dan tidak boleh ada. Kalau
    //                              JawabanService tahu aturan penilaian,
    //                              jawaban akan dinilai saat disimpan -
    //                              sebelum siswa selesai mengerjakan.
    //
    // `StudentAnswer` sengaja TIDAK punya relasi `koreksi()`. Koreksi uraian
    // dibaca lewat satu kueri di PenghitungHasil, karena relasi di model
    // Attendance berarti Attendance mengimpor model Grading - dan arah
    // modulnya berbalik lewat pintu belakang.
    'Grading' => ['Identity', 'Tenancy', 'Academic', 'Facility', 'Event', 'Exam', 'Attendance', 'Audit'],
];

function berkasDomain(): array
{
    $akar = base_path('app/Domain');
    $hasil = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($akar, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $berkas) {
        if ($berkas->getExtension() !== 'php') {
            continue;
        }

        $jalur = str_replace('\\', '/', $berkas->getPathname());
        $relatif = str_replace(str_replace('\\', '/', base_path()).'/', '', $jalur);

        if (preg_match('#^app/Domain/([^/]+)/#', $relatif, $m)) {
            $hasil[] = ['modul' => $m[1], 'jalur' => $jalur, 'relatif' => $relatif];
        }
    }

    return $hasil;
}

it('menemukan berkas domain untuk diperiksa', function () {
    // Penjaga terhadap test yang diam-diam hampa bila glob-nya salah.
    expect(berkasDomain())->not->toBeEmpty();
});

it('tidak ada impor model lintas modul di luar arah yang disengaja', function () {
    $pelanggaran = [];

    foreach (berkasDomain() as $berkas) {
        // Provider adalah composition root - dikecualikan.
        if (str_contains($berkas['relatif'], '/Providers/')) {
            continue;
        }

        $isi = file_get_contents($berkas['jalur']);

        preg_match_all('#App\\\\Domain\\\\([A-Za-z]+)\\\\Models\\\\#', $isi, $cocok);

        foreach (array_unique($cocok[1]) as $modulTujuan) {
            if ($modulTujuan === $berkas['modul']) {
                continue;
            }

            $diizinkan = ARAH_MODEL_DIIZINKAN[$berkas['modul']] ?? [];

            if (! in_array($modulTujuan, $diizinkan, true)) {
                $pelanggaran[] = "{$berkas['relatif']} -> App\\Domain\\{$modulTujuan}\\Models";
            }
        }
    }

    expect($pelanggaran)->toBe([], "Impor model lintas modul yang tidak disengaja:\n".implode("\n", $pelanggaran));
});

it('tidak ada modul yang menyentuh Http modul lain', function () {
    $pelanggaran = [];

    foreach (berkasDomain() as $berkas) {
        $isi = file_get_contents($berkas['jalur']);

        preg_match_all('#App\\\\Domain\\\\([A-Za-z]+)\\\\Http\\\\#', $isi, $cocok);

        foreach (array_unique($cocok[1]) as $modulTujuan) {
            if ($modulTujuan === $berkas['modul']) {
                continue; // namespace sendiri atau impor internal modul
            }

            $pelanggaran[] = "{$berkas['relatif']} -> App\\Domain\\{$modulTujuan}\\Http";
        }
    }

    expect($pelanggaran)->toBe([], "Akses Http lintas modul:\n".implode("\n", $pelanggaran));
});

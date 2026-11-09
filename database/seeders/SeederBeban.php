<?php

namespace Database\Seeders;

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Attendance\Enums\StatusPresensi;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Services\KehadiranService;
use App\Domain\Attendance\Services\KonteksRuang;
use App\Domain\Event\Models\EventParticipant;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Event\Services\EventParticipantService;
use App\Domain\Exam\Enums\StatusSesi;
use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Exam\Services\PenempatanService;
use App\Domain\Exam\Services\TokenSesi;
use App\Domain\Facility\Services\RoomEntryLinkService;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\StudentProfile;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Session;

/**
 * Menyiapkan data untuk uji beban PRD §19.3 - bukan seeder contoh.
 *
 * =====================================================================
 * Mengapa seeder TERPISAH, dan bukan `DatabaseSeeder --class`
 * =====================================================================
 * `DatabaseSeeder` membuat satu sekolah contoh yang enak dilihat: dua belas
 * siswa, empat attempt dengan empat keadaan berbeda, berita acara draf.
 * Uji beban menuntut yang hampir berlawanan - BANYAK siswa yang SEMUANYA
 * berada pada keadaan yang sama (terverifikasi, punya kursi, belum mulai),
 * karena satu peserta yang izin atau satu peserta yang belum diverifikasi
 * akan tampak di laporan sebagai galat, padahal ia memang begitu isinya.
 *
 * Menumpuk keduanya dalam satu kelas berarti setiap kali salah satu
 * kebutuhan berubah, yang lain ikut bergeser.
 *
 * =====================================================================
 * Menulis lewat SERVICE, persis seperti jalur Admin
 * =====================================================================
 * Seluruh baris di sini lahir dari service yang sama dengan yang dipakai
 * laman Admin: `EventParticipantService::tambahManual()`,
 * `PenempatanService::tarikPeserta()` dan `sebar()`, `KehadiranService::catat()`.
 *
 * Yang paling menentukan adalah `sebar()`: ia yang menulis
 * `exam_session_room_id` DAN melahirkan baris presensi lewat
 * `PencatatKehadiran`. Menulis penempatannya langsung akan menghasilkan
 * siswa yang punya kursi tetapi TIDAK punya baris presensi - dan siswa
 * seperti itu ditolak gerbang dengan "Anda belum dipresensi", yang di
 * laporan uji beban terbaca sebagai 40 kegagalan login padahal yang salah
 * adalah persiapannya.
 *
 * =====================================================================
 * Pengaman nama basis data
 * =====================================================================
 * Seeder ini MENAMBAH tiga ratus akun. Dijalankan pada basis data
 * pengembangan, hasilnya adalah tiga ratus siswa palsu di daftar siswa
 * sekolah contoh - dan pembersihannya jauh lebih menyebalkan daripada
 * penolakannya. Karena itu namanya diperiksa lebih dulu, dengan pengaman
 * yang sama seperti `cbt-pulih.sh`.
 *
 * =====================================================================
 * Pemakaian
 * =====================================================================
 *   # Sesudah DatabaseSeeder, pada basis data yang berakhiran _beban:
 *   DB_DATABASE=cbt_beban php artisan db:seed --class=SeederBeban --force
 *
 *   CBT_BEBAN_SISWA=300 DB_DATABASE=cbt_beban php artisan db:seed --class=SeederBeban --force
 *
 * Menulis daftar akunnya ke `storage/app/private/beban-akun.txt`, yang
 * dibaca `scripts/cbt-uji-beban.mjs` - lihat catatan di ujung method `run()`.
 */
class SeederBeban extends Seeder
{
    /** Akhiran nama basis data yang boleh ditambahi akun beban. */
    private const AKHIRAN_BOLEH = ['_beban', '_test', '_pulih'];

    public function run(): void
    {
        $basisData = (string) config('database.connections.mysql.database');

        $boleh = false;

        foreach (self::AKHIRAN_BOLEH as $akhiran) {
            if (str_ends_with($basisData, $akhiran)) {
                $boleh = true;
                break;
            }
        }

        if (! $boleh) {
            $this->command->error(
                "DITOLAK: basis data '{$basisData}' bukan basis data uji. ".
                'Nama harus berakhiran '.implode('/', self::AKHIRAN_BOLEH).'.',
            );

            return;
        }

        $jumlah = max(1, (int) (getenv('CBT_BEBAN_SISWA') ?: 40));

        $event = SchoolEvent::query()->orderBy('id')->first();
        $ujian = Exam::query()->orderBy('id')->first();

        if ($event === null || $ujian === null) {
            $this->command->error('Jalankan DatabaseSeeder lebih dulu - event atau ujiannya belum ada.');

            return;
        }

        $tahun = AcademicYear::query()->orderBy('id')->first();

        // `$event->classes()` mengembalikan baris `event_classes`, yaitu
        // PASANGAN event<->kelas - dan `->id`-nya adalah id pasangannya,
        // BUKAN `class_id` kelasnya. Keduanya kebetulan sama pada basis data
        // pengembangan (event pertama memakai kelas pertama, dan kedua tabel
        // disemai berurutan), sehingga kekeliruan ini tidak bersuara di sana.
        //
        // Di basis data yang disemai dari nol, urutannya berbeda dan
        // `tambahManual()` menolaknya dengan "Kelas itu tidak termasuk kelas
        // peserta event ini" - satu galat yang menyebut kelas, padahal yang
        // salah adalah id yang diambil dari tabel yang salah.
        $kelas = $event->classes()->orderBy('id')->first();

        if ($tahun === null || $kelas === null) {
            $this->command->error('Tahun ajaran atau kelas event belum ada.');

            return;
        }

        $this->command->info("Menyiapkan {$jumlah} akun siswa untuk uji beban ...");

        $usernames = $this->buatSiswa($jumlah, (int) $kelas->class_id, $tahun->id, $event);

        // ------------------------------------------------------- attempt lama
        //
        // Seeder ini menjanjikan keadaan SERAGAM: semua peserta punya kursi,
        // semua terverifikasi, dan BELUM ADA yang mulai. Janji terakhir itu
        // ternyata tidak dijaga di mana pun - dan yang rusak karenanya
        // adalah uji beban yang dijalankan dua kali.
        //
        // Jalan pertama meninggalkan satu attempt berstatus `dikirim` untuk
        // tiap peserta. Jalan kedua menemukan attempt itu, menghitungnya
        // sebagai kesempatan yang SUDAH terpakai (batasnya 1 untuk ujian
        // contoh), lalu `KelayakanUjian` menolak dengan "Anda sudah memakai
        // seluruh kesempatan ujian ini (1x)". Dari sisi peniru itu 302 pada
        // `/kerjakan` - gejala yang PERSIS SAMA dengan jendela sesi yang
        // sudah tertutup, dan dua penyebab berbeda yang bergejala sama
        // adalah cara tercepat menghabiskan satu jam.
        //
        // Karena itu attempt lama dibersihkan lebih dulu. Penghapusannya
        // menurun berantai lewat foreign key ke `attempt_questions` ->
        // `student_answers` -> `essay_gradings`, lalu ke `exam_results` dan
        // `exam_violations` - jadi satu perintah ini mengembalikan peserta
        // ke "belum mulai" seutuhnya, tanpa menyentuh baris presensi atau
        // penempatannya (yang justru dipertahankan agar seeder tetap murah).
        $dihapus = $this->hapusAttempt($ujian);

        if ($dihapus > 0) {
            $this->command->warn(
                "Attempt lama dihapus: {$dihapus} baris - peserta kembali ke keadaan belum mulai.",
            );
        }

        // ---------------------------------------------------------- penempatan

        $ditarik = app(PenempatanService::class)->tarikPeserta($ujian);
        $this->command->info("Penempatan ujian: {$ditarik} baru.");

        $sesi = ExamSession::query()
            ->where('exam_id', $ujian->id)
            ->where('status', StatusSesi::Berjalan)
            ->orderBy('session_number')
            ->first();

        if ($sesi === null) {
            $this->command->error('Tidak ada sesi berjalan. Jalankan DatabaseSeeder lebih dulu.');

            return;
        }

        // ------------------------------------------------------------------
        // Jendela sesi DIGESER ke sekarang, dan itu bukan kelonggaran
        // ------------------------------------------------------------------
        // `DatabaseSeeder` membuka sesi 1 tiga puluh menit sebelum ia
        // dijalankan. Uji beban dijalankan SETELAH itu - sering beberapa jam
        // setelahnya, karena seeding dan persiapan memakan waktu - dan
        // sesinya sudah tertutup pada saat peniru pertama mencoba masuk.
        //
        // Gejalanya menyesatkan: POST login berhasil (302 ke /siswa), POST
        // gerbang juga 302, dan yang gagal adalah /kerjakan yang mengalihkan
        // kembali ke gerbang. Dua puluh kegagalan beruntun itu terbaca
        // sebagai masalah kapasitas, padahal `KelayakanUjian` sedang
        // menjawab dengan benar: sesinya memang sudah berakhir.
        //
        // Tokennya ikut diterbitkan ULANG di bawah, karena `valid_until`
        // token mengikuti `hard_end_at` sesinya - menggeser jendelanya tanpa
        // menerbitkan ulang menghasilkan token yang sudah kedaluwarsa untuk
        // sesi yang baru saja dibuka.
        $buka = now()->subMinutes(10);

        $sesi->forceFill([
            'access_opens_at' => $buka,
            'access_closes_at' => $buka->copy()->addMinutes(90),
            'hard_end_at' => $buka->copy()->addHours(2),
            'status' => StatusSesi::Berjalan,
        ])->save();

        $this->command->info(
            "Jendela sesi {$sesi->label()} digeser: {$buka->format('H:i')} - ".
            $sesi->hard_end_at->format('H:i').' ('.config('app.timezone').')',
        );

        // ------------------------------------------------------------------
        // Kapasitas dinaikkan bila perlu - dan pada 300 memang perlu
        // ------------------------------------------------------------------
        // Sesi contoh punya DUA ruang berkapasitas 32, yaitu 64 kursi. Menyemai
        // tiga ratus siswa ke atasnya bukan "gagal mendistribusikan" - `sebar()`
        // memeriksa kapasitas LEBIH DULU dan menolak seluruhnya dengan
        // "Kapasitas sesi S-1 hanya 64 kursi untuk 300 peserta". Tidak ada satu
        // baris pun yang ditulis, jadi kegagalannya bersih, tetapi uji bebannya
        // tidak pernah berjalan dan prosedur 300 konkuren di docs/BEBAN.md §3
        // berhenti di langkah pertama.
        //
        // `capacity_override` di exam_session_rooms adalah kolom yang memang
        // disediakan untuk ini: kapasitas alokasi event (32 kursi Lab Komputer)
        // dan kapasitas yang dipakai satu sesi adalah dua angka yang berbeda,
        // dan ruang yang sama bisa dipakai sesi berbeda dengan jumlah berbeda.
        // Menimpanya lewat kolom itu berarti seeder ini menyatakan sesuatu yang
        // bisa dinyatakan Admin di plate 17 - bukan menulis ke tempat yang
        // tidak punya arti.
        //
        // Dilaporkan apa adanya supaya angkanya terlihat, bukan tersembunyi:
        // angka kursi itulah yang menentukan berapa peniru yang bisa jalan.
        $kapasitasAwal = $this->kapasitasSesi($sesi);
        $jumlahPeserta = ExamParticipant::query()
            ->where('exam_id', $ujian->id)
            ->where('status', ExamParticipant::STATUS_TERDAFTAR)
            ->count();

        if ($kapasitasAwal < $jumlahPeserta) {
            $dinaikkan = $this->naikkanKapasitas($sesi, $jumlahPeserta);

            $this->command->warn(
                "Kapasitas sesi {$sesi->label()} dinaikkan: {$kapasitasAwal} -> ".array_sum($dinaikkan).
                ' kursi pada '.count($dinaikkan).' ruang, untuk memuat '.$jumlahPeserta.' peserta.',
            );
        }

        // `sebar()` menempatkan SELURUH peserta ujian ke sesi ini - lihat
        // docblock-nya. Itu memang yang dibutuhkan di sini: peserta contoh
        // yang tadinya di sesi 1 ikut pindah, dan tidak ada yang tertinggal
        // di sesi yang jendelanya sudah tertutup.
        $perRuang = app(PenempatanService::class)->sebar($ujian, $sesi);

        $kursi = array_sum($perRuang);
        $this->command->info("Sebar ke sesi {$sesi->label()}: {$kursi} kursi pada ".count($perRuang).' ruang.');

        // ------------------------------------------------------------ presensi

        $diverifikasi = $this->verifikasiSemua($sesi);

        $this->command->info("Presensi terverifikasi: {$diverifikasi} peserta.");

        // -------------------------------------------------------------- token

        $token = app(TokenSesi::class)->terbitkan($sesi, null);
        $this->command->info("Token sesi: {$token['kode']}");

        // ------------------------------------------------------------- daftar

        // Daftar akunnya ditulis ke BERKAS, bukan dicetak untuk disalin.
        // Empat puluh username yang disalin dari terminal adalah tempat yang
        // sangat mudah salah, dan salah satu username yang keliru akan
        // muncul di laporan sebagai kegagalan login - yaitu terbaca sebagai
        // masalah kapasitas padahal masalah salin-tempel.
        $berkas = storage_path('app/private/beban-akun.txt');

        @mkdir(dirname($berkas), 0775, true);

        file_put_contents($berkas, implode("\n", $usernames)."\n");

        $this->command->info('Daftar akun: '.$berkas);
        $this->command->info('Jalankan:');
        $this->command->line(
            '  CBT_SISWA_DAFTAR="'.implode(',', $usernames).'" '.
            "CBT_TOKEN={$token['kode']} CBT_SISWA=".count($usernames).' '.
            'node scripts/cbt-uji-beban.mjs',
        );
    }

    /**
     * Buat N siswa beserta profil, keanggotaan kelas, dan keikutsertaan event.
     *
     * @return array<int, string> Username yang dibuat, berurutan.
     */
    private function buatSiswa(int $jumlah, int $kelasId, int $tahunId, SchoolEvent $event): array
    {
        $peserta = app(EventParticipantService::class);
        $usernames = [];

        for ($i = 1; $i <= $jumlah; $i++) {
            $nomor = str_pad((string) $i, 4, '0', STR_PAD_LEFT);
            $username = 'beban'.$nomor;

            // Idempoten: seeder yang dijalankan dua kali harus menghasilkan
            // keadaan yang sama, bukan tiga ratus akun kedua yang dibedakan
            // nomornya - dan siswa yang tersedot ke event dua kali.
            $siswa = User::query()->where('username', $username)->first();

            if ($siswa === null) {
                $siswa = User::create([
                    'school_id' => $event->school_id,
                    'role' => Role::Student,
                    'name' => 'Beban '.$nomor,
                    'username' => $username,
                    'password' => 'password',
                    'is_active' => true,
                ]);

                StudentProfile::create([
                    'school_id' => $event->school_id,
                    'user_id' => $siswa->id,
                    'nis' => 'B'.$nomor,
                    'nisn' => '99'.$nomor,
                    'gender' => $i % 2 === 0 ? 'P' : 'L',
                ]);
            }

            $usernames[] = $username;

            $kelas = SchoolClass::query()->find($kelasId);

            $kelas?->enrollments()->firstOrCreate(
                ['student_id' => $siswa->id],
                [
                    'school_id' => $event->school_id,
                    'academic_year_id' => $tahunId,
                    'enrollment_date' => '2026-07-15',
                    'status' => 'aktif',
                ],
            );

            if (! EventParticipant::query()
                ->where('event_id', $event->id)
                ->where('student_id', $siswa->id)
                ->exists()) {
                $peserta->tambahManual($event, $siswa->id, $kelasId);
            }
        }

        return $usernames;
    }

    /**
     * Hapus seluruh attempt MILIK SEEDER INI saja, beserta turunannya.
     *
     * Batasnya adalah akun `beban*` - bukan seluruh attempt pada ujian itu.
     * Peserta contoh dari `DatabaseSeeder` demonya justru perlu tetap ada
     * (ia yang mengisi halaman hasil dan riwayat pelanggaran di layar
     * contoh); yang harus kembali ke "belum mulai" hanyalah tiga ratus
     * peniru, karena merekalah yang akan mulai berulang kali.
     *
     * `tanpaTenant()` dipakai eksplisit: seeder berjalan di console tanpa
     * pengguna, dan `SchoolResolver::id()` yang null memang sudah membuat
     * `SchoolScope` diam - tetapi mengandalkan kebetulan itu berarti seeder
     * ini berhenti membersihkan begitu ada yang menjalankannya dari konteks
     * sekolah, dan gagalnya tidak bersuara.
     *
     * @return int Jumlah attempt yang dihapus.
     */
    private function hapusAttempt(Exam $ujian): int
    {
        $peserta = ExamParticipant::query()
            ->where('exam_id', $ujian->id)
            ->whereIn('student_id', User::query()
                ->where('username', 'like', 'beban%')
                ->select('id'))
            ->pluck('id');

        if ($peserta->isEmpty()) {
            return 0;
        }

        return ExamAttempt::tanpaTenant()
            ->whereIn('exam_participant_id', $peserta)
            ->delete();
    }

    /**
     * Total kursi efektif sebuah sesi.
     *
     * Kueri yang sama dengan yang dipakai `sebar()` sebelum ia menolak - dan
     * `capacity_override` WAJIB ada di daftar kolomnya, karena kolom yang tidak
     * dimuat bernilai null dan `kapasitasEfektif()` membacanya sebagai "pakai
     * alokasi event" - lihat catatan panjang di `sebar()`.
     */
    private function kapasitasSesi(ExamSession $sesi): int
    {
        return (int) ExamSessionRoom::query()
            ->where('exam_session_id', $sesi->id)
            ->with('eventRoom.room:id,capacity')
            ->get()
            ->sum(fn (ExamSessionRoom $r) => $r->kapasitasEfektif());
    }

    /**
     * Naikkan kapasitas setiap ruang sesi sampai totalnya memuat N peserta.
     *
     * Dibagi RATA antar ruang, bukan seadanya. Membebankan seluruh tambahan ke
     * ruang pertama akan menjadikannya ruang berisi 236 orang - dan angka itu
     * ikut terbaca di papan monitoring serta laporan beban sebagai keadaan yang
     * sungguh terjadi di sekolah. Pembagian rata membuat uji bebannya mengukur
     * penyebaran yang masuk akal.
     *
     * @return array<int, int> Kapasitas per exam_session_room_id, sesudahnya.
     */
    private function naikkanKapasitas(ExamSession $sesi, int $jumlahPeserta): array
    {
        $ruangan = ExamSessionRoom::query()
            ->where('exam_session_id', $sesi->id)
            ->with('eventRoom.room:id,capacity')
            ->orderBy('id')
            ->get();

        // Relasi dimuat eksplisit di atas karena `kapasitasEfektif()`
        // menelusuri capacity_override -> eventRoom.capacity_override ->
        // room.capacity; relasi yang belum dimuat bernilai null dan terbaca
        // sebagai kapasitas nol.
        $perlu = (int) ceil($jumlahPeserta / max(1, $ruangan->count()));
        $hasil = [];

        foreach ($ruangan as $r) {
            if ($perlu > $r->kapasitasEfektif()) {
                $r->forceFill(['capacity_override' => $perlu])->save();
            }

            $hasil[$r->id] = $r->kapasitasEfektif();
        }

        return $hasil;
    }

    /**
     * Verifikasi presensi SELURUH peserta sesi ini sebagai hadir.
     *
     * Lewat `KonteksRuang` dan tautan ruang, sama seperti `DatabaseSeeder`
     * dan sama seperti pengawas di pintu kelas - karena `KehadiranService`
     * menuntut pelaku, dan pelaku yang dikarang akan menulis log yang
     * berbohong. Kalau ada akun yang tidak bisa diverifikasi, itu justru
     * yang harus terlihat sekarang, bukan saat uji beban.
     */
    private function verifikasiSemua(ExamSession $sesi): int
    {
        $kehadiran = app(KehadiranService::class);
        $total = 0;

        foreach ($sesi->rooms()->orderBy('id')->get() as $ruangSesi) {
            $ruang = $ruangSesi->eventRoom?->room;

            if ($ruang === null) {
                continue;
            }

            // Tautan ruang diterbitkan seperlunya: `KonteksRuang::dari()`
            // menolak permintaan yang tidak punya tautan DAN tidak punya
            // pengguna - lihat docblocknya. Seeder berjalan di console, jadi
            // ia memang tidak punya pengguna.
            $tautan = app(RoomEntryLinkService::class)->terbitkan($ruang);

            Session::put(KonteksRuang::KUNCI_SESI, [
                'exam_session_room_id' => $ruangSesi->id,
                'room_id' => $ruang->id,
                'room_entry_link_id' => $tautan->id,
                // Pengawas yang ditugaskan di ruang ini, supaya presensi yang
                // ditandatangani seeder menyebut ORANGNYA dan bukan
                // "R-01 (tautan ruang)". Sejak 2026-11-07 identitas itu memang
                // tersedia lewat kode pengawas; di sini ia dibaca dari
                // penugasannya, karena seeder tidak mengetik kode apa pun.
                'school_id' => $tautan->school_id,
                'pengguna_id' => $ruangSesi->supervisors()->value('supervisor_id'),
                'sejak' => now()->toIso8601String(),
            ]);

            $konteks = KonteksRuang::dari($ruangSesi);

            foreach ($kehadiran->daftarRuang($ruangSesi) as $record) {
                if ($record->sudahDiverifikasi()) {
                    continue;
                }

                $kehadiran->catat($record, StatusPresensi::Hadir, $konteks);
                $total++;
            }

            Session::forget(KonteksRuang::KUNCI_SESI);
        }

        return $total;
    }
}

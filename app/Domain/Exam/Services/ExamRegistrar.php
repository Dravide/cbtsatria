<?php

namespace App\Domain\Exam\Services;

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Models\ExamSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pembuatan dan perubahan ujian - PRD §9.2.
 *
 * ---------------------------------------------------------------------
 * Kode ujian dibangkitkan, dan polanya disalin dari EventRegistrar
 * ---------------------------------------------------------------------
 * Bentuknya `UJ-2026-001`, dan urutannya diambil dari KODE TERTINGGI yang
 * sudah ada - bukan dari jumlah baris. Alasannya sama persis dengan kode
 * event: ujian yang pernah dihapus membuat jumlah baris menurun, dan kode
 * berikutnya menabrak kode yang masih dipakai.
 *
 * Yang menyalin pola ini, bukan menyatukannya jadi satu kelas generik, adalah
 * keputusan sadar: kedua kode itu punya awalan, panjang urutan, dan sumber
 * tahun yang berbeda, dan satu kelas generik untuk dua bentuk berarti tiga
 * parameter yang tidak bisa ditebak dari namanya.
 *
 * ---------------------------------------------------------------------
 * Tahun diambil dari EVENT, bukan dari ujian yang diketik
 * ---------------------------------------------------------------------
 * Ujian tidak punya tahun ajarannya sendiri. Ia milik event, dan event milik
 * tahun ajaran - jadi bertanya "ujian ini tahun berapa" berarti menanyakan
 * eventnya. Menyimpan salinan tahun di exams berarti dua tempat yang bisa
 * berselisih saat event dipindahkan ke tahun ajaran lain.
 *
 * ---------------------------------------------------------------------
 * bekukanPaket: sambungan Fase 5 yang Fase 4 isi
 * ---------------------------------------------------------------------
 * PRD §9.2 menyebut "paket soal" sebagai field ujian, dan kriteria selesai
 * blueprint untuk Fase 5 berbunyi "...mengganti isi paket asli tidak mengubah
 * snapshot".
 *
 * Snapshot itu milik Fase 4, karena yang perlu dibekukan adalah SOALNYA.
 * Metode ini ADA dan DIPANGGIL dari `buat()` sejak Fase 5, isinya kosong saat
 * itu, dan Fase 4 menggantinya dengan pemanggilan `PembekuPaket` - pemanggilnya
 * tidak berubah, dan itu seluruh gunanya.
 *
 * Yang tetap berlaku meski isinya sudah berisi: PEMBUATAN UJIAN dan PEMBEKUAN
 * SOALNYA berada di satu transaksi. Ujian yang lahir tanpa snapshotnya adalah
 * ujian yang tidak punya soal, dan keadaan itu tidak boleh pernah tersimpan.
 */
class ExamRegistrar
{
    /**
     * Ujian lahir tanpa status, dan itu memang bentuknya.
     *
     * Sejak Fase 5 kolom `exams.status` diisi `'draft'` di sini dengan alasan
     * "ujian yang lahir terbit berarti jendela sesinya tidak pernah ditinjau".
     * Alasan itu tetap benar - tetapi ia menjelaskan mengapa ujian **tidak
     * punya** tombol terbit, bukan mengapa ia butuh kolomnya sendiri. Yang
     * ditinjau adalah SESINYA, dan sesi punya status sendiri yang benar-benar
     * bergerak (`StatusSesi`) dan benar-benar dibaca.
     *
     * Kolomnya dijatuhkan 2026-11-07; lihat migrasi 0016_01_01_000030.
     *
     * @param  array<string, mixed>  $data
     */
    public function buat(SchoolEvent $event, array $data, int $userId): Exam
    {
        return DB::transaction(function () use ($event, $data, $userId) {
            $ujian = Exam::create([
                'school_id' => $event->school_id,
                'event_id' => $event->id,
                'event_activity_id' => $data['event_activity_id'] ?? null,
                'subject_id' => $data['subject_id'],
                'question_package_id' => $data['question_package_id']
                    ?? $this->paketMapelEvent((int) $event->id, (int) $data['subject_id']),
                'created_by' => $userId,
                'code' => $data['code'] ?? $this->kodeBerikutnya($event),

                // Judul dibangkitkan saat Admin tidak mengetiknya - dan
                // sejak laman Jadwal berhenti menampilkan kolomnya, itulah
                // jalur yang dipakai setiap ujian baru. Lihat
                // `judulOtomatis()` di bawah.
                'title' => filled($data['title'] ?? null)
                    ? $data['title']
                    : $this->judulOtomatis(
                        $event,
                        (int) $data['subject_id'],
                        $data['exam_type'] ?? $event->event_type,
                        $data['class_id'] ?? [],
                    ),

                // Jenis ujian kini milik EVENT (`school_events.event_type`,
                // disunting di laman Ubah event), bukan pilihan per ujian.
                // Ujian baru menyalinnya supaya kolom `exam_type` tetap terisi
                // untuk laporan lama yang sudah membacanya; yang MENENTUKAN
                // jenis adalah eventnya.
                'exam_type' => $data['exam_type'] ?? $event->event_type,
                'instructions' => $data['instructions'] ?? null,
                'duration_minutes' => (int) ($data['duration_minutes'] ?? 60),
                'max_attempts' => (int) ($data['max_attempts'] ?? 1),
                'shuffle_questions' => (bool) ($data['shuffle_questions'] ?? false),
                'shuffle_options' => (bool) ($data['shuffle_options'] ?? false),
                'allow_back_navigation' => (bool) ($data['allow_back_navigation'] ?? true),
                'allow_late_entry' => (bool) ($data['allow_late_entry'] ?? false),
                'late_entry_deadline' => $data['late_entry_deadline'] ?? null,

                // Tidak ada `publish_results` di sini. Publikasi hasil adalah
                // aksi SATU EVENT (plate 21, `school_events.results_published_at`),
                // dan kolom per ujian sudah dijatuhkan di Fase 8 - lihat catatan
                // kepala `Exam` dan migrasi 0008_01_01_000019.

                // Tidak ada `status` di sini sejak 2026-11-07. Kolomnya
                // dijatuhkan karena hanya pernah berisi satu nilai dan tidak
                // dibaca siapa pun; lihat migrasi 0016_01_01_000030 dan
                // catatan kepala `Exam`.
            ]);

            $this->simpanKelas($ujian, $data['class_id'] ?? []);
            $this->bekukanPaket($ujian);

            return $ujian;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function perbarui(Exam $ujian, array $data): Exam
    {
        // Perhatikan yang TIDAK ada di sini: `code` dan `question_package_id`
        // tidak ikut berubah lewat jalur ini. Kode adalah identitas yang sudah
        // dipakai di jadwal tercetak, dan paket soal hanya berpindah lewat
        // bekukanPaket() supaya pembekuannya tidak bisa terlewat.
        $kolom = [];

        foreach ([
            'title', 'exam_type', 'instructions', 'event_activity_id',
        ] as $kunci) {
            if (array_key_exists($kunci, $data)) {
                $kolom[$kunci] = $data[$kunci] ?: null;
            }
        }

        if (array_key_exists('subject_id', $data)) {
            $kolom['subject_id'] = $data['subject_id'];
        }

        foreach (['duration_minutes', 'max_attempts'] as $kunci) {
            if (array_key_exists($kunci, $data)) {
                $kolom[$kunci] = (int) $data[$kunci];
            }
        }

        foreach ([
            'shuffle_questions', 'shuffle_options', 'allow_back_navigation',
            'allow_late_entry',
        ] as $kunci) {
            if (array_key_exists($kunci, $data)) {
                $kolom[$kunci] = (bool) $data[$kunci];
            }
        }

        if (array_key_exists('late_entry_deadline', $data)) {
            $kolom['late_entry_deadline'] = $data['late_entry_deadline'] ?: null;
        }

        if ($kolom !== []) {
            $ujian->fill($kolom)->save();
        }

        if (array_key_exists('class_id', $data)) {
            $this->simpanKelas($ujian, $data['class_id'] ?? []);
        }

        return $ujian;
    }

    /**
     * Judul ujian yang dibangkitkan dari jenis event dan mapelnya.
     *
     * ---------------------------------------------------------------------
     * Mengapa dibangkitkan, bukan diketik
     * ---------------------------------------------------------------------
     * "Judul ujian" adalah kolom yang isinya bisa diturunkan dari dua hal
     * yang SUDAH ditentukan Admin di tempat lain - jenis event dan mata
     * pelajaran. Yang terjadi saat ia diketik: 40 ujian dalam satu event
     * berjudul "ujian", "Ujian 2", "FISIKA FIX", dan "ujian fisika beneran" -
     * lalu laman koreksi, laporan, dan kartu peserta menampilkan keempatnya
     * apa adanya. Judul yang konsisten lebih berharga daripada judul yang
     * boleh apa saja.
     *
     * ---------------------------------------------------------------------
     * Jenisnya milik EVENT
     * ---------------------------------------------------------------------
     * Sejak 2026-11-12 jenis ujian tidak dipilih per ujian di laman Jadwal:
     * `school_events.event_type` sudah menyimpannya, dan satu event berisi
     * satu jenis kegiatan. Memilihnya lagi di tiap ujian hanya menciptakan
     * dua tempat yang bisa berselisih. `$jenis` tetap diterima sebagai
     * parameter supaya pemanggil lama yang mengirim `exam_type` eksplisit
     * (test, impor) tidak berubah perilakunya.
     *
     * Bentuknya: `{jenis} - {mapel} #{tingkat}`, dan bagian yang kosong
     * dilewati. Tingkat diambil dari kelas sasaran ujian (lihat `tingkat()`),
     * karena dalam satu event ada mapel yang sama untuk tiga tingkat dan yang
     * membedakan ujiannya adalah kelas yang mengerjakannya. `exams.title` NOT
     * NULL, jadi hasilnya tidak pernah string kosong: mapel yang tidak
     * ditemukan pun menghasilkan "Ujian", bukan pelanggaran constraint dengan
     * pesan yang tidak menjelaskan apa pun.
     *
     * ---------------------------------------------------------------------
     * Judul lama TIDAK ditimpa
     * ---------------------------------------------------------------------
     * Yang dibangkitkan hanya nilai saat ujian LAHIR. Nama mapel yang berubah
     * sesudahnya tidak menarik judul ujian yang sudah dipakai di jadwal
     * tercetak dan di berita acara - alasan yang sama dengan `code`.
     */
    public function judulOtomatis(
        SchoolEvent $event,
        int $subjectId,
        ?string $jenis = null,
        array $classId = [],
    ): string {
        $jenis ??= $event->event_type;

        $mapel = DB::table('event_subjects')
            ->join('subjects', 'subjects.id', '=', 'event_subjects.subject_id')
            ->where('event_subjects.event_id', $event->id)
            ->where('event_subjects.subject_id', $subjectId)
            ->value('subjects.name');

        $judul = $this->gabung($jenis, $mapel);
        $tingkat = $this->tingkat($subjectId, $classId);

        if ($tingkat === null) {
            return $judul;
        }

        return $judul.' #'.$tingkat;
    }

    /**
     * Tingkat yang dicantumkan di judul: `#9`.
     *
     * Diambil dari KELAS SASARAN lebih dulu, bukan dari mapelnya: satu mapel
     * "Informatika" ada untuk tingkat 7, 8, dan 9, dan yang membedakan dua
     * ujian Informatika di event yang sama adalah kelas yang mengerjakannya.
     * Ujian tanpa kelas sasaran berarti "seluruh peserta event", jadi
     * tingkatnya tidak bisa dijawab dan judulnya berhenti di nama mapel.
     *
     * Kelas sasaran yang mencampur dua tingkat juga tidak dijawab - menulis
     * "#8" untuk ujian 8 dan 9 sekaligus lebih menyesatkan daripada tidak
     * menulis apa pun. Fallback ke tingkat mapel hanya dipakai saat ujiannya
     * memang tidak punya kelas sasaran.
     *
     * @param  array<int, int>  $classId
     */
    private function tingkat(int $subjectId, array $classId): ?string
    {
        if ($classId !== []) {
            $tingkat = DB::table('classes')
                ->whereIn('id', $classId)
                ->whereNotNull('grade_level')
                ->distinct()
                ->pluck('grade_level')
                ->map(fn ($t) => trim((string) $t))
                ->filter()
                ->unique()
                ->values();

            return $tingkat->count() === 1 ? $tingkat->first() : null;
        }

        $tingkat = DB::table('subjects')->where('id', $subjectId)->value('grade_level');

        return blank($tingkat) ? null : trim((string) $tingkat);
    }

    /**
     * Bentuk judul: `{jenis} - {mapel}`.
     *
     * Pemisahnya ` - ` dan bukan spasi, karena `ASESMEN SUMATIF AKHIR TAHUN
     * Informatika` terbaca sebagai satu nama kegiatan yang panjang. Bagian
     * yang kosong dilewati, sehingga jenis yang tidak diisi menghasilkan nama
     * mapel saja - dan keduanya kosong menghasilkan "Ujian", bukan string
     * kosong yang melanggar `exams.title` NOT NULL.
     */
    private function gabung(?string $jenis, ?string $mapel): string
    {
        $bagian = array_filter([
            trim((string) $jenis),
            trim((string) $mapel),
        ], fn (string $s) => $s !== '');

        return $bagian === [] ? 'Ujian' : implode(' - ', $bagian);
    }

    /**
     * `UJ-2026-001`, dari tahun ajaran event dan urutan tertinggi yang ada.
     */
    public function kodeBerikutnya(SchoolEvent $event): string
    {
        $tahun = AcademicYear::find($event->academic_year_id);
        $tahunAngka = $tahun ? substr($tahun->name, 0, 4) : (string) now()->year;
        $awalan = 'UJ-'.$tahunAngka.'-';

        // Dicari lintas event pada tahun ajaran yang sama, bukan hanya di
        // dalam event ini: dua ujian di event berbeda tetap muncul berdampingan
        // di daftar jadwal sekolah, dan kode yang sama di dua baris membuat
        // percakapan "UJ-2026-003 itu yang mana" tidak punya jawaban.
        $terakhir = Exam::query()
            ->where('code', 'like', $awalan.'%')
            ->orderByDesc('code')
            ->value('code');

        $urut = $terakhir ? ((int) substr($terakhir, -3)) + 1 : 1;

        return $awalan.str_pad((string) $urut, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Paket yang sedang terpasang pada mapel event ini, atau null.
     *
     * Dipakai `buat()`. Sejak Fase 4 Admin memasang paket di plate 15 lewat
     * `PemasanganPaket`, dan UNTUK MAPEL YANG SAMA ujiannya lahir di plate 16 -
     * jadi paket itu memang sudah diketahui pada saat ujiannya dibuat. Yang
     * membuatnya tidak terbaca sampai 07 Nov 2026 hanyalah kenyataan bahwa
     * tidak ada satu pun baris yang membacanya: `buat()` menerima
     * `question_package_id` dari formulir yang tidak pernah mengirimkannya,
     * sehingga ujian selalu lahir dengan paket null - walau mapelnya sudah
     * dipasangi satu paket yang berstatus Siap.
     *
     * Dua kolom dibaca langsung, dan itu bukan jalan pintas. Modul Exam tidak
     * boleh menyentuh model modul lain kecuali lewat kontrak atau service -
     * peta di DomainBoundariesTest menegakkannya - dan yang dibutuhkan di sini
     * hanyalah satu nilai dari satu baris `event_subjects`.
     */
    private function paketMapelEvent(int $eventId, int $subjectId): ?int
    {
        $terpasang = DB::table('event_subjects')
            ->where('event_id', $eventId)
            ->where('subject_id', $subjectId)
            ->value('question_package_id');

        return $terpasang === null ? null : (int) $terpasang;
    }

    /**
     * Bekukan paket yang TERPASANG di mapel, tiap kali sesinya akan dibuka.
     *
     * ---------------------------------------------------------------------
     * Mengapa pembekuan tidak cukup dikerjakan sekali saat ujian lahir
     * ---------------------------------------------------------------------
     * `bekukanPaket()` sudah dipanggil `buat()`, dan itu tidak cukup untuk
     * urutan kerja yang docblock-nya sendiri janjikan: "Admin menyusun jadwal
     * lebih dulu, dan paketnya dipasang Gurunya belakangan". Ujian yang lahir
     * sebelum paketnya dipasang tidak pernah dibekukan ulang oleh apa pun -
     * sehingga snapshotnya tetap kosong, dan kegagalannya baru muncul di
     * gerbang siswa sebagai "Ujian ini tidak punya butir soal. Hubungi Admin
     * sebelum memulai." Ujian yang bisa dijadwalkan, ditempati 300 siswa, dan
     * baru ketahuan kosong saat tokennya dibuka.
     *
     * ---------------------------------------------------------------------
     * Mengapa di sini, bukan di tombol "Pasang paket"
     * ---------------------------------------------------------------------
     * `PemasanganPaket` hidup di modul Question, dan Question TIDAK boleh
     * menunjuk Exam - arah itu yang membuat bank soal bisa diuji sendirian.
     * Memanggil pembekuan dari sana akan menambahkan baris `Question -> Exam`
     * ke peta modul, dan lingkaran itu tepat yang dijaga
     * DomainBoundariesTest. Yang diubah adalah tabel Event, dan yang membaca
     * perubahannya adalah Exam - jadi pembekuannya memang urusan di sisi ini.
     *
     * ---------------------------------------------------------------------
     * Admin yang sudah memasang paket lewat laman Jadwal juga tertolong
     * ---------------------------------------------------------------------
     * `ExamRegistrar::perbarui()` sengaja tidak menulis `question_package_id`.
     * Tanpa method ini, paket yang dipasang SETELAH ujiannya lahir tidak akan
     * pernah masuk ke ujian mana pun, dan satu-satunya cara memperbaikinya
     * adalah menghapus ujiannya lalu membuatnya ulang.
     *
     * Amannya: `PembekuPaket::bekukan()` menghapus snapshot lama di dalam
     * transaksi sebelum menulis yang baru, dan method ini hanya menulis bila
     * paketnya memang berpindah. Sesi yang di-`siapkan()` dua kali, atau yang
     * kembali ke Draft lalu disiapkan lagi, tidak membekukan ulang apa pun.
     *
     * Yang TIDAK dikerjakan di sini, dan tetap tugas `mulai()`: memeriksa ruang
     * dan pengawas. `siapkan()` menandai jendelanya sudah final; kelayakan
     * menjalankannya diperiksa di tempat yang memang menjalankannya.
     */
    public function bekukanPaketMapel(ExamSession $sesi): void
    {
        $ujian = $sesi->exam;

        if ($ujian === null) {
            return;
        }

        $terpasang = $this->paketMapelEvent((int) $ujian->event_id, (int) $ujian->subject_id);

        // Mapelnya belum dipasangi paket, atau paketnya sudah yang ini. Yang
        // kedua adalah keadaan normal - sesi yang disiapkan, dikembalikan ke
        // Draft, lalu disiapkan lagi - dan membekukan ulang di situ berarti
        // menulis ulang snapshot yang isinya sama persis.
        if ($terpasang === null || $terpasang === (int) $ujian->question_package_id) {
            return;
        }

        $ujian->forceFill(['question_package_id' => $terpasang])->save();

        // Snapshot lama dibuang oleh PembekuPaket, dan paket kosong ditolak di
        // sana dengan ValidationException - sebelum sesinya berstatus Siap.
        $this->bekukanPaket($ujian);
    }

    /**
     * Membekukan isi paket soal ke dalam ujian - PRD §9.2, blueprint `:312`.
     *
     * ---------------------------------------------------------------------
     * Isinya diganti di Fase 4, PEMANGGILNYA TIDAK - dan itu seluruh gunanya
     * ---------------------------------------------------------------------
     * Fase 5 menulis metode ini kosong dengan satu log, sengaja: yang perlu
     * dibekukan adalah SOALNYA, dan bank soal belum ada. Bentuknya sudah
     * dipanggil dari `buat()` sejak saat itu, sehingga Fase 4 hanya mengganti
     * isinya - tidak menambah pemanggil di seluruh alur.
     *
     * ---------------------------------------------------------------------
     * Ujian TANPA paket tetap boleh lahir
     * ---------------------------------------------------------------------
     * Dan itu bukan kelonggaran, melainkan urutan kerja yang nyata: Admin
     * menyusun jadwal lebih dulu, dan paketnya dipasang Gurunya belakangan.
     * Yang menegakkan "ujian harus punya soal" bukan tempat ini, melainkan
     * gerbang kesiapan event - dan gerbang itulah yang menahan status event
     * berpindah ke Siap selama masih ada mapel tanpa paket.
     *
     * Paket yang sudah dipasang tetapi MASIH KOSONG ditolak `PembekuPaket`
     * dengan ValidationException, dan penolakan itu terjadi di dalam transaksi
     * `buat()` - sehingga ujiannya tidak pernah tersimpan.
     *
     * ---------------------------------------------------------------------
     * Jalur SESUDAHNYA, dan mengapa ia harus ada
     * ---------------------------------------------------------------------
     * Ujian yang lahir tanpa paket - karena Admin menyusunnya lebih dulu -
     * datang kembali lewat `bekukanPaketMapel()`, yang dipanggil
     * `SesiService::siapkan()`. Tanpa jalur itu, janji di atas hanya benar
     * untuk ujian yang paketnya sudah ada SEBELUM jadwalnya, dan sisanya
     * berakhir sebagai ujian kosong yang gempanya berbunyi di depan siswa.
     */
    public function bekukanPaket(Exam $ujian): void
    {
        if ($ujian->question_package_id === null) {
            Log::info('Ujian dibuat tanpa paket soal - snapshot dilewati.', [
                'exam_id' => $ujian->id,
                'code' => $ujian->code,
            ]);

            return;
        }

        $jumlah = app(PembekuPaket::class)->bekukan($ujian);

        Log::info('Paket soal dibekukan ke ujian.', [
            'exam_id' => $ujian->id,
            'code' => $ujian->code,
            'question_package_id' => $ujian->question_package_id,
            'butir' => $jumlah,
        ]);
    }

    /**
     * Kelas sasaran ujian.
     *
     * Kosong berarti "seluruh peserta event" - bukan "tidak ada yang ikut".
     * Perbedaan itu penting di PenempatanService: ujian tanpa kelas sasaran
     * menarik semua peserta event, dan menjadikannya "tidak ada kelas berarti
     * tidak ada peserta" akan membuat ujian umum tidak pernah punya peserta.
     *
     * @param  array<int, int>  $classId
     */
    private function simpanKelas(Exam $ujian, array $classId): void
    {
        $ujian->classes()->delete();

        if ($classId === []) {
            return;
        }

        // Kelas milik sekolah lain tidak lolos SchoolScope, sehingga tidak
        // ikut tersimpan - bukan galat yang membocorkan keberadaannya.
        $sah = SchoolClass::query()
            ->whereIn('id', $classId)
            ->pluck('id')
            ->all();

        foreach ($sah as $id) {
            $ujian->classes()->create([
                'school_id' => $ujian->school_id,
                'class_id' => $id,
            ]);
        }
    }
}

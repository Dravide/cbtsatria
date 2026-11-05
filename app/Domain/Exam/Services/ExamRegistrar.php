<?php

namespace App\Domain\Exam\Services;

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Models\Exam;
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
                'question_package_id' => $data['question_package_id'] ?? null,
                'created_by' => $userId,
                'code' => $data['code'] ?? $this->kodeBerikutnya($event),
                'title' => $data['title'],
                'exam_type' => $data['exam_type'] ?? null,
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

                // Selalu draft. Ujian yang lahir terbit berarti jendela
                // sesinya tidak pernah ditinjau - dan seluruh pemeriksaan
                // konflik jadwal menjadi hiasan.
                'status' => 'draft',
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

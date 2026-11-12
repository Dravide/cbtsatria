<?php

namespace App\Domain\Event\Services;

use App\Domain\Academic\Models\AcademicYear;
use App\Domain\Event\Enums\EventStatus;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Event\Support\AmbangPelanggaran;
use Illuminate\Support\Facades\DB;

/**
 * Pembuatan dan perubahan event.
 *
 * ---------------------------------------------------------------------
 * Kode event dibangkitkan, bukan diketik
 * ---------------------------------------------------------------------
 * PRD §6.2 menyebut "kode event" sebagai field utama, dan wireframe
 * menampilkan bentuknya `EV-2026-001`. Yang mengetiknya adalah Admin, dan
 * yang paling mungkin terjadi adalah dua event bernama sama pada tahun yang
 * sama mendapat kode yang sama pula - lalu unique constraint menolaknya
 * dengan pesan basis data yang tidak menjelaskan apa pun.
 *
 * Karena itu kodenya dibangkitkan dari tahun ajaran: `EV-{tahun}-{urut}`,
 * dengan urutan diambil dari kode tertinggi yang sudah ada pada tahun itu.
 * Admin masih bisa menimpanya bila sekolahnya punya konvensi sendiri; yang
 * dibangkitkan hanyalah nilai awalnya.
 *
 * ---------------------------------------------------------------------
 * Ambang disalin SEKALI, di sini
 * ---------------------------------------------------------------------
 * Kolom `violation_thresholds` diisi dari pengaturan sekolah pada saat
 * CREATE, dan tidak pernah lagi sesudahnya. Kalimat di plate 23 - "Event yang
 * sudah dibuat menyimpan nilainya sendiri dan tidak ikut berubah" - ditegakkan
 * oleh ketiadaan kode yang menyalin ulang, bukan oleh pemeriksaan. Tidak ada
 * jalur di kelas ini yang membaca pengaturan sekolah pada update.
 */
class EventRegistrar
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $pengaturan  Nilai dari PengaturanBawaan + settings sekolah.
     */
    public function buat(array $data, int $schoolId, int $userId, array $pengaturan): SchoolEvent
    {
        return DB::transaction(function () use ($data, $schoolId, $userId, $pengaturan) {
            $tahun = AcademicYear::findOrFail($data['academic_year_id']);

            $bawaan = (int) ($pengaturan['ambang_pelanggaran'] ?? 5);

            // Tiga ambang: nilai yang dikirim form bila ada, angka pengaturan
            // sekolah bila tidak. Satu angka sekolah dipakai untuk ketiganya -
            // itu yang membuat "bawaan" di plate 23 masuk akal meski plate 11
            // menampilkan tiga field.
            $ambang = new AmbangPelanggaran(
                peringatkan: (int) ($data['ambang_peringatkan'] ?? $bawaan),
                tandaiPengawas: (int) ($data['ambang_tandai_pengawas'] ?? $bawaan),
                kunciUjian: (int) ($data['ambang_kunci_ujian'] ?? $bawaan),
            );

            $event = SchoolEvent::create([
                'school_id' => $schoolId,
                'academic_year_id' => $tahun->id,
                'semester_id' => $data['semester_id'] ?? null,
                'code' => $data['code'] ?? $this->kodeBerikutnya($tahun),
                'name' => $data['name'],
                'event_type' => $data['event_type'] ?? null,
                'description' => $data['description'] ?? null,
                'start_at' => $data['start_at'] ?? null,
                'end_at' => $data['end_at'] ?? null,

                // Selalu draft. Event yang lahir langsung "siap" berarti
                // kesiapan tidak pernah diperiksa - dan seluruh gerbang di
                // EventStatusService menjadi hiasan.
                'status' => EventStatus::Draft,
                'violation_thresholds' => $ambang->toArray(),

                // Empat kebijakan layar, disalin SEKALI di sini dari nilai
                // pengaturan sekolah - alasan yang sama dengan ambang di
                // atas: event yang sudah dibuat tidak ikut berubah ketika
                // Admin mengubah bawaannya.
                'enforce_fullscreen' => (bool) ($data['enforce_fullscreen'] ?? true),
                'detect_split_screen' => (bool) ($data['detect_split_screen']
                    ?? $pengaturan['deteksi_layar_terbagi']
                    ?? true),
                'detect_float_window' => (bool) ($data['detect_float_window']
                    ?? $pengaturan['deteksi_jendela_mengambang']
                    ?? true),
                'show_watermark' => (bool) ($data['show_watermark']
                    ?? $pengaturan['tanda_air_ujian']
                    ?? true),

                'created_by' => $userId,
            ]);

            return $event;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function perbarui(SchoolEvent $event, array $data): SchoolEvent
    {
        // Perhatikan yang TIDAK ada di sini: `status` dan
        // `violation_thresholds` yang berasal dari pengaturan sekolah.
        // Status hanya berubah lewat EventStatusService, dan ambang hanya
        // berubah bila Admin benar-benar menyentuh fieldnya di form.
        $kolom = [];

        foreach (['name', 'event_type', 'description', 'semester_id', 'code'] as $kunci) {
            if (array_key_exists($kunci, $data)) {
                $kolom[$kunci] = $data[$kunci];
            }
        }

        foreach (['start_at', 'end_at'] as $kunci) {
            if (array_key_exists($kunci, $data)) {
                $kolom[$kunci] = $data[$kunci] ?: null;
            }
        }

        // Empat kebijakan layar, ditulis hanya bila fieldnya dikirim - alasan
        // yang sama dengan ambang di bawah: form ubah boleh menampilkan
        // keempatnya tanpa setiap penyimpanan menyentuh kolomnya.
        foreach (['enforce_fullscreen', 'detect_split_screen', 'detect_float_window', 'show_watermark'] as $kunci) {
            if (array_key_exists($kunci, $data)) {
                $kolom[$kunci] = (bool) $data[$kunci];
            }
        }

        // Ambang hanya ditulis ulang bila salah satu fieldnya dikirim. Ini
        // yang membuat form ubah boleh menampilkan ketiganya tanpa membuat
        // setiap penyimpanan menyentuh kolomnya - dan menyentuh kolomnya
        // berarti menyalin ulang dari pengaturan, yang justru dilarang.
        $adaAmbang = array_intersect_key($data, array_flip([
            'ambang_peringatkan', 'ambang_tandai_pengawas', 'ambang_kunci_ujian',
        ])) !== [];

        if ($adaAmbang) {
            $lama = $event->ambang();

            $kolom['violation_thresholds'] = (new AmbangPelanggaran(
                peringatkan: (int) ($data['ambang_peringatkan'] ?? $lama->peringatkan),
                tandaiPengawas: (int) ($data['ambang_tandai_pengawas'] ?? $lama->tandaiPengawas),
                kunciUjian: (int) ($data['ambang_kunci_ujian'] ?? $lama->kunciUjian),
            ))->toArray();
        }

        if ($kolom !== []) {
            $event->fill($kolom)->save();
        }

        return $event;
    }

    /**
     * `EV-2026-001`, dari tahun ajaran dan urutan tertinggi yang sudah ada.
     *
     * Diambil dari kode tertinggi, bukan dari jumlah baris: event yang pernah
     * dihapus akan membuat jumlah baris menurun, dan kode berikutnya
     * menabrak kode yang masih dipakai.
     */
    public function kodeBerikutnya(AcademicYear $tahun): string
    {
        $tahunAngka = substr($tahun->name, 0, 4);
        $awalan = 'EV-'.$tahunAngka.'-';

        $terakhir = SchoolEvent::query()
            ->where('code', 'like', $awalan.'%')
            ->orderByDesc('code')
            ->value('code');

        $urut = $terakhir ? ((int) substr($terakhir, -3)) + 1 : 1;

        return $awalan.str_pad((string) $urut, 3, '0', STR_PAD_LEFT);
    }
}

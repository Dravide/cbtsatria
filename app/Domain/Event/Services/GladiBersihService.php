<?php

namespace App\Domain\Event\Services;

use App\Domain\Event\Enums\EventStatus;
use App\Domain\Event\Models\EventClass;
use App\Domain\Event\Models\EventParticipant;
use App\Domain\Event\Models\EventRoom;
use App\Domain\Event\Models\EventSubject;
use App\Domain\Event\Models\SchoolEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mode latihan "Gladi Bersih Hari-H" (dry-run simulator).
 *
 * Menyalin konfigurasi event (mapel, kelas, ruang, dan sampel peserta) ke
 * event simulasi terisolasi (`is_simulasi = true`) agar panitia & pengawas
 * dapat berlatih alur hari-H tanpa memengaruhi data nilai atau laporan resmi.
 */
class GladiBersihService
{
    public const MAKS_SAMPEL_PESERTA = 10;

    public function buatDari(SchoolEvent $sumber, int $actorId): SchoolEvent
    {
        return DB::transaction(function () use ($sumber, $actorId) {
            $kodeBaru = Str::upper(Str::limit($sumber->code, 18, '')).'-SIM-'.random_int(100, 999);

            $simulasi = SchoolEvent::create([
                'school_id' => $sumber->school_id,
                'academic_year_id' => $sumber->academic_year_id,
                'semester_id' => $sumber->semester_id,
                'code' => $kodeBaru,
                'name' => '[GLADI BERSIH] '.$sumber->name,
                'event_type' => $sumber->event_type,
                'description' => 'Event simulasi latihan hari-H dari '.$sumber->code.'. Aman dihapus setelah gladi bersih.',
                'start_at' => now(),
                'end_at' => now()->addDays(2),
                'status' => EventStatus::Draft,
                'violation_thresholds' => $sumber->violation_thresholds,
                'enforce_fullscreen' => $sumber->enforce_fullscreen,
                'detect_split_screen' => $sumber->detect_split_screen,
                'detect_float_window' => $sumber->detect_float_window,
                'show_watermark' => $sumber->show_watermark,
                'show_objectives' => $sumber->show_objectives,
                'show_explanations' => $sumber->show_explanations,
                'is_simulasi' => true,
                'created_by' => $actorId,
            ]);

            foreach ($sumber->classes()->get() as $kelas) {
                EventClass::create([
                    'school_id' => $simulasi->school_id,
                    'event_id' => $simulasi->id,
                    'class_id' => $kelas->class_id,
                ]);
            }

            foreach ($sumber->subjects()->get() as $mapel) {
                EventSubject::create([
                    'school_id' => $simulasi->school_id,
                    'event_id' => $simulasi->id,
                    'subject_id' => $mapel->subject_id,
                    'question_package_id' => $mapel->question_package_id,
                    'duration_minutes' => $mapel->duration_minutes,
                    'passing_score' => $mapel->passing_score,
                ]);
            }

            foreach ($sumber->rooms()->get() as $ruang) {
                EventRoom::create([
                    'school_id' => $simulasi->school_id,
                    'event_id' => $simulasi->id,
                    'room_id' => $ruang->room_id,
                    'capacity_override' => $ruang->capacity_override,
                    'status' => $ruang->status,
                ]);
            }

            foreach ($sumber->participants()->limit(self::MAKS_SAMPEL_PESERTA)->get() as $peserta) {
                EventParticipant::create([
                    'school_id' => $simulasi->school_id,
                    'event_id' => $simulasi->id,
                    'student_id' => $peserta->student_id,
                    'class_id' => $peserta->class_id,
                    'status' => $peserta->status,
                    'butuh_perangkat' => $peserta->butuh_perangkat,
                ]);
            }

            return $simulasi;
        });
    }
}

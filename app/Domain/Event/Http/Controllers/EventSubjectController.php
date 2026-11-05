<?php

namespace App\Domain\Event\Http\Controllers;

use App\Domain\Academic\Models\Subject;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Event\Models\EventSubject;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Question\Services\PemasanganPaket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mata pelajaran & paket soal - wireframe plate 15.
 *
 * =====================================================================
 * Kolom "Paket soal" kini dibaca dari baris pemasangan
 * =====================================================================
 * Fase 3 membangun kolom itu berisi "belum" pada setiap baris, dengan catatan
 * yang menyebut Fase 4 sebagai penggantinya. Fase 4 tiba, dan yang diganti
 * adalah SUMBER jawabannya - bukan tempatnya.
 *
 * Yang menjawabnya sekarang: `event_subjects.question_package_id`, kolom yang
 * ditulis `PemasanganPaket` dalam transaksi yang sama dengan baris riwayatnya.
 * Tiga keadaan yang dibedakan di layar, karena ketiganya menuntut tindakan
 * Admin yang berbeda:
 *
 *   belum    - tidak ada paket terpasang. Gerbang kesiapan menolak.
 *   kosong   - paket terpasang tetapi belum berisi butir. Gerbang menolak,
 *              dan pesannya menyebut paket mana yang harus diisi.
 *   terpasang - paket terpasang dan berisi. Gerbang meloloskan.
 *
 * ---------------------------------------------------------------------
 * Modul Event TIDAK mengimpor model Question
 * ---------------------------------------------------------------------
 * Peta modul (DomainBoundariesTest) melarangnya, dan pelarangan itu memang
 * disengaja: bank soal harus bisa diuji tanpa event, dan sebaliknya. Yang
 * dipakai di sini adalah SERVICE-nya, dan service boleh dipanggil lintas
 * modul - lihat docblock PenghitungButir tentang aturan blueprint `:422`.
 *
 * Yang kembali dari service itu hanya teks dan angka. Event tidak pernah
 * memegang QuestionPackage, sehingga tidak ada jalan bagi laman ini untuk
 * menyimpan atau menghapusnya.
 *
 * ---------------------------------------------------------------------
 * Pemasangan dilakukan Admin, bukan Guru
 * ---------------------------------------------------------------------
 * Wireframe plate 15 mencatatnya: "mengubah paket setelah jadwal tersimpan
 * harus lewat satu pintu, dan pintu itu mencatat siapa yang mengubahnya serta
 * kapan." Guru menulis soal di bank soal (Fase 4); Admin memutuskan paket mana
 * dipakai di event ini.
 */
class EventSubjectController
{
    public function index(SchoolEvent $event, PemasanganPaket $pemasangan): Response
    {
        Gate::authorize('view', $event);

        $mapel = $event->subjects()->get();
        $mapelId = $mapel->pluck('subject_id');

        // Satu kueri untuk seluruh paket yang terpasang, bukan satu per baris:
        // event dengan empat puluh mapel berarti empat puluh paket, dan
        // `find()` berulang adalah empat puluh kueri untuk data yang muat
        // dalam satu.
        $ringkas = $pemasangan->ringkasPaket(
            $mapel->pluck('question_package_id')->filter()->map(fn ($id) => (int) $id)->all(),
        );

        $perMapel = $mapel->keyBy('subject_id');

        return Inertia::render('Admin/Event/Mapel', [
            'event' => $this->ringkas($event),
            'baris' => Subject::query()
                ->whereIn('id', $mapelId)
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'category', 'grade_level'])
                ->map(function (Subject $s) use ($perMapel, $ringkas) {
                    $baris = $perMapel->get($s->id);
                    $paketId = $baris?->question_package_id;
                    $paket = $paketId === null ? null : ($ringkas[(int) $paketId] ?? null);

                    return [
                        'id' => $s->id,
                        'kode' => $s->code,
                        'nama' => $s->name,
                        'kategori' => $s->category,
                        'tingkat' => $s->grade_level,
                        // Id baris event_subjects, bukan id mapel. Pemasangan
                        // berbicara tentang MAPEL DI SEBUAH EVENT, dan satu
                        // mapel yang dipakai di dua event punya dua baris.
                        'baris_id' => $baris?->id,
                        'paket_id' => $paketId,
                        'paket' => $paket['nama'] ?? null,
                        'paket_status' => $paket === null ? 'belum' : ($paket['butir'] > 0 ? 'terpasang' : 'kosong'),
                        'butir' => $paket['butir'] ?? null,
                    ];
                })->all(),
            'tersedia' => Subject::query()
                ->where('status', 'aktif')
                ->whereNotIn('id', $mapelId)
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn (Subject $s) => ['id' => $s->id, 'kode' => $s->code, 'nama' => $s->name])
                ->all(),
        ]);
    }

    public function store(Request $request, SchoolEvent $event, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('update', $event);

        $data = $request->validate([
            'id' => ['present', 'array'],
            'id.*' => ['integer'],
        ]);

        // Scope Subject menyaring sekolahnya; status aktif disaring di sini
        // supaya mapel yang dinonaktifkan tidak bisa ditambahkan ke event
        // baru - mapel nonaktif tetap muncul di event lama yang sudah
        // memakainya, dan itu memang yang diinginkan.
        $sah = Subject::query()
            ->whereIn('id', $data['id'])
            ->where('status', 'aktif')
            ->pluck('id');

        $sebelum = $event->subjects()->pluck('subject_id');

        // Mapel yang dilepas ikut menghapus BARIS-nya, dan baris itu membawa
        // riwayat pemasangan paketnya lewat cascade. Itu memang yang
        // diinginkan: mapel yang tidak lagi diujikan tidak boleh menyisakan
        // riwayat pemasangan yang menggantung, karena riwayat tanpa mapel
        // tidak menjawab pertanyaan apa pun.
        $event->subjects()->whereIn('subject_id', $sebelum->diff($sah))->delete();

        foreach ($sah->diff($sebelum) as $subjectId) {
            EventSubject::create([
                'school_id' => $event->school_id,
                'event_id' => $event->id,
                'subject_id' => $subjectId,
            ]);
        }

        $logger->log('Menetapkan mata pelajaran event', $event, ['mapel' => $sah->count()]);

        return back()->with('sukses', $sah->count().' mata pelajaran ditetapkan.');
    }

    /** @return array<string, mixed> */
    private function ringkas(SchoolEvent $event): array
    {
        return [
            'id' => $event->id,
            'kode' => $event->code,
            'nama' => $event->name,
            'status' => $event->status->value,
            'status_label' => $event->status->label(),
        ];
    }
}

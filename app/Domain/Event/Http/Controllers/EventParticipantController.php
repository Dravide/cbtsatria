<?php

namespace App\Domain\Event\Http\Controllers;

use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Event\Models\EventClass;
use App\Domain\Event\Models\EventParticipant;
use App\Domain\Event\Models\EventRoom;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Event\Services\EventParticipantService;
use App\Domain\Facility\Models\Room;
use App\Domain\Identity\Models\StudentProfile;
use App\Support\TabelQuery;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kelas & peserta event - wireframe plate 14, PRD §6.7.
 *
 * ---------------------------------------------------------------------
 * Kelas dan peserta di satu laman, bukan dua
 * ---------------------------------------------------------------------
 * Plate 14 menampilkan keduanya bersamaan karena keduanya satu keputusan:
 * "kelas mana yang ikut" dan "siapa saja orangnya". Memisahkannya berarti
 * Admin menetapkan kelas di satu tempat, menekan tombol tarik di tempat lain,
 * lalu kembali ke tempat pertama untuk memeriksa hasilnya.
 *
 * ---------------------------------------------------------------------
 * Tiga angka, dan angka ketiga yang paling mudah salah
 * ---------------------------------------------------------------------
 * "Kelas ditetapkan / Siswa terdaftar / Dikecualikan". Yang ketiga dihitung
 * dari peserta berstatus dikecualikan - dan itu sebabnya peserta yang keluar
 * ditandai, bukan dihapus. Sekolah yang mengecualikan tujuh siswa karena
 * mengikuti lomba perlu melihat angkanya tujuh, bukan nol.
 */
class EventParticipantController
{
    public function index(Request $request, SchoolEvent $event): Response
    {
        Gate::authorize('view', $event);

        $kelasId = $event->classes()->pluck('class_id');

        $cari = trim((string) $request->query('cari', ''));

        $query = EventParticipant::query()
            ->where('event_id', $event->id)
            ->with(['student:id,name,username,is_active', 'kelas:id,name']);

        // Pencarian diterapkan ke QUERY, sebelum paginasi - bukan sesudah.
        // Menyaring 50 baris yang sedang terlihat akan menghasilkan "tidak
        // ketemu" untuk siswa yang ada di halaman berikutnya, dan Admin
        // menyimpulkan siswanya belum masuk daftar padahal ia ada.
        //
        // Pencariannya lewat relasi, karena NIS dan nama ada di users dan
        // student_profiles - bukan di tabel peserta.
        if ($cari !== '') {
            $query->whereHas('student', fn ($q) => $q
                ->where('name', 'like', "%{$cari}%")
                ->orWhere('username', 'like', "%{$cari}%"));
        }

        $halaman = TabelQuery::buat(
            $query,
            $request,
            bolehDiurut: ['id', 'status', 'source'],
            urutBawaan: 'id',
        );

        $nisPerSiswa = StudentProfile::query()
            ->whereIn('user_id', $halaman->pluck('student_id'))
            ->pluck('nis', 'user_id');

        $angka = fn (string $status) => EventParticipant::query()
            ->where('event_id', $event->id)
            ->where('status', $status)
            ->count();

        return Inertia::render('Admin/Event/Peserta', [
            'event' => $this->ringkas($event),
            'baris' => $halaman->through(fn (EventParticipant $p) => [
                'id' => $p->id,
                'nis' => $nisPerSiswa[$p->student_id] ?? $p->student?->username,
                'nama' => $p->student?->name,
                'aktif' => (bool) $p->student?->is_active,
                'kelas' => $p->kelas?->name,
                'sumber' => $p->source,
                'status' => $p->status,
            ]),
            'angka' => [
                'kelas' => $kelasId->count(),
                'peserta' => $angka(EventParticipant::STATUS_TERDAFTAR),
                'dikecualikan' => $angka(EventParticipant::STATUS_DIKECUALIKAN),
            ],

            // Kelas yang ditetapkan ke event, beserta jumlah anggotanya -
            // inilah yang ditampilkan di kotak pemilih kelas.
            'kelas' => SchoolClass::query()
                ->whereIn('id', $kelasId)
                ->withCount(['enrollments as anggota' => fn ($q) => $q->where('status', 'aktif')])
                ->orderBy('name')
                ->get(['id', 'name', 'grade_level'])
                ->map(fn (SchoolClass $k) => [
                    'id' => $k->id,
                    'nama' => $k->name,
                    'tingkat' => $k->grade_level,
                    'anggota' => (int) $k->anggota,
                ])->all(),

            // Kelas yang BELUM ikut, untuk kotak "tambah kelas". Dibatasi
            // tahun ajaran event: menawarkan kelas tahun lain berarti
            // menawarkan siswa yang tidak ada di tahun ini.
            'kelas_tersedia' => SchoolClass::query()
                ->where('academic_year_id', $event->academic_year_id)
                ->whereNotIn('id', $kelasId)
                ->orderBy('name')
                ->get(['id', 'name', 'grade_level'])
                ->map(fn (SchoolClass $k) => ['id' => $k->id, 'nama' => $k->name])
                ->all(),

            'ruang' => $event->rooms()->with('room:id,code,name,capacity')->get()
                ->map(fn (EventRoom $r) => [
                    'id' => $r->id,
                    'kode' => $r->room?->code,
                    'nama' => $r->room?->name,
                    'kapasitas' => $r->kapasitasEfektif(),
                    'status' => $r->status,
                ])->all(),

            'ruang_tersedia' => Room::query()
                ->where('status', 'aktif')
                ->whereNotIn('id', $event->rooms()->pluck('room_id'))
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'capacity', 'device_count'])
                ->map(fn (Room $r) => [
                    'id' => $r->id,
                    'kode' => $r->code,
                    'nama' => $r->name,
                    'kapasitas' => $r->capacity,
                    'byod' => $r->device_count === 0,
                ])->all(),

            'filter' => ['cari' => $cari],
        ]);
    }

    /**
     * Tetapkan atau lepaskan kelas peserta.
     *
     * Berbeda dari "tarik peserta": menetapkan kelas hanya menyatakan kelas
     * mana yang ikut. Penarikan pesertanya adalah aksi terpisah, karena
     * keduanya diambil pada saat yang berbeda - kelas ditetapkan saat event
     * disusun, peserta ditarik saat pendaftaran ditutup.
     */
    public function simpanKelas(Request $request, SchoolEvent $event, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('update', $event);

        $data = $request->validate([
            'id' => ['present', 'array'],
            'id.*' => ['integer'],
        ]);

        // Hanya kelas milik tahun ajaran event ini. Scope menyaring sekolahnya;
        // pemeriksaan tahun ajaran menutup sisanya - kelas tahun lalu tidak
        // boleh masuk ke event tahun ini meski sekolahnya sama.
        $sah = SchoolClass::query()
            ->whereIn('id', $data['id'])
            ->where('academic_year_id', $event->academic_year_id)
            ->pluck('id');

        $sebelum = $event->classes()->pluck('class_id');
        $lepas = $sebelum->diff($sah);

        $event->classes()->whereIn('class_id', $lepas)->delete();

        foreach ($sah->diff($sebelum) as $classId) {
            EventClass::create([
                'school_id' => $event->school_id,
                'event_id' => $event->id,
                'class_id' => $classId,
            ]);
        }

        $logger->log('Menetapkan kelas peserta', $event, [
            'kelas' => $sah->count(),
            'dilepas' => $lepas->count(),
        ]);

        return back()->with('sukses', $sah->count().' kelas ditetapkan sebagai peserta event.');
    }

    /**
     * Tarik peserta dari keanggotaan kelas.
     *
     * Seluruh logikanya ada di service - lihat catatannya tentang mengapa
     * peserta bersumber manual tidak tersentuh, dan mengapa peserta yang
     * keluar ditandai alih-alih dihapus.
     */
    public function tarik(
        SchoolEvent $event,
        EventParticipantService $service,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $event);

        try {
            $hasil = $service->tarikDariKelas($event);
        } catch (DomainException $e) {
            return back()->with('galat', $e->getMessage());
        }

        $logger->log('Menarik peserta dari kelas', $event, $hasil);

        $pesan = $hasil['baru'].' peserta baru ditarik.';

        if ($hasil['keluar'] > 0) {
            $pesan .= ' '.$hasil['keluar'].' peserta dikeluarkan karena kelasnya tidak lagi ikut.';
        }

        return back()->with('sukses', $pesan." Total {$hasil['total']} peserta.");
    }

    public function tambah(Request $request, SchoolEvent $event, EventParticipantService $service, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('update', $event);

        $data = $request->validate([
            'student_id' => ['required', 'integer'],
            'class_id' => ['nullable', 'integer'],
        ]);

        try {
            $peserta = $service->tambahManual($event, $data['student_id'], $data['class_id'] ?? null);
        } catch (DomainException $e) {
            return back()->with('galat', $e->getMessage());
        }

        $logger->log('Menambah peserta manual', $event, ['peserta' => $peserta->id]);

        return back()->with('sukses', 'Peserta ditambahkan secara manual.');
    }

    /**
     * Kecualikan atau kembalikan peserta terpilih.
     *
     * Plate 14 menampilkan satu tombol "Kecualikan terpilih" beserta kotak
     * centang per baris. Aksi massal, bukan per baris: sekolah mengecualikan
     * siswa per kelompok (satu kelas yang ikut lomba, misalnya), dan
     * mengecualikannya satu per satu berarti mengulang pekerjaan tiga puluh
     * kali.
     */
    public function massal(
        Request $request,
        SchoolEvent $event,
        EventParticipantService $service,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $event);

        $data = $request->validate([
            'id' => ['required', 'array'],
            'id.*' => ['integer'],
            'status' => ['required', 'in:terdaftar,dikecualikan'],
        ]);

        // Peserta yang dikirim harus benar-benar milik event INI. Tanpa
        // penyaringan ini, id dari event lain akan ikut berubah statusnya -
        // dan scope tenant tidak menolongnya, karena sekolahnya sama.
        $sah = EventParticipant::query()
            ->where('event_id', $event->id)
            ->whereIn('id', $data['id'])
            ->pluck('id')
            ->all();

        $jumlah = $service->setStatus($sah, $data['status']);

        $logger->log('Mengubah status peserta', $event, [
            'status' => $data['status'],
            'jumlah' => $jumlah,
        ]);

        return back()->with('sukses', $jumlah.' peserta diperbarui.');
    }

    /**
     * Alokasikan atau lepaskan ruang.
     *
     * Kapasitas diperiksa di sini sebagai peringatan, bukan penolakan:
     * alokasi ruang yang kurang adalah keadaan yang perlu terlihat, bukan
     * yang perlu dicegah - ruang tambahan bisa ditambahkan, dan Admin yang
     * belum tahu berapa peserta yang akan hadir tidak boleh dihalangi.
     * Readiness gate yang menolak transisi ke Siap bila kapasitasnya kurang.
     */
    public function simpanRuang(Request $request, SchoolEvent $event, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('update', $event);

        $data = $request->validate([
            'id' => ['present', 'array'],
            'id.*' => ['integer'],
        ]);

        $sah = Room::query()
            ->whereIn('id', $data['id'])
            ->where('status', 'aktif')
            ->pluck('id');

        $sebelum = $event->rooms()->pluck('room_id');
        $lepas = $sebelum->diff($sah);

        $event->rooms()->whereIn('room_id', $lepas)->delete();

        foreach ($sah->diff($sebelum) as $roomId) {
            EventRoom::create([
                'school_id' => $event->school_id,
                'event_id' => $event->id,
                'room_id' => $roomId,
                'status' => 'aktif',
            ]);
        }

        // Ruang berstatus "perbaikan" (wireframe plate 09, R-09) tidak
        // ditawarkan sama sekali di daftar tersedia, sehingga penyaring
        // status di atas hanya menutup request yang mengarang id.

        $logger->log('Mengalokasikan ruang event', $event, ['ruang' => $sah->count()]);

        return back()->with('sukses', $sah->count().' ruang dialokasikan.');
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

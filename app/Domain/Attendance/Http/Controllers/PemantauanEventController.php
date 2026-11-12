<?php

namespace App\Domain\Attendance\Http\Controllers;

use App\Domain\Attendance\Enums\PeranTandaTangan;
use App\Domain\Attendance\Enums\SisiFoto;
use App\Domain\Attendance\Enums\StatusPresensi;
use App\Domain\Attendance\Models\AttemptQuestion;
use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Models\ExamViolation;
use App\Domain\Attendance\Models\SessionReport;
use App\Domain\Attendance\Services\InsidenService;
use App\Domain\Attendance\Services\KehadiranService;
use App\Domain\Attendance\Services\KonteksRuang;
use App\Domain\Attendance\Services\PencatatPelanggaran;
use App\Domain\Event\Models\EventRoom;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Exam\Services\NomorKursi;
use App\Domain\Exam\Services\PetaKursiEvent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Presensi dan monitoring lintas ruang, dari konsol Admin - PRD §3.5.
 *
 * =====================================================================
 * Mengapa BERBEDA dari laman pengawas, bukan sekadar versi lebih lebar
 * =====================================================================
 * Pertanyaan Admin dan pertanyaan pengawas bukan pertanyaan yang sama, dan
 * menyatukan keduanya menghasilkan laman yang menjawab keduanya dengan
 * buruk:
 *
 *   pengawas  "siapa di ruang SAYA yang belum saya tandai"   -> satu ruang
 *   Admin     "ruang mana yang pengawasnya belum selesai"    -> semua ruang
 *
 * Karena itu kolom utamanya adalah rekap - Admin mencari ruang yang
 * tertinggal, bukan memanggil nama satu per satu.
 *
 * Sejak 2026-11-10 daftar namanya ADA di laman ini (tombol "Koreksi
 * presensi"), tetapi ia TIDAK menarik kesimpulan ini kembali: daftarnya
 * adalah alat untuk MEMPERBAIKI satu baris, dan ia dibuka satu ruang pada
 * satu waktu. Yang tetap berbeda dari laman pengawas adalah titik masuknya -
 * Admin tiba di nama itu lewat ruang yang rekapnya tertinggal. Di laman
 * MONITORING daftar nama punya alasan lain lagi: rekap tidak bisa menjawab
 * "anak ini sampai nomor berapa". Lihat catatan `monitoring()`.
 *
 * ---------------------------------------------------------------------
 * Admin boleh MENGOREKSI, dan koreksinya menuntut alasan SELALU
 * ---------------------------------------------------------------------
 * Ini kewenangan yang diberikan PRD §3.5 secara eksplisit ("Presensi:
 * Monitoring atau koreksi"), dan ia berbeda sifatnya dari penandaan
 * pengawas: Admin mengubah catatan ruang yang tidak ia masuki, sering kali
 * berhari-hari sesudahnya.
 *
 * ---------------------------------------------------------------------
 * Alasan koreksi Admin: divergensi yang disengaja dari plate 17
 * ---------------------------------------------------------------------
 * Plate 17 membebaskan pengawas dari alasan SELAMA sesi berjalan, dan
 * membebaskannya justru karena ia melihat orangnya berdiri di depan kursi.
 * Admin tidak melihat apa pun - yang sampai kepadanya adalah laporan bahwa
 * "anak saya sebenarnya hadir". Mewarisi keringanan itu akan menghasilkan
 * koreksi tanpa kalimat, tepat pada kasus yang paling perlu kalimat.
 *
 * Yang TIDAK dilakukan Admin di sini: mengubah baris lama. `catat()`
 * menulis baris `attendance_logs` baru setiap kali, tanpa cabang.
 */
class PemantauanEventController
{
    public function __construct(
        private readonly KehadiranService $kehadiran,
        private readonly InsidenService $insiden,
        private readonly NomorKursi $kursi,
        private readonly PetaKursiEvent $peta,
        private readonly PencatatPelanggaran $pencatat,
    ) {}

    /**
     * Ringkasan presensi per RUANG EVENT - dan alasannya bukan pilihan gaya.
     *
     * ---------------------------------------------------------------------
     * Sampai 2026-11-10 laman ini per RUANG-SESI, dan ia hanya memuat kelas 7
     * ---------------------------------------------------------------------
     * Setiap kartu dulu dibangun dari `$ruangan` - `exam_session_rooms` - dan
     * daftar anaknya dari baris `exam_participants` yang menunjuk ruang-sesi
     * itu. Akibatnya pada event yang hanya SEBAGIAN ujiannya punya sesi,
     * laman ini memuat kelas 7 saja: ujian kelas 8 tidak punya sesi, sehingga
     * penyebaran tidak pernah menulis `exam_session_room_id` untuk 432
     * siswanya, dan kelas 9 tidak ikut ujian apa pun. Padahal laman
     * Penempatan dan denah menyebut seluruh 1265 anak.
     *
     * Sekarang kartunya per RUANG EVENT, dan penghuninya dihitung ULANG dari
     * kocokan benih lewat `PetaKursiEvent::rosterEvent()` - sumber yang sama
     * dengan denah, tablo, dan papan pengawas. Karena itu "39 peserta" di
     * sini sama dengan "39" di laman Penempatan, dan tidak menunggu "Sebar".
     *
     * Konsekuensinya disebut terang: peserta yang ujiannya belum punya sesi
     * muncul dengan status kosong - bukan `hadir`, bukan `tidak_hadir`,
     * karena memang belum ada yang bisa menandainya. `pending` menghitung
     * keduanya, sehingga "belum tuntas" tetap berarti apa yang dibaca Admin.
     *
     * ---------------------------------------------------------------------
     * Tanpa benih tersimpan, bentuk lamanya yang dipakai
     * ---------------------------------------------------------------------
     * Event yang belum pernah dikocok tidak punya peta untuk dihitung ulang.
     * Menggambar ruangan kosong akan menghapus satu-satunya kebenaran yang
     * ada - yaitu baris yang sudah tersebar. Karena itu `rosterEvent()`
     * mengembalikan null dan jalur lamanya (`presensiPerRuangSesi()`) yang
     * berjalan.
     */
    public function presensi(SchoolEvent $event): Response
    {
        Gate::authorize('view', $event);

        $ruangan = $this->ruangan($event);

        // Berita acaranya dibaca SEKALI untuk kedua bentuk laman - lihat
        // `beritaAcara()`. Dua pemanggilan berarti dua kueri atas tabel yang
        // sama untuk laman yang hanya dibuka satu kali.
        $laporan = $this->beritaAcara($ruangan);

        $roster = $this->peta->rosterEvent($event);

        return $roster === null
            ? $this->presensiPerRuangSesi($event, $ruangan, $laporan)
            : $this->presensiPerRuangEvent($event, $ruangan, $roster, $laporan);
    }

    /**
     * Bentuk lama: satu kartu per ruang-sesi, penghuni dari baris tersebar.
     *
     * Dipakai event yang belum pernah dikocok - lihat catatan `presensi()`.
     *
     * @param  Collection<int, ExamSessionRoom>  $ruangan
     * @param  Collection<int, SessionReport>  $laporan  kunci: exam_session_room_id
     */
    private function presensiPerRuangSesi(SchoolEvent $event, Collection $ruangan, Collection $laporan): Response
    {
        // Satu query presensi untuk SELURUH event, bukan satu per ruang.
        // Laman ini dibuka Admin di tengah hari-H saat basis data paling
        // sibuk, dan N+1 di sini berarti dua puluh query yang semuanya
        // mencari hal yang sama.
        $records = AttendanceRecord::query()
            ->whereIn('exam_participant_id', $ruangan->flatMap->participants->pluck('id'))
            ->get()
            ->keyBy('exam_participant_id');

        // Peta peserta -> ruang, dibangun dari relasi yang SUDAH dimuat
        // `ruangan()`. Tanpa ini, rekap per ruang harus mencari sendiri baris
        // mana miliknya - dan satu-satunya cara melakukannya tanpa memuat
        // ulang adalah kueri per ruang, yaitu N+1 yang justru dihindari.
        $ruangPeserta = $ruangan->flatMap(
            fn (ExamSessionRoom $r) => $r->participants->mapWithKeys(
                fn (ExamParticipant $p) => [$p->id => $r->id],
            ),
        );

        // Presensi yang sudah dimuat, dikelompokkan menurut ruangnya.
        $recordsPerRuang = $records->groupBy(fn (AttendanceRecord $r) => $ruangPeserta->get($r->exam_participant_id));

        // Jumlah insiden terbuka untuk SELURUH ruang dalam satu kueri -
        // lihat catatan `InsidenService::jumlahTerbukaPerRuang()`.
        $insidenPerRuang = $this->insiden->jumlahTerbukaPerRuang($ruangan);

        $baris = $ruangan->map(function (ExamSessionRoom $ruang) use ($records, $recordsPerRuang, $insidenPerRuang, $laporan) {
            $rekap = $this->kehadiran->rekapDari(
                $recordsPerRuang->get($ruang->id, new Collection),
            );

            return [
                'ruang_id' => $ruang->id,
                'ruang' => $ruang->eventRoom?->room?->code ?? 'R-?',
                'ruang_nama' => $ruang->eventRoom?->room?->name,
                'lokasi' => $ruang->eventRoom?->room?->location,
                'sesi_id' => $ruang->session?->id,
                'sesi' => $ruang->session?->label(),
                'ujian' => $ruang->session?->exam?->title,
                'jam' => $ruang->session === null
                    ? null
                    : $ruang->session->access_opens_at->format('H:i').'–'.$ruang->session->hard_end_at->format('H:i'),
                'pengawas' => $ruang->supervisors
                    ->map(fn ($t) => $t->supervisor?->name)
                    ->filter()
                    ->values()
                    ->all(),
                'rekap' => $rekap,
                'insiden' => $insidenPerRuang[$ruang->id] ?? 0,

                // Jalur ini barisnya SUDAH ruang-sesi, jadi berita acaranya
                // satu - bandingkan `presensiPerRuangEvent()`, yang harus
                // menggabungkan beberapa ruang-sesi di bawah satu ruang event.
                'berita_acara' => [$this->ringkasLaporan($ruang, $laporan->get($ruang->id))],

                // Daftar anak-anaknyalah yang membuat laman ini bisa MEMPERBAIKI
                // sesuatu; tanpa mereka, Admin hanya bisa melihat bahwa satu
                // ruangan tertinggal tanpa bisa berbuat apa pun. `record_id`
                // disertakan karena koreksi bekerja atas baris presensi, bukan
                // atas peserta.
                'peserta' => $this->peserta($ruang, $records),
            ];
        })->values();

        return $this->renderPresensi($event, $baris);
    }

    /**
     * Bentuk baru: satu kartu per RUANG EVENT, penghuni dari kocokan benih.
     *
     * ---------------------------------------------------------------------
     * Presensinya dicari lewat PENEMPATAN, bukan lewat ruang-sesi
     * ---------------------------------------------------------------------
     * Daftar anaknya kini memuat siswa yang ujiannya belum punya sesi, dan
     * mereka tidak punya `exam_session_room_id`. Satu-satunya jalan dari
     * seorang siswa ke baris presensinya karena itu `student_id` ->
     * `exam_participants` -> `attendance_records`, bukan ruang-sesinya.
     *
     * Seorang siswa bisa punya LEBIH DARI SATU baris penempatan di event
     * yang sama - satu per ujian - dan karena itu dia bisa punya lebih dari
     * satu baris presensi. Yang dipakai adalah yang TERBARU: baris presensi
     * adalah catatan tentang orangnya di ruang itu, dan dua ujian di ruang
     * yang sama tidak menghasilkan dua kehadiran yang berbeda.
     *
     * ---------------------------------------------------------------------
     * Kuerinya tiga, berapa pun jumlah ruangnya
     * ---------------------------------------------------------------------
     * Satu untuk penempatan (di dalam `rosterEvent()`), satu untuk presensi,
     * satu untuk insiden. Tidak ada yang dijalankan per ruang maupun per
     * anak - sifat yang dijaga `PemantauanQueryTest`.
     *
     * @param  Collection<int, ExamSessionRoom>  $ruangan  Ruang sesi event ini.
     * @param  array<int, array<int, array{id: ?int, student_id: int, kursi: int, nama: ?string, nis: ?string, kelas: ?string}>>  $roster
     * @param  Collection<int, SessionReport>  $laporan  kunci: exam_session_room_id
     */
    private function presensiPerRuangEvent(SchoolEvent $event, Collection $ruangan, array $roster, Collection $laporan): Response
    {
        // Id penempatan setiap siswa, dipakai menautkan baris presensinya.
        // Dibaca dari simpanan `PetaKursiEvent` yang sudah diisi
        // `rosterEvent()`, sehingga ini TIDAK menambah satu kueri pun -
        // lihat `penempatanPerSiswa()`.
        //
        // Disaring ke ujian-ujian EVENT INI, dan penyaringan itu wajib: seorang
        // siswa yang ikut dua event punya baris penempatan di keduanya, dan
        // tanpa saringan ini kehadirannya di event lain terbaca sebagai
        // kehadiran di ruang ini.
        $idUjian = $event->exams()->pluck('id')->all();

        $idPenempatan = $this->peta
            ->penempatanPerSiswa($this->siswaDariRoster($roster))
            ->map(fn (\Illuminate\Support\Collection $baris) => $baris
                ->filter(fn (ExamParticipant $p) => in_array((int) $p->exam_id, $idUjian, true))
                ->values());

        // Satu kueri presensi untuk SELURUH event - lihat `presensiPerRuangSesi()`.
        // `flatMap`, bukan `flatten(1)`: `Arr::flatten` hanya meratakan
        // ARRAY, dan nilai di sini adalah Collection - yang akan lolos
        // sebagai satu item utuh dan tidak menghasilkan satu id pun.
        $records = AttendanceRecord::query()
            ->whereIn('exam_participant_id', $idPenempatan->flatMap(fn ($b) => $b->pluck('id')))
            ->get()
            ->keyBy('exam_participant_id');

        // Ruangnya dibaca dari RUANG EVENT, bukan dari ruang-sesinya: ruang
        // yang ujiannya belum dijadwalkan tidak punya satu pun ruang-sesi,
        // dan justru ruang itulah yang selama ini hilang dari laman ini.
        $fisik = EventRoom::query()
            ->where('event_id', $event->id)
            ->with('room:id,code,name,location')
            ->get()
            ->keyBy('id');

        // Insiden tetap dibaca per ruang-SESI: itulah tempatnya dilaporkan
        // pengawas. Sebuah ruang event bisa punya beberapa ruang-sesi, jadi
        // angkanya dijumlahkan per ruang EVENTnya.
        $insidenPerSesi = $this->insiden->jumlahTerbukaPerRuang($ruangan);
        $insidenPerRuang = $ruangan
            ->groupBy(fn (ExamSessionRoom $r) => (int) $r->event_room_id)
            // Parameter tanpa tipe, dan itu disengaja: `map()` pada Eloquent
            // Collection mengembalikan Collection BIASA begitu isinya bukan
            // model lagi - sehingga parameter bertipe `Collection` (Eloquent)
            // akan meledak sebagai TypeError.
            ->map(fn ($sesi) => $sesi->sum(fn (ExamSessionRoom $r) => $insidenPerSesi[$r->id] ?? 0));

        // Pengawas juga per ruang-sesi, dan sebuah ruang event bisa punya
        // lebih dari satu. Yang ditampilkan adalah gabungannya - pertanyaan
        // Admin adalah "siapa yang mengawasi ruang ini", bukan "siapa yang
        // mengawasi sesi ketiga".
        $pengawasPerRuang = $ruangan
            ->groupBy(fn (ExamSessionRoom $r) => (int) $r->event_room_id)
            ->map(fn ($sesi) => $sesi
                ->flatMap(fn (ExamSessionRoom $r) => $r->supervisors->map(fn ($t) => $t->supervisor?->name))
                ->filter()
                ->unique()
                ->values()
                ->all());

        // Berita acara per ruang EVENT. Satu ruang event bisa menampung
        // BEBERAPA ruang-sesi - satu per sesi yang memakainya - dan berita
        // acaranya melekat pada ruang-SESI, bukan pada ruang event. Karena
        // itu daftarnya, bukan satu nilai: ruang yang dipakai ujian pagi dan
        // siang punya dua laporan yang ditandatangani orang yang berbeda.
        //
        // Dikelompokkan dari koleksi yang SUDAH dimuat, sehingga tidak ada
        // kueri per ruang - sifat yang dijaga `PemantauanQueryTest`.
        $ruangSesiPerRuang = $ruangan->groupBy(fn (ExamSessionRoom $r) => (int) $r->event_room_id);

        $baris = [];

        foreach ($roster as $idRuangEvent => $anggota) {
            $ruang = $fisik->get((int) $idRuangEvent);
            $sesi = $ruangan->firstWhere('event_room_id', $idRuangEvent);

            $baris[] = [
                'ruang_id' => (int) $idRuangEvent,
                'ruang' => $ruang?->room?->code ?? 'R-?',
                'ruang_nama' => $ruang?->room?->name,
                'lokasi' => $ruang?->room?->location,
                'sesi_id' => $sesi?->session?->id,
                'sesi' => $sesi?->session?->label(),
                'ujian' => $sesi?->session?->exam?->title,
                'jam' => $sesi?->session === null
                    ? null
                    : $sesi->session->access_opens_at->format('H:i').'–'.$sesi->session->hard_end_at->format('H:i'),
                'punya_sesi' => $sesi !== null,
                'pengawas' => $pengawasPerRuang[$idRuangEvent] ?? [],
                'rekap' => $this->rekapRuangEvent($anggota, $idPenempatan, $records),
                'insiden' => (int) ($insidenPerRuang[$idRuangEvent] ?? 0),
                'berita_acara' => ($ruangSesiPerRuang->get((int) $idRuangEvent) ?? collect())
                    ->map(fn (ExamSessionRoom $r) => $this->ringkasLaporan($r, $laporan->get($r->id)))
                    ->values()
                    ->all(),
                'peserta' => $this->pesertaRuangEvent($anggota, $idPenempatan, $records),
            ];
        }

        return $this->renderPresensi($event, collect($baris));
    }

    /**
     * Rekap satu ruang EVENT: SELURUH penghuninya dihitung, bukan hanya yang
     * punya baris presensi.
     *
     * Perbedaan itu yang membuat laman ini berguna. Bila `total` diambil
     * dari `rekapDari($catatan)` - yaitu jumlah BARIS PRESENSI - maka ruang
     * yang sebelas dari tigapuluh sembilannya belum punya sesi akan terbaca
     * "11 peserta", dan Admin menyimpulkan ruangannya kosong alih-alih
     * melihat bahwa 28 anaknya belum dijadwalkan.
     *
     * Maka `total` = jumlah siswa di ruang itu, `pending` = yang belum punya
     * baris presensi DITAMBAH yang barisnya masih `pending`, dan sisanya
     * dihitung dari barisnya.
     *
     * @param  array<int, array{id: ?int, student_id: int, kursi: int, nama: ?string, nis: ?string, kelas: ?string}>  $anggota
     * @param  \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, ExamParticipant>>  $idPenempatan
     * @param  \Illuminate\Support\Collection<int, AttendanceRecord>  $records
     * @return array{total: int, hadir: int, terlambat: int, tidak_hadir: int, izin: int, pending: int}
     */
    private function rekapRuangEvent(array $anggota, \Illuminate\Support\Collection $idPenempatan, \Illuminate\Support\Collection $records): array
    {
        $catatan = collect($anggota)
            ->map(fn (array $p) => $this->recordUntuk($p['student_id'], $idPenempatan, $records))
            ->filter()
            ->values();

        $rekap = $this->kehadiran->rekapDari($catatan);

        // Peserta tanpa baris presensi masuk `pending` - dan itu memang arti
        // `pending` di seluruh aplikasi: "belum diverifikasi", bukan "tidak
        // datang".
        $tanpaBaris = count($anggota) - $catatan->count();
        $rekap['pending'] += $tanpaBaris;
        $rekap['total'] = count($anggota);

        return $rekap;
    }

    /**
     * Baris presensi satu siswa: yang TERBARU di antara penempatannya.
     *
     * @param  \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, ExamParticipant>>  $idPenempatan
     * @param  \Illuminate\Support\Collection<int, AttendanceRecord>  $records
     */
    private function recordUntuk(
        int $studentId,
        \Illuminate\Support\Collection $idPenempatan,
        \Illuminate\Support\Collection $records,
    ): ?AttendanceRecord {
        $baris = $idPenempatan->get($studentId);

        if ($baris === null) {
            return null;
        }

        return $baris
            ->map(fn (ExamParticipant $p) => $records->get($p->id))
            ->filter()
            ->sortByDesc(fn (AttendanceRecord $r) => $r->id)
            ->first();
    }

    /**
     * Daftar anak satu ruang EVENT, dengan kolom koreksinya.
     *
     * @param  array<int, array{id: ?int, student_id: int, kursi: int, nama: ?string, nis: ?string, kelas: ?string}>  $anggota
     * @param  \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, ExamParticipant>>  $idPenempatan
     * @param  \Illuminate\Support\Collection<int, AttendanceRecord>  $records
     * @return array<int, array<string, mixed>>
     */
    private function pesertaRuangEvent(array $anggota, \Illuminate\Support\Collection $idPenempatan, \Illuminate\Support\Collection $records): array
    {
        $baris = [];

        foreach ($anggota as $p) {
            $record = $this->recordUntuk($p['student_id'], $idPenempatan, $records);

            $baris[] = [
                // `peserta_id` boleh null - lihat catatan `payload()` di
                // `PetaKursiEvent`. Yang selalu ada adalah `student_id`.
                'peserta_id' => $p['id'],
                'student_id' => $p['student_id'],
                'kursi' => $p['kursi'],
                'record_id' => $record?->id,
                'nama' => $p['nama'] ?? 'Peserta',
                'nis' => $p['nis'],
                'kelas' => $p['kelas'],
                'status' => $record?->attendance_status->value,
                // Tanpa baris presensi, statusnya TIDAK diberi label
                // "belum diverifikasi": itu berbunyi seperti pengawas
                // yang lalai, padahal ujiannya memang belum dijadwalkan.
                'status_label' => $record === null
                    ? 'Belum ada sesi'
                    : $record->attendance_status->label(),
                'status_nada' => $record?->attendance_status->nada() ?? 'mute',
                'diverifikasi_oleh' => $record?->pelaku_label,
                'diverifikasi_jam' => $record?->verified_at?->format('H:i'),
            ];
        }

        return $baris;
    }

    /**
     * Seluruh `student_id` pada peta roster, tanpa duplikat.
     *
     * @param  array<int, array<int, array{student_id: int}>>  $roster
     * @return array<int, int>
     */
    private function siswaDariRoster(array $roster): array
    {
        $id = [];

        foreach ($roster as $anggota) {
            foreach ($anggota as $p) {
                $id[(int) $p['student_id']] = true;
            }
        }

        return array_keys($id);
    }

    /**
     * Payload akhir laman presensi - dipakai KEDUA bentuknya.
     *
     * Dipisahkan supaya angka ringkasannya tidak bisa berbeda antara event
     * yang sudah dikocok dan yang belum: keduanya menjawab pertanyaan yang
     * sama, dan dua tempat menjumlahkannya adalah dua tempat yang suatu hari
     * berbeda diam-diam.
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $baris
     */
    private function renderPresensi(SchoolEvent $event, \Illuminate\Support\Collection $baris): Response
    {
        return Inertia::render('Admin/Event/Presensi', [
            'event' => $this->ringkas($event),
            'baris' => $baris->all(),

            'angka' => [
                'ruang' => $baris->count(),
                // "Ruangan yang belum tuntas" adalah satu-satunya angka yang
                // membuat laman ini dibuka Admin: sisanya sudah terbaca di
                // kolomnya masing-masing.
                'belum_tuntas' => $baris->filter(fn (array $b) => $b['rekap']['pending'] > 0)->count(),
                'hadir' => $baris->sum(fn (array $b) => $b['rekap']['hadir']),
                'peserta' => $baris->sum(fn (array $b) => $b['rekap']['total']),
                'insiden' => $baris->sum('insiden'),

                // Ruang yang berita acaranya BELUM final - dan "belum" di sini
                // mencakup dua keadaan yang sama-sama perlu dikejar Admin:
                // belum pernah dibuka pengawasnya, dan sudah dibuka tetapi
                // fotonya/tanda tangannya belum lengkap. Keduanya dihitung
                // sebagai satu angka karena pertanyaan Admin-nya satu: "ruang
                // mana yang dokumennya belum selesai".
                'berita_acara_belum' => $baris
                    ->filter(fn (array $b) => collect($b['berita_acara'])
                        ->contains(fn (array $l) => $l['selesai'] === false))
                    ->count(),
            ],

            'opsi' => StatusPresensi::options(),

            // Seluruh mapel event, termasuk yang belum dijadwalkan - lihat
            // catatan `mapelEvent()`. Baris `baris` di atas hanya memuat yang
            // sudah punya sesi, dan tanpa daftar ini Admin tidak punya cara
            // mengetahui mapel mana yang tertinggal.
            'mapel' => $this->mapelEvent($event),

            'tanpa_pengawas' => $baris
                ->filter(fn (array $b) => $b['pengawas'] === [])
                ->pluck('ruang')
                ->values()
                ->all(),
        ]);
    }

    /**
     * Papan keadaan sesi lintas ruang.
     *
     * ---------------------------------------------------------------------
     * Barisnya RUANG, dan daftar anaknya punya laman sendiri
     * ---------------------------------------------------------------------
     * Laman ini menjawab "ruang mana yang tertinggal", dan itu pertanyaan
     * yang dijawab rekap - bukan daftar nama.
     *
     * Daftar nama itu sendiri tetap ada, karena ada pertanyaan yang tidak
     * bisa dijawab rekap: "anak ini sudah sampai nomor berapa". Rekap
     * menjawab berapa BANYAK yang sudah dikerjakan; ia tidak menjawab soal
     * MANA yang sedang dibuka, dan bagi anak yang melompat-lompat dua
     * jawaban itu berbeda jauh.
     *
     * Yang berubah: daftarnya tidak lagi dibuka di dalam kartu ruang di
     * laman ini, melainkan di `monitoringRuang()` - laman sendiri, dibuka
     * di tab baru dari tombol "Lihat peserta". Sampai 2026-11-09 ia bisa
     * dibuka di sini, satu ruang pada satu waktu, dan itu tiga hal yang
     * sekaligus salah:
     *
     *   1. Papan ini disegarkan terus selama ujian berjalan, dan membawa
     *      daftar seribu anak di payload-nya berarti setiap penyegaran
     *      mengirim ulang seluruh daftar yang belum tentu dibuka.
     *   2. Dua puluh ruang yang daftarnya bisa dibuka di satu halaman
     *      menghasilkan halaman yang tidak lagi bisa dipindai - dan
     *      kemampuan memindainya itulah gunanya papan ini.
     *   3. Admin yang menelusuri satu anak sedang mengerjakan satu
     *      pekerjaan. Tab tersendiri membuat pekerjaan itu tidak hilang
     *      saat ia kembali ke rekap, dan tautannya bisa dikirim ke panitia
     *      lain apa adanya.
     *
     * ---------------------------------------------------------------------
     * Biayanya nol kueri per ruang - dan itu yang menjaga `PemantauanQueryTest`
     * ---------------------------------------------------------------------
     * Seluruh angka tiap ruang dihitung dari `exam_attempts` yang SUDAH
     * dimuat di atas, ditambah SATU kueri insiden untuk seluruh ruang.
     * Tidak ada kueri yang dijalankan per ruang maupun per anak, sehingga
     * selisih antara papan berruang empat dan berruang delapan tetap nol.
     *
     * `exam_violations` TIDAK lagi dikueri di sini: pelanggaran per anak
     * dipakai sebagai chip di barisnya, dan barisnya kini ada di laman lain.
     * Angka "pelanggaran" per ruang datang dari `violation_count` milik
     * attempt yang sudah dimuat - kolom yang diisi `PencatatPelanggaran`
     * justru supaya papan ini tidak perlu menghitung ulang.
     */
    public function monitoring(SchoolEvent $event): Response
    {
        Gate::authorize('view', $event);

        $ruangan = $this->ruangan($event);
        $peserta = $ruangan->flatMap->participants;

        $attempt = $this->attemptTerakhir($peserta->pluck('id'));

        // Satu kueri untuk seluruh ruang, bukan satu per ruang - lihat
        // catatan di `InsidenService::jumlahTerbukaPerRuang()`.
        $insidenPerRuang = $this->insiden->jumlahTerbukaPerRuang($ruangan);

        $baris = $ruangan->map(function (ExamSessionRoom $ruang) use ($attempt, $insidenPerRuang) {
            $anak = $ruang->participants;

            return [
                'ruang_id' => $ruang->id,
                'ruang' => $ruang->eventRoom?->room?->code ?? 'R-?',
                'ruang_nama' => $ruang->eventRoom?->room?->name,
                'sesi' => $ruang->session?->label(),
                'ujian' => $ruang->session?->exam?->title,
                'status_sesi' => $ruang->session?->status->value,
                'status_label' => $ruang->session?->status->label(),

                'peserta' => $anak->count(),
                'mengerjakan' => $anak->filter(
                    fn (ExamParticipant $p) => $attempt->get($p->id)?->status === ExamAttempt::STATUS_BERJALAN,
                )->count(),
                'selesai' => $anak->filter(
                    fn (ExamParticipant $p) => $attempt->get($p->id)?->status === ExamAttempt::STATUS_DIKIRIM,
                )->count(),
                'terputus' => $anak->filter(function (ExamParticipant $p) use ($attempt) {
                    $a = $attempt->get($p->id);

                    return $a !== null && ! $a->terhubung();
                })->count(),
                'pelanggaran' => $anak->sum(fn (ExamParticipant $p) => $attempt->get($p->id)?->violation_count ?? 0),
                'terkunci' => $anak->filter(fn (ExamParticipant $p) => $attempt->get($p->id)?->dikunci() ?? false)->count(),
                'insiden' => $insidenPerRuang[$ruang->id] ?? 0,
            ];
        })->values();

        return Inertia::render('Admin/Event/Monitoring', [
            'event' => $this->ringkas($event),
            // Alamat laman rincian, supaya tautannya TIDAK disusun ulang di
            // React. Laman denah memakai pola yang sama (`denah_url`), dan
            // alasannya sama: alamat yang hidup di dua tempat adalah alamat
            // yang suatu hari berbeda di salah satunya.
            'ruang_url' => "/admin/events/{$event->id}/monitoring/ruang",
            'baris' => $baris->all(),

            // Daftar mapel yang AKAN diujikan - termasuk yang belum punya
            // sesi. Papan ini disusun dari ruang-sesi, sehingga mapel yang
            // jadwalnya belum dibuat tidak muncul satu kali pun di `baris`.
            // Tanpa daftar ini, papan yang kosong pada event yang belum
            // dijadwalkan terbaca sebagai "event tanpa mapel", padahal
            // mapelnya ada - hanya belum dijadwalkan.
            'mapel' => $this->mapelEvent($event),
            'angka' => [
                'ruang' => $baris->count(),
                'peserta' => $peserta->count(),
                'mengerjakan' => $baris->sum('mengerjakan'),
                'selesai' => $baris->sum('selesai'),
                'terputus' => $baris->sum('terputus'),
                'pelanggaran' => $baris->sum('pelanggaran'),
                'terkunci' => $baris->sum('terkunci'),
            ],
        ]);
    }

    /**
     * Keadaan per peserta SATU ruang-sesi - laman sendiri, tab sendiri.
     *
     * ---------------------------------------------------------------------
     * Ruangnya harus milik EVENT ini, dan itu bukan kehati-hatian
     * ---------------------------------------------------------------------
     * Binding implisit atas `ExamSessionRoom` hanya menjamin ruangnya milik
     * SEKOLAH ini. Tanpa pemeriksaan tambahan, Admin yang membuka event A
     * mengubah satu angka di URL dan membaca daftar anak event B - lengkap
     * dengan nama, NIS, dan nomor soal mereka. `ruangDiEvent()` yang
     * menutupnya, dan ia mengembalikan 404, bukan 403: yang 403 membuktikan
     * barisnya ada.
     *
     * ---------------------------------------------------------------------
     * Nomor kursi ikut, dan itu satu-satunya alasan `NomorKursi` ada di sini
     * ---------------------------------------------------------------------
     * Admin membaca laman ini untuk MENELUSURI satu anak, dan langkah
     * berikutnya hampir selalu menghubungi pengawas ruangnya. Yang
     * disampaikan saat itu adalah nomor kursi - bukan nomor peserta, yang
     * tidak tertulis di mana pun yang bisa dilihat pengawas. Nomornya
     * dihitung `NomorKursi`, aturan yang sama dengan papan pengawas, denah,
     * dan tablo.
     */
    public function monitoringRuang(SchoolEvent $event, ExamSessionRoom $ruang): Response
    {
        Gate::authorize('view', $event);

        $ruang = $this->ruangDiEvent($event, (int) $ruang->id);

        $anak = $ruang->participants;
        $idPeserta = $anak->pluck('id');

        $attempt = $this->attemptTerakhir($idPeserta);
        $pelanggaran = $this->pelanggaranPeserta($idPeserta);
        $butir = $this->butirRuang($attempt, $anak->count());

        return Inertia::render('Admin/Event/MonitoringRuang', [
            'event' => $this->ringkas($event),
            'ruang' => [
                'id' => (int) $ruang->id,
                'kode' => $ruang->eventRoom?->room?->code ?? 'R-?',
                'nama' => $ruang->eventRoom?->room?->name,
                'lokasi' => $ruang->eventRoom?->room?->location,
                'sesi' => $ruang->session?->label(),
                'ujian' => $ruang->session?->exam?->title,
                'status_sesi' => $ruang->session?->status->value,
                'status_label' => $ruang->session?->status->label(),
                'jam' => $ruang->session === null
                    ? null
                    : $ruang->session->access_opens_at->format('H:i').'–'.$ruang->session->hard_end_at->format('H:i'),
                'pengawas' => $ruang->supervisors
                    ->map(fn ($t) => $t->supervisor?->name)
                    ->filter()
                    ->values()
                    ->all(),
            ],
            'angka' => [
                'peserta' => $anak->count(),
                'mengerjakan' => $anak->filter(
                    fn (ExamParticipant $p) => $attempt->get($p->id)?->status === ExamAttempt::STATUS_BERJALAN,
                )->count(),
                'selesai' => $anak->filter(
                    fn (ExamParticipant $p) => $attempt->get($p->id)?->status === ExamAttempt::STATUS_DIKIRIM,
                )->count(),
                'terputus' => $anak->filter(function (ExamParticipant $p) use ($attempt) {
                    $a = $attempt->get($p->id);

                    return $a !== null && ! $a->terhubung();
                })->count(),
                'terkunci' => $anak->filter(fn (ExamParticipant $p) => $attempt->get($p->id)?->dikunci() ?? false)->count(),
                'pelanggaran' => $anak->sum(fn (ExamParticipant $p) => $attempt->get($p->id)?->violation_count ?? 0),
                'insiden' => $this->insiden->jumlahTerbuka($ruang),
            ],
            'anak' => $this->daftarAnak($ruang, $attempt, $pelanggaran, $butir['terjawab']),
            'soal' => $butir['soal'],
            'angka_soal' => $butir['angka'],
            'monitoring_url' => "/admin/events/{$event->id}/monitoring",
        ]);
    }

    /**
     * Koreksi presensi oleh Admin - dan alasannya WAJIB, selalu.
     *
     * Lihat catatan kelas ini tentang mengapa Admin tidak mewarisi
     * keringanan plate 17. Pesan galatnya menyebut sebabnya, bukan sekadar
     * "wajib diisi".
     */
    public function koreksi(SchoolEvent $event, Request $request): RedirectResponse
    {
        Gate::authorize('view', $event);

        $valid = $request->validate([
            'record_id' => ['required', 'integer'],
            'status' => ['required', 'string'],
            'alasan' => ['required', 'string', 'min:4', 'max:500'],
        ], [
            'alasan.required' => 'Koreksi presensi oleh Admin wajib disertai alasan tertulis.',
            'alasan.min' => 'Alasan koreksi terlalu pendek untuk bisa dipertanggungjawabkan.',
        ], ['record_id' => 'peserta', 'status' => 'status presensi', 'alasan' => 'alasan koreksi']);

        $status = StatusPresensi::tryFrom($valid['status']);

        if ($status === null) {
            return back()->with('galat', 'Status presensi itu tidak dikenal.');
        }

        // Barisnya dicari lewat PENEMPATANNYA, dan penempatan itu harus
        // berada di salah satu ruang-sesi event ini. Tanpa syarat kedua, id
        // dari event lain akan ditemukan - dan Admin yang membuka event A
        // bisa mengubah presensi event B dengan menyunting satu angka.
        $record = $this->recordDiEvent($event, (int) $valid['record_id']);

        if ($record === null) {
            abort(404);
        }

        $ruang = $record->penempatan?->room;

        if ($ruang === null) {
            abort(404);
        }

        $this->kehadiran->catat($record, $status, KonteksRuang::dari($ruang), $valid['alasan']);

        return back()->with('sukses', 'Koreksi tersimpan sebagai perubahan baru.');
    }

    /* ------------------------------------------------------------- Pembantu */

    /**
     * Berita acara SELURUH ruang-sesi event ini, dalam satu kueri.
     *
     * ---------------------------------------------------------------------
     * MEMBACA saja - dan itu berbeda dari laman pengawas
     * ---------------------------------------------------------------------
     * `LaporanSesiService::untuk()` memakai `firstOrCreate`, sehingga LAMAN
     * PENGAWAS membuat draf hanya dengan dibuka. Di sini itu justru salah:
     * Admin yang menyegarkan papan presensi setiap lima menit akan membuat
     * 33 draf kosong, dan "pengawas sudah membuka berita acaranya" - satu-
     * satunya informasi yang diberikan draf kepada Admin - berhenti berarti
     * apa pun.
     *
     * Karena itu yang dibaca hanya baris yang SUDAH ada, dan ruang tanpa
     * draf dikirim sebagai `null` di `ringkasLaporan()`.
     *
     * ---------------------------------------------------------------------
     * Dua relasi dimuat sekaligus, dan tidak ada yang dijalankan per ruang
     * ---------------------------------------------------------------------
     * Satu kueri laporan + satu kueri berkas + satu kueri tanda tangan,
     * berapa pun jumlah ruangnya. Menghitung "berapa foto" dan "ada tanda
     * tangan pengawas" lewat relasi yang tidak dimuat akan menghasilkan N+1
     * pada laman yang dibuka Admin di tengah ujian.
     *
     * @param  Collection<int, ExamSessionRoom>  $ruangan
     * @return Collection<int, SessionReport> kunci: exam_session_room_id
     */
    private function beritaAcara(Collection $ruangan): Collection
    {
        if ($ruangan->isEmpty()) {
            return collect();
        }

        return SessionReport::query()
            ->whereIn('exam_session_room_id', $ruangan->pluck('id'))
            ->with([
                'berkas:id,session_report_id,sisi,path,nama_asli',
                'tandaTangan:id,session_report_id,peran,nama,path,pelaku_label',
            ])
            ->get()
            ->keyBy('exam_session_room_id');
    }

    /**
     * Satu baris berita acara, ringkas - untuk ditumpangkan pada kartu ruang.
     *
     * ---------------------------------------------------------------------
     * Ia TIDAK memuat isi laporannya, dan itu disengaja
     * ---------------------------------------------------------------------
     * Catatan, foto, dan tanda tangan adalah isi DOKUMEN, dan dokumen dibaca
     * di lamannya sendiri (`/pengawas/ruang/{ruang}/berita-acara`) - lengkap
     * dengan fotonya dan kolom tanda tangannya. Yang dikirim di sini hanya
     * cukup untuk menjawab pertanyaan Admin: "ruang mana yang berita
     * acaranya belum selesai".
     *
     * `url` dibangun di server, bukan disusun di React: alamat yang hidup di
     * dua tempat adalah alamat yang suatu hari berbeda di salah satunya -
     * pola yang sama dengan `ruang_url` di `monitoring()`.
     *
     * ---------------------------------------------------------------------
     * `null` berarti BELUM DIBUKA, dan itu bukan "kosong"
     * ---------------------------------------------------------------------
     * Ruang tanpa baris laporan belum pernah dibuka pengawasnya. Mengirim
     * draf kosong untuknya akan menyamakan dua keadaan yang berbeda: "belum
     * disentuh siapa pun" dan "sudah dibuka, belum diisi" - dan Admin yang
     * mengejar pengawas perlu membedakannya.
     *
     * @return array<string, mixed>
     */
    private function ringkasLaporan(ExamSessionRoom $ruang, ?SessionReport $laporan): array
    {
        $kurang = $laporan?->kekuranganFinal() ?? [];
        $ttd = $laporan?->tandaTanganPeran(PeranTandaTangan::Pengawas);

        return [
            'ruang_sesi_id' => (int) $ruang->id,
            'pengawas' => $ruang->supervisors
                ->map(fn ($t) => $t->supervisor?->name)
                ->filter()
                ->values()
                ->all(),
            'url' => route('pengawas.berita-acara', ['ruang' => $ruang->id], absolute: false),

            // `false` = belum pernah dibuka pengawasnya. Tidak ada draf yang
            // dibuat untuknya - lihat `beritaAcara()`.
            'dibuka' => $laporan !== null,
            'final' => $laporan?->final() ?? false,
            'dikirim_pada' => $laporan?->submitted_at?->format('d M H:i'),

            // Jumlah foto, bukan daftar URL-nya: yang membuka berita acaranya
            // membuka lamannya, dan di sana fotonya tampil seukuran aslinya.
            'foto' => $laporan === null
                ? 0
                : $laporan->berkas->count(),
            'foto_sesudah' => $laporan?->berkasSisi(SisiFoto::Sesudah->value) !== null,

            // Tanda tangan pengawas adalah SATU-SATUNYA yang wajib - lihat
            // `PeranTandaTangan::wajib()` - sehingga ia satu-satunya yang
            // menentukan selesainya dokumen.
            'ttd_pengawas' => $ttd !== null,
            'ttd_oleh' => $ttd?->nama,

            // Daftar APA yang masih kurang, dari modelnya - bukan disusun
            // ulang di sini. Aturan yang hidup di dua tempat akan berbeda
            // suatu hari, dan yang berbeda berupa laporan yang tampak selesai
            // padahal belum.
            'kurang' => $kurang,
            'selesai' => $laporan !== null && $laporan->final(),
        ];
    }

    /**
     * Baris presensi satu ruang, untuk daftar peserta di laman Admin.
     *
     * Admin tidak memanggil nama satu per satu - tetapi ia MENGOREKSI, dan
     * koreksi bekerja atas baris presensi seseorang. Tanpa daftar ini,
     * laman presensi Admin hanya bisa memberi tahu bahwa satu ruangan
     * tertinggal tanpa memberi jalan memperbaikinya, dan Admin yang
     * menemukan anak yang tercatat alpa akan menelepon pengawasnya.
     *
     * `record_id` ada di setiap baris karena itulah yang dikirim kembali
     * saat koreksi - bukan `peserta_id`. Koreksi atas baris yang belum ada
     * akan gagal, dan penempatan yang belum punya baris presensi karena itu
     * ditampilkan tanpa tombolnya.
     *
     * @param  \Illuminate\Support\Collection<int, AttendanceRecord>  $records
     * @return array<int, array<string, mixed>>
     */
    private function peserta(ExamSessionRoom $ruang, \Illuminate\Support\Collection $records): array
    {
        return $ruang->participants
            ->map(function (ExamParticipant $p) use ($records) {
                $record = $records->get($p->id);

                return [
                    'peserta_id' => $p->id,
                    'record_id' => $record?->id,
                    'nama' => $p->student?->name ?? 'Peserta',
                    'nis' => $p->student?->studentProfile?->nis,
                    'status' => $record?->attendance_status->value,
                    'status_label' => $record?->attendance_status->label() ?? 'Belum ada baris presensi',
                    'status_nada' => $record?->attendance_status->nada() ?? 'mute',
                    'diverifikasi_oleh' => $record?->pelaku_label,
                    'diverifikasi_jam' => $record?->verified_at?->format('H:i'),
                ];
            })
            ->sortBy('nama')
            ->values()
            ->all();
    }

    /**
     * Buka kunci satu peserta dari papan monitoring Admin.
     *
     * ---------------------------------------------------------------------
     * Mengapa Admin perlu jalurnya sendiri
     * ---------------------------------------------------------------------
     * Sampai 2026-11-11 satu-satunya jalan membuka attempt yang terkunci
     * adalah tautan ruang pengawas. Itu cukup selama pengawasnya ada di
     * ruangnya - dan pada ujian yang siswanya sudah berpindah ke sesi
     * berikutnya, tautan ruang sesi lama berakhir 404: pesertanya tidak lagi
     * duduk di ruang itu. Admin yang sedang memantau justru melihat anak itu
     * di papan, terkunci, tanpa satu pun tombol.
     *
     * ---------------------------------------------------------------------
     * Yang ditulis SAMA dengan jalur pengawas
     * ---------------------------------------------------------------------
     * `PencatatPelanggaran::catatPembukaan()` dipakai ulang, bukan
     * diduplikasi: pembukaan kunci menulis baris `kunci_dibuka` ke riwayat
     * yang dibaca siswa di `/siswa/pelanggaran`, dan dua jalur yang menulis
     * dua bentuk baris akan membuat riwayat itu punya dua bahasa.
     *
     * Pelakunya ditandai lewat `pelaku_label` yang menyebut "Admin", sehingga
     * catatan siswa tidak menyebut pengawas ruang yang tidak pernah
     * menyentuhnya.
     */
    public function bukaKunci(SchoolEvent $event, Request $request, ExamParticipant $peserta): RedirectResponse
    {
        Gate::authorize('view', $event);

        // Peserta harus benar-benar milik event ini. Tanpa pemeriksaan ini,
        // nomor peserta berurutan cukup untuk membuka kunci anak di sekolah
        // lain pada event yang tidak sedang dipantau.
        abort_unless((int) $peserta->exam?->event_id === (int) $event->id, 404);

        $valid = $request->validate([
            'alasan' => ['nullable', 'string', 'max:200'],
        ]);

        $attempt = ExamAttempt::query()
            ->where('exam_participant_id', $peserta->id)
            ->orderByDesc('attempt_number')
            ->first();

        if ($attempt === null) {
            return back()->with('galat', 'Peserta ini belum mulai mengerjakan, jadi tidak ada yang terkunci.');
        }

        if (! $attempt->dikunci()) {
            return back()->with('galat', 'Pengerjaan peserta ini tidak sedang terkunci.');
        }

        $alasan = trim((string) ($valid['alasan'] ?? ''));

        $this->pencatat->catatPembukaan(
            $attempt,
            'Admin '.($request->user()?->name ?? ''),
            $alasan === '' ? 'Dibuka dari papan monitoring Admin.' : $alasan,
            $request->user()?->id,
        );

        return back()->with('sukses', 'Kunci dibuka. Penghitung pelanggaran tidak direset, dan pembukaannya tercatat.');
    }

    /**
     * Satu baris per peserta, untuk daftar yang dibuka Admin di bawah ruangnya.
     *
     * ---------------------------------------------------------------------
     * Nomor soal datang dari DENYUT, bukan dari `attempt_questions`
     * ---------------------------------------------------------------------
     * Kolom "Sampai nomor" adalah alasan daftar ini ada, dan ia satu-satunya
     * yang tidak bisa dihitung server di sini. Nomor yang dilihat anak
     * adalah `attempt_questions.display_order`, dan membacanya berarti satu
     * kueri per anak setiap kali papan disegarkan.
     *
     * Karena itu ia diambil dari `guard_flags_json['nomor']` yang ditulis
     * denyut anak - lihat `DenyutService::catat()`. Konsekuensinya nyata dan
     * dinyatakan di laman: angkanya setua denyut terakhirnya, dan anak yang
     * baru berpindah soal masih tampil di nomor lamanya sampai tiga puluh
     * detik berikutnya. Untuk "sampai nomor berapa" itu cukup; angka yang
     * lebih baru daripada denyutnya tidak ada.
     *
     * ---------------------------------------------------------------------
     * `progres` sengaja TIDAK dihitung ulang di sini
     * ---------------------------------------------------------------------
     * Ia sudah tersimpan bersama denyut yang sama (`JawabanService::progres()`
     * memanggilnya sekali di sana, dan sekali lagi berarti membaca seluruh
     * jawaban satu attempt dari nol - untuk setiap anak, di setiap muat
     * ulang). Karena itu kedua angka di baris ini selalu sepakat: keduanya
     * berasal dari denyut yang sama.
     *
     * Nomor kursinya dihitung atas RUANG EVENT - lihat catatan panjang di
     * `PetaKursiEvent` tentang mengapa nomor tidak disimpan, dan mengapa
     * yang mengikat adalah seluruh penghuni ruangnya, bukan peserta ujian
     * yang sedang berjalan. Kuncinya `student_id`, bukan id penempatan: satu
     * siswa punya satu baris penempatan per ujian, dan menomori barisnya akan
     * menaruh siswa yang sama di dua kursi.
     *
     * Nomornya boleh melompat di sini, dan itu memang benar: sesi yang hanya
     * diikuti sebagian kelas memuat kursi 3, 7, 12. Yang tidak boleh adalah
     * angkanya berbeda dari yang dibaca pengawas di ruang yang sama.
     *
     * @param  \Illuminate\Support\Collection<int, ExamAttempt>  $attempt
     * @param  \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, ExamViolation>>  $pelanggaran
     * @return array<int, array<string, mixed>>
     */
    private function daftarAnak(
        ExamSessionRoom $ruang,
        \Illuminate\Support\Collection $attempt,
        \Illuminate\Support\Collection $pelanggaran,
        \Illuminate\Support\Collection $terjawab,
    ): array {
        $kursi = $this->kursi->petaRuangEvent((int) $ruang->eventRoom?->id);

        return $ruang->participants
            ->map(function (ExamParticipant $p) use ($attempt, $pelanggaran, $kursi, $terjawab) {
                $a = $attempt->get($p->id);
                $jenis = $pelanggaran->get($p->id, collect());
                $butir = $terjawab->get($p->id);

                return [
                    'peserta_id' => $p->id,
                    'kursi' => $kursi[(int) $p->student_id] ?? null,
                    'nama' => $p->student?->name ?? 'Peserta',
                    'nis' => $p->student?->studentProfile?->nis,

                    'status' => $a?->status,
                    'status_label' => $a?->labelStatus() ?? 'Belum mulai',
                    'status_nada' => $a?->nadaStatus() ?? 'mute',

                    // Null berarti "belum ada kabar", dan itu memang berbeda
                    // dari 1 (anak baru membuka soal pertama). Memaksanya
                    // menjadi angka akan menghapus pembedaan itu.
                    'terhubung' => $a?->terhubung(),
                    'nomor' => $a?->guard_flags_json['nomor'] ?? null,
                    'progres' => $a?->guard_flags_json['progres'] ?? null,
                    'denyut' => $a?->last_heartbeat_at?->format('H:i:s'),

                    // Nomor soal yang SUDAH dan BELUM dikerjakan, dihitung
                    // server dari jawaban yang benar-benar tersimpan - lihat
                    // `butirRuang()`. Ini jawaban atas pertanyaan yang tidak
                    // bisa dijawab denyut: denyut menyebut nomor yang sedang
                    // DILIHAT anak, dan anak yang melompat ke nomor 40 lalu
                    // kembali ke nomor 3 punya daftar yang sama sekali lain.
                    //
                    // Ketiganya NULL untuk anak yang belum punya attempt sama
                    // sekali - bukan array kosong. Array kosong berarti "tidak
                    // ada satu pun soal yang belum dikerjakan", dan itu
                    // kebalikan dari keadaannya: anaknya belum membuka apa pun.
                    'dikerjakan' => $butir['dikerjakan'] ?? null,
                    'belum' => $butir['belum'] ?? null,
                    'ditandai' => $butir['ditandai'] ?? null,

                    // Peta nomor-yang-dilihat-anak ke soal ujiannya, berurut.
                    // Inilah yang membuat rincian per anak bisa memakai
                    // statistik per SOAL walau soalnya diacak - lihat
                    // `butirRuang()`. Null untuk anak yang belum membuka
                    // ujiannya, sama seperti ketiga daftar di atasnya.
                    'butir' => $butir['butir'] ?? null,

                    'pelanggaran' => $a?->violation_count ?? 0,
                    'jenis_pelanggaran' => $jenis
                        ->map(fn (ExamViolation $v) => $v->jumlah.' '.strtolower(ExamViolation::JENIS[$v->violation_type] ?? $v->violation_type))
                        ->values()
                        ->all(),

                    // --------------------------------------------------
                    // Keadaan layar, dibaca dari DENYUT dan RIWAYAT
                    // --------------------------------------------------
                    // Keduanya di sini punya alasan yang berbeda, dan
                    // menyatukannya akan membuat salah satunya berbohong.
                    //
                    // `layar_penuh` adalah KEADAAN SEKARANG, dilaporkan
                    // setiap tiga puluh detik (`DenyutService`). Ia bisa
                    // basi, dan ia menyebut keadaan - bukan perbuatan.
                    // Karena itu ia tidak pernah menambah penghitung
                    // pelanggaran; yang menambah adalah `keluar_layar_penuh`.
                    //
                    // `layar_terbagi` adalah JUMLAH PERISTIWA yang sudah
                    // tercatat. Ia tidak pernah berkurang, dan ia menjawab
                    // pertanyaan yang berbeda: "berapa kali".
                    //
                    // Keduanya berasal dari koleksi yang SUDAH dimuat
                    // (`$a` dari `attemptTerakhir()`, `$jenis` dari
                    // `pelanggaranPeserta()`), sehingga dua isyarat ini tidak
                    // menambah satu kueri pun - sifat yang dijaga
                    // `PemantauanQueryTest`.
                    'layar_penuh' => $a === null
                        ? null
                        : (bool) ($a->guard_flags_json['layar_penuh'] ?? false),
                    'layar_terbagi' => (int) ($jenis
                        ->firstWhere('violation_type', 'layar_terbagi')?->jumlah ?? 0),

                    'terkunci' => $a?->dikunci() ?? false,
                    'kunci_alasan' => $a?->lock_reason,
                ];
            })
            ->sortBy('nama')
            ->values()
            ->all();
    }

    /**
     * Soal mana yang sudah dan belum dikerjakan, per anak dan per butir.
     *
     * =====================================================================
     * Yang dihitung jawaban TERSIMPAN, bukan nomor yang dilaporkan peramban
     * =====================================================================
     * `guard_flags_json['nomor']` menjawab "anak ini sedang melihat soal
     * nomor berapa", dan itu isyarat yang datang tiap tiga puluh detik dari
     * peramban anak - bisa tertinggal, dan bisa saja anaknya melompat. Yang
     * dihitung di sini adalah `student_answers` yang benar-benar ada, atas
     * butir yang benar-benar ditulis `MesinUjian` untuk attempt itu.
     *
     * Karena itu daftar ini bisa berbeda dari kolom "Sampai nomor", dan
     * perbedaan itulah gunanya: anak yang sudah sampai nomor 12 tetapi baru
     * menyimpan tiga jawaban adalah anak yang perlu ditanyai pengawasnya.
     *
     * =====================================================================
     * Nomornya `display_order`, dan itu nomor yang dilihat anak
     * =====================================================================
     * `exam_questions.position` adalah urutan pembekuan paket; yang tercetak
     * di layar anak adalah `attempt_questions.display_order`. Keduanya sama
     * selama soal tidak diacak, dan berbeda begitu diacak - maka nomornya
     * diambil dari `display_order`, karena nomor yang dipanggil pengawas di
     * ruang ujian harus nomor yang dilihat anak.
     *
     * Konsekuensinya dinyatakan di laman: pada ujian yang mengacak soal,
     * nomor yang sama menunjuk soal yang BERBEDA untuk tiap anak, sehingga
     * tabel per-butir di bawahnya memakai urutan ujian, bukan urutan anak.
     *
     * =====================================================================
     * Tiga kueri untuk SELURUH ruang, bukan per anak
     * =====================================================================
     * Butir, jawaban, dan soalnya dimuat sekaligus untuk seluruh attempt di
     * ruang ini. Menghitungnya per anak akan menambah kueri linier terhadap
     * jumlah peserta - tepat pada laman yang dibuka Admin di tengah ujian.
     *
     * `benar` dan `salah` sengaja `null` untuk jenis yang tidak dinilai
     * otomatis (`JenisSoal::otomatis()`): `is_correct` uraian tidak pernah
     * diisi, dan menampilkan "0 benar" untuk seluruh uraian adalah angka yang
     * salah dibaca sebagai "tidak ada yang benar".
     *
     * @param  \Illuminate\Support\Collection<int, ExamAttempt>  $attempt  kunci: exam_participant_id
     * @param  int  $pesertaRuang  jumlah anak yang tercatat di ruang ini, attempt atau belum
     * @return array{
     *     terjawab: \Illuminate\Support\Collection<int, array{dikerjakan: array<int, int>, belum: array<int, int>, ditandai: array<int, int>, butir: array<int, array{nomor: int, soal_id: int, dijawab: bool, ditandai: bool}>}>,
     *     soal: array<int, array<string, mixed>>,
     *     angka: array<string, int>
     * }
     */
    private function butirRuang(\Illuminate\Support\Collection $attempt, int $pesertaRuang): array
    {
        $kosong = [
            'terjawab' => collect(),
            'soal' => [],
            'angka' => [
                'soal' => 0, 'peserta' => 0, 'sudah' => 0, 'belum' => 0,
                'ditandai' => 0, 'selesai_semua' => 0, 'belum_mulai' => $pesertaRuang,
            ],
        ];

        $punyaAttempt = $attempt->filter(fn (ExamAttempt $a) => $a->id !== null);

        if ($punyaAttempt->isEmpty()) {
            // Ruang yang belum satu pun anaknya membuka gerbang tidak
            // menyentuh basis data sama sekali - dan itu memang keadaannya,
            // bukan kekurangan.
            return $kosong;
        }

        $butir = AttemptQuestion::query()
            ->whereIn('exam_attempt_id', $punyaAttempt->pluck('id'))
            ->with([
                'jawaban:id,attempt_question_id,answer_json,is_correct,ditandai',
                'soal:id,position,jenis,judul,bobot',
            ])
            ->orderBy('display_order')
            ->get();

        $berAttempt = $butir->groupBy('exam_attempt_id');

        // attempt_id -> peserta_id, supaya barisnya bisa dikembalikan ke
        // daftar anak tanpa satu pun kueri tambahan.
        $pesertaDariAttempt = $punyaAttempt->mapWithKeys(
            fn (ExamAttempt $a, int $pesertaId) => [(int) $a->id => $pesertaId],
        );

        $perPeserta = collect();
        $selesaiSemua = 0;
        $sudah = 0;
        $ditandai = 0;

        foreach ($berAttempt as $attemptId => $daftar) {
            $pesertaId = $pesertaDariAttempt->get((int) $attemptId);

            if ($pesertaId === null) {
                continue;
            }

            $dikerjakan = [];
            $belum = [];
            $ragu = [];
            $urut = [];

            foreach ($daftar as $b) {
                $nomor = (int) $b->display_order;
                $dijawab = $b->jawaban?->terjawab() === true;
                $raguSatu = $b->jawaban?->ditandai === true;

                if ($dijawab) {
                    $dikerjakan[] = $nomor;
                    $sudah++;
                } else {
                    $belum[] = $nomor;
                }

                if ($raguSatu) {
                    $ragu[] = $nomor;
                    $ditandai++;
                }

                // Daftar berurut, satu baris per butir - dan inilah satu-satunya
                // hal di payload ini yang menghubungkan nomor yang DILIHAT anak
                // dengan SOAL yang dijumlahkan `statistikButir()`. Keduanya
                // berbeda begitu soalnya diacak: `display_order` milik attempt,
                // `position` milik paket. Tanpa perantaranya, rincian per anak
                // akan menempelkan statistik soal yang salah.
                $urut[] = [
                    'nomor' => $nomor,
                    'soal_id' => (int) $b->exam_question_id,
                    'dijawab' => $dijawab,
                    'ditandai' => $raguSatu,
                ];
            }

            sort($dikerjakan);
            sort($belum);
            sort($ragu);

            if ($belum === [] && $dikerjakan !== []) {
                $selesaiSemua++;
            }

            $perPeserta->put($pesertaId, [
                'dikerjakan' => $dikerjakan,
                'belum' => $belum,
                'ditandai' => $ragu,
                'butir' => $urut,
            ]);
        }

        $soal = $this->statistikButir($butir);

        return [
            'terjawab' => $perPeserta,
            'soal' => $soal,
            'angka' => [
                'soal' => count($soal),
                'peserta' => $perPeserta->count(),
                'sudah' => $sudah,
                'belum' => $butir->count() - $sudah,
                'ditandai' => $ditandai,
                'selesai_semua' => $selesaiSemua,
                // Anak yang tercatat di ruang ini tetapi belum punya attempt:
                // yang perlu ditanyakan pengawasnya lebih dulu. Dihitung dari
                // jumlah ANAK di ruangnya, bukan dari `$attempt` - koleksi itu
                // hanya memuat yang sudah punya attempt, sehingga selisihnya
                // selalu nol dan angkanya tidak akan pernah berarti apa pun.
                'belum_mulai' => max(0, $pesertaRuang - $perPeserta->count()),
            ],
        ];
    }

    /**
     * Statistik satu baris per SOAL ujian - sudah, belum, dan nilai benarnya.
     *
     * Dikelompokkan menurut `exam_question_id`, bukan menurut nomor: pada
     * ujian yang mengacak soal, satu nomor menunjuk soal berbeda di tiap
     * attempt, dan menjumlahkannya menurut nomor akan mencampur empat soal
     * yang berbeda menjadi satu baris.
     *
     * =====================================================================
     * "Belum dinilai" bukan nol, dan karena itu `benar`/`salah` nullable
     * =====================================================================
     * `is_correct` diisi penilaian otomatis, dan penilaian itu berjalan
     * SESUDAH ujiannya berakhir - bukan saat anak menyimpan jawabannya. Jadi
     * selama ujian berjalan, soal pilihan ganda pun belum punya nilai: `0
     * benar, 0 salah` untuk seluruhnya. Angka itu bukan netral - ia terbaca
     * sebagai "semua anak menjawab salah", dan Admin yang membacanya di
     * tengah ujian akan menghubungi pengawas untuk masalah yang tidak ada.
     *
     * Karena itu ketiganya null selama belum ada satu pun yang dinilai, dan
     * kolom `dinilai` menyebutkan berapa yang sudah.
     *
     * @param  Collection<int, AttemptQuestion>  $butir
     * @return array<int, array<string, mixed>>
     */
    private function statistikButir(Collection $butir): array
    {
        return $butir
            ->groupBy('exam_question_id')
            ->map(function (\Illuminate\Support\Collection $kelompok) {
                $soal = $kelompok->first()->soal;

                $terjawab = $kelompok->filter(fn (AttemptQuestion $b) => $b->jawaban?->terjawab() === true);
                $otomatis = $soal?->jenis->otomatis() ?? false;

                // Yang sudah dinilai mesin - `is_correct` terisi. Selama
                // penilaiannya belum berjalan, seluruh jawaban objektif pun
                // masih null, dan menghitungnya sebagai "salah" akan
                // melaporkan satu ruangan penuh jawaban salah pada menit
                // pertama ujian.
                $dinilai = $terjawab->filter(fn (AttemptQuestion $b) => $b->jawaban?->is_correct !== null);

                $jumlah = $kelompok->count();
                $sudah = $terjawab->count();
                $adaNilai = $otomatis && $dinilai->isNotEmpty();

                return [
                    'id' => (int) $kelompok->first()->exam_question_id,
                    // Urutan UJIAN, bukan urutan satu anak - lihat catatan
                    // `butirRuang()` tentang ujian yang mengacak soalnya.
                    'nomor' => (int) ($soal?->position ?? 0),
                    'jenis' => $soal?->jenis->value,
                    'jenis_label' => $soal?->jenis->label(),
                    'jenis_pendek' => $soal?->jenis->pendek(),
                    'judul' => $soal?->judul,
                    'bobot' => (float) ($soal?->bobot ?? 0),
                    'otomatis' => $otomatis,
                    'peserta' => $jumlah,
                    'sudah' => $sudah,
                    'belum' => $jumlah - $sudah,
                    'ditandai' => $kelompok->filter(fn (AttemptQuestion $b) => $b->jawaban?->ditandai === true)->count(),
                    'dinilai' => $dinilai->count(),
                    // `is_correct` null berarti BELUM DINILAI, bukan salah -
                    // dan itulah sebabnya ketiganya null, bukan nol: uraian
                    // menunggu koreksi guru, dan soal objektif menunggu
                    // penilaian otomatis berjalan.
                    'benar' => $adaNilai ? $dinilai->filter(fn (AttemptQuestion $b) => $b->jawaban?->is_correct === true)->count() : null,
                    'salah' => $adaNilai ? $dinilai->filter(fn (AttemptQuestion $b) => $b->jawaban?->is_correct === false)->count() : null,
                    'persen' => $jumlah === 0 ? 0 : (int) round($sudah / $jumlah * 100),
                ];
            })
            ->sortBy('nomor')
            ->values()
            ->all();
    }

    /**
     * Attempt TERAKHIR tiap peserta, dalam satu kueri.
     *
     * Peserta bisa punya lebih dari satu attempt - `attempt_number` naik saat
     * anak masuk kembali ke ujian yang sama - dan yang dibaca papan ini
     * selalu yang terakhir. Mengambilnya di PHP dari hasil yang sudah dimuat
     * (`groupBy` lalu `last()`) menghindari satu kueri per anak, dan urutan
     * `attempt_number` dijamin oleh `orderBy` sebelum `groupBy`.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $idPeserta
     * @return \Illuminate\Support\Collection<int, ExamAttempt>
     */
    private function attemptTerakhir(\Illuminate\Support\Collection $idPeserta): \Illuminate\Support\Collection
    {
        return ExamAttempt::query()
            ->whereIn('exam_participant_id', $idPeserta)
            ->orderBy('attempt_number')
            ->get()
            ->groupBy('exam_participant_id')
            ->map(fn ($baris) => $baris->last());
    }

    /**
     * Pelanggaran per peserta, dikelompokkan menurut jenisnya.
     *
     * Satu kueri untuk seluruh daftar, bukan satu per anak. Bentuknya sama
     * dengan papan pengawas (`MonitoringController::index()`) karena chip
     * yang dibacanya sama: "2 keluar tab".
     *
     * Ia TIDAK lagi dipanggil dari `monitoring()`: papan lintas ruang tidak
     * memuat daftar anak, jadi chip itu tidak ada di sana - lihat catatan
     * `monitoring()`.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $idPeserta
     * @return \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, ExamViolation>>
     */
    private function pelanggaranPeserta(\Illuminate\Support\Collection $idPeserta): \Illuminate\Support\Collection
    {
        return ExamViolation::query()
            ->whereIn('exam_participant_id', $idPeserta)
            ->selectRaw('exam_participant_id, violation_type, count(*) as jumlah')
            ->groupBy('exam_participant_id', 'violation_type')
            ->get()
            ->groupBy('exam_participant_id');
    }

    /**
     * Satu ruang-sesi, dicari HANYA di dalam event ini.
     *
     * Binding implisit menjamin ruangnya milik sekolah ini, bukan milik event
     * ini. Tanpa syarat kedua, Admin yang membuka event A mengubah satu angka
     * di URL dan membaca daftar anak event B - nama, NIS, dan nomor soal
     * mereka sekaligus. 404, bukan 403: yang 403 membuktikan barisnya ada.
     *
     * Ruang yang diminta tapi tidak dikembalikan `ruangan()` berarti ruang itu
     * tidak dipakai sesi mana pun di event ini - keadaan yang sama dengan
     * ruang yang tidak ada, dan diperlakukan sama.
     */
    private function ruangDiEvent(SchoolEvent $event, int $ruangId): ExamSessionRoom
    {
        $ruang = $this->ruangan($event)->firstWhere('id', $ruangId);

        if ($ruang === null) {
            abort(404);
        }

        return $ruang;
    }

    /**
     * Satu baris presensi, dicari HANYA di dalam event ini.
     *
     * Pencariannya lewat penempatannya, dan penempatan itu harus berada di
     * salah satu ruang-sesi event ini. Tanpa syarat kedua, id dari event
     * lain akan ditemukan - dan Admin yang membuka event A bisa mengubah
     * presensi event B dengan menyunting satu angka.
     */
    private function recordDiEvent(SchoolEvent $event, int $recordId): ?AttendanceRecord
    {
        $record = AttendanceRecord::query()
            ->with('penempatan:id,exam_session_room_id')
            ->find($recordId);

        if ($record === null) {
            return null;
        }

        $ruangId = $record->penempatan?->exam_session_room_id;

        if ($ruangId === null) {
            return null;
        }

        return $this->ruangan($event)->contains(fn (ExamSessionRoom $r) => (int) $r->id === (int) $ruangId)
            ? $record
            : null;
    }

    /**
     * Ruang-sesi seluruh event, beserta penempatan dan pengawasnya.
     *
     * Satu query untuk seluruh laman - dua laman Admin memakai daftar yang
     * sama, dan daftar yang disusun ulang di tiap method adalah daftar yang
     * suatu hari berbeda urutannya.
     *
     * @return Collection<int, ExamSessionRoom>
     */
    private function ruangan(SchoolEvent $event): Collection
    {
        $sesi = ExamSession::query()
            ->whereIn('exam_id', $event->exams()->pluck('id'))
            ->pluck('id');

        return ExamSessionRoom::query()
            ->whereIn('exam_session_id', $sesi)
            ->with([
                'eventRoom.room:id,code,name,location',
                'session.exam:id,code,title,subject_id',
                'session.exam.subject:id,name',
                'supervisors.supervisor:id,name',
                // Nama dan NIS ikut, karena laman presensi Admin
                // menampilkan daftar anaknya - lihat `peserta()`. NIS
                // datang dari profil siswa: yang menyimpannya adalah
                // StudentProfile, dan menyalinnya ke peserta event berarti
                // dua tempat yang bisa berbeda diam-diam saat siswa pindah
                // kelas.
                'participants:id,exam_session_room_id,student_id,school_id',
                'participants.student:id,name,school_id',
                'participants.student.studentProfile:id,user_id,nis',
            ])
            ->orderBy('exam_session_id')
            ->orderBy('id')
            ->get();
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

    /**
     * Seluruh mata pelajaran event ini - yang sudah dijadwalkan MAUPUN belum.
     *
     * ---------------------------------------------------------------------
     * Mengapa daftarnya tidak boleh diturunkan dari sesi
     * ---------------------------------------------------------------------
     * Kedua laman ini menyusun barisnya dari `exam_session_rooms`. Mapel yang
     * ujiannya belum punya sesi karena itu tidak muncul sama sekali - dan
     * akibatnya laman terlihat "lengkap" padahal baru sebagian mapel yang
     * dijadwalkan. Admin yang membuka dua laman ini untuk memeriksa kesiapan
     * justru kehilangan satu-satunya informasi yang ia cari: mapel mana yang
     * BELUM dijadwalkan.
     *
     * Sumbernya karena itu `event_subjects` - daftar mapel yang menjadi
     * tanggung jawab event ini - dan ujian/sesi/presensi digantung pada
     * barisnya. Mapel tanpa ujian tetap punya baris, dengan status
     * "belum dijadwalkan".
     *
     * ---------------------------------------------------------------------
     * Empat kueri, berapa pun jumlah mapelnya
     * ---------------------------------------------------------------------
     * Mapel, ujian, sesi, dan presensi masing-masing satu kueri. Menanyakan
     * presensi per ujian berarti satu kueri per mapel - persis yang dijaga
     * `PemantauanQueryTest` untuk laman-laman ini.
     *
     * @return array<int, array<string, mixed>>
     */
    private function mapelEvent(SchoolEvent $event): array
    {
        $mapel = $event->subjects()
            ->with('subject:id,code,name')
            ->get()
            ->sortBy(fn ($baris) => $baris->subject?->code)
            ->values();

        if ($mapel->isEmpty()) {
            return [];
        }

        $ujian = Exam::query()
            ->where('event_id', $event->id)
            ->get(['id', 'subject_id', 'title']);

        // Sesi dikelompokkan menurut ujiannya, dan urutannya nomor sesi -
        // bukan id: jadwal yang dibaca Admin berjalan menurut nomornya.
        $sesi = ExamSession::query()
            ->whereIn('exam_id', $ujian->pluck('id'))
            ->orderBy('session_number')
            ->get(['id', 'exam_id', 'session_number', 'status', 'access_opens_at', 'hard_end_at'])
            // `toBase()` wajib sebelum `only()`: `only()` milik Eloquent
            // Collection menuntut kuncinya adalah KUNCI MODEL, dan di sini
            // kuncinya adalah id ujian.
            ->toBase()
            ->groupBy('exam_id');

        $ruangPerSesi = ExamSessionRoom::query()
            ->whereIn('exam_session_id', $sesi->collapse()->pluck('id'))
            ->selectRaw('exam_session_id, count(*) as jumlah')
            ->groupBy('exam_session_id')
            ->pluck('jumlah', 'exam_session_id');

        // Peserta dan presensinya dihitung per UJIAN, bukan per mapel: satu
        // mapel bisa punya lebih dari satu ujian, dan menjumlahkan presensinya
        // sendiri berarti menghitung anak yang sama dua kali.
        $pesertaPerUjian = ExamParticipant::query()
            ->whereIn('exam_id', $ujian->pluck('id'))
            ->where('status', ExamParticipant::STATUS_TERDAFTAR)
            ->selectRaw('exam_id, count(*) as jumlah')
            ->groupBy('exam_id')
            ->pluck('jumlah', 'exam_id');

        $presensiPerUjian = AttendanceRecord::query()
            ->join('exam_participants', 'exam_participants.id', '=', 'attendance_records.exam_participant_id')
            ->whereIn('exam_participants.exam_id', $ujian->pluck('id'))
            ->selectRaw('exam_participants.exam_id as exam_id, attendance_records.attendance_status as status, count(*) as jumlah')
            ->groupBy('exam_participants.exam_id', 'attendance_records.attendance_status')
            ->get()
            ->toBase()
            ->groupBy('exam_id');

        // `toBase()` pada ketiganya bukan kehati-hatian: `only()` milik
        // Eloquent Collection memanggil `getKey()` pada kuncinya, dan kunci
        // di sini adalah Collection id ujian - yang membuatnya melempar
        // "Method getKey does not exist". Collection dasarnya hanya memakai
        // kuncinya sebagai array.
        $ujianPerMapel = $ujian->toBase()->groupBy('subject_id');

        return $mapel->map(function ($baris) use ($ujianPerMapel, $sesi, $ruangPerSesi, $pesertaPerUjian, $presensiPerUjian) {
            $daftarUjian = $ujianPerMapel->get($baris->subject_id, collect());
            $idUjian = $daftarUjian->pluck('id');

            // `collapse()`, bukan `flatten()`: nilai di sini adalah Collection
            // sesi per ujian, dan `flatten()` hanya meratakan ARRAY - Collection
            // lolos sebagai satu item utuh sehingga hitungannya selalu 0 atau 1.
            $jumlahSesi = $sesi->only($idUjian->all())->collapse()->count();

            // Presensi dijumlahkan lintas ujian mapel ini - lihat catatan
            // `$pesertaPerUjian`.
            $presensi = $presensiPerUjian->only($idUjian->all())->collapse();

            $hitung = fn (StatusPresensi $status) => (int) $presensi
                ->where('status', $status->value)
                ->sum('jumlah');

            return [
                'subject_id' => $baris->subject_id,
                'kode' => $baris->subject?->code,
                'nama' => $baris->subject?->name,
                'ujian' => $daftarUjian->pluck('title')->values()->all(),
                'sesi' => $daftarUjian->flatMap(fn ($u) => $sesi->get($u->id, collect())->map(fn ($s) => [
                    'id' => $s->id,
                    'label' => $s->label(),
                    'status' => $s->status->value,
                    'status_label' => $s->status->label(),
                    'jam' => $s->access_opens_at->format('H:i').'–'.$s->hard_end_at->format('H:i'),
                    'ruang' => (int) ($ruangPerSesi[$s->id] ?? 0),
                ]))->values()->all(),
                'jumlah_sesi' => $jumlahSesi,
                // Mapel dengan ujian tetapi tanpa sesi BELUM terjadwal; mapel
                // tanpa ujian sama sekali belum punya apa pun. Keduanya
                // dilaporkan berbeda supaya Admin tahu langkah berikutnya.
                'keadaan' => $jumlahSesi > 0 ? 'terjadwal' : ($daftarUjian->isEmpty() ? 'belum_ujian' : 'belum_sesi'),
                'peserta' => (int) $idUjian->sum(fn ($id) => (int) ($pesertaPerUjian[$id] ?? 0)),
                'hadir' => $hitung(StatusPresensi::Hadir) + $hitung(StatusPresensi::Terlambat),
                'tidak_hadir' => $hitung(StatusPresensi::TidakHadir),
                'izin' => $hitung(StatusPresensi::Izin),
                'belum_diverifikasi' => $hitung(StatusPresensi::Pending),
            ];
        })->values()->all();
    }
}

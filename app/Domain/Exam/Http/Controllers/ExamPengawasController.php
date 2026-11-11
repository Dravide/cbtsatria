<?php

namespace App\Domain\Exam\Http\Controllers;

use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Exam\Models\SupervisionAssignment;
use App\Domain\Exam\Services\PengawasService;
use App\Domain\Identity\Models\User;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pengawas & penempatan ruang - wireframe plate 17, PRD §9.6.
 *
 * ---------------------------------------------------------------------
 * Panitia berjabatan pengawas BUKAN penugasan, dan laman ini tempat bedanya
 * ---------------------------------------------------------------------
 * Plate 13 mencatat siapa saja pengawasnya di tingkat event - satu daftar
 * nama. Laman ini mencatat siapa yang berdiri di R-02 pada sesi 1. Plate 17
 * menyatakan mengapa pemisahan itu menentukan:
 *
 *   "penugasan inilah yang memberi Pengawas hak melihat dan memverifikasi
 *    presensi di ruang itu. Tanpa penugasan, ia tidak melihat apa pun."
 *
 * Karena itu halaman ini TIDAK membaca kepanitiaan sebagai penugasan. Ia
 * memakainya hanya sebagai daftar calon di panel "Tugaskan" dan di tombol
 * "Tugaskan otomatis" - supaya Admin tidak mengetik ulang nama yang sudah ia
 * masukkan di plate 13.
 *
 * ---------------------------------------------------------------------
 * Baris tabelnya adalah RUANG-SESI, bukan ruang
 * ---------------------------------------------------------------------
 * Plate 17 menampilkan `Ruang | Pengawas 1 | Pengawas 2 | Sesi`, dan barisnya
 * terbaca seperti satu ruang. Yang disimpan tabelnya bukan itu: `R-01` muncul
 * tiga kali bila ia dipakai tiga sesi, karena pengawas sesi pagi belum tentu
 * pengawas sesi siang - dan menugaskan satu orang untuk "R-01" tanpa menyebut
 * sesinya berarti ia dikunci sepanjang hari.
 *
 * Karena itu barisnya adalah (ruang, sesi), dan kolom `Sesi` menyebut sesi
 * mana yang dimaksud. Kolom itu bukan hiasan; ia satu-satunya yang membuat
 * barisnya bisa dibedakan.
 */
class ExamPengawasController
{
    public function index(SchoolEvent $event, PengawasService $penugasan): Response
    {
        Gate::authorize('view', $event);

        $sesi = ExamSession::query()
            ->whereIn('exam_id', $event->exams()->pluck('id'))
            ->with(['exam:id,code,title,subject_id', 'exam.subject:id,name'])
            ->orderBy('access_opens_at')
            ->orderBy('id')
            ->get();

        $ruangan = ExamSessionRoom::query()
            ->whereIn('exam_session_id', $sesi->pluck('id'))
            ->with([
                'eventRoom.room:id,code,name,capacity',
                'session:id,exam_id,session_number,access_opens_at,hard_end_at',
                'session.exam:id,code,title,subject_id',
                'session.exam.subject:id,name',
                'supervisors.supervisor:id,name,username,role',
            ])
            ->withCount(['participants as terisi' => fn ($q) => $q->where('status', 'terdaftar')])
            ->orderBy('exam_session_id')
            ->orderBy('id')
            ->get();

        // Tampilkan hanya baris untuk sesi yang SUDAH punya peserta terdaftar.
        // Laman ini menugaskan pengawas ke ruang yang benar-benar akan dipakai;
        // sesi yang belum punya peserta sama sekali belum membutuhkan pengawas,
        // dan menampilkan jadwalnya sekarang berarti menawarkan penugasan untuk
        // ujian yang tidak menjalankan siapa pun - keramaian yang menenggelamkan
        // sesi yang justru membutuhkan perhatian.
        //
        // Kelayakannya dihitung per UJIAN, bukan per sesi: peserta tergabung ke
        // ujian, dan satu ujian biasanya punya satu sesi (sesi susulan dikecualikan
        // terpisah). Ujian inilah yang dijadwalkan - sesi sebenarnya gelombangnya.
        $idUjianBerpeserta = ExamParticipant::query()
            ->whereIn('exam_id', $event->exams()->pluck('id'))
            ->where('status', ExamParticipant::STATUS_TERDAFTAR)
            ->distinct()
            ->pluck('exam_id');

        // Baris SESI-TANPA-RUANG dihitung terhadap himpunan ruangan SEBELUM
        // penyaringan ini. Sesi yang belum punya peserta sengaja tidak muncul
        // pada tabel, tetapi ia TETAP punya ruang - dan menyatakannya sebagai
        // "sesi tanpa ruang" adalah laporan yang salah tentang keadaannya.
        $semuaRuangan = $ruangan;

        $ruangan = $ruangan->filter(
            fn (ExamSessionRoom $r) => $r->session?->exam_id !== null
                && $idUjianBerpeserta->contains($r->session->exam_id),
        );

        $baris = $ruangan->map(fn (ExamSessionRoom $r) => [
            'id' => $r->id,
            'ruang' => $r->eventRoom?->room?->code,
            'ruang_nama' => $r->eventRoom?->room?->name,
            'kapasitas' => $r->kapasitasEfektif(),
            'sesi' => $r->session?->label(),
            'sesi_id' => $r->session?->id,
            'ujian' => $r->session?->exam?->subject?->name ?? $r->session?->exam?->title,
            'jam' => $r->session?->access_opens_at?->format('H:i'),
            'tanggal' => $r->session?->access_opens_at?->format('d M'),

            // Pengawas dikirim sebagai daftar, bukan dua kolom tetap: PRD §9.6
            // mengizinkan lebih dari dua pengawas per ruang, dan kolom tetap
            // berarti orang ketiga tidak punya tempat untuk muncul - ia
            // tersimpan, tetapi tidak terlihat di laman yang menugaskannya.
            'pengawas' => $r->supervisors
                ->map(fn (SupervisionAssignment $t) => [
                    'id' => $t->id,
                    'user_id' => $t->supervisor_id,
                    'nama' => $t->supervisor?->name,
                    'username' => $t->supervisor?->username,
                    'status' => $t->status,
                ])
                ->values()
                ->all(),

            'peserta' => (int) $r->terisi,
        ])->all();

        // Ruang tanpa pengawas dibaca dari PengawasService, bukan dihitung ulang
        // di sini: angka yang sama dipakai SesiService untuk menolak membuka
        // sesi, dan KPI yang bilang "0" sementara sesinya menolak dibuka adalah
        // layar yang bertengkar dengan dirinya sendiri.
        $tanpaPengawas = $penugasan->ruangTanpaPengawas($event);

        return Inertia::render('Admin/Event/Pengawas', [
            'event' => $this->ringkas($event),
            'baris' => $baris,

            'angka' => [
                'ruang' => $ruangan->count(),
                'ditugaskan' => $penugasan->jumlahPenugasan($event),
                'tanpa_pengawas' => $tanpaPengawas,
                'saran_per_ruang' => PengawasService::SARAN_PER_RUANG,
                'minimal_per_ruang' => PengawasService::MINIMAL_PER_RUANG,
            ],

            // Ruang yang sudah punya pengawas, tetapi belum sampai dua.
            //
            // Prop ini BUKAN daftar masalah, dan namanya sengaja bukan
            // "perhatian" lagi. Satu pengawas per ruang sah (PRD §9.6) dan
            // sesinya tetap bisa dibuka - yang menolak hanya ruang tanpa
            // pengawas sama sekali. Karena itu isinya disampaikan sebagai
            // saran di layar, bukan sebagai peringatan: sekolah dengan 24
            // ruang dan 26 pengawas memang hanya sanggup satu orang per ruang,
            // dan memberi tahu mereka "salah" sepanjang hari adalah layar yang
            // bertengkar dengan keadaan yang sudah benar.
            'saran' => collect($baris)
                ->filter(fn (array $b) => count($b['pengawas']) > 0
                    && count($b['pengawas']) < PengawasService::SARAN_PER_RUANG)
                ->values()
                ->all(),

            'calon' => collect($penugasan->calonPengawas($event))
                ->map(fn (User $u) => [
                    'id' => $u->id,
                    'nama' => $u->name,
                    'username' => $u->username,
                    'peran' => $u->role->label(),
                ])
                ->values()
                ->all(),

            // Sesi tanpa ruang tidak bisa ditugaskan: penugasan menempel pada
            // ruang-sesi, dan sesi tanpa ruang tidak punya tempat untuk
            // menempel. Daftarnya dikirim supaya halaman bisa mengatakan itu,
            // alih-alih menampilkan tabel kosong tanpa penjelasan.
            //
            // Dibandingkan terhadap `$semuaRuangan`, BUKAN `$ruangan` yang
            // sudah disaring: sesi yang belum punya peserta tetap punya ruang,
            // dan menyatakannya sebagai "tanpa ruang" adalah laporan yang salah.
            'sesi_tanpa_ruang' => $sesi
                ->reject(fn (ExamSession $s) => $semuaRuangan->contains('exam_session_id', $s->id))
                ->map(fn (ExamSession $s) => [
                    'id' => $s->id,
                    'label' => $s->label(),
                    'ujian' => $s->exam?->subject?->name ?? $s->exam?->title,
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * Tugaskan satu pengawas ke satu ruang-sesi.
     *
     * Bentrok ditolak di sini DAN dilaporkan di plate 16, dan itu disengaja:
     * KonflikJadwal menilai keadaan jadwal yang sudah tersimpan, sementara
     * pemeriksaan ini mencegah penugasan yang bentrok pernah tersimpan sama
     * sekali.
     */
    public function tugaskan(
        Request $request,
        SchoolEvent $event,
        ExamSessionRoom $ruang,
        PengawasService $service,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $event);
        $this->pastikanRuangMilikEvent($ruang, $event);

        $data = $request->validate([
            'supervisor_id' => ['required', 'integer'],
        ]);

        $pengawas = User::query()->find($data['supervisor_id']);

        if ($pengawas === null) {
            return back()->with('galat', 'Akun pengawas itu tidak ditemukan.');
        }

        try {
            $service->tugaskan($ruang, $pengawas, (int) $request->user()->id);
        } catch (ValidationException $e) {
            return back()->with('galat', $e->validator->errors()->first());
        } catch (DomainException $e) {
            return back()->with('galat', $e->getMessage());
        }

        $logger->log('Menugaskan pengawas', $event, [
            'ruang' => $ruang->eventRoom?->room?->code,
            'pengawas' => $pengawas->name,
        ]);

        return back()->with('sukses', "{$pengawas->name} ditugaskan di ruang itu.");
    }

    public function batalkan(
        SchoolEvent $event,
        SupervisionAssignment $tugas,
        PengawasService $service,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $event);
        $this->pastikanTugasMilikEvent($tugas, $event);

        $nama = $tugas->supervisor?->name ?? 'Pengawas';

        $service->batalkan($tugas);

        $logger->log('Membatalkan penugasan pengawas', $event, ['pengawas' => $nama]);

        return back()->with('sukses', "Penugasan {$nama} dibatalkan.");
    }

    /**
     * Tugaskan otomatis seluruh ruang yang belum cukup pengawas.
     *
     * Yang bentrok DILEWATI, bukan dijadikan galat: gunanya tombol ini
     * menyelesaikan pekerjaan yang membosankan, dan berhenti pada benturan
     * pertama berarti Admin tetap harus menugaskan sisanya satu per satu -
     * pekerjaan yang justru ingin ia hindari.
     */
    public function otomatis(
        Request $request,
        SchoolEvent $event,
        PengawasService $service,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $event);

        try {
            $hasil = $service->tugaskanOtomatis($event, (int) $request->user()->id);
        } catch (DomainException $e) {
            return back()->with('galat', $e->getMessage());
        }

        $logger->log('Menugaskan pengawas otomatis', $event, $hasil);

        if ($hasil['penugasan'] === 0 && $hasil['dilewati'] === 0) {
            return back()->with('sukses', 'Setiap ruang sudah punya pengawas yang cukup.');
        }

        $pesan = "{$hasil['penugasan']} penugasan dibuat di {$hasil['ruang']} ruang.";

        if ($hasil['dilewati'] > 0) {
            // Disebutkan, bukan disembunyikan: orang yang dilewati karena
            // bentrok meninggalkan ruang yang tetap kurang pengawas, dan Admin
            // perlu tahu bahwa sebagian pekerjaan belum selesai.
            return back()->with(
                'galat',
                $pesan." {$hasil['dilewati']} calon dilewati karena bentrok jadwal - tugaskan manual.",
            );
        }

        return back()->with('sukses', $pesan);
    }

    /* ------------------------------------------------------------ Pembantu */

    /**
     * Ruang-sesi harus benar-benar milik event di URL.
     *
     * Binding implisit menemukan id di seluruh sekolah, dan scope tenant tidak
     * menutupnya karena sekolahnya sama. Tanpa pemeriksaan ini, penugasan di
     * event B bisa dibuat lewat URL event A. 404, bukan 403: bagi event ini
     * baris itu tidak ada.
     */
    private function pastikanRuangMilikEvent(ExamSessionRoom $ruang, SchoolEvent $event): void
    {
        abort_unless($ruang->session?->exam?->event_id === $event->id, 404);
    }

    private function pastikanTugasMilikEvent(SupervisionAssignment $tugas, SchoolEvent $event): void
    {
        abort_unless($tugas->room?->session?->exam?->event_id === $event->id, 404);
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

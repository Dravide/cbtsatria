<?php

namespace App\Domain\Grading\Http\Controllers\Admin;

use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Models\ExamQuestion;
use App\Domain\Grading\Models\ExamResult;
use App\Domain\Grading\Services\PenghitungAnalisis;
use App\Domain\Grading\Services\PenghitungHasil;
use App\Domain\Grading\Services\PublikasiHasil;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Publikasi hasil - wireframe-admin.html plate 21.
 *
 * =====================================================================
 * SATU aksi untuk SELURUH event
 * =====================================================================
 * Kalimat plate 21 sendiri: "Publikasi adalah satu aksi untuk seluruh event,
 * bukan per ujian." Itulah sebabnya tabelnya per ujian (Admin perlu melihat
 * ujian mana yang menahan publikasi) sedangkan tombolnya satu - dan itu pula
 * sebabnya `exams.publish_results` dijatuhkan di `0008_01_01_000019`
 * ketimbang dibiarkan sebagai kolom kembar.
 *
 * Yang ditegakkan tombolnya bukan "semua ujian sudah dikoreksi", melainkan
 * "tidak ada uraian yang masih menunggu". Bedanya nyata: ujian yang seluruh
 * soalnya pilihan ganda selesai begitu attempt ditutup, dan menuntutnya
 * "dikoreksi" akan menahan publikasi selamanya.
 *
 * =====================================================================
 * Kunci koreksi diperiksa DUA kali, dan itu disengaja
 * =====================================================================
 * `PublikasiHasil::terbitkan()` menolak bila `sisaKoreksi() > 0`.
 * Laman ini membaca angka yang sama untuk menonaktifkan tombolnya. Bukan
 * duplikasi aturan: yang pertama adalah aturan yang ditegakkan, yang kedua
 * adalah penjelasan SEBELUM Admin menekan tombol - dan penjelasan yang
 * berbeda dari penegakannya akan membuat Admin menekan tombol yang tidak
 * bisa ia pahami kenapa mati.
 */
class HasilController
{
    public function __construct(
        private readonly PublikasiHasil $publikasi,
        private readonly PenghitungHasil $penghitung,
        private readonly PenghitungAnalisis $analisis,
    ) {}

    public function index(Request $request, SchoolEvent $event): Response
    {
        Gate::authorize('terbitkan', $event);

        $ujian = $event->exams()
            ->with('subject:id,name')
            ->orderBy('id')
            ->get()
            ->map(fn (Exam $satu) => $this->ringkasUjian($satu));

        $sisa = $this->publikasi->sisaKoreksi($event);

        return Inertia::render('Admin/Event/Hasil', [
            'event' => [
                'id' => $event->id,
                'nama' => $event->name,
                'kode' => $event->code,
                'status' => $event->status->value,
                'status_label' => $event->status->label(),
            ],
            'ujian' => $ujian->all(),
            'publikasi' => [
                // Ini yang mematikan tombolnya - dan sebabnya ikut dikirim,
                // supaya laman menulis "2 dari 12 ujian masih memiliki uraian
                // belum dinilai" alih-alih tombol mati tanpa kalimat.
                'terbit' => $event->hasilTerbit(),
                'terbit_pada' => $event->results_published_at?->toIso8601String(),
                'terbit_label' => $event->results_published_at?->format('d M Y H:i'),
                'diumumkan' => $event->results_announced_at !== null,
                'diumumkan_pada' => $event->results_announced_at?->toIso8601String(),
                'terkunci' => $event->koreksiTerkunci(),
                'sisa_koreksi' => $sisa,
                'boleh_terbit' => $sisa === 0,
                'ujian_tertahan' => $ujian->filter(fn (array $b) => $b['sisa_uraian'] > 0)->count(),
                'total_ujian' => $ujian->count(),
                'tampilkan_objektif' => (bool) $event->show_objectives,
                'tampilkan_pembahasan' => (bool) $event->show_explanations,
            ],
        ]);
    }

    /**
     * Terbitkan seluruh hasil event.
     */
    public function terbitkan(Request $request, SchoolEvent $event): RedirectResponse
    {
        Gate::authorize('terbitkan', $event);

        $this->publikasi->terbitkan($event, $request->user());

        return back()->with('sukses', 'Hasil event '.$event->name.' dipublikasikan. Siswa dapat melihat nilainya.');
    }

    /**
     * Tarik publikasi - hasil kembali tidak terlihat siswa.
     *
     * Alasan WAJIB. Yang membedakan "salah tekan" dari "nilai yang beredar
     * memang salah" hanya catatannya, dan yang dicabut adalah hasil yang
     * sudah dibaca seluruh angkatan.
     */
    public function tarik(Request $request, SchoolEvent $event): RedirectResponse
    {
        Gate::authorize('terbitkan', $event);

        $data = $request->validate([
            'alasan' => ['required', 'string', 'max:'.PublikasiHasil::BATAS_ALASAN],
        ]);

        $this->publikasi->tarik($event, $request->user(), $data['alasan']);

        return back()->with('sukses', 'Publikasi ditarik. Hasil tidak lagi terlihat siswa, dan koreksi terbuka kembali.');
    }

    /**
     * Buka kunci koreksi tanpa menarik publikasi - blueprint keputusan #10.
     */
    public function bukaKoreksi(Request $request, SchoolEvent $event): RedirectResponse
    {
        Gate::authorize('bukaKoreksi', $event);

        $data = $request->validate([
            'alasan' => ['required', 'string', 'max:'.PublikasiHasil::BATAS_ALASAN],
        ]);

        $this->publikasi->bukaKoreksi($event, $request->user(), $data['alasan']);

        return back()->with('sukses', 'Koreksi dibuka kembali. Alasan tercatat di log aktivitas.');
    }

    /**
     * Kunci ulang koreksi.
     */
    public function kunciKoreksi(Request $request, SchoolEvent $event): RedirectResponse
    {
        Gate::authorize('bukaKoreksi', $event);

        $this->publikasi->kunciKoreksi($event, $request->user());

        return back()->with('sukses', 'Koreksi dikunci. Guru tidak dapat mengubah nilai sampai dibuka kembali.');
    }

    /**
     * Kirim pengumuman ke siswa - tombol terpisah di plate 21.
     */
    public function umumkan(Request $request, SchoolEvent $event): RedirectResponse
    {
        Gate::authorize('terbitkan', $event);

        $this->publikasi->umumkan($event, $request->user());

        return back()->with('sukses', 'Pengumuman dikirim. Siswa melihat hasil ini pada laman Nilai saya.');
    }

    /**
     * Simpan dua kotak centang tampilan siswa.
     */
    public function aturTampilan(Request $request, SchoolEvent $event): RedirectResponse
    {
        Gate::authorize('terbitkan', $event);

        $data = $request->validate([
            'tampilkan_objektif' => ['required', 'boolean'],
            'tampilkan_pembahasan' => ['required', 'boolean'],
        ]);

        $this->publikasi->aturTampilan(
            $event,
            (bool) $data['tampilkan_objektif'],
            (bool) $data['tampilkan_pembahasan'],
        );

        return back()->with('sukses', 'Tampilan hasil untuk siswa tersimpan.');
    }

    /**
     * Hitung ulang seluruh hasil + analisis satu event.
     *
     * Ada karena satu sebab yang nyata: Admin memperbaiki kunci soal di
     * plate 15 sesudah ujiannya berjalan. Nilai yang sudah tersimpan dihitung
     * dari kunci LAMA, dan tanpa tombol ini satu-satunya jalan memperbaikinya
     * adalah menjalankan artisan dari server.
     */
    public function hitungUlang(Request $request, SchoolEvent $event): RedirectResponse
    {
        Gate::authorize('terbitkan', $event);

        $jumlah = 0;

        foreach ($event->exams()->pluck('id') as $examId) {
            $jumlah += $this->penghitung->hitungUlangUjian($examId);
            $this->analisis->hitungUlang($examId);
        }

        return back()->with('sukses', "Nilai {$jumlah} pengerjaan dihitung ulang, analisis butir diperbarui.");
    }

    /* ------------------------------------------------------------ Pembantu */

    /**
     * Satu baris tabel plate 21: ujian, peserta, objektif, uraian.
     *
     * @return array<string, mixed>
     */
    private function ringkasUjian(Exam $ujian): array
    {
        $attempts = ExamAttempt::query()
            ->whereHas('penempatan', fn ($q) => $q->where('exam_id', $ujian->id))
            ->whereIn('status', [
                ExamAttempt::STATUS_DIKIRIM,
                ExamAttempt::STATUS_KEDALUWARSA,
                ExamAttempt::STATUS_DIKUNCI,
            ])
            ->select('id');

        $result = ExamResult::query()->whereIn('exam_attempt_id', $attempts);

        $peserta = (clone $result)->count();
        $sisaUraian = (int) (clone $result)->sum('pending_essays');

        // Jawaban objektif dinilai seketika saat attempt ditutup; jawaban
        // uraian menunggu Guru. Angka yang ditampilkan plate 21 adalah
        // "278/278" dan "112/278" - sudah dibanding total.
        $totalUraian = (int) ExamQuestion::query()
            ->where('exam_id', $ujian->id)
            ->where('jenis', 'uraian')
            ->count() * $peserta;

        return [
            'id' => $ujian->id,
            'judul' => $ujian->title,
            'kode' => $ujian->code,
            'mapel' => $ujian->subject?->name,
            'peserta' => $peserta,
            'objektif' => $peserta,
            'uraian_dinilai' => max(0, $totalUraian - $sisaUraian),
            'uraian_total' => $totalUraian,
            'sisa_uraian' => $sisaUraian,
        ];
    }
}

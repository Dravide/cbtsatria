<?php

namespace App\Domain\Attendance\Http\Controllers\Siswa;

use App\Domain\Attendance\Services\JendelaLuang;
use App\Domain\Attendance\Services\PapanPeringkatLuang;
use App\Domain\Event\Enums\EventStatus;
use App\Domain\Exam\Models\ExamParticipant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Permainan waktu luang - wireframe-waktu-luang.html plate 02, 03, 05, 06.
 *
 * =====================================================================
 * Tiga alamat, dan satu PINTU yang sama
 * =====================================================================
 *   GET  /siswa/luang            panggung + rekor saya
 *   GET  /siswa/luang/peringkat  papan peringkat kelas
 *   POST /siswa/luang/skor       kiriman skor (JSON)
 *
 * Ketiganya melewati pemeriksaan yang sama, dan pemeriksaan itu BUKAN
 * pemeriksaan izin - izinnya sudah dijawab middleware `siswa`. Yang diperiksa
 * adalah apakah JENDELANYA masih terbuka. Anak yang membuka `/siswa/luang`
 * dari tautan yang disimpan di riwayat peramban, satu jam setelah ujian
 * berikutnya dimulai, harus menerima jawaban yang jelas - bukan panggung yang
 * tetap bisa dimainkan karena laman itu sudah dimuat sebelumnya.
 *
 * =====================================================================
 * Kiriman yang datang setelah jendela tutup TETAP DITOLAK
 * =====================================================================
 * `kirim()` memeriksa jendelanya ULANG, dan itu bukan kerapian. Seluruh
 * aturan "permainan menutup dirinya saat sesi berikutnya dibuka" bergantung
 * pada satu kenyataan: jendela yang diperiksa peramban hanyalah tampilan.
 * Peramban yang menahan tabnya tetap terbuka - atau yang menyalin permintaan
 * POST-nya - harus ditolak server, dan jawabannya menyebut sebabnya.
 */
class LuangSiswaController extends SiswaDasar
{
    public function __construct(
        private readonly JendelaLuang $jendela,
        private readonly PapanPeringkatLuang $papan,
    ) {}

    public function tampilkan(Request $request): Response|RedirectResponse
    {
        $siswa = $request->user();
        $penempatan = $this->penempatan($siswa->id);

        $jendela = $this->jendela->untukSiswa($penempatan, now());

        // Tanpa jeda, lamannya TIDAK dirender sama sekali. Menggambar
        // panggung yang menolak dimulai adalah laman yang mengundang lalu
        // membantah - dan anak yang melihatnya sekali akan mengira
        // permainannya rusak, bukan bahwa jedanya sudah habis.
        if ($jendela === null) {
            return redirect()->route('siswa.dashboard')
                ->with('galat', 'Tidak ada waktu luang sekarang. Permainan terbuka setelah ujian Anda dikumpulkan dan sebelum sesi berikutnya dibuka.');
        }

        $kelasId = $penempatan->first(fn (ExamParticipant $p) => $p->pesertaEvent?->class_id !== null)
            ?->pesertaEvent?->class_id;

        return Inertia::render('Siswa/Luang', [
            'jendela' => $jendela,
            'rekor' => $this->papan->rekorSaya($siswa),
            'peringkat' => $this->papan->peringkatSaya($siswa, $kelasId),
            'kelas' => $penempatan->first()?->pesertaEvent?->kelas?->name,
        ]);
    }

    public function peringkat(Request $request): Response|RedirectResponse
    {
        $siswa = $request->user();
        $penempatan = $this->penempatan($siswa->id);

        if ($this->jendela->untukSiswa($penempatan, now()) === null) {
            return redirect()->route('siswa.dashboard')
                ->with('galat', 'Papan peringkat hanya terbuka selama waktu luang.');
        }

        $kelasId = $penempatan->first(fn (ExamParticipant $p) => $p->pesertaEvent?->class_id !== null)
            ?->pesertaEvent?->class_id;

        $ringkas = $this->papan->peringkatSaya($siswa, $kelasId);

        return Inertia::render('Siswa/LuangPeringkat', [
            'kelas' => $penempatan->first()?->pesertaEvent?->kelas?->name,
            'papan' => $this->papan->papan($siswa->school_id, $kelasId),
            'ringkas' => $ringkas,
            // Baris pemain sendiri selalu terlihat - bila ia di luar sepuluh
            // teratas, barisnya ditempel di bawah daftar. Papan yang membuat
            // pemain tidak menemukan namanya sendiri berhenti dibuka.
            'saya' => [
                'student_id' => $siswa->id,
                'nama' => $siswa->name,
                'peringkat' => $ringkas['peringkat'],
                'score' => $ringkas['rekor'],
            ],
        ]);
    }

    /**
     * Satu kiriman skor.
     *
     * ---------------------------------------------------------------------
     * Yang divalidasi adalah BENTUKNYA; yang memutuskan adalah penjaganya
     * ---------------------------------------------------------------------
     * `distance` dan `duration_ms` harus bilangan positif yang masuk akal
     * sebagai bilangan (batas atasnya menjaga `unsignedInteger` di basis
     * data). Apakah angkanya MUNGKIN berasal dari permainan sungguhan
     * dijawab `PenjagaSkorLuar`, dan kiriman yang mustahil tetap disimpan
     * sebagai baris ditolak - dengan jawaban 200, bukan 422.
     *
     * 422 akan membuat `useForm` di peramban menampilkan galat validasi yang
     * tidak bisa diperbaiki anak mana pun, karena yang salah bukan
     * kirimannya.
     */
    public function kirim(Request $request): JsonResponse
    {
        $data = $request->validate([
            'distance' => ['required', 'integer', 'min:0', 'max:4000000'],
            'duration_ms' => ['required', 'integer', 'min:0', 'max:3600000'],
        ]);

        $siswa = $request->user();
        $penempatan = $this->penempatan($siswa->id);

        $jendela = $this->jendela->untukSiswa($penempatan, now());

        if ($jendela === null) {
            // 409, bukan 403: anaknya sah, permainannya sah, yang habis
            // adalah waktunya - dan panggungnya menanggapi kode ini dengan
            // menutup diri (plate 06), bukan dengan menampilkan galat.
            return response()->json([
                'pesan' => 'Waktu luangnya sudah habis. Skor ini tidak dicatat.',
            ], 409);
        }

        // Penempatan yang dipakai sebagai jejak: yang pertama, dan yang
        // memang punya sesi. Skornya milik ANAK, bukan milik sesi - lihat
        // catatan migrasi.
        $peserta = $penempatan->first(fn (ExamParticipant $p) => $p->exam_session_id !== null)
            ?? $penempatan->first();

        if ($peserta === null) {
            return response()->json(['pesan' => 'Anda belum ditempatkan pada ujian mana pun.'], 409);
        }

        $skor = $this->papan->catat($siswa, $peserta, (int) $data['distance'], (int) $data['duration_ms']);

        $ringkas = $this->papan->peringkatSaya($siswa, $peserta->pesertaEvent?->class_id);

        return response()->json([
            'diterima' => $skor->diterima(),
            'score' => $skor->score,
            'rekor' => $ringkas['rekor'],
            'peringkat' => $ringkas['peringkat'],
            'pemain' => $ringkas['pemain'],
            'pesan' => $skor->diterima()
                ? 'Skor tersimpan.'
                : 'Skor ditolak: angkanya tidak mungkin berasal dari permainan ini.',
        ]);
    }

    /**
     * Penempatan milik siswa ini, lengkap dengan sesi dan kelasnya.
     *
     * Disaring dua kali dan itu disengaja: `student_id` menjawab "milik
     * siapa", `EventStatus::tampilKeSiswa()` menjawab "masih relevan atau
     * tidak". Tanpa yang kedua, anak yang eventnya sudah diarsipkan masih
     * bisa membuka permainan dari event tahun lalu - dan jeda setahun lalu
     * bukan jeda.
     *
     * @return Collection<int, ExamParticipant>
     */
    private function penempatan(int $studentId): Collection
    {
        return ExamParticipant::query()
            ->where('student_id', $studentId)
            ->whereHas('exam.event', fn ($q) => $q->whereIn(
                'status',
                array_map(fn (EventStatus $s) => $s->value, EventStatus::tampilKeSiswa()),
            ))
            ->with([
                'session',
                'exam.subject:id,name',
                'pesertaEvent.kelas:id,name',
            ])
            ->get();
    }
}

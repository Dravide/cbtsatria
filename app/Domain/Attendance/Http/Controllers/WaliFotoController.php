<?php

namespace App\Domain\Attendance\Http\Controllers;

use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Attendance\Models\SessionReport;
use App\Domain\Attendance\Models\SessionReportFile;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Tenancy\Models\School;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Foto berita acara untuk laman wali - `/wali/{slug}/foto/{berkas}`.
 *
 * =====================================================================
 * Mengapa rute TERSENDIRI, bukan alamat di props Inertia
 * =====================================================================
 * Berkas berita acara tinggal di disk `local` dan keluar HANYA lewat
 * controller yang memeriksa hak baca. `BerkasLaporanController` memeriksa
 * apakah pemanggilnya berhak atas ruang-sesi itu - dan di laman wali,
 * pemanggilnya tidak punya akun sama sekali. Memakai jalur itu berarti
 * membuka seluruh berkas berita acara, termasuk tanda tangan dan catatan
 * pengawas.
 *
 * Mengirim URL foto di props akan lebih buruk lagi: rute foto tidak akan
 * tahu kode apa pun, dan siapa pun yang menebak id berkas membuka wajah
 * tiga puluh anak.
 *
 * Karena itu rute ini memeriksa SEMUANYA sendiri, berurutan, dan yang
 * pertama adalah kode kelas - karena itu satu-satunya yang membedakan
 * orang tua dari orang yang menemukan tautannya.
 *
 * =====================================================================
 * Lima pemeriksaan, dan urutannya menentukan
 * =====================================================================
 *   1. kode kelas sudah benar di sesi peramban ini;
 *   2. kelas itu menyalakan foto, dan sekolahnya mengizinkan
 *      (DUA saklar - lihat migrasi `0035`);
 *   3. laporannya `final` - draf masih bisa berubah;
 *   4. sisi foto `sesudah` - foto ruang kosong tidak menjawab pertanyaan
 *      orang tua, dan `sebelum` adalah data lama;
 *   5. `mime` benar-benar `image/*` - tabel yang sama menyimpan berkas
 *      pendukung lain, dan laman ini tidak boleh menjadi jalan keluarnya.
 */
class WaliFotoController
{
    /** Nama kunci sesi yang sama dengan `WaliPublikController`. */
    private const SESI_KODE = 'wali_terbuka';

    public function tampilkan(string $slug, int $berkas): StreamedResponse
    {
        $sekolah = $this->sekolah($slug);

        $file = SessionReportFile::query()
            ->withoutGlobalScopes()
            ->with(['laporan:id,exam_session_room_id,status,school_id'])
            ->where('school_id', $sekolah->id)
            ->whereKey($berkas)
            ->first();

        if ($file === null) {
            abort(404);
        }

        $laporan = $file->laporan;

        if ($laporan === null || $laporan->status !== SessionReport::STATUS_FINAL) {
            abort(404);
        }

        // Berkas ini milik satu ruang-sesi. Kelas yang berhak membacanya
        // adalah kelas yang punya peserta DI ruang-sesi itu - bukan
        // sembarang kelas di sekolah yang sama.
        $kelas = $this->kelasTerbukaDari($sekolah, (int) $laporan->exam_session_room_id);

        if ($kelas === null) {
            abort(404);
        }

        // 2 - saklar sekolah (saklar kelas sudah disaring di kelasTerbukaDari).
        if (! (bool) $sekolah->wali_foto_boleh) {
            abort(404);
        }

        // 4 & 5 - sisi dan jenis
        if ($file->sisi?->value !== 'sesudah' || ! str_starts_with((string) $file->mime, 'image/')) {
            abort(404);
        }

        if ($file->path === null || ! Storage::disk('local')->exists($file->path)) {
            abort(404);
        }

        return Storage::disk('local')->response(
            $file->path,
            'berita-acara-'.$file->id.'.'.pathinfo($file->path, PATHINFO_EXTENSION),
            // `inline`, bukan `attachment`: fotonya ditampilkan di laman,
            // dan unduhan paksa membuat orang tua menyimpan berkas yang
            // memuat wajah anak lain.
            ['Content-Type' => $file->mime, 'Content-Disposition' => 'inline'],
        );
    }

    /**
     * Kelas yang punya peserta di ruang-sesi ini dan sudah dibuka di sesi peramban.
     *
     * Satu ruang ujian dapat berisi peserta dari lebih dari satu kelas,
     * sehingga seluruh kelas di ruang itu diperiksa terhadap sesi peramban.
     */
    private function kelasTerbukaDari(School $sekolah, int $ruangSesi): ?SchoolClass
    {
        $kelasIds = ExamParticipant::query()
            ->withoutGlobalScopes()
            ->where('school_id', $sekolah->id)
            ->where('exam_session_room_id', $ruangSesi)
            ->with('pesertaEvent:id,class_id')
            ->get()
            ->map(fn (ExamParticipant $p) => (int) ($p->pesertaEvent?->class_id ?? 0))
            ->filter()
            ->unique()
            ->values();

        if ($kelasIds->isEmpty()) {
            return null;
        }

        return SchoolClass::query()
            ->withoutGlobalScopes()
            ->where('school_id', $sekolah->id)
            ->whereNotNull('wali_slug')
            ->where('wali_foto', true)
            ->whereIn('id', $kelasIds)
            ->get()
            ->first(fn (SchoolClass $kelas) => $this->terbuka($kelas->id));
    }

    private function terbuka(int $kelas): bool
    {
        $sampai = Session::get(self::SESI_KODE.'.'.$kelas);

        return $sampai !== null && now()->getTimestamp() < (int) $sampai;
    }

    private function sekolah(string $slug): School
    {
        $sekolah = School::query()->where('papan_slug', $slug)->first();

        if ($sekolah === null) {
            abort(404);
        }

        return $sekolah;
    }
}

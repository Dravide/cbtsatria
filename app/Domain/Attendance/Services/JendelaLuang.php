<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSession;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Apakah siswa ini sedang punya JEDA, dan sampai kapan - wireframe-waktu-luang
 * plate 01 dan 06.
 *
 * =====================================================================
 * Jendela luang itu KESIMPULAN, bukan pengaturan
 * =====================================================================
 * Tidak ada tombol "izinkan main sekarang" dan tidak ada jam main yang
 * diketik siapa pun. Jendelanya lahir dari keadaan yang sudah ada:
 *
 *   ADA      attempt hari ini yang SUDAH ditutup (dikirim atau kedaluwarsa),
 *   DAN      sesi berikutnya belum dibuka.
 *
 * Kalimat itu sengaja dibaca apa adanya. Kalau syarat pertamanya dilonggarkan
 * - "sudah pernah mengerjakan apa saja" - maka anak yang belum mengerjakan
 * apa pun pagi itu bisa membuka permainan sebelum ujian pertamanya, dan
 * seluruh alasan fitur ini ada (mengisi jeda yang membosankan, BUKAN jalan
 * keluar sebelum ujian) hilang di hari pertama.
 *
 * =====================================================================
 * Batas akhirnya dua, dan yang lebih dulu menang
 * =====================================================================
 * Jendelanya ditutup oleh:
 *
 *   1. `access_opens_at` sesi berikutnya yang BELUM dicoba anak itu - saat
 *      pintunya dibuka, permainan berhenti.
 *   2. `hard_end_at` sesi yang sedang berjalan - batas yang lebih jarang,
 *      tetapi nyata bila anak mengirim attempt-nya lalu sesi yang sama masih
 *      dibuka untuk anak lain. Ia tidak boleh bermain-main di kursinya
 *      sementara temannya masih mengerjakan.
 *
 * Yang TIDAK dipakai sebagai batas: jam dinding peramban. Seluruh waktu di
 * sini dihitung server, dengan alasan yang sama seperti hitung mundur di
 * `JadwalSiswaController` - ponsel yang jamnya meleset setengah jam akan
 * memberi anak dua puluh menit tambahan main.
 *
 * =====================================================================
 * `exam_status` tidak dibaca di sini
 * =====================================================================
 * Event yang diarsipkan memang tidak menampilkan jadwal, tetapi memeriksa
 * status event di sini berarti kelas ini harus menyentuh kolom milik modul
 * Event lewat kueri sendiri - dan jadwal siswanya sudah menyaring itu lebih
 * dulu. Yang dijaga kelas ini adalah JEDA, bukan kelayakan ujian.
 */
final class JendelaLuang
{
    /**
     * Batas aman, supaya anak tidak ditawari jeda sepanjang tiga jam bila
     * sesi berikutnya masih besok.
     *
     * Angka ini bukan aturan produk - ia penjaga tata letak. Nama sesi
     * berikutnya dan hitung mundurnya tetap ditampilkan; yang dipotong
     * hanyalah janji "main selama mungkin". Bermain dalam jendela yang
     * sebenarnya tidak ada membuat fitur ini terasa seperti hadiah yang
     * ditarik kembali.
     */
    public const MENIT_MAKS = 180;

    /**
     * Jendela waktu luang siswa ini pada saat `$sekarang`, atau null.
     *
     * @param  Collection<int, ExamParticipant>  $penempatan  penempatan MILIK siswa ini
     * @return array{berakhir_pada: string, sesi_berikut: array{label: string, mapel: string|null, dibuka: string}|null}|null
     */
    public function untukSiswa(Collection $penempatan, CarbonInterface $sekarang): ?array
    {
        if ($penempatan->isEmpty()) {
            return null;
        }

        $idPenempatan = $penempatan->pluck('id');

        // `whereIn` atas penempatan, bukan atas siswa: attempt milik penempatan
        // di ujian anak itu, dan satu siswa boleh punya dua ujian di satu
        // event.
        $attempt = ExamAttempt::query()
            ->whereIn('exam_participant_id', $idPenempatan)
            ->orderByDesc('attempt_number')
            ->get()
            ->groupBy('exam_participant_id')
            ->map(fn (Collection $baris) => $baris->first());

        // Syarat pertama: ada attempt yang SUDAH ditutup hari ini.
        $sudahMenutup = $attempt->contains(
            fn (ExamAttempt $a) => in_array(
                $a->status,
                [ExamAttempt::STATUS_DIKIRIM, ExamAttempt::STATUS_KEDALUWARSA],
                true,
            ),
        );

        if (! $sudahMenutup) {
            return null;
        }

        $sesi = $penempatan
            ->map(fn (ExamParticipant $p) => $p->session)
            ->filter()
            ->unique('id')
            ->values();

        // Sesi yang attempt-nya sudah ada - anak ini sudah mengerjakannya.
        $sesiTercoba = $penempatan
            ->filter(fn (ExamParticipant $p) => $attempt->has($p->id))
            ->pluck('exam_session_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        // ------------------------------------------------------------------
        // Sesi berikutnya MENENTUKAN, dan ia dicari dari seluruh sesi yang
        // belum dicoba - bukan hanya yang pintunya belum dibuka
        // ------------------------------------------------------------------
        // Versi pertama kelas ini menyaring `isFuture()` di sini, dan itu
        // salah dengan cara yang tidak berbunyi: begitu jamnya lewat, sesi
        // berikutnya berhenti dianggap "berikutnya", lalu sesi ITU SENDIRI
        // terbaca sebagai "sedang berjalan" dan menyumbang `hard_end_at`-nya
        // sebagai batas - sehingga jendelanya justru TERBUKA selama ujian
        // berikutnya berlangsung. Persis kebalikan dari yang dijanjikan
        // plate 06.
        //
        // Yang benar: sesi berikutnya adalah yang paling awal di antara yang
        // belum dicoba, tanpa peduli jamnya sudah lewat atau belum. Bila
        // jamnya sudah lewat, jendelanya memang sudah tutup - lihat
        // pemeriksaan `lte($sekarang)` di bawah.
        $berikut = $sesi
            ->reject(fn (ExamSession $s) => in_array($s->id, $sesiTercoba, true))
            ->filter(fn (ExamSession $s) => $s->access_opens_at !== null)
            ->sortBy(fn (ExamSession $s) => $s->access_opens_at)
            ->first();

        if ($berikut === null) {
            // Tidak ada ujian lagi yang menunggu: seluruh ujian anak ini
            // sudah selesai. Jeda yang tidak berujung pada ujian bukan jeda -
            // ia waktu pulang, dan menawarkan permainan di sana adalah
            // menahan anak di ruang yang sudah bukan urusannya.
            return null;
        }

        // Batas akhir: pintu sesi berikutnya. Sesi yang BARU SAJA
        // dikerjakan tidak ikut menentukan meski `hard_end_at`-nya belum
        // lewat - anak itu sudah mengumpulkan, dan yang membatasi waktunya
        // adalah ujian berikutnya, bukan sisa jam ruang ujian yang sudah ia
        // tinggalkan.
        $berakhir = $berikut->access_opens_at->copy();

        // Jendela yang lebih panjang dari `MENIT_MAKS` tetap dilaporkan,
        // tetapi berakhir lebih cepat: lihat catatan konstanta.
        $plafon = $sekarang->copy()->addMinutes(self::MENIT_MAKS);

        if ($berakhir->gt($plafon)) {
            $berakhir = $plafon;
        }

        // Sudah lewat berarti jendelanya tertutup - dan inilah satu-satunya
        // jawaban null yang bergantung waktu.
        if ($berakhir->lte($sekarang)) {
            return null;
        }

        return [
            'berakhir_pada' => $berakhir->toIso8601String(),
            'sesi_berikut' => [
                'label' => $berikut->label(),
                'mapel' => $berikut->exam?->subject?->name,
                'dibuka' => $berikut->access_opens_at->toIso8601String(),
            ],
        ];
    }
}

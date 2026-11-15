<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSession;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Apakah siswa ini boleh memainkan permainan waktu luang, dan sampai kapan -
 * wireframe-waktu-luang.html.
 *
 * =====================================================================
 * Aturannya DILONGGARKAN 2026-11-15: satu syarat, bukan dua
 * =====================================================================
 * Versi pertama kelas ini menuntut DUA hal sekaligus:
 *
 *   ada attempt yang sudah ditutup  DAN  ada sesi berikutnya yang belum
 *   dibuka.
 *
 * Aturan itu benar menurut desainnya sendiri, dan salah menurut pemakaiannya.
 * Ia membuat ikon permainan tidak muncul untuk dua keadaan yang paling sering
 * dialami siswa:
 *
 *   - siswa yang tidak punya ujian sama sekali (kelas lain, hari lain, atau
 *     siswa yang penempatannya belum dibuat), dan
 *   - siswa yang baru saja mengumpulkan ujian TERAKHIRNYA.
 *
 * Pada keduanya, pintu yang seharusnya menjadi jeda justru tertutup - dan
 * tidak ada satu pun kalimat di layar yang menjelaskan mengapa.
 *
 * Aturan yang berlaku sekarang hanya SATU, dan ia berupa larangan:
 *
 *   TIDAK BOLEH ada attempt yang sedang berjalan.
 *
 * Selebihnya bebas. Alasannya tetap sama dengan versi pertama, hanya dibaca
 * dari sisi sebaliknya: yang berbahaya bukan "bermain sebelum ujian pertama",
 * melainkan "bermain DI TENGAH ujian" - dan itu dilarang oleh attempt, bukan
 * oleh ketiadaan attempt. Siswa yang belum mulai mengerjakan apa pun memang
 * sedang menunggu; menahannya dari permainan tidak membuatnya mengerjakan
 * ujian lebih cepat.
 *
 * ---------------------------------------------------------------------
 * Konsekuensi yang disengaja: `sekarang` tidak lagi menentukan boleh/tidak
 * ---------------------------------------------------------------------
 * Karena syaratnya kini hanya "tidak sedang ujian", jendelanya tidak pernah
 * tertutup oleh jam. `berakhir_pada` tetap dikirim, tetapi artinya berubah:
 * ia bukan "sampai kapan kamu boleh main", melainkan "kapan ujianmu berikutnya
 * dibuka, kalau ada". Panggungnya memakainya sebagai hitung mundur, bukan
 * sebagai tenggat.
 *
 * Satu-satunya yang masih bergantung waktu adalah `hard_end_at` sesi yang
 * sedang dipakai - dan itu tetap dikirim, karena sesi yang masih terbuka
 * untuk anak lain memang batas yang nyata: ia tidak boleh bermain-main di
 * kursinya sementara temannya masih mengerjakan.
 *
 * =====================================================================
 * Kuota harian BUKAN urusan kelas ini
 * =====================================================================
 * Batas sepuluh permainan per hari diperiksa dan dicatat oleh
 * `KuotaPermainan`, karena ia menyangkut JUMLAH yang sudah dimainkan - bukan
 * keadaan jadwal. Memasukkannya ke sini berarti kelas ini membaca tabel skor,
 * dan batas yang hidup di dua tempat adalah batas yang suatu hari berbeda.
 */
final class JendelaLuang
{
    /**
     * Status attempt yang berarti anak itu MASIH mengerjakan.
     *
     * `dikunci` ikut di sini: ujian yang terkunci bukan ujian yang selesai.
     * Anaknya masih punya laman terkunci yang bisa ia buka kembali lewat
     * pengawas, dan membiarkannya bermain sambil menunggu adalah satu-satunya
     * keadaan yang benar-benar ingin dicegah aturan ini.
     */
    private const STATUS_MENGERJAKAN = [
        ExamAttempt::STATUS_BERJALAN,
        ExamAttempt::STATUS_DIKUNCI,
    ];

    /**
     * Jendela permainan siswa ini, atau null bila ia sedang ujian.
     *
     * @param  Collection<int, ExamParticipant>  $penempatan  penempatan MILIK siswa ini
     * @return array{berakhir_pada: ?string, sesi_berikut: array{label: string, mapel: string|null, dibuka: string}|null}|null
     */
    public function untukSiswa(Collection $penempatan, CarbonInterface $sekarang): ?array
    {
        // ------------------------------------------------------------------
        // Satu-satunya larangan: attempt yang belum ditutup
        // ------------------------------------------------------------------
        // Diperiksa untuk SELURUH penempatan anak ini, bukan per sesi: satu
        // ujian yang sedang dikerjakan sudah cukup untuk menutup pintunya,
        // dan anak yang punya dua ujian di hari yang sama tidak boleh bermain
        // karena ujian keduanya kebetulan belum dimulai.
        $idPenempatan = $penempatan->pluck('id');

        if ($idPenempatan->isNotEmpty() && ExamAttempt::query()
            ->whereIn('exam_participant_id', $idPenempatan)
            ->whereIn('status', self::STATUS_MENGERJAKAN)
            ->exists()) {
            return null;
        }

        // ------------------------------------------------------------------
        // Sesi yang masih terbuka - satu-satunya batas yang tersisa
        // ------------------------------------------------------------------
        // `hard_end_at` sesi yang ruangnya masih dibuka. Bila ada, permainan
        // berhenti di situ: anak yang sudah mengumpulkan tidak boleh bermain
        // di kursinya sementara teman sebelahnya masih mengerjakan.
        $sesi = $penempatan
            ->map(fn (ExamParticipant $p) => $p->session)
            ->filter()
            ->unique('id')
            ->values();

        $masihTerbuka = $sesi
            ->filter(fn (ExamSession $s) => $s->hard_end_at !== null && $s->hard_end_at->gt($sekarang))
            ->sortBy(fn (ExamSession $s) => $s->hard_end_at)
            ->first();

        // ------------------------------------------------------------------
        // Ujian berikutnya, untuk hitung mundur di layar
        // ------------------------------------------------------------------
        // Hanya yang belum dicoba dan pintunya belum dibuka. `null` berarti
        // anak ini tidak punya ujian lagi - dan itu BUKAN alasan menutup
        // pintunya, hanya alasan layar tidak menyebut nama mapel apa pun.
        $sesiTercoba = $this->sesiSudahDicoba($penempatan, $sekarang);

        $berikut = $sesi
            ->filter(fn (ExamSession $s) => $s->access_opens_at !== null
                && $sekarang->lt($s->access_opens_at)
                && ! in_array($s->id, $sesiTercoba, true))
            ->sortBy(fn (ExamSession $s) => $s->access_opens_at)
            ->first();

        return [
            // Deadline sesi yang masih terbuka, atau null bila tidak ada.
            // Panggungnya memakai ini sebagai batas keras, dan `null` berarti
            // "main sampai kuota harianmu habis".
            'berakhir_pada' => $masihTerbuka?->hard_end_at?->toIso8601String(),

            'sesi_berikut' => $berikut === null ? null : [
                'label' => $berikut->label(),
                'mapel' => $berikut->exam?->subject?->name,
                'dibuka' => $berikut->access_opens_at->toIso8601String(),
            ],
        ];
    }

    /**
     * Id sesi yang attempt-nya sudah ada - anak ini sudah mengerjakannya.
     *
     * @param  Collection<int, ExamParticipant>  $penempatan
     * @return array<int, int>
     */
    private function sesiSudahDicoba(Collection $penempatan, CarbonInterface $sekarang): array
    {
        $idPenempatan = $penempatan->pluck('id');

        if ($idPenempatan->isEmpty()) {
            return [];
        }

        // Attempt apa pun yang sudah ada berarti sesi itu sudah disentuh -
        // termasuk yang `batal`, karena percobaan yang dibatalkan bukan
        // percobaan yang belum terjadi.
        $sudahDikerjakan = ExamAttempt::query()
            ->whereIn('exam_participant_id', $idPenempatan)
            ->pluck('exam_participant_id');

        return $penempatan
            ->filter(fn (ExamParticipant $p) => $sudahDikerjakan->contains($p->id))
            ->pluck('exam_session_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}

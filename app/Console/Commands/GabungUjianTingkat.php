<?php

namespace App\Console\Commands;

use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Models\Exam;
use App\Domain\Exam\Models\ExamClass;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Exam\Models\SupervisionAssignment;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Gabungkan beberapa ujian satu mapel menjadi SATU ujian lintas tingkat.
 *
 * =====================================================================
 * Masalah yang diselesaikan
 * =====================================================================
 * Sekolah menyusun satu ujian per tingkat: "Pendidikan Agama #7" dengan
 * kelas 7 saja, "#8" dengan kelas 8 saja. Akibatnya tiap tingkat membawa
 * 33 ruang sendiri, dan laman pengawas menampilkan 33 baris per tingkat -
 * tiga kali lebih banyak daripada yang sebenarnya diperlukan.
 *
 * Yang benar: SATU ujian per mapel, dengan kelas sasaran LINTAS TINGKAT.
 * Ujian itu tetap punya 33 ruang, karena ruang bukan milik tingkat - ruang
 * milik event, dan siswa tingkat berbeda boleh duduk di ruang yang sama pada
 * jam yang sama (lihat aturan benturan per tingkat di KonflikJadwal).
 *
 * =====================================================================
 * Yang DILARANG digabung, dan mengapa perintah ini berhenti
 * =====================================================================
 * Penggabungan hanya memindahkan STRUKTUR - peserta, sesi, ruang, penugasan.
 * Ia tidak bisa memindahkan hasil pekerjaan siswa: attempt, jawaban, dan
 * nilai menempel pada ujian asalnya lewat `exam_questions`, dan
 * menggabungkannya berarti dua snapshot soal yang berbeda diakui sebagai
 * satu. Karena itu perintah ini MENOLAK bila salah satu ujian sudah punya
 * attempt - dan menyebut kode ujian yang menahannya.
 *
 * Ujian yang hanya punya peserta dan jadwal (belum pernah dijalankan) aman
 * digabung: yang berpindah adalah baris-baris itu, dan tidak ada satu pun
 * jawaban yang bisa hilang.
 *
 * =====================================================================
 * `--kering` adalah bawaan, dan itu disengaja
 * =====================================================================
 * Perintah yang menghapus baris ujian tanpa memperlihatkan apa yang akan
 * terjadi lebih dulu adalah perintah yang dijalankan orang yang panik pada
 * malam sebelum ujian. `--tulis` harus disebut eksplisit, dan `--kering`
 * mencetak seluruh rencananya.
 */
class GabungUjianTingkat extends Command
{
    protected $signature = 'ujian:gabung-tingkat
                            {event : Id event}
                            {--mapel= : Nama mapel yang digabung; wajib bila event punya lebih dari satu kelompok}
                            {--nama= : Judul ujian hasil gabungan (opsional)}
                            {--tulis : Jalankan sungguhan. Tanpa ini, hanya melaporkan rencananya}';

    protected $description = 'Gabungkan ujian satu mapel lintas tingkat menjadi satu ujian, beserta sesi, ruang, peserta, dan penugasannya';

    public function handle(): int
    {
        $event = SchoolEvent::query()->find((int) $this->argument('event'));

        if ($event === null) {
            $this->error('Event tidak ditemukan.');

            return self::FAILURE;
        }

        $cariMapel = $this->option('mapel');

        // ------------------------------------------------------------------
        // Dikelompokkan menurut NAMA mapel, bukan `subject_id`
        // ------------------------------------------------------------------
        // Sekolah yang menyusun satu mapel per tingkat punya BEBERAPA baris
        // `subjects` dengan nama sama dan kode berbeda: "PABK-7" dan "PABK-8"
        // keduanya bernama "Pendidikan Agama dan Budi Pekerti". Mengelompokkan
        // menurut `subject_id` karena itu tidak akan pernah menemukan apa pun
        // untuk digabung - dan itulah yang membuat perintah ini pertama kali
        // menjawab "tidak ada yang perlu digabung" pada event yang jelas punya
        // dua ujian mapel sama.
        $semuaUjian = Exam::query()
            ->where('event_id', $event->id)
            ->with('subject:id,name,code')
            ->orderBy('id')
            ->get();

        $kelompok = $semuaUjian->groupBy(
            fn (Exam $u) => mb_strtolower(trim((string) ($u->subject?->name ?? $u->title))),
        );

        $kandidat = $kelompok->filter(fn ($daftar) => $daftar->count() > 1);

        if ($kandidat->isEmpty()) {
            $this->info('Tidak ada mapel yang punya lebih dari satu ujian. Tidak ada yang perlu digabung.');
            $this->daftarUjian($semuaUjian);

            return self::SUCCESS;
        }

        if ($cariMapel !== null) {
            $cocok = $kandidat->filter(
                fn ($daftar, string $nama) => str_contains($nama, mb_strtolower(trim($cariMapel))),
            );

            if ($cocok->isEmpty()) {
                $this->error("Tidak ada ujian bernama mapel \"{$cariMapel}\" yang punya lebih dari satu baris.");
                $this->daftarUjian($kandidat->flatten(1));

                return self::FAILURE;
            }

            $kandidat = $cocok;
        }

        if ($kandidat->count() > 1) {
            $this->error('Event ini punya lebih dari satu kelompok yang bisa digabung. Sebutkan --mapel=.');
            $this->daftarUjian($kandidat->flatten(1));

            return self::FAILURE;
        }

        $daftar = $kandidat->first()->values();

        $this->line("Event: {$event->code} {$event->name}");
        $this->line('Mapel: '.($daftar->first()->subject?->name ?? '—'));
        $this->newLine();
        $this->daftarUjian($daftar);

        // ---------------------------------------------------------------
        // Penjaga: ujian yang sudah dijalankan TIDAK boleh digabung
        // ---------------------------------------------------------------
        $terkunci = $this->ujianYangSudahBerjalan($daftar);

        if ($terkunci !== []) {
            $this->newLine();
            $this->error('Tidak bisa digabung: ujian berikut sudah punya pengerjaan siswa.');

            foreach ($terkunci as $baris) {
                $this->line("  {$baris['kode']} — {$baris['attempt']} pengerjaan, {$baris['jawaban']} jawaban");
            }

            $this->newLine();
            $this->line('Penggabungan hanya memindahkan STRUKTUR. Hasil pekerjaan siswa menempel');
            $this->line('pada ujian asalnya lewat snapshot soalnya, dan dua snapshot tidak bisa');
            $this->line('diakui sebagai satu. Hapus hasilnya lebih dulu bila ujian itu memang batal,');

            return self::FAILURE;
        }

        // Ujian utama = yang paling awal dibuat. Pilihan ini disengaja: kode
        // ujiannya sudah mungkin tercetak di jadwal, dan membuat ujian baru
        // berarti kode yang beredar menunjuk baris yang tidak ada.
        $utama = $daftar->first();
        $dilebur = $daftar->slice(1)->values();

        $this->newLine();
        $this->line("Ujian yang DIPERTAHANKAN: {$utama->code} (id {$utama->id})");

        foreach ($dilebur as $u) {
            $this->line("Ujian yang DILEBUR:       {$u->code} (id {$u->id})");
        }

        $rencana = $this->rencana($utama, $dilebur, $event);

        $this->newLine();
        $this->line('Rencana perubahan:');
        $this->line("  kelas sasaran  +{$rencana['kelas']} kelas");
        $this->line("  peserta        {$rencana['peserta']} baris dipindah");
        $this->line("  sesi           {$rencana['sesi']} sesi dipindah ke ujian utama (bukan dihapus)");
        $this->line("  ruang-sesi     {$rencana['ruang_sesi']} baris ikut pindah bersama sesinya");
        $this->line("  penugasan      {$rencana['penugasan']} ikut pindah bersama ruang-sesinya");

        if (! $this->option('tulis')) {
            $this->newLine();
            $this->comment('Mode UJI-COBA - tidak ada yang diubah. Jalankan lagi dengan --tulis untuk menerapkan.');

            return self::SUCCESS;
        }

        $hasil = DB::transaction(fn () => $this->lebur($utama, $dilebur, $event));

        $this->newLine();
        $this->info("Selesai. {$utama->code} kini menaungi {$hasil['kelas']} kelas dan {$hasil['peserta']} peserta.");

        Log::info('Ujian lintas tingkat digabung.', [
            'event' => $event->id,
            'utama' => $utama->code,
            'dilebur' => $dilebur->pluck('code')->all(),
            'peserta' => $hasil['peserta'],
        ]);

        return self::SUCCESS;
    }

    /* ------------------------------------------------------------ Pembantu */

    /**
     * Ujian yang sudah punya attempt, beserta jumlah pekerjaannya.
     *
     * @param  Collection<int, Exam>  $daftar
     * @return array<int, array{kode: string, attempt: int, jawaban: int}>
     */
    private function ujianYangSudahBerjalan($daftar): array
    {
        $hasil = [];

        foreach ($daftar as $u) {
            $attempt = (int) DB::table('exam_attempts')
                ->join('exam_participants', 'exam_participants.id', '=', 'exam_attempts.exam_participant_id')
                ->where('exam_participants.exam_id', $u->id)
                ->count();

            if ($attempt === 0) {
                continue;
            }

            $jawaban = (int) DB::table('student_answers')
                ->join('attempt_questions', 'attempt_questions.id', '=', 'student_answers.attempt_question_id')
                ->join('exam_attempts', 'exam_attempts.id', '=', 'attempt_questions.exam_attempt_id')
                ->join('exam_participants', 'exam_participants.id', '=', 'exam_attempts.exam_participant_id')
                ->where('exam_participants.exam_id', $u->id)
                ->count();

            $hasil[] = ['kode' => $u->code, 'attempt' => $attempt, 'jawaban' => $jawaban];
        }

        return $hasil;
    }

    /** @param  Collection<int, Exam>  $daftar */
    private function daftarUjian($daftar): void
    {
        foreach ($daftar as $u) {
            $kelas = ExamClass::query()->where('exam_id', $u->id)->count();
            $peserta = ExamParticipant::query()->where('exam_id', $u->id)->count();
            $sesi = ExamSession::query()->where('exam_id', $u->id)->count();

            $this->line(sprintf(
                '  %-14s id=%-4d kelas=%-3d peserta=%-5d sesi=%d  %s',
                $u->code,
                $u->id,
                $kelas,
                $peserta,
                $sesi,
                $u->title,
            ));
        }
    }

    /**
     * Hitung apa yang akan berubah, tanpa menulis apa pun.
     *
     * @param  Collection<int, Exam>  $dilebur
     * @return array{kelas: int, peserta: int, sesi: int, ruang_sesi: int, penugasan: int}
     */
    private function rencana(Exam $utama, $dilebur, SchoolEvent $event): array
    {
        $idDilebur = $dilebur->pluck('id')->all();

        $kelasBaru = ExamClass::query()
            ->whereIn('exam_id', $idDilebur)
            ->whereNotIn('class_id', ExamClass::query()->where('exam_id', $utama->id)->select('class_id'))
            ->count();

        $peserta = ExamParticipant::query()->whereIn('exam_id', $idDilebur)->count();

        $sesiDilebur = ExamSession::query()->whereIn('exam_id', $idDilebur)->pluck('id');
        $ruangSesi = ExamSessionRoom::query()->whereIn('exam_session_id', $sesiDilebur)->count();
        $penugasan = SupervisionAssignment::query()
            ->whereIn('exam_session_room_id', ExamSessionRoom::query()
                ->whereIn('exam_session_id', $sesiDilebur)->select('id'))
            ->count();

        return [
            'kelas' => $kelasBaru,
            'peserta' => $peserta,
            'sesi' => $sesiDilebur->count(),
            'ruang_sesi' => $ruangSesi,
            'penugasan' => $penugasan,
        ];
    }

    /**
     * Jalankan penggabungan, di dalam satu transaksi.
     *
     * ---------------------------------------------------------------------
     * Urutannya mengikat
     * ---------------------------------------------------------------------
     * 1. Kelas sasaran digabung ke ujian utama.
     * 2. Peserta dipindah - dan baris yang SUDAH ada di ujian utama (siswa
     *    yang kebetulan tercatat di dua ujian) dihapus dari ujian yang
     *    dilebur, bukan dipindah: `unique(exam_id, student_id)` akan
     *    menolaknya, dan dua baris untuk satu siswa di satu ujian adalah
     *    keadaan yang tidak boleh ada.
     * 3. Sesi yang dilebur DIPINDAHKAN ke ujian utama - bukan dihapus, dan
     *    bukan dibuat baru. Sesi lamanya sudah membawa ruang dan penugasan
     *    pengawasnya; membuat sesi baru berarti menyalin ketiganya, dan
     *    salinan itu yang akan berbeda diam-diam.
     * 4. Ujian yang dilebur dihapus - kini tanpa sesi, tanpa peserta, tanpa
     *    kelas.
     *
     * @param  Collection<int, Exam>  $dilebur
     * @return array{kelas: int, peserta: int}
     */
    private function lebur(Exam $utama, $dilebur, SchoolEvent $event): array
    {
        $idDilebur = $dilebur->pluck('id')->all();

        // 1 - kelas sasaran, tanpa duplikat.
        $kelasAda = ExamClass::query()->where('exam_id', $utama->id)->pluck('class_id')->all();

        foreach (ExamClass::query()->whereIn('exam_id', $idDilebur)->get() as $baris) {
            if (in_array($baris->class_id, $kelasAda, true)) {
                $baris->delete();

                continue;
            }

            $baris->forceFill(['exam_id' => $utama->id])->save();
            $kelasAda[] = $baris->class_id;
        }

        // 2 - peserta. Yang sudah tercatat di ujian utama dibuang dari ujian
        // yang dilebur; sisanya dipindah beserta sesi dan ruang sesinya.
        $siswaAda = ExamParticipant::query()->where('exam_id', $utama->id)->pluck('student_id')->all();
        $dipindah = 0;

        foreach (ExamParticipant::query()->whereIn('exam_id', $idDilebur)->get() as $p) {
            if (in_array($p->student_id, $siswaAda, true)) {
                $p->delete();

                continue;
            }

            $p->forceFill(['exam_id' => $utama->id])->save();
            $siswaAda[] = $p->student_id;
            $dipindah++;
        }

        // 3 - sesi, beserta ruang dan penugasannya, ikut pindah ke ujian
        // utama. `session_number` sesi yang dilebur bisa bertabrakan dengan
        // nomor yang sudah dipakai ujian utama, dan unique-nya menolaknya -
        // karena itu nomornya disesuaikan dulu.
        $nomorTerpakai = ExamSession::query()->where('exam_id', $utama->id)->pluck('session_number')->all();
        $nomor = $nomorTerpakai === [] ? 0 : max($nomorTerpakai);

        foreach (ExamSession::query()->whereIn('exam_id', $idDilebur)->orderBy('id')->get() as $s) {
            $nomor++;

            $s->forceFill([
                'exam_id' => $utama->id,
                'session_number' => $nomor,
            ])->save();
        }

        // 4 - ujian yang dilebur kini kosong. Dihapus sesudah isinya pindah.
        Exam::query()->whereIn('id', $idDilebur)->delete();

        // Jumlah akhir dibaca ULANG dari basis data, bukan dihitung dari
        // `$dipindah`: baris yang sudah ada di ujian utama tidak dipindah
        // (ia sudah di sana), sehingga `$dipindah` lebih kecil daripada total
        // - dan laporan "432 peserta" untuk ujian berisi 850 adalah angka
        // yang membuat orang mengira separuhnya hilang.
        $totalPeserta = ExamParticipant::query()->where('exam_id', $utama->id)->count();

        $utama->forceFill(['title' => $this->judulGabungan($utama)])->save();

        return ['kelas' => count($kelasAda), 'peserta' => $totalPeserta];
    }

    /**
     * Judul ujian hasil gabungan, bila Admin tidak menyebutkannya.
     *
     * Judul lama berakhiran "#7" atau "#8" - penanda TINGKAT yang kini tidak
     * berlaku lagi, karena ujian ini menaungi keduanya. Mempertahankannya
     * berarti ujian lintas tingkat yang berjudul "...#7", dan judul itu
     * terbaca di laporan, kartu peserta, dan berita acara.
     *
     * `--nama` tetap menang bila diberikan: judul yang sudah dipakai di
     * dokumen tercetak tidak boleh diubah oleh perintah yang bermaksud
     * merapikan strukturnya.
     */
    private function judulGabungan(Exam $utama): string
    {
        $nama = $this->option('nama');

        if (filled($nama)) {
            return (string) $nama;
        }

        $lama = (string) $utama->title;

        // Buang akhiran "#7" / " #8" / "#VII" - penanda tingkat, bukan bagian
        // nama ujiannya.
        $bersih = preg_replace('/\s*#\s*[0-9IVXLC]+\s*$/u', '', $lama) ?? $lama;

        return trim($bersih) === '' ? $lama : trim($bersih);
    }
}

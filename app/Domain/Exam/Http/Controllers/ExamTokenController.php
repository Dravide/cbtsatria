<?php

namespace App\Domain\Exam\Http\Controllers;

use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Exam\Enums\StatusToken;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Models\ExamToken;
use App\Domain\Exam\Models\ExamTokenView;
use App\Domain\Exam\Services\TokenSesi;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Token ujian - wireframe plate 18, PRD §9.7, keputusan §24 #6.
 *
 * ---------------------------------------------------------------------
 * Tokennya ditampilkan, dan itu memang tujuannya
 * ---------------------------------------------------------------------
 * Halaman lain di sistem ini menyembunyikan kredensialnya - kode pengawas
 * hanya tampil sekali (plate 09). Yang ini tidak, dan perbedaannya bukan
 * kelalaian: token dibacakan pengawas di depan kelas, ditulis di papan tulis,
 * dan diketik tiga ratus siswa sambil berdiri. Tidak ada versi laman ini yang
 * berguna bila tokennya tidak terbaca.
 *
 * Yang menjaga bukan larangan membaca, melainkan pencatatan: setiap
 * pembacaan menulis satu baris `exam_token_views`, dan panel "Riwayat
 * pembukaan token" di plate 18 adalah tampilan baris-baris itu. Kalau tokennya
 * bocor, asalnya bisa ditelusuri ke satu akun - bukan berhenti pada "entah
 * siapa".
 *
 * ---------------------------------------------------------------------
 * Statusnya dihitung di server, bukan di peramban
 * ---------------------------------------------------------------------
 * Lima chip di plate 18 - draft, aktif, dibuka, kedaluwarsa, dicabut - adalah
 * StatusToken, dan ia turunan: tiga di antaranya fakta tentang waktu dan
 * riwayat saat dibaca. Menghitungnya di peramban berarti jam laptop pengawas
 * menentukan apakah tokennya terbaca sah, dan jam laptop yang salah akan
 * membuat token yang sudah kedaluwarsa tampak masih berlaku.
 *
 * Nada chipnya diambil dari StatusToken::nada(), BUKAN dari nadaStatus() di
 * ui.tsx: `dibuka` ingin nada `info` dan `draft` ingin `mute`, sementara
 * nadaStatus() tidak mengenal keduanya dan menjatuhkan `dibuka` ke `mute`.
 */
class ExamTokenController
{
    public function index(SchoolEvent $event, TokenSesi $token): Response
    {
        Gate::authorize('view', $event);

        $sesi = ExamSession::query()
            ->whereIn('exam_id', $event->exams()->pluck('id'))
            ->with([
                'exam:id,code,title,subject_id',
                'exam.subject:id,name',
                'rooms.eventRoom.room:id,code',
            ])
            ->orderBy('access_opens_at')
            ->orderBy('id')
            ->get();

        // Seluruh token sesi-sesi ini, beserta riwayat pembacaannya, dalam dua
        // query - bukan dua query per baris.
        $semuaToken = ExamToken::query()
            ->whereIn('exam_session_id', $sesi->pluck('id'))
            ->with([
                'creator:id,name',
                'views' => fn ($q) => $q->with(['user:id,name', 'room:id,code'])->orderByDesc('viewed_at')->limit(50),
            ])
            ->orderByDesc('id')
            ->get();

        $perSesi = $semuaToken->groupBy('exam_session_id');

        // Pembaca pertama tercatat sekaligus sebagai bukti "pernah dibuka" -
        // pertanyaan yang menentukan bedanya chip `aktif` dari `dibuka`.
        $pembaca = ExamTokenView::query()
            ->whereIn('exam_token_id', $semuaToken->pluck('id'))
            ->with(['user:id,name', 'room:id,code'])
            ->orderByDesc('viewed_at')
            ->limit(20)
            ->get();

        $baris = $sesi->map(function (ExamSession $s) use ($perSesi) {
            $aktif = $perSesi->get($s->id, collect())->first(fn (ExamToken $t) => $t->revoked_at === null);
            $pernahDibuka = $aktif !== null && $aktif->views->isNotEmpty();

            // Sesi yang sudah punya ruang DAN pengawas boleh diterbitkan
            // tokennya. Tombolnya nonaktif dengan alasan tertulis bila belum -
            // pola plate 12, dan alasannya bukan kehati-hatian: token yang
            // terbit untuk ruang tanpa pengawas adalah token yang tidak ada
            // yang memverifikasi, dan ia terbit tanpa satu pun tanda bahwa ada
            // yang kurang.
            $tanpaPengawas = $s->ruangTanpaPengawas()
                ->map(fn ($r) => $r->eventRoom?->room?->code ?? 'R-?')
                ->values()
                ->all();

            return [
                'sesi_id' => $s->id,
                'sesi' => $s->label(),
                'ujian' => $s->exam?->subject?->name ?? $s->exam?->title,
                'ruang' => $s->rooms->count(),
                'ruang_kode' => $s->rooms->map(fn ($r) => $r->eventRoom?->room?->code)->filter()->values()->all(),

                'token_id' => $aktif?->id,
                'berlaku' => $aktif === null
                    ? null
                    : $aktif->valid_from?->format('H:i').'–'.$aktif->valid_until?->format('H:i'),

                // Nama pembaca pertama, bukan terakhir: pertanyaan kolom ini
                // adalah "siapa yang membukanya", dan pembukaan pertama adalah
                // jawabannya. Pembacaan berikutnya sudah masuk riwayat.
                //
                // Jatuh ke `pelaku_label` bila `user` kosong - sejak Fase 6
                // pengawas membuka token dari tautan ruang, dan di sana tidak
                // ada akun sama sekali. Label itu berisi "R-01 (tautan ruang)",
                // yang lebih berguna daripada tanda pisah.
                'dibuka_oleh' => $aktif?->views
                    ->sortBy('viewed_at')
                    ->first()?->user?->name
                    ?? $aktif?->views->sortBy('viewed_at')->first()?->pelaku_label,

                'status' => $aktif?->status($pernahDibuka)->value ?? StatusToken::Draft->value,
                'status_label' => $aktif?->status($pernahDibuka)->label() ?? StatusToken::Draft->label(),
                'status_nada' => $aktif?->status($pernahDibuka)->nada() ?? StatusToken::Draft->nada(),

                'boleh_terbit' => $s->rooms->isNotEmpty() && $tanpaPengawas === [],
                'alasan_tolak' => $s->rooms->isEmpty()
                    ? 'Sesi ini belum punya ruang.'
                    : ($tanpaPengawas === []
                        ? null
                        : 'Ruang berikut belum punya pengawas: '.implode(', ', $tanpaPengawas).'.'),
            ];
        })->all();

        return Inertia::render('Admin/Event/Token', [
            'event' => $this->ringkas($event),
            'baris' => $baris,

            'angka' => [
                'sesi' => $sesi->count(),
                'bertoken' => $baris === [] ? 0 : collect($baris)->whereNotNull('token_id')->count(),
                'dibuka' => collect($baris)->whereNotIn('status', [StatusToken::Draft->value])->whereNotNull('token_id')->count(),
            ],

            // Riwayat pembukaan lintas sesi, terbaru dulu. Plate 18 menampilkan
            // panelnya di bawah tabel, dan yang dibacanya adalah baris seperti
            // "S-01 dibuka Ani Mulyani | 07:31 | R-01" - sesi, orang, jam, ruang.
            'riwayat' => $pembaca->map(fn (ExamTokenView $v) => [
                'id' => $v->id,
                'sesi' => $v->token?->session?->label() ?? 'S-?',
                'ujian' => $v->token?->session?->exam?->subject?->name
                    ?? $v->token?->session?->exam?->title,
                'jenis' => $v->jenis,
                // `pelaku_label` menjadi cadangan sejak Fase 6: pembacaan lewat
                // tautan ruang tidak punya akun, dan riwayat yang berbunyi
                // "—" tidak menjawab pertanyaan yang membuat panel ini ada.
                'oleh' => $v->user?->name ?? $v->pelaku_label ?? '—',
                'ruang' => $v->room?->code,
                'jam' => $v->viewed_at?->format('H:i'),
                'tanggal' => $v->viewed_at?->format('d M'),
            ])->all(),

            // Ruang yang pengawasnya bisa membuka token - dipakai panel
            // "Tugaskan lalu terbitkan" saat sesinya belum punya pengawas.
            'tanpa_pengawas' => collect($baris)
                ->filter(fn (array $b) => $b['alasan_tolak'] !== null)
                ->values()
                ->all(),
        ]);
    }

    /**
     * Terbitkan token satu sesi.
     *
     * Menerbitkan ulang MENCABUT yang lama, dan itu keputusan §24 #6: tanpa
     * pencabutan, token yang sudah difoto siswa tetap berlaku, dan tidak ada
     * yang tahu berapa banyak token sah yang beredar untuk satu sesi.
     *
     * Kode terbitnya dikirim lewat flash `kode_token` supaya bisa dibacakan
     * Admin sekali lagi tanpa satu pun pembacaan tercatat - pencatatan itu
     * untuk token yang dibuka lewat laman, dan penerbitan bukan pembukaan.
     */
    public function terbitkan(
        SchoolEvent $event,
        ExamSession $sesi,
        TokenSesi $token,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $sesi);
        $this->pastikanSesiMilikEvent($sesi, $event);

        try {
            $hasil = $token->terbitkan($sesi, (int) request()->user()->id);
        } catch (DomainException $e) {
            return back()->with('galat', $e->getMessage());
        }

        $logger->log('Menerbitkan token sesi', $event, [
            'sesi' => $sesi->label(),
        ]);

        return back()
            ->with('sukses', "Token {$sesi->label()} diterbitkan.")
            ->with('kode_token', $hasil['kode'])
            ->with('token_sesi', $sesi->label());
    }

    /**
     * Tampilkan token dan CATAT pembacaannya.
     *
     * Satu endpoint untuk dua hal yang tidak bisa dipisahkan: anotasi plate 18
     * menuntut riwayatnya, dan riwayat yang hilang membuat "kalau token bocor,
     * asalnya bisa ditelusuri" menjadi janji kosong.
     *
     * Admin dan Pengawas boleh - PRD §9.7 mengakui pengawas memang harus
     * membacakannya. Yang menjaga bukan larangannya.
     */
    public function baca(
        SchoolEvent $event,
        ExamToken $token,
        TokenSesi $service,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('bacaToken', $token);
        $this->pastikanTokenMilikEvent($token, $event);

        if (! $token->berlaku()) {
            // Token kedaluwarsa atau dicabut TIDAK dibacakan, dan itu bukan
            // kehati-hatian: mengirim kode yang sudah mati ke layar pengawas
            // akan membuatnya membacakannya ke siswa yang lalu ditolak sistem,
            // dan pengawas menyimpulkan sistemnya rusak.
            return back()->with('galat', 'Token itu sudah tidak berlaku. Terbitkan yang baru bila perlu.');
        }

        $kode = $service->bacaBerwenang($token, request()->user());

        if ($kode === null) {
            return back()->with('galat', 'Token itu tidak bisa dibaca.');
        }

        $logger->log('Membuka token sesi', $event, ['sesi' => $token->session?->label()]);

        return back()
            ->with('kode_token', $kode)
            ->with('token_sesi', $token->session?->label());
    }

    public function cabut(
        SchoolEvent $event,
        ExamToken $token,
        TokenSesi $service,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $token);
        $this->pastikanTokenMilikEvent($token, $event);

        $service->cabut($token);

        $logger->log('Mencabut token sesi', $event, ['sesi' => $token->session?->label()]);

        return back()->with('sukses', 'Token dicabut. Siswa tidak bisa lagi memakainya.');
    }

    /* ------------------------------------------------------------ Pembantu */

    private function pastikanSesiMilikEvent(ExamSession $sesi, SchoolEvent $event): void
    {
        abort_unless($sesi->exam?->event_id === $event->id, 404);
    }

    private function pastikanTokenMilikEvent(ExamToken $token, SchoolEvent $event): void
    {
        abort_unless($token->session?->exam?->event_id === $event->id, 404);
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

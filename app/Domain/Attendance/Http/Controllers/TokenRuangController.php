<?php

namespace App\Domain\Attendance\Http\Controllers;

use App\Domain\Attendance\Models\ExamAttempt;
use App\Domain\Attendance\Services\KonteksRuang;
use App\Domain\Exam\Models\ExamParticipant;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Exam\Models\ExamTokenView;
use App\Domain\Exam\Services\TokenSesi;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Token sesi dari layar pengawas - wireframe plate 14.
 *
 * =====================================================================
 * Yang dijaga di sini bukan larangan membaca, melainkan CAKUPANNYA
 * =====================================================================
 * Token memang harus terbaca pengawas: ia membacakannya di depan kelas,
 * dan pembacaan itu tercatat satu baris di `exam_token_views`. Yang tidak
 * boleh terjadi adalah pengawas ruang R-02 membaca token sesi yang tidak
 * memuat ruangnya.
 *
 * Karena itu controller ini TIDAK memakai `Gate::authorize('bacaToken', …)`
 * untuk pemegang tautan: Gate memerlukan User, dan pemegang tautan tidak
 * punya. Yang menjaganya adalah `KonteksRuang::dari($ruang)` - ruang yang
 * diminta harus benar-benar ruangnya - ditambah pemeriksaan di bawah bahwa
 * sesi yang tokennya dibaca MEMUAT ruang itu.
 *
 * Keduanya bukan pemeriksaan yang sama. Yang pertama menjawab "apakah
 * ruang ini ruangku"; yang kedua menjawab "apakah sesi yang membawa token
 * ini sesi ruangku". Sebuah sesi bisa memuat sepuluh ruang, dan pengawas
 * R-02 hanya boleh membaca tokennya bila R-02 memang salah satunya.
 */
class TokenRuangController
{
    public function index(ExamSessionRoom $ruang): Response
    {
        $konteks = KonteksRuang::dari($ruang);
        $sesi = $ruang->session;

        $token = $sesi?->tokenAktif();

        // Riwayat dibatasi 20 baris terakhir. Panelnya untuk menjawab "siapa
        // yang membuka token ini", bukan untuk menjadi arsip - arsipnya ada
        // di konsol Admin, dan laman ini dipegang sambil berdiri.
        $riwayat = $token === null ? collect() : ExamTokenView::query()
            ->where('exam_token_id', $token->id)
            ->with(['user:id,name', 'room:id,code'])
            ->orderByDesc('viewed_at')
            ->limit(20)
            ->get();

        // Pemakaian token = peserta ruang ini yang sudah punya attempt.
        // Fase 6 belum punya penulisnya, sehingga angkanya 0/32 sampai
        // Fase 7 mengisinya - dan bentuknya sudah benar sehingga laman ini
        // tidak perlu diubah lagi.
        $pesertaRuang = ExamParticipant::query()
            ->where('exam_session_room_id', $ruang->id)
            ->pluck('id');

        $pemakaian = [
            'sudah' => ExamAttempt::query()->whereIn('exam_participant_id', $pesertaRuang)->count(),
            'total' => $pesertaRuang->count(),
        ];

        return Inertia::render('Pengawas/Token', [
            'ruang' => [
                'id' => $ruang->id,
                'kode' => $konteks->kodeRuang(),
                'nama' => $ruang->eventRoom?->room?->name,
                'sesi' => $sesi?->label(),
                'ujian' => $sesi?->exam?->title,
            ],
            'pelaku' => $konteks->jenisPelaku(),

            'token' => $token === null ? null : [
                'id' => $token->id,
                'berlaku_hingga' => $token->valid_until?->format('H:i'),
                'berlaku' => $token->berlaku(),
                'status' => $token->status(false)->value,
                'status_label' => $token->status(false)->label(),
                'status_nada' => $token->status(false)->nada(),
            ],

            'pemakaian' => $pemakaian,

            'riwayat' => $riwayat->map(fn (ExamTokenView $v) => [
                'jam' => $v->viewed_at?->format('H:i'),
                'oleh' => $v->user?->name ?? $v->pelaku_label ?? '—',
                'ruang' => $v->room?->code,
            ])->all(),
        ]);
    }

    /**
     * Baca token - dan CATAT pembacaannya.
     *
     * Kode dikirim lewat flash, bukan lewat prop halaman. Perbedaannya
     * bukan gaya: prop halaman ikut terkirim ke SETIAP respons berikutnya
     * selama sesinya hidup, sementara flash hidup tepat satu respons.
     * Token yang terus-menerus ada di dalam props adalah token yang ikut
     * tersimpan di riwayat peramban pengawas.
     *
     * Laman plate 12 memang menampilkan token tertutup sampai tombolnya
     * ditekan - dan tombol itulah satu-satunya jalan kode ini masuk ke DOM.
     */
    public function baca(ExamSessionRoom $ruang, TokenSesi $service): RedirectResponse
    {
        $konteks = KonteksRuang::dari($ruang);
        $sesi = $ruang->session;

        if ($sesi === null) {
            return back()->with('galat', 'Ruang ini belum dipakai sesi mana pun.');
        }

        // Pemeriksaan kedua - lihat catatan kelas ini. Sesi yang tokennya
        // dibaca harus MEMUAT ruang pemanggilnya.
        $memuat = $sesi->rooms()->whereKey($ruang->id)->exists();

        if (! $memuat) {
            abort(404);
        }

        $token = $sesi->tokenAktif();

        if ($token === null) {
            return back()->with('galat', 'Sesi ini belum punya token. Minta Admin menerbitkannya.');
        }

        // Token mati tidak dibacakan, dan pemeriksaan ini ada DI SINI juga -
        // bukan hanya di dalam service - karena pesannya berbeda: yang ini
        // memberi tahu pengawas apa yang harus dilakukannya.
        if (! $token->berlaku()) {
            return back()->with('galat', 'Token itu sudah tidak berlaku. Minta Admin menerbitkan yang baru.');
        }

        $kode = $service->bacaBerwenang(
            $token,
            $konteks->pengguna,
            $ruang->eventRoom?->room_id,
            $konteks->pelakuLabel(),
        );

        if ($kode === null) {
            return back()->with('galat', 'Token itu tidak bisa dibaca.');
        }

        return back()
            ->with('kode_token', $kode)
            ->with('token_sesi', $sesi->label());
    }
}

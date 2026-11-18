<?php

namespace App\Domain\Attendance\Http\Controllers;

use App\Domain\Attendance\Services\PantauPublik;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Tenancy\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pantau publik aktivitas ujian - `/pantau/{slug}`, TANPA login.
 *
 * =====================================================================
 * Alamat kedua di luar `auth`, dan batasnya ditegakkan di layanan
 * =====================================================================
 * Yang pertama adalah `/papan/{slug}` (pengawas per ruang). Laman ini
 * menjawab pertanyaan lain dari orang yang sama: "ujiannya sudah sampai
 * mana, dan ada yang tertahan tidak". Batas isinya ada di `PantauPublik` -
 * angka, kode ruang, nama pengawas, jam, dan keadaan laporan. Tidak nama
 * siswa, tidak NIS, tidak nomor kursi, tidak token.
 *
 * ---------------------------------------------------------------------
 * Satu endpoint DENYUT, bukan dua laman
 * ---------------------------------------------------------------------
 * `denyut()` mengembalikan potret yang SAMA dengan `tampilkan()`, sebagai
 * JSON. Halaman memanggilnya tiap lima detik dan mengganti angkanya tanpa
 * memuat ulang - dan itu satu-satunya cara papan ini bisa bergerak tanpa
 * kehilangan posisi gulir orang yang sedang membacanya.
 *
 * Memakai Inertia untuk penyegaran berkala akan mengirim seluruh props
 * halaman (termasuk daftar mapel dan nama sekolah) setiap lima detik, dan
 * Inertia tidak dirancang untuk itu: ia menyimpan riwayat kunjungan, dan
 * `replace` berulang akan mengotori riwayat peramban.
 *
 * ---------------------------------------------------------------------
 * Limiter sendiri, dan ia ketat
 * ---------------------------------------------------------------------
 * Alamat ini di luar login, jadi siapa pun bisa memanggilnya. Tanpa limiter,
 * satu skrip sederhana menjadikan endpoint ini alat pembebanan atas basis
 * data sekolah - dan ia dipanggil tiap lima detik oleh setiap layar yang
 * membuka papan ini.
 */
class PantauPublikController
{
    public function __construct(private readonly PantauPublik $pantau) {}

    public function tampilkan(string $slug): Response
    {
        $sekolah = $this->sekolah($slug);

        $potret = $this->pantau->susun($sekolah);

        return Inertia::render('Publik/Pantau', [
            'slug' => $slug,
            'potret' => $potret,
            // Alamat denyut dibangun di server, bukan disusun ulang di React:
            // alamat yang hidup di dua tempat adalah alamat yang suatu hari
            // berbeda di salah satunya - pola yang sama dengan `ruang_url`.
            'denyut_url' => "/pantau/{$slug}/denyut",
            'papan_url' => "/papan/{$slug}",
        ]);
    }

    /**
     * Potret terbaru sebagai JSON - dipanggil halaman tiap lima detik.
     *
     * Tanpa slug yang sah, jawabannya 404 JSON, bukan halaman galat HTML:
     * pemanggilnya `fetch` di latar belakang, dan HTML 404 akan gagal
     * di-parse tanpa satu pun pesan yang bisa dibaca.
     */
    public function denyut(string $slug): JsonResponse
    {
        $sekolah = $this->sekolah($slug);

        return response()->json($this->pantau->susun($sekolah));
    }

    /**
     * Papan pantau publik untuk SATU event tertentu - `/pantau/{slug}/event/{event}`.
     */
    public function tampilkanEventPublik(Request $request, string $slug, int $event): Response
    {
        $sekolah = $this->sekolah($slug);
        $eventModel = $this->eventSekolah($sekolah, $event);
        $sesiId = $request->integer('sesi') ?: null;

        return Inertia::render('Publik/Pantau', [
            'slug' => $slug,
            'potret' => $this->pantau->susunEvent($eventModel, $sesiId),
            'denyut_url' => "/pantau/{$slug}/event/{$eventModel->id}/denyut",
            'ruang_url_template' => "/pantau/{$slug}/event/{$eventModel->id}/ruang/__RUANG__",
            'papan_url' => "/papan/{$slug}",
            'kembali_url' => null,
        ]);
    }

    /**
     * Denyut JSON papan pantau publik untuk SATU event tertentu.
     */
    public function denyutEventPublik(Request $request, string $slug, int $event): JsonResponse
    {
        $sekolah = $this->sekolah($slug);
        $eventModel = $this->eventSekolah($sekolah, $event);
        $sesiId = $request->integer('sesi') ?: null;

        return response()->json($this->pantau->susunEvent($eventModel, $sesiId));
    }

    /**
     * Detail interaktif satu ruang-sesi pada papan Layar PID publik.
     */
    public function ruangEventPublik(string $slug, int $event, int $ruang): JsonResponse
    {
        $sekolah = $this->sekolah($slug);
        $eventModel = $this->eventSekolah($sekolah, $event);

        $detail = $this->pantau->detailRuangEvent($eventModel, $ruang);

        if ($detail === null) {
            abort(404);
        }

        return response()->json($detail);
    }

    /**
     * Papan pantau dari konsol Admin untuk SATU event - `/admin/events/{event}/pantau`.
     *
     * Bekerja meskipun `schools.papan_slug` belum dinyalakan, karena Admin
     * sudah terautentikasi dan diotorisasi lewat `Gate::authorize('view', $event)`.
     */
    public function tampilkanEventAdmin(Request $request, SchoolEvent $event): Response
    {
        Gate::authorize('view', $event);
        $sesiId = $request->integer('sesi') ?: null;

        return Inertia::render('Publik/Pantau', [
            'slug' => $event->code,
            'potret' => $this->pantau->susunEvent($event, $sesiId),
            'denyut_url' => "/admin/events/{$event->id}/pantau/denyut",
            'ruang_url_template' => "/admin/events/{$event->id}/pantau/ruang/__RUANG__",
            'papan_url' => null,
            'kembali_url' => "/admin/events/{$event->id}",
        ]);
    }

    /**
     * Denyut JSON papan pantau Admin untuk SATU event.
     */
    public function denyutEventAdmin(Request $request, SchoolEvent $event): JsonResponse
    {
        Gate::authorize('view', $event);
        $sesiId = $request->integer('sesi') ?: null;

        return response()->json($this->pantau->susunEvent($event, $sesiId));
    }

    /**
     * Detail interaktif satu ruang-sesi pada papan pantau Admin.
     */
    public function ruangEventAdmin(SchoolEvent $event, int $ruang): JsonResponse
    {
        Gate::authorize('view', $event);

        $detail = $this->pantau->detailRuangEvent($event, $ruang);

        if ($detail === null) {
            abort(404);
        }

        return response()->json($detail);
    }

    private function eventSekolah(School $sekolah, int $eventId): SchoolEvent
    {
        $event = SchoolEvent::withoutGlobalScopes()
            ->where('school_id', $sekolah->id)
            ->find($eventId);

        if ($event === null) {
            abort(404);
        }

        return $event;
    }

    /**
     * Sekolah dari slug - TANPA scope tenant, dan itu wajib.
     *
     * Pada permintaan ini belum ada pengguna, sehingga `SchoolResolver`
     * mengembalikan null - dan null berarti "jangan saring", bukan "tidak
     * ada sekolah". Karena itu `School` dibaca apa adanya; ia tabel induk,
     * dan scope yang menyaringnya berarti tidak ada yang bisa membacanya.
     */
    private function sekolah(string $slug): School
    {
        $sekolah = School::query()->where('papan_slug', $slug)->first();

        if ($sekolah === null) {
            abort(404);
        }

        return $sekolah;
    }
}

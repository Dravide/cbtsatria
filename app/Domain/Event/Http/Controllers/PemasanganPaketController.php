<?php

namespace App\Domain\Event\Http\Controllers;

use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Event\Models\EventSubject;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Question\Services\PemasanganPaket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Pemasangan paket soal ke mapel event - wireframe plate 15.
 *
 * =====================================================================
 * Mengapa controller ini ada di modul Event, bukan di Question
 * =====================================================================
 * Ia berbicara tentang MAPEL SEBUAH EVENT - baris `event_subjects` yang
 * dipasangi paket. Karena itu ia memerlukan `SchoolEvent` dan `EventSubject`,
 * dan modul Question tidak boleh mengimpornya: peta modul
 * (DomainBoundariesTest) mengizinkan Question menunjuk Identity, Tenancy,
 * Academic, dan Audit - bukan Event.
 *
 * Arah sebaliknya DIIZINKAN, dan itulah yang dipakai di sini: Event memanggil
 * `PemasanganPaket`, sebuah SERVICE modul Question. Aturan blueprint `:422`
 * berbunyi "satu modul hanya boleh mengakses modul lain melalui service
 * resminya, bukan lewat model langsung milik domain lain", dan itulah tepat
 * yang terjadi - lihat docblock PenghitungButir untuk pembahasan yang sama.
 *
 * Menaruh controller ini di Question akan menuntut pengecualian pada peta
 * modul, dan peta yang punya satu pengecualian berhenti menjelaskan apa pun.
 *
 * =====================================================================
 * Masuknya lewat service, bukan langsung ke kolom
 * =====================================================================
 * Tidak ada satu baris di sini yang menulis `event_subjects`. Seluruh
 * penulisan lewat `PemasanganPaket::pasang()`, yang menulis kolom DAN baris
 * riwayat dalam satu transaksi. Itu yang membuat laman riwayat tidak pernah
 * berbeda dari keadaan sebenarnya.
 *
 * =====================================================================
 * Paket berstatus Draft DITOLAK di sini
 * =====================================================================
 * Dan itu keputusan yang bisa diperdebatkan, jadi alasannya ditulis.
 *
 * `StatusPaket::Siap` adalah tanda dari GURU bahwa paketnya sudah selesai -
 * bukan tanda bahwa ia berisi. Pemasangan yang menerima paket Draft membuat
 * status itu hiasan: paket setengah jadi terpasang, ujian lahir darinya, dan
 * yang menemukan soalnya kurang adalah pengawas di ruang ujian.
 *
 * Gerbang kesiapan event memang penegak terakhirnya, dan ia tetap bekerja.
 * Tetapi gerbang yang menolak sesuatu yang BISA dicegah lebih awal adalah
 * gerbang yang menyerahkan pekerjaan ke orang yang salah: yang tahu paketnya
 * belum selesai adalah Gurunya, dan yang menekan tombol di sini adalah Admin.
 */
class PemasanganPaketController
{
    /**
     * Pasang atau lepas paket pada satu mapel event.
     *
     * @param  int|null  $questionPackageId  Null berarti melepas paketnya.
     */
    public function simpan(
        Request $request,
        SchoolEvent $event,
        PemasanganPaket $pemasangan,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $event);

        $data = $request->validate([
            'baris_id' => ['required', 'integer'],
            // Nullable: melepas paket adalah permintaan yang sah, dan
            // formulir yang tidak bisa mengosongkan pilihan adalah formulir
            // yang memaksa Admin memilih paket salah untuk membatalkan yang
            // benar.
            'question_package_id' => ['nullable', 'integer'],
        ]);

        $baris = $this->baris($event, (int) $data['baris_id']);

        $paketId = isset($data['question_package_id']) && $data['question_package_id'] !== null
            ? (int) $data['question_package_id']
            : null;

        if ($paketId !== null) {
            $this->pastikanSah($pemasangan, $event, $baris, $paketId);
        }

        $terpasang = $pemasangan->pasang(
            (int) $baris->id,
            $paketId,
            (int) $request->user()->id,
        );

        $nama = $baris->subject?->name ?? 'Mata pelajaran';

        $logger->log('Memasang paket soal ke mapel event', $event, [
            'mapel' => $nama,
            'paket_id' => $paketId,
        ]);

        if ($paketId === null) {
            return back()->with('sukses', 'Paket dilepas dari '.$nama.'.');
        }

        return back()->with(
            'sukses',
            'Paket '.($pemasangan->ringkasPaket([$paketId])[$paketId]['nama'] ?? '').' dipasang ke '.$nama.'.',
        );
    }

    /**
     * Riwayat pemasangan satu mapel event.
     *
     * Dikembalikan sebagai JSON, bukan sebagai laman: ia dibuka dari panel di
     * laman yang sama, dan berpindah halaman untuk melihat empat baris riwayat
     * berarti kehilangan posisi gulir pada daftar empat puluh mapel.
     */
    public function riwayat(
        SchoolEvent $event,
        EventSubject $mapel,
        PemasanganPaket $pemasangan,
    ): JsonResponse {
        Gate::authorize('view', $event);

        if ((int) $mapel->event_id !== (int) $event->id) {
            abort(404, 'Mata pelajaran tidak ada di event ini.');
        }

        return response()->json([
            'mapel' => $mapel->subject?->name,
            'riwayat' => $pemasangan->riwayat((int) $mapel->id),
            // Daftar paket yang boleh dipasang - disaring per mapel, karena
            // paket Matematika tidak boleh dipasang ke mapel Fisika dan
            // pemilih yang menawarkannya akan menuntut Admin mengingat mana
            // paket milik mapel mana.
            'tersedia' => $pemasangan->paketTersedia(
                (int) $event->school_id,
                (int) $mapel->subject_id,
            ),
        ]);
    }

    /* ------------------------------------------------------------- Pembantu */

    /** Baris event_subjects yang benar-benar milik event di URL. */
    private function baris(SchoolEvent $event, int $barisId): EventSubject
    {
        $baris = EventSubject::query()->whereKey($barisId)->first();

        if ($baris === null || (int) $baris->event_id !== (int) $event->id) {
            // 404, BUKAN 403: barisnya bukan milik event yang disebut URL,
            // sehingga dari sudut pandang permintaan ini ia memang tidak ada.
            abort(404, 'Mata pelajaran tidak ada di event ini.');
        }

        return $baris;
    }

    /**
     * Paket harus dari mapel yang sama, dan sudah berstatus siap.
     *
     * Diperiksa di sini, bukan di service: `PemasanganPaket` sengaja tidak
     * memeriksa apa pun selain keberadaan barisnya, karena ia juga dipanggil
     * seeder dan test - dan memeriksa izin dua kali berarti dua tempat yang
     * bisa berbeda diam-diam.
     */
    private function pastikanSah(
        PemasanganPaket $pemasangan,
        SchoolEvent $event,
        EventSubject $baris,
        int $paketId,
    ): void {
        $ringkas = $pemasangan->ringkasPaket([$paketId])[$paketId] ?? null;

        if ($ringkas === null) {
            throw ValidationException::withMessages([
                'question_package_id' => 'Paket soal tidak ditemukan.',
            ]);
        }

        $tersedia = collect($pemasangan->paketTersedia((int) $event->school_id, (int) $baris->subject_id))
            ->firstWhere('id', $paketId);

        if ($tersedia === null) {
            throw ValidationException::withMessages([
                'question_package_id' => 'Paket itu bukan milik mata pelajaran ini.',
            ]);
        }

        if ($ringkas['status'] !== 'siap') {
            throw ValidationException::withMessages([
                'question_package_id' => 'Paket '.$ringkas['nama'].' masih berstatus Draft. Minta Guru penulisnya menandainya Siap terlebih dahulu.',
            ]);
        }
    }
}

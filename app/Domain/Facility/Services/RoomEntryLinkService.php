<?php

namespace App\Domain\Facility\Services;

use App\Domain\Facility\Models\Room;
use App\Domain\Facility\Models\RoomEntryLink;
use App\Domain\Tenancy\Services\SchoolResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Penerbitan tautan masuk ruang.
 *
 * ---------------------------------------------------------------------
 * Kelas ini dulu juga menerbitkan dan memeriksa KODE pengawas
 * ---------------------------------------------------------------------
 * Sampai 2026-11-07 ia memegang `kodeAcak()` dan `cocokkan()`. Keduanya
 * pindah 2026-11-07 - bukan ditambahkan di tempat lain, melainkan
 * DIPINDAHKAN, karena kode pengawas bukan lagi milik ruang:
 *
 *   menerbitkan  -> Event\Services\PenerbitKodePengawas
 *   mencocokkan  -> Attendance\Services\PencocokKodePengawas
 *
 * Yang tersisa di sini hanyalah tautan, dan itu memang seluruh tugasnya
 * sekarang. Menerbitkan tautan tidak lagi menerbitkan kredensial apa pun -
 * konsekuensi yang nyata di lapangan dan disengaja: kartu yang ditempel di
 * daun pintu berhenti memuat apa pun yang bisa membuka pintunya.
 *
 * ---------------------------------------------------------------------
 * Menerbitkan ulang mencabut yang lama
 * ---------------------------------------------------------------------
 * Satu ruang punya satu tautan pintu yang berlaku. Menerbitkan yang baru
 * otomatis mencabut yang lama - kalau tidak, kartu pintu yang difoto orang
 * lain tetap berfungsi, dan tidak ada yang tahu berapa banyak tautan sah
 * yang beredar untuk satu pintu.
 *
 * `use_count` yang menjawab pertanyaan itu sekarang, dan ia menjadi lebih
 * penting setelah kodenya pindah: pada kartu yang tidak lagi memuat kode,
 * "tautan ini dipakai lima kali" adalah satu-satunya tanda kebocoran yang
 * masih bisa dibaca Admin dari kartunya.
 */
class RoomEntryLinkService
{
    /** Berapa lama tautan berlaku bila Admin tidak menentukan. */
    public const BERLAKU_JAM = 2;

    public function __construct(private readonly SchoolResolver $resolver) {}

    /**
     * Terbitkan tautan baru untuk satu ruang.
     */
    public function terbitkan(Room $ruang, ?int $berlakuJam = null): RoomEntryLink
    {
        $berlakuJam ??= self::BERLAKU_JAM;

        return DB::transaction(function () use ($ruang, $berlakuJam) {
            $this->cabutSemua($ruang);

            return RoomEntryLink::create([
                'school_id' => $ruang->school_id,
                'room_id' => $ruang->id,
                'slug' => $this->slugUnik($ruang),
                'expires_at' => now()->addHours($berlakuJam),
                'use_count' => 0,
            ]);
        });
    }

    /**
     * Cabut semua tautan ruang yang masih berlaku.
     */
    public function cabutSemua(Room $ruang): int
    {
        return RoomEntryLink::query()
            ->where('room_id', $ruang->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    /**
     * Catat satu pemakaian tautan - dan HANYA dipanggil sesudah kodenya cocok.
     *
     * ---------------------------------------------------------------------
     * Mengapa methodnya terpisah dari pencocokan kode
     * ---------------------------------------------------------------------
     * Urutan "cocok dulu, baru naikkan" adalah keamanannya, dan ia dulu
     * dijamin oleh satu method yang memuat keduanya. Sejak kodenya pindah ke
     * modul lain, jaminan itu tidak lagi bisa ditegakkan oleh kompilator -
     * yang menjaga sekarang adalah nama method ini dan pemanggilnya di
     * `PintuRuangController::buka()`, yang memanggilnya SESUDAH pemeriksaan
     * ruang dan jendela sesi.
     *
     * Kalau urutannya terbalik, `use_count` naik untuk percobaan yang GAGAL,
     * dan Admin melihat "5 pemakaian" pada tautan yang belum pernah dipakai
     * siapa pun - angka yang tidak lagi bisa dipercaya untuk pertanyaan yang
     * justru paling penting: apakah kartu pintu ini difoto orang lain.
     *
     * Pemeriksaan `berlaku()` di sini bukan pengulangan yang sia-sia:
     * pemanggilnya sudah memeriksanya, tetapi method ini juga menulis, dan
     * yang menulis ke baris yang sudah dicabut adalah cara paling sunyi
     * untuk menghidupkan kembali sesuatu yang sudah dimatikan Admin.
     */
    public function catatPemakaian(RoomEntryLink $tautan): void
    {
        if (! $tautan->berlaku()) {
            return;
        }

        $tautan->forceFill([
            'use_count' => $tautan->use_count + 1,
            'last_used_at' => now(),
        ])->save();
    }

    /**
     * Slug unik global untuk URL publik.
     *
     * Bentuknya mengikuti wireframe - "r01-7k4m": kode ruang yang bisa dibaca
     * manusia, lalu empat karakter acak yang membuatnya tidak bisa ditebak.
     * Kode ruangnya sengaja tetap ada supaya Admin yang memegang tautan itu
     * bisa tahu ruang mana yang dimaksud tanpa membukanya.
     */
    private function slugUnik(Room $ruang): string
    {
        $awalan = Str::slug($ruang->code) ?: 'ruang';

        do {
            $slug = $awalan.'-'.Str::lower(Str::random(4));
        } while (RoomEntryLink::tanpaTenant()->where('slug', $slug)->exists());

        return $slug;
    }
}

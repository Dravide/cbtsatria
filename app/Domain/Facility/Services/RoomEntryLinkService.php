<?php

namespace App\Domain\Facility\Services;

use App\Domain\Facility\Models\Room;
use App\Domain\Facility\Models\RoomEntryLink;
use App\Domain\Tenancy\Services\SchoolResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Penerbitan tautan masuk ruang + kode pengawas.
 *
 * ---------------------------------------------------------------------
 * Mengapa kode dikembalikan, bukan disimpan
 * ---------------------------------------------------------------------
 * Kode ditampilkan SEKALI, pada respons yang menerbitkannya. Ia tidak
 * disimpan dalam bentuk terbaca di mana pun - hanya hash-nya. Konsekuensinya
 * yang tidak nyaman tapi benar: kode yang hilang hanya bisa diganti, tidak
 * bisa dilihat lagi. Itu memang sifat kredensial yang baik.
 *
 * Karena itu pula fungsi ini mengembalikan kode sebagai nilai kembalian,
 * bukan lewat properti model: nilai kembalian tidak bisa terbawa ke respons
 * berikutnya secara tidak sengaja.
 *
 * ---------------------------------------------------------------------
 * Menerbitkan ulang mencabut yang lama
 * ---------------------------------------------------------------------
 * Satu ruang punya satu tautan pintu yang berlaku. Menerbitkan yang baru
 * otomatis mencabut yang lama - kalau tidak, kartu pintu yang difoto orang
 * lain tetap berfungsi, dan tidak ada yang tahu berapa banyak tautan sah
 * yang beredar untuk satu pintu.
 */
class RoomEntryLinkService
{
    /** Berapa lama tautan berlaku bila Admin tidak menentukan. */
    public const BERLAKU_JAM = 2;

    public function __construct(private readonly SchoolResolver $resolver) {}

    /**
     * Terbitkan tautan baru untuk satu ruang.
     *
     * @return array{tautan: RoomEntryLink, kode: string}
     */
    public function terbitkan(Room $ruang, ?int $berlakuJam = null, ?string $kodeManual = null): array
    {
        $kode = $kodeManual ?? $this->kodeAcak();
        $berlakuJam ??= self::BERLAKU_JAM;

        $tautan = DB::transaction(function () use ($ruang, $kode, $berlakuJam) {
            $this->cabutSemua($ruang);

            return RoomEntryLink::create([
                'school_id' => $ruang->school_id,
                'room_id' => $ruang->id,
                'slug' => $this->slugUnik($ruang),
                // Hash dengan cost bawaan. Kode ini diketik beberapa kali
                // sehari di depan pintu, jadi biayanya tidak terasa; tetapi
                // ia juga satu-satunya penjaga selain panjang tautan.
                'code_hash' => Hash::make($kode),
                'expires_at' => now()->addHours($berlakuJam),
                'use_count' => 0,
            ]);
        });

        return ['tautan' => $tautan, 'kode' => $kode];
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
     * Cocokkan kode yang diketik dengan tautan yang membawanya.
     *
     * Perhatikan urutan pemeriksaannya, karena urutan itu adalah keamanannya:
     *
     *   1. Tautannya masih berlaku? Kalau tidak, berhenti - tidak ada gunanya
     *      memeriksa kode pada tautan yang sudah mati.
     *   2. Kodenya cocok? Baru sesudah itu penghitung pemakaian naik.
     *
     * Kalau urutannya dibalik, use_count akan naik untuk percobaan yang gagal,
     * dan Admin melihat "5 pemakaian" pada tautan yang belum pernah dipakai
     * siapa pun - angka yang tidak lagi bisa dipercaya untuk pertanyaan yang
     * justru paling penting: apakah kartu pintu ini difoto orang lain.
     */
    public function cocokkan(RoomEntryLink $tautan, string $kode): bool
    {
        if (! $tautan->berlaku()) {
            return false;
        }

        if (! Hash::check($kode, $tautan->code_hash)) {
            return false;
        }

        $tautan->forceFill([
            'use_count' => $tautan->use_count + 1,
            'last_used_at' => now(),
        ])->save();

        return true;
    }

    /**
     * Kode enam digit.
     *
     * Enam digit karena itu yang bisa diketik di depan pintu sambil berdiri
     * (wireframe: "482916"). Kode yang lebih panjang lebih aman tetapi akan
     * ditulis di kertas dan ditempel di meja - dan kertas di meja adalah
     * kredensial yang jauh lebih bocor daripada kode enam digit.
     *
     * str_pad menutup satu kasus yang mudah terlewat: random_int bisa
     * mengembalikan angka di bawah 100000, dan tanpa padding kode itu menjadi
     * lima digit - panjangnya berbeda dari yang tertulis di instruksi.
     */
    private function kodeAcak(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
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

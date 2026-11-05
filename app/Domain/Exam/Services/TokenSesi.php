<?php

namespace App\Domain\Exam\Services;

use App\Domain\Exam\Enums\StatusSesi;
use App\Domain\Exam\Models\ExamSession;
use App\Domain\Exam\Models\ExamToken;
use App\Domain\Exam\Models\ExamTokenView;
use App\Domain\Identity\Models\User;
use DomainException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Penerbitan, pencabutan, dan pembacaan token sesi - PRD §9.7.
 *
 * ---------------------------------------------------------------------
 * Mengapa hash-nya HMAC dan bukan Hash::make
 * ---------------------------------------------------------------------
 * Alasan lengkapnya ada di ExamToken. Ringkasnya: siswa mengirim HANYA token,
 * sehingga token itulah kunci pencariannya - dan bcrypt bergaram, jadi
 * `where('token_hash', $token)` tidak akan pernah cocok. Yang tersisa adalah
 * memindai setiap token aktif dengan Hash::check, tepat pada saat tiga ratus
 * siswa menekan tombol mulai bersamaan.
 *
 * HMAC-SHA256 dengan APP_KEY sebagai kunci: deterministik (satu index),
 * satu arah, dan tidak bisa dihitung ulang tanpa APP_KEY.
 *
 * ---------------------------------------------------------------------
 * token_encrypted: token harus bisa dibaca kembali
 * ---------------------------------------------------------------------
 * PRD §9.7 menuntutnya, dan tanpa itu konsekuensinya tidak nyaman: token yang
 * sudah terbit tidak bisa dibaca siapa pun, termasuk Admin yang
 * menerbitkannya. Satu-satunya jalan keluar adalah mencabutnya lalu menerbitkan
 * yang baru - tepat ketika siswa sudah mengantre di depan pintu.
 *
 * Jadi ada dua representasi, dan keduanya menjawab pertanyaan berbeda:
 * `token_hash` menjawab "apakah token ini sah", `token_encrypted` menjawab
 * "token apa yang tadi saya terbitkan".
 *
 * ---------------------------------------------------------------------
 * Satu token per sesi, dan menerbitkan ulang mencabut yang lama
 * ---------------------------------------------------------------------
 * Keputusan PRD §24 #6. Menerbitkan ulang mencabut yang lama dengan alasan
 * yang sama seperti tautan pintu ruang: tanpa itu, token yang difoto siswa
 * tetap berlaku, dan tidak ada yang tahu berapa banyak token sah yang beredar
 * untuk satu sesi.
 *
 * Sesi hanya boleh punya SATU token yang tidak dicabut, meskipun tabelnya
 * mengizinkan banyak - kolom `revoked_at` yang menjaga itu, bukan constraint,
 * karena percabangan waktu (terbit, cabut, terbit lagi) tidak bisa dinyatakan
 * sebagai unique.
 */
class TokenSesi
{
    /**
     * Terbitkan token untuk satu sesi.
     *
     * @return array{token: ExamToken, kode: string}
     */
    public function terbitkan(ExamSession $sesi, ?int $olehUserId = null, ?string $kodeManual = null): array
    {
        if ($sesi->status === StatusSesi::Batal) {
            throw new DomainException("Sesi {$sesi->label()} sudah dibatalkan.");
        }

        if ($sesi->rooms()->doesntExist()) {
            throw new DomainException("Sesi {$sesi->label()} belum punya ruang.");
        }

        // Token hanya berguna bila ada orang yang memverifikasi presensi di
        // pintu. Tanpa pengawas, tokennya sah tetapi tidak ada yang membacanya.
        $tanpaPengawas = $sesi->ruangTanpaPengawas()
            ->map(fn ($r) => $r->eventRoom?->room?->code ?? 'R-?')
            ->all();

        if ($tanpaPengawas !== []) {
            throw new DomainException(
                'Ruang berikut belum punya pengawas: '.implode(', ', $tanpaPengawas).'.',
            );
        }

        $kode = $kodeManual ?? $this->kodeAcak();

        $token = DB::transaction(function () use ($sesi, $kode, $olehUserId) {
            $this->cabutSemua($sesi);

            return ExamToken::create([
                'school_id' => $sesi->school_id,
                'exam_session_id' => $sesi->id,
                'token_hash' => $this->hash($kode),
                'token_encrypted' => Crypt::encryptString($kode),

                // Jendela berlakunya mengikuti jendela sesinya. Itu bukan
                // duplikasi: token boleh terbit lebih awal dari sesinya, dan
                // valid_from yang mengikutinya berarti token yang terbit
                // pagi belum bisa dipakai sampai sesinya benar-benar dibuka.
                'valid_from' => $sesi->access_opens_at,
                'valid_until' => $sesi->hard_end_at,
                'created_by' => $olehUserId,
            ]);
        });

        return ['token' => $token, 'kode' => $kode];
    }

    /**
     * Cabut seluruh token sesi yang masih berlaku.
     */
    public function cabutSemua(ExamSession $sesi): int
    {
        return ExamToken::query()
            ->where('exam_session_id', $sesi->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function cabut(ExamToken $token): ExamToken
    {
        if ($token->revoked_at === null) {
            $token->forceFill(['revoked_at' => now()])->save();
        }

        return $token;
    }

    /**
     * Baca token dalam bentuk terbaca - dan CATAT pembacaannya.
     *
     * Pencatatannya bukan pilihan, ia bagian dari operasi ini. Anotasi plate
     * 18 menyatakannya: "setiap pembukaan token dicatat: kalau token bocor,
     * asalnya bisa ditelusuri ke satu akun, bukan berhenti pada 'entah
     * siapa'."
     *
     * Nama methodnya sengaja bukan `baca()` - ia menyebut syaratnya
     * (berwenang), karena method yang hanya bisa dipanggil oleh orang
     * berwenang lebih baik diberi nama yang mengingatkan itu.
     *
     * ---------------------------------------------------------------------
     * Token yang tidak berlaku ditolak DI SINI, bukan hanya di controller
     * ---------------------------------------------------------------------
     * Pemeriksaan yang sama ada di ExamTokenController, dan mengulanginya di
     * sini bukan kehati-hatian berlebih: method ini yang MENULIS baris
     * riwayat. Pemanggil yang lupa memeriksa lebih dulu - dan kelak ada
     * pemanggil lain, karena pengawas membuka token dari ruangnya di Fase 6 -
     * akan menghasilkan kode mati yang dibacakan pengawas ke siswa yang lalu
     * ditolak sistem, DITAMBAH baris riwayat yang mencatat pembukaan yang
     * tidak pernah terjadi. Riwayat yang memuat kejadian yang tidak ada lebih
     * buruk daripada tidak ada riwayat: ia dipakai untuk menelusuri kebocoran.
     */
    public function bacaBerwenang(
        ExamToken $token,
        ?User $oleh = null,
        ?int $roomId = null,
        ?string $pelakuLabel = null,
    ): ?string {
        if (! $token->berlaku() || $token->token_encrypted === null) {
            return null;
        }

        $kode = Crypt::decryptString($token->token_encrypted);

        ExamTokenView::create([
            'school_id' => $token->school_id,
            'exam_token_id' => $token->id,
            // Nullable sejak Fase 6, dan bukan kelonggaran: pengawas membuka
            // token dari ponselnya di depan pintu, dan ia belum tentu punya
            // akun - ia memegang tautan ruang.
            'user_id' => $oleh?->id,
            // Pelaku yang TIDAK punya akun tetap harus bisa disebut, karena
            // tanpa itu panel "Riwayat pembukaan" kehilangan satu-satunya hal
            // yang membuatnya berguna. Isinya nama pengguna, atau
            // "R-01 (tautan ruang)" bagi pemegang tautan - lihat
            // KonteksRuang::pelakuLabel().
            'pelaku_label' => $pelakuLabel ?? $oleh?->name,
            // Ruangnya dicatat bila pembacanya sedang membuka satu ruang
            // tertentu. Pengawas membuka token DARI ruangnya, dan ruang itu
            // yang membuat catatannya berguna: "dibuka Ani pukul 07:31" lebih
            // lemah daripada "dibuka Ani di R-01 pukul 07:31".
            'room_id' => $roomId,
            'viewed_at' => now(),
            'jenis' => 'dibuka',
        ]);

        return $kode;
    }

    /**
     * Verifikasi token yang dikirim siswa.
     *
     * Belum ada pemanggilnya di Fase 5: endpoint siswa adalah Fase 7. Metodenya
     * ada sekarang supaya bentuk hash-nya terbukti bisa di-query - dan supaya
     * test bisa membuktikan bahwa bcrypt memang tidak akan bekerja di sini.
     */
    public function verifikasi(string $kode): ?ExamToken
    {
        return ExamToken::query()
            ->where('token_hash', $this->hash($kode))
            ->first();
    }

    /**
     * HMAC-SHA256 dengan APP_KEY sebagai kunci.
     *
     * Dipisahkan jadi method supaya verifikasi dan penerbitan tidak bisa
     * memakai algoritma yang berbeda - dan itu bukan kekhawatiran teoretis:
     * dua tempat yang menghitung hash yang sama adalah dua tempat yang bisa
     * lupa sepakat.
     */
    public function hash(string $kode): string
    {
        return hash_hmac('sha256', $kode, (string) config('app.key'));
    }

    /**
     * Token enam digit.
     *
     * Enam digit karena itu yang dibacakan di depan pintu dan diketik siswa
     * sambil berdiri - sama seperti kode pengawas di plate 09. Kode yang lebih
     * panjang lebih aman, tetapi akan ditulis di papan tulis dan difoto, dan
     * papan tulis adalah saluran yang jauh lebih bocor daripada enam digit.
     *
     * str_pad menutup satu kasus yang mudah terlewat: random_int bisa
     * mengembalikan angka di bawah 100000, dan tanpa padding kode itu menjadi
     * lima digit - panjangnya berbeda dari yang tertulis di instruksi.
     */
    private function kodeAcak(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}

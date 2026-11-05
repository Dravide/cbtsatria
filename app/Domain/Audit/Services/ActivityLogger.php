<?php

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Models\ActivityLog;
use App\Domain\Tenancy\Services\SchoolResolver;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Satu-satunya jalur tulis untuk activity_logs.
 *
 * ---------------------------------------------------------------------
 * Mengapa panggilan eksplisit, bukan Eloquent observer
 * ---------------------------------------------------------------------
 * Observer menyembunyikan penulisan. Enam bulan kemudian, saat ada baris
 * log yang tidak seharusnya ada, pertanyaannya adalah "siapa yang menulis
 * ini" - dan observer tidak bisa menjawabnya, karena penulisnya adalah
 * setiap $model->save() di seluruh aplikasi. Panggilan eksplisit membuat
 * penyebabnya terbaca di tempat kejadian: action/service yang bersangkutan.
 *
 * Observer menyusul untuk tabel bervolume tinggi (autosave jawaban, heartbeat)
 * yang penulisannya terlalu sering untuk dipanggil manual.
 *
 * ---------------------------------------------------------------------
 * Mengapa kegagalan dicatat, bukan dilempar
 * ---------------------------------------------------------------------
 * Blueprint: "Kegagalan mengirim catatan pelanggaran tidak boleh menahan
 * penyimpanan jawaban, karena autosave jauh lebih penting daripada log."
 * Prinsipnya berlaku umum di sini. Log yang gagal ditulis tidak boleh
 * menggagalkan aksi yang sedang dicatatnya - jawaban siswa tetap tersimpan
 * meski lognya hilang. Karena itu setiap kegagalan ditelan dan dilaporkan,
 * tidak dilempar.
 */
class ActivityLogger
{
    public function __construct(
        private readonly SchoolResolver $resolver,
        private readonly AuthFactory $auth,
    ) {}

    /**
     * Catat satu aktivitas.
     *
     * @param  string  $action  Kata kerja bahasa manusia, mis. "Membuka token sesi".
     * @param  Model|null  $entity  Objek yang dikenai aksi.
     * @param  array<string, mixed>  $metadata  Konteks tambahan yang berguna saat ditelusuri.
     */
    public function log(string $action, ?Model $entity = null, array $metadata = []): ?ActivityLog
    {
        try {
            return $this->write($action, $entity, $metadata);
        } catch (Throwable $e) {
            // report(), bukan throw: aksi yang sedang dicatat tidak boleh
            // ikut gagal hanya karena jejaknya tidak sempat ditulis.
            report($e);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function write(string $action, ?Model $entity, array $metadata): ActivityLog
    {
        $user = $this->auth->guard()->user();

        return ActivityLog::create([
            'school_id' => $user?->school_id ?? $this->resolver->id() ?? $this->sekolahEntitas($entity),
            'user_id' => $user?->getKey(),
            'action' => $action,
            'entity_type' => $entity ? class_basename($entity) : null,
            'entity_id' => $entity?->getKey(),
            'metadata_json' => $metadata ?: null,
            'ip_address' => request()->ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * Sekolah yang memiliki entitas yang dicatat - jalan terakhir.
     *
     * ---------------------------------------------------------------------
     * Mengapa ini ada, dan mengapa ia jalan TERAKHIR
     * ---------------------------------------------------------------------
     * `activity_logs.school_id` NOT NULL, sementara dua sumber di atasnya
     * memang bisa kosong bersama-sama: permintaan yang datang lewat pintu
     * ruang `/p/{slug}` belum punya pengguna, dan `SchoolResolver` baru
     * ditetapkan sesudah pintunya terbuka. Akibatnya percobaan kode pengawas
     * yang SALAH - persis peristiwa yang paling perlu tercatat - berakhir
     * sebagai galat 500 alih-alih sebagai baris log.
     *
     * Yang dibaca di sini adalah `school_id` milik ENTITAS yang dicatat, dan
     * itu lebih benar daripada konteks permintaannya: yang membuat baris ini
     * berarti adalah sekolah yang memiliki tautan yang sedang ditebak
     * kodenya, bukan sekolah yang kebetulan sedang aktif di resolver.
     *
     * Ia diletakkan paling belakang dengan sengaja. Konteks permintaan tetap
     * menang bila ada, karena baris log milik aksi di dalam aplikasi memang
     * harus mengikuti sekolah yang sedang dibuka pelakunya - dan model yang
     * tidak punya `school_id` sama sekali (mis. `School`) mengembalikan null,
     * sehingga barisnya ditolak constraint dan kegagalannya ditelan
     * `log()` - bukan menggagalkan aksi yang sedang dicatat.
     */
    protected function sekolahEntitas(?Model $entity): ?int
    {
        $nilai = $entity?->getAttribute('school_id');

        return $nilai === null ? null : (int) $nilai;
    }
}

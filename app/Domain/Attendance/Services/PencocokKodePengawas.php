<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Event\Models\EventCommitteeMember;
use App\Domain\Exam\Models\ExamSessionRoom;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Collection;

/**
 * Mencocokkan kode enam angka yang diketik di pintu dengan ORANG-nya.
 *
 * =====================================================================
 * Kode menentukan orang - dan di sini kalimat itu dijalankan
 * =====================================================================
 * Blueprint `:995`: "Tautan unik per ruang ... menentukan ruang mana yang
 * dibuka ... Setelah tautan dibuka, pengawas mengetik KODE PENGAWAS enam
 * angka untuk ruang itu; KODE MENENTUKAN SIAPA YANG MASUK."
 *
 * Sampai 2026-11-07 kodenya hidup di `room_entry_links`, sehingga ia
 * menentukan RUANG, bukan orang - dan siapa pun yang memfoto kartu di daun
 * pintu mendapat tautannya sekaligus kodenya. Sejak kodenya pindah ke
 * `event_committee_members.code`, pencocokan di sinilah yang membuat
 * kalimat blueprint itu benar: hasilnya adalah ORANG, dan orang itulah yang
 * kemudian tercatat di setiap baris presensi, insiden, dan berita acara.
 *
 * ---------------------------------------------------------------------
 * Kelas ini hidup di modul Attendance, bukan di Event atau Facility
 * ---------------------------------------------------------------------
 * Yang dibutuhkan untuk mencocokkan adalah RUANG-SESI - dan hanya modul ini
 * yang memegangnya saat pintu dibuka. Kode penerbitannya tetap milik Event
 * (`PenerbitKodePengawas`), karena yang menerbitkan adalah Admin di laman
 * kepanitiaan dan ia tidak sedang berbicara tentang ruang mana pun.
 *
 * =====================================================================
 * Mengapa kandidatnya dari PENUGASAN, bukan dari seluruh sekolah
 * =====================================================================
 * Ini keputusan yang menentukan bentuk methodnya, dan ia menjawab tiga hal
 * sekaligus:
 *
 *   1. KEPUTUSAN PRODUK: yang boleh membuka ruang adalah yang DITUGASKAN di
 *      ruang-sesi itu. Menyaring lewat `supervision_assignments` berarti
 *      aturan itu tidak ditulis ulang di sini - ia dibaca dari satu-satunya
 *      tabel yang memang menyimpannya.
 *
 *   2. KODE PENGAWAS LAIN TIDAK PERNAH SAMPAI KE PERBANDINGAN. Pengawas
 *      R-02 yang berdiri di pintu R-01 tidak ada di daftar kandidat, jadi
 *      kodenya tidak pernah diperiksa - dan penolakannya bukan "kode salah"
 *      melainkan "kode ini bukan untuk pintu ini". Perilakunya sama dengan
 *      yang dijaga test sejak Fase 6; yang berpindah adalah SEBABNYA.
 *
 *   3. JUMLAH PERBANDINGAN PER PERCOBAAN TETAP SATU-DUA, bukan jumlah
 *      pengawas sekolah. Perbandingan `hash_equals` atas kode terenkripsi
 *      memang murah (dekripsi ~mikrodetik), tetapi menyaring lewat penugasan
 *      tetap benar: yang boleh masuk ruang ini memang hanya orang di daftar
 *      itu, dan penyaring itulah yang menegakkannya.
 *
 * =====================================================================
 * `whereNotNull('code')` WAJIB, dan bukan kehati-hatian berlebih
 * =====================================================================
 * Baris yang belum pernah menerima kode bernilai `code = null`, dan
 * `hash_equals($kode, null)` meledak sebagai `TypeError` - bukan `false`.
 * Tanpa penyaring itu, satu kiriman kode ke ruang yang pengawasnya belum
 * pernah menerima kode menghasilkan 500 di depan pintu kelas, di depan
 * puluhan siswa. Dan itu keadaan yang SANGAT lazim: seluruh baris
 * kepanitiaan yang lahir sebelum kolomnya ada, dan setiap pengawas baru yang
 * belum menerbitkan kodenya.
 */
class PencocokKodePengawas
{
    /**
     * Siapa pemilik kode ini di ruang-sesi ini, atau null bila tidak ada.
     *
     * Methodnya mengembalikan `?User`, bukan `bool`. Pemanggilnya bukan
     * sekadar butuh tahu "boleh masuk"; ia harus tahu SIAPA yang masuk -
     * nama itulah yang ditulis ke setiap baris presensi yang ditandatangani
     * orang ini. Pengawas dan kode yang salah sama-sama ditolak, tetapi
     * keduanya berakhir berbeda, dan method yang mengembalikan bool membuang
     * perbedaan itu di tempat yang paling membutuhkannya.
     *
     * ---------------------------------------------------------------------
     * `hash_equals`, bukan `===`
     * ---------------------------------------------------------------------
     * Kodenya kini tersimpan terenkripsi (bisa dibaca kembali), bukan
     * ter-hash - lihat `PenerbitKodePengawas`. Perbandingannya karena itu
     * dilakukan atas nilai yang sudah didekripsi cast `encrypted`, dengan
     * `hash_equals` supaya waktunya tidak bergantung pada berapa digit awal
     * yang kebetulan cocok. Untuk enam angka risikonya kecil, tetapi
     * perbandingan kredensial yang membocorkan panjang awalan yang benar
     * tidak punya alasan untuk ditulis, bahkan ketika ia murah.
     *
     * ---------------------------------------------------------------------
     * Yang SENGAJA tidak diperiksa di sini
     * ---------------------------------------------------------------------
     * Jendela sesinya, status sesinya, dan berlaku-tidaknya tautannya.
     * Ketiganya diperiksa `PintuRuangController::buka()` SESUDAH pencocokan
     * ini, dan membaginya ke dua tempat berarti aturan yang sama ditulis dua
     * kali lalu diam-diam berbeda suatu hari. Yang dijaga kelas ini sempit
     * dan tunggal: "apakah enam angka ini milik seseorang yang bertugas di
     * ruang-sesi ini".
     */
    public function cocokkan(ExamSessionRoom $ruang, string $kode): ?User
    {
        foreach ($this->kandidat($ruang) as $panitia) {
            if (hash_equals((string) $panitia->code, $kode)) {
                return $panitia->user;
            }
        }

        return null;
    }

    /**
     * Baris kepanitiaan yang boleh memiliki kode untuk ruang-sesi ini.
     *
     * Urutan kuerinya dari yang paling menyempit, dan itu bukan gaya:
     * `supervision_assignments` untuk satu ruang-sesi biasanya berisi satu
     * sampai dua baris, sehingga sisa penyaringannya dijalankan atas
     * sekumpulan id yang sudah kecil.
     *
     * `event_id` diambil dari ujian sesinya, bukan dari permintaan. Satu
     * sekolah bisa menjalankan dua event berbarengan, dan panitia event lain
     * tidak boleh punya kode yang bekerja di pintu ini - meski orangnya sama
     * dan jabatannya juga pengawas.
     *
     * @return Collection<int, EventCommitteeMember>
     */
    private function kandidat(ExamSessionRoom $ruang): Collection
    {
        $pengawas = $ruang->supervisors()->pluck('supervisor_id');

        if ($pengawas->isEmpty()) {
            return collect();
        }

        $eventId = $ruang->session?->exam?->event_id;

        if ($eventId === null) {
            return collect();
        }

        return EventCommitteeMember::query()
            ->with('user')
            ->where('event_id', $eventId)
            ->whereIn('user_id', $pengawas)
            ->where('status', 'aktif')
            ->where('position', 'like', '%pengawas%')
            ->whereNotNull('code')
            ->get();
    }
}

<?php

namespace App\Domain\Admin\Jobs;

use App\Domain\Admin\Import\PenjajarPengguna;
use App\Domain\Admin\Models\UserExport;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

/**
 * Menyiapkan satu berkas ekspor pengguna di latar belakang - PRD §19.3.
 *
 * =====================================================================
 * Mengapa antrean
 * =====================================================================
 * PRD §19.3: "Import dan ekspor berjalan melalui queue jika membutuhkan waktu
 * lama." Sekolah dengan 5.000 siswa adalah waktu yang lama - dan menyiapkannya
 * di dalam permintaan HTTP berarti Admin menatap tombol berputar untuk
 * pekerjaan yang tidak ia butuhkan untuk melanjutkan.
 *
 * =====================================================================
 * `chunkById(500)`, bukan `get()`
 * =====================================================================
 * Ini satu-satunya perbedaan BENTUK dari `BuatEksporLaporan`, dan alasannya
 * nyata: ekspor laporan Fase 8 terbatas pada satu event, sementara ekspor
 * pengguna mencakup SELURUH akun satu sekolah. `get()` atas 5.000 user beserta
 * relasi profilnya adalah 5.000 model di memori sekaligus.
 *
 * `chunkById`, bukan `chunk`: `chunk` menaikkan `offset` sementara barisnya
 * dibaca, dan itu benar asalkan tidak ada yang menyisipkan baris pada kunci
 * yang lebih kecil di tengahnya. `chunkById` tidak punya syarat itu.
 *
 * =====================================================================
 * `tries = 1`, dan galatnya DITANGKAP
 * =====================================================================
 * Disalin dari Fase 8 karena alasannya masih berlaku, dan yang kedua yang
 * paling penting: job yang melempar membiarkan barisnya berstatus `diproses`
 * selamanya, dan Admin melihat "Diproses" tanpa akhir. Yang ditangkap di sini
 * ditulis ke `pesan_gagal` dengan pesan yang bisa ia baca; jejak lengkapnya
 * tetap sampai ke log lewat report().
 */
class BuatEksporPengguna implements ShouldQueue
{
    use Queueable;

    /** Satu percobaan - lihat catatan kepala kelas. */
    public int $tries = 1;

    /** Batas waktu worker. 5.000 baris selesai jauh di bawah ini. */
    public int $timeout = 300;

    public function __construct(public readonly int $eksporId) {}

    public function handle(): void
    {
        $ekspor = UserExport::query()->withoutGlobalScopes()->find($this->eksporId);

        if ($ekspor === null || $ekspor->status === UserExport::STATUS_SELESAI) {
            return;
        }

        $ekspor->forceFill([
            'status' => UserExport::STATUS_DIPROSES,
            'started_at' => now(),
        ])->save();

        try {
            $kolom = PenjajarPengguna::judulEkspor();
            $nama = $this->namaBerkas($ekspor);
            $path = 'pengguna/'.$ekspor->school_id.'/'.$nama;

            $jumlah = $ekspor->format === 'csv'
                ? $this->tulisCsv($path, $kolom, $ekspor)
                : $this->tulisXlsx($path, $kolom, $ekspor);

            $ekspor->forceFill([
                'status' => UserExport::STATUS_SELESAI,
                'total_baris' => $jumlah,
                'path' => $path,
                'nama_berkas' => $nama,
                'ukuran' => Storage::disk('local')->size($path),
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            report($e);

            $ekspor->forceFill([
                'status' => UserExport::STATUS_GAGAL,
                'pesan_gagal' => mb_substr($e->getMessage(), 0, 500, 'UTF-8'),
                'finished_at' => now(),
            ])->save();
        }
    }

    /* ------------------------------------------------------------ Penulis */

    /**
     * @param  array<int, string>  $kolom
     */
    private function tulisXlsx(string $path, array $kolom, UserExport $ekspor): int
    {
        $buku = new Spreadsheet;
        $lembar = $buku->getActiveSheet();
        $lembar->setTitle('Pengguna');

        $lembar->fromArray($kolom, null, 'A1');

        $nomor = 2;

        $this->setiapBaris($ekspor, function (array $nilai) use ($lembar, &$nomor) {
            $lembar->fromArray($nilai, null, 'A'.$nomor);
            $nomor++;
        });

        foreach (array_keys($kolom) as $i) {
            $lembar->getColumnDimension(chr(65 + $i))->setAutoSize(true);
        }

        // Penulis XLSX menulis ke path filesystem, bukan ke disk abstrak
        // Laravel. Berkas sementara lalu dipindahkan membuat satu jalur yang
        // sama untuk kedua format.
        $sementara = tempnam(sys_get_temp_dir(), 'ekspor_pengguna_').'.xlsx';
        (new Xlsx($buku))->save($sementara);

        Storage::disk('local')->put($path, file_get_contents($sementara));

        @unlink($sementara);

        return $nomor - 2;
    }

    /**
     * @param  array<int, string>  $kolom
     */
    private function tulisCsv(string $path, array $kolom, UserExport $ekspor): int
    {
        $pegangan = fopen('php://temp', 'r+');

        // BOM UTF-8: tanpa ini Excel berbahasa Indonesia membaca nama
        // berhuruf beraksen sebagai karakter rusak, dan berkasnya terlihat
        // seperti gagal dibuat.
        fwrite($pegangan, "\xEF\xBB\xBF");
        fputcsv($pegangan, $kolom, ';', '"', '\\');

        $jumlah = 0;

        $this->setiapBaris($ekspor, function (array $nilai) use ($pegangan, &$jumlah) {
            fputcsv($pegangan, $nilai, ';', '"', '\\');
            $jumlah++;
        });

        rewind($pegangan);
        Storage::disk('local')->put($path, stream_get_contents($pegangan));
        fclose($pegangan);

        return $jumlah;
    }

    /* -------------------------------------------------------------- Baris */

    /**
     * Jalankan penulis untuk setiap baris, sebanyak satu chunk sekaligus.
     *
     * @param  callable(array<int, mixed>): void  $tulis
     */
    private function setiapBaris(UserExport $ekspor, callable $tulis): void
    {
        $kolom = PenjajarPengguna::judulEkspor();

        $this->kueri($ekspor)->chunkById(500, function ($halaman) use ($kolom, $tulis) {
            foreach ($halaman as $user) {
                $tulis($this->nilaiBaris($kolom, $user));
            }
        });
    }

    /**
     * Akun yang diekspor, dengan penyaring yang diminta Admin.
     *
     * Penyaringnya dibaca dari `parameter_json` - peran, kata kunci, status -
     * dan penerapannya SAMA PERSIS dengan `TabelQuery` di laman Pengguna,
     * supaya berkasnya memuat baris yang Admin lihat saat ia menekan Ekspor.
     */
    private function kueri(UserExport $ekspor): Builder
    {
        $saring = $ekspor->parameter_json ?? [];
        $sekolahId = (int) $ekspor->school_id;

        $role = Role::tryFrom((string) ($saring['role'] ?? ''));

        $query = User::query()
            // Ekspor berjalan di worker tanpa pengguna terautentikasi, jadi
            // scope sekolah tidak menyala - sekolahnya disebut EKSPLISIT
            // supaya hasilnya tidak bergantung pada apakah scope-nya kebetulan
            // hidup.
            ->withoutGlobalScopes()
            ->where('school_id', $sekolahId)
            ->with(['studentProfile', 'teacherProfile', 'supervisorProfile']);

        if ($role !== null) {
            $query->where('role', $role);
        }

        $cari = trim((string) ($saring['cari'] ?? ''));

        if ($cari !== '') {
            $query->where(fn (Builder $q) => $q
                ->orWhere('name', 'like', '%'.$cari.'%')
                ->orWhere('username', 'like', '%'.$cari.'%')
                ->orWhere('email', 'like', '%'.$cari.'%'));
        }

        $status = (string) ($saring['status'] ?? '');

        if ($status !== '') {
            $query->where('is_active', $status === '1');
        }

        // -----------------------------------------------------------------
        // TIDAK ada `orderBy('name')` di sini, dan itu bukan kelalaian
        // -----------------------------------------------------------------
        // Ini satu-satunya urutan yang benar, dan alasannya halus sampai
        // terlihat seperti bug orang lain.
        //
        // `chunkById` berpaginasi dengan KEYSET pada `id` - `WHERE id > ?`
        // - bukan dengan `OFFSET`. Paginasi keyset hanya benar bila barisnya
        // diurutkan menurut kolom kunci itu. `forPageAfterId()` membuang
        // hanya `ORDER BY` yang menyebut `id`, lalu MENAMBAHKAN
        // `ORDER BY id ASC` di belakangnya; `ORDER BY name, id` di sini akan
        // bertahan di depan, sehingga `lastId` diambil dari baris terakhir
        // menurut NAMA sementara `WHERE id > lastId` membuang baris menurut
        // ID. Setiap halaman akan MELOMPATI baris yang namanya jatuh sesudah
        // nama terakhir halaman sebelumnya tetapi id-nya lebih kecil.
        //
        // Gejalanya adalah berkas yang lebih pendek dari yang seharusnya,
        // tanpa galat apa pun. Untuk sekolah dengan 1.284 siswa, itu berarti
        // puluhan akun hilang dari daftar - dan baru ketahuan saat ada siswa
        // yang mengaku tidak punya akun padahal ia ada di Excel.
        //
        // Urutan `id` = urutan akun dibuat, dan itu urutan yang stabil.
        // Admin yang ingin berkasnya berurut nama cukup menekan Sort di
        // Excel - satu klik, dan itu harga yang jauh lebih murah daripada
        // daftar yang diam-diam tidak lengkap.
        return $query->orderBy('id');
    }

    /**
     * Satu akun sebagai daftar nilai, berurutan sesuai kepala kolom.
     *
     * Urutannya WAJIB sama dengan `judulEkspor()`, dan karena keduanya membaca
     * daftar kolom yang sama, menambahkan satu kolom di satu tempat tidak bisa
     * membuat keduanya bergeser.
     *
     * @param  array<int, string>  $kolom
     * @return array<int, mixed>
     */
    private function nilaiBaris(array $kolom, User $user): array
    {
        $profil = match ($user->role) {
            Role::Student => $user->studentProfile,
            Role::Teacher => $user->teacherProfile,
            Role::Supervisor => $user->supervisorProfile,
            Role::Admin => null,
        };

        $nilai = [
            'username' => $user->username,
            'peran' => $user->role->value,
            'nama' => $user->name,
            'nis' => $profil?->nis,
            'nisn' => $profil?->nisn,
            'nip' => $profil?->nip,
            'kode_pegawai' => $profil?->staff_code,
            // Kelas sengaja KOSONG di ekspor. Ia milik tahun ajaran, bukan
            // milik akun, dan menuliskan kelas tahun ini ke berkas yang akan
            // diimpor tahun depan adalah cara paling halus untuk memindahkan
            // seluruh siswa ke kelas yang salah.
            'kelas' => null,
            'email' => $user->email,
            'telepon' => $user->phone,
            'jenis_kelamin' => $profil?->gender,
            'alamat' => $profil?->address,
            'aktif' => $user->is_active ? 'ya' : 'tidak',
        ];

        $hasil = [];

        foreach ($kolom as $kunci) {
            // Null ditulis sebagai sel KOSONG, bukan string "null" - dan
            // bukan 0. Sel kosong pada impor berarti "jangan ubah", sehingga
            // kolom yang memang kosong tidak menghapus apa pun saat berkasnya
            // diunggah kembali.
            $hasil[] = $nilai[$kunci] ?? null;
        }

        return $hasil;
    }

    private function namaBerkas(UserExport $ekspor): string
    {
        $peran = (string) ($ekspor->parameter_json['role'] ?? 'pengguna');

        // Nama acak di DEPAN, nama nyaman di belakang: berkas di disk privat
        // tidak bisa ditebak, tetapi yang sudah diunduh Admin tetap bernama
        // jelas di folder unduhannya.
        return Str::random(20).'-pengguna-'.Str::slug($peran).'.'.$ekspor->format;
    }
}

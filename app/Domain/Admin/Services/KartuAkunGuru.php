<?php

namespace App\Domain\Admin\Services;

use App\Domain\Academic\Models\TeachingAssignment;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Support\Cetak\KopSekolah;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Kartu akun guru sebagai PDF siap cetak - satu kartu per guru.
 *
 * =====================================================================
 * Mengapa satu kartu per GURU, bukan satu kartu per mapel
 * =====================================================================
 * Yang dicetak adalah kredensial MASUK, dan kredensialnya milik ORANG, bukan
 * milik penugasan. Guru yang mengampu tiga mapel tetap punya satu username
 * dan satu sandi. Mencetak tiga kartu berisi kredensial yang sama berarti
 * tiga kertas yang bisa tertukar, terselip, dan satu di antaranya hilang
 * tanpa ada yang tahu kartu mana yang masih beredar.
 *
 * Karena itu mapelnya ditulis DI DALAM kartu: satu lembar menjawab dua
 * pertanyaan sekaligus - "bagaimana masuk" dan "mengajar apa" - dan
 * keduanya pertanyaan pertama guru baru di hari pertama.
 *
 * =====================================================================
 * Sandi hanya dicetak bila MASIH sandi bawaan
 * =====================================================================
 * Kartu yang mencetak "password" pada akun yang sandinya sudah diganti
 * adalah kartu yang menyesatkan guru untuk memakai sandi yang salah, lalu
 * menyalahkan sistemnya. Pemeriksaannya `Hash::check` - sumber yang sama
 * dengan peringatan di laman Akun siswa, sehingga kartu dan layar tidak bisa
 * berselisih tentang apakah sandi seseorang masih bawaan.
 *
 * =====================================================================
 * Biaya `Hash::check`, dan mengapa tetap diterima
 * =====================================================================
 * Satu pemeriksaan bcrypt sekitar 220 ms di mesin pengembangan. Tiga puluh
 * enam guru berarti tujuh detik untuk satu unduhan ZIP - dan itu terasa,
 * tetapi masih di bawah batas waktu satu permintaan web, dan hanya terjadi
 * pada tindakan yang memang disengaja (mencetak seluruh kartu). Alternatifnya
 * - menyimpan penanda "sandi belum diganti" di kolom tersendiri - menambah
 * satu keadaan yang harus dijaga tiga produsen sandi (buat, reset, impor),
 * dan penanda yang meleset adalah kartu yang membocorkan sandi orang.
 */
class KartuAkunGuru
{
    public const UKURAN = 'a4';

    /**
     * Data kartu untuk sekumpulan guru, siap dirender.
     *
     * Satu kueri penugasan untuk SELURUH guru yang diminta, bukan satu per
     * guru: tiga puluh guru berarti tiga puluh kueri yang isinya sama-sama
     * satu tabel kecil.
     *
     * @param  Collection<int, User>  $guru
     * @return array<int, array<string, mixed>>
     */
    public function susun(Collection $guru): array
    {
        if ($guru->isEmpty()) {
            return [];
        }

        $penugasan = TeachingAssignment::query()
            ->whereIn('teacher_id', $guru->pluck('id'))
            ->with(['subject:id,code,name', 'kelas:id,name'])
            ->get()
            ->groupBy('teacher_id');

        return $guru->map(fn (User $u) => [
            'nama' => $u->name,
            'nip' => $u->teacherProfile?->nip,
            'username' => $u->username,
            // `null` berarti "jangan cetak sandi" - bukan "sandi kosong".
            'sandi' => Hash::check(User::SANDI_BAWAAN, (string) $u->password)
                ? User::SANDI_BAWAAN
                : null,
            'mapel' => $this->mapel($penugasan->get($u->id, collect())),
        ])->values()->all();
    }

    /**
     * Satu kartu satu PDF, satu halaman.
     *
     * @param  array<string, mixed>  $satu
     * @param  array{nama: string, npsn: string|null, logo: string|null}  $sekolah
     */
    public function pdf(array $satu, array $sekolah): string
    {
        return Pdf::loadView('kartu.akun-guru', [
            'sekolah' => $sekolah,
            'kartu' => $satu,
        ])->setPaper(self::UKURAN, 'portrait')->output();
    }

    /**
     * Seluruh kartu sebagai satu ZIP - satu berkas PDF per guru.
     *
     * Ditulis ke berkas SEMENTARA di disk, bukan dirakit di memori: lima
     * ratus guru berarti lima ratus PDF yang kalau ditampung seluruhnya
     * sekaligus menghabiskan memori proses web - dan yang gagal adalah
     * unduhan yang sedang ditunggu Admin, bukan halaman lain.
     *
     * @param  array<int, array<string, mixed>>  $kartu
     * @param  array{nama: string, npsn: string|null, logo: string|null}  $sekolah
     * @return string Path berkas ZIP. Pemanggil yang menghapusnya.
     */
    public function zip(array $kartu, array $sekolah): string
    {
        $jalur = (string) tempnam(sys_get_temp_dir(), 'kartu-akun-');

        $zip = new ZipArchive;

        if ($zip->open($jalur, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Arsip kartu tidak bisa dibuat.');
        }

        $dipakai = [];

        foreach ($kartu as $satu) {
            // Dua guru bisa bernama sama dan bernomor sama hanya bila salah
            // satunya salah ketik - tetapi nama berkas yang bertabrakan
            // berarti PDF yang KEDUA menghapus yang pertama, dan yang
            // terunduh hanya satu. Akhiran angka menutupnya.
            $nama = $this->namaBerkas($satu);
            $n = 1;

            while (isset($dipakai[$nama])) {
                $n++;
                $nama = $this->namaBerkas($satu, $n);
            }

            $dipakai[$nama] = true;
            $zip->addFromString($nama, $this->pdf($satu, $sekolah));
        }

        $zip->close();

        return $jalur;
    }

    /**
     * Nama berkas satu kartu: `nama_nip.pdf`, atau `nama_username.pdf`.
     *
     * Nomor identitas lebih dulu karena itulah yang tertulis di kartu dan di
     * daftar absen sekolah; username dipakai sebagai cadangan untuk guru yang
     * profilnya belum lengkap. Tanpa cadangan itu, guru tanpa NIP mendapat
     * berkas bernama `nama_.pdf` - nama yang tidak bisa dibedakan dari nama
     * guru lain yang juga belum punya NIP.
     *
     * @param  array<string, mixed>  $satu
     */
    public function namaBerkas(array $satu, int $urut = 1): string
    {
        $nomor = $satu['nip'] ?? null;

        if ($nomor === null || trim((string) $nomor) === '') {
            $nomor = $satu['username'] ?? null;
        }

        $nama = Str::slug((string) $satu['nama']) ?: 'guru';
        $nomor = Str::slug((string) $nomor) ?: 'tanpa-nomor';

        return $nama.'_'.$nomor.($urut > 1 ? '_'.$urut : '').'.pdf';
    }

    /**
     * Mapel yang diampu, dikelompokkan per mapel.
     *
     * @param  Collection<int, TeachingAssignment>  $penugasan
     * @return array<int, array{nama: string, kode: string|null, kelas: array<int, string>}>
     */
    private function mapel(Collection $penugasan): array
    {
        return $penugasan
            ->filter(fn (TeachingAssignment $p) => $p->subject !== null)
            ->groupBy('subject_id')
            ->map(fn (Collection $baris) => [
                'nama' => (string) $baris->first()->subject?->name,
                'kode' => $baris->first()->subject?->code,
                'kelas' => $baris
                    ->map(fn (TeachingAssignment $p) => $p->kelas?->name)
                    ->filter()
                    ->unique()
                    ->sort()
                    ->values()
                    ->all(),
            ])
            ->sortBy('nama')
            ->values()
            ->all();
    }

    /**
     * Kop sekolah untuk seluruh kartu dalam satu permintaan.
     *
     * @return array{nama: string, npsn: string|null, logo: string|null}
     */
    public function kop(?School $sekolah): array
    {
        return KopSekolah::dari($sekolah);
    }
}

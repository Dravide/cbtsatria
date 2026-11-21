<?php

namespace App\Domain\Admin\Services;

use App\Domain\Academic\Models\SchoolClass;
use App\Domain\Tenancy\Models\School;
use App\Support\Cetak\KopSekolah;
use App\Support\Qr\KodeQr;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Kartu pantau wali kelas sebagai PDF siap cetak - satu kartu per kelas.
 *
 * Setiap kartu memuat identitas kelas, kode akses 6 digit, URL laman pantau
 * wali (`/wali/{papan_slug}/kelas/{id}`), dan QR code untuk dipindai.
 * Bentuknya mengikuti `KartuAkunGuru` (120mm) agar proporsional saat dicetak
 * maupun dibagikan langsung ke wali kelas lewat WhatsApp.
 */
class KartuWaliKelas
{
    public const LEBAR_MM = 120;

    public const POIN_PER_MM = 2.83465;

    /**
     * Data kartu untuk sekumpulan kelas, siap dirender.
     *
     * @param  Collection<int, SchoolClass>  $kelas
     * @return array<int, array<string, mixed>>
     */
    public function susun(Collection $kelas, ?string $papanSlug): array
    {
        if ($kelas->isEmpty()) {
            return [];
        }

        return $kelas->map(function (SchoolClass $k) use ($papanSlug): array {
            $tayang = $k->wali_slug !== null;
            $url = ($tayang && $papanSlug !== null)
                ? url("/wali/{$papanSlug}/kelas/{$k->id}")
                : null;

            return [
                'id' => $k->id,
                'nama' => $k->name,
                'tingkat' => $k->grade_level,
                'tahun' => $k->academicYear?->name,
                'wali' => $k->homeroomTeacher?->name,
                'kode' => $k->wali_code,
                'tayang' => $tayang,
                'url' => $url,
                'qr' => $url !== null ? KodeQr::dataUri($url, 480) : null,
            ];
        })->values()->all();
    }

    /**
     * Satu kartu satu PDF, satu halaman.
     *
     * @param  array<string, mixed>  $satu
     * @param  array{nama: string, npsn: string|null, logo: string|null}  $sekolah
     */
    public function pdf(array $satu, array $sekolah): string
    {
        $html = view('kartu.wali-kelas', [
            'sekolah' => $sekolah,
            'kartu' => $satu,
        ])->render();

        $tinggiMm = $satu['qr'] !== null ? 172 : 128;
        $lebarPt = round(self::LEBAR_MM * self::POIN_PER_MM, 2);

        for ($coba = 0; $coba < 3; $coba++) {
            $tinggiPt = round($tinggiMm * self::POIN_PER_MM, 2);
            $pdf = Pdf::loadHTML($html)->setPaper([0, 0, $lebarPt, $tinggiPt]);

            $dompdf = $pdf->getDomPDF();
            $dompdf->render();

            if ($dompdf->getCanvas()->get_page_count() <= 1) {
                return $dompdf->output();
            }

            $tinggiMm += 22;
        }

        return $pdf->output();
    }

    /**
     * Seluruh kartu sebagai satu ZIP - satu berkas PDF per kelas.
     *
     * @param  array<int, array<string, mixed>>  $kartu
     * @param  array{nama: string, npsn: string|null, logo: string|null}  $sekolah
     * @return string Path berkas ZIP. Pemanggil yang menghapusnya.
     */
    public function zip(array $kartu, array $sekolah): string
    {
        $jalur = (string) tempnam(sys_get_temp_dir(), 'kartu-wali-kelas-');

        $zip = new ZipArchive;

        if ($zip->open($jalur, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Arsip kartu kelas tidak bisa dibuat.');
        }

        $dipakai = [];

        foreach ($kartu as $satu) {
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
     * Nama berkas satu kartu: `kartu-wali_{nama-kelas}.pdf`.
     *
     * @param  array<string, mixed>  $satu
     */
    public function namaBerkas(array $satu, int $urut = 1): string
    {
        $nama = Str::slug((string) ($satu['nama'] ?? '')) ?: 'kelas';

        return 'kartu-wali_'.$nama.($urut > 1 ? '_'.$urut : '').'.pdf';
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

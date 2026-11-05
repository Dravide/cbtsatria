<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Enums\PeranTandaTangan;
use App\Domain\Attendance\Enums\SisiFoto;
use App\Domain\Attendance\Models\SessionReport;
use App\Domain\Attendance\Models\SessionReportFile;
use App\Domain\Attendance\Models\SessionReportSignature;
use App\Domain\Exam\Models\ExamSessionRoom;
use Carbon\Carbon;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Berita acara ruang-sesi - PRD §12, wireframe plate 18.
 *
 * =====================================================================
 * Berkas disimpan di DISK PRIVAT, dan itu keputusan yang dikunci
 * =====================================================================
 * Foto ruang ujian adalah BUKTI, bukan aset publik. Foto itu memuat wajah
 * anak-anak, papan tulis, dan tata letak ruangan; membiarkannya di
 * `storage/app/public` berarti setiap orang yang pernah mengetahui satu URL
 * dapat membukanya selamanya - dan URL yang pernah dibuka tersimpan di
 * riwayat peramban, di log proxy, dan di pesan yang dikirim ke grup guru.
 *
 * Karena itu:
 *
 *   - berkasnya ditulis ke disk `local` (`storage/app/private`), di luar
 *     jangkauan web server;
 *   - TIDAK ada symlink `public/storage`, dan itu disengaja;
 *   - yang membacanya hanya rute `/laporan/{berkas}` yang memeriksa
 *     KonteksRuang lagi - sehingga mencabut tautan ruang mencabut fotonya
 *     juga, tanpa kode tambahan.
 *
 * ---------------------------------------------------------------------
 * Nama berkas di disk diacak, nama aslinya disimpan terpisah
 * ---------------------------------------------------------------------
 * Nama yang diunggah peramban memuat apa saja - nama siswa, nomor induk,
 * judul berkas dari laporan lain yang keliru dipilih. Menyimpannya sebagai
 * nama di disk berarti setiap nama itu menjadi jalur yang bisa ditebak.
 * Karena itu yang tersimpan di disk adalah `Str::random(40)`, dan nama asli
 * hanya dipakai saat berkasnya diunduh.
 *
 * ---------------------------------------------------------------------
 * Draf tersimpan otomatis
 * ---------------------------------------------------------------------
 * Tidak ada tombol Simpan. Setiap unggahan foto dan setiap tanda tangan
 * menulis langsung sebagai draf. Alasannya bukan kemalasan: pengawas
 * mengerjakan ini di depan pintu kelas, di sela memanggil nama, dari
 * ponsel - dan satu-satunya pekerjaan yang bisa hilang adalah pekerjaan
 * yang tidak diberi tombol simpan.
 */
class LaporanSesiService
{
    /** Ukuran maksimum satu berkas foto. */
    public const MAKS_KB = 5120;

    /** @return array<int, string> */
    public static function mimeDiizinkan(): array
    {
        return ['image/jpeg', 'image/png', 'image/webp'];
    }

    /**
     * Berita acara satu ruang-sesi - dibuat bila belum ada.
     *
     * Dipanggil saat laman berita acara dibuka, bukan saat sesuatu
     * diunggah. Bedanya terlihat: laman yang dibuka lalu ditinggalkan tetap
     * meninggalkan draf kosong, dan Admin yang menunggu laporan tahu ruang
     * mana yang sudah dibuka pengawasnya - informasi yang justru berguna di
     * hari-H.
     */
    public function untuk(ExamSessionRoom $ruang): SessionReport
    {
        return SessionReport::query()->firstOrCreate(
            [
                'school_id' => $ruang->school_id,
                'exam_session_room_id' => $ruang->id,
            ],
            [
                'status' => SessionReport::STATUS_DRAFT,
                'pernyataan_presensi' => false,
                'pernyataan_keluar' => false,
            ],
        );
    }

    /**
     * Simpan foto satu sisi.
     *
     * Foto lama pada sisi yang sama digantikan, bukan ditumpuk: foto ketiga
     * dari ruang yang sama tidak menambah bukti apa pun. Berkas lamanya
     * dihapus dari disk supaya berita acara yang disunting berkali-kali
     * tidak meninggalkan berkas yatim yang tidak pernah bisa ditemukan lagi.
     *
     * @param  int|null  $diambilPada  Unix timestamp dari File.lastModified.
     */
    public function simpanFoto(
        SessionReport $laporan,
        SisiFoto $sisi,
        UploadedFile $berkas,
        ?int $diambilPada = null,
    ): SessionReportFile {
        $this->pastikanDraf($laporan);

        if (! in_array($berkas->getMimeType(), self::mimeDiizinkan(), true)) {
            throw new DomainException('Berkas harus berupa gambar (JPG, PNG, atau WEBP).');
        }

        return DB::transaction(function () use ($laporan, $sisi, $berkas, $diambilPada) {
            $lama = $laporan->berkas()->where('sisi', $sisi->value)->first();

            if ($lama !== null) {
                Storage::disk('local')->delete($lama->path);
                $lama->delete();
            }

            $nama = Str::random(40).'.'.$berkas->getClientOriginalExtension();
            $path = "laporan/{$laporan->school_id}/{$laporan->id}/{$nama}";

            Storage::disk('local')->put($path, $berkas->get());

            return SessionReportFile::create([
                'school_id' => $laporan->school_id,
                'session_report_id' => $laporan->id,
                'sisi' => $sisi,
                'path' => $path,
                'nama_asli' => $berkas->getClientOriginalName(),
                'mime' => $berkas->getMimeType(),
                'ukuran' => $berkas->getSize(),
                // Dua waktu, dan bedanya yang membuat plate 18 benar:
                // "foto yang dipilih dari galeri setelah ujian tetap
                // tercatat kapan diunggah".
                'diambil_pada' => $diambilPada !== null
                    ? Carbon::createFromTimestamp($diambilPada)
                    : null,
                'diunggah_pada' => now(),
            ]);
        });
    }

    /**
     * Simpan tanda tangan - PNG dari kanvas ponsel.
     *
     * Berkasnya menempuh jalur yang sama dengan foto: disk privat, nama
     * acak, dibaca lewat rute berwenang. Tanda tangan yang bocor sama
     * merugikannya dengan foto.
     *
     * `nama` disimpan di barisnya, bukan hanya ditunjuk lewat akun:
     * berita acara harus tetap terbaca lima tahun kemudian, dan akun
     * pengawas yang sudah dinonaktifkan tidak boleh membuat tanda tangannya
     * kehilangan pemiliknya.
     */
    public function simpanTandaTangan(
        SessionReport $laporan,
        PeranTandaTangan $peran,
        string $nama,
        string $dataUrl,
        KonteksRuang $konteks,
    ): SessionReportSignature {
        $this->pastikanDraf($laporan);

        $png = $this->uraiDataUrl($dataUrl);

        if ($png === null) {
            throw new DomainException('Tanda tangan tidak terbaca. Coba gambar ulang.');
        }

        return DB::transaction(function () use ($laporan, $peran, $nama, $png, $konteks) {
            $lama = $laporan->tandaTangan()->where('peran', $peran->value)->first();

            if ($lama !== null) {
                Storage::disk('local')->delete($lama->path);
                $lama->delete();
            }

            $path = "laporan/{$laporan->school_id}/{$laporan->id}/ttd-{$peran->value}-".Str::random(24).'.png';

            Storage::disk('local')->put($path, $png);

            return SessionReportSignature::create([
                'school_id' => $laporan->school_id,
                'session_report_id' => $laporan->id,
                'peran' => $peran,
                'nama' => $nama,
                'path' => $path,
                'ditandatangani_pada' => now(),
                'ditandatangani_oleh' => $konteks->penggunaId(),
                'pelaku_label' => $konteks->pelakuLabel(),
            ]);
        });
    }

    /** Simpan catatan dan kedua pernyataan - bagian draf yang tersimpan otomatis. */
    public function simpanCatatan(
        SessionReport $laporan,
        ?string $catatan,
        bool $pernyataanPresensi,
        bool $pernyataanKeluar,
    ): SessionReport {
        $this->pastikanDraf($laporan);

        $laporan->forceFill([
            'catatan' => $catatan,
            'pernyataan_presensi' => $pernyataanPresensi,
            'pernyataan_keluar' => $pernyataanKeluar,
        ])->save();

        return $laporan;
    }

    /**
     * Kirim final.
     *
     * Ditolak selama syaratnya belum lengkap, dan penolakannya menyebut APA
     * yang kurang - bukan sekadar "belum lengkap". Daftar alasannya berasal
     * dari `kekuranganFinal()` di modelnya, supaya aturan dan pesannya tidak
     * pernah berbeda.
     */
    public function kirim(SessionReport $laporan, KonteksRuang $konteks): SessionReport
    {
        $kurang = $laporan->fresh()->kekuranganFinal();

        if ($kurang !== []) {
            throw new DomainException('Belum bisa dikirim: '.implode(' ', $kurang));
        }

        $laporan->forceFill([
            'status' => SessionReport::STATUS_FINAL,
            'submitted_at' => now(),
            'dibuat_oleh' => $konteks->penggunaId(),
            'pelaku_label' => $konteks->pelakuLabel(),
        ])->save();

        return $laporan;
    }

    /**
     * Buka kembali laporan yang sudah final.
     *
     * Ada, dan disengaja: berita acara yang salah tulis dan tidak bisa
     * diperbaiki akan memaksa pengawas mengarang laporan baru di atas
     * kertas. Yang dijaga bukan ketidakbisaan mengubahnya, melainkan
     * tercatatnya perubahan itu.
     */
    public function bukaKembali(SessionReport $laporan): SessionReport
    {
        $laporan->forceFill([
            'status' => SessionReport::STATUS_DRAFT,
            'submitted_at' => null,
        ])->save();

        return $laporan;
    }

    /**
     * Laporan yang sudah difinalkan tidak bisa diubah isinya.
     *
     * Tanpa pemeriksaan ini, seluruh janji "draf tersimpan otomatis" berubah
     * menjadi lubang: berkas yang sudah dikirim masih bisa ditukar, dan
     * laporan yang dibaca Admin tidak lagi sama dengan yang dikirim
     * pengawas.
     */
    private function pastikanDraf(SessionReport $laporan): void
    {
        if ($laporan->final()) {
            throw new DomainException(
                'Berita acara sudah dikirim. Buka kembali dulu bila ada yang perlu diperbaiki.',
            );
        }
    }

    /** Urai data URL kanvas menjadi byte PNG; null bila bukan PNG yang sah. */
    private function uraiDataUrl(string $dataUrl): ?string
    {
        if (! preg_match('#^data:image/png;base64,#', $dataUrl)) {
            return null;
        }

        $isi = base64_decode(substr($dataUrl, strlen('data:image/png;base64,')), true);

        return $isi === false || $isi === '' ? null : $isi;
    }
}

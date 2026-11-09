<?php

namespace App\Domain\Attendance\Http\Controllers;

use App\Domain\Attendance\Models\ExamIncident;
use App\Domain\Attendance\Services\KonteksRuang;
use App\Domain\Exam\Enums\StatusSesi;
use App\Domain\Exam\Models\ExamSessionRoom;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Beranda pengawas - wireframe plate 11, `/pengawas`.
 *
 * ---------------------------------------------------------------------
 * Mengapa daftarnya datang dari KonteksRuang, bukan dari query di sini
 * ---------------------------------------------------------------------
 * Ada dua pintu ke laman ini, dan keduanya menghasilkan daftar yang
 * BERBEDA dengan aturan yang sama:
 *
 *   lewat akun   - setiap ruang-sesi yang ditugaskan kepadanya (Admin:
 *                  seluruh ruang di sekolahnya);
 *   lewat tautan - TEPAT SATU kartu, yaitu ruang yang tautannya ia pegang.
 *
 * Menuliskan keduanya di controller berarti dua tempat yang bisa berbeda
 * diam-diam, dan perbedaannya akan berupa ruang yang tampil untuk orang
 * yang tidak berhak. Karena itu `ruangTersedia()` yang menjawab - dan
 * jawabannya berasal dari bentuk datanya sendiri, bukan dari pemeriksaan
 * tambahan di sini.
 *
 * ---------------------------------------------------------------------
 * Tombol yang belum bisa ditekan diberi ALASAN, bukan disembunyikan
 * ---------------------------------------------------------------------
 * Plate 11 menggambarnya begitu: kartu R-02 punya tiga tombol nonaktif dan
 * satu keterangan "Sesi R-02 belum dibuka." Pengawas yang menemukan tombol
 * mati tanpa penjelasan akan menekannya berulang kali lalu menelepon Admin.
 */
class RuangTugasController
{
    public function index(): Response
    {
        $ruang = KonteksRuang::ruangTersedia();

        $data = $ruang->map(function (ExamSessionRoom $r) {
            $sesi = $r->session;
            $sekarang = now();

            // Empat syarat tombolnya aktif, dan urutannya yang menentukan
            // pesan mana yang muncul - sebab pesan yang salah membuat
            // pengawas menunggu hal yang tidak akan terjadi.
            $alasan = match (true) {
                $sesi === null => 'Ruang ini belum dipakai sesi mana pun.',
                in_array($sesi->status, [StatusSesi::Selesai, StatusSesi::Batal], true) => 'Sesi ini sudah '.$sesi->status->label().'.',
                $sekarang->lt($sesi->access_opens_at) => 'Sesi ini belum dibuka. Akses mulai pukul '.$sesi->access_opens_at->format('H:i').'.',
                $sekarang->gt($sesi->hard_end_at) => 'Sesi ini sudah berakhir pukul '.$sesi->hard_end_at->format('H:i').'.',
                default => null,
            };

            // Jumlah insiden terbuka ditampilkan di kartunya supaya pengawas
            // tahu ada yang belum ditangani tanpa harus membuka laman lain.
            $insiden = $sesi === null ? 0 : ExamIncident::query()
                ->where('exam_session_room_id', $r->id)
                ->where('status', ExamIncident::STATUS_TERBUKA)
                ->count();

            return [
                'ruang_id' => $r->id,
                'ruang' => $r->eventRoom?->room?->code ?? 'R-?',
                'ruang_nama' => $r->eventRoom?->room?->name,
                'lokasi' => $r->eventRoom?->room?->location,

                'sesi_id' => $sesi?->id,
                'sesi' => $sesi?->label(),
                'ujian' => $sesi?->exam?->title,
                'jam' => $sesi === null
                    ? null
                    : $sesi->access_opens_at->format('H:i').'–'.$sesi->hard_end_at->format('H:i'),
                'status_sesi' => $sesi?->status->value,
                'status_label' => $sesi?->status->label(),

                'peserta' => $r->participants()->count(),
                'kapasitas' => $r->kapasitasEfektif(),
                'insiden_terbuka' => $insiden,

                'boleh_buka' => $alasan === null,
                'alasan_tolak' => $alasan,
            ];
        })->values()->all();

        return Inertia::render('Pengawas/RuangTugas', [
            'ruang' => $data,

            // Jenis pelaku dikirim supaya kepalanya bisa menyebut "tautan
            // ruang" pada chip plate 10 - dan kalimat "Anda masuk lewat
            // tautan, jadi hanya ruang ini yang tampil" hanya ditampilkan
            // kepada pemegang tautan, bukan kepada pengawas berakun yang
            // memang punya banyak ruang.
            //
            // Ia menjawab "lewat PINTU MANA Anda masuk", bukan "siapa Anda" -
            // dan sejak kodenya milik orang, pertanyaan kedua punya
            // jawabannya sendiri di bawah. Keduanya tetap dikirim: pemegang
            // tautan yang namanya terbaca tetap hanya melihat satu ruang.
            'pelaku' => KonteksRuang::tautanSesi() !== null ? 'tautan' : 'akun',

            // Nama pengawas yang membuka pintu ini, bila masuknya lewat kode
            // pengawas. Sejak 2026-11-07 nilainya memang ada - kodenya
            // menempel pada orang, sehingga "data pengawasnya langsung ada"
            // bukan lagi sesuatu yang harus dikarang dari nama ruang.
            //
            // null bagi yang masuk lewat akun, dan bagi pemegang tautan yang
            // baris kepanitiaannya sudah hilang di tengah sesi.
            'pengawas' => KonteksRuang::penggunaSesi()?->name,
        ]);
    }
}

<?php

namespace App\Domain\Event\Http\Controllers;

use App\Domain\Event\Contracts\ButirSoalPerMapel;
use App\Domain\Event\Models\SchoolEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Soal per mata pelajaran - ditambahkan 2026-11-14.
 *
 * =====================================================================
 * Mengapa laman ini ADA, padahal paket soal sudah ditampilkan
 * =====================================================================
 * `EventSubjectController` menjawab dua angka: paket mana terpasang, dan
 * berapa butirnya. Ia tidak pernah menjawab pertanyaan yang sebenarnya
 * dibawa Admin ke sana: soal APA yang akan dikerjakan 240 anak.
 *
 * Akibatnya terlihat pada urutan kerja, bukan pada layar: Admin menandai
 * event Siap, membuka sesi, dan mencetak tablo - tanpa sekali pun melihat isi
 * paket yang ia setujui. Yang baru diketahuinya adalah keluhan sesudah ujian:
 * soal yang kuncinya hilang, soal yang sudah diarsipkan tetapi masih dipakai,
 * atau butir yang jumlahnya bukan yang ia kira.
 *
 * =====================================================================
 * Baca saja, dan itu batas modul yang terlihat di layar
 * =====================================================================
 * Soal adalah aset sekolah yang disunting Guru pemiliknya lewat bank soal;
 * Admin tidak punya izin tulis di sana. Laman ini karena itu TIDAK punya satu
 * pun tombol yang mengubah soal - yang tersedia hanya jalan ke bank soal.
 * Menaruh tombol yang server tolak (403) adalah tombol yang mengajari Admin
 * untuk tidak memercayai layarnya.
 *
 * =====================================================================
 * Penyusunannya lewat KONTRAK, bukan service Exam
 * =====================================================================
 * Yang dibaca laman ini adalah `exam_questions` (milik Exam) dan `questions`
 * (milik Question). Modul Event tidak boleh mengimpor model keduanya - peta
 * modul mencatat `Event -> Exam` sebagai arah yang tidak ada, dan
 * ketiadaannya yang membuat modul Event bisa diuji tanpa menjalankan ujian.
 *
 * Karena itu controller ini hanya memanggil `ButirSoalPerMapel`, dan yang
 * kembali adalah skalar. Bentuknya meniru `ButirKesiapanUjian` - lihat
 * docblock kontraknya.
 */
class EventSoalController
{
    public function index(Request $request, SchoolEvent $event, ButirSoalPerMapel $soal): Response
    {
        Gate::authorize('view', $event);

        $isi = $soal->susun($event);

        // Penyaring hanya berlaku pada KELOMPOK, tidak pada angka: KPI di
        // atas laman menjawab "seberapa besar event ini", dan angka yang
        // ikut menyusut saat Admin mencari satu kata berhenti menjawab
        // pertanyaan itu.
        $cari = trim((string) $request->query('cari', ''));
        $jenis = (string) $request->query('jenis', '');
        $tingkat = (string) $request->query('tingkat', '');

        $kelompok = collect($isi['kelompok'])
            ->map(function (array $k) use ($cari, $jenis, $tingkat) {
                // Baris disaring lebih dulu, lalu kelompok yang seluruh
                // barisnya tersaring keluar DIBUANG. Kelompok kosong yang
                // tetap tampil terbaca sebagai "mapel ini tidak punya soal",
                // padahal yang terjadi adalah "tidak ada yang cocok".
                $baris = collect($k['baris'])
                    ->when($cari !== '', fn ($b) => $b->filter(
                        fn (array $s) => mb_stripos(
                            (string) $s['judul'].' '.(string) $s['kode'],
                            $cari,
                        ) !== false,
                    ))
                    ->when($jenis !== '', fn ($b) => $b->where('jenis', $jenis))
                    ->when($tingkat !== '', fn ($b) => $b->where('tingkat', $tingkat))
                    ->values();

                return $baris->isEmpty() && ($cari !== '' || $jenis !== '' || $tingkat !== '')
                    ? null
                    : [
                        ...$k,
                        'baris' => $baris->all(),
                        // Angka kelompok DIHITUNG ULANG dari baris tersaring,
                        // dan itu kebalikan dari aturan KPI di atas: kepala
                        // kelompok menjawab "isi blok ini", bukan "ukuran
                        // event". Kepala yang menyebut 19 sementara tiga baris
                        // tampil adalah angka yang salah.
                        'butir' => $baris->count(),
                        'poin' => round((float) $baris->sum('poin'), 2),
                        'uraian' => $baris->where('perlu_koreksi', true)->count(),
                        'diarsipkan' => $baris->where('diarsipkan', true)->count(),
                        'basi' => $baris->where('basi', true)->count(),
                    ];
            })
            ->filter()
            ->values();

        // Daftar jenis dan tingkat dari SELURUH baris, bukan dari yang
        // tersaring: pilihan penyaring yang hilang begitu ia dipakai adalah
        // pilihan yang tidak bisa dibatalkan.
        $semua = collect($isi['kelompok'])->flatMap(fn (array $k) => $k['baris']);

        return Inertia::render('Admin/Event/Soal', [
            'event' => [
                'id' => $event->id,
                'kode' => $event->code,
                'nama' => $event->name,
                'status' => $event->status->value,
                'status_label' => $event->status->label(),
            ],
            'kelompok' => $kelompok->all(),
            'angka' => $isi['angka'],
            'filter' => ['cari' => $cari, 'jenis' => $jenis, 'tingkat' => $tingkat],
            'pilihan' => [
                'jenis' => $semua->pluck('jenis_pendek', 'jenis')->sort()->all(),
                'tingkat' => $semua->pluck('tingkat')->filter()->unique()->sort()->values()->all(),
            ],
        ]);
    }
}

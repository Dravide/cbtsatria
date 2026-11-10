<?php

namespace App\Domain\Event\Http\Controllers;

use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Event\Models\EventCommitteeMember;
use App\Domain\Event\Models\SchoolEvent;
use App\Domain\Event\Services\EksporPanitia;
use App\Domain\Event\Services\KartuPengawas;
use App\Domain\Event\Services\PenerbitKodePengawas;
use App\Domain\Facility\Models\Room;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Support\Cetak\KopSekolah;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kepanitiaan event - wireframe plate 13, PRD §6.6.
 *
 * ---------------------------------------------------------------------
 * Kolom "Cakupan" harus terlihat di tabel, bukan disembunyikan di detail
 * ---------------------------------------------------------------------
 * Wireframe plate 13 mencatat alasannya: cakupan "membatasi apa yang boleh
 * mereka lihat dan ubah - dan itu sebabnya cakupan harus terlihat di tabel,
 * bukan tersembunyi di halaman detail."
 *
 * Konsekuensinya: kolomnya dirender sebagai daftar kode ruang yang bisa
 * dibaca sekali pandang ("R-01, R-02"), bukan sebagai jumlah ("2 ruang").
 * "2 ruang" tidak memberi tahu ruang mana, dan pertanyaan berikutnya selalu
 * ruang mana - sehingga Admin harus membuka barisnya satu per satu untuk
 * memastikan penempatan pengawas benar. Pada sekolah dengan 24 ruang dan 31
 * pengawas, itu 31 kali membuka.
 *
 * ---------------------------------------------------------------------
 * Laman ini juga tempat KODE PENGAWAS diterbitkan - sejak 2026-11-07
 * ---------------------------------------------------------------------
 * Sebelumnya kode itu terbit di `/admin/ruang/{id}`, satu kode per RUANG,
 * dan dicetak pada kartu yang sama dengan tautannya di daun pintu - sehingga
 * memfoto kartu itu sudah cukup untuk masuk. Sekarang kodenya milik ORANG,
 * dan orangnya ada di tabel laman ini. Lihat `terbitkanKode()`.
 */
class EventCommitteeController
{
    public function index(SchoolEvent $event): Response
    {
        Gate::authorize('view', $event);

        $panitia = $this->panitia($event);

        return Inertia::render('Admin/Event/Panitia', [
            'event' => $this->ringkas($event),
            'panitia' => $panitia->map(fn (EventCommitteeMember $p) => [
                'id' => $p->id,
                'nama' => $p->name,
                'jabatan' => $p->position,
                'cakupan' => $p->labelCakupan(),
                'cakupan_kode' => $p->scope,
                'ruang_id' => $p->rooms->pluck('id')->all(),
                'ruang_kode' => $p->rooms->pluck('code')->all(),
                'status' => $p->status,
                'akun' => $p->user?->username,
                'email' => $p->email,
                'phone' => $p->phone,
                // Kode pengawas DITAMPILKAN, bukan disembunyikan. Pemilik
                // produk mencetaknya menjadi kartu pengawas, jadi ia harus
                // bisa membaca kodenya kapan saja - bukan sekali saja pada
                // respons yang menerbitkannya.
                //
                // Yang dikirim adalah kodenya (hasil dekripsi cast
                // `encrypted`), bukan hash: tidak ada hash lagi, karena kode
                // yang harus bisa dibaca kembali tidak bisa disimpan satu
                // arah. Lihat `EventCommitteeMember` dan
                // `PenerbitKodePengawas`.
                'kode' => $p->code,
                'punya_kode' => $p->code !== null,
                'kode_terbit' => $p->code_issued_at?->format('d/m H:i'),
            ])->all(),

            // Akun yang bisa ditautkan: Guru dan Pengawas. Menawarkan seluruh
            // pengguna berarti menawarkan siswa sebagai ketua panitia, dan itu
            // pilihan yang tidak pernah benar - sama seperti daftar wali kelas
            // di SchoolClassController.
            'akun' => User::query()
                ->whereIn('role', [Role::Teacher, Role::Supervisor])
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'username', 'role'])
                ->map(fn (User $u) => [
                    'id' => $u->id,
                    'nama' => $u->name,
                    'username' => $u->username,
                    'peran' => $u->role->label(),
                ])->all(),

            'ruang' => Room::query()
                ->where('status', 'aktif')
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn (Room $r) => ['id' => $r->id, 'kode' => $r->code, 'nama' => $r->name])
                ->all(),

            'jabatan' => self::JABATAN,
        ]);
    }

    /* -------------------------------------------- Ekspor & kartu cetak */

    /**
     * Unduh daftar panitia sebagai XLSX - PRD §6.6.
     *
     * ---------------------------------------------------------------------
     * Mengapa berkasnya dibuat di dalam permintaan, bukan lewat antrean
     * ---------------------------------------------------------------------
     * Satu event punya puluhan panitia, bukan ribuan seperti ekspor pengguna
     * - lihat catatan yang sama di `EksporPanitia`. Seluruh barisnya sudah
     * dibaca `index()`, jadi berkasnya selesai sebelum jawabannya sempat
     * disusun; menaruhnya di antrean berarti Admin menunggu worker untuk
     * pekerjaan yang lebih cepat daripada membuka halaman ini.
     */
    public function ekspor(SchoolEvent $event, EksporPanitia $ekspor): HttpResponse
    {
        Gate::authorize('view', $event);

        $isi = $ekspor->buat($event, $this->panitia($event));

        // Dikirim sebagai respons langsung, bukan lewat disk privat: berkasnya
        // kecil, hanya ada selama beberapa milidetik, dan tidak ada gunanya
        // disimpan. Tautan unduh yang mati karena berkasnya dipangkas adalah
        // kegagalan yang tidak perlu diciptakan untuk berkas sebesar ini.
        return response($isi, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$ekspor->namaBerkas($event).'"',
        ]);
    }

    /**
     * Cetak kartu panitia dan pengawas sebagai PDF.
     *
     * ---------------------------------------------------------------------
     * Yang keluar dari sini memuat KODE, dan itu disebut di log
     * ---------------------------------------------------------------------
     * Kartu ini memuat kode pintu setiap pengawas dalam satu berkas. Berkasnya
     * memang untuk dicetak dan dibagikan - tetapi "dicetak lalu dibagikan" dan
     * "diunduh diam-diam" terlihat sama dari sisi basis data, dan hanya yang
     * pertama yang diinginkan. Karena itu pengunduhannya DICATAT, sama seperti
     * penerbitan kodenya: pertanyaan "kartu ini dari mana" punya jawaban.
     */
    public function kartu(
        SchoolEvent $event,
        KartuPengawas $kartu,
        ActivityLogger $logger,
    ): HttpResponse {
        Gate::authorize('view', $event);

        // Dimuat sekali, dipakai dua kali. Memanggil `panitia()` lagi untuk
        // menghitung barisnya berarti kueri kedua terhadap daftar yang sama -
        // dan yang dilaporkan ke log bisa berbeda dari yang tercetak bila ada
        // yang menambah panitia di antara keduanya.
        $panitia = $this->panitia($event);

        $pdf = $kartu->buat(
            $event,
            $panitia,
            KopSekolah::dari(School::query()->find($event->school_id)),
        );

        $logger->log('Mencetak kartu panitia', $event, [
            'jumlah' => $panitia->count(),
        ]);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$kartu->namaBerkas($event).'"',
        ]);
    }

    /**
     * Panitia satu event, urut seperti tabelnya.
     *
     * Dipakai `index()`, `ekspor()`, dan `kartu()` - dan itulah alasannya
     * berdiri sendiri. Berkas Excel dan kartu cetak yang urutannya berbeda
     * dari layar membuat Admin membandingkan tiga daftar yang seharusnya
     * satu.
     *
     * @return Collection<int, EventCommitteeMember>
     */
    private function panitia(SchoolEvent $event)
    {
        return $event->committee()
            ->with(['rooms:id,code,name', 'user:id,name,username'])
            ->orderByRaw("FIELD(position, 'ketua', 'koordinator', 'pengawas', 'anggota')")
            ->orderBy('name')
            ->get();
    }

    public function store(Request $request, SchoolEvent $event, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('update', $event);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'position' => ['required', 'string', 'max:40'],
            'user_id' => ['nullable', 'integer'],
            'email' => ['nullable', 'email', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'scope' => ['required', 'in:event,ruang'],
            'ruang_id' => ['nullable', 'array'],
            'ruang_id.*' => ['integer'],
            'status' => ['nullable', 'in:aktif,belum konfirmasi,nonaktif'],
        ]);

        // Cakupan "ruang" tanpa satu ruang pun bukan cakupan yang membatasi
        // apa pun - ia terbaca seperti panitia penuh yang diberi label salah.
        // Menolaknya di sini lebih baik daripada membiarkan Admin menemukan
        // bahwa orangnya bisa membuka semua ruang.
        if ($data['scope'] === EventCommitteeMember::SCOPE_ROOMS && empty($data['ruang_id'])) {
            return back()->with('galat', 'Cakupan "ruang tertentu" harus menyebut minimal satu ruang.');
        }

        // Akun yang ditautkan harus benar-benar ada di sekolah ini: scope
        // yang memutuskan, dan id sekolah lain berakhir 404 lewat findOrFail.
        if (! empty($data['user_id'])) {
            User::findOrFail($data['user_id']);
        }

        $panitia = EventCommitteeMember::create([
            'school_id' => $event->school_id,
            'event_id' => $event->id,
            'user_id' => $data['user_id'] ?? null,
            'name' => $data['name'],
            'position' => $data['position'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'scope' => $data['scope'],
            'status' => $data['status'] ?? 'aktif',
        ]);

        $this->simpanRuang($panitia, $data['ruang_id'] ?? []);

        // Kode pengawas diterbitkan sejak orang ini ditambahkan, bukan
        // menunggu satu klik terpisah. Kartunya bisa langsung dicetak, dan
        // `terbitkan()` tidak mengganti kode yang sudah ada - jadi aman
        // dipanggil di jalur mana pun yang menambah pengawas.
        $penerbit = app(PenerbitKodePengawas::class);

        if ($penerbit->berlakuUntuk($panitia)) {
            $penerbit->terbitkan($panitia);
        }

        $logger->log('Menambah panitia event', $event, [
            'nama' => $panitia->name,
            'jabatan' => $panitia->position,
        ]);

        return back()->with('sukses', "{$panitia->name} ditambahkan sebagai {$panitia->position}.");
    }

    /**
     * Tambah panitia secara massal - satu jabatan, satu cakupan, banyak akun.
     *
     * ---------------------------------------------------------------------
     * Mengapa massal, dan mengapa BUKAN per-baris
     * ---------------------------------------------------------------------
     * Sekolah dengan 31 pengawas harus membuka-tutup satu panel 31 kali, dan
     * jabatannya sama untuk semuanya. Yang diminta karena itu bukan panel
     * besar berisi 31 form, melainkan satu kiriman: centang orangnya, pilih
     * jabatannya sekali. Tiga jabatan berbeda tetap berarti tiga kiriman, dan
     * itu sepuluh detik - jauh lebih murah daripada 31 penutup panel.
     *
     * ---------------------------------------------------------------------
     * Nama diambil dari AKUNNYA, tidak diketik
     * ---------------------------------------------------------------------
     * Formnya tidak punya kolom nama sama sekali. Menyediakannya berarti
     * membuka celah di mana orang yang sama tercatat dua kali dengan ejaan
     * berbeda, dan `committee_unik` menolaknya sebagai galat basis data yang
     * tidak menjelaskan apa pun.
     *
     * ---------------------------------------------------------------------
     * `committee_unik` adalah alasan utama bentuk "dilewati", bukan "gagal"
     * ---------------------------------------------------------------------
     * Tabel `event_committee_members` memakai
     * `unique(['school_id','event_id','name'])`. Menyisipkan Massal tanpa
     * penyaringan akan meledak menjadi 500 pada dua keadaan yang SANGAT
     * lazim: orangnya sudah jadi panitia, dan dua akun berbeda memakai nama
     * yang sama (dua "Ahmad Fauzi" adalah hal biasa di satu sekolah). Karena
     * itu keduanya dilewati - dan dilaporkan beserta sebabnya, bukan hilang
     * diam-diam. Bentuk laporannya sama dengan `PengawasService::tugaskanOtomatis()`:
     * yang berhasil dikerjakan dan yang dilewati, keduanya dalam satu pesan.
     */
    public function massal(Request $request, SchoolEvent $event, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('update', $event);

        $data = $request->validate([
            'user_id' => ['required', 'array', 'min:1'],
            'user_id.*' => ['integer'],
            'position' => ['required', Rule::in(self::JABATAN)],
            'scope' => ['required', 'in:event,ruang'],
            // Cakupan "ruang" tanpa satu ruang pun ditolak, sama seperti di
            // `store()`: cakupan yang tidak membatasi apa pun tetapi berlabel
            // membatasi adalah label yang salah.
            'ruang_id' => ['nullable', 'array'],
            'ruang_id.*' => ['integer'],
        ]);

        if ($data['scope'] === EventCommitteeMember::SCOPE_ROOMS && empty($data['ruang_id'])) {
            return back()->with('galat', 'Cakupan "ruang tertentu" harus menyebut minimal satu ruang.');
        }

        // Akun disaring ULANG di server terhadap peran dan keaktifannya.
        // Prop `akun` yang dikirim ke peramban hanya daftar yang DITAWARKAN;
        // id apa pun bisa dikirim langsung ke rute ini, termasuk akun nonaktif
        // atau siswa yang tidak pernah muncul di kotak centang.
        $akun = User::query()
            ->whereIn('id', $data['user_id'])
            ->whereIn('role', [Role::Teacher, Role::Supervisor])
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $sudah = $event->committee()->pluck('name')->map(fn ($n) => mb_strtolower($n))->all();
        $sudahAkun = $event->committee()->whereNotNull('user_id')->pluck('user_id')->all();

        $jumlah = 0;
        $dilewati = [];

        $penerbit = app(PenerbitKodePengawas::class);

        DB::transaction(function () use ($data, $event, $akun, $sudah, $sudahAkun, $penerbit, &$jumlah, &$dilewati) {
            $namaDiKiriman = [];

            foreach (array_unique($data['user_id']) as $id) {
                $orang = $akun->get($id);

                if ($orang === null) {
                    // Termasuk akun milik sekolah lain: `User` memakai
                    // SchoolScope, jadi id dari sekolah lain tidak ditemukan
                    // di sini. Scope tenant tidak menolong untuk kasus ini
                    // kalau sekolahnya sama, sehingga penyaringannya memang
                    // harus lewat daftar akun yang sah di atas.
                    $dilewati[] = "akun #{$id} (bukan Guru/Pengawas aktif)";

                    continue;
                }

                if (in_array($orang->id, $sudahAkun, true)) {
                    $dilewati[] = "{$orang->name} (sudah jadi panitia)";

                    continue;
                }

                $kunci = mb_strtolower($orang->name);

                if (in_array($kunci, $sudah, true) || in_array($kunci, $namaDiKiriman, true)) {
                    $dilewati[] = "{$orang->name} (nama sudah ada di kepanitiaan)";

                    continue;
                }

                $namaDiKiriman[] = $kunci;

                $panitia = EventCommitteeMember::create([
                    'school_id' => $event->school_id,
                    'event_id' => $event->id,
                    'user_id' => $orang->id,
                    'name' => $orang->name,
                    'position' => $data['position'],
                    'scope' => $data['scope'],
                    'status' => 'aktif',
                ]);

                $this->simpanRuang($panitia, $data['ruang_id'] ?? []);

                // Sama seperti `store()`: kode pengawas terbit sejak orangnya
                // masuk, supaya kartunya bisa langsung dicetak. Hanya untuk
                // baris yang memenuhi syarat (jabatan memuat "pengawas" dan
                // punya akun); jabatan lain tidak pernah butuh kode.
                if ($penerbit->berlakuUntuk($panitia)) {
                    $penerbit->terbitkan($panitia);
                }

                $jumlah++;
            }
        });

        // Satu log untuk satu kiriman, dengan jumlahnya. Menulis satu baris per
        // orang membuat riwayat aktivitas event ini tenggelam oleh 31 baris
        // yang isinya sama, dan yang dicari saat menelusuri justru "kapan
        // pengawas ditambahkan", bukan "pengawas ke-17".
        $logger->log('Menambah panitia event secara massal', $event, [
            'jabatan' => $data['position'],
            'cakupan' => $data['scope'],
            'jumlah' => $jumlah,
            'dilewati' => count($dilewati),
        ]);

        if ($jumlah === 0) {
            return back()->with('galat', 'Tidak ada yang ditambahkan. '.implode('; ', $dilewati));
        }

        $pesan = "{$jumlah} panitia ditambahkan sebagai {$data['position']}.";

        if ($dilewati !== []) {
            $pesan .= ' Dilewati: '.implode('; ', $dilewati).'.';
        }

        return back()->with('sukses', $pesan);
    }

    /**
     * Terbitkan ULANG kode pintu ruang untuk satu panitia - wireframe plate 13.
     *
     * ---------------------------------------------------------------------
     * Mengapa di sini, bukan di laman ruang seperti dulu
     * ---------------------------------------------------------------------
     * Sampai 2026-11-07 kode pengawas terbit di `/admin/ruang/{id}`, satu
     * kode per RUANG. Akibatnya bukan sekadar salah tempat: kartu yang
     * ditempel di daun pintu memuat tautan DAN kodenya, sehingga memfotonya
     * sudah cukup untuk masuk. Kode yang "menentukan orang" justru
     * menentukan ruang untuk siapa pun yang memegang kertasnya.
     *
     * Sekarang kodenya milik ORANG, dan orangnya ada di tabel ini. Kodenya
     * SELALU terlihat di kolom "Kode pengawas" - karena ia dicetak menjadi
     * kartu pengawas - sehingga tombol di sini bukan "terbitkan yang
     * pertama", melainkan "GANTI kode yang sudah ada" (kartunya bocor atau
     * hilang). Itulah sebabnya ia memanggil `putar()`, bukan `terbitkan()`.
     *
     * ---------------------------------------------------------------------
     * Yang menolak bukan hanya layar
     * ---------------------------------------------------------------------
     * Panelnya tidak merender tombol untuk baris yang tidak memenuhi syarat,
     * tetapi penolakan yang benar ada di sini - id bisa dikirim langsung ke
     * rutenya. Pesannya menyebut SEBABNYA, karena Admin di depan layar tidak
     * bisa menyimpulkan sendiri bahwa masalahnya adalah akun yang belum
     * ditautkan.
     */
    public function terbitkanKode(
        SchoolEvent $event,
        EventCommitteeMember $panitia,
        PenerbitKodePengawas $penerbit,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $event);

        // Alasan yang sama dengan `update()`: binding implisit mencari di
        // seluruh sekolah, sehingga panitia milik event LAIN akan lolos dan
        // menerima kode untuk event yang bukan miliknya.
        abort_unless($panitia->event_id === $event->id, 404);

        if (! $penerbit->berlakuUntuk($panitia)) {
            return back()->with('galat', $this->alasanTanpaKode($panitia));
        }

        $penerbit->putar($panitia);

        $logger->log('Menerbitkan kode pengawas', $event, [
            'nama' => $panitia->name,
            'jabatan' => $panitia->position,
        ]);

        // Kodenya tidak dikirim lewat flash: ia selalu terlihat di tabel,
        // dan pesan ini hanya perlu memberi tahu bahwa yang LAMA sudah mati -
        // supaya Admin yang menekan tombolnya karena kartunya bocor tahu
        // kode barunya ada di kolom itu, bukan bertambah menjadi dua.
        return back()->with('sukses', "Kode pengawas {$panitia->name} diganti. Kode lama berhenti berlaku.");
    }

    /**
     * Terbitkan kode untuk SELURUH pengawas event ini yang belum punya.
     *
     * ---------------------------------------------------------------------
     * Memanggil `terbitkan()`, bukan `putar()` - dan itu seluruh desainnya
     * ---------------------------------------------------------------------
     * Menerbitkan kode satu per satu untuk 31 pengawas adalah 31 kali membuka
     * panel dan 31 kali menekan tombol, dan itulah satu-satunya alasan method
     * ini ada. Yang TIDAK boleh ia lakukan adalah mengganti kode yang sudah
     * terbit: kartu panitia yang sudah dicetak dan dibagikan akan mati
     * serentak, dan Admin yang menekan tombol "terbitkan semua" pada pagi
     * hari ujian justru menghancurkan seluruh kartu yang sudah di tangannya.
     *
     * Karena itu kode yang sudah ada DILEWATI, dan hasilnya dilaporkan sebagai
     * "sudah punya kode" - bukan sebagai kegagalan. Baris yang belum memenuhi
     * syarat (belum berakun, bukan pengawas, nonaktif) disebut satu per satu
     * dengan sebabnya, sama seperti tambah massal: yang dilewati diam-diam
     * akan membuat Admin menekan tombolnya lagi, dan itu tidak mengubah apa
     * pun.
     */
    public function terbitkanKodeMassal(
        SchoolEvent $event,
        PenerbitKodePengawas $penerbit,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $event);

        $jumlah = 0;
        $sudah = 0;
        $dilewati = [];

        foreach ($this->panitia($event) as $panitia) {
            if (! $penerbit->berlakuUntuk($panitia)) {
                $hambatan = $this->hambatanKode($panitia);

                $dilewati[] = "{$panitia->name} ({$hambatan['sebab']})";

                continue;
            }

            if ($panitia->code !== null) {
                $sudah++;

                continue;
            }

            $penerbit->terbitkan($panitia);

            $jumlah++;
        }

        $logger->log('Menerbitkan kode pengawas secara massal', $event, [
            'diterbitkan' => $jumlah,
            'sudah_punya' => $sudah,
            'dilewati' => count($dilewati),
        ]);

        if ($jumlah === 0 && $dilewati === []) {
            return back()->with('sukses', 'Semua pengawas sudah punya kode. Tidak ada yang diterbitkan.');
        }

        if ($jumlah === 0) {
            return back()->with('galat', 'Tidak ada kode yang diterbitkan. '.implode('; ', $dilewati));
        }

        $pesan = "{$jumlah} kode pengawas diterbitkan.";

        if ($sudah > 0) {
            $pesan .= " {$sudah} sudah punya kode - tidak diubah, kartunya tetap berlaku.";
        }

        if ($dilewati !== []) {
            $pesan .= ' Dilewati: '.implode('; ', $dilewati).'.';
        }

        return back()->with('sukses', $pesan);
    }

    /**
     * Mengapa baris ini belum boleh punya kode - sebab dan saran, terpisah.
     *
     * Dipecah menjadi dua bagian karena ada dua pembacanya. Panel satu baris
     * menyusun satu kalimat penuh dengan saran yang bisa langsung dikerjakan;
     * pesan massal hanya butuh SEBABNYA, karena 31 baris yang masing-masing
     * membawa paragraf saran adalah pesan yang tidak terbaca siapa pun.
     *
     * Syaratnya sendiri tetap satu tempat: `bolehPunyaKode()` memutuskan, dan
     * method ini hanya menerangkan keputusan itu - bukan mengulangnya.
     *
     * @return array{sebab: string, saran: string}
     */
    private function hambatanKode(EventCommitteeMember $panitia): array
    {
        if ($panitia->user_id === null) {
            return [
                'sebab' => 'belum punya akun',
                'saran' => 'Tautkan akun Guru atau Pengawas lebih dulu - tanpa akun, ia tidak bisa ditugaskan ke ruang mana pun, sehingga kodenya tidak akan punya pintu.',
            ];
        }

        if (! str_contains(mb_strtolower($panitia->position), 'pengawas')) {
            return [
                'sebab' => "berjabatan {$panitia->position}, bukan pengawas",
                'saran' => 'Kode pintu ruang hanya untuk pengawas.',
            ];
        }

        return [
            'sebab' => "berstatus {$panitia->status}",
            'saran' => 'Aktifkan kembali sebelum menerbitkan kodenya.',
        ];
    }

    /**
     * Mengapa baris ini belum boleh punya kode - dinyatakan sebagai kalimat.
     */
    private function alasanTanpaKode(EventCommitteeMember $panitia): string
    {
        $hambatan = $this->hambatanKode($panitia);

        return "{$panitia->name} {$hambatan['sebab']}. {$hambatan['saran']}";
    }

    public function update(
        Request $request,
        SchoolEvent $event,
        EventCommitteeMember $panitia,
        ActivityLogger $logger,
    ): RedirectResponse {
        Gate::authorize('update', $event);

        // Binding implisit pada $panitia mencari berdasarkan id di seluruh
        // sekolah, bukan di dalam event ini - sehingga id panitia milik event
        // LAIN akan lolos dan berubah di tempat yang salah. Pemeriksaan ini
        // yang menutupnya, dan 404 adalah jawabannya: bagi event ini, baris
        // itu memang tidak ada.
        abort_unless($panitia->event_id === $event->id, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'position' => ['required', 'string', 'max:40'],
            'user_id' => ['nullable', 'integer'],
            'email' => ['nullable', 'email', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'scope' => ['required', 'in:event,ruang'],
            'ruang_id' => ['nullable', 'array'],
            'ruang_id.*' => ['integer'],
            'status' => ['required', 'in:aktif,belum konfirmasi,nonaktif'],
        ]);

        if ($data['scope'] === EventCommitteeMember::SCOPE_ROOMS && empty($data['ruang_id'])) {
            return back()->with('galat', 'Cakupan "ruang tertentu" harus menyebut minimal satu ruang.');
        }

        if (! empty($data['user_id'])) {
            User::findOrFail($data['user_id']);
        }

        $panitia->update([
            'user_id' => $data['user_id'] ?? null,
            'name' => $data['name'],
            'position' => $data['position'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'scope' => $data['scope'],
            'status' => $data['status'],
        ]);

        $this->simpanRuang($panitia, $data['ruang_id'] ?? []);

        $logger->log('Memperbarui panitia event', $event, ['nama' => $panitia->name]);

        return back()->with('sukses', 'Panitia tersimpan.');
    }

    public function destroy(SchoolEvent $event, EventCommitteeMember $panitia, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('update', $event);

        abort_unless($panitia->event_id === $event->id, 404);

        $nama = $panitia->name;

        // Hapus keras, berbeda dari peserta. Panitia adalah penugasan yang
        // berakhir saat orangnya tidak lagi bertugas, dan barisnya tidak
        // menyimpan riwayat apa pun yang perlu dipertahankan - tidak seperti
        // peserta, yang kehadirannya di event adalah catatan akademik.
        $panitia->delete();

        $logger->log('Menghapus panitia event', $event, ['nama' => $nama]);

        return back()->with('sukses', "{$nama} dikeluarkan dari kepanitiaan.");
    }

    /**
     * Cakupan "seluruh event" tidak menyimpan satu baris ruang pun.
     *
     * Menyimpannya berarti dua sumber kebenaran untuk pertanyaan yang sama,
     * dan yang lebih tua akan menang diam-diam saat keduanya berbeda.
     *
     * @param  array<int, int>  $ruangId
     */
    private function simpanRuang(EventCommitteeMember $panitia, array $ruangId): void
    {
        if ($panitia->scope !== EventCommitteeMember::SCOPE_ROOMS) {
            $panitia->rooms()->detach();

            return;
        }

        // Hanya ruang yang benar-benar ada di sekolah ini yang tersimpan.
        // Scope pada Room menyaringnya, dan id sekolah lain berakhir tidak
        // ikut - bukan galat yang membocorkan keberadaannya.
        $sah = Room::query()->whereIn('id', $ruangId)->pluck('id')->all();

        $panitia->sinkronRuang($sah);
    }

    /** @return array<string, mixed> */
    private function ringkas(SchoolEvent $event): array
    {
        return [
            'id' => $event->id,
            'kode' => $event->code,
            'nama' => $event->name,
            'status' => $event->status->value,
            'status_label' => $event->status->label(),
        ];
    }

    /**
     * Jabatan panitia.
     *
     * Wireframe plate 13 menampilkan empat: ketua, koordinator, pengawas,
     * anggota. Daftar ini diambil apa adanya dari sana - bukan dikarang -
     * karena readiness gate membaca "pengawas" dari daftar ini untuk
     * menentukan apakah pengawas sudah ditugaskan.
     *
     * @var array<int, string>
     */
    public const JABATAN = ['ketua', 'koordinator', 'pengawas', 'anggota'];
}

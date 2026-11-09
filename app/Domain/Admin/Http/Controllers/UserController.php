<?php

namespace App\Domain\Admin\Http\Controllers;

use App\Domain\Admin\Models\UserExport;
use App\Domain\Admin\Services\UserRegistrar;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Support\TabelQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Pengguna - wireframe plate 05.
 *
 * Empat tab peran, satu tabel. Tab-nya bukan navigasi ke empat laman: yang
 * berubah hanya penyaring role, dan itu terlihat dari "1-50 dari 1.284" yang
 * tetap satu baris tabel.
 *
 * ---------------------------------------------------------------------
 * Mengapa username tidak bisa diketik Admin
 * ---------------------------------------------------------------------
 * Username diturunkan dari NIS/NIP (UserRegistrar). Kalau Admin bisa
 * mengetiknya sendiri, maka ia bisa mengetik yang bertabrakan dengan akun
 * sekolah lain, dan kegagalannya muncul jauh kemudian - di layar login siswa,
 * pada hari ujian, sebagai "username tidak ada atau password salah" yang
 * tidak menjelaskan apa pun. Karena itu satu-satunya tempat bentrokan itu
 * diselesaikan adalah di service, dan form tidak menawarkan jalur lain.
 */
class UserController
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        $roleTab = $this->roleTab($request);

        $query = User::query()
            ->where('role', $roleTab)
            // Hanya profil yang sesuai peran tab ini yang ikut diambil:
            // memuat ketiganya berarti mentransfer dua tabel yang pasti
            // kosong untuk setiap barisnya.
            ->with($this->relasiProfil($roleTab));

        $halaman = TabelQuery::buat(
            $query,
            $request,
            cariDi: ['name', 'username', 'email'],
            bolehDiurut: ['name', 'username', 'created_at'],
            penyaring: ['status' => 'is_active'],
            urutBawaan: 'name',
        );

        return Inertia::render('Admin/Pengguna', [
            'tab' => $roleTab->value,
            'tabnya' => $this->hitungan(),
            'baris' => $halaman->through(fn (User $u) => [
                'id' => $u->id,
                'nama' => $u->name,
                'username' => $u->username,
                'nomor' => $this->nomorIdentitas($u),
                'email' => $u->email,
                'aktif' => $u->is_active,
                'terakhir_masuk' => $u->last_login_at?->format('d/m H:i'),
                'phone' => $u->phone,
                // Profilnya ikut dikirim BESERTA isinya, bukan hanya nomornya.
                // Modal "Ubah" membuka form yang sama dengan "Tambah", dan
                // tanpa kolom-kolom ini ia menampilkan field identitas dan
                // jenis kelamin yang kosong untuk akun yang sudah terisi -
                // lalu Admin menyimpan form kosong itu dan menghapus datanya.
                'profil' => $this->profil($u),
            ]),
            'filter' => [
                'cari' => $request->query('cari', ''),
                'status' => $request->query('status', ''),
            ],
            'jumlah' => $halaman->total(),
            // Riwayat ekspor ikut dikirim karena ekspornya berjalan di latar
            // belakang: tanpa daftar ini Admin yang menekan tombol lalu
            // berpindah tab tidak punya cara menemukan berkasnya. Pola yang
            // sama dipakai plate 22 Fase 8.
            //
            // Lima terakhir, bukan dua belas seperti laporan: di sini ekspornya
            // bukan keluaran utama lamannya, dan daftar panjang di bawah tabel
            // 1.284 baris hanya menambah gulir.
            'ekspor' => UserExport::query()
                ->with('peminta:id,name')
                ->orderByDesc('id')
                ->limit(5)
                ->get()
                ->map(fn (UserExport $satu) => [
                    'id' => $satu->id,
                    'format' => strtoupper($satu->format),
                    'peran' => $satu->parameter_json['role'] ?? null,
                    'status' => $satu->status,
                    'status_label' => $satu->labelStatus(),
                    // Dihitung di model, bukan di React: `nadaStatus()` di
                    // `ui.tsx` memetakan kosakata status event, presensi, dan
                    // master data - ia tidak mengenal `menunggu`/`diproses`.
                    // Mengirimnya dari sini berarti komponen bersama itu tidak
                    // perlu disentuh.
                    'status_nada' => $satu->nadaStatus(),
                    'total_baris' => $satu->total_baris,
                    'ukuran' => $satu->ukuran,
                    'nama_berkas' => $satu->nama_berkas,
                    'pesan_gagal' => $satu->pesan_gagal,
                    'peminta' => $satu->peminta?->name,
                    'diminta_pada' => $satu->created_at?->format('d M Y H:i'),
                ])
                ->all(),
            'format' => UserExport::FORMAT,
        ]);
    }

    public function store(Request $request, UserRegistrar $registrar, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('create', User::class);

        $data = $request->validate($this->aturan(), [], $this->label());

        $sekolah = $request->user()->school;

        $user = $registrar->buat(
            array_merge($data, [
                // Password awal dari Admin, bukan dikirim lewat surel: siswa
                // sering tidak punya email, dan yang punya pun jarang
                // membukanya sebelum ujian dimulai.
                //
                // ?? '', bukan $data['password'] langsung: validate() hanya
                // mengembalikan kunci yang ADA di request, dan 'nullable'
                // tidak memaksa pemanggil mengirimnya. Tanpa ??, request yang
                // tidak menyertakan password sama sekali bukan berarti "pakai
                // sandi awal" - ia berarti "undefined array key", dan di
                // bawah error handler Laravel itu berakhir sebagai 500.
                'password' => ($data['password'] ?? '') ?: User::SANDI_BAWAAN,
                'username' => $registrar->usernameDari(
                    $registrar->nomorIdentitas($data),
                    $sekolah->code,
                ),
            ]),
            $sekolah->id,
        );

        $logger->log(
            'Menambah pengguna '.$user->role->label(),
            $user,
            ['username' => $user->username],
        );

        return back()->with('sukses', "Akun {$user->name} dibuat dengan username {$user->username}.");
    }

    public function update(Request $request, User $pengguna, UserRegistrar $registrar, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('update', $pengguna);

        $data = $request->validate($this->aturan($pengguna), [], $this->label());

        $registrar->perbarui($pengguna, $data);

        $logger->log('Memperbarui pengguna', $pengguna, ['username' => $pengguna->username]);

        return back()->with('sukses', 'Data pengguna tersimpan.');
    }

    /**
     * Ganti username satu akun.
     *
     * ---------------------------------------------------------------------
     * Mengapa rute tersendiri, bukan kolom di form Ubah
     * ---------------------------------------------------------------------
     * Username tertempel di kartu login siswa. Menaruhnya sebagai satu kolom
     * di antara nama, email, dan telepon berarti ia ikut tersimpan setiap kali
     * Admin memperbaiki alamat surel seseorang - dan satu salah ketik yang
     * tidak disadari langsung mengunci siswa itu pada hari ujian.
     *
     * Sebagai tindakan tersendiri, ia menuntut niat: Admin membukanya karena
     * memang ingin menggantinya. Dan layarnya bisa menyebut akibatnya lebih
     * dulu, karena di sinilah akibat itu sungguh terjadi.
     *
     * Gate-nya `update` yang sama, bukan izin baru: mengganti username adalah
     * mengubah akun, dan Admin yang sudah boleh mengubah nama serta emailnya
     * tidak menjadi tidak boleh hanya karena kolomnya berbeda.
     */
    public function gantiUsername(Request $request, User $pengguna, UserRegistrar $registrar, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('update', $pengguna);

        $data = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:60'],
        ], [], ['username' => 'username']);

        try {
            $lama = $registrar->gantiUsername($pengguna, $data['username']);
        } catch (InvalidArgumentException $e) {
            // Ditolak sebagai galat VALIDASI pada kolomnya, bukan sebagai
            // flash: pesannya milik kolom itu, dan `Field` sudah punya tempat
            // untuk menampilkannya tepat di bawah input yang salah.
            throw ValidationException::withMessages(['username' => $e->getMessage()]);
        }

        if ($lama === $pengguna->username) {
            return back()->with('sukses', 'Username tidak berubah.');
        }

        // Username lama ikut dicatat. Inilah satu-satunya tempat ia masih
        // berguna: saat siswa melapor tidak bisa masuk karena masih mengetik
        // nomor lama dari kartunya, yang dicari adalah username LAMA itu.
        $logger->log('Mengganti username pengguna', $pengguna, [
            'username_lama' => $lama,
            'username_baru' => $pengguna->username,
        ]);

        return back()->with('sukses', 'Username diganti menjadi '.$pengguna->username.'.');
    }

    /**
     * Nonaktifkan atau aktifkan sekumpulan akun.
     *
     * Menonaktifkan, bukan menghapus - dan itu bukan kehati-hatian berlebihan.
     * Riwayat ujian, presensi, dan jawaban menunjuk ke users.id; menghapus
     * satu akun berarti memutus riwayat yang justru harus tetap bisa
     * ditelusuri setelah ujian selesai. Akun yang nonaktif gagal login lewat
     * jalur yang sama dengan password salah, sehingga tidak ada pesan khusus
     * yang membocorkan bahwa akunnya pernah ada.
     */
    public function massal(Request $request, UserRegistrar $registrar, ActivityLogger $logger): RedirectResponse
    {
        Gate::authorize('viewAny', User::class);

        $data = $request->validate([
            'id' => ['required', 'array'],
            'id.*' => ['integer'],
            'aksi' => ['required', Rule::in(['nonaktifkan', 'aktifkan'])],
        ]);

        $aktif = $data['aksi'] === 'aktifkan';

        // Admin tidak menonaktifkan dirinya sendiri. Bukan pembatasan teknis,
        // tetapi satu-satunya akun yang dijamin ada: mengunci diri sendiri
        // berarti tidak ada yang bisa membuka kuncinya.
        $id = array_diff($data['id'], [$request->user()->id]);

        $jumlah = $registrar->setAktif($id, $aktif);

        $logger->log(
            $aktif ? 'Mengaktifkan pengguna' : 'Menonaktifkan pengguna',
            null,
            ['jumlah' => $jumlah],
        );

        return back()->with('sukses', $jumlah.' akun diperbarui.');
    }

    /**
     * Reset password sekumpulan akun ke nilai awal.
     *
     * Hanya Admin - lihat UserPolicy::resetPassword. Sandi yang direset tidak
     * pernah ditampilkan kembali di layar; yang ditampilkan hanya jumlahnya,
     * supaya tidak ada sesi di mana Admin membaca sandi milik orang lain.
     */
    public function resetPassword(Request $request, ActivityLogger $logger): RedirectResponse
    {
        $data = $request->validate([
            'id' => ['required', 'array'],
            'id.*' => ['integer'],
        ]);

        $jumlah = 0;

        foreach (User::query()->whereIn('id', $data['id'])->get() as $pengguna) {
            Gate::authorize('resetPassword', $pengguna);

            $pengguna->forceFill(['password' => User::SANDI_BAWAAN])->save();
            $jumlah++;
        }

        $logger->log('Reset password pengguna', null, ['jumlah' => $jumlah]);

        return back()->with('sukses', $jumlah.' sandi direset ke "password".');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function aturan(?User $pengguna = null): array
    {
        return [
            // Peran hanya boleh ditentukan saat membuat. Pada perubahan ia
            // dibaca & diabaikan - lihat catatan di form Pengguna: mengubah
            // peran berarti memindahkan profil dan seluruh riwayat, dan itu
            // operasi tersendiri, bukan efek samping sebuah dropdown.
            'role' => $pengguna ? ['sometimes'] : ['required', Rule::enum(Role::class)],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            // Kosong berarti "jangan ubah" saat memperbarui, dan berarti
            // "pakai sandi awal" saat membuat.
            'password' => ['nullable', 'string', 'min:6', 'max:255'],
            'is_active' => ['boolean'],
            'nis' => ['nullable', 'string', 'max:40'],
            'nisn' => ['nullable', 'string', 'max:40'],
            'nip' => ['nullable', 'string', 'max:40'],
            'staff_code' => ['nullable', 'string', 'max:40'],
            'gender' => ['nullable', Rule::in(['L', 'P'])],
            'address' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function label(): array
    {
        return [
            'role' => 'peran',
            'name' => 'nama',
            'nis' => 'NIS',
            'nisn' => 'NISN',
            'nip' => 'NIP',
            'staff_code' => 'kode pegawai',
            'password' => 'password',
        ];
    }

    private function roleTab(Request $request): Role
    {
        return Role::tryFrom((string) $request->query('tab', '')) ?? Role::Student;
    }

    /**
     * Relasi profil yang sesuai satu peran.
     *
     * Dipakai supaya tab Guru tidak ikut memuat `student_profiles` - satu
     * tabel yang pasti kosong untuk seluruh barisnya.
     */
    private function relasiProfil(Role $role): string
    {
        return match ($role) {
            Role::Student => 'studentProfile',
            Role::Teacher => 'teacherProfile',
            Role::Supervisor => 'supervisorProfile',
            Role::Admin => 'studentProfile',
        };
    }

    /**
     * Isi profil satu akun, dalam bentuk yang dipakai form.
     *
     * Kolomnya disatukan untuk ketiga peran - `nis`, `nisn`, `nip`,
     * `staff_code`, `gender`, `address` - karena form di layar memang satu
     * form untuk ketiganya, dan yang menentukan mana yang ditampilkan adalah
     * perannya. Mengirimnya per peran berarti React harus tahu nama relasi
     * Eloquent, dan `profil.nis` yang kosong jauh lebih sederhana daripada
     * tiga cabang di sisi klien.
     *
     * @return array<string, string|null>
     */
    private function profil(User $u): array
    {
        $profil = match ($u->role) {
            Role::Student => $u->studentProfile,
            Role::Teacher => $u->teacherProfile,
            Role::Supervisor => $u->supervisorProfile,
            Role::Admin => null,
        };

        return [
            'nis' => $profil?->nis,
            'nisn' => $profil?->nisn ?? null,
            'nip' => $profil?->nip ?? null,
            'staff_code' => $profil?->staff_code ?? null,
            'gender' => $profil?->gender ?? null,
            'address' => $profil?->address ?? null,
        ];
    }

    /**
     * Nomor identitas yang ditampilkan di kolom pertama tabel.
     *
     * Bukan selalu `nis`: pada tab Guru kolom yang sama berisi NIP, dan
     * sebelumnya ia selalu kosong di sana karena kodenya membaca profil siswa
     * untuk keempat peran.
     */
    private function nomorIdentitas(User $u): ?string
    {
        return match ($u->role) {
            Role::Student => $u->studentProfile?->nis ?? $u->studentProfile?->nisn,
            Role::Teacher => $u->teacherProfile?->nip,
            Role::Supervisor => $u->supervisorProfile?->staff_code,
            Role::Admin => null,
        };
    }

    /**
     * Hitungan per tab.
     *
     * Satu query dengan group by, bukan empat count. Perhatikan bahwa
     * StudentProfile tidak ikut dihitung di sini - jumlah di tab adalah
     * jumlah AKUN, dan satu akun siswa yang belum punya profil tetap harus
     * terhitung; ia justru akun yang paling perlu dilihat Admin.
     *
     * @return array<string, int>
     */
    private function hitungan(): array
    {
        $perRole = User::query()
            ->select('role', DB::raw('count(*) as jumlah'))
            ->groupBy('role')
            ->pluck('jumlah', 'role');

        $hasil = [];

        foreach (Role::cases() as $role) {
            $hasil[$role->value] = (int) ($perRole[$role->value] ?? 0);
        }

        return $hasil;
    }
}

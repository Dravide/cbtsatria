import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    BilahFilter,
    Chip,
    Field,
    Modal,
    Paginator,
    Tabel,
    Tombol,
    kelasInput,
    pakaiFilter,
} from '@/Components/ui';

/**
 * Laman 05 - Pengguna. Wireframe plate 05.
 *
 * Empat tab peran, satu tabel. Tab bukan navigasi ke empat laman: yang
 * berubah hanya penyaring role, dan tepi halaman "1-50 dari 1.284" tetap satu
 * baris tabel yang sama.
 *
 * ---------------------------------------------------------------------
 * Username tidak diketik Admin
 * ---------------------------------------------------------------------
 * Ia diturunkan dari NIS/NIP di server. Alasannya ada di layar login: kalau
 * Admin bisa mengetik username sendiri, ia bisa mengetik yang bertabrakan
 * dengan akun sekolah lain - dan kegagalannya muncul jauh kemudian, di layar
 * login siswa pada hari ujian, sebagai pesan yang tidak menjelaskan apa pun.
 *
 * Karena itu form ini tidak menawarkan field username, dan bukan karena
 * lupa: satu-satunya tempat bentrokan itu diselesaikan adalah di server.
 */
type Profil = {
    nis: string | null;
    nisn: string | null;
    nip: string | null;
    staff_code: string | null;
    gender: string | null;
    address: string | null;
};

type Baris = {
    id: number;
    nama: string;
    username: string;
    nomor: string | null;
    email: string | null;
    phone: string | null;
    aktif: boolean;
    terakhir_masuk: string | null;
    profil: Profil;
};

type Props = {
    tab: string;
    tabnya: Record<string, number>;
    baris: Paginator<Baris>;
    filter: { cari: string; status: string };
    jumlah: number;
};

const LABEL_TAB: Record<string, string> = {
    student: 'Siswa',
    teacher: 'Guru',
    supervisor: 'Pengawas',
    admin: 'Admin',
};

/** Field yang relevan per peran - form yang sama, isi yang berbeda. */
const FIELD_PERAN: Record<string, { nama: string; label: string; kunci: string }[]> = {
    student: [
        { nama: 'nis', label: 'NIS', kunci: 'nis' },
        { nama: 'nisn', label: 'NISN', kunci: 'nisn' },
    ],
    teacher: [{ nama: 'nip', label: 'NIP', kunci: 'nip' }],
    supervisor: [{ nama: 'staff_code', label: 'Kode pegawai', kunci: 'staff_code' }],
    admin: [],
};

export default function Pengguna({ tab, tabnya, baris, filter, jumlah }: Props) {
    const [tambah, setTambah] = useState(false);
    const [pilih, setPilih] = useState<number[]>([]);
    const [ubah, setUbah] = useState<Baris | null>(null);

    const f = pakaiFilter('/admin/pengguna', { cari: filter.cari, status: filter.status });

    const gantiTab = (nilai: string) => {
        setPilih([]);
        router.get('/admin/pengguna', { tab: nilai }, { preserveState: false, replace: true });
    };

    const aksiMassal = (aksi: 'nonaktifkan' | 'aktifkan') => {
        if (pilih.length === 0) {
            return;
        }

        router.post('/admin/pengguna/massal', { id: pilih, aksi }, { preserveScroll: true });
        setPilih([]);
    };

    const resetSandi = () => {
        if (pilih.length === 0) {
            return;
        }

        router.post('/admin/pengguna/reset-password', { id: pilih }, { preserveScroll: true });
        setPilih([]);
    };

    return (
        <AdminLayout
            judul="Pengguna"
            breadcrumb="/admin/pengguna"
            aksi={
                <>
                    <Tombol disabled title="Impor Excel tersedia setelah berkas template selesai">
                        Impor
                    </Tombol>
                    <Tombol disabled title="Ekspor menyusul bersama laporan">
                        Ekspor
                    </Tombol>
                    <Tombol nada="pri" onClick={() => setTambah(true)}>
                        Tambah
                    </Tombol>
                </>
            }
        >
            <Head title="Pengguna" />

            <div className="mb-3 flex flex-wrap gap-1 border-b border-line">
                {Object.entries(LABEL_TAB).map(([nilai, label]) => (
                    <button
                        key={nilai}
                        onClick={() => gantiTab(nilai)}
                        className={
                            '-mb-px border-b-2 px-3 py-2 text-sm ' +
                            (tab === nilai
                                ? 'border-accent font-medium text-accent'
                                : 'border-transparent text-ink-2 hover:text-ink')
                        }
                    >
                        {label}
                        <span className="ml-1.5 font-mono text-[11px] text-ink-3">
                            {(tabnya[nilai] ?? 0).toLocaleString('id-ID')}
                        </span>
                    </button>
                ))}
            </div>

            <div className="mb-3">
                <BilahFilter padaKirim={f.terapkan}>
                    <input
                        value={f.nilai.cari ?? ''}
                        onChange={(e) => f.ubah('cari', e.target.value)}
                        placeholder="Cari NIS, nama, atau username..."
                        className={kelasInput + ' max-w-xs'}
                    />
                    <select
                        value={f.nilai.status ?? ''}
                        onChange={(e) => f.ubah('status', e.target.value)}
                        className={kelasInput + ' max-w-[10rem]'}
                    >
                        <option value="">Semua status</option>
                        <option value="1">Aktif</option>
                        <option value="0">Nonaktif</option>
                    </select>
                    <Tombol type="submit" nada="pri">
                        Terapkan
                    </Tombol>
                    <Tombol onClick={f.reset}>Reset</Tombol>

                    {pilih.length > 0 && (
                        <span className="ml-auto flex items-center gap-2">
                            <span className="text-xs text-ink-3">{pilih.length} terpilih</span>
                            <Tombol onClick={() => aksiMassal('nonaktifkan')}>
                                Nonaktifkan terpilih
                            </Tombol>
                            <Tombol onClick={() => aksiMassal('aktifkan')}>Aktifkan</Tombol>
                            <Tombol nada="bahaya" onClick={resetSandi}>
                                Reset password
                            </Tombol>
                        </span>
                    )}
                </BilahFilter>
            </div>

            <Tabel<Baris>
                hal={baris}
                kunci={(b) => b.id}
                kosong="Belum ada pengguna di peran ini."
                kolom={[
                    {
                        judul: '',
                        kelas: 'w-10',
                        render: (b) => (
                            <input
                                type="checkbox"
                                checked={pilih.includes(b.id)}
                                onChange={(e) =>
                                    setPilih((p) =>
                                        e.target.checked
                                            ? [...p, b.id]
                                            : p.filter((x) => x !== b.id),
                                    )
                                }
                                className="size-4 accent-accent"
                                aria-label={`Pilih ${b.nama}`}
                            />
                        ),
                    },
                    {
                        judul: 'NIS / NIP',
                        kelas: 'w-32',
                        render: (b) => (
                            <span className="font-mono text-xs text-ink-2">{b.nomor ?? '-'}</span>
                        ),
                    },
                    {
                        judul: 'Nama',
                        render: (b) => (
                            <span>
                                <span className="text-ink">{b.nama}</span>
                                <span className="ml-2 font-mono text-[11px] text-ink-3">
                                    {b.username}
                                </span>
                            </span>
                        ),
                    },
                    {
                        judul: 'Terakhir masuk',
                        kelas: 'w-32',
                        render: (b) => (
                            <span className="font-mono text-xs text-ink-3">
                                {b.terakhir_masuk ?? 'belum pernah'}
                            </span>
                        ),
                    },
                    {
                        judul: 'Status',
                        kelas: 'w-24',
                        render: (b) => (
                            <Chip nada={b.aktif ? 'ok' : 'bad'}>
                                {b.aktif ? 'aktif' : 'nonaktif'}
                            </Chip>
                        ),
                    },
                    {
                        judul: '',
                        kelas: 'w-20 text-right',
                        render: (b) => (
                            <button
                                onClick={() => setUbah(b)}
                                className="text-xs text-accent hover:underline"
                            >
                                Ubah
                            </button>
                        ),
                    },
                ]}
            />

            <p className="mt-3 text-[11px] text-ink-3">
                {jumlah.toLocaleString('id-ID')} akun pada peran ini. Akun tidak pernah
                dihapus, hanya dinonaktifkan &mdash; riwayat ujian dan presensi menunjuk ke
                sana.
            </p>

            <FormPengguna
                key={tambah ? 'tambah-terbuka' : 'tambah-tutup'}
                terbuka={tambah}
                tab={tab}
                padaTutup={() => setTambah(false)}
            />

            {/*
             * `key` di sini BUKAN hiasan. `useForm` menetapkan nilai awalnya
             * sekali saat komponen dipasang, dan komponen ini tetap terpasang
             * meski modalnya tertutup - Modal hanya mengembalikan null, ia
             * tidak melepas anaknya. Tanpa key, menekan "Ubah" pada baris
             * kedua akan menampilkan isi baris PERTAMA yang tadi dibuka: state
             * form-nya tidak pernah disetel ulang.
             */}
            <FormPengguna
                key={ubah?.id ?? 'kosong'}
                terbuka={ubah !== null}
                tab={tab}
                pengguna={ubah}
                padaTutup={() => setUbah(null)}
            />
        </AdminLayout>
    );
}

/**
 * Form tambah/ubah, satu komponen.
 *
 * Field identitas mengikuti peran: siswa punya NIS/NISN, guru punya NIP,
 * pengawas punya kode pegawai. Menampilkan ketiganya sekaligus berarti
 * menampilkan field yang pasti dikosongkan - dan field yang boleh kosong
 * selalu dikosongkan.
 */
function FormPengguna({
    terbuka,
    tab,
    pengguna,
    padaTutup,
}: {
    terbuka: boolean;
    tab: string;
    pengguna?: Baris | null;
    padaTutup: () => void;
}) {
    const ubah = Boolean(pengguna);
    const profil = pengguna?.profil;

    const { data, setData, post, put, processing, errors, reset } = useForm({
        role: tab,
        name: pengguna?.nama ?? '',
        nis: profil?.nis ?? '',
        nisn: profil?.nisn ?? '',
        nip: profil?.nip ?? '',
        staff_code: profil?.staff_code ?? '',
        email: pengguna?.email ?? '',
        phone: pengguna?.phone ?? '',
        // Selalu kosong, termasuk saat mengubah. Mengisinya dengan apa pun
        // berarti menaruh sandi orang lain di dalam DOM - dan
        // UserRegistrar::perbarui memang tidak menyentuh sandi yang tidak
        // dikirim, jadi mengosongkannya bukan kehilangan.
        password: '',
        is_active: pengguna?.aktif ?? true,
        gender: profil?.gender ?? '',
        address: profil?.address ?? '',
    });

    const kirim = (e: React.FormEvent) => {
        e.preventDefault();

        if (ubah && pengguna) {
            put(`/admin/pengguna/${pengguna.id}`, {
                onSuccess: () => {
                    padaTutup();
                    reset();
                },
            });
        } else {
            post('/admin/pengguna', {
                onSuccess: () => {
                    padaTutup();
                    reset();
                },
            });
        }
    };

    return (
        <Modal
            judul={ubah ? `Ubah ${pengguna?.nama}` : 'Tambah pengguna'}
            terbuka={terbuka}
            padaTutup={padaTutup}
        >
            <form onSubmit={kirim} className="space-y-3">
                {!ubah && (
                    <Field label="Peran" galat={errors.role}>
                        <select
                            value={data.role}
                            onChange={(e) => setData('role', e.target.value)}
                            className={kelasInput}
                        >
                            {Object.entries(LABEL_TAB).map(([nilai, label]) => (
                                <option key={nilai} value={nilai}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </Field>
                )}

                <Field label="Nama lengkap" galat={errors.name}>
                    <input
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        className={kelasInput}
                    />
                </Field>

                {(FIELD_PERAN[data.role] ?? []).map((fld) => (
                    <Field
                        key={fld.kunci}
                        label={fld.label}
                        galat={(errors as Record<string, string>)[fld.kunci]}
                        petunjuk={
                            fld.kunci === 'nis'
                                ? 'Dipakai sebagai username. Bila sudah terpakai di sekolah lain, sistem menambahkan awalan kode sekolah.'
                                : undefined
                        }
                    >
                        <input
                            value={(data as unknown as Record<string, string>)[fld.kunci] ?? ''}
                            onChange={(e) => setData(fld.kunci as never, e.target.value as never)}
                            className={kelasInput + ' font-mono'}
                        />
                    </Field>
                ))}

                <Field label="Email (opsional)" galat={errors.email}>
                    <input
                        type="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        className={kelasInput}
                    />
                </Field>

                <Field label="Telepon (opsional)" galat={errors.phone}>
                    <input
                        value={data.phone}
                        onChange={(e) => setData('phone', e.target.value)}
                        className={kelasInput + ' font-mono'}
                    />
                </Field>

                {/*
                 * Jenis kelamin dan alamat HANYA untuk siswa dan guru - dua
                 * peran yang punya kolomnya di tabel profilnya. Pengawas tidak
                 * punya `gender` sama sekali, dan menampilkan field yang akan
                 * dibuang service berarti menawarkan isian yang tidak
                 * tersimpan.
                 */}
                {data.role !== 'supervisor' && data.role !== 'admin' && (
                    <Field label="Jenis kelamin (opsional)" galat={errors.gender}>
                        <select
                            value={data.gender}
                            onChange={(e) => setData('gender', e.target.value)}
                            className={kelasInput}
                        >
                            <option value="">Belum diisi</option>
                            <option value="L">Laki-laki</option>
                            <option value="P">Perempuan</option>
                        </select>
                    </Field>
                )}

                {data.role !== 'admin' && (
                    <Field label="Alamat (opsional)" galat={errors.address}>
                        <textarea
                            value={data.address}
                            onChange={(e) => setData('address', e.target.value)}
                            rows={2}
                            className={kelasInput}
                        />
                    </Field>
                )}

                <Field
                    label={ubah ? 'Password baru (opsional)' : 'Password awal (opsional)'}
                    galat={errors.password}
                    petunjuk='Kosongkan untuk memakai "password".'
                >
                    <input
                        type="text"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        className={kelasInput + ' font-mono'}
                        autoComplete="new-password"
                    />
                </Field>

                <label className="flex cursor-pointer items-center gap-2.5 text-sm">
                    <input
                        type="checkbox"
                        checked={data.is_active}
                        onChange={(e) => setData('is_active', e.target.checked)}
                        className="size-4 accent-accent"
                    />
                    Akun aktif
                </label>

                <div className="flex gap-2 pt-1">
                    <Tombol type="submit" nada="pri" disabled={processing}>
                        {processing ? 'Menyimpan...' : 'Simpan'}
                    </Tombol>
                    <Tombol onClick={padaTutup}>Batal</Tombol>
                </div>
            </form>
        </Modal>
    );
}

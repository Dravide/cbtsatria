import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    BilahFilter,
    Chip,
    Field,
    Inbox,
    Modal,
    Paginator,
    Tabel,
    Tombol,
    kelasInput,
    nadaStatus,
    pakaiFilter,
} from '@/Components/ui';

/**
 * Laman 07 - Kelas. Wireframe plate 07.
 *
 * ---------------------------------------------------------------------
 * Pemilih TA di bar atas, bukan di dalam form
 * ---------------------------------------------------------------------
 * Wireframe menandaskannya, dan perbedaan itu bukan tata letak:
 *
 *   "Berganti TA berarti berganti daftar kelas, bukan menyaring daftar
 *    yang sama."
 *
 * Karena itu pemilihnya mengubah URL (?ta=12) dan memuat ulang daftar dari
 * server. Kalau ia diletakkan di dalam form bersama pencarian, ia akan
 * tampak seperti penyaring - dan Admin akan menyimpulkan "X-1 tidak ada"
 * padahal X-1 hanya milik tahun ajaran lain.
 *
 * Laman ini juga tidak menawarkan "semua tahun ajaran". Daftar kosong pada
 * tahun ajaran kosong adalah jawaban yang benar.
 */
type Baris = {
    id: number;
    nama: string;
    tingkat: string | null;
    wali: string | null;
    siswa: number;
    status: string;
};

type Props = {
    tahunDipilih: { id: number; nama: string; aktif: boolean } | null;
    daftarTahun: { id: number; nama: string; aktif: boolean }[];
    baris: Paginator<Baris> | { data: Baris[]; total: number };
    wali: { id: number; nama: string }[];
    filter: { cari: string };
};

export default function Kelas({ tahunDipilih, daftarTahun, baris, wali, filter }: Props) {
    const [tambah, setTambah] = useState(false);
    const [ubah, setUbah] = useState<Baris | null>(null);

    const f = pakaiFilter('/admin/kelas', { cari: filter.cari, ta: String(tahunDipilih?.id ?? '') });

    const gantiTahun = (id: string) => {
        router.get('/admin/kelas', { ta: id }, { preserveState: false, replace: true });
    };

    if (daftarTahun.length === 0) {
        return (
            <AdminLayout judul="Kelas" breadcrumb="/admin/kelas">
                <Head title="Kelas" />
                <Inbox>
                    Belum ada tahun ajaran. Kelas selalu milik satu tahun ajaran, jadi
                    tambahkan tahun ajaran lebih dulu di laman Tahun ajaran.
                </Inbox>
            </AdminLayout>
        );
    }

    return (
        <AdminLayout
            judul="Kelas"
            breadcrumb={`/admin/kelas${tahunDipilih ? ` · TA ${tahunDipilih.nama}` : ''}`}
            aksi={
                <>
                    {/* Pemilih TA di bar atas - lihat catatan di atas. */}
                    <select
                        value={String(tahunDipilih?.id ?? '')}
                        onChange={(e) => gantiTahun(e.target.value)}
                        className={kelasInput + ' w-auto font-mono'}
                    >
                        {daftarTahun.map((t) => (
                            <option key={t.id} value={t.id}>
                                TA {t.nama}
                                {t.aktif ? ' (aktif)' : ''}
                            </option>
                        ))}
                    </select>

                    {/* Impor membuka laman impor DENGAN tahun ajarannya, dan
                        itu bukan kenyamanan: nama kelas unik per tahun
                        ajaran, sehingga berkas kelas tanpa tahun yang jelas
                        tidak punya jawaban yang benar soal ke mana barisnya
                        masuk. Yang sedang dibuka di sini adalah tahun yang
                        akan menerimanya. */}
                    <Tombol
                        onClick={() =>
                            router.visit(`/admin/impor/kelas?ta=${tahunDipilih?.id ?? ''}`)
                        }
                    >
                        Impor
                    </Tombol>

                    <Tombol nada="pri" onClick={() => setTambah(true)}>
                        Tambah kelas
                    </Tombol>
                </>
            }
        >
            <Head title="Kelas" />

            {tahunDipilih && !tahunDipilih.aktif && (
                <div className="mb-3">
                    <Inbox>
                        Anda sedang melihat tahun ajaran <strong>{tahunDipilih.nama}</strong> yang
                        tidak aktif. Daftar di bawah adalah kelas tahun itu, bukan kelas tahun
                        berjalan.
                    </Inbox>
                </div>
            )}

            <div className="mb-3">
                <BilahFilter padaKirim={f.terapkan}>
                    <input
                        value={f.nilai.cari ?? ''}
                        onChange={(e) => f.ubah('cari', e.target.value)}
                        placeholder="Cari nama kelas..."
                        className={kelasInput + ' max-w-xs'}
                    />
                    <Tombol type="submit" nada="pri">
                        Terapkan
                    </Tombol>
                    <Tombol onClick={f.reset}>Reset</Tombol>
                </BilahFilter>
            </div>

            <Tabel<Baris>
                hal={baris}
                kunci={(b) => b.id}
                kosong="Belum ada kelas di tahun ajaran ini."
                kolom={[
                    {
                        judul: 'Kelas',
                        render: (b) => (
                            <span className="font-medium text-ink">{b.nama}</span>
                        ),
                    },
                    {
                        judul: 'Tingkat',
                        kelas: 'w-24',
                        render: (b) => (
                            <span className="font-mono text-xs text-ink-2">{b.tingkat ?? '-'}</span>
                        ),
                    },
                    {
                        judul: 'Wali kelas',
                        render: (b) => (
                            <span className="text-ink-2">{b.wali ?? 'belum ditentukan'}</span>
                        ),
                    },
                    {
                        judul: 'Siswa',
                        kelas: 'w-24',
                        render: (b) => (
                            <span className="font-mono text-sm text-ink">
                                {b.siswa}
                            </span>
                        ),
                    },
                    {
                        judul: 'Status',
                        kelas: 'w-24',
                        render: (b) => <Chip nada={nadaStatus(b.status)}>{b.status}</Chip>,
                    },
                    {
                        judul: '',
                        kelas: 'w-40 text-right',
                        render: (b) => (
                            <span className="flex justify-end gap-3">
                                <Link
                                    href={`/admin/kelas/${b.id}/anggota`}
                                    className="text-xs text-accent hover:underline"
                                >
                                    Kelola anggota
                                </Link>
                                <button
                                    onClick={() => setUbah(b)}
                                    className="text-xs text-accent hover:underline"
                                >
                                    Ubah
                                </button>
                            </span>
                        ),
                    },
                ]}
            />

            <FormKelas
                // Tahun ajaran yang sedang dipilih ikut jadi kunci: berpindah
                // tab tahun lalu membuka "Tambah kelas" akan menyimpan kelas
                // baru ke tahun yang PERTAMA dibuka, bukan yang sedang tampil.
                key={'baru-' + (tahunDipilih?.id ?? 'kosong')}
                terbuka={tambah}
                tahunId={tahunDipilih?.id ?? null}
                wali={wali}
                padaTutup={() => setTambah(false)}
            />

            {/* `key` bukan hiasan: `Modal` mengembalikan `null` dari dalam
                dirinya sendiri, jadi form di luarnya tidak pernah dilepas dan
                `useForm` menyimpan nilai awalnya sejak panel masih tertutup -
                saat `kelas` masih kosong. Tanpa `key`, "Ubah" membuka kolom
                kosong. */}
            <FormKelas
                key={'ubah-' + (ubah?.id ?? 'kosong')}
                terbuka={ubah !== null}
                tahunId={tahunDipilih?.id ?? null}
                wali={wali}
                kelas={ubah}
                padaTutup={() => setUbah(null)}
            />
        </AdminLayout>
    );
}

function FormKelas({
    terbuka,
    tahunId,
    wali,
    kelas,
    padaTutup,
}: {
    terbuka: boolean;
    tahunId: number | null;
    wali: { id: number; nama: string }[];
    kelas?: Baris | null;
    padaTutup: () => void;
}) {
    const ubah = Boolean(kelas);

    const { data, setData, post, put, processing, errors, reset } = useForm({
        academic_year_id: tahunId ?? 0,
        name: kelas?.nama ?? '',
        grade_level: kelas?.tingkat ?? '',
        homeroom_teacher_id: '',
        status: kelas?.status ?? 'aktif',
    });

    return (
        <Modal
            judul={ubah ? `Ubah ${kelas?.nama}` : 'Tambah kelas'}
            terbuka={terbuka}
            padaTutup={padaTutup}
        >
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    const opsi = {
                        onSuccess: () => {
                            padaTutup();
                            reset();
                        },
                    };

                    if (ubah && kelas) {
                        put(`/admin/kelas/${kelas.id}`, opsi);
                    } else {
                        post('/admin/kelas', opsi);
                    }
                }}
                className="space-y-3"
            >
                <Field
                    label="Nama kelas"
                    galat={errors.name}
                    petunjuk="Nama boleh sama di tahun ajaran berbeda - X-1 muncul lagi setiap tahun."
                >
                    <input
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        placeholder="X-1"
                        className={kelasInput}
                    />
                </Field>

                <Field label="Tingkat" galat={errors.grade_level}>
                    <input
                        value={data.grade_level}
                        onChange={(e) => setData('grade_level', e.target.value)}
                        placeholder="X / XI / XII"
                        className={kelasInput}
                    />
                </Field>

                <Field
                    label="Wali kelas"
                    galat={errors.homeroom_teacher_id}
                    petunjuk="Hanya akun berguru yang ditawarkan di sini."
                >
                    <select
                        value={data.homeroom_teacher_id}
                        onChange={(e) => setData('homeroom_teacher_id', e.target.value)}
                        className={kelasInput}
                    >
                        <option value="">Belum ditentukan</option>
                        {wali.map((w) => (
                            <option key={w.id} value={w.id}>
                                {w.nama}
                            </option>
                        ))}
                    </select>
                </Field>

                <Field label="Status" galat={errors.status}>
                    <select
                        value={data.status}
                        onChange={(e) => setData('status', e.target.value)}
                        className={kelasInput}
                    >
                        <option value="aktif">aktif</option>
                        <option value="nonaktif">nonaktif</option>
                    </select>
                </Field>

                <div className="flex gap-2 pt-1">
                    <Tombol type="submit" nada="pri" disabled={processing}>
                        Simpan
                    </Tombol>
                    <Tombol onClick={padaTutup}>Batal</Tombol>
                </div>
            </form>
        </Modal>
    );
}

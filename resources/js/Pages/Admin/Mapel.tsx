import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    BilahFilter,
    Chip,
    Field,
    Inbox,
    Modal,
    Paginator,
    Tombol,
    kelasInput,
    nadaStatus,
    pakaiFilter,
} from '@/Components/ui';

/**
 * Laman 08 - Mata pelajaran + penugasan mengajar. Wireframe plate 08.
 *
 * ---------------------------------------------------------------------
 * Penugasan mengajar ada di laman ini, bukan di laman Guru
 * ---------------------------------------------------------------------
 * Satu baris penugasan adalah satu IZIN: guru A boleh menyusun soal untuk
 * mapel B di kelas C. Tabel itu milik mapel - tanpa mapel dan kelas ia tidak
 * punya arti, sementara guru hanya salah satu kolomnya.
 *
 * Kalau dipindahkan ke laman Guru, Admin harus membuka dua layar untuk
 * menjawab satu pertanyaan yang sebenarnya satu: "siapa yang mengajar MTK di
 * X-1?" - dan jawabannya dibaca dari dua tempat yang bisa tidak sinkron.
 * Karena itu penugasannya ditampilkan langsung di baris mapelnya.
 *
 * Akibatnya di Fase 4: bank soal Guru dibatasi tabel ini, bukan daftar yang
 * disimpan di laman Guru. Sumber kebenarannya satu.
 */
type Penugasan = { id: number; guru: string | null; kelas: string | null };

type Baris = {
    id: number;
    kode: string;
    nama: string;
    kelompok: string | null;
    tingkat: string | null;
    status: string;
    penugasan: Penugasan[];
};

type Props = {
    baris: Paginator<Baris>;
    filter: { cari: string; status: string };
    jumlah: number;
    guru: { id: number; nama: string }[];
    kelas: { id: number; nama: string }[];
    tahunAjaranAktif: string | null;
};

export default function Mapel({
    baris,
    filter,
    jumlah,
    guru,
    kelas,
    tahunAjaranAktif,
}: Props) {
    const [tambah, setTambah] = useState(false);
    const [ubah, setUbah] = useState<Baris | null>(null);
    const [tugaskan, setTugaskan] = useState<Baris | null>(null);

    const f = pakaiFilter('/admin/mapel', { cari: filter.cari, status: filter.status });

    return (
        <AdminLayout
            judul="Mata pelajaran"
            breadcrumb="/admin/mapel"
            aksi={
                <>
                    <Tombol disabled title="Impor Excel tersedia setelah berkas template selesai">
                        Impor
                    </Tombol>
                    <Tombol nada="pri" onClick={() => setTambah(true)}>
                        Tambah mapel
                    </Tombol>
                </>
            }
        >
            <Head title="Mata pelajaran" />

            {!tahunAjaranAktif && (
                <div className="mb-3">
                    <Inbox>
                        Belum ada tahun ajaran aktif, jadi penugasan mengajar belum bisa
                        dibuat &mdash; daftar kelas masih kosong. Aktifkan tahun ajaran di
                        laman Tahun ajaran.
                    </Inbox>
                </div>
            )}

            <div className="mb-3">
                <BilahFilter padaKirim={f.terapkan}>
                    <input
                        value={f.nilai.cari ?? ''}
                        onChange={(e) => f.ubah('cari', e.target.value)}
                        placeholder="Cari kode atau nama mapel..."
                        className={kelasInput + ' max-w-xs'}
                    />
                    <select
                        value={f.nilai.status ?? ''}
                        onChange={(e) => f.ubah('status', e.target.value)}
                        className={kelasInput + ' max-w-[10rem]'}
                    >
                        <option value="">Semua status</option>
                        <option value="aktif">Aktif</option>
                        <option value="nonaktif">Nonaktif</option>
                    </select>
                    <Tombol type="submit" nada="pri">
                        Terapkan
                    </Tombol>
                    <Tombol onClick={f.reset}>Reset</Tombol>
                </BilahFilter>
            </div>

            <div className="space-y-3">
                {baris.data?.length === 0 && (
                    <div className="rounded-wf border border-line bg-surface px-3 py-8 text-center text-sm text-ink-3">
                        Belum ada mata pelajaran.
                    </div>
                )}

                {/* Satu mapel satu kotak, bukan satu baris tabel: penugasan
                    mengajar butuh ruang, dan baris tabel tidak punya tempat
                    untuk menampungnya tanpa menggulir mendatar. */}
                {baris.data?.map((m) => (
                    <div key={m.id} className="rounded-wf border border-line bg-surface">
                        <div className="flex flex-wrap items-center gap-3 border-b border-line px-4 py-2.5">
                            <span className="font-mono text-xs text-ink-3">{m.kode}</span>
                            <span className="font-medium text-ink">{m.nama}</span>
                            {m.kelompok && <Chip nada="info">{m.kelompok}</Chip>}
                            {m.tingkat && <Chip nada="mute">{m.tingkat}</Chip>}
                            <Chip nada={nadaStatus(m.status)}>{m.status}</Chip>

                            <span className="ml-auto flex gap-3">
                                <button
                                    onClick={() => setTugaskan(m)}
                                    className="text-xs text-accent hover:underline"
                                >
                                    Tugaskan guru
                                </button>
                                <button
                                    onClick={() => setUbah(m)}
                                    className="text-xs text-accent hover:underline"
                                >
                                    Ubah
                                </button>
                            </span>
                        </div>

                        <div className="px-4 py-2.5">
                            <p className="text-[11px] font-medium tracking-wide text-ink-3 uppercase">
                                Penugasan mengajar
                            </p>

                            {m.penugasan.length === 0 ? (
                                <p className="mt-1.5 text-xs text-ink-3">
                                    Belum ada guru ditugaskan. Tanpa penugasan, tidak ada guru
                                    yang dapat menyusun soal untuk mapel ini.
                                </p>
                            ) : (
                                <ul className="mt-1.5 flex flex-wrap gap-1.5">
                                    {m.penugasan.map((p) => (
                                        <li key={p.id}>
                                            <span className="inline-flex items-center gap-2 rounded-wf border border-line bg-surface-2/60 px-2 py-1 text-xs text-ink-2">
                                                <span>
                                                    {p.guru} &middot; {p.kelas}
                                                </span>
                                                <button
                                                    onClick={() =>
                                                        router.delete(`/admin/penugasan/${p.id}`, {
                                                            preserveScroll: true,
                                                        })
                                                    }
                                                    className="text-ink-3 hover:text-bad"
                                                    aria-label="Batalkan penugasan"
                                                >
                                                    &#10005;
                                                </button>
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </div>
                ))}
            </div>

            <p className="mt-3 text-[11px] text-ink-3">
                {jumlah.toLocaleString('id-ID')} mata pelajaran.
            </p>

            <FormMapel terbuka={tambah} padaTutup={() => setTambah(false)} />
            <FormMapel terbuka={ubah !== null} mapel={ubah} padaTutup={() => setUbah(null)} />

            <FormTugaskan
                mapel={tugaskan}
                guru={guru}
                kelas={kelas}
                padaTutup={() => setTugaskan(null)}
            />
        </AdminLayout>
    );
}

function FormMapel({
    terbuka,
    mapel,
    padaTutup,
}: {
    terbuka: boolean;
    mapel?: Baris | null;
    padaTutup: () => void;
}) {
    const ubah = Boolean(mapel);

    const { data, setData, post, put, processing, errors, reset } = useForm({
        code: mapel?.kode ?? '',
        name: mapel?.nama ?? '',
        category: mapel?.kelompok ?? '',
        grade_level: mapel?.tingkat ?? '',
        status: mapel?.status ?? 'aktif',
    });

    return (
        <Modal
            judul={ubah ? `Ubah ${mapel?.nama}` : 'Tambah mata pelajaran'}
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

                    if (ubah && mapel) {
                        put(`/admin/mapel/${mapel.id}`, opsi);
                    } else {
                        post('/admin/mapel', opsi);
                    }
                }}
                className="space-y-3"
            >
                <div className="grid gap-3 sm:grid-cols-[6rem_1fr]">
                    <Field label="Kode" galat={errors.code}>
                        <input
                            value={data.code}
                            onChange={(e) => setData('code', e.target.value)}
                            placeholder="MTK"
                            className={kelasInput + ' font-mono'}
                        />
                    </Field>
                    <Field label="Nama" galat={errors.name}>
                        <input
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            className={kelasInput}
                        />
                    </Field>
                </div>

                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Kelompok" galat={errors.category}>
                        <input
                            value={data.category}
                            onChange={(e) => setData('category', e.target.value)}
                            placeholder="Wajib / Peminatan / Kejuruan"
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
                </div>

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

function FormTugaskan({
    mapel,
    guru,
    kelas,
    padaTutup,
}: {
    mapel: Baris | null;
    guru: { id: number; nama: string }[];
    kelas: { id: number; nama: string }[];
    padaTutup: () => void;
}) {
    const { data, setData, post, processing, errors } = useForm({
        teacher_id: '',
        class_id: '',
    });

    return (
        <Modal
            judul={mapel ? `Tugaskan guru pada ${mapel.nama}` : 'Tugaskan guru'}
            terbuka={mapel !== null}
            padaTutup={padaTutup}
        >
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    post(`/admin/mapel/${mapel?.id}/tugaskan`, {
                        onSuccess: () => {
                            padaTutup();
                            setData({ teacher_id: '', class_id: '' });
                        },
                    });
                }}
                className="space-y-3"
            >
                <Field label="Guru" galat={errors.teacher_id}>
                    <select
                        value={data.teacher_id}
                        onChange={(e) => setData('teacher_id', e.target.value)}
                        className={kelasInput}
                    >
                        <option value="">Pilih guru...</option>
                        {guru.map((g) => (
                            <option key={g.id} value={g.id}>
                                {g.nama}
                            </option>
                        ))}
                    </select>
                </Field>

                <Field
                    label="Kelas"
                    galat={errors.class_id}
                    petunjuk="Kelas dari tahun ajaran aktif saja."
                >
                    <select
                        value={data.class_id}
                        onChange={(e) => setData('class_id', e.target.value)}
                        className={kelasInput}
                    >
                        <option value="">Pilih kelas...</option>
                        {kelas.map((k) => (
                            <option key={k.id} value={k.id}>
                                {k.nama}
                            </option>
                        ))}
                    </select>
                </Field>

                <Inbox>
                    Baris ini adalah satu izin: guru tersebut boleh menyusun soal untuk mapel
                    ini di kelas itu, dan tidak untuk yang lain.
                </Inbox>

                <div className="flex gap-2 pt-1">
                    <Tombol type="submit" nada="pri" disabled={processing}>
                        Tugaskan
                    </Tombol>
                    <Tombol onClick={padaTutup}>Batal</Tombol>
                </div>
            </form>
        </Modal>
    );
}

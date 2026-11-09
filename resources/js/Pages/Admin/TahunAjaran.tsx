import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Chip, Field, Inbox, Kotak, Modal, Tombol, kelasInput } from '@/Components/ui';

/**
 * Laman 06 - Tahun ajaran & semester. Wireframe plate 06.
 *
 * ---------------------------------------------------------------------
 * Peringatan penutupan ditulis di badan laman, bukan di dialog konfirmasi
 * ---------------------------------------------------------------------
 * "Menutup tahun ajaran tidak menghapus data. Kelas, penugasan, dan event lama
 * diarsipkan dan tetap dapat dibuka sebagai arsip."
 *
 * Keputusan paling berisiko di laman ini bukan klik "Tutup" - melainkan
 * keyakinan Admin bahwa menutup berarti menghapus. Keyakinan itu terbentuk
 * sebelum ia mengklik apa pun, jadi penjelasannya harus ada sebelum ia
 * mengklik apa pun. Dialog konfirmasi dibaca setelah keputusan diambil, dan
 * karena itu tidak pernah mengubah keputusan.
 *
 * ---------------------------------------------------------------------
 * Semester tampil bersarang di dalam tahun ajarannya
 * ---------------------------------------------------------------------
 * Bukan laman terpisah, karena semester selalu milik satu tahun ajaran.
 * Memisahkannya berarti menawarkan semester yang tidak punya induk - bentuk
 * yang tidak ada di datanya, dan seharusnya tidak ada di layarnya.
 */
type Semester = {
    id: number;
    nama: string;
    mulai: string | null;
    selesai: string | null;
    aktif: boolean;
};

type Tahun = {
    id: number;
    nama: string;
    mulai: string | null;
    selesai: string | null;
    aktif: boolean;
    arsip: boolean;
    semester: Semester[];
};

export default function TahunAjaran({ tahun }: { tahun: Tahun[] }) {
    const [tambah, setTambah] = useState(false);
    const [semesterUntuk, setSemesterUntuk] = useState<Tahun | null>(null);

    return (
        <AdminLayout
            judul="Tahun ajaran & semester"
            breadcrumb="/admin/tahun-ajaran"
            aksi={
                <Tombol nada="pri" onClick={() => setTambah(true)}>
                    Tambah tahun ajaran
                </Tombol>
            }
        >
            <Head title="Tahun ajaran" />

            <div className="mb-3">
                <Inbox>
                    Menutup tahun ajaran tidak menghapus data. Kelas, penugasan, dan event
                    lama diarsipkan dan tetap dapat dibuka sebagai arsip. Hanya satu tahun
                    ajaran yang dapat aktif pada satu waktu.
                </Inbox>
            </div>

            <div className="space-y-3">
                {tahun.length === 0 && (
                    <Kotak>
                        <p className="py-6 text-center text-sm text-ink-3">
                            Belum ada tahun ajaran. Tambahkan yang pertama untuk mulai
                            menyusun kelas.
                        </p>
                    </Kotak>
                )}

                {tahun.map((t) => (
                    <Kotak
                        key={t.id}
                        judul={undefined}
                        aksi={undefined}
                    >
                        <div className="flex flex-wrap items-center gap-3">
                            <span className="font-display text-lg font-semibold text-ink">
                                {t.nama}
                            </span>

                            {t.aktif && <Chip nada="ok">aktif</Chip>}
                            {t.arsip && <Chip nada="mute">arsip</Chip>}
                            {!t.aktif && !t.arsip && <Chip nada="info">belum dimulai</Chip>}

                            <span className="font-mono text-xs text-ink-3">
                                {t.mulai} &ndash; {t.selesai}
                            </span>

                            <span className="ml-auto flex gap-2">
                                {!t.aktif && (
                                    <Tombol
                                        nada="pri"
                                        onClick={() =>
                                            router.post(
                                                `/admin/tahun-ajaran/${t.id}/aktifkan`,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Aktifkan
                                    </Tombol>
                                )}

                                {t.aktif && (
                                    <Tombol
                                        onClick={() =>
                                            router.post(
                                                `/admin/tahun-ajaran/${t.id}/tutup`,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Tutup tahun ajaran
                                    </Tombol>
                                )}

                                <Tombol onClick={() => setSemesterUntuk(t)}>
                                    Tambah semester
                                </Tombol>
                            </span>
                        </div>

                        {/* Semester bersarang di dalam tahun ajarannya. */}
                        <div className="mt-3 rounded-wf border border-line bg-surface-2/40 p-3">
                            <p className="text-[11px] text-ink-3">
                                Semester di {t.nama}
                            </p>

                            {t.semester.length === 0 ? (
                                <p className="mt-2 text-xs text-ink-3">
                                    Belum ada semester. Tahun ajaran tanpa semester tidak
                                    dapat dipakai membuat event.
                                </p>
                            ) : (
                                <ul className="mt-2 space-y-1.5">
                                    {t.semester.map((s) => (
                                        <li
                                            key={s.id}
                                            className="flex flex-wrap items-center gap-2.5 text-sm"
                                        >
                                            <span className="text-ink">{s.nama}</span>
                                            <Chip nada={s.aktif ? 'ok' : 'mute'}>
                                                {s.aktif ? 'aktif' : 'belum'}
                                            </Chip>
                                            <span className="font-mono text-[11px] text-ink-3">
                                                {s.mulai} &ndash; {s.selesai}
                                            </span>

                                            {!s.aktif && (
                                                <button
                                                    onClick={() =>
                                                        router.post(
                                                            `/admin/semester/${s.id}/aktifkan`,
                                                            {},
                                                            { preserveScroll: true },
                                                        )
                                                    }
                                                    className="ml-auto text-xs text-accent hover:underline"
                                                >
                                                    Aktifkan
                                                </button>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </Kotak>
                ))}
            </div>

            <FormTahun terbuka={tambah} padaTutup={() => setTambah(false)} />

            <FormSemester
                // `key` bukan hiasan: `Modal` mengembalikan `null` dari dalam
                // dirinya sendiri, jadi form di luarnya tidak pernah dilepas.
                // Berpindah tahun ajaran lalu membuka "Tambah semester" tanpa
                // menutup panelnya akan membawa isian tahun sebelumnya.
                key={'semester-' + (semesterUntuk?.id ?? 'kosong')}
                tahun={semesterUntuk}
                padaTutup={() => setSemesterUntuk(null)}
            />
        </AdminLayout>
    );
}

function FormTahun({ terbuka, padaTutup }: { terbuka: boolean; padaTutup: () => void }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        start_date: '',
        end_date: '',
    });

    return (
        <Modal judul="Tambah tahun ajaran" terbuka={terbuka} padaTutup={padaTutup}>
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    post('/admin/tahun-ajaran', {
                        onSuccess: () => {
                            padaTutup();
                            reset();
                        },
                    });
                }}
                className="space-y-3"
            >
                <Field
                    label="Nama tahun ajaran"
                    galat={errors.name}
                    petunjuk="Format YYYY/YYYY, mis. 2027/2028."
                >
                    <input
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        placeholder="2027/2028"
                        className={kelasInput + ' font-mono'}
                    />
                </Field>

                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Mulai" galat={errors.start_date}>
                        <input
                            type="date"
                            value={data.start_date}
                            onChange={(e) => setData('start_date', e.target.value)}
                            className={kelasInput}
                        />
                    </Field>
                    <Field label="Selesai" galat={errors.end_date}>
                        <input
                            type="date"
                            value={data.end_date}
                            onChange={(e) => setData('end_date', e.target.value)}
                            className={kelasInput}
                        />
                    </Field>
                </div>

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

function FormSemester({ tahun, padaTutup }: { tahun: Tahun | null; padaTutup: () => void }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: 'Ganjil',
        start_date: '',
        end_date: '',
    });

    return (
        <Modal
            judul={tahun ? `Tambah semester di ${tahun.nama}` : 'Tambah semester'}
            terbuka={tahun !== null}
            padaTutup={padaTutup}
        >
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    post(`/admin/tahun-ajaran/${tahun?.id}/semester`, {
                        onSuccess: () => {
                            padaTutup();
                            reset();
                        },
                    });
                }}
                className="space-y-3"
            >
                <Field label="Semester" galat={errors.name}>
                    <select
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        className={kelasInput}
                    >
                        <option value="Ganjil">Ganjil</option>
                        <option value="Genap">Genap</option>
                    </select>
                </Field>

                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Mulai" galat={errors.start_date}>
                        <input
                            type="date"
                            value={data.start_date}
                            onChange={(e) => setData('start_date', e.target.value)}
                            className={kelasInput}
                        />
                    </Field>
                    <Field label="Selesai" galat={errors.end_date}>
                        <input
                            type="date"
                            value={data.end_date}
                            onChange={(e) => setData('end_date', e.target.value)}
                            className={kelasInput}
                        />
                    </Field>
                </div>

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

import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import GuruLayout from '@/Layouts/GuruLayout';
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
 * Daftar paket soal - wireframe-peran.html plate 05 (versi daftar).
 *
 * ---------------------------------------------------------------------
 * Paket Guru lain ikut tampil, dan itu bukan kelalaian
 * ---------------------------------------------------------------------
 * Sama seperti bank soal: yang dibatasi adalah MAPEL, bukan penulis. Guru
 * yang menyusun ujian paralel perlu tahu paket apa yang sudah ada untuk
 * mapelnya - kalau tidak, dua Guru menyusun paket Matematika X yang hampir
 * sama, dan yang kedua menemukannya sehari sebelum ujian.
 *
 * Yang tidak boleh adalah MENGUBAHNYA, dan itu yang membedakan tombol di
 * kolom aksi. Baris milik Guru lain hanya menampilkan "Lihat".
 */
type Baris = {
    id: number;
    kode: string;
    nama: string;
    mapel: string | null;
    tingkat: string | null;
    status: string;
    status_label: string;
    butir: number;
    penulis: string | null;
    milik_saya: boolean;
};

type Props = {
    baris: Paginator<Baris>;
    filter: { cari: string; mapel: string; status: string };
    mapel: { id: number; nama: string; kode: string | null }[];
    bolehMenulis: boolean;
};

export default function PaketIndex({ baris, filter, mapel, bolehMenulis }: Props) {
    const [buka, setBuka] = useState(false);
    const [kirim, setKirim] = useState(false);

    const f = pakaiFilter('/guru/paket', {
        cari: filter.cari,
        mapel: filter.mapel,
        status: filter.status,
    });

    return (
        <GuruLayout
            judul="Paket soal"
            breadcrumb="/guru/paket"
            aksi={
                bolehMenulis && (
                    <button
                        onClick={() => setBuka(true)}
                        className="rounded-wf border border-accent bg-accent px-3 py-1.5 text-xs font-medium text-on-accent hover:bg-accent/90"
                    >
                        Buat paket
                    </button>
                )
            }
        >
            <Head title="Paket soal" />

            <div className="mb-3">
                <Inbox>
                    Paket hanya <strong>menunjuk</strong> soal, tidak memilikinya.
                    Mengeluarkan butir dari paket tidak menghapus soalnya dari bank soal —
                    satu soal bagus boleh dipakai di tiga ujian berbeda.
                </Inbox>
            </div>

            <div className="mb-3">
                <BilahFilter padaKirim={f.terapkan}>
                    <select
                        value={f.nilai.mapel ?? ''}
                        onChange={(e) => f.ubah('mapel', e.target.value)}
                        className={kelasInput + ' max-w-[14rem]'}
                    >
                        <option value="">Semua mata pelajaran</option>
                        {mapel.map((m) => (
                            <option key={m.id} value={m.id}>
                                {m.nama}
                            </option>
                        ))}
                    </select>

                    <select
                        value={f.nilai.status ?? ''}
                        onChange={(e) => f.ubah('status', e.target.value)}
                        className={kelasInput + ' max-w-[10rem]'}
                    >
                        <option value="">Semua status</option>
                        <option value="draft">Draft</option>
                        <option value="siap">Siap</option>
                    </select>

                    <input
                        value={f.nilai.cari ?? ''}
                        onChange={(e) => f.ubah('cari', e.target.value)}
                        placeholder="Cari nama atau kode paket..."
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
                kosong="Belum ada paket soal untuk mata pelajaran yang Anda ampu."
                kolom={[
                    {
                        judul: 'Paket',
                        render: (b) => (
                            <div className="min-w-0">
                                <Link
                                    href={`/guru/paket/${b.id}`}
                                    className="block truncate font-medium text-ink hover:text-accent"
                                >
                                    {b.nama}
                                </Link>
                                <span className="mt-0.5 flex flex-wrap items-center gap-2 text-[11px] text-ink-3">
                                    {/* Kode paket berdiri paling depan, mono,
                                        karena ia satu-satunya bagian baris ini
                                        yang diketik orang - sisanya dibaca. */}
                                    <span className="font-mono text-ink-2">{b.kode}</span>
                                    {b.mapel && <span>{b.mapel}</span>}
                                    {b.tingkat && <span>{b.tingkat}</span>}
                                    {!b.milik_saya && b.penulis && (
                                        <span title="Paket Guru lain - boleh dibaca, tidak boleh diubah">
                                            penulis: {b.penulis}
                                        </span>
                                    )}
                                </span>
                            </div>
                        ),
                    },
                    {
                        judul: 'Butir',
                        kelas: 'w-20',
                        render: (b) => (
                            <span className="font-mono text-xs text-ink-2">
                                {b.butir > 0 ? b.butir : '—'}
                            </span>
                        ),
                    },
                    {
                        judul: 'Status',
                        kelas: 'w-24',
                        render: (b) => (
                            <Chip nada={nadaStatus(b.status)}>{b.status_label}</Chip>
                        ),
                    },
                    {
                        judul: '',
                        kelas: 'w-24 text-right',
                        render: (b) => (
                            <Link
                                href={`/guru/paket/${b.id}`}
                                className="text-xs font-medium text-accent hover:underline"
                            >
                                {b.milik_saya ? 'Buka' : 'Lihat'}
                            </Link>
                        ),
                    },
                ]}
            />

            <Modal judul="Buat paket soal" terbuka={buka} padaTutup={() => setBuka(false)}>
                <BuatPaket
                    mapel={mapel}
                    kirim={kirim}
                    padaKirim={setKirim}
                    padaSukses={() => setBuka(false)}
                />
            </Modal>
        </GuruLayout>
    );
}

/* -------------------------------------------------------- Form buat paket */

type PropsBuat = {
    mapel: { id: number; nama: string; kode: string | null }[];
    kirim: boolean;
    padaKirim: (v: boolean) => void;
    padaSukses: () => void;
};

/**
 * Form pembuatan paket, terpisah dari `useForm` laman ini.
 *
 * Dipisah supaya `useForm`-nya punya tipe konkret: Inertia 3 menuntut `T`
 * memenuhi `FormDataType<T>`, dan `Record<string, unknown>` tidak memenuhinya.
 */
function BuatPaket({ mapel, kirim, padaKirim, padaSukses }: PropsBuat) {
    const [mapelId, setMapelId] = useState('');
    const [nama, setNama] = useState('');
    const [tingkat, setTingkat] = useState('');
    const [keterangan, setKeterangan] = useState('');
    const [galat, setGalat] = useState<Record<string, string>>({});

    const simpan = () => {
        if (kirim) {
            return;
        }

        setGalat({});
        padaKirim(true);

        // `router.post` mentah, bukan `useForm`, karena form ini hanya punya
        // empat field datar dan tidak ada unggahan - `useForm` akan menambah
        // satu lapis tipe tanpa menambah satu pun perilaku.
        router.post(
            '/guru/paket',
            {
                subject_id: mapelId,
                name: nama,
                grade_level: tingkat || null,
                description: keterangan || null,
            },
            {
                onError: (e) => setGalat(e as Record<string, string>),
                onFinish: () => padaKirim(false),
                onSuccess: () => padaSukses(),
            },
        );
    };

    return (
        <div className="space-y-3">
            <Field label="Mata pelajaran" galat={galat.subject_id}>
                <select
                    value={mapelId}
                    onChange={(e) => setMapelId(e.target.value)}
                    className={kelasInput}
                >
                    <option value="">Pilih mata pelajaran…</option>
                    {mapel.map((m) => (
                        <option key={m.id} value={m.id}>
                            {m.nama}
                        </option>
                    ))}
                </select>
            </Field>

            <Field
                label="Nama paket"
                galat={galat.name}
                petunjuk="Contoh: Matematika X - Penilaian Akhir Semester Ganjil"
            >
                <input
                    value={nama}
                    onChange={(e) => setNama(e.target.value)}
                    className={kelasInput}
                />
            </Field>

            <Field label="Tingkat kelas" galat={galat.grade_level} petunjuk="Opsional.">
                <input
                    value={tingkat}
                    onChange={(e) => setTingkat(e.target.value)}
                    placeholder="X, XI, XII…"
                    className={kelasInput}
                />
            </Field>

            <Field label="Keterangan" galat={galat.description} petunjuk="Opsional.">
                <textarea
                    value={keterangan}
                    onChange={(e) => setKeterangan(e.target.value)}
                    rows={2}
                    className={kelasInput}
                />
            </Field>

            <p className="text-[11px] text-ink-3">
                Butir ditambahkan setelah paketnya ada. Paket yang baru dibuat berstatus
                Draft — status Siap hanya bisa diberikan bila paketnya sudah berisi butir.
            </p>

            <div className="flex justify-end gap-2">
                <Tombol onClick={padaSukses}>Batal</Tombol>
                <Tombol nada="pri" onClick={simpan} disabled={kirim || !mapelId || !nama}>
                    {kirim ? 'Menyimpan…' : 'Buat paket'}
                </Tombol>
            </div>
        </div>
    );
}

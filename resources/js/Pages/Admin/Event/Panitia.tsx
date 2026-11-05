import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Chip, Field, Inbox, Modal, Tabel, Tombol, kelasInput, nadaStatus } from '@/Components/ui';

/**
 * Laman 13 - Kepanitiaan. Wireframe plate 13, PRD §6.6.
 *
 * ---------------------------------------------------------------------
 * Kolom "Cakupan" adalah alasan laman ini ada
 * ---------------------------------------------------------------------
 * Plate 13 mencatatnya dan itu dikutip apa adanya: cakupan "membatasi apa
 * yang boleh mereka lihat dan ubah - dan itu sebabnya cakupan harus terlihat
 * di tabel, bukan tersembunyi di halaman detail."
 *
 * Karena itu kolomnya memuat KODE RUANG, bukan jumlahnya. "2 ruang" tidak
 * memberi tahu ruang mana, dan pertanyaan berikutnya selalu ruang mana -
 * sehingga Admin membuka barisnya satu per satu untuk memastikan penempatan
 * pengawas benar. Pada sekolah dengan 24 ruang dan 31 pengawas, itu 31 kali
 * membuka.
 *
 * ---------------------------------------------------------------------
 * Panitia bukan peran kelima
 * ---------------------------------------------------------------------
 * PRD §6.6: kepanitiaan adalah tambahan atas peran yang sudah ada. Seorang
 * Guru yang menjadi ketua panitia tetap Guru - ia hanya mendapat satu
 * kewenangan tambahan di event ini. Karena itu laman ini tidak pernah
 * membuat akun, hanya MENAUTKAN akun yang sudah ada, dan menyediakan kolom
 * nama bebas untuk panitia yang tidak punya akun (mis. perwakilan komite
 * sekolah).
 */
type Baris = {
    id: number;
    nama: string;
    jabatan: string;
    cakupan: string;
    cakupan_kode: string;
    ruang_id: number[];
    ruang_kode: string[];
    status: string;
    akun: string | null;
    email: string | null;
    phone: string | null;
};

type Props = {
    event: { id: number; kode: string; nama: string; status: string; status_label: string };
    panitia: Baris[];
    akun: { id: number; nama: string; username: string; peran: string }[];
    ruang: { id: number; kode: string; nama: string }[];
    jabatan: string[];
};

export default function EventPanitia({ event, panitia, akun, ruang, jabatan }: Props) {
    const [panel, setPanel] = useState<'tutup' | 'tambah' | number>('tutup');

    const sedangDiubah = typeof panel === 'number' ? panitia.find((p) => p.id === panel) : undefined;

    const tutup = () => setPanel('tutup');

    return (
        <AdminLayout
            judul="Kepanitiaan"
            breadcrumb={`/admin/events · ${event.kode} · Kepanitiaan`}
            aksi={
                <Tombol nada="pri" onClick={() => setPanel('tambah')}>
                    Tambah panitia
                </Tombol>
            }
        >
            <Head title={`Kepanitiaan · ${event.nama}`} />

            <div className="mb-3 grid gap-3 sm:grid-cols-3">
                <KPI label="Panitia" nilai={panitia.length} />
                <KPI
                    label="Pengawas"
                    nilai={panitia.filter((p) => p.jabatan.includes('pengawas')).length}
                />
                <KPI
                    label="Cakupan ruang"
                    nilai={panitia.filter((p) => p.cakupan_kode === 'ruang').length}
                />
            </div>

            <Tabel<Baris>
                hal={{ data: panitia, total: panitia.length }}
                kunci={(b) => b.id}
                kosong="Belum ada panitia. Event tanpa panitia tidak bisa mencapai status siap."
                kolom={[
                    {
                        judul: 'Nama',
                        render: (b) => (
                            <span>
                                <span className="font-medium text-ink">{b.nama}</span>
                                {b.akun && (
                                    <span className="mt-0.5 block font-mono text-[11px] text-ink-3">
                                        {b.akun}
                                    </span>
                                )}
                                {/* Panitia tanpa akun ditandai, bukan dibiarkan
                                    tampak sama. Ia tidak bisa masuk ke portal
                                    pengawas, dan Admin yang mengira sebaliknya
                                    baru menyadarinya pada hari-H. */}
                                {!b.akun && (
                                    <span className="mt-0.5 block text-[11px] text-warn">
                                        tanpa akun
                                    </span>
                                )}
                            </span>
                        ),
                    },
                    {
                        judul: 'Jabatan',
                        kelas: 'w-32',
                        render: (b) => <Chip nada="info">{b.jabatan}</Chip>,
                    },
                    {
                        judul: 'Cakupan',
                        render: (b) => (
                            <span>
                                <span className="text-sm text-ink">{b.cakupan}</span>
                                {b.ruang_kode.length > 0 && (
                                    <span className="mt-0.5 block font-mono text-[11px] text-ink-3">
                                        {b.ruang_kode.join(', ')}
                                    </span>
                                )}
                            </span>
                        ),
                    },
                    {
                        judul: 'Kontak',
                        render: (b) => (
                            <span className="text-xs text-ink-2">
                                {b.email ?? '—'}
                                {b.phone && (
                                    <span className="block font-mono text-[11px] text-ink-3">
                                        {b.phone}
                                    </span>
                                )}
                            </span>
                        ),
                    },
                    {
                        judul: 'Status',
                        kelas: 'w-32',
                        render: (b) => <Chip nada={nadaStatus(b.status)}>{b.status}</Chip>,
                    },
                    {
                        judul: '',
                        kelas: 'w-40',
                        render: (b) => (
                            <span className="flex gap-1.5">
                                <Tombol onClick={() => setPanel(b.id)}>Ubah</Tombol>
                                <Tombol
                                    nada="bahaya"
                                    onClick={() => {
                                        if (
                                            confirm(
                                                `${b.nama} dikeluarkan dari kepanitiaan? Tugasnya di event ini hilang, dan bila ia pengawas, kesiapan event ikut turun.`,
                                            )
                                        ) {
                                            router.delete(
                                                `/admin/events/${event.id}/panitia/${b.id}`,
                                                { preserveScroll: true },
                                            );
                                        }
                                    }}
                                >
                                    Hapus
                                </Tombol>
                            </span>
                        ),
                    },
                ]}
            />

            <div className="mt-3">
                <Inbox>
                    Cakupan <strong>seluruh event</strong> memberi akses ke semua komponen event
                    ini. Cakupan <strong>ruang tertentu</strong> membatasinya pada ruang yang
                    disebut &mdash; pengawas biasanya masuk di sini, karena yang ia butuhkan adalah
                    presensi ruangnya sendiri, bukan seluruh event.
                </Inbox>
            </div>

            {panel !== 'tutup' && (
                <PanelPanitia
                    event={event}
                    panitia={sedangDiubah}
                    akun={akun}
                    ruang={ruang}
                    jabatan={jabatan}
                    padaTutup={tutup}
                />
            )}
        </AdminLayout>
    );
}

function PanelPanitia({
    event,
    panitia,
    akun,
    ruang,
    jabatan,
    padaTutup,
}: {
    event: Props['event'];
    panitia?: Baris;
    akun: Props['akun'];
    ruang: Props['ruang'];
    jabatan: string[];
    padaTutup: () => void;
}) {
    const ubah = panitia !== undefined;

    const { data, setData, processing, errors, reset } = useForm({
        name: panitia?.nama ?? '',
        position: panitia?.jabatan ?? jabatan[0],
        user_id: null as number | null,
        email: panitia?.email ?? '',
        phone: panitia?.phone ?? '',
        scope: panitia?.cakupan_kode ?? 'event',
        ruang_id: panitia?.ruang_id ?? ([] as number[]),
        status: panitia?.status ?? 'aktif',
    });

    // Akun ditautkan lewat username, bukan id. Panel ini menampilkan daftar
    // akun sebagai pilihan, sehingga id-nya memang tersedia - tetapi saat
    // mengubah panitia yang sudah ada, id akunnya tidak dikirim balik oleh
    // server (yang dikirim adalah username untuk ditampilkan). Menautkannya
    // kembali lewat username menjaga panel ubah tidak diam-diam melepas akun.
    const idAkun =
        data.user_id ??
        akun.find((a) => a.username === panitia?.akun)?.id ??
        null;

    const kirim = (e: React.FormEvent) => {
        e.preventDefault();

        // Nama boleh diketik bebas sementara akunnya dipilih dari daftar:
        // begitu akun dipilih, namanya diisi dari akun itu supaya tabel tidak
        // menampilkan dua nama berbeda untuk orang yang sama. Pengisian itu
        // terjadi di sini, bukan lewat setData, karena setData baru berlaku
        // pada render berikutnya - dan kita mengirim sekarang.
        const isi = {
            ...data,
            user_id: idAkun,
            name: data.name || akun.find((a) => a.id === idAkun)?.nama || '',
        };

        const opsi = {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                padaTutup();
            },
        };

        if (ubah) {
            router.put(`/admin/events/${event.id}/panitia/${panitia!.id}`, isi, opsi);
        } else {
            router.post(`/admin/events/${event.id}/panitia`, isi, opsi);
        }
    };

    return (
        <Modal judul={ubah ? `Ubah ${panitia!.nama}` : 'Tambah panitia'} terbuka padaTutup={padaTutup}>
            <form onSubmit={kirim} className="space-y-3">
                <Field
                    label="Akun"
                    galat={errors.user_id}
                    petunjuk="Hanya Guru dan Pengawas. Kosongkan untuk panitia tanpa akun."
                >
                    <select
                        value={idAkun ?? ''}
                        onChange={(e) => {
                            const id = e.target.value ? Number(e.target.value) : null;
                            setData('user_id', id);
                            const a = akun.find((x) => x.id === id);
                            if (a) {
                                setData('name', a.nama);
                            }
                        }}
                        className={kelasInput}
                    >
                        <option value="">— tanpa akun —</option>
                        {akun.map((a) => (
                            <option key={a.id} value={a.id}>
                                {a.nama} · {a.username} ({a.peran})
                            </option>
                        ))}
                    </select>
                </Field>

                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Nama" galat={errors.name}>
                        <input
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="Nama lengkap"
                            className={kelasInput}
                        />
                    </Field>

                    <Field label="Jabatan" galat={errors.position}>
                        <select
                            value={data.position}
                            onChange={(e) => setData('position', e.target.value)}
                            className={kelasInput}
                        >
                            {jabatan.map((j) => (
                                <option key={j} value={j}>
                                    {j}
                                </option>
                            ))}
                        </select>
                    </Field>
                </div>

                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Email" galat={errors.email}>
                        <input
                            type="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            className={kelasInput}
                        />
                    </Field>

                    <Field label="Telepon" galat={errors.phone}>
                        <input
                            value={data.phone}
                            onChange={(e) => setData('phone', e.target.value)}
                            className={kelasInput + ' font-mono'}
                        />
                    </Field>
                </div>

                <Field label="Cakupan" galat={errors.scope}>
                    <div className="flex gap-2">
                        {[
                            { nilai: 'event', label: 'Seluruh event' },
                            { nilai: 'ruang', label: 'Ruang tertentu' },
                        ].map((o) => (
                            <button
                                key={o.nilai}
                                type="button"
                                onClick={() => setData('scope', o.nilai)}
                                className={
                                    'rounded-wf border px-3 py-1.5 text-xs font-medium ' +
                                    (data.scope === o.nilai
                                        ? 'border-accent bg-accent-soft text-accent'
                                        : 'border-line bg-surface text-ink-2 hover:bg-surface-2')
                                }
                            >
                                {o.label}
                            </button>
                        ))}
                    </div>
                </Field>

                {/* Daftar ruang hanya muncul saat cakupannya memang dibatasi.
                    Selalu menampilkannya berarti menawarkan pilihan yang
                    diabaikan server, dan Admin yang memilihnya tidak tahu
                    pilihannya tidak berpengaruh. */}
                {data.scope === 'ruang' && (
                    <Field
                        label="Ruang"
                        galat={errors.ruang_id}
                        petunjuk="Minimal satu ruang. Cakupan ruang tanpa ruang tidak membatasi apa pun."
                    >
                        <div className="max-h-40 space-y-1 overflow-y-auto rounded-wf border border-line p-2">
                            {ruang.length === 0 && (
                                <p className="text-xs text-ink-3">
                                    Belum ada ruang aktif di sekolah ini.
                                </p>
                            )}
                            {ruang.map((r) => (
                                <label key={r.id} className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={data.ruang_id.includes(r.id)}
                                        onChange={(e) =>
                                            setData(
                                                'ruang_id',
                                                e.target.checked
                                                    ? [...data.ruang_id, r.id]
                                                    : data.ruang_id.filter((x) => x !== r.id),
                                            )
                                        }
                                    />
                                    <span className="font-mono text-xs text-ink-2">{r.kode}</span>
                                    <span className="text-ink">{r.nama}</span>
                                </label>
                            ))}
                        </div>
                    </Field>
                )}

                {ubah && (
                    <Field label="Status" galat={errors.status}>
                        <select
                            value={data.status}
                            onChange={(e) => setData('status', e.target.value)}
                            className={kelasInput}
                        >
                            <option value="aktif">aktif</option>
                            <option value="belum konfirmasi">belum konfirmasi</option>
                            <option value="nonaktif">nonaktif</option>
                        </select>
                    </Field>
                )}

                <div className="flex gap-2 pt-1">
                    <Tombol type="submit" nada="pri" disabled={processing}>
                        {ubah ? 'Simpan' : 'Tambahkan'}
                    </Tombol>
                    <Tombol onClick={padaTutup}>Batal</Tombol>
                </div>
            </form>
        </Modal>
    );
}

function KPI({ label, nilai }: { label: string; nilai: number }) {
    return (
        <div className="rounded-wf border border-line bg-surface px-4 py-3">
            <p className="text-xs text-ink-3">{label}</p>
            <p className="mt-1 font-mono text-2xl font-medium text-ink">{nilai}</p>
        </div>
    );
}

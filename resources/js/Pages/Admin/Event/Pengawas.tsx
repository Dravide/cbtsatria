import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Chip,
    Field,
    Inbox,
    Kotak,
    Peringatan,
    Tabel,
    Tombol,
    kelasInput,
} from '@/Components/ui';

/**
 * Laman 17 - Pengawas & penempatan ruang. Wireframe plate 17, PRD §9.6.
 *
 * ---------------------------------------------------------------------
 * Laman ini bukan daftar nama, dan itu yang menentukan bentuknya
 * ---------------------------------------------------------------------
 * Anotasi plate 17 menyatakannya: "penugasan inilah yang memberi Pengawas hak
 * melihat dan memverifikasi presensi di ruang itu. Tanpa penugasan, ia tidak
 * melihat apa pun - dan itu batas isolasi yang harus tetap berlaku meskipun ia
 * guru di sekolah yang sama."
 *
 * Konsekuensinya terlihat di tombol "Buka" pada laman jadwal: ia menolak
 * selama masih ada ruang tanpa pengawas. Karena itu angka "Ruang tanpa
 * pengawas" di sini BUKAN statistik - ia sebab dari penolakan di laman lain,
 * dan menampilkannya sebagai nol palsu akan membuat Admin menebak-nebak.
 *
 * ---------------------------------------------------------------------
 * Kolomnya dua, tetapi datanya daftar
 * ---------------------------------------------------------------------
 * Plate 17 menampilkan `Pengawas 1 | Pengawas 2`. PRD §9.6 mengizinkan lebih
 * dari dua, jadi yang dirender adalah daftar - dua nama pertama di kolomnya,
 * sisanya dihitung. Memaksa dua kolom berarti orang ketiga tersimpan tanpa
 * pernah terlihat di laman yang menugaskannya, dan itu bentuk kehilangan data
 * yang paling sulit disadari.
 */
type PengawasBaris = {
    id: number;
    user_id: number;
    nama: string | null;
    username: string | null;
    status: string;
};

type Baris = {
    id: number;
    ruang: string | null;
    ruang_nama: string | null;
    kapasitas: number;
    sesi: string | null;
    sesi_id: number | null;
    ujian: string | null;
    jam: string | null;
    tanggal: string | null;
    pengawas: PengawasBaris[];
    peserta: number;
};

type Props = {
    event: { id: number; kode: string; nama: string; status: string; status_label: string };
    baris: Baris[];
    angka: { ruang: number; ditugaskan: number; tanpa_pengawas: number; saran_per_ruang: number };
    perhatian: Baris[];
    calon: { id: number; nama: string; username: string; peran: string }[];
    sesi_tanpa_ruang: { id: number; label: string; ujian: string | null }[];
};

export default function EventPengawas({
    event,
    baris,
    angka,
    perhatian,
    calon,
    sesi_tanpa_ruang,
}: Props) {
    const [panel, setPanel] = useState(false);
    const [ruangId, setRuangId] = useState<number | null>(null);
    const [orangId, setOrangId] = useState<number | null>(null);

    const jalur = `/admin/events/${event.id}`;

    const bukaPanel = (id?: number) => {
        setRuangId(id ?? baris.find((b) => b.pengawas.length === 0)?.id ?? baris[0]?.id ?? null);
        setOrangId(calon[0]?.id ?? null);
        setPanel(true);
    };

    const tugaskan = () => {
        if (ruangId === null || orangId === null) {
            return;
        }

        router.post(
            `${jalur}/pengawas/ruang/${ruangId}`,
            { supervisor_id: orangId },
            { preserveScroll: true, onSuccess: () => setPanel(false) },
        );
    };

    return (
        <AdminLayout
            judul="Pengawas & penempatan ruang"
            breadcrumb={`/admin/events · ${event.kode} · Pengawas`}
            aksi={
                <>
                    <span className="flex items-center gap-2 rounded-wf border border-line bg-surface px-2.5 py-1.5 text-xs text-ink-2">
                        <span className="text-ink-3">Event:</span>
                        <span className="font-medium text-ink">{event.nama}</span>
                    </span>

                    <Tombol
                        disabled={baris.length === 0}
                        title={
                            baris.length === 0
                                ? 'Belum ada sesi ber-ruang. Susun jadwalnya terlebih dahulu.'
                                : undefined
                        }
                        onClick={() =>
                            router.post(`${jalur}/pengawas/otomatis`, {}, { preserveScroll: true })
                        }
                    >
                        Tugaskan otomatis
                    </Tombol>

                    <Tombol
                        nada="pri"
                        disabled={baris.length === 0 || calon.length === 0}
                        title={
                            calon.length === 0
                                ? 'Belum ada panitia berjabatan pengawas yang punya akun.'
                                : undefined
                        }
                        onClick={() => bukaPanel()}
                    >
                        Tugaskan
                    </Tombol>
                </>
            }
        >
            <Head title={`Pengawas · ${event.nama}`} />

            {/* Tiga angka, dan yang ketiga yang menentukan: ruang tanpa
                pengawas adalah ruang yang siswanya tidak akan bisa masuk. */}
            <div className="mb-3 grid gap-3 sm:grid-cols-3">
                <KPI label="Ruang" nilai={angka.ruang} />
                <KPI label="Pengawas ditugaskan" nilai={angka.ditugaskan} nada="ok" />
                <KPI
                    label="Ruang tanpa pengawas"
                    nilai={angka.tanpa_pengawas}
                    nada={angka.tanpa_pengawas > 0 ? 'bad' : 'ok'}
                />
            </div>

            {panel && (
                <div className="mb-4">
                    <Kotak
                        judul="Tugaskan pengawas"
                        aksi={
                            <>
                                <Tombol nada="sek" onClick={() => setPanel(false)}>
                                    Batal
                                </Tombol>
                                <Tombol nada="pri" onClick={tugaskan}>
                                    Tugaskan
                                </Tombol>
                            </>
                        }
                    >
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="Ruang & sesi" petunjuk="Barisnya ruang-sesi, bukan ruang">
                                <select
                                    value={ruangId ?? ''}
                                    onChange={(e) => setRuangId(Number(e.target.value))}
                                    className={kelasInput}
                                >
                                    {baris.map((b) => (
                                        <option key={b.id} value={b.id}>
                                            {b.ruang} · {b.sesi} · {b.ujian}
                                        </option>
                                    ))}
                                </select>
                            </Field>

                            <Field
                                label="Pengawas"
                                petunjuk="Daftar ini diambil dari panitia berjabatan pengawas"
                            >
                                <select
                                    value={orangId ?? ''}
                                    onChange={(e) => setOrangId(Number(e.target.value))}
                                    className={kelasInput}
                                >
                                    {calon.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.nama} · {c.peran}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                        </div>

                        <p className="mt-3 text-[11px] leading-relaxed text-ink-3">
                            Penugasan yang bentrok ditolak di sini, bukan hanya dilaporkan di laman
                            jadwal. Satu pengawas tidak bisa berdiri di dua ruang pada jam yang sama,
                            dan menolaknya saat menyimpan berarti Admin tidak perlu menemukannya
                            belakangan.
                        </p>
                    </Kotak>
                </div>
            )}

            {calon.length === 0 && baris.length > 0 && (
                <div className="mb-3">
                    <Peringatan>
                        Belum ada panitia berjabatan <strong>pengawas</strong> yang punya akun.
                        Tambahkan orangnya di laman <strong>Kepanitiaan</strong> &mdash; penugasan di
                        sini hanya bisa diberikan kepada akun yang sudah ada.
                    </Peringatan>
                </div>
            )}

            {sesi_tanpa_ruang.length > 0 && (
                <div className="mb-3">
                    <Peringatan>
                        {sesi_tanpa_ruang.length} sesi belum punya ruang:{' '}
                        {sesi_tanpa_ruang.map((s) => `${s.label} (${s.ujian ?? '—'})`).join(', ')}.
                        Penugasan menempel pada ruang-sesi, sehingga sesi tanpa ruang tidak bisa
                        ditugaskan &mdash; tetapkan ruangnya di laman{' '}
                        <strong>Jadwal &amp; sesi</strong>.
                    </Peringatan>
                </div>
            )}

            <Tabel<Baris>
                hal={{ data: baris, total: baris.length }}
                kunci={(b) => b.id}
                kosong="Belum ada ruang yang dipakai sesi mana pun. Susun jadwalnya terlebih dahulu."
                kolom={[
                    {
                        judul: 'Ruang',
                        kelas: 'w-40',
                        render: (b) => (
                            <span>
                                <span className="font-mono text-xs font-medium text-ink">
                                    {b.ruang ?? '—'}
                                </span>
                                <span className="ml-2 text-[11px] text-ink-3">{b.ruang_nama}</span>
                            </span>
                        ),
                    },
                    {
                        judul: 'Pengawas 1',
                        render: (b) => <NamaPengawas orang={b.pengawas[0]} />,
                    },
                    {
                        judul: 'Pengawas 2',
                        render: (b) => <NamaPengawas orang={b.pengawas[1]} />,
                    },
                    {
                        judul: 'Sesi',
                        kelas: 'w-36',
                        render: (b) => (
                            <span className="flex items-center gap-1.5">
                                <span className="font-mono text-xs text-ink-2">{b.sesi ?? '—'}</span>
                                {b.pengawas.length > 2 && (
                                    <Chip nada="mute">+{b.pengawas.length - 2}</Chip>
                                )}
                            </span>
                        ),
                    },
                    {
                        judul: '',
                        kelas: 'w-40',
                        render: (b) => (
                            <span className="flex justify-end gap-1.5">
                                <Tombol
                                    nada="sek"
                                    disabled={calon.length === 0}
                                    onClick={() => bukaPanel(b.id)}
                                >
                                    Tugaskan
                                </Tombol>
                            </span>
                        ),
                    },
                ]}
            />

            {/* Penugasan dibatalkan per baris, bukan per kolom: yang dilepas
                adalah satu orang dari satu ruang-sesi, dan tombol di kolom
                Pengawas 1 tidak bisa menyatakan siapa yang dimaksud bila
                kolomnya berisi lebih dari satu nama. */}
            {baris.some((b) => b.pengawas.length > 0) && (
                <div className="mt-3">
                    <Kotak judul="Batalkan penugasan">
                        <div className="flex flex-wrap gap-2">
                            {baris.flatMap((b) =>
                                b.pengawas.map((p) => (
                                    <span
                                        key={p.id}
                                        className="flex items-center gap-2 rounded-wf border border-line bg-surface px-2.5 py-1 text-xs"
                                    >
                                        <span className="font-mono text-ink-3">{b.ruang}</span>
                                        <span className="font-mono text-ink-3">{b.sesi}</span>
                                        <span className="text-ink">{p.nama}</span>
                                        <button
                                            className="text-bad hover:underline"
                                            onClick={() =>
                                                router.delete(`${jalur}/pengawas/${p.id}`, {
                                                    preserveScroll: true,
                                                })
                                            }
                                        >
                                            &#10005;
                                        </button>
                                    </span>
                                )),
                            )}
                        </div>
                    </Kotak>
                </div>
            )}

            <div className="mt-3 space-y-3">
                {perhatian.length > 0 && (
                    <Peringatan>
                        {perhatian
                            .slice(0, 3)
                            .map((b) => `${b.ruang} (${b.sesi}) hanya ${b.pengawas.length} pengawas`)
                            .join('. ')}
                        . Ruang yang tidak punya pengawas tidak akan bisa dibuka siswanya, karena
                        presensi harus diverifikasi orang.
                    </Peringatan>
                )}

                <Inbox>
                    Ini <strong>bukan</strong> daftar panitia. Laman{' '}
                    <strong>Kepanitiaan</strong> mencatat siapa saja pengawasnya di tingkat event
                    &mdash; satu daftar nama, sekali. Laman ini mencatat siapa yang berdiri di ruang
                    mana pada sesi mana, dan penugasan itulah yang memberi hak memverifikasi
                    presensi. Seorang pengawas yang ada di kepanitiaan tetapi tidak ditugaskan di
                    sini tidak bisa membuka ruang mana pun.
                </Inbox>
            </div>
        </AdminLayout>
    );
}

function NamaPengawas({ orang }: { orang?: PengawasBaris }) {
    if (!orang) {
        return <span className="text-xs text-ink-3">&mdash;</span>;
    }

    return (
        <span className="flex items-center gap-2">
            <span className="text-sm text-ink">{orang.nama ?? orang.username ?? '—'}</span>
            {orang.status !== 'aktif' && <Chip nada="warn">{orang.status}</Chip>}
        </span>
    );
}

function KPI({
    label,
    nilai,
    nada,
}: {
    label: string;
    nilai: number;
    nada?: 'ok' | 'warn' | 'bad';
}) {
    const warna =
        nada === 'ok'
            ? 'text-ok'
            : nada === 'warn'
              ? 'text-warn'
              : nada === 'bad'
                ? 'text-bad'
                : 'text-ink';

    return (
        <div className="rounded-wf border border-line bg-surface px-4 py-3">
            <p className="text-xs text-ink-3">{label}</p>
            <p className={'mt-1 font-mono text-2xl font-medium ' + warna}>
                {nilai.toLocaleString('id-ID')}
            </p>
        </div>
    );
}

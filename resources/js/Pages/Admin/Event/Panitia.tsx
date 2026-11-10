import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Chip,
    Field,
    Inbox,
    KodeTeks,
    Modal,
    Tabel,
    Tombol,
    TombolTautan,
    kelasInput,
    nadaStatus,
} from '@/Components/ui';

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
 * Laman ini juga tempat KODE PENGAWAS diterbitkan - sejak 2026-11-07
 * ---------------------------------------------------------------------
 * Kode pintu ruang menempel pada ORANG, bukan pada ruang: tautan `/p/{slug}`
 * yang ditempel di daun pintu menentukan RUANG, dan kode enam angka yang
 * dipegang pengawasnya menentukan SIAPA yang masuk. Sebelumnya kodenya
 * menempel pada baris ruang, sehingga kartu pintu yang difoto orang lewat
 * membawa tautan sekaligus kodenya - dan keduanya cukup untuk masuk.
 *
 * Karena kodenya milik orang, tempat menampilkannya adalah laman tempat
 * orangnya berdiri. Satu kode per pengawas, bisa dipakai di ruang mana pun
 * yang ditugaskan kepadanya, dan SELALU terlihat di tabel - karena Admin
 * mencetaknya menjadi kartu pengawas. Menerbitkan ulang menggantinya (mis.
 * kartunya bocor atau hilang).
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
    kode: string | null;
    punya_kode: boolean;
    kode_terbit: string | null;
};

type Props = {
    event: { id: number; kode: string; nama: string; status: string; status_label: string };
    panitia: Baris[];
    akun: { id: number; nama: string; username: string; peran: string }[];
    ruang: { id: number; kode: string; nama: string }[];
    jabatan: string[];
};

export default function EventPanitia({ event, panitia, akun, ruang, jabatan }: Props) {
    const [panel, setPanel] = useState<'tutup' | 'tambah' | 'massal' | number>('tutup');

    const sedangDiubah = typeof panel === 'number' ? panitia.find((p) => p.id === panel) : undefined;

    const tutup = () => setPanel('tutup');

    return (
        <AdminLayout
            judul="Kepanitiaan"
            breadcrumb={`/admin/events · ${event.kode} · Kepanitiaan`}
            aksi={
                <span className="flex flex-wrap gap-2">
                    {/* Unduhan berdiri lebih dulu, terpisah dari tombol yang
                        mengubah data: yang datang ke laman ini sesudah
                        menyusun panitia biasanya ingin MENGAMBIL hasilnya,
                        bukan menambah baris lagi. */}
                    <TombolTautan
                        href={`/admin/events/${event.id}/panitia/ekspor`}
                        title="Unduh daftar panitia sebagai Excel (.xlsx)"
                    >
                        Unduh Excel
                    </TombolTautan>
                    <TombolTautan
                        href={`/admin/events/${event.id}/panitia/kartu`}
                        title="Cetak kartu pengawas & panitia (PDF)"
                    >
                        Cetak kartu
                    </TombolTautan>
                    {/* Terbitkan kode massal bersebelahan dengan "Tambahkan
                        massal": keduanya menjawab keadaan yang sama - puluhan
                        pengawas yang harus disiapkan sebelum hari ujian.
                        Tombolnya TIDAK mengganti kode yang sudah ada; itu
                        dikatakan di konfirmasinya, karena nama "terbitkan
                        semua" mudah dibaca sebagai "ganti semua". */}
                    <Tombol
                        onClick={() => {
                            if (
                                confirm(
                                    'Terbitkan kode untuk semua pengawas yang belum punya? Kode yang sudah ada tidak diubah, sehingga kartu yang sudah dicetak tetap berlaku.',
                                )
                            ) {
                                router.post(
                                    `/admin/events/${event.id}/panitia/kode-massal`,
                                    {},
                                    { preserveScroll: true },
                                );
                            }
                        }}
                    >
                        Terbitkan kode massal
                    </Tombol>
                    <span className="mx-1 h-6 w-px self-center bg-line" aria-hidden />
                    {/* Massal ada di header, di samping satu-per-satu, supaya
                        panelnya bisa dibuka tanpa lebih dulu menempuh jalur
                        yang justru ingin dihindari. */}
                    <Tombol nada="sek" onClick={() => setPanel('massal')}>
                        Tambahkan massal
                    </Tombol>
                    <Tombol nada="pri" onClick={() => setPanel('tambah')}>
                        Tambah panitia
                    </Tombol>
                </span>
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
                        // Kolom ini menjawab satu pertanyaan yang ditanyakan
                        // Admin pada hari-H: "pengawas ini bisa masuk atau
                        // tidak". Karena itu yang ditampilkan adalah KODE-nya,
                        // dan kode itu SELALU terlihat - ia dicetak menjadi
                        // kartu pengawas, jadi Admin harus membacanya kapan
                        // saja, bukan sekali pada respons penerbitannya.
                        judul: 'Kode pengawas',
                        kelas: 'w-52',
                        render: (b) => {
                            // Tombolnya tidak dirender untuk orang yang pasti
                            // ditolak server: tanpa akun, ia tidak bisa
                            // ditugaskan ke ruang mana pun sehingga kodenya
                            // tidak akan punya pintu. Server tetap menolaknya
                            // sendiri - layar hanya tidak menjanjikan yang
                            // pasti gagal.
                            const bisa =
                                b.akun !== null && b.jabatan.toLowerCase().includes('pengawas');

                            if (!bisa) {
                                return <span className="text-xs text-ink-3">&mdash;</span>;
                            }

                            return (
                                <span className="flex flex-col gap-1.5">
                                    {b.kode ? (
                                        <KodeTeks kode={b.kode} />
                                    ) : (
                                        <Chip nada="mute">belum ada</Chip>
                                    )}
                                    <span className="flex flex-wrap items-center gap-1.5">
                                        <Tombol
                                            onClick={() => {
                                                // Menekan ini MENGGANTI kode,
                                                // dan kartu yang sudah tercetak
                                                // berhenti berlaku. Karena itu
                                                // konfirmasinya hanya muncul
                                                // bila kodenya memang sudah ada.
                                                if (
                                                    b.kode &&
                                                    !confirm(
                                                        `Ganti kode pengawas ${b.nama}? Kode lama langsung berhenti berlaku, dan kartu yang sudah tercetak harus dibuat ulang.`,
                                                    )
                                                ) {
                                                    return;
                                                }

                                                router.post(
                                                    `/admin/events/${event.id}/panitia/${b.id}/kode`,
                                                    {},
                                                    { preserveScroll: true },
                                                );
                                            }}
                                        >
                                            {b.kode ? 'Terbitkan ulang' : 'Terbitkan kode'}
                                        </Tombol>
                                        {b.kode && b.kode_terbit && (
                                            <span className="text-[11px] text-ink-3">
                                                terbit {b.kode_terbit}
                                            </span>
                                        )}
                                    </span>
                                </span>
                            );
                        },
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

            <div className="mt-3">
                <Inbox>
                    <strong>Kode pengawas</strong> diketik pengawasnya di pintu ruang
                    (<code className="font-mono text-[11px]">/p/&lt;slug&gt;</code>). Tautan di
                    daun pintu menentukan <em>ruang</em>; kode ini menentukan <em>siapa</em> yang
                    masuk &mdash; dan hanya berlaku bila orangnya ditugaskan di ruang-sesi itu.
                    Kodenya selalu terlihat di tabel ini, jadi bisa langsung dipakai untuk membuat
                    kartu pengawas. Menekan &ldquo;Terbitkan ulang&rdquo; menggantinya &mdash; kode
                    lama langsung berhenti berlaku.
                </Inbox>
            </div>

            {/* Cabang render SENDIRI, bukan berbagi simpul dengan
                `PanelPanitia`. Kalau keduanya menempati simpul yang sama,
                React memakai ulang komponen yang sama dan `useForm` di
                dalamnya mempertahankan nilai panel yang pertama dibuka - bug
                yang bentuknya sama dengan yang baru saja diperbaiki di berkas
                ini. Dua komponen berbeda = dua simpul berbeda. */}
            {panel === 'massal' && (
                <PanelMassal
                    key="massal"
                    event={event}
                    akun={akun}
                    ruang={ruang}
                    jabatan={jabatan}
                    panitia={panitia}
                    padaTutup={tutup}
                />
            )}

            {panel !== 'tutup' && panel !== 'massal' && (
                <PanelPanitia
                    // `key` bukan hiasan. Panelnya memang dipasang hanya saat
                    // terbuka, tetapi berpindah dari satu baris ke baris lain
                    // tanpa menutupnya memakai ULANG komponen yang sama, dan
                    // `useForm` tidak pernah menyetel ulang nilai awalnya -
                    // yang tersimpan lalu adalah isi panitia yang pertama.
                    key={typeof panel === 'number' ? panel : 'tambah'}
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

function PanelMassal({
    event,
    akun,
    ruang,
    jabatan,
    panitia,
    padaTutup,
}: {
    event: Props['event'];
    akun: Props['akun'];
    ruang: Props['ruang'];
    jabatan: string[];
    panitia: Baris[];
    padaTutup: () => void;
}) {
    const { data, setData, processing, errors, reset } = useForm({
        user_id: [] as number[],
        position: jabatan[0],
        scope: 'event',
        ruang_id: [] as number[],
    });

    const [cari, setCari] = useState('');

    // Akun yang sudah jadi panitia event ini ditandai dan dimatikan kotaknya.
    // Server tetap melewatinya (dan itu memang harus, karena id bisa dikirim
    // langsung ke rute), tetapi mencentang sesuatu yang PASTI dilewati adalah
    // janji yang tidak ditepati layar.
    const sudah = new Set(panitia.map((p) => p.akun).filter((a): a is string => a !== null));

    const tampil = akun.filter((a) => {
        if (cari.trim() === '') {
            return true;
        }

        const q = cari.trim().toLowerCase();

        return a.nama.toLowerCase().includes(q) || a.username.toLowerCase().includes(q);
    });

    const kirim = (e: React.FormEvent) => {
        e.preventDefault();

        router.post(`/admin/events/${event.id}/panitia/massal`, data, {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                padaTutup();
            },
        });
    };

    // Cakupan "ruang" tanpa satu ruang pun ditolak server. Menahan tombolnya
    // di sini bukan hiasan: Admin membaca sebabnya sebelum menekan, bukan
    // sesudah kiriman ditolak.
    const kurangRuang = data.scope === 'ruang' && data.ruang_id.length === 0;
    const semuaSudah = tampil.length > 0 && tampil.every((a) => sudah.has(a.username));

    return (
        <Modal judul="Tambah panitia massal" terbuka padaTutup={padaTutup}>
            <form onSubmit={kirim} className="space-y-3">
                <Inbox>
                    Nama diambil dari akunnya, jadi tidak ada kolom nama. Satu jabatan dan satu
                    cakupan berlaku untuk semua yang dicentang &mdash; jabatan yang berbeda berarti
                    kiriman terpisah.
                </Inbox>

                <div className="grid gap-3 sm:grid-cols-2">
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

                    <Field label="Cari akun">
                        <input
                            value={cari}
                            onChange={(e) => setCari(e.target.value)}
                            placeholder="Nama atau username"
                            className={kelasInput}
                        />
                    </Field>
                </div>

                <Field
                    label={`Akun (${data.user_id.length} dipilih)`}
                    galat={errors.user_id}
                    petunjuk="Hanya Guru dan Pengawas aktif. Yang sudah jadi panitia ditandai dan dimatikan."
                >
                    <div className="max-h-64 space-y-1 overflow-y-auto rounded-wf border border-line p-2">
                        {tampil.length === 0 && (
                            <p className="text-xs text-ink-3">
                                Tidak ada akun Guru atau Pengawas yang cocok.
                            </p>
                        )}
                        {tampil.map((a) => {
                            const adaSudah = sudah.has(a.username);

                            return (
                                <label
                                    key={a.id}
                                    className={
                                        'flex items-center gap-2 text-sm ' +
                                        (adaSudah ? 'opacity-50' : '')
                                    }
                                >
                                    <input
                                        type="checkbox"
                                        disabled={adaSudah}
                                        checked={data.user_id.includes(a.id)}
                                        onChange={(e) =>
                                            setData(
                                                'user_id',
                                                e.target.checked
                                                    ? [...data.user_id, a.id]
                                                    : data.user_id.filter((x) => x !== a.id),
                                            )
                                        }
                                    />
                                    <span className="text-ink">{a.nama}</span>
                                    <span className="font-mono text-[11px] text-ink-3">
                                        {a.username}
                                    </span>
                                    <span className="ml-auto text-[11px] text-ink-3">
                                        {adaSudah ? 'sudah jadi panitia' : a.peran}
                                    </span>
                                </label>
                            );
                        })}
                    </div>
                </Field>

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

                {/* Sama seperti panel satu-per-satu: daftar ruang hanya muncul
                    saat cakupannya memang dibatasi, karena menawarkan pilihan
                    yang diabaikan server berarti Admin memilih sesuatu yang
                    tidak berpengaruh. */}
                {data.scope === 'ruang' && (
                    <Field
                        label="Ruang"
                        galat={errors.ruang_id}
                        petunjuk="Minimal satu ruang. Cakupan ruang tanpa ruang tidak membatasi apa pun."
                    >
                        <div className="max-h-40 space-y-1 overflow-y-auto rounded-wf border border-line p-2">
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

                <div className="flex gap-2 pt-1">
                    <Tombol
                        type="submit"
                        nada="pri"
                        disabled={processing || data.user_id.length === 0 || kurangRuang || semuaSudah}
                    >
                        Tambahkan {data.user_id.length > 0 ? data.user_id.length + ' orang' : ''}
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

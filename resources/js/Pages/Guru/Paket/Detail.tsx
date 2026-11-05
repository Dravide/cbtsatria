import { Head, Link, router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import GuruLayout from '@/Layouts/GuruLayout';
import { Chip, Inbox, Kotak, Tabel, Tombol, kelasInput } from '@/Components/ui';

/**
 * Rincian paket + panel bank soal - wireframe-peran.html plate 05.
 *
 * ---------------------------------------------------------------------
 * Daftar butir dan bank soal BERDAMPINGAN, bukan dua laman
 * ---------------------------------------------------------------------
 * Wireframe plate 05 menaruh panel "Bank soal" di sebelah kanan daftar butir,
 * dengan kotak centang, pencarian, dan tombol "Tambah 3 butir". Alasannya
 * ditulis di catatan wireframe itu sendiri: tanpa panel, Guru harus keluar ke
 * bank soal, memilih, menandai paket tujuan, lalu kembali - tiga perpindahan
 * halaman untuk satu pekerjaan yang ia lakukan puluhan kali saat menyusun
 * satu paket.
 *
 * Kolom `bank` dari controller sudah dibatasi ke MAPEL paket ini, sehingga
 * panel tidak pernah menawarkan soal yang akan ditolak service.
 *
 * ---------------------------------------------------------------------
 * KPI "Durasi" TIDAK dirender, dan itu bukan karena lupa
 * ---------------------------------------------------------------------
 * Wireframe plate 05 menampilkan lima KPI: Butir / Poin total / Durasi /
 * Objektif / Uraian. Durasi BUKAN properti paket soal - durasi adalah
 * properti SESI ujian (`exam_sessions.duration_minutes`), diisi Admin di
 * plate 16. Paket yang sama boleh dipakai di sesi 90 menit dan sesi 60 menit.
 *
 * Menghitungnya dari sini berarti menebaknya, dan kotak berisi angka tebakan
 * dengan label yang meyakinkan lebih buruk daripada kotak yang tidak ada.
 *
 * ---------------------------------------------------------------------
 * Panel tidak memaginasi, dan itu disengaja
 * ---------------------------------------------------------------------
 * Panel ini pemilih cepat. Pemilih yang butuh menekan "berikutnya" memaksa
 * Guru menyusun paket sambil berpindah halaman - pekerjaan yang sama
 * berulang. Yang mencari lebih dari yang tampil memakai kotak cari di panel
 * itu, yang menyaring 50 baris teratas di sisi peramban tanpa permintaan
 * jaringan.
 */
type Butir = {
    id: number;
    question_id: number;
    kode: string | null;
    judul: string | null;
    jenis_pendek: string;
    jenis_label: string;
    objektif: boolean;
    poin: number;
    poin_override: number | null;
    urutan: number;
    penulis: string | null;
    milik_saya: boolean;
};

type SoalBank = {
    id: number;
    kode: string;
    judul: string;
    jenis_pendek: string;
    bobot: number;
    penulis: string | null;
};

type Paket = {
    id: number;
    nama: string;
    mapel_id: number;
    mapel: string | null;
    tingkat: string | null;
    keterangan: string | null;
    status: string;
    penulis: string | null;
};

type Props = {
    paket: Paket;
    butir: Butir[];
    ringkasan: {
        butir: number;
        poin: number;
        objektif: number;
        uraian: number;
    };
    bank: SoalBank[];
    bolehUbah: boolean;
};

export default function PaketDetail({ paket, butir, ringkasan, bank, bolehUbah }: Props) {
    const [pilihBank, setPilihBank] = useState<number[]>([]);
    const [cari, setCari] = useState('');
    const [identitas, setIdentitas] = useState(false);

    const bankTersaring = useMemo(() => {
        const q = cari.trim().toLowerCase();

        if (q === '') {
            return bank;
        }

        return bank.filter(
            (s) =>
                s.judul.toLowerCase().includes(q) ||
                s.kode.toLowerCase().includes(q),
        );
    }, [bank, cari]);

    const tambah = () => {
        if (pilihBank.length === 0) {
            return;
        }

        router.post(
            `/guru/paket/${paket.id}/butir`,
            { question_ids: pilihBank },
            { onSuccess: () => setPilihBank([]) },
        );
    };

    const buang = (b: Butir) => {
        router.delete(`/guru/paket/${paket.id}/butir/${b.id}`);
    };

    // Urutan naik/turun ditukar dengan tetangganya, lalu seluruh urutan
    // dikirim ulang. Mengirim satu pergeseran berarti server menghitung ulang
    // posisi dari keadaan yang mungkin sudah berubah sejak halaman dirender.
    const geser = (indeks: number, arah: -1 | 1) => {
        const tujuan = indeks + arah;

        if (tujuan < 0 || tujuan >= butir.length) {
            return;
        }

        const urutan = butir.map((b) => b.id);
        [urutan[indeks], urutan[tujuan]] = [urutan[tujuan], urutan[indeks]];

        router.put(`/guru/paket/${paket.id}/urutan`, { urutan }, { preserveScroll: true });
    };

    return (
        <GuruLayout
            judul={paket.nama}
            breadcrumb={`/guru/paket / ${paket.nama}`}
            aksi={
                <>
                    {/* Pratinjau SELALU ada, juga bagi Guru yang bukan
                        penulisnya. Membaca paket Guru lain adalah izin yang
                        memang diberikan (viewPaket), dan yang paling berguna
                        dari izin itu justru melihat isinya utuh sebelum
                        memutuskan memakainya sebagai acuan.

                        Ia diletakkan paling kiri karena ia satu-satunya tombol
                        di sini yang tidak mengubah apa pun - dan tombol yang
                        tidak mengubah apa pun tidak boleh berada di sebelah
                        tombol yang mengubah segalanya. */}
                    <Link
                        href={`/guru/paket/${paket.id}/pratinjau`}
                        className="rounded-wf border border-line bg-surface px-3 py-1.5 text-xs font-medium text-ink hover:bg-surface-2"
                    >
                        Pratinjau
                    </Link>
                    {bolehUbah && (
                        <>
                            <button
                                onClick={() => setIdentitas(true)}
                                className="rounded-wf border border-line bg-surface px-3 py-1.5 text-xs font-medium text-ink hover:bg-surface-2"
                            >
                                Ubah identitas
                            </button>
                        <button
                            onClick={() =>
                                router.put(`/guru/paket/${paket.id}`, {
                                    name: paket.nama,
                                    grade_level: paket.tingkat,
                                    description: paket.keterangan,
                                    status: 'siap',
                                })
                            }
                            // Tombol tetap AKTIF meski paketnya kosong: menonaktifkannya
                            // berarti Guru tidak pernah tahu MENGAPA ia tidak bisa
                            // menandai siap. Service menolaknya dengan pesan yang
                            // menjelaskan syaratnya.
                            className="rounded-wf border border-accent bg-accent px-3 py-1.5 text-xs font-medium text-on-accent hover:bg-accent/90"
                        >
                            Tandai siap
                        </button>
                        </>
                    )}
                </>
            }
        >
            <Head title={paket.nama} />

            <div className="mb-3 space-y-2">
                {!bolehUbah && (
                    <Inbox>
                        Paket ini disusun oleh <strong>{paket.penulis ?? 'Guru lain'}</strong>.
                        Anda boleh membacanya dan memakainya sebagai acuan, tetapi perubahan
                        hanya bisa dilakukan oleh penulisnya. Bila perlu menyesuaikan,
                        buat paket baru Anda sendiri.
                    </Inbox>
                )}

                <div className="flex flex-wrap items-center gap-2 text-xs text-ink-3">
                    <Chip nada={paket.status === 'siap' ? 'ok' : 'mute'}>
                        {paket.status === 'siap' ? 'Siap' : 'Draft'}
                    </Chip>
                    {paket.mapel && <span>{paket.mapel}</span>}
                    {paket.tingkat && <span>Kelas {paket.tingkat}</span>}
                    {paket.keterangan && <span className="italic">{paket.keterangan}</span>}
                </div>
            </div>

            {/* Empat KPI, bukan lima - lihat catatan kelas ini tentang Durasi. */}
            <div className="mb-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                <Kpi label="Butir" nilai={ringkasan.butir} />
                <Kpi label="Poin total" nilai={ringkasan.poin} />
                <Kpi label="Objektif" nilai={ringkasan.objektif} />
                <Kpi label="Uraian" nilai={ringkasan.uraian} />
            </div>

            <div className="grid gap-3 lg:grid-cols-[minmax(0,1fr)_18rem]">
                {/* ------------------------------------------------ Daftar butir */}
                <Tabel<Butir>
                    hal={{ data: butir, total: butir.length }}
                    kunci={(b) => b.id}
                    kosong={
                        bolehUbah
                            ? 'Paket ini belum berisi butir. Tandai soal di panel Bank soal sebelah kanan, lalu tekan "Tambah butir".'
                            : 'Paket ini belum berisi butir.'
                    }
                    kolom={[
                        {
                            judul: 'Butir',
                            render: (b) => (
                                <div className="min-w-0">
                                    <span className="block truncate text-ink">
                                        {b.judul ?? b.kode ?? 'Soal'}
                                    </span>
                                    <span className="mt-0.5 flex flex-wrap items-center gap-2 text-[11px] text-ink-3">
                                        <span className="font-mono">{b.kode}</span>
                                        {!b.milik_saya && b.penulis && (
                                            <span title="Soal Guru lain - boleh dipakai, tidak boleh diubah">
                                                penulis: {b.penulis}
                                            </span>
                                        )}
                                        {b.poin_override !== null && (
                                            <span title="Poin butir ini disetel khusus di paket ini, bukan memakai bobot soalnya">
                                                poin khusus
                                            </span>
                                        )}
                                    </span>
                                </div>
                            ),
                        },
                        {
                            judul: 'Jenis',
                            kelas: 'w-20',
                            render: (b) => (
                                <Chip nada={b.objektif ? 'mute' : 'info'}>{b.jenis_pendek}</Chip>
                            ),
                        },
                        {
                            judul: 'Poin',
                            kelas: 'w-24',
                            render: (b) => (
                                <PoinButir
                                    butir={b}
                                    paketId={paket.id}
                                    aktif={bolehUbah}
                                />
                            ),
                        },
                        {
                            judul: 'Urut',
                            kelas: 'w-20',
                            render: (b) => (
                                <span className="flex items-center gap-1">
                                    <span className="font-mono text-xs text-ink-3">{b.urutan}</span>
                                    {bolehUbah && (
                                        <span className="flex flex-col leading-none">
                                            <button
                                                onClick={() => geser(butir.indexOf(b), -1)}
                                                disabled={butir.indexOf(b) === 0}
                                                aria-label="Naikkan urutan"
                                                className="text-[10px] text-ink-3 hover:text-accent disabled:opacity-30"
                                            >
                                                ▲
                                            </button>
                                            <button
                                                onClick={() => geser(butir.indexOf(b), 1)}
                                                disabled={butir.indexOf(b) === butir.length - 1}
                                                aria-label="Turunkan urutan"
                                                className="text-[10px] text-ink-3 hover:text-accent disabled:opacity-30"
                                            >
                                                ▼
                                            </button>
                                        </span>
                                    )}
                                </span>
                            ),
                        },
                        {
                            judul: '',
                            kelas: 'w-16 text-right',
                            render: (b) =>
                                bolehUbah && (
                                    <button
                                        onClick={() => buang(b)}
                                        className="text-xs text-bad hover:underline"
                                        title="Keluarkan dari paket - soalnya tetap ada di bank soal"
                                    >
                                        Buang
                                    </button>
                                ),
                        },
                    ]}
                />

                {/* -------------------------------------------- Panel bank soal */}
                <Kotak
                    judul="Bank soal"
                    aksi={
                        <span className="text-[11px] text-ink-3">
                            {pilihBank.length > 0 ? `${pilihBank.length} dipilih` : `${bank.length} tersedia`}
                        </span>
                    }
                >
                    <div className="space-y-2">
                        <input
                            value={cari}
                            onChange={(e) => setCari(e.target.value)}
                            placeholder="Cari soal…"
                            className={kelasInput}
                        />

                        <div className="max-h-72 space-y-1 overflow-y-auto pr-1">
                            {bankTersaring.length === 0 && (
                                <p className="px-1 py-4 text-center text-[11px] text-ink-3">
                                    {bank.length === 0
                                        ? 'Tidak ada soal lain untuk mapel ini di bank soal.'
                                        : 'Tidak ada soal yang cocok dengan pencarian.'}
                                </p>
                            )}

                            {bankTersaring.map((s) => (
                                <label
                                    key={s.id}
                                    className="flex cursor-pointer items-start gap-2 rounded-wf px-1.5 py-1.5 hover:bg-surface-2"
                                >
                                    <input
                                        type="checkbox"
                                        checked={pilihBank.includes(s.id)}
                                        onChange={(e) =>
                                            setPilihBank((p) =>
                                                e.target.checked
                                                    ? [...p, s.id]
                                                    : p.filter((x) => x !== s.id),
                                            )
                                        }
                                        className="mt-0.5"
                                    />
                                    <span className="min-w-0">
                                        <span className="block truncate text-xs text-ink">
                                            {s.judul}
                                        </span>
                                        <span className="block text-[10px] text-ink-3">
                                            <span className="font-mono">{s.kode}</span> ·{' '}
                                            {s.jenis_pendek} · {s.bobot} poin
                                            {s.penulis && ` · ${s.penulis}`}
                                        </span>
                                    </span>
                                </label>
                            ))}
                        </div>

                        <Tombol
                            nada="pri"
                            onClick={tambah}
                            disabled={!bolehUbah || pilihBank.length === 0}
                            title={
                                !bolehUbah
                                    ? 'Paket ini bukan milik Anda'
                                    : undefined
                            }
                        >
                            {pilihBank.length > 0
                                ? `Tambah ${pilihBank.length} butir`
                                : 'Tambah butir'}
                        </Tombol>

                        <p className="text-[10px] leading-relaxed text-ink-3">
                            Butir yang sudah ada di paket tidak ditawarkan lagi di sini.
                            Soal baru yang Anda tulis lewat builder juga langsung muncul.
                        </p>
                    </div>
                </Kotak>
            </div>

            {identitas && (
                <FormIdentitas
                    paket={paket}
                    padaTutup={() => setIdentitas(false)}
                />
            )}
        </GuruLayout>
    );
}

/* ------------------------------------------------------------------ KPI */

function Kpi({ label, nilai }: { label: string; nilai: number }) {
    return (
        <div className="rounded-wf border border-line bg-surface px-3 py-2">
            <span className="block text-[10px] font-medium tracking-wide text-ink-3 uppercase">
                {label}
            </span>
            <span className="font-display text-lg font-semibold text-ink">
                {nilai.toLocaleString('id-ID')}
            </span>
        </div>
    );
}

/* --------------------------------------------------------- Poin per butir */

/**
 * Poin butir, dapat disunting di tempat.
 *
 * Kosong berarti "pakai bobot soalnya" - dan itu permintaan yang berbeda dari
 * nol. Nol berarti soal bonus yang dikerjakan tetapi tidak dihitung; kosong
 * berarti ikut bobot soalnya. Karena itu mengetik nol menyimpan `0`, dan
 * menghapus isinya menyimpan `null`.
 */
function PoinButir({
    butir,
    paketId,
    aktif,
}: {
    butir: Butir;
    paketId: number;
    aktif: boolean;
}) {
    const [nilai, setNilai] = useState(
        butir.poin_override !== null ? String(butir.poin_override) : '',
    );

    if (!aktif) {
        return <span className="font-mono text-xs text-ink-2">{butir.poin}</span>;
    }

    const simpan = () => {
        const dikirim = nilai.trim() === '' ? null : nilai.trim();

        router.put(
            `/guru/paket/${paketId}/butir/${butir.id}`,
            { poin: dikirim },
            { preserveScroll: true },
        );
    };

    return (
        <input
            value={nilai}
            onChange={(e) => setNilai(e.target.value)}
            onBlur={simpan}
            onKeyDown={(e) => {
                if (e.key === 'Enter') {
                    e.currentTarget.blur();
                }
            }}
            placeholder={String(butir.poin)}
            title="Kosongkan untuk memakai bobot soalnya"
            className="w-16 rounded-wf border border-line bg-surface px-1.5 py-1 font-mono text-xs text-ink outline-none focus:border-accent"
        />
    );
}

/* ------------------------------------------------------- Ubah identitas */

/**
 * Identitas paket - nama, tingkat, keterangan.
 *
 * MAPEL tidak ada di sini, dan itu disengaja: butirnya sudah dipilih dari
 * mapel lama, dan memindahkan paket ke mapel lain akan meninggalkan butir
 * yang mapelnya tidak cocok. Pelanggaran itu baru ketahuan saat snapshot
 * menolaknya - jauh setelah Guru menekan tombol yang tampak berhasil.
 */
function FormIdentitas({ paket, padaTutup }: { paket: Paket; padaTutup: () => void }) {
    const form = useForm({
        name: paket.nama,
        grade_level: paket.tingkat ?? '',
        description: paket.keterangan ?? '',
    });

    return (
        <div className="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-ink/30 p-4 sm:p-8">
            <div className="w-full max-w-lg rounded-wf border border-line bg-surface shadow-wf">
                <header className="border-b border-line px-4 py-3">
                    <h2 className="font-display text-sm font-semibold text-ink">
                        Ubah identitas paket
                    </h2>
                </header>

                <div className="space-y-3 p-4">
                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-ink-2">
                            Nama paket
                        </span>
                        <input
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            className={kelasInput}
                        />
                        {form.errors.name && (
                            <span className="mt-1 block text-[11px] text-bad">
                                {form.errors.name}
                            </span>
                        )}
                    </label>

                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-ink-2">
                            Tingkat kelas
                        </span>
                        <input
                            value={form.data.grade_level}
                            onChange={(e) => form.setData('grade_level', e.target.value)}
                            className={kelasInput}
                        />
                    </label>

                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-ink-2">
                            Keterangan
                        </span>
                        <textarea
                            value={form.data.description}
                            onChange={(e) => form.setData('description', e.target.value)}
                            rows={2}
                            className={kelasInput}
                        />
                    </label>

                    <p className="text-[11px] text-ink-3">
                        Mata pelajaran tidak dapat dipindah: butir di paket ini dipilih
                        dari mapel asalnya.
                    </p>

                    <div className="flex justify-end gap-2">
                        <Tombol onClick={padaTutup}>Batal</Tombol>
                        <Tombol
                            nada="pri"
                            disabled={form.processing}
                            onClick={() =>
                                form.put(`/guru/paket/${paket.id}`, {
                                    preserveScroll: true,
                                    onSuccess: padaTutup,
                                })
                            }
                        >
                            Simpan
                        </Tombol>
                    </div>
                </div>
            </div>
        </div>
    );
}

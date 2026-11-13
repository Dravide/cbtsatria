import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { formatUkuran } from '@/Components/angka';
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
 * Username diturunkan dari NIS/NIP, tetapi BISA diganti
 * ---------------------------------------------------------------------
 * Saat akun dibuat, username tidak diketik Admin: ia diturunkan dari NIS/NIP
 * di server. Alasannya ada di layar login - kalau Admin mengarang username,
 * ia bisa mengarang yang bertabrakan dengan akun sekolah lain, dan
 * kegagalannya muncul jauh kemudian di layar login siswa pada hari ujian.
 *
 * Tetapi NIS dan NIP memang salah ketik. Karena itu ada tindakan tersendiri
 * untuk MENGGANTI username akun yang sudah ada - dan bukan kolom di form
 * Ubah. Perbedaannya bukan tata letak: sebagai kolom di antara nama dan
 * email, username akan ikut tersimpan setiap kali Admin memperbaiki surel
 * seseorang, dan satu salah ketik yang tidak disadari mengunci siswa itu
 * pada hari ujian. Sebagai tindakan tersendiri, ia menuntut niat, dan
 * layarnya bisa menyebut akibatnya lebih dulu.
 *
 * Yang tidak berubah: memperbaiki NIS tidak pernah menurunkan username ulang.
 * Username tertempel di kartu login, dan mengubahnya diam-diam di belakang
 * perbaikan NIS berarti mengunci setiap siswa yang sudah menghafalnya.
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
    ekspor: Ekspor[];
    format: string[];
};

/**
 * Satu baris riwayat ekspor.
 *
 * `status_nada` datang dari server (`UserExport::nadaStatus()`), bukan
 * dihitung di sini - `nadaStatus()` di `ui.tsx` memetakan kosakata status
 * event, presensi, dan master data; ia tidak mengenal `menunggu`/`diproses`.
 * Menghitungnya di model berarti komponen bersama itu tidak perlu disentuh
 * setiap ada kosakata status baru.
 */
type Ekspor = {
    id: number;
    format: string;
    peran: string | null;
    status: string;
    status_label: string;
    status_nada: string;
    total_baris: number | null;
    ukuran: number | null;
    nama_berkas: string | null;
    pesan_gagal: string | null;
    peminta: string | null;
    diminta_pada: string | null;
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

export default function Pengguna({ tab, tabnya, baris, filter, jumlah, ekspor, format }: Props) {
    const [tambah, setTambah] = useState(false);
    const [pilih, setPilih] = useState<number[]>([]);
    const [ubah, setUbah] = useState<Baris | null>(null);
    const [gantiUsername, setGantiUsername] = useState<Baris | null>(null);
    const [eksporTerbuka, setEksporTerbuka] = useState(false);

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

    /*
     * Kartu akun hanya untuk GURU - tab ini juga menampilkan siswa dan
     * pengawas, dan kartu akun siswa dicetak per kelas dari laman lain.
     * Server menyaring rolenya juga; tombol ini menyembunyikannya supaya
     * Admin tidak menekan tombol yang pasti ditolak.
     */
    const adaKartu = tab === 'teacher';

    /**
     * Cetak kartu satu guru, atau semua yang dicentang.
     *
     * Unduhan lewat `<a>`/navigasi, bukan `router.get`: berkasnya bukan
     * laman Inertia, dan respons PDF/ZIP tidak bisa diurai sebagai props.
     * Karena itu alamatnya disusun lalu dibuka di tab baru - pola yang sama
     * dengan tombol ekspor berkas di laman lain.
     */
    const cetakKartu = (id?: number[]) => {
        const parameter = new URLSearchParams();

        if (id && id.length > 0) {
            id.forEach((satu) => parameter.append('guru[]', String(satu)));
        } else {
            // Seluruh hasil saringan, bukan satu halaman: Admin yang mencari
            // "budi" lalu menekan "Semua kartu" mengharapkan semua yang
            // cocok, bukan lima puluh baris pertama.
            parameter.set('semua', '1');

            if (filter.cari) {
                parameter.set('cari', filter.cari);
            }
            if (filter.status) {
                parameter.set('status', filter.status);
            }
        }

        window.open(`/admin/pengguna/kartu?${parameter.toString()}`, '_blank', 'noopener');
    };

    return (
        <AdminLayout
            judul="Pengguna"
            breadcrumb="/admin/pengguna"
            aksi={
                <>
                    <Tombol onClick={() => router.visit('/admin/pengguna/impor')}>Impor</Tombol>
                    <Tombol onClick={() => setEksporTerbuka(true)}>Ekspor</Tombol>
                    {adaKartu && (
                        <Tombol
                            onClick={() => cetakKartu()}
                            title="Kartu akun seluruh guru pada penyaring ini - satu PDF per guru, dibungkus ZIP"
                        >
                            Kartu akun
                        </Tombol>
                    )}
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
                        <span className="ml-auto flex flex-wrap items-center gap-2">
                            <span className="text-xs text-ink-3">{pilih.length} terpilih</span>
                            {adaKartu && (
                                <Tombol
                                    onClick={() => cetakKartu(pilih)}
                                    title="Cetak kartu akun untuk baris yang dicentang"
                                >
                                    Kartu terpilih
                                </Tombol>
                            )}
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
                        // Lebar kolom aksi mengikuti jumlah tautannya: tab
                        // Guru punya tiga (Kartu, Username, Ubah), tab lain
                        // dua. `w-32` membuat tiga tautan berdesakan di tab
                        // Guru dan terlipat menjadi dua baris.
                        kelas: adaKartu ? 'w-40 text-right' : 'w-32 text-right',
                        render: (b) => (
                            <span className="flex justify-end gap-3">
                                {adaKartu && (
                                    <button
                                        onClick={() => cetakKartu([b.id])}
                                        className="text-xs text-accent hover:underline"
                                        title={`Cetak kartu akun ${b.nama}`}
                                    >
                                        Kartu
                                    </button>
                                )}
                                <button
                                    onClick={() => setGantiUsername(b)}
                                    className="text-xs text-accent hover:underline"
                                    title="Ganti username akun ini"
                                >
                                    Username
                                </button>
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

            <p className="mt-3 text-[11px] text-ink-3">
                {jumlah.toLocaleString('id-ID')} akun pada peran ini. Akun tidak pernah
                dihapus, hanya dinonaktifkan &mdash; riwayat ujian dan presensi menunjuk ke
                sana.
            </p>

            {ekspor.length > 0 && <RiwayatEkspor ekspor={ekspor} />}

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

            <FormGantiUsername
                key={'username-' + (gantiUsername?.id ?? 'kosong')}
                pengguna={gantiUsername}
                padaTutup={() => setGantiUsername(null)}
            />

            <FormEkspor
                terbuka={eksporTerbuka}
                tab={tab}
                filter={filter}
                format={format}
                padaTutup={() => setEksporTerbuka(false)}
            />
        </AdminLayout>
    );
}

/**
 * Permintaan ekspor - modal, bukan tiga tombol di header.
 *
 * ---------------------------------------------------------------------
 * Mengapa modal
 * ---------------------------------------------------------------------
 * Yang membuat ekspor ini berguna justru hal yang tidak terlihat di tombolnya:
 * ia mengekspor TAB DAN PENYARING yang sedang aktif. Admin yang menekan
 * "Ekspor" pada tab Guru sambil mengetik "budi" mengharapkan satu berkas
 * berisi guru bernama Budi - dan mendapatkannya. Tanpa kalimat yang
 * menyebutkannya, ia akan mengira dirinya mengekspor seluruh sekolah, dan
 * satu-satunya cara mengetahui jawabannya adalah membuka berkasnya.
 *
 * Header di laman ini sudah memuat Impor dan Tambah; modal ini satu-satunya
 * tempat yang bisa mengatakan APA yang akan diekspor sebelum menekan tombol.
 */
/**
 * Ganti username - modal tersendiri, bukan kolom di form Ubah.
 *
 * ---------------------------------------------------------------------
 * Mengapa dipisah dari form Ubah
 * ---------------------------------------------------------------------
 * Username adalah satu-satunya identitas yang diketik saat login, dan ia
 * tertempel di kartu siswa. Sebagai satu kolom di antara nama, email, dan
 * telepon, ia akan ikut tersimpan setiap kali Admin memperbaiki alamat surel
 * seseorang - dan satu salah ketik yang tidak disadari mengunci siswa itu
 * pada hari ujian, tanpa galat apa pun di layar Admin.
 *
 * Sebagai tindakan tersendiri, ia menuntut niat, dan layarnya bisa menyebut
 * akibatnya sebelum Admin mengetik: siswa yang menghafal angka lama akan
 * gagal masuk, dan kartunya perlu dicetak ulang.
 */
function FormGantiUsername({
    pengguna,
    padaTutup,
}: {
    pengguna: Baris | null;
    padaTutup: () => void;
}) {
    const { data, setData, put, processing, errors, reset } = useForm({
        username: pengguna?.username ?? '',
    });

    return (
        <Modal
            judul={pengguna ? `Ganti username ${pengguna.nama}` : 'Ganti username'}
            terbuka={pengguna !== null}
            padaTutup={padaTutup}
        >
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    put(`/admin/pengguna/${pengguna?.id}/username`, {
                        onSuccess: () => {
                            padaTutup();
                            reset();
                        },
                    });
                }}
                className="space-y-3"
            >
                <Field
                    label="Username"
                    galat={errors.username}
                    petunjuk="Huruf kecil, angka, dan titik. Username dipakai lintas sekolah, jadi nama yang sama dengan akun sekolah lain akan ditolak."
                >
                    <input
                        value={data.username}
                        onChange={(e) => setData('username', e.target.value)}
                        className={kelasInput + ' font-mono'}
                        autoComplete="off"
                    />
                </Field>

                <Inbox>
                    Username adalah satu-satunya yang diketik siswa di layar login, dan
                    ia tertempel di kartu siswa. Menggantinya berarti siswa yang
                    menghafal username lama tidak bisa masuk sampai kartunya diperbarui
                    &mdash; dan ia akan melapor sebagai &ldquo;username atau sandi
                    salah&rdquo;, yang tidak menjelaskan apa pun kepada pengawas.
                </Inbox>

                <div className="flex gap-2 pt-1">
                    <Tombol type="submit" nada="pri" disabled={processing}>
                        Simpan username
                    </Tombol>
                    <Tombol onClick={padaTutup}>Batal</Tombol>
                </div>
            </form>
        </Modal>
    );
}

function FormEkspor({
    terbuka,
    tab,
    filter,
    format,
    padaTutup,
}: {
    terbuka: boolean;
    tab: string;
    filter: { cari: string; status: string };
    format: string[];
    padaTutup: () => void;
}) {
    const [proses, setProses] = useState(false);

    const minta = (satuFormat: string) => {
        setProses(true);

        router.post(
            '/admin/pengguna/ekspor',
            // Peran dan penyaringnya dikirim APA ADANYA dari layar. Server
            // menyimpannya di `parameter_json` lalu memberikannya ke job, dan
            // itulah satu-satunya jalur yang membuat berkasnya berisi baris
            // yang Admin lihat - bukan seluruh 1.284 akun.
            { format: satuFormat, role: tab, cari: filter.cari, status: filter.status },
            { onFinish: () => setProses(false) },
        );

        padaTutup();
    };

    return (
        <Modal judul="Ekspor daftar pengguna" terbuka={terbuka} padaTutup={padaTutup}>
            <div className="space-y-3 text-sm">
                <p className="text-ink-2">
                    Yang diekspor adalah tab <strong className="text-ink">{LABEL_TAB[tab]}</strong>{' '}
                    dengan penyaring yang sedang aktif
                    {filter.cari ? (
                        <>
                            {' '}
                            &mdash; kata kunci <span className="font-mono">{filter.cari}</span>
                        </>
                    ) : null}
                    {filter.status ? (
                        <>
                            {' '}
                            &mdash; status{' '}
                            <span className="font-mono">
                                {filter.status === '1' ? 'aktif' : 'nonaktif'}
                            </span>
                        </>
                    ) : null}
                    .
                </p>

                <p className="text-[11px] text-ink-3">
                    Berkasnya disiapkan di latar belakang. Unduh dari daftar Ekspor terakhir
                    begitu statusnya siap &mdash; berkasnya bisa langsung diunggah kembali
                    lewat Impor setelah diperbaiki di Excel.
                </p>

                <div className="flex gap-2 pt-1">
                    {format.map((satu) => (
                        <Tombol
                            key={satu}
                            nada={satu === 'xlsx' ? 'pri' : undefined}
                            disabled={proses}
                            onClick={() => minta(satu)}
                        >
                            {satu.toUpperCase()}
                        </Tombol>
                    ))}
                    <Tombol onClick={padaTutup}>Batal</Tombol>
                </div>
            </div>
        </Modal>
    );
}

/**
 * Riwayat ekspor - lima terakhir, di bawah tabel.
 *
 * Menyalin blok "Ekspor terakhir" di `Laporan.tsx`, dengan satu aturan yang
 * sama: tautan unduhnya HANYA muncul saat statusnya `selesai`. Tautan yang
 * berakhir 404 pada ekspor yang masih menunggu membuat Admin menyimpulkan
 * berkasnya hilang, padahal ia hanya belum jadi.
 */
function RiwayatEkspor({ ekspor }: { ekspor: Ekspor[] }) {
    return (
        <section className="mt-4 rounded-wf border border-line bg-surface">
            <header className="border-b border-line px-4 py-2.5">
                <h2 className="font-display text-sm font-semibold text-ink">
                    Ekspor terakhir
                </h2>
            </header>

            <div className="divide-y divide-line/60">
                {ekspor.map((e) => (
                    <div
                        key={e.id}
                        className="flex flex-wrap items-center gap-3 px-4 py-2.5 text-xs"
                    >
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-ink">
                                {e.peran ? (LABEL_TAB[e.peran] ?? e.peran) : 'Semua peran'}
                                <span className="ml-1 font-mono text-[11px] text-ink-3">
                                    .{e.format.toLowerCase()}
                                </span>
                            </p>
                            <p className="mt-0.5 truncate text-[11px] text-ink-3">
                                {[
                                    e.peminta && `oleh ${e.peminta}`,
                                    e.diminta_pada,
                                    e.total_baris !== null && `${e.total_baris} baris`,
                                    e.ukuran !== null && formatUkuran(e.ukuran),
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </p>
                            {e.pesan_gagal && (
                                <p className="mt-1 line-clamp-2 text-[11px] text-bad">
                                    {e.pesan_gagal}
                                </p>
                            )}
                        </div>

                        <Chip nada={nadaChip(e.status_nada)}>{e.status_label}</Chip>

                        {e.status === 'selesai' ? (
                            <a
                                href={`/admin/pengguna/ekspor/${e.id}/unduh`}
                                className="rounded-wf border border-line px-2.5 py-1 text-[11px] font-medium text-accent hover:bg-surface-2"
                            >
                                Unduh
                            </a>
                        ) : (
                            // Lebar tetap supaya status dan tautan sejajar di
                            // seluruh baris: tanpa itu, baris yang belum
                            // selesai membuat chip-nya bergeser ke kanan, dan
                            // daftarnya terlihat berantakan justru saat sedang
                            // ada pekerjaan berjalan.
                            <span className="w-[3.5rem]" />
                        )}
                    </div>
                ))}
            </div>
        </section>
    );
}

/** Nada chip dari server, disempitkan ke yang dikenal `Chip`. */
function nadaChip(nada: string): 'ok' | 'warn' | 'bad' | 'info' | 'mute' {
    switch (nada) {
        case 'ok':
        case 'warn':
        case 'bad':
        case 'info':
            return nada;
        default:
            return 'mute';
    }
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

import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { konfirmasi } from '@/Components/dialog';
import {
    BilahFilter,
    Chip,
    Field,
    Inbox,
    Modal,
    Paginator,
    Tabel,
    Tombol,
    TombolTautan,
    kelasInput,
    nadaStatus,
    pakaiFilter,
} from '@/Components/ui';

/**
 * Laman 09 - Ruang. Wireframe plate 09.
 *
 * ---------------------------------------------------------------------
 * Kolom PC memisahkan ruang berkomputer dari ruang BYOD
 * ---------------------------------------------------------------------
 * Wireframe menampilkan tiga keadaan yang berbeda dan semuanya sah: R-01
 * dengan 32 kapasitas dan 32 PC, R-04 dengan chip "BYOD", R-09 berstatus
 * perbaikan.
 *
 * Perbedaannya bukan hiasan. Ruang ber-PC bisa disiapkan sebelum ujian; ruang
 * BYOD tidak, karena perangkatnya milik siswa dan baru muncul di hari-H. Chip
 * "BYOD" karena itu ditampilkan di kolom PC sebagai 0 dengan penanda, bukan
 * disembunyikan sebagai kolom kosong - Admin perlu melihat ruang mana yang
 * bergantung pada perangkat siswa.
 */
type Baris = {
    id: number;
    kode: string;
    nama: string | null;
    lokasi: string | null;
    kapasitas: number;
    pc: number;
    byod: boolean;
    status: string;
};

type Props = {
    baris: Paginator<Baris>;
    filter: { cari: string; status: string };
    ringkasan: { kapasitas: number; pc: number; byod: number };
    jumlah: number;
};

export default function Ruang({ baris, filter, ringkasan, jumlah }: Props) {
    const [tambah, setTambah] = useState(false);
    const [ubah, setUbah] = useState<Baris | null>(null);
    const [pilih, setPilih] = useState<number[]>([]);
    const [kapasitas, setKapasitas] = useState(false);

    const f = pakaiFilter('/admin/ruang', { cari: filter.cari, status: filter.status });

    // Pilihan direset setiap kali isi halaman berganti. Membiarkannya berarti
    // id dari halaman yang sudah ditinggalkan ikut terkirim - dan operasi
    // massal yang mengubah ruang yang tidak terlihat di layar adalah operasi
    // yang tidak bisa diperiksa Admin sebelum ia menekan tombolnya.
    const halaman = (baris.data ?? []).map((b) => b.id);
    const semuaTerpilih = halaman.length > 0 && halaman.every((id) => pilih.includes(id));

    const alihkan = (id: number, dicentang: boolean) =>
        setPilih((p) => (dicentang ? [...p, id] : p.filter((x) => x !== id)));

    const pilihSemua = () =>
        setPilih((p) => (semuaTerpilih ? p.filter((x) => !halaman.includes(x)) : [...new Set([...p, ...halaman])]));

    // Ruang terpilih yang kapasitasnya akan berubah, dihitung dari data yang
    // SEDANG tampil. Yang belum terlihat tidak bisa dihitung - dan justru itu
    // sebabnya angkanya ditampilkan: Admin melihat berapa ruang yang akan
    // tersentuh sebelum ia menekan Simpan.
    const terpilih = (baris.data ?? []).filter((b) => pilih.includes(b.id));

    const simpanKapasitas = (nilai: number) => {
        router.put(
            '/admin/ruang/kapasitas-massal',
            { id: pilih, capacity: nilai },
            { preserveScroll: true, onSuccess: () => setPilih([]) },
        );
    };

    return (
        <AdminLayout
            judul="Ruang ujian"
            breadcrumb="/admin/ruang"
            aksi={
                <>
                    {/* Satu berkas, satu lembar per pintu - lihat catatan di
                        `KartuPintu`. Diletakkan paling kiri karena ia dibuka
                        saat menyiapkan hari ujian, sebelum ruang baru
                        ditambahkan. */}
                    <TombolTautan
                        href="/admin/ruang/kartu"
                        title="Cetak kartu pintu (QR + tautan) semua ruang, satu lembar A4 per ruang"
                    >
                        Cetak kartu pintu
                    </TombolTautan>
                    {/* Massal, bukan per ruang: yang menyiapkan hari ujian
                        menyalakan seluruh pintu sekaligus. Sama seperti pada
                        kode pengawas, tombol ini hanya melayani ruang yang
                        BELUM punya tautan - mencabut ulang dua puluh empat
                        kartu yang sudah ditempel bukan yang diminta siapa pun
                        yang menekannya. */}
                    <Tombol
                        onClick={async () => {
                            const setuju = await konfirmasi({
                                judul: 'Terbitkan tautan untuk ruang yang belum punya?',
                                pesan: 'Tautan yang sudah berlaku TIDAK dicabut, sehingga kartu pintu yang sudah ditempel tetap bisa dipakai.',
                                labelYa: 'Terbitkan tautan',
                            });

                            if (!setuju) {
                                return;
                            }

                            router.post(
                                '/admin/ruang/terbitkan-massal',
                                {},
                                { preserveScroll: true },
                            );
                        }}
                    >
                        Terbitkan tautan massal
                    </Tombol>
                    <Tombol onClick={() => router.visit('/admin/impor/ruang')}>Impor</Tombol>
                    <Tombol nada="pri" onClick={() => setTambah(true)}>
                        Tambah ruang
                    </Tombol>
                </>
            }
        >
            <Head title="Ruang" />

            {/* Angka ringkasan di atas bilah filter - Admin melihat ukuran
                dulu, baru mempersempit. */}
            <div className="mb-3 grid gap-3 sm:grid-cols-3">
                <Ringkasan label="Total kapasitas" nilai={ringkasan.kapasitas} />
                <Ringkasan label="Unit PC" nilai={ringkasan.pc} />
                <Ringkasan label="Ruang BYOD" nilai={ringkasan.byod} />
            </div>

            <div className="mb-3">
                <BilahFilter padaKirim={f.terapkan}>
                    <input
                        value={f.nilai.cari ?? ''}
                        onChange={(e) => f.ubah('cari', e.target.value)}
                        placeholder="Cari kode, nama, atau lokasi..."
                        className={kelasInput + ' max-w-xs'}
                    />
                    <select
                        value={f.nilai.status ?? ''}
                        onChange={(e) => f.ubah('status', e.target.value)}
                        className={kelasInput + ' max-w-[10rem]'}
                    >
                        <option value="">Semua status</option>
                        <option value="aktif">Aktif</option>
                        <option value="perbaikan">Perbaikan</option>
                        <option value="nonaktif">Nonaktif</option>
                    </select>
                    <Tombol type="submit" nada="pri">
                        Terapkan
                    </Tombol>
                    <Tombol onClick={f.reset}>Reset</Tombol>

                    {/* Aksi massal hidup di bar seleksi, sama seperti laman
                        Pengguna - dan itu memang tempatnya: yang menekannya
                        baru saja mencentang barisnya, dan tombol yang muncul
                        tepat di sebelah cacahnya menjelaskan sendiri apa yang
                        akan tersentuh. Jumlahnya disebut di depan tombol,
                        karena "Ubah kapasitas massal" tanpa angka tidak
                        memberi tahu apakah yang terpilih dua ruang atau
                        seluruh sekolah. */}
                    {pilih.length > 0 ? (
                        <span className="ml-auto flex items-center gap-2">
                            <span className="text-xs text-ink-3">
                                {pilih.length} ruang terpilih
                            </span>
                            <Tombol nada="pri" onClick={() => setKapasitas(true)}>
                                Ubah kapasitas massal
                            </Tombol>
                            <Tombol onClick={() => setPilih([])}>Batal pilih</Tombol>
                        </span>
                    ) : (
                        <span className="ml-auto flex items-center gap-2">
                            <Tombol onClick={pilihSemua} disabled={halaman.length === 0}>
                                {semuaTerpilih ? 'Batalkan semua' : 'Pilih semua di halaman ini'}
                            </Tombol>
                        </span>
                    )}
                </BilahFilter>
            </div>

            <Tabel<Baris>
                hal={baris}
                kunci={(b) => b.id}
                kosong="Belum ada ruang."
                kolom={[
                    {
                        judul: '',
                        kelas: 'w-10',
                        render: (b) => (
                            <input
                                type="checkbox"
                                checked={pilih.includes(b.id)}
                                onChange={(e) => alihkan(b.id, e.target.checked)}
                                className="size-4 accent-accent"
                                aria-label={`Pilih ${b.kode}`}
                            />
                        ),
                    },
                    {
                        judul: 'Kode',
                        kelas: 'w-24',
                        render: (b) => (
                            <Link
                                href={`/admin/ruang/${b.id}`}
                                className="font-mono text-xs font-medium text-accent hover:underline"
                            >
                                {b.kode}
                            </Link>
                        ),
                    },
                    {
                        judul: 'Nama & lokasi',
                        render: (b) => (
                            <span>
                                <span className="text-ink">{b.nama ?? '-'}</span>
                                {b.lokasi && (
                                    <span className="ml-2 text-xs text-ink-3">{b.lokasi}</span>
                                )}
                            </span>
                        ),
                    },
                    {
                        judul: 'Kapasitas',
                        kelas: 'w-24',
                        render: (b) => (
                            <span className="font-mono text-sm text-ink">{b.kapasitas}</span>
                        ),
                    },
                    {
                        judul: 'PC',
                        kelas: 'w-28',
                        render: (b) =>
                            b.byod ? (
                                <Chip nada="warn">BYOD</Chip>
                            ) : (
                                <span className="font-mono text-sm text-ink">{b.pc}</span>
                            ),
                    },
                    {
                        judul: 'Status',
                        kelas: 'w-28',
                        render: (b) => <Chip nada={nadaStatus(b.status)}>{b.status}</Chip>,
                    },
                    {
                        judul: '',
                        kelas: 'w-32 text-right',
                        render: (b) => (
                            <span className="flex justify-end gap-3">
                                <Link
                                    href={`/admin/ruang/${b.id}`}
                                    className="text-xs text-accent hover:underline"
                                >
                                    Tautan pintu
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

            <div className="mt-3 space-y-3">
                <Inbox>
                    Ruang master dipakai ulang lintas event. Satu ruang fisik yang sama muncul
                    di banyak event sepanjang tahun, dan tautan pintunya terikat pada ruang
                    itu &mdash; bukan pada event tertentu.
                </Inbox>

                <p className="text-[11px] text-ink-3">
                    {jumlah.toLocaleString('id-ID')} ruang terdaftar.
                </p>
            </div>

            <FormRuang terbuka={tambah} padaTutup={() => setTambah(false)} />
            {/* `key` BUKAN hiasan: `Modal` mengembalikan `null` dari dalam
                dirinya sendiri, sehingga komponen form di luarnya TIDAK pernah
                dilepas. `useForm` menangkap nilai awalnya sekali saja, saat
                masih terpasang - dan saat itu `ruang` masih kosong. Tanpa
                `key`, panel "Ubah" terbuka dengan kolom kosong, lalu menyimpan
                akan menimpa ruang yang dipilih dengan isian kosong. */}
            <FormRuang
                key={'ubah-' + (ubah?.id ?? 'kosong')}
                terbuka={ubah !== null}
                ruang={ubah}
                padaTutup={() => setUbah(null)}
            />
            {/* `key` berisi DAFTAR id terpilih, bukan sekadar jumlahnya.
                Tanpa itu, membatalkan pilihan R-03 lalu mencentang R-07 -
                jumlahnya tetap satu - akan membuka panel yang masih membawa
                angka awal dari R-03, dan menyimpannya berarti menyetel
                kapasitas R-07 ke angka yang dipilih untuk ruang lain. Jebakan
                yang sama sudah tercatat pada `useForm` + `Modal`. */}
            <FormKapasitas
                key={'kapasitas-' + [...pilih].sort((a, b) => a - b).join('-')}
                terbuka={kapasitas}
                terpilih={terpilih}
                jumlah={pilih.length}
                padaTutup={() => setKapasitas(false)}
                padaSimpan={simpanKapasitas}
            />
        </AdminLayout>
    );
}

function Ringkasan({ label, nilai }: { label: string; nilai: number }) {
    return (
        <div className="rounded-wf border border-line bg-surface px-4 py-3">
            <p className="text-xs text-ink-3">{label}</p>
            <p className="mt-1 font-mono text-2xl font-medium text-ink">
                {nilai.toLocaleString('id-ID')}
            </p>
        </div>
    );
}

function FormKapasitas({
    terbuka,
    terpilih,
    jumlah,
    padaTutup,
    padaSimpan,
}: {
    terbuka: boolean;
    terpilih: Baris[];
    jumlah: number;
    padaTutup: () => void;
    padaSimpan: (nilai: number) => void;
}) {
    // Nilai awalnya diambil dari ruang terpilih yang PERTAMA, bukan dari
    // angka tetap. Admin yang mengubah 32 menjadi 36 pada dua belas ruang
    // hampir selalu memakai angka yang sudah ada sebagai titik tolak, dan
    // angka 32 yang diketik ulang dari ingatan adalah salah ketik yang
    // menghasilkan kapasitas 3.
    const { data, setData, processing, errors } = useForm({
        capacity: terpilih[0]?.kapasitas ?? 32,
    });

    const nilai = Number(data.capacity) || 0;

    return (
        <Modal
            judul={`Ubah kapasitas ${jumlah} ruang`}
            terbuka={terbuka}
            padaTutup={padaTutup}
        >
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    padaSimpan(nilai);
                }}
                className="space-y-3"
            >
                <Field label="Kapasitas baru" galat={errors.capacity}>
                    <input
                        type="number"
                        min={0}
                        max={1000}
                        value={data.capacity}
                        onChange={(e) => setData('capacity', Number(e.target.value))}
                        className={kelasInput + ' font-mono max-w-[8rem]'}
                        autoFocus
                    />
                </Field>

                {/* Akibatnya diperlihatkan SEBELUM disimpan, bukan dilaporkan
                    sesudahnya. Dua akibat yang tidak terlihat dari satu angka:
                    ruang yang kapasitasnya sudah sama (tidak berubah), dan
                    ruang yang jumlah PC-nya akan ikut turun. */}
                <div className="rounded-wf border border-line bg-surface-2/60 px-3 py-2">
                    <p className="text-[11px] font-medium text-ink-2">
                        {jumlah} ruang akan disetel ke {nilai}
                    </p>
                    {terpilih.length > 0 && (
                        <ul className="mt-1 space-y-0.5">
                            {terpilih.map((b) => {
                                const turunPc = b.pc > nilai && !b.byod;

                                return (
                                    <li
                                        key={b.id}
                                        className="flex items-baseline gap-2 font-mono text-[11px] text-ink-3"
                                    >
                                        <span className="text-ink-2">{b.kode}</span>
                                        <span>
                                            {b.kapasitas} &rarr; {nilai}
                                        </span>
                                        {b.kapasitas === nilai && (
                                            <span className="text-ink-3">(tetap)</span>
                                        )}
                                        {turunPc && (
                                            <span className="text-warn">
                                                PC {b.pc} &rarr; {nilai}
                                            </span>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                    {jumlah > terpilih.length && (
                        <p className="mt-1 text-[11px] text-ink-3">
                            {jumlah - terpilih.length} ruang terpilih lainnya tidak tampil di
                            halaman ini, tetapi ikut diubah.
                        </p>
                    )}
                </div>

                <div className="flex gap-2 pt-1">
                    <Tombol type="submit" nada="pri" disabled={processing}>
                        Setel kapasitas
                    </Tombol>
                    <Tombol onClick={padaTutup}>Batal</Tombol>
                </div>
            </form>
        </Modal>
    );
}

function FormRuang({
    terbuka,
    ruang,
    padaTutup,
}: {
    terbuka: boolean;
    ruang?: Baris | null;
    padaTutup: () => void;
}) {
    const ubah = Boolean(ruang);

    const { data, setData, post, put, processing, errors, reset } = useForm({
        code: ruang?.kode ?? '',
        name: ruang?.nama ?? '',
        location: ruang?.lokasi ?? '',
        capacity: ruang?.kapasitas ?? 32,
        device_count: ruang?.pc ?? 32,
        status: ruang?.status ?? 'aktif',
    });

    return (
        <Modal
            judul={ubah ? `Ubah ${ruang?.kode}` : 'Tambah ruang'}
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

                    if (ubah && ruang) {
                        put(`/admin/ruang/${ruang.id}`, opsi);
                    } else {
                        post('/admin/ruang', opsi);
                    }
                }}
                className="space-y-3"
            >
                <div className="grid gap-3 sm:grid-cols-[6rem_1fr]">
                    <Field label="Kode" galat={errors.code}>
                        <input
                            value={data.code}
                            onChange={(e) => setData('code', e.target.value)}
                            placeholder="R-01"
                            className={kelasInput + ' font-mono'}
                        />
                    </Field>
                    <Field label="Nama" galat={errors.name}>
                        <input
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="Lab Komputer"
                            className={kelasInput}
                        />
                    </Field>
                </div>

                <Field label="Lokasi" galat={errors.location}>
                    <input
                        value={data.location}
                        onChange={(e) => setData('location', e.target.value)}
                        placeholder="Gedung A lantai 2"
                        className={kelasInput}
                    />
                </Field>

                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Kapasitas" galat={errors.capacity}>
                        <input
                            type="number"
                            min={0}
                            max={1000}
                            value={data.capacity}
                            onChange={(e) => setData('capacity', Number(e.target.value))}
                            className={kelasInput + ' font-mono'}
                        />
                    </Field>

                    <Field
                        label="Jumlah PC"
                        galat={errors.device_count}
                        petunjuk="Isi 0 untuk ruang BYOD."
                    >
                        <input
                            type="number"
                            min={0}
                            max={1000}
                            value={data.device_count}
                            onChange={(e) => setData('device_count', Number(e.target.value))}
                            className={kelasInput + ' font-mono'}
                        />
                    </Field>
                </div>

                {data.device_count === 0 && (
                    <Chip nada="warn">Ruang ini akan ditandai BYOD</Chip>
                )}

                <Field label="Status" galat={errors.status}>
                    <select
                        value={data.status}
                        onChange={(e) => setData('status', e.target.value)}
                        className={kelasInput}
                    >
                        <option value="aktif">aktif</option>
                        <option value="perbaikan">perbaikan</option>
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

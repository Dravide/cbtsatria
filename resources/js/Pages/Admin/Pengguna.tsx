import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { formatUkuran } from '@/Components/angka';
import { konfirmasi } from '@/Components/dialog';
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
 * Username awal diturunkan dari NIS/NIP di server; memperbarui NIS tidak otomatis mengubah username.
 * Penggantian username dipisah ke modal tersendiri agar tidak ikut berubah secara tidak sengaja saat menyunting profil.
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
    ai_kuota?: { batas: number; terpakai: number; sisa: number } | null;
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

/** `status_nada` dihitung di `UserExport::nadaStatus()` karena `ui.tsx` tidak memetakan status ekspor. */
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
    const [kuotaAiGuru, setKuotaAiGuru] = useState<Baris | null>(null);
    const [eksporTerbuka, setEksporTerbuka] = useState(false);
    const [editMassalTarget, setEditMassalTarget] = useState<Baris[] | null>(null);

    const f = pakaiFilter('/admin/pengguna', { cari: filter.cari, status: filter.status });

    const gantiTab = (nilai: string) => {
        setPilih([]);
        setEditMassalTarget(null);
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

    const resetKuotaAiMassal = () => {
        if (pilih.length === 0) {
            return;
        }

        router.post('/admin/pengguna/reset-kuota-ai', { id: pilih }, { preserveScroll: true });
        setPilih([]);
    };

    // Kartu akun dari halaman ini khusus guru; kartu siswa dicetak per kelas di halaman lain.
    const adaKartu = tab === 'teacher';
    const tabPengawas = tab === 'supervisor';

    const hapusSemuaPengawas = async () => {
        const total = tabnya.supervisor ?? 0;

        if (total === 0) {
            return;
        }

        const setuju = await konfirmasi({
            judul: `Hapus semua ${total.toLocaleString('id-ID')} akun pengawas?`,
            pesan: 'Seluruh akun pada tab Pengawas di sekolah ini akan dihapus permanen dan sesi loginnya diakhiri. Akun yang sudah terkait riwayat event/presensi tetap diarsipkan agar data ujian aman.',
            labelYa: 'Ya, hapus semua pengawas',
            nada: 'bahaya',
        });

        if (!setuju) {
            return;
        }

        router.delete('/admin/pengguna/pengawas', {
            preserveScroll: true,
            onSuccess: () => setPilih([]),
        });
    };

    const hapusPengawas = async (b: Baris) => {
        const setuju = await konfirmasi({
            judul: `Hapus pengawas ${b.nama}?`,
            pesan: `Akun pengawas ${b.nama} (${b.username}) akan dihapus permanen dan sesi loginnya diakhiri. Bila sudah terkait riwayat event/presensi, akun akan diarsipkan agar data ujian aman.`,
            labelYa: 'Ya, hapus pengawas',
            nada: 'bahaya',
        });

        if (!setuju) {
            return;
        }

        router.delete(`/admin/pengguna/pengawas/${b.id}`, {
            preserveScroll: true,
            onSuccess: () => setPilih((p) => p.filter((x) => x !== b.id)),
        });
    };

    const hapusPengawasTerpilih = async () => {
        if (pilih.length === 0) {
            return;
        }

        const setuju = await konfirmasi({
            judul: `Hapus ${pilih.length} pengawas terpilih?`,
            pesan: 'Akun pengawas yang dipilih akan dihapus permanen dan sesi loginnya diakhiri. Akun yang sudah terkait riwayat event/presensi tetap diarsipkan agar data ujian aman.',
            labelYa: 'Ya, hapus terpilih',
            nada: 'bahaya',
        });

        if (!setuju) {
            return;
        }

        router.delete('/admin/pengguna/pengawas/terpilih', {
            data: { id: pilih },
            preserveScroll: true,
            onSuccess: () => setPilih([]),
        });
    };

    /** Buka unduhan PDF/ZIP di tab baru (bukan `router.get`) karena respons bukan halaman Inertia. */
    const cetakKartu = (id?: number[]) => {
        const parameter = new URLSearchParams();

        if (id && id.length > 0) {
            id.forEach((satu) => parameter.append('guru[]', String(satu)));
        } else {
            // Ekspor seluruh hasil saringan aktif, bukan hanya halaman tabel saat ini.
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
                    {tabPengawas && baris.data.length > 0 && (
                        <Tombol
                            onClick={() => {
                                const sasaran =
                                    pilih.length > 0
                                        ? baris.data.filter((b) => pilih.includes(b.id))
                                        : baris.data;
                                setEditMassalTarget(sasaran);
                            }}
                            title="Edit data banyak pengawas sekaligus dalam satu tabel"
                        >
                            {pilih.length > 0
                                ? `Edit massal (${pilih.length})`
                                : 'Edit massal'}
                        </Tombol>
                    )}
                    {tabPengawas && (tabnya.supervisor ?? 0) > 0 && (
                        <Tombol
                            nada="bahaya"
                            onClick={hapusSemuaPengawas}
                            title="Hapus semua akun pengawas di sekolah ini"
                        >
                            Hapus semua pengawas
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
                                <>
                                    <Tombol
                                        onClick={() => cetakKartu(pilih)}
                                        title="Cetak kartu akun untuk baris yang dicentang"
                                    >
                                        Kartu terpilih
                                    </Tombol>
                                    <Tombol
                                        onClick={resetKuotaAiMassal}
                                        title="Reset pemakaian kuota generate AI guru terpilih ke 0"
                                    >
                                        Reset kuota AI
                                    </Tombol>
                                </>
                            )}
                            {tabPengawas && (
                                <>
                                    <Tombol
                                        onClick={() =>
                                            setEditMassalTarget(
                                                baris.data.filter((b) => pilih.includes(b.id)),
                                            )
                                        }
                                        title="Edit data pengawas yang dicentang sekaligus"
                                    >
                                        Edit terpilih
                                    </Tombol>
                                    <Tombol
                                        nada="bahaya"
                                        onClick={hapusPengawasTerpilih}
                                        title="Hapus akun pengawas yang dicentang"
                                    >
                                        Hapus terpilih
                                    </Tombol>
                                </>
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

            {editMassalTarget !== null ? (
                <PanelEditMassalPengawas
                    key={editMassalTarget.map((b) => b.id).join(',')}
                    target={editMassalTarget}
                    padaSelesai={() => {
                        setEditMassalTarget(null);
                        setPilih([]);
                    }}
                    padaBatal={() => setEditMassalTarget(null)}
                />
            ) : (
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
                    ...(adaKartu
                        ? [
                              {
                                  judul: 'Kuota AI',
                                  kelas: 'w-28',
                                  render: (b: Baris) => {
                                      const k = b.ai_kuota;

                                      if (!k) {
                                          return <span className="text-xs text-ink-3">—</span>;
                                      }

                                      return (
                                          <Chip nada={k.sisa <= 0 ? 'bad' : 'mute'}>
                                              {k.terpakai}/{k.batas}
                                          </Chip>
                                      );
                                  },
                              },
                          ]
                        : []),
                    {
                        judul: '',
                        // Tab guru menampilkan empat aksi (Kartu, Kuota AI, Username, Ubah); tab pengawas tiga; tab lain dua.
                        kelas: adaKartu
                            ? 'w-52 text-right'
                            : tabPengawas
                              ? 'w-44 text-right'
                              : 'w-32 text-right',
                        render: (b) => (
                            <span className="flex justify-end gap-3">
                                {adaKartu && (
                                    <>
                                        <button
                                            onClick={() => cetakKartu([b.id])}
                                            className="text-xs text-accent hover:underline"
                                            title={`Cetak kartu akun ${b.nama}`}
                                        >
                                            Kartu
                                        </button>
                                        <button
                                            onClick={() => setKuotaAiGuru(b)}
                                            className="text-xs text-accent hover:underline"
                                            title={`Perbarui atau reset kuota AI ${b.nama}`}
                                        >
                                            Kuota AI
                                        </button>
                                    </>
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
                                {tabPengawas && (
                                    <button
                                        onClick={() => hapusPengawas(b)}
                                        className="text-xs text-bad hover:underline"
                                        title={`Hapus pengawas ${b.nama}`}
                                    >
                                        Hapus
                                    </button>
                                )}
                            </span>
                        ),
                    },
                ]}
            />
            )}

            <p className="mt-3 text-[11px] text-ink-3">
                {tabPengawas
                    ? `${jumlah.toLocaleString('id-ID')} akun pengawas. Akun yang belum terkait riwayat event/presensi akan dihapus permanen; yang sudah terkait diarsipkan agar data ujian aman.`
                    : `${jumlah.toLocaleString('id-ID')} akun pada peran ini. Akun tidak pernah dihapus, hanya dinonaktifkan. Riwayat ujian dan presensi menunjuk ke sana.`}
            </p>

            {ekspor.length > 0 && <RiwayatEkspor ekspor={ekspor} />}

            <FormPengguna
                key={tambah ? 'tambah-terbuka' : 'tambah-tutup'}
                terbuka={tambah}
                tab={tab}
                padaTutup={() => setTambah(false)}
            />

            {/* Key memaksa remount agar nilai awal useForm disetel ulang saat berganti pengguna (Modal tidak melepas anaknya). */}
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

            <FormKuotaAi
                key={'kuota-ai-' + (kuotaAiGuru?.id ?? 'kosong')}
                pengguna={kuotaAiGuru}
                padaTutup={() => setKuotaAiGuru(null)}
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
                    menghafal username lama tidak bisa masuk sampai kartunya diperbarui.
                    Ia akan melapor sebagai &ldquo;username atau sandi
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
            // Kirim tab dan filter aktif agar job mengekspor subset yang sedang dilihat.
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
                            {', kata kunci '}
                            <span className="font-mono">{filter.cari}</span>
                        </>
                    ) : null}
                    {filter.status ? (
                        <>
                            {', status '}
                            <span className="font-mono">
                                {filter.status === '1' ? 'aktif' : 'nonaktif'}
                            </span>
                        </>
                    ) : null}
                    .
                </p>

                <p className="text-[11px] text-ink-3">
                    Berkasnya disiapkan di latar belakang. Unduh dari daftar Ekspor terakhir
                    begitu statusnya siap. Berkasnya bisa langsung diunggah kembali
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

/** Tautan unduh hanya ditampilkan saat status `selesai` agar tidak mengarah ke 404. */
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
                            // Penahan lebar agar chip status tetap sejajar dengan baris yang punya tombol Unduh.
                            <span className="w-[3.5rem]" />
                        )}
                    </div>
                ))}
            </div>
        </section>
    );
}

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
        // Selalu kosong saat ubah; UserRegistrar::perbarui tidak menimpa sandi jika tidak dikirim.
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

                {/* Kolom gender hanya ada pada profil siswa dan guru. */}
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

function FormKuotaAi({
    pengguna,
    padaTutup,
}: {
    pengguna: Baris | null;
    padaTutup: () => void;
}) {
    const kuota = pengguna?.ai_kuota ?? { batas: 20, terpakai: 0, sisa: 20 };

    const { data, setData, put, processing, errors } = useForm<{
        ai_limit: number;
        reset: boolean;
    }>({
        ai_limit: kuota.batas,
        reset: false,
    });

    const kirim = (e: React.FormEvent) => {
        e.preventDefault();

        if (!pengguna) {
            return;
        }

        put(`/admin/pengguna/${pengguna.id}/kuota-ai`, {
            preserveScroll: true,
            onSuccess: () => padaTutup(),
        });
    };

    return (
        <Modal
            terbuka={pengguna !== null}
            padaTutup={padaTutup}
            judul={pengguna ? `Kuota AI · ${pengguna.nama}` : 'Kuota AI'}
        >
            <form onSubmit={kirim} className="space-y-3">
                <div className="flex items-center justify-between rounded-wf border border-line bg-surface-2/60 px-3 py-2 text-xs">
                    <span className="text-ink-2">Pemakaian saat ini</span>
                    <span className="font-mono font-medium text-ink">
                        {kuota.terpakai} / {kuota.batas} soal (sisa {kuota.sisa})
                    </span>
                </div>

                <Field
                    label="Batas kuota generate AI (soal)"
                    galat={errors.ai_limit}
                    petunjuk="Bawaan setiap guru adalah 20 soal."
                >
                    <input
                        type="number"
                        min={0}
                        max={10000}
                        value={data.ai_limit}
                        onChange={(e) => setData('ai_limit', Number.parseInt(e.target.value, 10) || 0)}
                        className={kelasInput + ' font-mono'}
                    />
                </Field>

                <label className="flex cursor-pointer items-center gap-2.5 text-sm">
                    <input
                        type="checkbox"
                        checked={data.reset}
                        onChange={(e) => setData('reset', e.target.checked)}
                        className="size-4 accent-accent"
                    />
                    Reset jumlah terpakai ke 0
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

type BarisEditPengawas = {
    id: number;
    username: string;
    name: string;
    staff_code: string;
    email: string;
    phone: string;
    address: string;
    password: string;
    is_active: boolean;
};

function buatDrafPengawas(target: Baris[]): BarisEditPengawas[] {
    return target.map((b) => ({
        id: b.id,
        username: b.username,
        name: b.nama,
        staff_code: b.profil.staff_code ?? '',
        email: b.email ?? '',
        phone: b.phone ?? '',
        address: b.profil.address ?? '',
        password: '',
        is_active: b.aktif,
    }));
}

function PanelEditMassalPengawas({
    target,
    padaSelesai,
    padaBatal,
}: {
    target: Baris[];
    padaSelesai: () => void;
    padaBatal: () => void;
}) {
    const awal = buatDrafPengawas(target);
    const [draf, setDraf] = useState<BarisEditPengawas[]>(awal);
    const [proses, setProses] = useState(false);
    const [galat, setGalat] = useState<Record<string, string>>({});
    const [idTerkirim, setIdTerkirim] = useState<number[]>([]);

    const ubahKolom = <K extends keyof BarisEditPengawas>(
        id: number,
        kolom: K,
        nilai: BarisEditPengawas[K],
    ) => {
        setDraf((prev) => prev.map((r) => (r.id === id ? { ...r, [kolom]: nilai } : r)));
    };

    const berubah = (r: BarisEditPengawas) => {
        const asli = awal.find((a) => a.id === r.id);

        if (!asli) {
            return false;
        }

        return (
            r.name !== asli.name ||
            r.staff_code !== asli.staff_code ||
            r.email !== asli.email ||
            r.phone !== asli.phone ||
            r.address !== asli.address ||
            r.password !== '' ||
            r.is_active !== asli.is_active
        );
    };

    const barisBerubah = draf.filter(berubah);

    const galatUntuk = (id: number, kolom: string): string | undefined => {
        const indeks = idTerkirim.indexOf(id);

        if (indeks === -1) {
            return undefined;
        }

        return galat[`pengawas.${indeks}.${kolom}`];
    };

    const kirim = (e: React.FormEvent) => {
        e.preventDefault();

        if (barisBerubah.length === 0) {
            return;
        }

        setProses(true);
        setGalat({});
        setIdTerkirim(barisBerubah.map((r) => r.id));

        router.put(
            '/admin/pengguna/pengawas/massal',
            {
                pengawas: barisBerubah.map((r) => ({
                    id: r.id,
                    name: r.name,
                    staff_code: r.staff_code || null,
                    email: r.email || null,
                    phone: r.phone || null,
                    address: r.address || null,
                    password: r.password || null,
                    is_active: r.is_active,
                })),
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    padaSelesai();
                },
                onError: (errors) => {
                    setGalat(errors);
                },
                onFinish: () => {
                    setProses(false);
                },
            },
        );
    };

    const kelasSel =
        'w-full rounded-wf border border-line bg-surface px-2 py-1 text-xs text-ink outline-none focus:border-accent';

    return (
        <form
            onSubmit={kirim}
            className="rounded-wf border border-line bg-surface"
        >
            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-line bg-surface-2/60 px-4 py-2.5">
                <div>
                    <p className="text-sm font-medium text-ink">
                        Edit massal pengawas ({draf.length} baris)
                    </p>
                    <p className="text-[11px] text-ink-3">
                        {barisBerubah.length} baris diubah. Kosongkan password bila tidak ingin
                        mengubahnya.
                    </p>
                </div>
                <div className="flex gap-2">
                    <Tombol
                        type="submit"
                        nada="pri"
                        disabled={proses || barisBerubah.length === 0}
                    >
                        {proses
                            ? 'Menyimpan...'
                            : `Simpan perubahan (${barisBerubah.length})`}
                    </Tombol>
                    <Tombol onClick={padaBatal} disabled={proses}>
                        Batal
                    </Tombol>
                </div>
            </div>

            <div className="overflow-x-auto">
                <table className="w-full min-w-[60rem] text-xs">
                    <thead>
                        <tr className="border-b border-line bg-surface-2/40 text-left text-[11px] text-ink-3">
                            <th className="px-3 py-2 font-medium">Username</th>
                            <th className="w-32 px-2 py-2 font-medium">Kode pegawai</th>
                            <th className="min-w-[12rem] px-2 py-2 font-medium">Nama lengkap</th>
                            <th className="min-w-[11rem] px-2 py-2 font-medium">Email</th>
                            <th className="w-36 px-2 py-2 font-medium">Telepon</th>
                            <th className="min-w-[11rem] px-2 py-2 font-medium">Alamat</th>
                            <th className="w-36 px-2 py-2 font-medium">Password baru</th>
                            <th className="w-16 px-3 py-2 text-center font-medium">Aktif</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-line/60">
                        {draf.map((r) => {
                            const sudahUbah = berubah(r);

                            return (
                                <tr
                                    key={r.id}
                                    className={sudahUbah ? 'bg-accent-soft/20' : undefined}
                                >
                                    <td className="px-3 py-2 align-top font-mono text-[11px] text-ink-3">
                                        {r.username}
                                    </td>
                                    <td className="px-2 py-1.5 align-top">
                                        <input
                                            value={r.staff_code}
                                            onChange={(e) =>
                                                ubahKolom(r.id, 'staff_code', e.target.value)
                                            }
                                            placeholder="-"
                                            className={kelasSel + ' font-mono'}
                                        />
                                        {galatUntuk(r.id, 'staff_code') && (
                                            <p className="mt-0.5 text-[10px] text-bad">
                                                {galatUntuk(r.id, 'staff_code')}
                                            </p>
                                        )}
                                    </td>
                                    <td className="px-2 py-1.5 align-top">
                                        <input
                                            value={r.name}
                                            onChange={(e) =>
                                                ubahKolom(r.id, 'name', e.target.value)
                                            }
                                            required
                                            className={kelasSel}
                                        />
                                        {galatUntuk(r.id, 'name') && (
                                            <p className="mt-0.5 text-[10px] text-bad">
                                                {galatUntuk(r.id, 'name')}
                                            </p>
                                        )}
                                    </td>
                                    <td className="px-2 py-1.5 align-top">
                                        <input
                                            type="email"
                                            value={r.email}
                                            onChange={(e) =>
                                                ubahKolom(r.id, 'email', e.target.value)
                                            }
                                            placeholder="-"
                                            className={kelasSel}
                                        />
                                        {galatUntuk(r.id, 'email') && (
                                            <p className="mt-0.5 text-[10px] text-bad">
                                                {galatUntuk(r.id, 'email')}
                                            </p>
                                        )}
                                    </td>
                                    <td className="px-2 py-1.5 align-top">
                                        <input
                                            value={r.phone}
                                            onChange={(e) =>
                                                ubahKolom(r.id, 'phone', e.target.value)
                                            }
                                            placeholder="-"
                                            className={kelasSel + ' font-mono'}
                                        />
                                        {galatUntuk(r.id, 'phone') && (
                                            <p className="mt-0.5 text-[10px] text-bad">
                                                {galatUntuk(r.id, 'phone')}
                                            </p>
                                        )}
                                    </td>
                                    <td className="px-2 py-1.5 align-top">
                                        <input
                                            value={r.address}
                                            onChange={(e) =>
                                                ubahKolom(r.id, 'address', e.target.value)
                                            }
                                            placeholder="-"
                                            className={kelasSel}
                                        />
                                        {galatUntuk(r.id, 'address') && (
                                            <p className="mt-0.5 text-[10px] text-bad">
                                                {galatUntuk(r.id, 'address')}
                                            </p>
                                        )}
                                    </td>
                                    <td className="px-2 py-1.5 align-top">
                                        <input
                                            type="text"
                                            value={r.password}
                                            onChange={(e) =>
                                                ubahKolom(r.id, 'password', e.target.value)
                                            }
                                            placeholder="Tetap"
                                            autoComplete="new-password"
                                            className={kelasSel + ' font-mono'}
                                        />
                                        {galatUntuk(r.id, 'password') && (
                                            <p className="mt-0.5 text-[10px] text-bad">
                                                {galatUntuk(r.id, 'password')}
                                            </p>
                                        )}
                                    </td>
                                    <td className="px-3 py-2 text-center align-top">
                                        <input
                                            type="checkbox"
                                            checked={r.is_active}
                                            onChange={(e) =>
                                                ubahKolom(r.id, 'is_active', e.target.checked)
                                            }
                                            className="size-4 accent-accent"
                                            aria-label={`Aktifkan ${r.name}`}
                                        />
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </form>
    );
}

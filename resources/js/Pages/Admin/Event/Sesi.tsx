import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { konfirmasi, tanyaTeks } from '@/Components/dialog';
import {
    Baris,
    JadwalEvent,
    KPI,
    KandidatSusulan,
    PemilihEvent,
    RuangEvent,
    TemuanJadwal,
    Ujian,
    ValidasiItem,
    ValidasiStrip,
} from '@/Components/JadwalKomponen';
import {
    Chip,
    Field,
    Inbox,
    Kotak,
    Peringatan,
    Tombol,
    kelasInput,
    nadaStatus,
} from '@/Components/ui';

type Props = {
    event: JadwalEvent;
    ujian_aktif: Ujian;
    baris: Baris[];
    temuan: TemuanJadwal[];
    validasi: ValidasiItem[];
    ruang: RuangEvent[];
    susulan_kandidat: Record<number, KandidatSusulan[]>;
    angka: { ujian: number; sesi: number; konflik: number; belum_ditempatkan: number };
};

export default function EventSesi({
    event,
    ujian_aktif,
    baris,
    temuan,
    validasi,
    ruang,
    susulan_kandidat,
    angka,
}: Props) {
    const [panelSesi, setPanelSesi] = useState(false);
    const [panelSusulan, setPanelSusulan] = useState(false);
    const [sesiDiubah, setSesiDiubah] = useState<Baris | null>(null);
    const [susulanDiisi, setSusulanDiisi] = useState<Baris | null>(null);

    const [saringStatus, setSaringStatus] = useState('semua');
    const [cari, setCari] = useState('');
    const [terbuka, setTerbuka] = useState<number | null>(null);

    const jalur = `/admin/events/${event.id}`;
    const barisBentrok = temuan.filter((t) => t.sesi_id !== null).length;

    const tersaring = baris.filter((b) => {
        if (saringStatus !== 'semua' && b.status !== saringStatus) {
            return false;
        }
        if (cari.trim() !== '') {
            const q = cari.trim().toLowerCase();
            const teks = `${b.label} ${b.ujian} ${b.ruang_kode.join(' ')}`.toLowerCase();
            if (!teks.includes(q)) {
                return false;
            }
        }

        return true;
    });

    const grup = kelompokkan(tersaring);
    const sesiTampil = tersaring.length;
    const statusAda = [...new Set(baris.map((b) => b.status))].sort();

    return (
        <AdminLayout
            judul={`Sesi · ${ujian_aktif.mapel ?? ujian_aktif.judul}`}
            breadcrumb={`/admin/events · ${event.kode} · ${ujian_aktif.kode}`}
            aksi={
                <>
                    <PemilihEvent event={event} />

                    <Link href={`${jalur}/jadwal`}>
                        <Tombol nada="sek">Kembali ke ujian</Tombol>
                    </Link>

                    <Tombol
                        onClick={() => router.post(`${jalur}/jadwal/deteksi`, {}, { preserveScroll: true })}
                        title="Periksa ulang jadwal dan laporkan hasilnya"
                    >
                        Deteksi konflik
                    </Tombol>

                    <Tombol
                        onClick={() => setPanelSusulan(true)}
                        title="Gelombang pengganti pada ujian ini, di tanggal terpisah"
                    >
                        Tambah sesi susulan
                    </Tombol>

                    <Tombol nada="pri" onClick={() => setPanelSesi(true)}>
                        Tambah sesi
                    </Tombol>
                </>
            }
        >
            <Head title={`Sesi · ${ujian_aktif.mapel ?? ujian_aktif.judul}`} />

            <div className="mb-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                <KPI label="Sesi ujian ini" nilai={baris.length} />
                <KPI label="Durasi (menit)" nilai={ujian_aktif.durasi} />
                <KPI
                    label="Konflik jadwal"
                    nilai={angka.konflik}
                    nada={angka.konflik > 0 ? 'bad' : 'ok'}
                />
                <KPI
                    label="Belum ditempatkan"
                    nilai={angka.belum_ditempatkan}
                    nada={angka.belum_ditempatkan > 0 ? 'warn' : 'ok'}
                />
            </div>

            <ValidasiStrip validasi={validasi} adaTemuan={temuan.length > 0} />

            {temuan.length > 0 && (
                <div className="mt-2 space-y-2">
                    {temuan.slice(0, 4).map((t, i) => (
                        <div
                            key={`${t.jenis}-${i}`}
                            className="rounded-wf border border-bad/30 bg-bad-bg px-3 py-2.5 text-xs leading-relaxed text-bad"
                        >
                            {t.pesan}
                        </div>
                    ))}
                    {temuan.length > 4 && (
                        <p className="text-xs text-ink-3">
                            +{temuan.length - 4} temuan lain. Perbaiki yang di atas lebih dulu.
                        </p>
                    )}
                </div>
            )}

            {panelSesi && (
                <div className="mt-4">
                    <FormSesi
                        jalur={jalur}
                        ujianAktif={ujian_aktif}
                        ruang={ruang}
                        padaTutup={() => setPanelSesi(false)}
                    />
                </div>
            )}

            {panelSusulan && (
                <div className="mt-4">
                    <FormSesi
                        jalur={jalur}
                        ujianAktif={ujian_aktif}
                        ruang={ruang}
                        susulan
                        padaTutup={() => setPanelSusulan(false)}
                    />
                </div>
            )}

            {susulanDiisi && (
                <div className="mt-4">
                    <PanelSusulan
                        jalur={jalur}
                        baris={susulanDiisi}
                        kandidat={susulan_kandidat[susulanDiisi.ujian_id] ?? []}
                        padaTutup={() => setSusulanDiisi(null)}
                    />
                </div>
            )}

            {sesiDiubah && (
                <div className="mt-4">
                    <FormSesi
                        jalur={jalur}
                        ujianAktif={ujian_aktif}
                        ruang={ruang}
                        sesiAwal={sesiDiubah}
                        padaTutup={() => setSesiDiubah(null)}
                    />
                </div>
            )}

            {baris.length > 1 && (
                <div className="mt-4 flex flex-wrap items-center gap-2 rounded-wf border border-line bg-surface px-3 py-2">
                    <input
                        value={cari}
                        onChange={(e) => setCari(e.target.value)}
                        placeholder="Cari sesi atau ruang"
                        className="min-w-0 flex-1 rounded-wf border border-line bg-surface px-2.5 py-1.5 text-xs text-ink outline-none placeholder:text-ink-3 focus:border-accent focus:ring-2 focus:ring-accent/15 sm:max-w-xs"
                    />

                    <select
                        value={saringStatus}
                        onChange={(e) => setSaringStatus(e.target.value)}
                        className="rounded-wf border border-line bg-surface px-2.5 py-1.5 text-xs text-ink outline-none focus:border-accent"
                        aria-label="Saring menurut status"
                    >
                        <option value="semua">Semua status</option>
                        {statusAda.map((s) => (
                            <option key={s} value={s}>
                                {labelStatus(s)}
                            </option>
                        ))}
                    </select>

                    {(cari !== '' || saringStatus !== 'semua') && (
                        <button
                            type="button"
                            onClick={() => {
                                setCari('');
                                setSaringStatus('semua');
                            }}
                            className="text-xs text-ink-3 underline-offset-2 hover:text-ink hover:underline"
                        >
                            Bersihkan
                        </button>
                    )}

                    <span className="ml-auto text-[11px] text-ink-3">
                        {sesiTampil === baris.length
                            ? `${baris.length} sesi`
                            : `${sesiTampil} dari ${baris.length} sesi`}
                    </span>
                </div>
            )}

            <div className="mt-3 overflow-hidden rounded-wf border border-line bg-surface">
                {baris.length === 0 ? (
                    <p className="px-4 py-8 text-center text-sm text-ink-3">
                        Belum ada sesi untuk ujian ini. Tekan <em>Tambah sesi</em> untuk menjadwalkannya.
                    </p>
                ) : grup.length === 0 ? (
                    <p className="px-4 py-8 text-center text-sm text-ink-3">
                        Tidak ada sesi yang cocok dengan penyaring ini.
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[44rem] text-sm">
                            <thead>
                                <tr className="border-b border-line bg-surface-2/50 text-left">
                                    {['Ujian', 'Ruang', 'Peserta', 'Status', ''].map((h, i) => (
                                        <th
                                            key={h || i}
                                            className={
                                                'px-3 py-2 text-[11px] font-medium text-ink-3 ' +
                                                ['', 'w-32', 'w-20', 'w-28', 'w-16'][i]
                                            }
                                        >
                                            {h}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            {grup.map((g) => (
                                <tbody key={g.kunci}>
                                    <tr className="border-b border-line bg-surface-2/30">
                                        <td colSpan={5} className="px-3 py-1.5">
                                            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                                                {g.label && (
                                                    <span className="text-xs font-medium text-ink">
                                                        {g.label}
                                                    </span>
                                                )}
                                                <span className="font-mono text-xs font-medium text-ink">
                                                    {g.tanggal ?? '—'}
                                                </span>
                                                <span className="font-mono text-xs text-ink-2">
                                                    {g.jam ?? '—'}
                                                    {g.selesai && (
                                                        <span className="text-ink-3"> – {g.selesai}</span>
                                                    )}
                                                </span>
                                                <span className="text-[11px] text-ink-3">
                                                    {g.sesi.length} sesi · {g.peserta} peserta
                                                </span>
                                                {g.konflik > 0 && (
                                                    <Chip nada="bad">{g.konflik} bentrok</Chip>
                                                )}
                                                {!g.final && <Chip nada="mute">belum lengkap</Chip>}
                                            </div>
                                        </td>
                                    </tr>

                                    {g.sesi.map((b) => (
                                        <BarisSesi
                                            key={b.id}
                                            jalur={jalur}
                                            baris={b}
                                            terbuka={terbuka === b.id}
                                            padaBuka={() =>
                                                setTerbuka(terbuka === b.id ? null : b.id)
                                            }
                                            padaUbah={setSesiDiubah}
                                            padaSusulan={setSusulanDiisi}
                                        />
                                    ))}
                                </tbody>
                            ))}
                        </table>
                    </div>
                )}
            </div>

            {barisBentrok > 0 && (
                <p className="mt-2 text-xs text-ink-3">
                    {barisBentrok} sesi bertanda <span className="text-bad">bentrok</span>.
                    Tekan <em>Rincian</em> pada barisnya untuk melihat penyebabnya.
                </p>
            )}

            <div className="mt-3">
                <Inbox>
                    Tanggal dan jam di tabel ini adalah jendela sesinya, bukan durasi ujiannya.
                    Sesi yang selesai tepat ketika sesi berikutnya dibuka{' '}
                    <strong>tidak dihitung bentrok</strong>.
                </Inbox>
            </div>
        </AdminLayout>
    );
}

type Grup = {
    kunci: string;
    label: string | null;
    tanggal: string | null;
    jam: string | null;
    selesai: string | null;
    sesi: Baris[];
    konflik: number;
    peserta: number;
    final: boolean;
};

function kelompokkan(baris: Baris[]): Grup[] {
    const peta = new Map<string, Grup>();

    for (const b of baris) {
        const kunci = `${b.tanggal ?? '—'}|${b.jam ?? '—'}|${b.label}`;
        let g = peta.get(kunci);

        if (g === undefined) {
            g = {
                kunci,
                label: b.label,
                tanggal: b.tanggal,
                jam: b.jam,
                selesai: b.selesai,
                sesi: [],
                konflik: 0,
                peserta: 0,
                final: true,
            };
            peta.set(kunci, g);
        }

        g.sesi.push(b);
        g.konflik += b.bentrok.length > 0 ? 1 : 0;
        g.peserta += b.peserta;

        if (b.status !== 'selesai' && b.status !== 'batal') {
            g.final = false;
        }
    }

    return [...peta.values()];
}

function BarisSesi({
    jalur,
    baris,
    terbuka,
    padaBuka,
    padaUbah,
    padaSusulan,
}: {
    jalur: string;
    baris: Baris;
    terbuka: boolean;
    padaBuka: () => void;
    padaUbah: (baris: Baris) => void;
    padaSusulan: (baris: Baris) => void;
}) {
    const final = baris.status === 'selesai' || baris.status === 'batal';

    const bagianUjian = baris.ujian.split(/\s*·\s*/);
    const judulUjian = bagianUjian[0] ?? baris.ujian;
    const sasaranUjian = bagianUjian.length > 1 ? bagianUjian.slice(1).join(' · ') : null;
    const daftarKelas =
        sasaranUjian && sasaranUjian !== 'semua kelas'
            ? sasaranUjian.split(/\s*,\s*/).filter(Boolean)
            : [];
    const ringkasKelas =
        daftarKelas.length > 3
            ? `${daftarKelas.slice(0, 3).join(', ')} +${daftarKelas.length - 3} kelas`
            : sasaranUjian;

    const ringkasRuang =
        baris.ruang_kode.length > 3
            ? `${baris.ruang_kode.slice(0, 3).join(', ')} +${baris.ruang_kode.length - 3} ruang`
            : baris.ruang_kode.join(', ');

    return (
        <>
            <tr className="border-b border-line last:border-0 align-top">
                <td className="px-3 py-2">
                    <span className="text-sm text-ink">{judulUjian}</span>
                    {sasaranUjian === 'semua kelas' && (
                        <span className="ml-1.5 text-[11px] text-ink-3">semua kelas</span>
                    )}
                    {sasaranUjian && sasaranUjian !== 'semua kelas' && (
                        <span
                            className="ml-1.5 text-[11px] text-ink-3"
                            title={daftarKelas.length > 3 ? sasaranUjian : undefined}
                        >
                            &middot; {ringkasKelas}
                        </span>
                    )}
                    {baris.susulan && (
                        <span className="ml-1.5">
                            <Chip nada="info">susulan</Chip>
                        </span>
                    )}
                </td>

                <td className="px-3 py-2">
                    {baris.bentrok.length > 0 ? (
                        <span className="flex items-center gap-2">
                            <Chip nada="bad">bentrok</Chip>
                            <span className="font-mono text-[11px] text-ink-3">
                                {baris.ruang} ruang
                            </span>
                        </span>
                    ) : (
                        <span
                            className="font-mono text-xs text-ink-2"
                            title={
                                baris.ruang_kode.length > 3
                                    ? baris.ruang_kode.join(', ')
                                    : undefined
                            }
                        >
                            {baris.ruang_kode.length > 0 ? ringkasRuang : '—'}
                        </span>
                    )}
                </td>

                <td className="px-3 py-2">
                    <span className="font-mono text-xs text-ink-2">{baris.peserta}</span>
                </td>

                <td className="px-3 py-2">
                    <span
                        title={
                            baris.alasan_batal ??
                            (baris.status === 'batal' ? 'Dibatalkan tanpa keterangan.' : undefined)
                        }
                    >
                        <Chip nada={nadaStatus(baris.status)}>{baris.status_label}</Chip>
                    </span>
                </td>

                <td className="px-3 py-2">
                    <div className="flex justify-end gap-1.5">
                        <LangkahBerikut jalur={jalur} baris={baris} />
                        <AksiLainnya
                            jalur={jalur}
                            baris={baris}
                            final={final}
                            terbuka={terbuka}
                            padaBuka={padaBuka}
                            padaUbah={padaUbah}
                            padaSusulan={padaSusulan}
                        />
                    </div>
                </td>
            </tr>

            {terbuka && (
                <tr className="border-b border-line bg-surface-2/20 last:border-0">
                    <td colSpan={5} className="px-3 py-2">
                        <div className="space-y-1 text-xs leading-relaxed">
                            {baris.bentrok.length > 0 ? (
                                baris.bentrok.map((t, i) => (
                                    <p key={i} className="text-bad">
                                        <span className="font-medium">{t.jenis}</span> {t.pesan}
                                    </p>
                                ))
                            ) : (
                                <p className="text-ok">Tidak ada konflik pada sesi ini.</p>
                            )}

                            {baris.alasan_batal && (
                                <p className="text-ink-2">
                                    <span className="font-medium">Dibatalkan:</span>{' '}
                                    {baris.alasan_batal}
                                    {baris.dibatalkan_pada && (
                                        <span className="text-ink-3">
                                            {' '}
                                            · {baris.dibatalkan_pada.slice(0, 16).replace('T', ' ')}
                                        </span>
                                    )}
                                </p>
                            )}

                            <p className="text-ink-3">
                                Ruang:{' '}
                                {baris.ruang_kode.length > 0 ? baris.ruang_kode.join(', ') : 'belum ada'}
                                {' · '}Peserta: {baris.peserta}
                            </p>
                        </div>
                    </td>
                </tr>
            )}
        </>
    );
}

function LangkahBerikut({ jalur, baris }: { jalur: string; baris: Baris }) {
    const kirim = (aksi: string) =>
        router.post(`${jalur}/ujian/${baris.ujian_id}/sesi/${baris.id}/${aksi}`, {}, { preserveScroll: true });

    if (baris.status === 'draft') {
        return (
            <Tombol nada="sek" onClick={() => kirim('siapkan')}>
                Siapkan
            </Tombol>
        );
    }

    if (baris.status === 'siap') {
        return (
            <Tombol nada="pri" onClick={() => kirim('buka')}>
                Buka
            </Tombol>
        );
    }

    if (baris.status === 'berjalan') {
        return (
            <Tombol
                nada="bahaya"
                onClick={async () => {
                    const setuju = await konfirmasi({
                        judul: `Tutup ${baris.label}?`,
                        pesan: 'Siswa yang masih mengerjakan akan diselesaikan otomatis.',
                        labelYa: 'Tutup sesi',
                        nada: 'bahaya',
                    });

                    if (setuju) {
                        kirim('tutup');
                    }
                }}
            >
                Tutup
            </Tombol>
        );
    }

    return null;
}

function AksiLainnya({
    jalur,
    baris,
    final,
    terbuka,
    padaBuka,
    padaUbah,
    padaSusulan,
}: {
    jalur: string;
    baris: Baris;
    final: boolean;
    terbuka: boolean;
    padaBuka: () => void;
    padaUbah: (baris: Baris) => void;
    padaSusulan: (baris: Baris) => void;
}) {
    const [menuTerbuka, setMenuTerbuka] = useState(false);
    const [posisi, setPosisi] = useState<{
        right: number;
        top?: number;
        bottom?: number;
    }>({ right: 0, top: 0 });
    const tombolRef = useRef<HTMLButtonElement>(null);
    const menuRef = useRef<HTMLDivElement>(null);

    const bukaMenu = () => {
        if (menuTerbuka) {
            setMenuTerbuka(false);

            return;
        }

        const rect = tombolRef.current?.getBoundingClientRect();

        if (rect) {
            const ruangBawah = window.innerHeight - rect.bottom;
            const keAtas = ruangBawah < 190 && rect.top > 190;

            setPosisi({
                right: Math.max(8, window.innerWidth - rect.right),
                ...(keAtas
                    ? { bottom: window.innerHeight - rect.top + 4 }
                    : { top: rect.bottom + 4 }),
            });
        }

        setMenuTerbuka(true);
    };

    useEffect(() => {
        if (!menuTerbuka) {
            return;
        }

        const tutupKlik = (e: MouseEvent) => {
            const target = e.target as Node;

            if (
                !menuRef.current?.contains(target) &&
                !tombolRef.current?.contains(target)
            ) {
                setMenuTerbuka(false);
            }
        };

        const tutupEsc = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                setMenuTerbuka(false);
            }
        };

        const tutupGulir = () => setMenuTerbuka(false);

        document.addEventListener('mousedown', tutupKlik);
        document.addEventListener('keydown', tutupEsc);
        window.addEventListener('scroll', tutupGulir, true);

        return () => {
            document.removeEventListener('mousedown', tutupKlik);
            document.removeEventListener('keydown', tutupEsc);
            window.removeEventListener('scroll', tutupGulir, true);
        };
    }, [menuTerbuka]);

    return (
        <div className="relative">
            <button
                ref={tombolRef}
                type="button"
                onClick={bukaMenu}
                aria-expanded={menuTerbuka}
                aria-haspopup="menu"
                className="grid size-8 cursor-pointer place-items-center rounded-wf border border-line text-xs text-ink-2 hover:bg-surface-2 hover:text-ink"
                title="Tindakan lain"
                aria-label="Tindakan lain"
            >
                ⋯
            </button>

            {menuTerbuka && (
                <div
                    ref={menuRef}
                    role="menu"
                    style={posisi}
                    className="fixed z-50 w-44 overflow-hidden rounded-wf border border-line bg-surface shadow-wf"
                >
                    <button
                        type="button"
                        role="menuitem"
                        onClick={() => {
                            setMenuTerbuka(false);
                            padaBuka();
                        }}
                        className="block w-full px-3 py-2 text-left text-xs text-ink-2 hover:bg-surface-2 hover:text-ink"
                    >
                        {terbuka ? 'Tutup rincian' : 'Rincian'}
                    </button>

                    <button
                        type="button"
                        role="menuitem"
                        disabled={final}
                        title={
                            final
                                ? 'Sesi yang sudah selesai atau batal tidak bisa diubah lagi.'
                                : undefined
                        }
                        onClick={() => {
                            setMenuTerbuka(false);
                            padaUbah(baris);
                        }}
                        className="block w-full px-3 py-2 text-left text-xs text-ink-2 hover:bg-surface-2 hover:text-ink disabled:cursor-not-allowed disabled:text-ink-3/50"
                    >
                        Ubah jendela &amp; ruang
                    </button>

                    {baris.susulan && (
                        <button
                            type="button"
                            role="menuitem"
                            disabled={final}
                            onClick={() => {
                                setMenuTerbuka(false);
                                padaSusulan(baris);
                            }}
                            className="block w-full px-3 py-2 text-left text-xs text-ink-2 hover:bg-surface-2 hover:text-ink disabled:cursor-not-allowed disabled:text-ink-3/50"
                        >
                            Daftarkan peserta susulan
                        </button>
                    )}

                    {!final && (
                        <button
                            type="button"
                            role="menuitem"
                            onClick={async () => {
                                setMenuTerbuka(false);

                                const alasan = await tanyaTeks({
                                    judul: `Batalkan ${baris.label}?`,
                                    pesan: 'Token sesi ini dicabut, dan sesi tidak dihitung lagi di jadwal.',
                                    label: 'Alasan pembatalan (boleh dikosongkan)',
                                    petunjuk:
                                        'Alasan ini dibaca Admin berikutnya yang menyusun ulang jadwal.',
                                    wajib: false,
                                    maksimal: 200,
                                    labelYa: 'Batalkan sesi',
                                    labelTidak: 'Jangan',
                                });

                                if (alasan === null) {
                                    return;
                                }

                                router.post(
                                    `${jalur}/ujian/${baris.ujian_id}/sesi/${baris.id}/batal`,
                                    { alasan },
                                    { preserveScroll: true },
                                );
                            }}
                            className="block w-full px-3 py-2 text-left text-xs text-ink-2 hover:bg-surface-2 hover:text-ink"
                        >
                            Batalkan sesi
                        </button>
                    )}

                    <button
                        type="button"
                        role="menuitem"
                        onClick={async () => {
                            setMenuTerbuka(false);

                            const setuju = await konfirmasi({
                                judul: `Hapus ${baris.label}?`,
                                pesan: 'Pesertanya dilepas dari ruang ini dan harus ditempatkan ulang. Berita acara yang sudah dikirim atau insiden yang tercatat akan menolak penghapusan.',
                                labelYa: 'Hapus sesi',
                                nada: 'bahaya',
                            });

                            if (!setuju) {
                                return;
                            }

                            router.delete(`${jalur}/ujian/${baris.ujian_id}/sesi/${baris.id}`, {
                                preserveScroll: true,
                            });
                        }}
                        className="block w-full border-t border-line px-3 py-2 text-left text-xs text-bad hover:bg-bad-bg"
                    >
                        Hapus sesi
                    </button>
                </div>
            )}
        </div>
    );
}

function PanelSusulan({
    jalur,
    baris,
    kandidat,
    padaTutup,
}: {
    jalur: string;
    baris: Baris;
    kandidat: KandidatSusulan[];
    padaTutup: () => void;
}) {
    const [pilih, setPilih] = useState<number[]>([]);
    const [alasan, setAlasan] = useState('');
    const [galat, setGalat] = useState<string | null>(null);

    const simpan = () => {
        setGalat(null);

        router.post(
            `${jalur}/ujian/${baris.ujian_id}/sesi/${baris.id}/pindah-susulan`,
            { peserta: pilih, alasan },
            {
                preserveScroll: true,
                onSuccess: () => padaTutup(),
                onError: (e: Record<string, string>) =>
                    setGalat(Object.values(e)[0] ?? 'Peserta tidak bisa dipindahkan.'),
            },
        );
    };

    return (
        <Kotak
            judul={`Daftarkan peserta susulan · ${baris.label}`}
            aksi={
                <>
                    <Tombol nada="sek" onClick={padaTutup}>
                        Batal
                    </Tombol>
                    <Tombol
                        nada="pri"
                        disabled={pilih.length === 0 || alasan.trim() === ''}
                        title={
                            alasan.trim() === ''
                                ? 'Alasan wajib: pemindahan mereset presensi yang sudah diverifikasi.'
                                : undefined
                        }
                        onClick={simpan}
                    >
                        Pindahkan {pilih.length} peserta
                    </Tombol>
                </>
            }
        >
            {galat && (
                <div className="mb-3">
                    <Peringatan>{galat}</Peringatan>
                </div>
            )}

            {kandidat.length === 0 ? (
                <p className="text-sm text-ink-3">
                    Tidak ada siswa yang ditandai tidak hadir atau izin di sesi utama ujian ini.
                    Sesi susulan hanya untuk siswa yang sudah dipresensi alpa di sesi utama.
                </p>
            ) : (
                <>
                    <p className="mb-2 text-xs text-ink-2">
                        Siswa yang tidak hadir di sesi utama. Memindahkan mereka mengoreksi
                        presensinya ke <strong>pending</strong> (tercatat di log), dan nilai yang
                        sudah ada tidak berubah.
                    </p>
                    <div className="overflow-hidden rounded-wf border border-line">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-line bg-surface-2/50 text-left text-[11px] text-ink-3">
                                    <th className="w-8 px-3 py-2"></th>
                                    <th className="px-3 py-2">NIS</th>
                                    <th className="px-3 py-2">Nama</th>
                                    <th className="px-3 py-2">Kelas</th>
                                    <th className="px-3 py-2">Presensi sesi utama</th>
                                </tr>
                            </thead>
                            <tbody>
                                {kandidat.map((k) => (
                                    <tr key={k.peserta_id} className="border-b border-line last:border-0">
                                        <td className="px-3 py-2">
                                            <input
                                                type="checkbox"
                                                checked={pilih.includes(k.peserta_id)}
                                                onChange={(e) =>
                                                    setPilih(
                                                        e.target.checked
                                                            ? [...pilih, k.peserta_id]
                                                            : pilih.filter((x) => x !== k.peserta_id),
                                                    )
                                                }
                                            />
                                        </td>
                                        <td className="px-3 py-2 font-mono text-xs text-ink-2">{k.nis ?? '—'}</td>
                                        <td className="px-3 py-2 text-ink">{k.nama}</td>
                                        <td className="px-3 py-2 text-xs text-ink-2">{k.kelas ?? '—'}</td>
                                        <td className="px-3 py-2">
                                            <Chip nada="warn">{k.status_label}</Chip>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="mt-3">
                        <Field label="Alasan pemindahan" petunjuk="Masuk ke log presensi (wajib)">
                            <input
                                value={alasan}
                                onChange={(e) => setAlasan(e.target.value)}
                                placeholder="mis. Sakit, mengikuti lomba"
                                maxLength={200}
                                className={kelasInput}
                            />
                        </Field>
                    </div>
                </>
            )}
        </Kotak>
    );
}

function FormSesi({
    jalur,
    ujianAktif,
    ruang,
    sesiAwal,
    susulan = false,
    padaTutup,
}: {
    jalur: string;
    ujianAktif: Ujian;
    ruang: RuangEvent[];
    sesiAwal?: Baris;
    susulan?: boolean;
    padaTutup: () => void;
}) {
    const mengubah = sesiAwal !== undefined;

    const [buka, setBuka] = useState(sesiAwal?.buka_iso ?? '');
    const [tutup, setTutup] = useState(sesiAwal?.tutup_iso ?? '');
    const [akhir, setAkhir] = useState(sesiAwal?.akhir_iso ?? '');
    const [pilihRuang, setPilihRuang] = useState<number[]>(() => {
        if (sesiAwal !== undefined) {
            return sesiAwal.ruang_id;
        }

        return susulan ? (ruang[0] ? [ruang[0].id] : []) : ruang.map((r) => r.id);
    });
    const [galat, setGalat] = useState<string | null>(null);

    const simpan = () => {
        setGalat(null);

        const isi = {
            access_opens_at: buka,
            access_closes_at: tutup,
            hard_end_at: akhir,
            event_room_id: pilihRuang,
        };

        const opsi = {
            preserveScroll: true,
            onSuccess: () => padaTutup(),
            onError: (e: Record<string, string>) =>
                setGalat(Object.values(e)[0] ?? 'Sesi tidak bisa disimpan.'),
        };

        if (mengubah) {
            router.put(`${jalur}/ujian/${ujianAktif.id}/sesi/${sesiAwal.id}`, isi, opsi);

            return;
        }

        if (susulan) {
            router.post(`${jalur}/ujian/${ujianAktif.id}/sesi/susulan`, isi, opsi);

            return;
        }

        router.post(`${jalur}/ujian/${ujianAktif.id}/sesi`, isi, opsi);
    };

    return (
        <Kotak
            judul={mengubah ? `Ubah ${sesiAwal.label}` : susulan ? 'Tambah sesi susulan' : 'Tambah sesi'}
            aksi={
                <>
                    <Tombol nada="sek" onClick={padaTutup}>
                        Batal
                    </Tombol>
                    <Tombol
                        nada="pri"
                        disabled={!buka || !tutup || !akhir}
                        onClick={simpan}
                    >
                        {mengubah ? 'Simpan perubahan' : 'Simpan sesi'}
                    </Tombol>
                </>
            }
        >
            {galat && (
                <div className="mb-3">
                    <Peringatan>{galat}</Peringatan>
                </div>
            )}

            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Field label="Ujian">
                    <p className="rounded-wf border border-line bg-surface-2/60 px-2.5 py-1.5 text-sm text-ink">
                        <span className="font-mono text-xs text-ink-3">{ujianAktif.kode}</span>{' '}
                        {ujianAktif.mapel ?? ujianAktif.judul}
                    </p>
                </Field>

                <Field label="Akses dibuka" petunjuk="Kapan siswa boleh masuk">
                    <input
                        type="datetime-local"
                        value={buka}
                        onChange={(e) => setBuka(e.target.value)}
                        className={kelasInput}
                    />
                </Field>

                <Field label="Batas mulai" petunjuk="Orang terakhir boleh MULAI">
                    <input
                        type="datetime-local"
                        value={tutup}
                        onChange={(e) => setTutup(e.target.value)}
                        className={kelasInput}
                    />
                </Field>

                <Field label="Batas akhir mutlak" petunjuk="Ujian berhenti, tanpa kecuali">
                    <input
                        type="datetime-local"
                        value={akhir}
                        onChange={(e) => setAkhir(e.target.value)}
                        className={kelasInput}
                    />
                </Field>
            </div>

            <div className="mt-4">
                <p className="mb-2 text-xs font-medium text-ink-2">Ruang</p>
                <div className="flex flex-wrap gap-3">
                    {ruang.map((r) => (
                        <label key={r.id} className="flex items-center gap-1.5 text-sm">
                            <input
                                type="checkbox"
                                checked={pilihRuang.includes(r.id)}
                                onChange={(e) =>
                                    setPilihRuang(
                                        e.target.checked
                                            ? [...pilihRuang, r.id]
                                            : pilihRuang.filter((x) => x !== r.id),
                                    )
                                }
                            />
                            <span className="font-mono text-xs text-ink-2">{r.kode}</span>
                            <span className="text-ink">{r.nama}</span>
                            <span className="font-mono text-[11px] text-ink-3">
                                {r.kapasitas}
                            </span>
                            {r.status !== 'aktif' && <Chip nada="warn">{r.status}</Chip>}
                        </label>
                    ))}

                    {ruang.length === 0 && (
                        <p className="text-xs text-ink-3">
                            Belum ada ruang dialokasikan ke event ini.
                        </p>
                    )}
                </div>
            </div>
        </Kotak>
    );
}

function labelStatus(status: string): string {
    return (
        {
            draft: 'Draft',
            siap: 'Siap',
            berjalan: 'Berjalan',
            selesai: 'Selesai',
            batal: 'Batal',
        }[status] ?? status
    );
}

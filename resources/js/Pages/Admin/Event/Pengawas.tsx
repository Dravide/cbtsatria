import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { konfirmasi } from '@/Components/dialog';
import AdminLayout from '@/Layouts/AdminLayout';
import {
    Chip,
    Inbox,
    Kotak,
    Modal,
    Peringatan,
    Tombol,
    TombolTautan,
    kelasInput,
} from '@/Components/ui';

/**
 * Pengawas & penempatan ruang - wireframe plate 17, PRD §9.6.
 *
 * ---------------------------------------------------------------------
 * Panitia berjabatan pengawas BUKAN penugasan
 * ---------------------------------------------------------------------
 * Plate 13 mencatat siapa saja pengawasnya di tingkat event - satu daftar
 * nama. Laman ini mencatat siapa yang berdiri di R-02 pada sesi 1. Plate 17
 * menyatakan mengapa pemisahan itu menentukan:
 *
 *   "penugasan inilah yang memberi Pengawas hak melihat dan memverifikasi
 *    presensi di ruang itu. Tanpa penugasan, ia tidak melihat apa pun."
 *
 * ---------------------------------------------------------------------
 * Laman ini SUDAH TIDAK menugaskan; ia meringkas dan menautkan
 * ---------------------------------------------------------------------
 * Pengawas menempel pada RUANG-SESI, dan tiap sesi punya pengawasnya sendiri.
 * Pada event dengan tiga puluh tiga ruang dan dua belas sesi, merender seluruh
 * baris di sini menghasilkan ratusan baris - dan yang tenggelam adalah daftar
 * SESINYA sendiri, padahal itulah yang dipindai Admin lebih dulu: "sesi mana
 * yang belum berpengawas".
 *
 * Karena itu laman ini menampilkan satu kartu per sesi, dan pekerjaan
 * menugaskan terjadi di laman sesinya (`/pengawas/sesi/{id}`). Tombol yang
 * seluruh aksinya ada di laman lain tidak ditampilkan di sini: tombol yang
 * mengalihkan halaman tanpa menyebutnya mengajari Admin untuk tidak
 * memercayai layarnya.
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
    kelas?: string[];
    sesi: string | null;
    sesi_id: number | null;
    ujian: string | null;
    jam: string | null;
    selesai?: string | null;
    tanggal: string | null;
    hari_tanggal?: string | null;
    pengawas: PengawasBaris[];
    peserta: number;
};

type SesiKelompok = {
    id: number;
    label: string;
    ujian: string | null;
    tanggal: string | null;
    hari_tanggal?: string | null;
    jam: string | null;
    selesai: string | null;
    status: string;
    status_label: string;
    jenis: string;
    susulan: boolean;
    ruang: {
        id: number;
        ruang: string | null;
        ruang_nama: string | null;
        kapasitas: number;
        kelas?: string[];
        peserta: number;
        pengawas: PengawasBaris[];
    }[];
    jumlah_ruang: number;
    jumlah_pengawas: number;
    ruang_tanpa_pengawas: string[];
};

type AturanPengawas = {
    maks_per_event: number;
    maks_per_hari: number;
    target_per_ruang: number;
    prioritas: 'merata' | 'urutan';
    hormati_pengecualian: boolean;
    hindari_wali_kelas: boolean;
    hindari_guru_mapel: boolean;
    tolak_melebihi_kuota: boolean;
};

type MatriksBaris = {
    id: number;
    nama: string;
    username: string;
    peran: string;
    terpakai: number;
    maks: number;
    maks_khusus: number | null;
    dikecualikan_sesi: number[];
    alasan_sesi: Record<string, string>;
    penugasan: {
        sesi_id: number | null;
        sesi_label: string;
        ujian: string | null;
        tanggal: string | null;
        jam: string | null;
        ruang: string;
    }[];
    status_kuota: 'aman' | 'penuh' | 'melebihi';
};

type SesiKolom = {
    id: number;
    label: string;
    ujian: string | null;
    tanggal: string | null;
    tanggal_iso: string | null;
    jam: string | null;
};

type PengecualianItem = {
    id: number;
    supervisor_id: number;
    nama: string;
    username: string | null;
    exam_session_id: number;
    sesi_label: string;
    ujian: string | null;
    hari_tanggal: string | null;
    jam: string | null;
    reason: string;
};

type Props = {
    event: { id: number; kode: string; nama: string; status: string; status_label: string };
    baris: Baris[];
    per_sesi: SesiKelompok[];
    angka: {
        ruang: number;
        ditugaskan: number;
        tanpa_pengawas: number;
        saran_per_ruang: number;
        minimal_per_ruang: number;
        melebihi_batas?: number;
    };
    calon: { id: number; nama: string; username: string; peran: string }[];
    aturan?: AturanPengawas;
    matriks?: MatriksBaris[];
    daftar_pengecualian?: PengecualianItem[];
    sesi_kolom?: SesiKolom[];
    beban_pengawas?: Record<number, { terpakai: number; maks: number }>;
    sesi_tanpa_ruang: { id: number; label: string; ujian: string | null }[];
    /** Alamat laman sesi - dikirim server supaya bentuk rutenya satu tempat. */
    jalur_sesi: string;
};

export default function EventPengawas({
    event,
    baris,
    per_sesi,
    angka,
    calon,
    aturan,
    matriks = [],
    daftar_pengecualian = [],
    sesi_kolom = [],
    beban_pengawas = {},
    sesi_tanpa_ruang,
    jalur_sesi,
}: Props) {
    const jalur = `/admin/events/${event.id}`;
    const tabAwal = (() => {
        if (typeof window === 'undefined') {
            return 'jadwal';
        }
        const param = new URLSearchParams(window.location.search).get('tab');
        return param === 'ketersediaan' || param === 'rekap' ? param : 'jadwal';
    })();
    const [tab, setTab] = useState<'jadwal' | 'ketersediaan' | 'rekap'>(tabAwal);
    const [modalPengecualian, setModalPengecualian] = useState(false);
    const [modalOtomatis, setModalOtomatis] = useState(false);
    const [prosesOtomatis, setProsesOtomatis] = useState<'cepat' | 'expert' | null>(null);

    const jalankanOtomatis = (mode: 'cepat' | 'expert') => {
        setProsesOtomatis(mode);
        router.post(
            `${jalur}/pengawas/otomatis`,
            { mode },
            {
                preserveScroll: true,
                onSuccess: () => setModalOtomatis(false),
                onFinish: () => setProsesOtomatis(null),
            },
        );
    };

    const resetPengawasan = async () => {
        const setuju = await konfirmasi({
            judul: 'Reset seluruh penugasan pengawas?',
            pesan: `Seluruh ${angka.ditugaskan} penugasan pengawas pada event ini akan dihapus. Aturan penjadwalan dan daftar pengecualian tetap tersimpan.`,
            labelYa: 'Ya, reset pengawasan',
            nada: 'bahaya',
        });

        if (!setuju) {
            return;
        }

        router.post(`${jalur}/pengawas/reset`, {}, { preserveScroll: true });
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

                    <TombolTautan
                        href={`${jalur}/pengawas/pdf`}
                        title="Unduh jadwal pengawasan sebagai PDF"
                    >
                        Unduh PDF
                    </TombolTautan>

                    <Tombol
                        onClick={() => setModalPengecualian(true)}
                        title="Lihat, ubah, atau hapus daftar pengecualian pengawas"
                    >
                        Pengecualian ({daftar_pengecualian.length})
                    </Tombol>

                    <TombolTautanKeLamanSesi per_sesi={per_sesi} jalur_sesi={jalur_sesi} />

                    <Tombol
                        disabled={baris.length === 0}
                        title={
                            baris.length === 0
                                ? 'Belum ada sesi ber-ruang. Susun jadwalnya terlebih dahulu.'
                                : 'Pilih Mode Cepat atau Mode Expert untuk menugaskan pengawas otomatis'
                        }
                        onClick={() => setModalOtomatis(true)}
                    >
                        Tugaskan otomatis
                    </Tombol>

                    <Tombol
                        nada="bahaya"
                        disabled={angka.ditugaskan === 0}
                        title={
                            angka.ditugaskan === 0
                                ? 'Belum ada penugasan pengawas untuk direset.'
                                : 'Hapus seluruh penugasan pengawas pada event ini'
                        }
                        onClick={resetPengawasan}
                    >
                        Reset pengawasan
                    </Tombol>
                </>
            }
        >
            <Head title={`Pengawas · ${event.nama}`} />

            <div className="mb-3 grid gap-3 sm:grid-cols-4">
                <KPI label="Ruang" nilai={angka.ruang} catatan="seluruh sesi" />
                <KPI
                    label="Pengawas ditugaskan"
                    nilai={angka.ditugaskan}
                    nada="ok"
                    catatan={`${aturan?.target_per_ruang ?? angka.minimal_per_ruang} pengawas per ruang`}
                />
                <KPI
                    label="Ruang tanpa pengawas"
                    nilai={angka.tanpa_pengawas}
                    nada={angka.tanpa_pengawas > 0 ? 'bad' : 'ok'}
                />
                <KPI
                    label="Melebihi batas"
                    nilai={angka.melebihi_batas ?? 0}
                    nada={(angka.melebihi_batas ?? 0) > 0 ? 'warn' : 'ok'}
                    catatan={aturan && aturan.maks_per_event > 0 ? `batas ${aturan.maks_per_event}×/event` : 'tanpa batas'}
                />
            </div>

            <div className="mb-4 flex flex-wrap gap-1 border-b border-line">
                {(
                    [
                        ['jadwal', 'Jadwal & Aturan'],
                        ['ketersediaan', `Ketersediaan & Pengecualian (${matriks.length})`],
                        ['rekap', 'Rekap Beban Mengawas'],
                    ] as const
                ).map(([kunci, label]) => (
                    <button
                        key={kunci}
                        type="button"
                        onClick={() => setTab(kunci)}
                        className={
                            'border-b-2 px-3 py-2 text-xs font-medium transition-colors ' +
                            (tab === kunci
                                ? 'border-accent text-accent'
                                : 'border-transparent text-ink-3 hover:text-ink')
                        }
                    >
                        {label}
                    </button>
                ))}
            </div>

            {calon.length === 0 && baris.length > 0 && (
                <div className="mb-3">
                    <Peringatan>
                        Belum ada panitia berjabatan <strong>pengawas</strong> yang punya akun.
                        Tambahkan orangnya di laman <strong>Kepanitiaan</strong>. Penugasan
                        hanya bisa diberikan kepada akun yang sudah ada.
                    </Peringatan>
                </div>
            )}

            {sesi_tanpa_ruang.length > 0 && (
                <div className="mb-3">
                    <Peringatan>
                        {sesi_tanpa_ruang.length} sesi belum punya ruang:{' '}
                        {sesi_tanpa_ruang.map((s) => `${s.label} (${s.ujian ?? '—'})`).join(', ')}.
                        Penugasan menempel pada ruang-sesi, sehingga sesi tanpa ruang tidak bisa
                        ditugaskan, tetapkan ruangnya di laman{' '}
                        <strong>Jadwal &amp; sesi</strong>.
                    </Peringatan>
                </div>
            )}

            {tab === 'jadwal' && (
                <>
                    {aturan && <PanelAturan jalur={jalur} aturan={aturan} />}

                    {per_sesi.length === 0 ? (
                        <Kotak>
                            <p className="text-sm text-ink-3">
                                Belum ada ruang yang dipakai sesi mana pun. Susun jadwalnya terlebih
                                dahulu di laman <strong>Jadwal &amp; sesi</strong>.
                            </p>
                        </Kotak>
                    ) : (
                        <div className="space-y-2">
                            {per_sesi.map((s) => (
                                <KartuSesi key={s.id} sesi={s} jalur_sesi={jalur_sesi} />
                            ))}
                        </div>
                    )}

                    {baris.length > 0 && (
                        <JadwalPengawasan
                            baris={baris}
                            per_sesi={per_sesi}
                            jalur_sesi={jalur_sesi}
                            beban_pengawas={beban_pengawas}
                        />
                    )}

                    {per_sesi.some((s) => s.jumlah_pengawas > 0) && (
                        <div className="mt-3">
                            <Inbox>
                                Satu pengawas per ruang sudah sah, sesinya tetap bisa dibuka.
                                Menambah pengawas kedua sebaiknya dilakukan pada sesi yang pengawasnya
                                cukup, karena ruang yang benar-benar tidak akan bisa dibuka siswanya
                                hanyalah ruang yang belum punya pengawas sama sekali.
                            </Inbox>
                        </div>
                    )}
                </>
            )}

            {tab === 'ketersediaan' && (
                <MatriksKetersediaan
                    jalur={jalur}
                    matriks={matriks}
                    sesi_kolom={sesi_kolom}
                />
            )}

            {tab === 'rekap' && <RekapBeban matriks={matriks} />}

            <ModalPengecualian
                terbuka={modalPengecualian}
                jalur={jalur}
                daftar={daftar_pengecualian}
                sesi_kolom={sesi_kolom}
                padaTutup={() => setModalPengecualian(false)}
            />

            <Modal
                judul="Tugaskan pengawas otomatis"
                terbuka={modalOtomatis}
                padaTutup={() => {
                    if (!prosesOtomatis) {
                        setModalOtomatis(false);
                    }
                }}
            >
                <div className="space-y-3">
                    {prosesOtomatis ? (
                        <div className="rounded-wf border border-line bg-surface-2/50 px-4 py-6 text-center">
                            <div className="mx-auto mb-3 size-6 animate-spin rounded-full border-2 border-accent border-t-transparent" />
                            <p className="text-sm font-medium text-ink">
                                {prosesOtomatis === 'expert'
                                    ? 'Menyusun jadwal optimal (Mode Expert)...'
                                    : 'Menugaskan pengawas (Mode Cepat)...'}
                            </p>
                            <p className="mt-1 text-xs text-ink-3">
                                {prosesOtomatis === 'expert'
                                    ? 'Mencari kombinasi terbaik dengan MRV, swap berantai, dan multi-iterasi agar seluruh slot terpenuhi.'
                                    : 'Membagikan pengawas ke ruang yang tersedia.'}
                            </p>
                        </div>
                    ) : (
                        <>
                            <p className="text-xs text-ink-2">
                                Pilih mode penjadwalan otomatis yang sesuai dengan tingkat kepadatan
                                kuota dan pengecualian event ini.
                            </p>

                            <div className="grid gap-2.5 sm:grid-cols-2">
                                <button
                                    type="button"
                                    onClick={() => jalankanOtomatis('cepat')}
                                    className="rounded-wf border border-line bg-surface p-3.5 text-left transition-colors hover:border-accent hover:bg-accent-soft/20"
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="text-sm font-semibold text-ink">
                                            Mode Cepat
                                        </span>
                                        <Chip nada="mute">Instan</Chip>
                                    </div>
                                    <p className="mt-1.5 text-xs leading-relaxed text-ink-2">
                                        Penugasan satu lintasan berurutan per ruang. Cepat untuk
                                        jadwal longgar dengan banyak pengawas tersedia.
                                    </p>
                                </button>

                                <button
                                    type="button"
                                    onClick={() => jalankanOtomatis('expert')}
                                    className="rounded-wf border border-accent/60 bg-accent-soft/15 p-3.5 text-left transition-colors hover:border-accent hover:bg-accent-soft/30"
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="text-sm font-semibold text-ink">
                                            Mode Expert
                                        </span>
                                        <Chip nada="info">Optimal</Chip>
                                    </div>
                                    <p className="mt-1.5 text-xs leading-relaxed text-ink-2">
                                        Pencarian mendalam (MRV, swap berantai, multi-iterasi) untuk
                                        memaksimalkan keterpenuhan ruang saat kuota &amp;
                                        pengecualian ketat. Proses sedikit lebih lama.
                                    </p>
                                </button>
                            </div>

                            <div className="flex justify-end pt-1">
                                <Tombol onClick={() => setModalOtomatis(false)}>Batal</Tombol>
                            </div>
                        </>
                    )}
                </div>
            </Modal>
        </AdminLayout>
    );
}

/* ---------------------------------------------------------- Kartu sesi */

function KartuSesi({ sesi, jalur_sesi }: { sesi: SesiKelompok; jalur_sesi: string }) {
    const kurang = sesi.ruang_tanpa_pengawas.length;
    const href = `${jalur_sesi}/${sesi.id}`;

    return (
        <Link
            href={href}
            className="block rounded-wf border border-line bg-surface px-3 py-2.5 transition-colors hover:border-ink-3/40 hover:bg-surface-2/40"
        >
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                <span className="font-mono text-sm font-medium text-ink">{sesi.label}</span>
                <span className="text-sm text-ink">{sesi.ujian ?? '—'}</span>
                {sesi.susulan && <Chip nada="info">susulan</Chip>}

                <span className="font-mono text-[11px] text-ink-3">
                    {sesi.tanggal ?? '—'} · {sesi.jam ?? '—'}
                    {sesi.selesai ? `–${sesi.selesai}` : ''}
                </span>

                <span className="ml-auto flex items-center gap-2">
                    <span className="font-mono text-[11px] text-ink-3">
                        {sesi.jumlah_ruang} ruang · {sesi.jumlah_pengawas} pengawas
                    </span>

                    {/* Ruang tanpa pengawas MENGHALANGI sesi dibuka - angka yang
                        sama dipakai `SesiService::mulai()` untuk menolak. */}
                    {kurang > 0 ? (
                        <Chip nada="bad">{kurang} ruang tanpa pengawas</Chip>
                    ) : sesi.jumlah_pengawas === 0 ? (
                        <Chip nada="warn">belum ada pengawas</Chip>
                    ) : (
                        <Chip nada="ok">lengkap</Chip>
                    )}

                    <span className="text-[11px] font-medium text-accent">Atur →</span>
                </span>
            </div>

            {/* Ruang yang belum terjaga disebut NAMANYA, bukan hanya
                dihitung: Admin yang membuka laman sesi perlu tahu ruang mana
                yang harus dicari, dan "3 ruang" tidak menjawab itu. */}
            {kurang > 0 && (
                <p className="mt-1 text-[11px] text-bad">
                    Belum berpengawas: {sesi.ruang_tanpa_pengawas.join(', ')}
                </p>
            )}
        </Link>
    );
}

/**
 * Tautan "Atur pengawas" di toolbar - hanya bila ada tepat satu sesi.
 *
 * Dengan dua sesi atau lebih, tautan tunggal ke salah satunya adalah tebakan
 * yang mengarahkan Admin ke sesi yang bukan maksudnya. Keadaan itu tidak
 * ditebak: Admin memilih sesinya dari daftar di bawah.
 */
function TombolTautanKeLamanSesi({
    per_sesi,
    jalur_sesi,
}: {
    per_sesi: SesiKelompok[];
    jalur_sesi: string;
}) {
    if (per_sesi.length !== 1) {
        return null;
    }

    return (
        <Link
            href={`${jalur_sesi}/${per_sesi[0].id}`}
            className="rounded-wf border border-line bg-surface px-3 py-1.5 text-xs font-medium text-ink hover:bg-surface-2"
        >
            Atur pengawas {per_sesi[0].label}
        </Link>
    );
}

function JadwalPengawasan({
    baris,
    per_sesi,
    jalur_sesi,
    beban_pengawas,
}: {
    baris: Baris[];
    per_sesi: SesiKelompok[];
    jalur_sesi: string;
    beban_pengawas: Record<number, { terpakai: number; maks: number }>;
}) {
    const [sesiDipilih, setSesiDipilih] = useState<number>(0);
    const [cari, setCari] = useState('');

    const tersaring = baris.filter((b) => {
        if (sesiDipilih !== 0 && b.sesi_id !== sesiDipilih) {
            return false;
        }

        if (cari.trim() !== '') {
            const q = cari.trim().toLowerCase();
            const teks = [
                b.hari_tanggal ?? b.tanggal ?? '',
                b.sesi ?? '',
                b.ujian ?? '',
                b.ruang ?? '',
                b.ruang_nama ?? '',
                ...(b.kelas ?? []),
                ...b.pengawas.map((p) => p.nama ?? ''),
            ]
                .join(' ')
                .toLowerCase();

            if (!teks.includes(q)) {
                return false;
            }
        }

        return true;
    });

    return (
        <section className="mt-4 overflow-hidden rounded-wf border border-line bg-surface">
            <header className="flex flex-wrap items-center gap-2 border-b border-line bg-surface-2/40 px-4 py-2.5">
                <h2 className="font-display text-sm font-semibold text-ink">
                    Jadwal pengawasan ({tersaring.length})
                </h2>

                <div className="ml-auto flex flex-wrap items-center gap-2">
                    <input
                        value={cari}
                        onChange={(e) => setCari(e.target.value)}
                        placeholder="Cari ruang, kelas, atau pengawas..."
                        className={kelasInput + ' w-56'}
                    />

                    <select
                        value={sesiDipilih}
                        onChange={(e) => setSesiDipilih(Number(e.target.value))}
                        className={kelasInput + ' w-auto'}
                        aria-label="Filter sesi jadwal pengawasan"
                    >
                        <option value={0}>Semua sesi</option>
                        {per_sesi.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.label} · {s.ujian ?? '—'}
                            </option>
                        ))}
                    </select>
                </div>
            </header>

            <div className="overflow-x-auto">
                <table className="w-full min-w-[48rem] text-sm">
                    <thead>
                        <tr className="border-b border-line bg-surface-2/50 text-left text-[11px] font-medium text-ink-3">
                            <th className="px-3 py-2">Hari &amp; Tanggal</th>
                            <th className="px-3 py-2">Sesi &amp; Ujian</th>
                            <th className="px-3 py-2">Ruang &amp; Kelas</th>
                            <th className="px-3 py-2">Pengawas</th>
                            <th className="w-20 px-3 py-2 text-right"></th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                        {tersaring.length === 0 ? (
                            <tr>
                                <td colSpan={5} className="px-4 py-6 text-center text-xs text-ink-3">
                                    Tidak ada jadwal pengawasan yang cocok.
                                </td>
                            </tr>
                        ) : (
                            tersaring.map((b) => (
                                <tr key={b.id} className="align-top hover:bg-surface-2/30">
                                    <td className="px-3 py-2.5">
                                        <div className="text-xs font-medium text-ink">
                                            {b.hari_tanggal ?? b.tanggal ?? '—'}
                                        </div>
                                        <div className="mt-0.5 font-mono text-[11px] text-ink-3">
                                            {b.jam ?? '—'}
                                            {b.selesai ? `–${b.selesai}` : ''}
                                        </div>
                                    </td>

                                    <td className="px-3 py-2.5">
                                        <div className="font-mono text-xs font-medium text-ink">
                                            {b.sesi ?? '—'}
                                        </div>
                                        <div className="mt-0.5 text-xs text-ink-2">
                                            {b.ujian ?? '—'}
                                        </div>
                                    </td>

                                    <td className="px-3 py-2.5">
                                        <div className="flex flex-wrap items-center gap-1.5">
                                            <span className="font-mono text-xs font-medium text-ink">
                                                {b.ruang ?? '—'}
                                            </span>
                                            {b.ruang_nama && (
                                                <span className="text-xs text-ink-3">
                                                    · {b.ruang_nama}
                                                </span>
                                            )}
                                        </div>
                                        <div className="mt-0.5 text-[11px] text-ink-2">
                                            {b.kelas && b.kelas.length > 0
                                                ? `Kelas ${b.kelas.join(', ')}`
                                                : `${b.peserta} peserta`}
                                        </div>
                                    </td>

                                    <td className="px-3 py-2.5">
                                        {b.pengawas.length === 0 ? (
                                            <Chip nada="bad">Belum ada pengawas</Chip>
                                        ) : (
                                            <div className="flex flex-wrap gap-1.5">
                                                {b.pengawas.map((p) => {
                                                    const beban = beban_pengawas[p.user_id];
                                                    return (
                                                        <span
                                                            key={p.id}
                                                            className="inline-flex items-center gap-1 rounded-wf border border-line bg-wf-soft px-2 py-0.5 text-xs text-ink"
                                                        >
                                                            <span>{p.nama ?? '—'}</span>
                                                            {beban && (
                                                                <span className="font-mono text-[10px] text-ink-3">
                                                                    {beban.maks > 0
                                                                        ? `${beban.terpakai}/${beban.maks}`
                                                                        : `${beban.terpakai}×`}
                                                                </span>
                                                            )}
                                                        </span>
                                                    );
                                                })}
                                            </div>
                                        )}
                                    </td>

                                    <td className="px-3 py-2.5 text-right">
                                        {b.sesi_id !== null && (
                                            <Link
                                                href={`${jalur_sesi}/${b.sesi_id}`}
                                                className="text-xs font-medium text-accent hover:underline"
                                            >
                                                Atur →
                                            </Link>
                                        )}
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>
        </section>
    );
}

function KPI({
    label,
    nilai,
    nada,
    catatan,
}: {
    label: string;
    nilai: number;
    nada?: 'ok' | 'warn' | 'bad';
    catatan?: string;
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
            {catatan && <p className="mt-0.5 text-[11px] text-ink-3">{catatan}</p>}
        </div>
    );
}

function PanelAturan({ jalur, aturan }: { jalur: string; aturan: AturanPengawas }) {
    const [form, setForm] = useState<AturanPengawas>(aturan);
    const [terbuka, setTerbuka] = useState(false);

    const simpan = () => {
        router.post(`${jalur}/pengawas/aturan`, form, { preserveScroll: true });
    };

    return (
        <section className="mb-3 rounded-wf border border-line bg-surface px-4 py-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 className="text-sm font-medium text-ink">Aturan penjadwalan pengawas</h2>
                    <p className="text-[11px] text-ink-3">
                        Maks. event:{' '}
                        <span className="font-mono text-ink">
                            {form.maks_per_event > 0 ? `${form.maks_per_event}×` : 'bebas'}
                        </span>{' '}
                        · Maks. hari:{' '}
                        <span className="font-mono text-ink">
                            {form.maks_per_hari > 0 ? `${form.maks_per_hari}×` : 'bebas'}
                        </span>{' '}
                        · Target ruang: <span className="font-mono text-ink">{form.target_per_ruang}</span> ·
                        Prioritas: <span className="font-mono text-ink">{form.prioritas}</span>
                    </p>
                </div>
                <Tombol nada="sek" onClick={() => setTerbuka((v) => !v)}>
                    {terbuka ? 'Tutup aturan' : 'Ubah aturan'}
                </Tombol>
            </div>

            {terbuka && (
                <div className="mt-3 border-t border-line pt-3">
                    <div className="grid gap-3 sm:grid-cols-4">
                        <label className="text-xs text-ink-2">
                            <span className="mb-1 block">Maks. tugas / event (0 = bebas)</span>
                            <input
                                type="number"
                                min={0}
                                max={50}
                                value={form.maks_per_event}
                                onChange={(e) =>
                                    setForm({ ...form, maks_per_event: Math.max(0, Number(e.target.value)) })
                                }
                                className={kelasInput}
                            />
                        </label>
                        <label className="text-xs text-ink-2">
                            <span className="mb-1 block">Maks. tugas / hari (0 = bebas)</span>
                            <input
                                type="number"
                                min={0}
                                max={20}
                                value={form.maks_per_hari}
                                onChange={(e) =>
                                    setForm({ ...form, maks_per_hari: Math.max(0, Number(e.target.value)) })
                                }
                                className={kelasInput}
                            />
                        </label>
                        <label className="text-xs text-ink-2">
                            <span className="mb-1 block">Target pengawas / ruang</span>
                            <select
                                value={form.target_per_ruang}
                                onChange={(e) =>
                                    setForm({ ...form, target_per_ruang: Number(e.target.value) })
                                }
                                className={kelasInput}
                            >
                                <option value={1}>1 pengawas</option>
                                <option value={2}>2 pengawas</option>
                            </select>
                        </label>
                        <label className="text-xs text-ink-2">
                            <span className="mb-1 block">Prioritas pembagian</span>
                            <select
                                value={form.prioritas}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        prioritas: e.target.value === 'urutan' ? 'urutan' : 'merata',
                                    })
                                }
                                className={kelasInput}
                            >
                                <option value="merata">Merata (beban terkecil dulu)</option>
                                <option value="urutan">Sesuai urutan daftar</option>
                            </select>
                        </label>
                    </div>

                    <div className="mt-3 flex flex-wrap gap-4 text-xs text-ink-2">
                        {(
                            [
                                ['hormati_pengecualian', 'Hormati sesi dikosongkan'],
                                ['hindari_wali_kelas', 'Hindari wali kelas peserta'],
                                ['hindari_guru_mapel', 'Hindari guru mapel ujian'],
                                ['tolak_melebihi_kuota', 'Tolak penugasan manual melebihi kuota'],
                            ] as const
                        ).map(([kunci, label]) => (
                            <label key={kunci} className="inline-flex items-center gap-1.5">
                                <input
                                    type="checkbox"
                                    checked={form[kunci]}
                                    onChange={(e) => setForm({ ...form, [kunci]: e.target.checked })}
                                />
                                <span>{label}</span>
                            </label>
                        ))}
                    </div>

                    <div className="mt-3 flex justify-end">
                        <Tombol nada="pri" onClick={simpan}>
                            Simpan aturan
                        </Tombol>
                    </div>
                </div>
            )}
        </section>
    );
}

function MatriksKetersediaan({
    jalur,
    matriks,
    sesi_kolom,
}: {
    jalur: string;
    matriks: MatriksBaris[];
    sesi_kolom: SesiKolom[];
}) {
    const [cari, setCari] = useState('');
    const [orangId, setOrangId] = useState<number>(matriks[0]?.id ?? 0);
    const tanggalUnik = Array.from(
        new Set(sesi_kolom.map((s) => s.tanggal_iso).filter((v): v is string => Boolean(v))),
    );
    const [tanggal, setTanggal] = useState<string>(tanggalUnik[0] ?? '');
    const [alasan, setAlasan] = useState('');
    const [kuotaDraft, setKuotaDraft] = useState<Record<number, string>>({});
    const [pilihIds, setPilihIds] = useState<number[]>([]);
    const [kuotaMassal, setKuotaMassal] = useState('');
    const [prosesMassal, setProsesMassal] = useState(false);

    const tersaring = matriks.filter((m) => {
        const q = cari.trim().toLowerCase();
        if (!q) {
            return true;
        }
        return m.nama.toLowerCase().includes(q) || m.username.toLowerCase().includes(q);
    });

    const toggleSesi = (sesiId: number, supervisorId: number) => {
        router.post(
            `${jalur}/pengawas/pengecualian`,
            { exam_session_id: sesiId, supervisor_id: supervisorId },
            { preserveScroll: true },
        );
    };

    const kosongkanHari = () => {
        if (!orangId || !tanggal) {
            return;
        }
        router.post(
            `${jalur}/pengawas/kosongkan-hari`,
            { supervisor_id: orangId, tanggal, reason: alasan || null },
            { preserveScroll: true, onSuccess: () => setAlasan('') },
        );
    };

    const simpanKuota = (supervisorId: number, nilai: string) => {
        const parsed = nilai.trim() === '' ? null : Math.max(0, Number(nilai));
        router.post(
            `${jalur}/pengawas/kuota`,
            { supervisor_id: supervisorId, maks_mengawas: parsed },
            {
                preserveScroll: true,
                onSuccess: () =>
                    setKuotaDraft((prev) => {
                        const salinan = { ...prev };
                        delete salinan[supervisorId];
                        return salinan;
                    }),
            },
        );
    };

    const draftBerubah = Object.entries(kuotaDraft)
        .map(([idStr, nilai]) => {
            const id = Number(idStr);
            const item = matriks.find((m) => m.id === id);
            const asli = item?.maks_khusus !== null && item?.maks_khusus !== undefined
                ? String(item.maks_khusus)
                : '';

            if (nilai === asli) {
                return null;
            }

            return {
                supervisor_id: id,
                maks_mengawas: nilai.trim() === '' ? null : Math.min(50, Math.max(0, Number(nilai))),
            };
        })
        .filter((x): x is { supervisor_id: number; maks_mengawas: number | null } => x !== null);

    const simpanSemuaDraftKuota = () => {
        if (draftBerubah.length === 0) {
            return;
        }

        setProsesMassal(true);
        router.post(
            `${jalur}/pengawas/kuota-massal`,
            { kuota: draftBerubah },
            {
                preserveScroll: true,
                onSuccess: () => setKuotaDraft({}),
                onFinish: () => setProsesMassal(false),
            },
        );
    };

    const sasaranMassal =
        pilihIds.length > 0
            ? tersaring.filter((m) => pilihIds.includes(m.id))
            : tersaring;

    const terapkanKuotaMassal = (nilaiStr: string) => {
        if (sasaranMassal.length === 0) {
            return;
        }

        const parsed =
            nilaiStr.trim() === '' ? null : Math.min(50, Math.max(0, Number(nilaiStr)));

        setProsesMassal(true);
        router.post(
            `${jalur}/pengawas/kuota-massal`,
            {
                kuota: sasaranMassal.map((m) => ({
                    supervisor_id: m.id,
                    maks_mengawas: parsed,
                })),
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setKuotaDraft({});
                    setPilihIds([]);
                },
                onFinish: () => setProsesMassal(false),
            },
        );
    };

    const semuaTersaringTerpilih =
        tersaring.length > 0 && tersaring.every((m) => pilihIds.includes(m.id));

    return (
        <section className="space-y-3">
            <Kotak>
                <div className="flex flex-wrap items-end gap-2">
                    <label className="text-xs text-ink-2">
                        <span className="mb-1 block">Cari pengawas</span>
                        <input
                            type="search"
                            value={cari}
                            onChange={(e) => setCari(e.target.value)}
                            placeholder="Nama atau username..."
                            className={kelasInput + ' w-56'}
                        />
                    </label>

                    {matriks.length > 0 && tanggalUnik.length > 0 && (
                        <>
                            <label className="text-xs text-ink-2">
                                <span className="mb-1 block">Kosongkan satu hari</span>
                                <select
                                    value={orangId}
                                    onChange={(e) => setOrangId(Number(e.target.value))}
                                    className={kelasInput + ' w-48'}
                                >
                                    {matriks.map((m) => (
                                        <option key={m.id} value={m.id}>
                                            {m.nama}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="text-xs text-ink-2">
                                <span className="mb-1 block">Tanggal</span>
                                <select
                                    value={tanggal}
                                    onChange={(e) => setTanggal(e.target.value)}
                                    className={kelasInput + ' w-36'}
                                >
                                    {tanggalUnik.map((t) => (
                                        <option key={t} value={t}>
                                            {t}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="text-xs text-ink-2">
                                <span className="mb-1 block">Alasan (opsional)</span>
                                <input
                                    type="text"
                                    value={alasan}
                                    onChange={(e) => setAlasan(e.target.value)}
                                    placeholder="Izin / rapat"
                                    className={kelasInput + ' w-40'}
                                />
                            </label>
                            <Tombol nada="sek" onClick={kosongkanHari}>
                                Kosongkan hari
                            </Tombol>
                        </>
                    )}
                </div>

                {matriks.length > 0 && (
                    <div className="mt-3 flex flex-wrap items-end gap-2 border-t border-line pt-3">
                        <label className="text-xs text-ink-2">
                            <span className="mb-1 block">
                                Kuota khusus massal (
                                {pilihIds.length > 0
                                    ? `${sasaranMassal.length} dipilih`
                                    : `semua ${sasaranMassal.length} tampil`}
                                )
                            </span>
                            <input
                                type="number"
                                min={0}
                                max={50}
                                value={kuotaMassal}
                                onChange={(e) => setKuotaMassal(e.target.value)}
                                placeholder="Default"
                                className={kelasInput + ' w-32 font-mono'}
                            />
                        </label>
                        <Tombol
                            nada="pri"
                            disabled={prosesMassal || sasaranMassal.length === 0}
                            onClick={() => terapkanKuotaMassal(kuotaMassal)}
                        >
                            Terapkan kuota
                        </Tombol>
                        <Tombol
                            disabled={prosesMassal || sasaranMassal.length === 0}
                            onClick={() => {
                                setKuotaMassal('');
                                terapkanKuotaMassal('');
                            }}
                            title="Kembalikan kuota khusus ke bawaan aturan event"
                        >
                            Reset ke default
                        </Tombol>
                        {draftBerubah.length > 0 && (
                            <Tombol
                                nada="sek"
                                disabled={prosesMassal}
                                onClick={simpanSemuaDraftKuota}
                            >
                                Simpan perubahan baris ({draftBerubah.length})
                            </Tombol>
                        )}
                    </div>
                )}
            </Kotak>

            <div className="overflow-x-auto rounded-wf border border-line bg-surface">
                <table className="w-full border-collapse text-left text-xs">
                    <thead>
                        <tr className="border-b border-line bg-surface-2/60 text-[11px] uppercase text-ink-3">
                            <th className="w-9 px-3 py-2 text-center">
                                <input
                                    type="checkbox"
                                    checked={semuaTersaringTerpilih}
                                    onChange={(e) =>
                                        setPilihIds(
                                            e.target.checked ? tersaring.map((m) => m.id) : [],
                                        )
                                    }
                                    className="size-3.5 accent-accent"
                                    aria-label="Pilih semua pengawas"
                                />
                            </th>
                            <th className="px-3 py-2">Pengawas</th>
                            <th className="px-3 py-2">Kuota Khusus</th>
                            {sesi_kolom.map((s) => (
                                <th key={s.id} className="px-2 py-2 text-center">
                                    <div className="font-mono text-ink">{s.label}</div>
                                    <div className="text-[10px] font-normal lowercase">
                                        {s.tanggal ?? '—'} {s.jam ?? ''}
                                    </div>
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                        {tersaring.length === 0 ? (
                            <tr>
                                <td
                                    colSpan={3 + sesi_kolom.length}
                                    className="px-3 py-6 text-center text-ink-3"
                                >
                                    Belum ada calon pengawas.
                                </td>
                            </tr>
                        ) : (
                            tersaring.map((m) => {
                                const draft =
                                    kuotaDraft[m.id] ??
                                    (m.maks_khusus !== null ? String(m.maks_khusus) : '');
                                return (
                                    <tr key={m.id}>
                                        <td className="px-3 py-2 text-center">
                                            <input
                                                type="checkbox"
                                                checked={pilihIds.includes(m.id)}
                                                onChange={(e) =>
                                                    setPilihIds((prev) =>
                                                        e.target.checked
                                                            ? [...prev, m.id]
                                                            : prev.filter((x) => x !== m.id),
                                                    )
                                                }
                                                className="size-3.5 accent-accent"
                                                aria-label={`Pilih ${m.nama}`}
                                            />
                                        </td>
                                        <td className="px-3 py-2">
                                            <div className="font-medium text-ink">{m.nama}</div>
                                            <div className="font-mono text-[10px] text-ink-3">
                                                {m.terpakai}/{m.maks > 0 ? m.maks : '∞'} tugas
                                            </div>
                                        </td>
                                        <td className="px-3 py-2">
                                            <div className="flex items-center gap-1">
                                                <input
                                                    type="number"
                                                    min={0}
                                                    max={50}
                                                    placeholder="Default"
                                                    value={draft}
                                                    onChange={(e) =>
                                                        setKuotaDraft({
                                                            ...kuotaDraft,
                                                            [m.id]: e.target.value,
                                                        })
                                                    }
                                                    className={kelasInput + ' w-20 py-1 text-xs'}
                                                />
                                                <button
                                                    type="button"
                                                    onClick={() => simpanKuota(m.id, draft)}
                                                    className="rounded-wf border border-line px-2 py-1 text-[11px] text-ink hover:bg-surface-2"
                                                >
                                                    Simpan
                                                </button>
                                            </div>
                                        </td>
                                        {sesi_kolom.map((s) => {
                                            const dikecualikan = m.dikecualikan_sesi.includes(s.id);
                                            const tugas = m.penugasan.find((p) => p.sesi_id === s.id);
                                            return (
                                                <td key={s.id} className="px-2 py-2 text-center">
                                                    <button
                                                        type="button"
                                                        onClick={() => toggleSesi(s.id, m.id)}
                                                        title={
                                                            dikecualikan
                                                                ? 'Klik untuk tandai tersedia'
                                                                : 'Klik untuk kosongkan di sesi ini'
                                                        }
                                                        className={
                                                            'rounded-wf border px-2 py-1 text-[11px] font-medium ' +
                                                            (dikecualikan
                                                                ? 'border-bad/40 bg-bad/10 text-bad'
                                                                : tugas
                                                                  ? 'border-accent/40 bg-accent/10 text-accent'
                                                                  : 'border-line bg-wf-soft text-ink-2 hover:border-ink-3')
                                                        }
                                                    >
                                                        {dikecualikan
                                                            ? 'Kosong'
                                                            : tugas
                                                              ? tugas.ruang
                                                              : 'Tersedia'}
                                                    </button>
                                                </td>
                                            );
                                        })}
                                    </tr>
                                );
                            })
                        )}
                    </tbody>
                </table>
            </div>
        </section>
    );
}

function RekapBeban({ matriks }: { matriks: MatriksBaris[] }) {
    const [cari, setCari] = useState('');
    const [filterStatus, setFilterStatus] = useState<'semua' | 'penuh' | 'aman'>('semua');

    const tersaring = matriks.filter((m) => {
        if (filterStatus === 'penuh' && m.status_kuota === 'aman') {
            return false;
        }
        if (filterStatus === 'aman' && m.status_kuota !== 'aman') {
            return false;
        }
        const q = cari.trim().toLowerCase();
        if (!q) {
            return true;
        }
        return m.nama.toLowerCase().includes(q) || m.username.toLowerCase().includes(q);
    });

    return (
        <section className="space-y-3">
            <div className="flex flex-wrap items-center gap-2">
                <input
                    type="search"
                    value={cari}
                    onChange={(e) => setCari(e.target.value)}
                    placeholder="Cari nama pengawas..."
                    className={kelasInput + ' w-60'}
                />
                <select
                    value={filterStatus}
                    onChange={(e) =>
                        setFilterStatus(
                            e.target.value === 'penuh' || e.target.value === 'aman'
                                ? e.target.value
                                : 'semua',
                        )
                    }
                    className={kelasInput + ' w-44'}
                >
                    <option value="semua">Semua status kuota</option>
                    <option value="aman">Aman</option>
                    <option value="penuh">Penuh / Melebihi</option>
                </select>
            </div>

            <div className="overflow-x-auto rounded-wf border border-line bg-surface">
                <table className="w-full border-collapse text-left text-xs">
                    <thead>
                        <tr className="border-b border-line bg-surface-2/60 text-[11px] uppercase text-ink-3">
                            <th className="px-3 py-2">Pengawas</th>
                            <th className="px-3 py-2">Peran</th>
                            <th className="px-3 py-2">Total Tugas</th>
                            <th className="px-3 py-2">Sesi Dikosongkan</th>
                            <th className="px-3 py-2">Daftar Penugasan</th>
                            <th className="px-3 py-2">Status</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                        {tersaring.length === 0 ? (
                            <tr>
                                <td colSpan={6} className="px-3 py-6 text-center text-ink-3">
                                    Tidak ada data pengawas yang cocok.
                                </td>
                            </tr>
                        ) : (
                            tersaring.map((m) => (
                                <tr key={m.id}>
                                    <td className="px-3 py-2.5 font-medium text-ink">{m.nama}</td>
                                    <td className="px-3 py-2.5 text-ink-2">{m.peran}</td>
                                    <td className="px-3 py-2.5 font-mono text-ink">
                                        {m.terpakai} / {m.maks > 0 ? m.maks : '∞'}
                                    </td>
                                    <td className="px-3 py-2.5 font-mono text-ink-2">
                                        {m.dikecualikan_sesi.length} sesi
                                    </td>
                                    <td className="px-3 py-2.5">
                                        {m.penugasan.length === 0 ? (
                                            <span className="text-ink-3">—</span>
                                        ) : (
                                            <div className="flex flex-wrap gap-1">
                                                {m.penugasan.map((p, idx) => (
                                                    <span
                                                        key={idx}
                                                        className="rounded-wf border border-line bg-wf-soft px-2 py-0.5 font-mono text-[11px] text-ink"
                                                    >
                                                        {p.sesi_label} · {p.ruang}
                                                    </span>
                                                ))}
                                            </div>
                                        )}
                                    </td>
                                    <td className="px-3 py-2.5">
                                        {m.status_kuota === 'melebihi' ? (
                                            <Chip nada="bad">Melebihi batas</Chip>
                                        ) : m.status_kuota === 'penuh' ? (
                                            <Chip nada="warn">Penuh</Chip>
                                        ) : (
                                            <Chip nada="ok">Aman</Chip>
                                        )}
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>
        </section>
    );
}

function ModalPengecualian({
    terbuka,
    jalur,
    daftar,
    sesi_kolom,
    padaTutup,
}: {
    terbuka: boolean;
    jalur: string;
    daftar: PengecualianItem[];
    sesi_kolom: SesiKolom[];
    padaTutup: () => void;
}) {
    const [cari, setCari] = useState('');
    const [editId, setEditId] = useState<number | null>(null);
    const [sesiId, setSesiId] = useState<number>(0);
    const [alasan, setAlasan] = useState('');
    const [proses, setProses] = useState(false);

    const tersaring = daftar.filter((item) => {
        const q = cari.trim().toLowerCase();
        if (!q) {
            return true;
        }
        return (
            item.nama.toLowerCase().includes(q) ||
            (item.username ?? '').toLowerCase().includes(q) ||
            item.sesi_label.toLowerCase().includes(q) ||
            (item.ujian ?? '').toLowerCase().includes(q) ||
            item.reason.toLowerCase().includes(q)
        );
    });

    const mulaiEdit = (item: PengecualianItem) => {
        setEditId(item.id);
        setSesiId(item.exam_session_id);
        setAlasan(item.reason);
    };

    const batalEdit = () => {
        setEditId(null);
        setAlasan('');
    };

    const simpanEdit = (id: number) => {
        setProses(true);
        router.put(
            `${jalur}/pengawas/pengecualian/${id}`,
            { exam_session_id: sesiId, reason: alasan.trim() || null },
            {
                preserveScroll: true,
                onSuccess: () => setEditId(null),
                onFinish: () => setProses(false),
            },
        );
    };

    const hapus = async (item: PengecualianItem) => {
        const setuju = await konfirmasi({
            judul: `Hapus pengecualian ${item.nama}?`,
            pesan: `${item.nama} akan kembali tersedia untuk bertugas pada ${item.sesi_label}${item.hari_tanggal ? ` (${item.hari_tanggal})` : ''}.`,
            labelYa: 'Hapus',
            nada: 'bahaya',
        });

        if (!setuju) {
            return;
        }

        router.delete(`${jalur}/pengawas/pengecualian/${item.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                if (editId === item.id) {
                    setEditId(null);
                }
            },
        });
    };

    return (
        <Modal
            judul={`Daftar pengecualian pengawas (${daftar.length})`}
            terbuka={terbuka}
            padaTutup={() => {
                batalEdit();
                padaTutup();
            }}
        >
            <div className="space-y-3">
                {daftar.length > 0 && (
                    <input
                        type="search"
                        value={cari}
                        onChange={(e) => setCari(e.target.value)}
                        placeholder="Cari pengawas, sesi, atau alasan..."
                        className={kelasInput}
                    />
                )}

                {tersaring.length === 0 ? (
                    <p className="rounded-wf border border-line bg-surface-2/40 px-3 py-6 text-center text-xs text-ink-3">
                        {daftar.length === 0
                            ? 'Belum ada pengecualian pengawas pada event ini.'
                            : 'Tidak ada pengecualian yang cocok dengan pencarian.'}
                    </p>
                ) : (
                    <div className="max-h-96 space-y-2 overflow-y-auto pr-0.5">
                        {tersaring.map((item) => {
                            const sedangEdit = editId === item.id;

                            return (
                                <div
                                    key={item.id}
                                    className="rounded-wf border border-line bg-surface-2/30 p-3 text-xs"
                                >
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="min-w-0">
                                            <div className="font-medium text-ink">
                                                {item.nama}
                                                {item.username && (
                                                    <span className="ml-1.5 font-mono text-[11px] font-normal text-ink-3">
                                                        {item.username}
                                                    </span>
                                                )}
                                            </div>
                                            {!sedangEdit && (
                                                <>
                                                    <div className="mt-0.5 text-[11px] text-ink-2">
                                                        <span className="font-mono">
                                                            {item.sesi_label}
                                                        </span>
                                                        {item.hari_tanggal && (
                                                            <> · {item.hari_tanggal}</>
                                                        )}
                                                        {item.jam && (
                                                            <span className="font-mono">
                                                                {' '}
                                                                {item.jam}
                                                            </span>
                                                        )}
                                                        {item.ujian && <> · {item.ujian}</>}
                                                    </div>
                                                    <div className="mt-1 text-[11px] text-ink-3">
                                                        Alasan:{' '}
                                                        <span className="text-ink-2">
                                                            {item.reason || '—'}
                                                        </span>
                                                    </div>
                                                </>
                                            )}
                                        </div>

                                        {!sedangEdit && (
                                            <div className="flex shrink-0 gap-1.5">
                                                <button
                                                    type="button"
                                                    onClick={() => mulaiEdit(item)}
                                                    className="rounded-wf border border-line bg-surface px-2 py-1 text-[11px] text-ink hover:bg-surface-2"
                                                >
                                                    Ubah
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => hapus(item)}
                                                    className="rounded-wf border border-bad/40 bg-surface px-2 py-1 text-[11px] text-bad hover:bg-bad/10"
                                                >
                                                    Hapus
                                                </button>
                                            </div>
                                        )}
                                    </div>

                                    {sedangEdit && (
                                        <div className="mt-2.5 space-y-2 border-t border-line/60 pt-2.5">
                                            <label className="block">
                                                <span className="mb-1 block text-[11px] text-ink-3">
                                                    Sesi
                                                </span>
                                                <select
                                                    value={sesiId}
                                                    onChange={(e) =>
                                                        setSesiId(Number(e.target.value))
                                                    }
                                                    className={kelasInput + ' py-1.5 text-xs'}
                                                >
                                                    {sesi_kolom.map((s) => (
                                                        <option key={s.id} value={s.id}>
                                                            {s.label}
                                                            {s.tanggal ? ` · ${s.tanggal}` : ''}
                                                            {s.jam ? ` ${s.jam}` : ''}
                                                            {s.ujian ? ` — ${s.ujian}` : ''}
                                                        </option>
                                                    ))}
                                                </select>
                                            </label>

                                            <label className="block">
                                                <span className="mb-1 block text-[11px] text-ink-3">
                                                    Alasan (opsional)
                                                </span>
                                                <input
                                                    type="text"
                                                    maxLength={120}
                                                    value={alasan}
                                                    onChange={(e) => setAlasan(e.target.value)}
                                                    placeholder="Izin / rapat / dinas luar"
                                                    className={kelasInput + ' py-1.5 text-xs'}
                                                />
                                            </label>

                                            <div className="flex justify-end gap-1.5 pt-1">
                                                <Tombol
                                                    nada="pri"
                                                    disabled={proses}
                                                    onClick={() => simpanEdit(item.id)}
                                                >
                                                    {proses ? 'Menyimpan...' : 'Simpan'}
                                                </Tombol>
                                                <Tombol disabled={proses} onClick={batalEdit}>
                                                    Batal
                                                </Tombol>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                )}

                <div className="flex justify-end pt-1">
                    <Tombol
                        onClick={() => {
                            batalEdit();
                            padaTutup();
                        }}
                    >
                        Tutup
                    </Tombol>
                </div>
            </div>
        </Modal>
    );
}

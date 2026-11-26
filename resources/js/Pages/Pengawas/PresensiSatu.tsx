import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import KerangkaPonsel from '@/Layouts/KerangkaPonsel';
import { Chip, Inbox } from '@/Components/ui';
import { KpiPonsel, KotakPonsel } from '@/Components/ponsel';

/**
 * Plate 13 - presensi ruang, satu siswa.
 *
 * =====================================================================
 * Panel ini adalah LAMAN, bukan lapisan
 * =====================================================================
 * Wireframe `:161` menyebutnya "lapisan dari baris tabel, bukan halaman
 * tersendiri, supaya Pengawas tidak kehilangan posisinya di daftar kursi".
 * Yang dipakai di sini adalah rute tersendiri dengan tombol kembali, dan
 * perbedaannya disengaja: di ponsel, "lapisan" berarti modal penuh layar -
 * dan modal penuh layar dengan daftar 32 kursi di belakangnya kehilangan
 * posisi gulirnya juga saat ditutup. Rute tersendiri yang kembali ke
 * `/presensi` mengembalikan posisinya lewat riwayat peramban, bukan lewat
 * state yang harus dijaga.
 *
 * ---------------------------------------------------------------------
 * Yang paling sering dibutuhkan ada di PALING ATAS
 * ---------------------------------------------------------------------
 * Empat tombol status, dan keterangan siapa yang menekannya kapan. Riwayat
 * ditaruh di bawah karena hanya ditengok saat ada perselisihan - misalnya
 * peserta mengaku sudah dipresensi padahal tabelnya belum berubah.
 *
 * ---------------------------------------------------------------------
 * Tidak ada tombol yang memaksa peserta masuk melewati verifikasi
 * ---------------------------------------------------------------------
 * Yang bisa dilakukan pengawas hanyalah mencatat kehadiran. Verifikasi
 * membuka gerbang ujian; selebihnya milik server. Tombol "Mulai tanpa
 * presensi" adalah tombol yang tidak boleh ada di laman ini.
 */
type Props = {
    ruang: {
        id: number;
        kode: string;
        nama: string | null;
        lokasi: string | null;
        sesi: string | null;
        ujian: string | null;
        jam: string | null;
    };
    pelaku: string;
    record_id: number;
    peserta_id: number;
    nama: string;
    nis: string | null;
    kelas: string | null;
    status: string;
    status_label: string;
    status_nada: string;
    diverifikasi_jam: string | null;
    diverifikasi_oleh: string | null;
    pengerjaan: {
        ada: boolean;
        label: string;
        nada: string;
        terhubung: boolean | null;
        pelanggaran: number;
        terakhir: string | null;
    };
    riwayat: {
        jam: string | null;
        dari: string | null;
        ke: string;
        oleh: string;
        alasan: string | null;
    }[];
    opsi: Record<string, string>;
    ruang_tujuan?: {
        id: number;
        kode: string;
        nama: string | null;
        kapasitas: number;
        terisi: number;
        pc: number;
        pc_tersedia: number[];
    }[];
    darurat?: {
        mode: string | null;
        nomor_pc: number | null;
        alasan: string | null;
        waktu: string | null;
    } | null;
};

export default function PresensiSatu({
    ruang,
    record_id,
    peserta_id,
    nama,
    nis,
    kelas,
    status,
    status_label,
    status_nada,
    diverifikasi_jam,
    diverifikasi_oleh,
    pengerjaan,
    riwayat,
    opsi,
    ruang_tujuan = [],
    darurat = null,
}: Props) {
    const [kirim, setKirim] = useState(false);

    const ubah = (ke: string) => {
        if (ke === status) {
            return;
        }

        setKirim(true);
        router.post(
            `/pengawas/ruang/${ruang.id}/presensi/verifikasi`,
            { record_id, status: ke },
            { preserveScroll: true, onFinish: () => setKirim(false) },
        );
    };

    // Empat tombol, dan yang sedang berlaku ditandai - bukan disembunyikan.
    // Menyembunyikan yang sedang berlaku membuat orang mengira statusnya
    // kosong lalu menekan yang lain.
    const URUTAN = ['hadir', 'terlambat', 'izin', 'tidak_hadir'];

    return (
        <KerangkaPonsel
            judul={`Presensi ${nama}`}
            bilah={false}
        >
            <header className="sticky top-0 z-20 flex items-center gap-2 border-b border-line bg-surface/95 px-3 py-2 backdrop-blur">
                <span className="shrink-0">
                    <Chip nada="info">{ruang.kode}</Chip>
                </span>
                <span className="min-w-0 truncate text-[11px] text-ink-3">
                    {[nama, kelas, nis].filter(Boolean).join(' · ')}
                </span>
                <Link
                    href={`/pengawas/ruang/${ruang.id}/presensi`}
                    className="ml-auto inline-grid min-h-11 -my-2 shrink-0 place-items-center rounded-wf border border-line bg-surface px-2.5 text-[11px] text-ink-2"
                >
                    Tutup
                </Link>
            </header>

            <KotakPonsel solid padat judul="Status presensi" kanan={<Chip nada={status_nada as 'mute'}>{status_label}</Chip>}>
                <div className="grid grid-cols-2 gap-1.5">
                    {URUTAN.map((s) => (
                        <button
                            key={s}
                            type="button"
                            onClick={() => ubah(s)}
                            disabled={kirim}
                            className={
                                // `min-h-11` - empat tombol status ini
                                // ditekan satu per satu di depan pintu, dan
                                // salah satu di antaranya membuka gerbang
                                // ujian. `py-2.5` memberinya 38px.
                                'inline-grid min-h-11 place-items-center rounded-wf border px-3 text-xs font-medium disabled:opacity-50 ' +
                                (s === status
                                    ? 'border-accent bg-accent text-on-accent'
                                    : 'border-line bg-surface text-ink hover:bg-surface-2')
                            }
                        >
                            {opsi[s] ?? s}
                        </button>
                    ))}
                </div>

                <p className="text-[11px] text-ink-3">
                    {diverifikasi_jam
                        ? `Diverifikasi ${diverifikasi_jam} oleh ${diverifikasi_oleh ?? '—'}`
                        : 'Belum diverifikasi siapa pun.'}
                </p>

                <p className="text-[11px] text-ink-3">
                    Verifikasi membuka gerbang ujian. Peserta boleh mulai kapan saja
                    sampai {ruang.jam?.split('–')[1] ?? 'sesi berakhir'}, dan waktunya
                    berjalan sejak ia menekan Mulai - bukan sejak sekarang.
                </p>
            </KotakPonsel>

            <KotakPonsel
                solid
                padat
                judul="Keadaan pengerjaan"
                kanan={<Chip nada={pengerjaan.nada as 'mute'}>{pengerjaan.label}</Chip>}
            >
                <div className="grid grid-cols-2 gap-1">
                    <KpiPonsel
                        label="Terhubung"
                        nilai={
                            pengerjaan.terhubung === null
                                ? '—'
                                : pengerjaan.terhubung
                                  ? 'Ya'
                                  : 'Tidak'
                        }
                    />
                    <KpiPonsel label="Pelanggaran" nilai={pengerjaan.pelanggaran} nada={pengerjaan.pelanggaran > 0 ? 'warn' : undefined} />
                    <KpiPonsel label="Terakhir aktif" nilai={pengerjaan.terakhir ?? '—'} />
                    <KpiPonsel label="Status" nilai={pengerjaan.ada ? pengerjaan.label : '—'} />
                </div>

                <p className="text-[11px] text-ink-3">
                    Kolom ini berasal dari server. Pengawas tidak dapat mengubahnya.
                </p>

                <Link
                    href={`/pengawas/ruang/${ruang.id}/siswa/${peserta_id}`}
                    className="inline-grid min-h-11 place-items-center rounded-wf border border-line bg-surface px-3 text-[11px] text-ink-2"
                >
                    Riwayat pelanggaran lengkap
                </Link>
            </KotakPonsel>

            <PanelPindahDarurat
                ruangId={ruang.id}
                pesertaId={peserta_id}
                nama={nama}
                ruangTujuan={ruang_tujuan}
                darurat={darurat}
            />

            <KotakPonsel padat judul="Riwayat presensi" kanan={<Chip nada="info">append-only</Chip>}>
                {riwayat.length === 0 && (
                    <p className="px-1 py-4 text-center text-xs text-ink-3">
                        Belum ada perubahan status.
                    </p>
                )}

                {riwayat.map((r, i) => (
                    <div
                        key={i}
                        className="flex items-start gap-2 border-b border-line/60 py-2 last:border-0"
                    >
                        <span className="w-11 shrink-0 font-mono text-[11px] text-ink-3">
                            {r.jam ?? '—'}
                        </span>
                        <div className="min-w-0 flex-1">
                            <p className="text-xs text-ink">
                                {r.dari ? `${r.dari} → ${r.ke}` : `dibuat, status ${r.ke}`}
                            </p>
                            <p className="text-[11px] text-ink-3">{r.oleh}</p>
                            {r.alasan && (
                                <p className="text-[11px] text-ink-2 italic">"{r.alasan}"</p>
                            )}
                        </div>
                    </div>
                ))}

                <p className="text-[11px] text-ink-3">
                    Baris lama tidak pernah dihapus, hanya ditambah. Koreksi tercatat
                    sebagai perubahan baru dengan nama pelakunya.
                </p>
            </KotakPonsel>

            <Inbox>
                Tidak ada tombol untuk menghapus riwayat, dan tidak ada tombol yang
                memaksa peserta masuk ke ujian melewati verifikasi. Yang bisa dilakukan
                Pengawas hanyalah mencatat kehadiran - selebihnya milik server.
            </Inbox>
        </KerangkaPonsel>
    );
}

function PanelPindahDarurat({
    ruangId,
    pesertaId,
    nama,
    ruangTujuan,
    darurat,
}: {
    ruangId: number;
    pesertaId: number;
    nama: string;
    ruangTujuan: NonNullable<Props['ruang_tujuan']>;
    darurat: Props['darurat'];
}) {
    const [tujuanId, setTujuanId] = useState<number>(ruangTujuan[0]?.id ?? 0);
    const [mode, setMode] = useState<'hp' | 'pc'>('hp');
    const [nomorPc, setNomorPc] = useState<string>('');
    const [alasan, setAlasan] = useState('');
    const [kirim, setKirim] = useState(false);

    const terpilih = ruangTujuan.find((r) => r.id === tujuanId) ?? null;
    const bisaPc = terpilih !== null && terpilih.pc > 0 && terpilih.pc_tersedia.length > 0;

    const gantiTujuan = (idBaru: number) => {
        setTujuanId(idBaru);
        const r = ruangTujuan.find((x) => x.id === idBaru);
        if (!r || r.pc === 0 || r.pc_tersedia.length === 0) {
            setMode('hp');
            setNomorPc('');
        } else if (mode === 'pc') {
            setNomorPc(String(r.pc_tersedia[0]));
        }
    };

    const gantiMode = (m: 'hp' | 'pc') => {
        setMode(m);
        if (m === 'pc' && terpilih && terpilih.pc_tersedia.length > 0) {
            setNomorPc(String(terpilih.pc_tersedia[0]));
        } else {
            setNomorPc('');
        }
    };

    const simpan = () => {
        if (!terpilih || alasan.trim().length < 3) {
            return;
        }
        if (mode === 'pc' && !nomorPc) {
            return;
        }
        setKirim(true);
        router.post(
            `/pengawas/ruang/${ruangId}/presensi/${pesertaId}/pindah-ruang`,
            {
                ruang_tujuan_id: terpilih.id,
                mode,
                nomor_pc: mode === 'pc' ? Number(nomorPc) : null,
                alasan: alasan.trim(),
            },
            {
                preserveScroll: true,
                onFinish: () => setKirim(false),
            },
        );
    };

    return (
        <KotakPonsel
            solid
            padat
            judul="Pindah ruang darurat"
            kanan={<Chip nada="warn">sesi ini saja</Chip>}
        >
            {darurat && (
                <p className="rounded-wf border border-info/30 bg-info-bg px-3 py-2 text-[11px] leading-relaxed text-info">
                    Peserta ini sedang berstatus pindahan darurat (
                    {darurat.mode === 'pc' && darurat.nomor_pc
                        ? `PC ${String(darurat.nomor_pc).padStart(2, '0')}`
                        : 'HP'}
                    {darurat.waktu ? `, pukul ${darurat.waktu}` : ''}).
                    {darurat.alasan ? ` Alasan: "${darurat.alasan}"` : ''}
                </p>
            )}

            {ruangTujuan.length === 0 ? (
                <p className="text-xs text-ink-3">
                    Tidak ada ruang lain yang terdaftar pada sesi ujian ini.
                </p>
            ) : (
                <div className="space-y-3">
                    <p className="text-[11px] leading-relaxed text-ink-3">
                        Pindahkan <strong>{nama}</strong> ke ruang lain khusus untuk sesi ini
                        (mis. HP bermasalah dan pindah ke lab komputer). Sesi berikutnya tetap
                        kembali ke ruang asal.
                    </p>

                    <label className="block">
                        <span className="mb-1 block text-[11px] font-medium text-ink-2">
                            Ruang tujuan
                        </span>
                        <select
                            value={tujuanId}
                            onChange={(e) => gantiTujuan(Number(e.target.value))}
                            className="w-full rounded-wf border border-line bg-surface px-2.5 py-2 text-xs text-ink"
                        >
                            {ruangTujuan.map((r) => (
                                <option key={r.id} value={r.id}>
                                    {r.kode}
                                    {r.nama ? ` · ${r.nama}` : ''} ({r.terisi}/{r.kapasitas}
                                    {r.pc > 0 ? `, ${r.pc_tersedia.length} PC kosong` : ', BYOD'})
                                </option>
                            ))}
                        </select>
                    </label>

                    <div>
                        <span className="mb-1 block text-[11px] font-medium text-ink-2">
                            Perangkat yang dipakai
                        </span>
                        <div className="grid grid-cols-2 gap-1.5">
                            <button
                                type="button"
                                onClick={() => gantiMode('hp')}
                                className={
                                    'inline-grid min-h-11 cursor-pointer place-items-center rounded-wf border px-3 text-xs font-medium ' +
                                    (mode === 'hp'
                                        ? 'border-accent bg-accent text-on-accent'
                                        : 'border-line bg-surface text-ink hover:bg-surface-2')
                                }
                            >
                                Tetap pakai HP
                            </button>
                            <button
                                type="button"
                                disabled={!bisaPc}
                                onClick={() => gantiMode('pc')}
                                className={
                                    'inline-grid min-h-11 cursor-pointer place-items-center rounded-wf border px-3 text-xs font-medium disabled:cursor-not-allowed disabled:opacity-45 ' +
                                    (mode === 'pc'
                                        ? 'border-accent bg-accent text-on-accent'
                                        : 'border-line bg-surface text-ink hover:bg-surface-2')
                                }
                            >
                                Pakai PC Lab
                            </button>
                        </div>
                        {!bisaPc && terpilih && (
                            <p className="mt-1 text-[11px] text-ink-3">
                                {terpilih.pc === 0
                                    ? 'Ruang tujuan adalah ruang BYOD (tidak punya PC).'
                                    : 'Seluruh slot PC di ruang tujuan sedang terisi.'}
                            </p>
                        )}
                    </div>

                    {mode === 'pc' && terpilih && (
                        <label className="block">
                            <span className="mb-1 block text-[11px] font-medium text-ink-2">
                                Pilih nomor PC kosong
                            </span>
                            <select
                                value={nomorPc}
                                onChange={(e) => setNomorPc(e.target.value)}
                                className="w-full rounded-wf border border-line bg-surface px-2.5 py-2 font-mono text-xs text-ink"
                            >
                                {terpilih.pc_tersedia.map((n) => (
                                    <option key={n} value={n}>
                                        PC {String(n).padStart(2, '0')}
                                    </option>
                                ))}
                            </select>
                        </label>
                    )}

                    <label className="block">
                        <span className="mb-1 block text-[11px] font-medium text-ink-2">
                            Alasan pindah
                        </span>
                        <input
                            value={alasan}
                            onChange={(e) => setAlasan(e.target.value)}
                            maxLength={255}
                            placeholder="Contoh: HP siswa bermasalah / layar mati"
                            className="w-full rounded-wf border border-line bg-surface px-2.5 py-2 text-xs text-ink"
                        />
                    </label>

                    <button
                        type="button"
                        disabled={kirim || alasan.trim().length < 3 || (mode === 'pc' && !nomorPc)}
                        onClick={simpan}
                        className="inline-grid min-h-11 w-full cursor-pointer place-items-center rounded-wf border border-accent bg-accent px-4 text-xs font-medium text-on-accent hover:bg-accent/90 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {kirim ? 'Memindahkan…' : 'Pindahkan untuk sesi ini'}
                    </button>
                </div>
            )}
        </KotakPonsel>
    );
}

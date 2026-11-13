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
            gelap
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
                    <KpiPonsel label="Terhubung" nilai={pengerjaan.ada ? (pengerjaan.terhubung ? 'Ya' : 'Tidak') : '—'} />
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

import { Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import KerangkaPonsel from '@/Layouts/KerangkaPonsel';
import { Chip, Inbox } from '@/Components/ui';
import { BarisKursi, KpiPonsel, KotakPonsel } from '@/Components/ponsel';

/**
 * Plate 12 - presensi ruang, daftar kursi.
 *
 * =====================================================================
 * DUA TINGKAT PER KURSI, bukan tabel lima kolom
 * =====================================================================
 * Aturan yang wajib dituruti, dan ditulis juga di `BarisKursi`: pengawas
 * memegang ponsel dengan satu tangan sambil berdiri. Laman yang menuntut
 * geseran mendatar dibaca sambil menggeser, dan baris yang tergeser membuat
 * orang salah menandai - dan salah tandai di sini berarti siswa yang hadir
 * tercatat tidak hadir lalu ditolak sistem saat menekan Mulai.
 *
 * ---------------------------------------------------------------------
 * Dua kolom yang sengaja dipisah: presensi dan pengerjaan
 * ---------------------------------------------------------------------
 * Presensi adalah keputusan pengawas; pengerjaan adalah keadaan yang
 * dilaporkan server dan tidak bisa diketik siapa pun. Peserta yang hadir
 * tapi belum mulai (mungkin lupa token) dan peserta yang hadir tapi
 * koneksinya putus (mungkin perangkatnya mati) adalah dua masalah berbeda,
 * dan menyatukannya dalam satu kolom menyembunyikan keduanya.
 *
 * ---------------------------------------------------------------------
 * Satu ketukan per siswa
 * ---------------------------------------------------------------------
 * Kalau setiap penandaan butuh konfirmasi, mengawasi 32 siswa memakan waktu
 * lebih lama daripada ujiannya sendiri. Karena itu tombol "Hadir" langsung
 * mengirim, dan yang tersisa hanya "Ubah" untuk membuka lapisan plate 13.
 */
type Kursi = {
    record_id: number;
    peserta_id: number;
    kursi: number;
    nama: string;
    nis: string | null;
    kelas: string | null;
    status: string;
    status_label: string;
    status_pendek: string;
    status_nada: string;
    pengerjaan: {
        ada: boolean;
        label: string;
        nada: string;
        terhubung: boolean | null;
        pelanggaran: number;
        terakhir: string | null;
    };
    sisa: string | null;
};

type Props = {
    ruang: {
        id: number;
        kode: string;
        nama: string | null;
        lokasi: string | null;
        sesi: string | null;
        ujian: string | null;
        jam: string | null;
        berakhir_pada: string | null;
    };
    pelaku: string;
    rekap: {
        total: number;
        hadir: number;
        terlambat: number;
        tidak_hadir: number;
        izin: number;
        pending: number;
    };
    kursi: Kursi[];
};

export default function Presensi({ ruang, rekap, kursi }: Props) {
    const [cari, setCari] = useState('');
    const [pilih, setPilih] = useState<number[]>([]);
    const [kirim, setKirim] = useState(false);

    // Penyaringan di peramban, bukan di server - dan itu kebalikan dari
    // laman Admin yang selalu menyaring di server. Alasannya berbeda
    // datanya: 32 baris sudah ada di peramban sejak laman dibuka, sementara
    // daftar 1.284 siswa tidak pernah dikirim seluruhnya.
    const tampil = useMemo(() => {
        const q = cari.trim().toLowerCase();

        if (q === '') {
            return kursi;
        }

        return kursi.filter(
            (k) =>
                k.nama.toLowerCase().includes(q) ||
                (k.nis ?? '').toLowerCase().includes(q) ||
                String(k.kursi) === q,
        );
    }, [kursi, cari]);

    const belum = kursi.filter((k) => k.status === 'pending');

    const tandaiSemua = () => {
        if (belum.length === 0) {
            return;
        }

        setKirim(true);
        router.post(
            `/pengawas/ruang/${ruang.id}/presensi/massal`,
            { record_ids: belum.map((k) => k.record_id), status: 'hadir' },
            { preserveScroll: true, onFinish: () => setKirim(false) },
        );
    };

    const tandai = (recordId: number, status: string) => {
        setKirim(true);
        router.post(
            `/pengawas/ruang/${ruang.id}/presensi/verifikasi`,
            { record_id: recordId, status },
            { preserveScroll: true, onFinish: () => setKirim(false) },
        );
    };

    const verifikasiTerpilih = () => {
        if (pilih.length === 0) {
            return;
        }

        setKirim(true);
        router.post(
            `/pengawas/ruang/${ruang.id}/presensi/massal`,
            { record_ids: pilih, status: 'hadir' },
            {
                preserveScroll: true,
                onFinish: () => {
                    setKirim(false);
                    setPilih([]);
                },
            },
        );
    };

    const toggle = (id: number) =>
        setPilih((p) => (p.includes(id) ? p.filter((x) => x !== id) : [...p, id]));

    return (
        <KerangkaPonsel
            judul="Presensi ruang"
            tabbar={{ ruangId: ruang.id, aktif: 'presensi' }}
            kiri={
                <>
                    <Chip nada="info">{ruang.kode}</Chip>
                    <span className="min-w-0 truncate text-[11px] text-ink-3">
                        {[ruang.nama, ruang.sesi].filter(Boolean).join(' · ')}
                    </span>
                </>
            }
            kanan={
                <Link
                    href={`/pengawas/ruang/${ruang.id}/token`}
                    className="rounded-wf border border-line bg-surface px-2 py-1 text-[11px] text-ink-2"
                >
                    Token
                </Link>
            }
        >
            <div className="grid grid-cols-5 gap-1">
                <KpiPonsel label="Peserta" nilai={rekap.total} />
                <KpiPonsel label="Hadir" nilai={rekap.hadir} nada="ok" />
                <KpiPonsel label="Telat" nilai={rekap.terlambat} nada="info" />
                <KpiPonsel label="Belum" nilai={rekap.pending} />
                <KpiPonsel
                    label="Izin/alpa"
                    nilai={`${rekap.izin}/${rekap.tidak_hadir}`}
                    nada="warn"
                />
            </div>

            <div className="flex flex-wrap items-center gap-1.5">
                <input
                    value={cari}
                    onChange={(e) => setCari(e.target.value)}
                    placeholder="Cari nama / NIS / no kursi"
                    className="min-w-[8rem] flex-1 rounded-wf border border-line bg-surface px-2 py-1.5 text-xs text-ink outline-none focus:border-accent"
                />

                {/* Tombol ini hanya muncul saat MASIH ada yang belum
                    ditandai. Menampilkannya dalam keadaan yang membuatnya
                    tidak melakukan apa pun adalah cara membuat orang
                    menekannya dua kali. */}
                {belum.length > 0 && (
                    <button
                        type="button"
                        onClick={tandaiSemua}
                        disabled={kirim}
                        className="rounded-wf border border-accent bg-accent px-2.5 py-1.5 text-[11px] font-medium text-on-accent disabled:opacity-50"
                    >
                        Tandai {belum.length} sisa hadir
                    </button>
                )}

                {pilih.length > 0 && (
                    <button
                        type="button"
                        onClick={verifikasiTerpilih}
                        disabled={kirim}
                        className="rounded-wf border border-line bg-surface px-2.5 py-1.5 text-[11px] text-ink disabled:opacity-50"
                    >
                        Verifikasi {pilih.length} terpilih
                    </button>
                )}
            </div>

            <KotakPonsel padat>
                {tampil.length === 0 && (
                    <p className="px-1 py-6 text-center text-xs text-ink-3">
                        {kursi.length === 0
                            ? 'Belum ada peserta yang ditempatkan di ruang ini.'
                            : 'Tidak ada nama yang cocok dengan pencarian itu.'}
                    </p>
                )}

                {tampil.map((k) => {
                    const p = k.pengerjaan;
                    const putus = p.ada && p.terhubung === false;

                    return (
                        <BarisKursi
                            key={k.record_id}
                            kursi={k.kursi}
                            nama={k.nama}
                            nada={putus ? 'bad' : null}
                            identitas={[k.nis, k.kelas].filter(Boolean).join(' · ')}
                            chip={
                                <>
                                    <button
                                        type="button"
                                        onClick={() => toggle(k.record_id)}
                                        title="Pilih untuk verifikasi massal"
                                        className={
                                            'rounded-wf border px-1 text-[10px] ' +
                                            (pilih.includes(k.record_id)
                                                ? 'border-accent bg-accent-soft text-accent'
                                                : 'border-line text-ink-3')
                                        }
                                    >
                                        {pilih.includes(k.record_id) ? '✓' : '○'}
                                    </button>

                                    <Chip nada={k.status_nada as 'ok'}>
                                        {k.status_pendek}
                                    </Chip>

                                    {/* Pengerjaan berasal dari server dan
                                        tidak bisa diketik siapa pun. Selama
                                        Fase 6 seluruhnya "belum mulai" - dan
                                        itu memang keadaannya, bukan angka
                                        karangan. */}
                                    <Chip nada={p.nada as 'mute'}>{p.label}</Chip>

                                    {putus && <Chip nada="bad">koneksi putus</Chip>}
                                    {!p.terhubung && p.ada && p.terakhir && (
                                        <span className="text-[10px] text-ink-3">
                                            {p.terakhir}
                                        </span>
                                    )}
                                </>
                            }
                            tindakan={
                                k.status === 'pending' ? (
                                    <button
                                        type="button"
                                        onClick={() => tandai(k.record_id, 'hadir')}
                                        disabled={kirim}
                                        className="rounded-wf border border-accent bg-accent px-3 py-1.5 text-xs font-medium text-on-accent disabled:opacity-50"
                                    >
                                        Hadir
                                    </button>
                                ) : (
                                    <Link
                                        href={`/pengawas/ruang/${ruang.id}/presensi/${k.peserta_id}`}
                                        className="rounded-wf border border-line bg-surface px-3 py-1.5 text-xs text-ink-2"
                                    >
                                        Ubah
                                    </Link>
                                )
                            }
                        />
                    );
                })}
            </KotakPonsel>

            <p className="px-1 text-center text-[11px] text-ink-3">
                Urut nomor kursi &middot; {tampil.length} dari {kursi.length} ditampilkan
            </p>

            <Inbox>
                Satu ketukan per siswa. <b>Presensi</b> adalah keputusan Anda dan boleh
                diubah; <b>pengerjaan</b> datang dari server dan tidak dapat diketik siapa
                pun.
            </Inbox>
        </KerangkaPonsel>
    );
}

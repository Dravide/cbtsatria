import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import KerangkaPonsel from '@/Layouts/KerangkaPonsel';
import { Chip, Inbox, Peringatan } from '@/Components/ui';
import { KpiPonsel, KotakPonsel } from '@/Components/ponsel';

/**
 * Plate 14 - token sesi saya. `/pengawas/ruang/{id}/token`.
 *
 * =====================================================================
 * Token TERTUTUP sampai tombolnya ditekan
 * =====================================================================
 * Dua alasan, dan keduanya bukan soal gaya.
 *
 * Pertama, plate 12 menyatakannya: "Ditampilkan tertutup sampai Pengawas
 * menekan Tampilkan." Layar ini dipegang sambil berdiri, sering dengan
 * pengawas lain atau peserta di dekatnya, dan token yang selalu terlihat
 * adalah token yang terbaca orang yang tidak sedang membukanya.
 *
 * Kedua - dan ini yang menentukan bentuk teknisnya - kodenya datang lewat
 * FLASH, bukan lewat prop halaman. Prop halaman ikut terkirim di SETIAP
 * respons berikutnya selama sesinya hidup, sementara flash hidup tepat satu
 * respons. Token yang terus ada di props adalah token yang tersimpan di
 * riwayat peramban pengawas.
 *
 * Karena itu DOM ini melakukan satu hal yang tidak biasa: ia MENAHAN kode
 * yang sudah diterimanya sampai tombolnya ditekan. Kode itu sudah ada di
 * memori peramban sejak respons tiba; yang ditahan adalah menampilkannya -
 * dan `scripts/cbt-cek-pintu.mjs` menguji tepat keadaan itu: tidak ada di DOM
 * sebelum ditekan, ada sesudahnya.
 *
 * ---------------------------------------------------------------------
 * "Setiap kali token dibuka, kejadiannya tercatat" - dan itu diberitahukan
 * ---------------------------------------------------------------------
 * Plate 14 menuntutnya sebagai peringatan yang terlihat. Rahasia yang tidak
 * diberitahukan kepada pemegangnya bukan pengamanan, melainkan jebakan.
 */
type Props = {
    ruang: {
        id: number;
        kode: string;
        nama: string | null;
        sesi: string | null;
        ujian: string | null;
    };
    pelaku: string;
    token: {
        id: number;
        berlaku_hingga: string | null;
        berlaku: boolean;
        status: string;
        status_label: string;
        status_nada: string;
    } | null;
    pemakaian: { sudah: number; total: number };
    riwayat: { jam: string | null; oleh: string; ruang: string | null }[];
};

export default function Token({ ruang, token, pemakaian, riwayat }: Props) {
    const { flash } = usePage().props as unknown as {
        flash: { kode_token?: string; token_sesi?: string; galat?: string };
    };

    const [terbuka, setTerbuka] = useState(false);
    const [besar, setBesar] = useState(false);

    const kode = flash?.kode_token ?? null;

    // Kode datang lewat flash, dan flash hanya hidup pada respons yang
    // membawanya. Karena itu kedatangannya adalah SATU-SATUNYA saat yang
    // boleh membuka tampilannya: sebelum tombol ditekan belum ada kode sama
    // sekali, dan sesudahnya kode yang baru tiba justru yang ingin dibaca.
    //
    // Menutup pada kedatangan - seperti sempat ditulis di sini - berarti
    // tokennya tidak pernah bisa dibaca siapa pun: setiap respons yang
    // membawa kode langsung menutupnya lagi.
    useEffect(() => {
        setTerbuka(kode !== null);
        setBesar(false);
    }, [kode]);

    const baca = () => {
        router.post(
            `/pengawas/ruang/${ruang.id}/token/baca`,
            {},
            { preserveScroll: true },
        );
    };

    return (
        <KerangkaPonsel
            judul="Token sesi"
            tabbar={{ ruangId: ruang.id, aktif: 'token' }}
            kiri={
                <>
                    <Chip nada="info">{ruang.kode}</Chip>
                    <span className="min-w-0 truncate text-[11px] text-ink-3">
                        {[ruang.ujian, ruang.sesi].filter(Boolean).join(' · ')}
                    </span>
                </>
            }
            kanan={
                token && (
                    <Chip nada={token.status_nada as 'mute'}>{token.status_label}</Chip>
                )
            }
        >
            {token === null ? (
                <Inbox>
                    Sesi ini belum punya token. Token diterbitkan Admin di laman Token
                    event, dan pengawas tidak dapat menerbitkannya sendiri.
                </Inbox>
            ) : (
                <>
                    <KotakPonsel solid padat>
                        <div className="py-3 text-center">
                            <span className="text-[11px] text-ink-3">
                                Token ujian
                            </span>

                            {/* Kotak ini yang menahan kodenya. Sebelum tombol
                                ditekan ia berisi titik-titik, dan TIDAK ada
                                teks kode di dalam DOM - bukan disembunyikan
                                dengan CSS, melainkan tidak dirender. */}
                            <div
                                className={
                                    'mx-auto mt-1.5 rounded-wf border border-line bg-surface-2 font-mono tracking-[0.3em] text-ink ' +
                                    (besar
                                        ? 'py-4 text-4xl'
                                        : 'w-full max-w-[12rem] py-3 text-2xl')
                                }
                            >
                                {terbuka && kode ? kode : '——————'}
                            </div>

                            <p className="mt-2 text-[11px] text-ink-3">
                                {token.berlaku_hingga
                                    ? `Berlaku sampai ${token.berlaku_hingga}, lalu tidak dapat dipakai lagi`
                                    : 'Berlaku sampai sesinya berakhir'}
                            </p>

                            <div className="mt-2 flex flex-wrap justify-center gap-1.5">
                                {!terbuka ? (
                                    <button
                                        type="button"
                                        onClick={baca}
                                        className="inline-grid min-h-11 place-items-center rounded-wf border border-accent bg-accent px-4 text-sm font-medium text-on-accent"
                                    >
                                        Tampilkan
                                    </button>
                                ) : (
                                    <>
                                        <button
                                            type="button"
                                            onClick={() => setBesar((b) => !b)}
                                            className="inline-grid min-h-11 place-items-center rounded-wf border border-line bg-surface px-3 text-xs text-ink"
                                        >
                                            {besar ? 'Perkecil' : 'Tampilkan besar'}
                                        </button>
                                        <button
                                            type="button"
                                            onClick={baca}
                                            className="inline-grid min-h-11 place-items-center rounded-wf border border-line bg-surface px-3 text-xs text-ink"
                                            title="Menerbitkan ulang tampilan dan mencatat pembacaan baru"
                                        >
                                            Segarkan
                                        </button>
                                    </>
                                )}
                            </div>

                            {/* Terbaca tetapi belum terbuka hanya mungkin
                                terjadi bila kode datang dari respons lain;
                                menyebutkannya mencegah pengawas mengira
                                tombolnya rusak. */}
                            {!terbuka && kode && (
                                <p className="mt-1 text-[11px] text-ink-3">
                                    Token sudah diterima. Tekan Tampilkan untuk
                                    membacanya.
                                </p>
                            )}
                        </div>
                    </KotakPonsel>

                    <KotakPonsel
                        padat
                        judul="Pemakaian token"
                        kanan={
                            <Chip nada={pemakaian.sudah >= pemakaian.total && pemakaian.total > 0 ? 'ok' : 'mute'}>
                                {pemakaian.sudah} / {pemakaian.total}
                            </Chip>
                        }
                    >
                        <div className="grid grid-cols-2 gap-1">
                            <KpiPonsel label="Sudah masuk" nilai={pemakaian.sudah} nada="ok" />
                            <KpiPonsel
                                label="Belum"
                                nilai={pemakaian.total - pemakaian.sudah}
                                nada={pemakaian.total - pemakaian.sudah > 0 ? 'warn' : undefined}
                            />
                        </div>

                        <p className="text-[11px] text-ink-3">
                            Pemakaian dihitung dari peserta ruang ini yang sudah membuka
                            ujiannya.
                        </p>
                    </KotakPonsel>

                    <KotakPonsel padat judul="Riwayat pembukaan">
                        {riwayat.length === 0 && (
                            <p className="px-1 py-4 text-center text-xs text-ink-3">
                                Token ini belum pernah dibuka.
                            </p>
                        )}

                        {riwayat.map((r, i) => (
                            <div
                                key={i}
                                className="flex items-center gap-2 border-b border-line/60 py-1.5 last:border-0"
                            >
                                <span className="w-11 shrink-0 font-mono text-[11px] text-ink-3">
                                    {r.jam ?? '—'}
                                </span>
                                <span className="min-w-0 flex-1 truncate text-xs text-ink">
                                    {r.oleh}
                                </span>
                                {r.ruang && <Chip nada="mute">{r.ruang}</Chip>}
                            </div>
                        ))}
                    </KotakPonsel>

                    <Peringatan>
                        Setiap kali token dibuka, kejadiannya tercatat atas nama Anda.
                        Jangan tunjukkan layar ini kepada siswa di luar keperluan pembukaan
                        sesi.
                    </Peringatan>
                </>
            )}
        </KerangkaPonsel>
    );
}

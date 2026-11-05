import { router } from '@inertiajs/react';
import { useState } from 'react';
import KerangkaPonsel from '@/Layouts/KerangkaPonsel';
import { Chip, Inbox, Peringatan } from '@/Components/ui';
import { BarisKursi, KotakPonsel } from '@/Components/ponsel';

/**
 * Plate 17 - koreksi presensi. `/pengawas/ruang/{id}/koreksi`.
 *
 * =====================================================================
 * Mengapa koreksi DIPISAH dari penandaan biasa
 * =====================================================================
 * Selama sesi berjalan, menandai peserta hadir adalah keputusan lapangan:
 * pengawas melihat sendiri orangnya berdiri di depan kursi, dan keringanan
 * itu pantas. Setelah sesi berakhir, mengubah presensi berarti menulis ulang
 * fakta yang sudah lewat, sering kali atas permintaan pihak lain - dan
 * karena itu ia menuntut alasan tertulis.
 *
 * Yang membedakan keduanya bukan siapa pelakunya, melainkan apakah sesinya
 * masih berjalan. Itu sebabnya chip di kepala laman ini menyebut keadaan
 * sesinya: ia yang mengubah bobot perbuatannya, bukan jenis akunnya.
 *
 * ---------------------------------------------------------------------
 * Dua hal yang tidak pernah dilakukan laman ini
 * ---------------------------------------------------------------------
 * Mengubah attempt yang sudah ada, dan menghapus baris presensi lama. Yang
 * pertama akan merusak jawaban peserta; yang kedua akan membuat jejaknya
 * hilang justru saat paling dibutuhkan. Koreksi menulis baris
 * `attendance_logs` BARU, dan baris lama tetap terbaca.
 *
 * ---------------------------------------------------------------------
 * Dampak koreksi dinyatakan sebelum disimpan, bukan sesudah
 * ---------------------------------------------------------------------
 * Plate 17 menampilkan satu kotak "Dampak koreksi ini". Ia ada karena
 * koreksi presensi dapat mengubah siapa yang boleh mengikuti ujian - dan
 * akibat sebesar itu harus terbaca SEBELUM tombolnya ditekan.
 */
type Kursi = {
    record_id: number;
    peserta_id: number;
    kursi: number;
    nama: string;
    nis: string | null;
    status: string;
    status_label: string;
    status_nada: string;
    perubahan: number;
};

type Props = {
    ruang: { id: number; kode: string; nama: string | null; sesi: string | null; ujian: string | null };
    pelaku: string;
    sesi_berakhir: boolean;
    kursi: Kursi[];
    opsi: Record<string, string>;
};

export default function Koreksi({ ruang, sesi_berakhir, kursi, opsi }: Props) {
    const [pilih, setPilih] = useState<Kursi | null>(null);
    const [status, setStatus] = useState('');
    const [alasan, setAlasan] = useState('');
    const [kirim, setKirim] = useState(false);

    const buka = (k: Kursi) => {
        setPilih(k);
        setStatus(k.status);
        setAlasan('');
    };

    const simpan = (e: React.FormEvent) => {
        e.preventDefault();

        if (pilih === null || alasan.trim().length < 4) {
            return;
        }

        setKirim(true);
        router.post(
            `/pengawas/ruang/${ruang.id}/koreksi/${pilih.peserta_id}`,
            { status, alasan },
            {
                preserveScroll: true,
                onFinish: () => {
                    setKirim(false);
                    setPilih(null);
                    setAlasan('');
                },
            },
        );
    };

    // Status yang membuka gerbang, menurut StatusPresensi::membukaGerbang().
    // Dihitung di sini HANYA untuk menyatakan akibatnya di layar; yang
    // memutuskan tetap server, dan aturannya hidup di enum supaya Fase 7
    // tidak menemukan aturan baru.
    const membuka = (s: string) => s === 'hadir' || s === 'terlambat';

    return (
        <KerangkaPonsel
            judul="Koreksi presensi"
            kiri={
                <>
                    <Chip nada="info">{ruang.kode}</Chip>
                    <span className="min-w-0 truncate text-[11px] text-ink-3">
                        Koreksi presensi &middot; {ruang.sesi ?? 'tanpa sesi'}
                    </span>
                </>
            }
            kanan={
                <Chip nada={sesi_berakhir ? 'warn' : 'info'}>
                    {sesi_berakhir ? 'sesi berakhir' : 'sesi berjalan'}
                </Chip>
            }
        >
            <Peringatan>
                Mengubah presensi setelah sesi berakhir akan tercatat sebagai koreksi atas
                nama Anda, beserta alasannya. Bukan lagi penandaan biasa.
            </Peringatan>

            {pilih === null ? (
                <KotakPonsel padat>
                    {kursi.length === 0 && (
                        <p className="px-1 py-6 text-center text-xs text-ink-3">
                            Belum ada peserta yang ditempatkan di ruang ini.
                        </p>
                    )}

                    {kursi.map((k) => (
                        <BarisKursi
                            key={k.record_id}
                            kursi={k.kursi}
                            nama={k.nama}
                            identitas={k.nis}
                            chip={
                                <>
                                    <Chip nada={k.status_nada as 'mute'}>{k.status_label}</Chip>
                                    {k.perubahan > 0 && (
                                        <span className="text-[10px] text-ink-3">
                                            {k.perubahan} perubahan
                                        </span>
                                    )}
                                </>
                            }
                            tindakan={
                                <button
                                    type="button"
                                    onClick={() => buka(k)}
                                    className="rounded-wf border border-line bg-surface px-3 py-1.5 text-xs text-ink-2"
                                >
                                    Ubah
                                </button>
                            }
                        />
                    ))}
                </KotakPonsel>
            ) : (
                <form onSubmit={simpan} className="space-y-3">
                    <KotakPonsel solid padat>
                        <div className="flex items-center gap-2">
                            <b className="font-display text-sm text-ink">{pilih.nama}</b>
                            <span className="ml-auto">
                                <Chip nada="mute">no. kursi {pilih.kursi}</Chip>
                            </span>
                        </div>

                        <div className="grid grid-cols-2 gap-1.5">
                            <div>
                                <span className="mb-1 block text-[10px] tracking-wide text-ink-3 uppercase">
                                    Status presensi
                                </span>
                                <div className="rounded-wf border border-line bg-surface-2 px-2 py-1.5 text-xs text-ink-2">
                                    {pilih.status_label}
                                </div>
                            </div>

                            <label>
                                <span className="mb-1 block text-[10px] tracking-wide text-ink-3 uppercase">
                                    Ubah menjadi
                                </span>
                                <select
                                    value={status}
                                    onChange={(e) => setStatus(e.target.value)}
                                    className="w-full rounded-wf border border-line bg-surface px-2 py-1.5 text-xs text-ink outline-none focus:border-accent"
                                >
                                    {Object.entries(opsi).map(([nilai, label]) => (
                                        <option key={nilai} value={nilai}>
                                            {label}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        </div>

                        <label className="block">
                            <span className="mb-1 block text-[10px] tracking-wide text-ink-3 uppercase">
                                Alasan koreksi (wajib)
                            </span>
                            <textarea
                                value={alasan}
                                onChange={(e) => setAlasan(e.target.value)}
                                rows={3}
                                className="w-full rounded-wf border border-line bg-surface px-2 py-1.5 text-xs text-ink outline-none focus:border-accent"
                            />
                            {alasan.length > 0 && alasan.trim().length < 4 && (
                                <span className="mt-1 block text-[11px] text-bad">
                                    Alasan koreksi terlalu pendek untuk bisa
                                    dipertanggungjawabkan.
                                </span>
                            )}
                        </label>

                        <div className="flex flex-wrap gap-1.5">
                            <button
                                type="submit"
                                disabled={
                                    kirim ||
                                    alasan.trim().length < 4 ||
                                    status === pilih.status
                                }
                                className="rounded-wf border border-accent bg-accent px-3 py-2 text-xs font-medium text-on-accent disabled:cursor-not-allowed disabled:border-ink-3/40 disabled:bg-ink-3/40"
                            >
                                Simpan koreksi
                            </button>
                            <button
                                type="button"
                                onClick={() => setPilih(null)}
                                className="rounded-wf border border-line bg-surface px-3 py-2 text-xs text-ink-2"
                            >
                                Batal
                            </button>
                        </div>
                    </KotakPonsel>

                    <KotakPonsel
                        solid
                        padat
                        judul="Dampak koreksi ini"
                        kanan={
                            <Chip nada={membuka(status) ? 'bad' : 'mute'}>
                                {membuka(status) ? 'mengubah kelayakan' : 'tidak membuka gerbang'}
                            </Chip>
                        }
                    >
                        <p className="text-[11px] text-ink-2">
                            {membuka(status)
                                ? `${opsi[status] ?? status} membuka gerbang ujian: attempt boleh dibuat. Koreksi tidak menghapus jawaban yang sudah ada.`
                                : `${opsi[status] ?? status} tidak membuka gerbang ujian, sehingga peserta ini tidak dapat memulai ujiannya.`}
                        </p>

                        <p className="text-[11px] text-ink-3">
                            Baris presensi lama tidak dihapus. Koreksi ditulis sebagai
                            perubahan baru dengan nama Anda dan alasan di atas.
                        </p>
                    </KotakPonsel>
                </form>
            )}

            <Inbox>
                Koreksi juga dipisah dari penandaan biasa karena keduanya beda sifat.
                Selama sesi berjalan, menandai hadir adalah keputusan lapangan. Setelah
                sesi berakhir, ia menulis ulang fakta yang sudah lewat.
            </Inbox>
        </KerangkaPonsel>
    );
}

import { Chip, Kotak } from '@/Components/ui';

/**
 * Mata pelajaran event - termasuk yang BELUM dijadwalkan.
 *
 * =====================================================================
 * Mengapa daftar ini terpisah dari tabel ruang di bawahnya
 * =====================================================================
 * Baik Presensi maupun Monitoring menyusun barisnya dari RUANG-SESI. Mapel
 * yang ujiannya belum dijadwalkan tidak punya satu pun ruang-sesi, sehingga
 * ia tidak muncul di sana sama sekali - dan laman menjadi terlihat lengkap
 * padahal baru sebagian mapel yang terjadwal.
 *
 * Yang hilang justru informasi yang dicari saat laman ini dibuka: mapel mana
 * yang belum punya jadwal. Karena itu daftar ini membaca `event_subjects`
 * dan tetap menggambar baris untuk mapel yang belum dijadwalkan.
 *
 * =====================================================================
 * Tiga keadaan, dan ketiganya menuntut tindakan Admin yang berbeda
 * =====================================================================
 *   terjadwal   - ujian dan sesinya ada. Kolom presensinya bermakna.
 *   belum_sesi  - ujiannya sudah dibuat, jadwalnya belum. Langkahnya
 *                 "Jadwal & sesi".
 *   belum_ujian - mapelnya terdaftar di event, ujiannya belum dibuat.
 *                 Langkahnya "Jadwal & sesi" juga, tetapi dari nol.
 *
 * Menggabungkan dua keadaan terakhir akan memberi Admin satu label untuk dua
 * pekerjaan yang berbeda.
 */
export type MapelEvent = {
    subject_id: number;
    kode: string | null;
    nama: string | null;
    ujian: string[];
    sesi: { id: number; label: string; status: string; status_label: string; jam: string; ruang: number }[];
    jumlah_sesi: number;
    keadaan: 'terjadwal' | 'belum_sesi' | 'belum_ujian';
    peserta: number;
    hadir: number;
    tidak_hadir: number;
    izin: number;
    belum_diverifikasi: number;
};

const LABEL_KEADAAN: Record<MapelEvent['keadaan'], string> = {
    terjadwal: 'terjadwal',
    belum_sesi: 'belum dijadwalkan',
    belum_ujian: 'belum ada ujian',
};

const NADA_KEADAAN: Record<MapelEvent['keadaan'], 'ok' | 'warn' | 'mute'> = {
    terjadwal: 'ok',
    // Dua keadaan ini memakai `warn`/`mute` yang berbeda meski keduanya
    // berarti "belum siap": yang pertama sudah punya ujian dan hanya
    // menunggu jadwal, yang kedua belum punya apa pun. Satu nada untuk
    // keduanya membuat baris yang paling tertinggal tampak sama dengan yang
    // hampir selesai.
    belum_sesi: 'warn',
    belum_ujian: 'mute',
};

export default function DaftarMapel({
    mapel,
    /** Menampilkan kolom presensi. Monitoring tidak punya angka presensi. */
    denganPresensi,
}: {
    mapel: MapelEvent[];
    denganPresensi: boolean;
}) {
    if (mapel.length === 0) {
        return null;
    }

    const terjadwal = mapel.filter((m) => m.keadaan === 'terjadwal').length;

    return (
        <Kotak
            judul="Mata pelajaran event"
            aksi={
                <>
                    <Chip nada={terjadwal === mapel.length ? 'ok' : 'warn'}>
                        {terjadwal} dari {mapel.length} terjadwal
                    </Chip>
                    <span className="text-[11px] text-ink-3">
                        termasuk yang belum dijadwalkan
                    </span>
                </>
            }
        >
            <div className="overflow-x-auto">
                <table className="w-full min-w-[36rem] text-xs">
                    <thead>
                        <tr className="border-b border-line text-left text-[11px] text-ink-3">
                            <th className="px-4 py-2 font-medium">Mapel</th>
                            <th className="px-2 py-2 font-medium">Keadaan</th>
                            <th className="px-2 py-2 font-medium">Sesi</th>
                            {denganPresensi && (
                                <>
                                    <th className="px-2 py-2 text-right font-medium">Peserta</th>
                                    <th className="px-2 py-2 text-right font-medium">Hadir</th>
                                    <th className="px-2 py-2 text-right font-medium">Tidak hadir</th>
                                    <th className="px-2 py-2 text-right font-medium">Belum diverifikasi</th>
                                </>
                            )}
                        </tr>
                    </thead>
                    <tbody>
                        {mapel.map((m) => (
                            <tr
                                key={m.subject_id}
                                className="border-b border-line/60 last:border-0 hover:bg-surface-2/40"
                            >
                                <td className="px-4 py-2">
                                    <span className="font-mono text-[11px] text-ink-3">
                                        {m.kode}
                                    </span>
                                    <span className="ml-2 text-ink">{m.nama}</span>
                                    {m.ujian.length > 0 && (
                                        <span className="mt-0.5 block text-[11px] text-ink-3">
                                            {m.ujian.join(' · ')}
                                        </span>
                                    )}
                                </td>
                                <td className="px-2 py-2">
                                    <Chip nada={NADA_KEADAAN[m.keadaan]}>
                                        {LABEL_KEADAAN[m.keadaan]}
                                    </Chip>
                                </td>
                                <td className="px-2 py-2">
                                    {m.sesi.length === 0 ? (
                                        <span className="text-ink-3">&mdash;</span>
                                    ) : (
                                        <span className="flex flex-wrap gap-1">
                                            {m.sesi.map((s) => (
                                                <span
                                                    key={s.id}
                                                    className="rounded-wf border border-line px-1.5 py-0.5 text-[11px] text-ink-2"
                                                    title={`${s.jam} · ${s.ruang} ruang`}
                                                >
                                                    {s.label} {s.status_label}
                                                </span>
                                            ))}
                                        </span>
                                    )}
                                </td>
                                {denganPresensi && (
                                    <>
                                        <td className="px-2 py-2 text-right font-mono">
                                            {m.peserta}
                                        </td>
                                        <td className="px-2 py-2 text-right font-mono text-ok">
                                            {m.hadir}
                                        </td>
                                        <td
                                            className={
                                                'px-2 py-2 text-right font-mono ' +
                                                (m.tidak_hadir > 0 ? 'text-bad' : 'text-ink-3')
                                            }
                                        >
                                            {m.tidak_hadir}
                                        </td>
                                        <td className="px-2 py-2 text-right font-mono text-ink-3">
                                            {m.belum_diverifikasi}
                                        </td>
                                    </>
                                )}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <p className="border-t border-line px-4 py-2 text-[11px] leading-relaxed text-ink-3">
                Tabel di bawah disusun dari ruang-sesi, sehingga hanya memuat mapel yang sudah
                dijadwalkan. Daftar ini memuat seluruh mapel event &mdash; yang belum
                dijadwalkan tidak punya baris di sana, dan itulah yang perlu dikerjakan
                berikutnya di <span className="font-medium">Jadwal &amp; sesi</span>.
            </p>
        </Kotak>
    );
}

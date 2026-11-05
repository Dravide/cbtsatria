import { router } from '@inertiajs/react';
import KerangkaSiswa from '@/Layouts/KerangkaSiswa';
import { Chip, Inbox } from '@/Components/ui';
import { KpiPonsel, KotakPonsel } from '@/Components/ponsel';

/**
 * Riwayat pelanggaran saya - wireframe-peran.html plate 29.
 *
 * =====================================================================
 * Siswa membaca catatannya sendiri, dan itu keputusan yang disengaja
 * =====================================================================
 * Plate 29 menulis konsekuensinya apa adanya: catatan yang dapat dibaca oleh
 * yang bersangkutan akan selalu ditanyakan - dan itu justru tujuannya.
 * Catatan yang tidak dapat dibantah dengan data adalah catatan yang tidak
 * layak dipakai untuk menjatuhkan sanksi.
 *
 * Karena itu yang dikirim ke laman ini adalah catatan yang SAMA dengan yang
 * dibaca pengawas di plate 16: jam, jenis, tingkat, dan tindakannya. Bukan
 * versi yang diperhalus.
 *
 * ---------------------------------------------------------------------
 * HANYA BACA, dan tidak ada satu pun tombol tulis di laman ini
 * ---------------------------------------------------------------------
 * `exam_violations` append-only sejak migrasinya, dan tidak ada rute yang
 * mengubahnya. Yang ditawarkan plate 29 adalah CARA MENGAJUKAN KEBERATAN -
 * menghubungi Pengawas ruang atau Admin sekolah - dan itu kalimat, bukan
 * tombol yang tidak melakukan apa-apa.
 *
 * ---------------------------------------------------------------------
 * Tindakan pengawas tidak ikut dihitung sebagai kejadian
 * ---------------------------------------------------------------------
 * Angka "Total kejadian" dihitung server dari pelanggaran siswa saja. Baris
 * "Kunci dibuka pengawas" tetap TAMPIL di riwayat - ia bagian dari apa yang
 * terjadi pada ujian itu - tetapi ia diberi label tingkatnya sendiri dan
 * tidak menambah hitungan. Menambahkannya berarti menuduh anak atas
 * perbuatan orang dewasa.
 */

type OpsiEvent = { id: number | null; nama: string | null; jumlah: number };

type Riwayat = {
    id: number;
    jam: string | null;
    tanggal: string | null;
    jenis: string;
    tindakan: string;
    tingkat: string;
    nada: string;
    mapel: string | null;
    durasi: string | null;
    tindakan_pengawas: boolean;
};

type Props = {
    nama: string;
    event: OpsiEvent[];
    terpilih: number | null;
    angka: { total: number; terberat: number; pernah_dikunci: number };
    riwayat: Riwayat[];
};

export default function Pelanggaran({ nama, event, terpilih, angka, riwayat }: Props) {
    const ganti = (id: number | null) => {
        // Penyaringan dikerjakan server, bukan di peramban: daftar
        // pelanggaran satu sekolah bisa panjang, dan menyaringnya di sini
        // berarti mengirimkan seluruh riwayat lebih dulu supaya bisa
        // disaring keluar.
        router.get('/siswa/pelanggaran', id === null ? {} : { event: id }, { preserveScroll: true });
    };

    return (
        <KerangkaSiswa
            judul="Riwayat pelanggaran"
            tabbarAktif="pelanggaran"
            kiri={
                <span className="truncate text-[12px] text-ink">Riwayat · {nama}</span>
            }
            kanan={<Chip nada="mute">hanya baca</Chip>}
        >
            {event.length > 1 && (
                <div className="flex flex-wrap gap-1.5">
                    {event.map((e) => (
                        <button
                            key={e.id ?? 'tanpa-event'}
                            type="button"
                            onClick={() => ganti(e.id)}
                            className={
                                'rounded-wf border px-2.5 py-1 text-[11px] ' +
                                (e.id === terpilih
                                    ? 'border-accent bg-accent-soft text-accent'
                                    : 'border-line bg-surface text-ink-2')
                            }
                        >
                            {e.nama ?? 'Tanpa nama'}
                            {e.jumlah > 0 && (
                                <span className="ml-1 font-mono text-ink-3">{e.jumlah}</span>
                            )}
                        </button>
                    ))}
                </div>
            )}

            <div className="grid grid-cols-3 gap-1.5">
                <KpiPonsel
                    label="Total kejadian"
                    nilai={angka.total}
                    nada={angka.total > 0 ? 'warn' : 'ok'}
                />
                {/* "Terberat" adalah tingkat keberatan satu PERISTIWA (1..5),
                    bukan tingkat eskalasi (0..3). Dua hal berbeda yang pernah
                    tertukar di blueprint - lihat ExamViolation::labelTingkat(). */}
                <KpiPonsel label="Terberat" nilai={angka.terberat} nada={angka.terberat >= 5 ? 'bad' : undefined} />
                <KpiPonsel
                    label="Pernah dikunci"
                    nilai={angka.pernah_dikunci}
                    nada={angka.pernah_dikunci > 0 ? 'bad' : undefined}
                />
            </div>

            {riwayat.length === 0 ? (
                <KotakPonsel padat>
                    <p className="py-6 text-center text-[12px] text-ink-3">
                        Tidak ada catatan pelanggaran untuk event ini.
                    </p>
                </KotakPonsel>
            ) : (
                <KotakPonsel padat>
                    <div className="space-y-0">
                        {riwayat.map((r) => (
                            <div
                                key={r.id}
                                className="border-b border-line/60 py-2.5 last:border-0"
                            >
                                <div className="flex items-center gap-2">
                                    <span className="min-w-0 flex-1 truncate text-[12px] text-ink">
                                        {r.jenis}
                                    </span>
                                    <span className="shrink-0 font-mono text-[11px] text-ink-3">
                                        {r.jam ?? '—'}
                                    </span>
                                    <Chip nada={r.nada as never}>{r.tingkat}</Chip>
                                </div>
                                <div className="mt-0.5 flex items-center gap-2 text-[10px] text-ink-3">
                                    <span className="min-w-0 flex-1 truncate">
                                        {[r.mapel, r.tanggal, r.durasi].filter(Boolean).join(' · ')}
                                    </span>
                                    <span className="shrink-0">{r.tindakan}</span>
                                </div>
                            </div>
                        ))}
                    </div>
                </KotakPonsel>
            )}

            <Inbox>
                Anda dapat mengajukan keberatan atas catatan ini kepada Pengawas ruang atau Admin
                sekolah. Catatan pelanggaran tidak dapat dihapus.
            </Inbox>
        </KerangkaSiswa>
    );
}

import { Link } from '@inertiajs/react';
import KerangkaSiswa from '@/Layouts/KerangkaSiswa';

/**
 * Papan peringkat kelas - wireframe-waktu-luang.html plate 03.
 * `/siswa/luang/peringkat`.
 *
 * =====================================================================
 * Baris pemain sendiri selalu terlihat
 * =====================================================================
 * Bila peringkatnya di luar sepuluh teratas, barisnya ditempel di bawah
 * daftar. Papan peringkat yang membuat pemain tidak menemukan namanya
 * sendiri adalah papan peringkat yang berhenti dibuka - dan anak yang baru
 * pertama kali main hampir selalu berada di luar sepuluh teratas.
 *
 * =====================================================================
 * Peringkat dari REKOR, bukan dari total
 * =====================================================================
 * Total akan membuat anak yang bermain paling lama selalu menang, dan
 * permainan berhenti menjadi soal keterampilan. Itu juga alasan
 * `PapanPeringkatLuang` memilih satu baris per siswa sebelum mengurutkan.
 */

type Baris = {
    student_id: number;
    nama: string;
    score: number;
    peringkat: number;
};

type Props = {
    kelas: string | null;
    papan: Baris[];
    ringkas: { peringkat: number | null; pemain: number; rekor: number | null };
    saya: { student_id: number; nama: string; peringkat: number | null; score: number | null };
};

export default function LuangPeringkat({ kelas, papan, ringkas, saya }: Props) {
    const milikSaya = (id: number) => id === saya.student_id;

    // Baris sendiri hanya ditempel bila ia TIDAK ada di daftar - dan
    // `peringkat === null` berarti ia bahkan belum punya skor yang diterima,
    // yang merupakan keadaan ketiga dan butuh kalimatnya sendiri.
    const diDaftar = papan.some((b) => milikSaya(b.student_id));

    return (
        <KerangkaSiswa
            judul="Papan peringkat"
            bilah
            kiri={<span className="truncate text-[12px] font-medium text-ink">Papan peringkat</span>}
            kanan={
                kelas ? (
                    <span className="border border-line-2 px-1.5 py-0.5 text-[11px] text-ink-2">
                        {kelas}
                    </span>
                ) : undefined
            }
        >
            <div className="flex items-stretch gap-2">
                <div className="flex-1 border border-line bg-surface px-2.5 py-2">
                    <p className="text-[11px] text-ink-3">Rekor saya</p>
                    <p className="angka text-lg">
                        {ringkas.rekor === null ? '—' : ringkas.rekor.toLocaleString('id-ID')}
                    </p>
                </div>
                <div className="flex-1 border border-line bg-surface px-2.5 py-2">
                    <p className="text-[11px] text-ink-3">Peringkat</p>
                    <p className="angka text-lg">{ringkas.peringkat ?? '—'}</p>
                </div>
                <div className="flex-1 border border-line bg-surface px-2.5 py-2">
                    <p className="text-[11px] text-ink-3">Pemain</p>
                    <p className="angka text-lg">{ringkas.pemain}</p>
                </div>
            </div>

            {papan.length === 0 ? (
                <p className="border border-line bg-surface px-2.5 py-2 text-[12px] leading-relaxed text-ink-2">
                    Belum ada yang mencatat skor di kelas ini. Rekor pertama yang tersimpan akan
                    langsung menempati peringkat satu.
                </p>
            ) : (
                <ol className="overflow-hidden border border-line bg-surface">
                    {papan.map((b) => (
                        <li
                            key={b.student_id}
                            className={
                                'flex items-baseline gap-2 border-b border-line px-2.5 py-2 last:border-b-0 ' +
                                (milikSaya(b.student_id) ? 'bg-accent-soft' : '')
                            }
                        >
                            <span className="w-5 shrink-0 font-mono text-[11px] text-ink-3 tabular-nums">
                                {b.peringkat}
                            </span>
                            <span className="min-w-0 flex-1 truncate text-[12px]">
                                {milikSaya(b.student_id) ? <b>{b.nama} (Anda)</b> : b.nama}
                            </span>
                            <span className="angka shrink-0 text-[12px] tabular-nums">
                                {b.score.toLocaleString('id-ID')}
                            </span>
                        </li>
                    ))}
                </ol>
            )}

            {/* Baris sendiri di luar sepuluh teratas - ditempel, bukan
                dihilangkan. Lihat catatan berkas ini. */}
            {!diDaftar && saya.score !== null && (
                <div className="border border-accent/40 bg-accent-soft px-2.5 py-2">
                    <p className="mb-1 text-[11px] text-ink-3">Posisi Anda</p>
                    <div className="flex items-baseline gap-2">
                        <span className="w-5 shrink-0 font-mono text-[11px] text-ink-3 tabular-nums">
                            {saya.peringkat ?? '—'}
                        </span>
                        <span className="min-w-0 flex-1 truncate text-[12px]">
                            <b>{saya.nama}</b>
                        </span>
                        <span className="angka shrink-0 text-[12px] tabular-nums">
                            {saya.score.toLocaleString('id-ID')}
                        </span>
                    </div>
                </div>
            )}

            {saya.score === null && (
                <p className="border border-line bg-surface px-2.5 py-2 text-[12px] leading-relaxed text-ink-2">
                    Anda belum punya skor yang tercatat. Peringkat baru muncul setelah satu
                    permainan selesai dan skornya diperiksa server.
                </p>
            )}

            <p className="text-[11px] leading-relaxed text-ink-3">
                Peringkat dihitung dari rekor tertinggi, bukan total. Total membuat anak yang
                bermain paling lama selalu menang, dan permainan berhenti menjadi soal
                keterampilan.
            </p>

            <Link
                href="/siswa/luang"
                className="block border border-accent bg-accent px-3 py-2 text-center text-[12px] font-medium text-on-accent"
            >
                Kembali ke permainan
            </Link>
        </KerangkaSiswa>
    );
}

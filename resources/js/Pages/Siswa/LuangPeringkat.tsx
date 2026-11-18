import { Link } from '@inertiajs/react';
import KerangkaSiswa from '@/Layouts/KerangkaSiswa';

/**
 * Papan peringkat kelas - wireframe-waktu-luang.html plate 03.
 * `/siswa/luang/peringkat`
 *
 * Sejak pemisahan skor, laman ini menampilkan tab untuk memilih antara
 * "Lompat Rintangan" dan "Hitung Cepat (60d)". Server mengirim data untuk
 * satu game type berdasarkan query param `?game=`.
 */

type Baris = {
    student_id: number;
    nama: string;
    score: number;
    peringkat: number;
};

type Props = {
    kelas: string | null;
    gameType: 'lompat' | 'hitung_cepat';
    papan: Baris[];
    ringkas: { peringkat: number | null; pemain: number; rekor: number | null };
    saya: { student_id: number; nama: string; peringkat: number | null; score: number | null };
};

const TAB_GAME: { kunci: 'lompat' | 'hitung_cepat'; label: string }[] = [
    { kunci: 'lompat', label: 'Lompat Rintangan' },
    { kunci: 'hitung_cepat', label: 'Hitung Cepat (60d)' },
];

export default function LuangPeringkat({ kelas, gameType, papan, ringkas, saya }: Props) {
    const milikSaya = (id: number) => id === saya.student_id;

    // Baris sendiri hanya ditempel bila ia TIDAK ada di daftar - dan
    // `peringkat === null` berarti ia bahkan belum punya skor yang diterima,
    // yang merupakan keadaan ketiga dan butuh kalimatnya sendiri.
    const diDaftar = papan.some((b) => milikSaya(b.student_id));

    return (
        <KerangkaSiswa
            judul="Papan peringkat"
            tabbarAktif="luang"
            luang
            bilah
            kiri={
                <div className="flex items-center gap-2">
                    <Link
                        href="/siswa/luang"
                        className="rounded border border-line bg-surface px-2 py-0.5 text-[11px] font-medium text-ink-2 hover:bg-surface-2 hover:text-ink"
                    >
                        ← Main
                    </Link>
                    <span className="truncate text-[12px] font-medium text-ink">Papan peringkat</span>
                </div>
            }
            kanan={
                kelas ? (
                    <span className="border border-line-2 px-1.5 py-0.5 text-[11px] text-ink-2">
                        {kelas}
                    </span>
                ) : undefined
            }
        >
            {/* Tab pilih game */}
            <div className="flex gap-1.5">
                {TAB_GAME.map(({ kunci, label }) => (
                    <Link
                        key={kunci}
                        href={`/siswa/luang/peringkat?game=${kunci}`}
                        className={
                            'flex-1 rounded-wf border px-3 py-1.5 text-center text-xs font-medium transition-colors ' +
                            (gameType === kunci
                                ? 'border-accent bg-accent-soft text-accent'
                                : 'border-line bg-surface text-ink-2 hover:bg-surface-2')
                        }
                    >
                        {label}
                    </Link>
                ))}
            </div>

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

            <div className="space-y-2">
                <Link
                    href="/siswa/luang"
                    className="block border border-accent bg-accent px-3 py-2 text-center text-[12px] font-medium text-on-accent hover:bg-accent/90"
                >
                    Kembali ke permainan
                </Link>
                <Link
                    href="/siswa"
                    className="block border border-line bg-surface px-3 py-2 text-center text-[12px] font-medium text-ink-2 hover:bg-surface-2"
                >
                    Kembali ke ujian
                </Link>
            </div>
        </KerangkaSiswa>
    );
}

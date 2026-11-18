import { useCallback, useEffect, useRef, useState } from 'react';

type SoalAritmetika = {
    teks: string;
    jawaban: number;
    pilihan: number[];
};

const DURASI_DETIK = 60;

/**
 * Bangkitkan satu soal aritmetika sesuai level kesulitan saat ini.
 */
function buatSoal(jumlahBenar: number): SoalAritmetika {
    const level = jumlahBenar >= 18 ? 3 : jumlahBenar >= 8 ? 2 : 1;
    const ops =
        level === 1
            ? (['+', '-'] as const)
            : level === 2
              ? (['+', '-', '×'] as const)
              : (['+', '-', '×', '÷'] as const);

    const op = ops[Math.floor(Math.random() * ops.length)];
    let a = 0;
    let b = 0;
    let jawaban = 0;

    if (op === '+') {
        const maks = level === 1 ? 25 : level === 2 ? 50 : 90;
        a = randInt(2, maks);
        b = randInt(2, maks);
        jawaban = a + b;
    } else if (op === '-') {
        const maks = level === 1 ? 30 : level === 2 ? 60 : 99;
        b = randInt(2, Math.floor(maks / 2));
        a = randInt(b + 1, maks);
        jawaban = a - b;
    } else if (op === '×') {
        const maks = level === 2 ? 12 : 15;
        a = randInt(2, maks);
        b = randInt(2, 12);
        jawaban = a * b;
    } else {
        b = randInt(2, 12);
        jawaban = randInt(2, 15);
        a = b * jawaban;
    }

    const opsi = new Set<number>([jawaban]);
    const selisihDekat = [-10, -3, -2, -1, 1, 2, 3, 10];

    while (opsi.size < 4) {
        const geser = selisihDekat[Math.floor(Math.random() * selisihDekat.length)];
        const kandidat = jawaban + geser;

        if (kandidat >= 0) {
            opsi.add(kandidat);
        } else {
            opsi.add(jawaban + randInt(1, 12));
        }
    }

    const pilihan = Array.from(opsi);
    for (let i = pilihan.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [pilihan[i], pilihan[j]] = [pilihan[j], pilihan[i]];
    }

    return {
        teks: `${a} ${op} ${b}`,
        jawaban,
        pilihan,
    };
}

function randInt(min: number, max: number): number {
    return Math.floor(Math.random() * (max - min + 1)) + min;
}

export function HitungCepat({
    habis,
    habisKuota,
    kuotaBatas,
    sesiLabel,
    padaSelesai,
}: {
    habis: boolean;
    habisKuota: boolean;
    kuotaBatas: number;
    sesiLabel?: string | null;
    padaSelesai: (skor: number, durasiMs: number) => void;
}) {
    const [fase, setFase] = useState<'siap' | 'main' | 'selesai'>('siap');
    const [sisa, setSisa] = useState(DURASI_DETIK);
    const [soal, setSoal] = useState<SoalAritmetika>(() => buatSoal(0));
    const [skor, setSkor] = useState(0);
    const [benar, setBenar] = useState(0);
    const [total, setTotal] = useState(0);
    const [streak, setStreak] = useState(0);
    const [streakMaks, setStreakMaks] = useState(0);
    const [umpan, setUmpan] = useState<'ok' | 'salah' | null>(null);

    const mulaiPadaRef = useRef<number>(0);
    const skorRef = useRef(0);
    const selesaiRef = useRef(false);

    const tutupPermainan = useCallback(
        (kirim: boolean) => {
            if (selesaiRef.current) {
                return;
            }
            selesaiRef.current = true;
            setFase('selesai');

            if (kirim && skorRef.current > 0) {
                const durasi = Math.max(1000, Date.now() - mulaiPadaRef.current);
                padaSelesai(skorRef.current, durasi);
            }
        },
        [padaSelesai],
    );

    const mulai = useCallback(() => {
        if (habis || habisKuota) {
            return;
        }

        selesaiRef.current = false;
        mulaiPadaRef.current = Date.now();
        skorRef.current = 0;
        setSkor(0);
        setBenar(0);
        setTotal(0);
        setStreak(0);
        setStreakMaks(0);
        setUmpan(null);
        setSisa(DURASI_DETIK);
        setSoal(buatSoal(0));
        setFase('main');
    }, [habis, habisKuota]);

    // Hitung mundur 60 detik berbasis wall-clock agar tidak meleset saat tab melambat.
    useEffect(() => {
        if (fase !== 'main') {
            return;
        }

        const jam = window.setInterval(() => {
            const lewat = Math.floor((Date.now() - mulaiPadaRef.current) / 1000);
            const tersisa = Math.max(0, DURASI_DETIK - lewat);
            setSisa(tersisa);

            if (tersisa <= 0) {
                window.clearInterval(jam);
                tutupPermainan(true);
            }
        }, 200);

        return () => window.clearInterval(jam);
    }, [fase, tutupPermainan]);

    // Jika ruang/sesi ujian ditutup saat sedang main, hentikan permainan.
    useEffect(() => {
        if (habis && fase === 'main') {
            tutupPermainan(true);
        }
    }, [habis, fase, tutupPermainan]);

    const pilih = useCallback(
        (nilai: number) => {
            if (fase !== 'main' || habis) {
                return;
            }

            setTotal((t) => t + 1);

            if (nilai === soal.jawaban) {
                const streakBaru = streak + 1;
                const tambahan = 10 + Math.min(streakBaru, 10) * 2;
                const skorBaru = skorRef.current + tambahan;
                const benarBaru = benar + 1;

                skorRef.current = skorBaru;
                setSkor(skorBaru);
                setBenar(benarBaru);
                setStreak(streakBaru);
                setStreakMaks((m) => Math.max(m, streakBaru));
                setUmpan('ok');
                setSoal(buatSoal(benarBaru));
            } else {
                setStreak(0);
                setUmpan('salah');
                setSoal(buatSoal(benar));
            }
        },
        [fase, habis, soal.jawaban, streak, benar],
    );

    // Pintasan keyboard 1-4 di desktop.
    useEffect(() => {
        if (fase !== 'main') {
            return;
        }

        const dengar = (e: KeyboardEvent) => {
            const idx = ['1', '2', '3', '4'].indexOf(e.key);
            if (idx !== -1 && soal.pilihan[idx] !== undefined) {
                e.preventDefault();
                pilih(soal.pilihan[idx]);
            }
        };

        window.addEventListener('keydown', dengar);

        return () => window.removeEventListener('keydown', dengar);
    }, [fase, soal.pilihan, pilih]);

    const akurasi = total === 0 ? 0 : Math.round((benar / total) * 100);

    return (
        <div className="relative overflow-hidden rounded-wf border border-line bg-surface p-3">
            {/* Bar status atas */}
            <div className="mb-3 flex items-center justify-between border-b border-line pb-2 font-mono text-[11px]">
                <span className="text-ink-3">
                    waktu{' '}
                    <b className={'tabular-nums ' + (sisa <= 10 && fase === 'main' ? 'text-bad' : 'text-ink')}>
                        {sisa}d
                    </b>
                </span>
                <span className="text-ink-3">
                    skor <b className="text-ink tabular-nums">{skor.toLocaleString('id-ID')}</b>
                </span>
                <span className="text-ink-3">
                    rangkaian <b className="text-accent tabular-nums">×{streak}</b>
                </span>
            </div>

            {habis ? (
                <div className="grid min-h-[12rem] place-content-center justify-items-center gap-1.5 text-center">
                    <b className="font-display text-sm text-warn">
                        {sesiLabel ? `${sesiLabel} sudah dibuka` : 'Sesi ujiannya sudah ditutup'}
                    </b>
                    <span className="text-[11px] text-ink-2">
                        {skor > 0
                            ? `Skor terakhir ${skor.toLocaleString('id-ID')} tersimpan.`
                            : 'Permainan ditutup.'}
                    </span>
                </div>
            ) : habisKuota && fase !== 'main' ? (
                <div className="grid min-h-[12rem] place-content-center justify-items-center gap-1.5 text-center">
                    <b className="font-display text-sm">
                        Sisa {kuotaBatas} permainan hari ini sudah dipakai
                    </b>
                    <span className="text-[11px] text-ink-2">
                        Besok jatahnya terbuka lagi. Rekormu hari ini tetap dihitung.
                    </span>
                </div>
            ) : fase === 'siap' ? (
                <div className="grid min-h-[12rem] place-content-center justify-items-center gap-2 text-center">
                    <b className="font-display text-sm text-ink">Adu Fokus Aritmetika (60 Detik)</b>
                    <p className="max-w-xs text-[11px] leading-relaxed text-ink-3">
                        Jawab soal hitung cepat sebanyak-banyaknya dalam 60 detik. Jawaban beruntun
                        memberi bonus poin.
                    </p>
                    <button
                        type="button"
                        onClick={mulai}
                        className="mt-1 rounded-wf border border-accent bg-accent px-4 py-1.5 font-mono text-[11px] font-medium uppercase tracking-wide text-on-accent"
                    >
                        Mulai 60 detik
                    </button>
                </div>
            ) : fase === 'selesai' ? (
                <div className="grid min-h-[12rem] place-content-center justify-items-center gap-2 text-center">
                    <b className="font-display text-2xl tabular-nums text-ink">
                        {skor.toLocaleString('id-ID')}
                    </b>
                    <p className="text-[11px] text-ink-2">
                        {benar} benar dari {total} soal ({akurasi}% akurasi) · Rangkaian terbaik ×
                        {streakMaks}
                    </p>
                    <button
                        type="button"
                        onClick={mulai}
                        className="mt-1 rounded-wf border border-accent bg-accent px-4 py-1.5 font-mono text-[11px] font-medium uppercase tracking-wide text-on-accent"
                    >
                        Main lagi
                    </button>
                </div>
            ) : (
                <div className="space-y-3">
                    <div
                        className={
                            'grid min-h-[5.5rem] place-items-center rounded-wf border px-3 py-4 transition-colors ' +
                            (umpan === 'salah'
                                ? 'border-bad/50 bg-bad-bg/40'
                                : umpan === 'ok'
                                  ? 'border-ok/40 bg-surface-2/40'
                                  : 'border-line bg-surface-2/40')
                        }
                    >
                        <span className="font-mono text-2xl font-semibold tracking-wide text-ink tabular-nums">
                            {soal.teks} = ?
                        </span>
                    </div>

                    <div className="grid grid-cols-2 gap-2">
                        {soal.pilihan.map((angka, idx) => (
                            <button
                                key={`${soal.teks}-${angka}-${idx}`}
                                type="button"
                                onClick={() => pilih(angka)}
                                className="flex items-center justify-between rounded-wf border border-line bg-surface px-3 py-3 font-mono text-base font-medium text-ink transition-colors hover:border-accent hover:bg-accent-soft/40 active:bg-accent-soft"
                            >
                                <span className="text-[10px] text-ink-3">{idx + 1}</span>
                                <span className="tabular-nums">{angka}</span>
                                <span className="w-2" aria-hidden="true" />
                            </button>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}

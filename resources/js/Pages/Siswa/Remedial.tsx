import { Link } from '@inertiajs/react';
import { useState } from 'react';
import KerangkaSiswa from '@/Layouts/KerangkaSiswa';

type Opsi = {
    kunci?: string;
    option_key?: string;
    teks?: { teks?: string } | string | null;
};

type ButirRemedial = {
    attempt_question_id: number;
    mapel: string;
    ujian: string;
    judul: string | null;
    soal: { teks?: string } | string | null;
    opsi: Opsi[];
};

type HasilCek = {
    benar: boolean;
    kunci: string | null;
    pembahasan: string | null;
};

type Props = {
    butir: ButirRemedial[];
};

export default function Remedial({ butir }: Props) {
    const [indeks, setIndeks] = useState(0);
    const [dipilih, setDipilih] = useState<Record<number, string>>({});
    const [hasil, setHasil] = useState<Record<number, HasilCek>>({});
    const [memuat, setMemuat] = useState(false);

    const aktif = butir[indeks] ?? null;
    const hasilAktif = aktif ? hasil[aktif.attempt_question_id] : undefined;

    const periksa = async (attemptQuestionId: number, opsi: string) => {
        if (memuat || hasil[attemptQuestionId]) {
            return;
        }

        setDipilih((prev) => ({ ...prev, [attemptQuestionId]: opsi }));
        setMemuat(true);

        try {
            const cocok = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
            const csrf = cocok ? decodeURIComponent(cocok[1]) : '';
            const res = await fetch('/siswa/luang/remedial/cek', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrf,
                },
                credentials: 'same-origin',
                body: JSON.stringify({ attempt_question_id: attemptQuestionId, opsi }),
            });

            if (res.ok) {
                const data = (await res.json()) as HasilCek;
                setHasil((prev) => ({ ...prev, [attemptQuestionId]: data }));
            }
        } finally {
            setMemuat(false);
        }
    };

    const teksBlok = (val: { teks?: string } | string | null | undefined): string => {
        if (typeof val === 'string') {
            return val;
        }
        return val?.teks ?? '';
    };

    return (
        <KerangkaSiswa judul="Klinik Remedial">
            <div className="space-y-3">
                <div className="flex items-center justify-between border border-line bg-surface px-3 py-2.5">
                    <div>
                        <p className="text-[13px] font-semibold text-ink">Klinik Remedial Pribadi</p>
                        <p className="text-[11px] text-ink-3">
                            Latihan ulang dari soal yang sebelumnya keliru. Tidak memengaruhi nilai resmi.
                        </p>
                    </div>
                    <Link
                        href="/siswa/luang"
                        className="border border-line bg-surface-2 px-2.5 py-1 text-[11px] font-medium text-ink-2"
                    >
                        Kembali
                    </Link>
                </div>

                {aktif === null ? (
                    <div className="border border-line bg-surface px-4 py-6 text-center text-[12px] text-ink-2">
                        Belum ada butir remedial yang perlu diulang. Pertahankan!
                    </div>
                ) : (
                    <div className="space-y-3 border border-line bg-surface p-3.5">
                        <div className="flex items-center justify-between text-[11px] text-ink-3">
                            <span>
                                {aktif.mapel} · {aktif.ujian}
                            </span>
                            <span className="font-mono">
                                {indeks + 1} / {butir.length}
                            </span>
                        </div>

                        {aktif.judul && <p className="text-[12px] font-medium text-ink">{aktif.judul}</p>}

                        <div
                            className="prose prose-sm max-w-none text-[13px] text-ink"
                            dangerouslySetInnerHTML={{ __html: teksBlok(aktif.soal) }}
                        />

                        <div className="space-y-2">
                            {aktif.opsi.map((o, idx) => {
                                const k = String(o.kunci ?? o.option_key ?? String.fromCharCode(65 + idx));
                                const dipilihIni = dipilih[aktif.attempt_question_id] === k;
                                const benarIni = hasilAktif?.kunci?.toUpperCase() === k.toUpperCase();

                                let kelas = 'border-line bg-surface text-ink hover:bg-surface-2';
                                if (hasilAktif) {
                                    if (benarIni) {
                                        kelas = 'border-ok bg-ok/10 text-ok font-medium';
                                    } else if (dipilihIni && !hasilAktif.benar) {
                                        kelas = 'border-bad bg-bad/10 text-bad';
                                    }
                                }

                                return (
                                    <button
                                        key={k}
                                        type="button"
                                        disabled={Boolean(hasilAktif) || memuat}
                                        onClick={() => void periksa(aktif.attempt_question_id, k)}
                                        className={`flex w-full items-start gap-2.5 border px-3 py-2 text-left text-[12px] ${kelas}`}
                                    >
                                        <span className="font-mono font-semibold">{k}.</span>
                                        <span
                                            className="flex-1"
                                            dangerouslySetInnerHTML={{ __html: teksBlok(o.teks) }}
                                        />
                                    </button>
                                );
                            })}
                        </div>

                        {hasilAktif && (
                            <div
                                className={`border px-3 py-2.5 text-[12px] ${
                                    hasilAktif.benar
                                        ? 'border-ok bg-ok/10 text-ok'
                                        : 'border-warn bg-warn/10 text-ink'
                                }`}
                            >
                                <p className="font-semibold">
                                    {hasilAktif.benar
                                        ? 'Benar! Pemahamanmu sudah tepat.'
                                        : `Belum tepat. Kunci jawaban: ${hasilAktif.kunci ?? '-'}`}
                                </p>
                                {hasilAktif.pembahasan && (
                                    <p className="mt-1 text-[11px] leading-relaxed text-ink-2">
                                        {hasilAktif.pembahasan}
                                    </p>
                                )}
                            </div>
                        )}

                        <div className="flex justify-between pt-1">
                            <button
                                type="button"
                                disabled={indeks === 0}
                                onClick={() => setIndeks((i) => Math.max(0, i - 1))}
                                className="border border-line bg-surface-2 px-3 py-1.5 text-[11px] text-ink-2 disabled:opacity-40"
                            >
                                Sebelumnya
                            </button>
                            <button
                                type="button"
                                disabled={indeks >= butir.length - 1}
                                onClick={() => setIndeks((i) => Math.min(butir.length - 1, i + 1))}
                                className="border border-accent bg-accent px-3 py-1.5 text-[11px] font-medium text-on-accent disabled:opacity-40"
                            >
                                Soal berikutnya
                            </button>
                        </div>
                    </div>
                )}
            </div>
        </KerangkaSiswa>
    );
}

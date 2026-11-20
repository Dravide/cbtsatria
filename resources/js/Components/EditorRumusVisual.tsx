import { useEffect, useRef } from 'react';
import { MathfieldElement } from 'mathlive';
import { Tombol, kelasInput } from '@/Components/ui';

// Nonaktifkan pemuatan aset eksternal/relatif bawaan MathLive karena font sudah
// dibundel Vite lewat `mathlive/fonts.css` (penting untuk CBT lokal/offline).
MathfieldElement.fontsDirectory = null;
MathfieldElement.soundsDirectory = null;

const TEMPLATE_CEPAT: { label: string; judul: string; latex: string }[] = [
    { label: 'a/b', judul: 'Pecahan', latex: '\\frac{#0}{#?}' },
    { label: '√□', judul: 'Akar kuadrat', latex: '\\sqrt{#0}' },
    { label: 'ⁿ√□', judul: 'Akar pangkat n', latex: '\\sqrt[#?]{#0}' },
    { label: 'xⁿ', judul: 'Pangkat', latex: '#0^{#?}' },
    { label: 'xₙ', judul: 'Indeks bawah', latex: '#0_{#?}' },
    { label: '∑', judul: 'Sigma / Penjumlahan', latex: '\\sum_{i=1}^{n}' },
    { label: '∫', judul: 'Integral', latex: '\\int_{a}^{b}' },
    { label: 'lim', judul: 'Limit', latex: '\\lim_{x \\to 0}' },
    { label: 'π', judul: 'Pi', latex: '\\pi' },
    { label: 'θ', judul: 'Teta', latex: '\\theta' },
    { label: '°', judul: 'Derajat', latex: '^{\\circ}' },
    { label: '≤', judul: 'Kurang dari sama dengan', latex: '\\le' },
    { label: '≥', judul: 'Lebih dari sama dengan', latex: '\\ge' },
    { label: '≠', judul: 'Tidak sama dengan', latex: '\\neq' },
    { label: '×', judul: 'Kali', latex: '\\times' },
    { label: '÷', judul: 'Bagi', latex: '\\div' },
];

export function EditorRumusVisual({
    nilai,
    padaUbah,
    modeBlok,
    padaUbahModeBlok,
    padaSisipkan,
    padaTutup,
}: {
    nilai: string;
    padaUbah: (latex: string) => void;
    modeBlok: boolean;
    padaUbahModeBlok: (blok: boolean) => void;
    padaSisipkan: () => void;
    padaTutup: () => void;
}) {
    const wadahRef = useRef<HTMLDivElement>(null);
    const mfRef = useRef<MathfieldElement | null>(null);
    const padaUbahRef = useRef(padaUbah);
    padaUbahRef.current = padaUbah;

    useEffect(() => {
        const wadah = wadahRef.current;
        if (!wadah) {
            return;
        }

        const mf = new MathfieldElement();
        mf.value = nilai;
        mf.style.width = '100%';
        mf.style.minHeight = '2.75rem';
        mf.style.fontSize = '1.1rem';
        mf.style.padding = '0.35rem 0.6rem';
        mf.style.borderRadius = '8px';
        mf.style.border = '1px solid var(--color-line, #d3d9e2)';
        mf.style.background = 'var(--color-surface, #ffffff)';
        mf.style.color = 'var(--color-ink, #161b24)';

        const handleInput = () => {
            padaUbahRef.current(mf.value);
        };

        mf.addEventListener('input', handleInput);
        wadah.innerHTML = '';
        wadah.appendChild(mf);
        mfRef.current = mf;

        setTimeout(() => mf.focus(), 30);

        return () => {
            mf.removeEventListener('input', handleInput);
            wadah.innerHTML = '';
            mfRef.current = null;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        const mf = mfRef.current;
        if (mf && mf.value !== nilai) {
            mf.value = nilai;
        }
    }, [nilai]);

    const sisipTemplate = (latex: string) => {
        const mf = mfRef.current;
        if (!mf) {
            return;
        }
        mf.focus();
        mf.insert(latex, { focus: true, feedback: false });
        padaUbah(mf.value);
    };

    return (
        <div className="border-b border-line bg-surface-2/40 px-3 py-2.5 text-xs">
            <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                <span className="text-[11px] font-medium text-ink-2">
                    Editor Rumus Visual (klik simbol atau ikon papan ketik di kanan kotak)
                </span>
                <div className="flex flex-wrap gap-1">
                    {TEMPLATE_CEPAT.map((t) => (
                        <button
                            key={t.label}
                            type="button"
                            title={t.judul}
                            onMouseDown={(e) => e.preventDefault()}
                            onClick={() => sisipTemplate(t.latex)}
                            className="rounded-wf border border-line bg-surface px-2 py-0.5 text-[11px] font-medium text-ink-2 hover:bg-surface-2 hover:text-ink"
                        >
                            {t.label}
                        </button>
                    ))}
                </div>
            </div>

            <div ref={wadahRef} />

            <div className="mt-2 flex flex-wrap items-center gap-2">
                <input
                    type="text"
                    value={nilai}
                    onChange={(e) => padaUbah(e.target.value)}
                    placeholder="Kode LaTeX otomatis terisi di sini..."
                    aria-label="Kode LaTeX"
                    className={kelasInput + ' min-w-[12rem] flex-1 font-mono text-[11px]'}
                />
                <label className="flex items-center gap-1 text-[11px] text-ink-2">
                    <input
                        type="checkbox"
                        checked={modeBlok}
                        onChange={(e) => padaUbahModeBlok(e.target.checked)}
                    />
                    Baris terpisah ($$)
                </label>
                <Tombol type="button" nada="pri" onClick={padaSisipkan}>
                    Sisipkan
                </Tombol>
                <Tombol type="button" onClick={padaTutup}>
                    Tutup
                </Tombol>
            </div>
        </div>
    );
}

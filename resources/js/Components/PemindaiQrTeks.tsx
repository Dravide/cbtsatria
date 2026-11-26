import { useEffect, useRef, useState } from 'react';

type Props = {
    labelTombol?: string;
    disabled?: boolean;
    padaTeks: (isi: string) => void;
};

function adaPembaca(): boolean {
    return typeof window !== 'undefined' && 'BarcodeDetector' in window;
}

/**
 * Pemindai QR generik via kamera peramban (`BarcodeDetector` + `getUserMedia`)
 * untuk membaca payload teks mentah seperti paket darurat offline (`Z1:...`).
 */
export default function PemindaiQrTeks({
    labelTombol = 'Scan QR Darurat',
    disabled = false,
    padaTeks,
}: Props) {
    const [terbuka, setTerbuka] = useState(false);
    const [galat, setGalat] = useState<string | null>(null);

    const video = useRef<HTMLVideoElement>(null);
    const aliran = useRef<MediaStream | null>(null);
    const berhenti = useRef(false);

    const hentikan = () => {
        berhenti.current = true;
        aliran.current?.getTracks().forEach((t) => t.stop());
        aliran.current = null;
    };

    const tutup = () => {
        hentikan();
        setTerbuka(false);
        setGalat(null);
    };

    useEffect(() => {
        if (!terbuka) {
            return;
        }

        if (!adaPembaca()) {
            setGalat(
                'Peramban ini belum mendukung pemindai QR langsung (gunakan Chrome/Edge Android, atau tempel kode teks di bawah).',
            );
            return;
        }

        berhenti.current = false;

        const mulai = async () => {
            try {
                const media = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: { ideal: 'environment' } },
                    audio: false,
                });

                if (berhenti.current) {
                    media.getTracks().forEach((t) => t.stop());
                    return;
                }

                aliran.current = media;

                if (video.current !== null) {
                    video.current.srcObject = media;
                    await video.current.play().catch(() => undefined);
                }

                const Pembaca = (window as unknown as {
                    BarcodeDetector: new (o: { formats: string[] }) => {
                        detect: (sumber: HTMLVideoElement) => Promise<{ rawValue: string }[]>;
                    };
                }).BarcodeDetector;

                const pembaca = new Pembaca({ formats: ['qr_code'] });

                const putaran = async () => {
                    if (berhenti.current || video.current === null) {
                        return;
                    }

                    try {
                        const hasil = await pembaca.detect(video.current);
                        const isi = hasil[0]?.rawValue?.trim();

                        if (isi) {
                            hentikan();
                            setTerbuka(false);
                            setGalat(null);
                            padaTeks(isi);
                            return;
                        }
                    } catch {
                        // Frame belum fokus; lanjut ke frame berikutnya.
                    }

                    if (!berhenti.current) {
                        window.requestAnimationFrame(() => {
                            void putaran();
                        });
                    }
                };

                window.requestAnimationFrame(() => {
                    void putaran();
                });
            } catch {
                if (!berhenti.current) {
                    setGalat('Izin kamera ditolak atau kamera tidak tersedia.');
                }
            }
        };

        void mulai();

        return () => {
            hentikan();
        };
    }, [terbuka, padaTeks]);

    if (!terbuka) {
        return (
            <button
                type="button"
                disabled={disabled}
                onClick={() => {
                    setGalat(null);
                    setTerbuka(true);
                }}
                className="inline-grid min-h-10 place-items-center rounded-wf border border-accent bg-accent px-3 text-xs font-medium text-on-accent disabled:opacity-50"
            >
                {labelTombol}
            </button>
        );
    }

    return (
        <div className="space-y-2 rounded-wf border border-line bg-surface p-2.5">
            <div className="flex items-center justify-between gap-2">
                <span className="text-[11px] font-medium text-ink">
                    Arahkan kamera ke QR di layar siswa
                </span>
                <button
                    type="button"
                    onClick={tutup}
                    className="rounded-wf border border-line bg-surface-2 px-2 py-1 text-[11px] text-ink-2"
                >
                    Tutup kamera
                </button>
            </div>

            {galat ? (
                <p className="rounded-wf border border-warn/40 bg-warn-bg px-2.5 py-2 text-[11px] text-warn">
                    {galat}
                </p>
            ) : (
                <video
                    ref={video}
                    playsInline
                    muted
                    className="aspect-square w-full rounded-wf border border-line bg-black object-cover"
                />
            )}
        </div>
    );
}

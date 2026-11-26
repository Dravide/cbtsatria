import { useEffect, useRef, useState } from 'react';
import { bacaQrDariBerkas, bacaQrDariVideo, buatDetectorBawaan } from '@/lib/bacaQr';

type Props = {
    labelTombol?: string;
    disabled?: boolean;
    padaTeks: (isi: string) => void;
};

/**
 * Pemindai QR generik via kamera peramban (`BarcodeDetector` dengan fallback `jsQR`)
 * untuk membaca payload teks mentah seperti paket darurat offline (`Z1:...`) di Android maupun iPhone.
 */
export default function PemindaiQrTeks({
    labelTombol = 'Scan QR Darurat',
    disabled = false,
    padaTeks,
}: Props) {
    const [terbuka, setTerbuka] = useState(false);
    const [kameraGagal, setKameraGagal] = useState(false);
    const [galat, setGalat] = useState<string | null>(null);

    const video = useRef<HTMLVideoElement>(null);
    const kanvas = useRef<HTMLCanvasElement | null>(null);
    const fotoInput = useRef<HTMLInputElement>(null);
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
        setKameraGagal(false);
        setGalat(null);
    };

    useEffect(() => {
        if (!terbuka) {
            return;
        }

        if (typeof navigator === 'undefined' || !navigator.mediaDevices?.getUserMedia) {
            setKameraGagal(true);
            setGalat('Kamera langsung tidak tersedia. Gunakan Foto QR atau tempel kode teks di bawah.');
            return;
        }

        berhenti.current = false;
        setKameraGagal(false);

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
                    video.current.setAttribute('playsinline', 'true');
                    video.current.setAttribute('webkit-playsinline', 'true');
                    video.current.srcObject = media;
                    await video.current.play().catch(() => undefined);
                }

                if (kanvas.current === null) {
                    kanvas.current = document.createElement('canvas');
                }

                const detector = buatDetectorBawaan();

                const putaran = async () => {
                    if (berhenti.current || video.current === null || kanvas.current === null) {
                        return;
                    }

                    try {
                        const isi = await bacaQrDariVideo(video.current, kanvas.current, detector);

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
                        window.setTimeout(putaran, 250);
                    }
                };

                void putaran();
            } catch {
                if (!berhenti.current) {
                    setKameraGagal(true);
                    setGalat('Izin kamera ditolak atau kamera tidak tersedia. Gunakan Foto QR.');
                }
            }
        };

        void mulai();

        return () => {
            hentikan();
        };
    }, [terbuka, padaTeks]);

    const pindaiDariFoto = async (berkas: File | null) => {
        if (berkas === null) {
            return;
        }

        const isi = await bacaQrDariBerkas(berkas).catch(() => null);
        if (!isi) {
            setGalat('QR tidak terbaca dari foto tersebut.');
            return;
        }

        tutup();
        padaTeks(isi);
    };

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

            {galat && (
                <p className="rounded-wf border border-warn/40 bg-warn-bg px-2.5 py-2 text-[11px] text-warn">
                    {galat}
                </p>
            )}

            {!kameraGagal && (
                <video
                    ref={video}
                    playsInline
                    muted
                    autoPlay
                    className="aspect-square w-full rounded-wf border border-line bg-black object-cover"
                />
            )}

            <input
                ref={fotoInput}
                type="file"
                accept="image/*"
                capture="environment"
                className="hidden"
                onChange={(e) => {
                    void pindaiDariFoto(e.target.files?.[0] ?? null);
                    e.target.value = '';
                }}
            />

            <button
                type="button"
                onClick={() => fotoInput.current?.click()}
                className="inline-grid min-h-9 w-full place-items-center rounded-wf border border-line bg-surface-2 px-3 text-[11px] font-medium text-ink"
            >
                Ambil foto QR (alternatif kamera)
            </button>
        </div>
    );
}

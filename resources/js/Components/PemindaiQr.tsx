import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { kelasInput } from '@/Components/ui';
import { bacaQrDariBerkas, bacaQrDariVideo, buatDetectorBawaan } from '@/lib/bacaQr';

/**
 * Pemindai QR presensi - kamera langsung di dalam laman gerbang.
 *
 * =====================================================================
 * Mengapa ada, padahal QR-nya sudah bisa dipindai kamera ponsel
 * =====================================================================
 * Kamera bawaan ponsel memang bisa membaca QR, tetapi yang didapat siswa
 * adalah tautan `/hadir/{kode}` yang terbuka di peramban lain - dan di
 * banyak ponsel itu berarti keluar dari sesi, atau masuk ke peramban yang
 * belum punya sesi sama sekali. Siswa lalu mendarat di layar login dan
 * mengira akunnya rusak. Pemindai di sini menjaga seluruh alurnya di dalam
 * aplikasi yang sudah masuk.
 *
 * =====================================================================
 * Tanpa pustaka baru, dan itu memang batasnya
 * =====================================================================
 * `BarcodeDetector` adalah API peramban - Chrome/Edge Android dan Chrome
 * desktop sudah memilikinya. Peramban yang belum punya (iOS Safari sampai
 * sekarang) TIDAK dipaksa dengan pustaka pihak ketiga: menambah dependensi
 * pemindai berarti beberapa ratus kilobyte yang harus diunduh setiap siswa
 * di ponsel murah, untuk perangkat yang kamera bawaannya sudah mampu.
 * Mereka mendapat jalan kedua yang selalu bekerja: mengetik kode dari layar
 * pengawas.
 *
 * =====================================================================
 * Yang dipindai adalah URL, bukan kode telanjang
 * =====================================================================
 * QR yang dicetak `PresensiController` memuat alamat penuh
 * (`/hadir/{kode}`). Keduanya diterima di sini - alamat penuh maupun kode
 * enam belas karakternya - karena siswa bisa saja memindai layar pengawas
 * yang menampilkan tautannya sebagai teks.
 */
type Props = {
    /** Kode yang diterima, sesudah diurai dari URL atau diketik langsung. */
    padaKode: (kode: string) => void;
};

/** Panjang kode di `KodePindai::PANJANG_KODE`, dan alfabetnya. */
const POLA_KODE = /^[a-hj-km-np-z2-9]{16}$/;

/**
 * Ambil kode dari apa pun yang keluar dari pemindai.
 *
 * `null` berarti isinya bukan kode presensi - QR lain, tautan lain, atau
 * teks acak. Pemanggil yang memutuskan apa yang dikatakan ke siswa; fungsi
 * ini tidak menebak.
 */
export function kodeDari(isi: string): string | null {
    const teks = isi.trim();

    if (POLA_KODE.test(teks)) {
        return teks;
    }

    // Alamat penuh: ambil segmen SESUDAH `/hadir/`, bukan segmen terakhir
    // secara buta - tautan yang berakhiran garis miring atau membawa query
    // akan menghasilkan string kosong pada tebakan yang buta.
    const cocok = teks.match(/\/hadir\/([^/?#\s]+)/i);

    if (cocok !== null && POLA_KODE.test(cocok[1])) {
        return cocok[1];
    }

    return null;
}

export default function PemindaiQr({ padaKode }: Props) {
    const [terbuka, setTerbuka] = useState(false);
    const [kameraGagal, setKameraGagal] = useState(false);
    const [galat, setGalat] = useState<string | null>(null);
    const [manual, setManual] = useState('');
    const [galatManual, setGalatManual] = useState<string | null>(null);

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
            setGalat(
                'Kamera langsung tidak tersedia di peramban ini (pastikan situs diakses via HTTPS). Gunakan tombol Foto QR atau ketik kode di bawah.',
            );
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

                        if (isi !== null) {
                            const kode = kodeDari(isi);

                            if (kode !== null) {
                                hentikan();
                                setTerbuka(false);
                                padaKode(kode);

                                return;
                            }

                            setGalat('QR itu bukan kode presensi ruang ujian.');
                        }
                    } catch {
                        // Frame awal belum siap; lanjut ke frame berikutnya.
                    }

                    if (!berhenti.current) {
                        window.setTimeout(putaran, 250);
                    }
                };

                void putaran();
            } catch {
                if (!berhenti.current) {
                    setKameraGagal(true);
                    setGalat(
                        'Kamera tidak bisa dibuka. Beri izin kamera pada peramban ini, '
                        + 'gunakan Foto QR, atau ketik kodenya di bawah.',
                    );
                }
            }
        };

        void mulai();

        return hentikan;
    }, [terbuka, padaKode]);

    const pindaiDariFoto = async (berkas: File | null) => {
        if (berkas === null) {
            return;
        }

        const isi = await bacaQrDariBerkas(berkas).catch(() => null);

        if (isi === null) {
            setGalat('QR tidak terbaca dari foto tersebut. Coba foto ulang lebih jelas atau ketik kodenya.');
            return;
        }

        const kode = kodeDari(isi);

        if (kode === null) {
            setGalat('QR itu bukan kode presensi ruang ujian.');
            return;
        }

        tutup();
        padaKode(kode);
    };

    const kirimManual = (e: React.FormEvent) => {
        e.preventDefault();

        const kode = kodeDari(manual);

        if (kode === null) {
            setGalatManual('Kode presensi 16 karakter. Periksa lagi, atau pindai QR-nya.');

            return;
        }

        setGalatManual(null);
        tutup();
        padaKode(kode);
    };

    if (!terbuka) {
        return (
            <div className="space-y-2">
                <button
                    type="button"
                    onClick={() => {
                        setTerbuka(true);
                        setGalat(null);
                    }}
                    className="tombol tombol--pri w-full"
                >
                    Pindai QR presensi
                </button>
                <p className="catatan text-center">
                    Layar pengawas di depan pintu menampilkan QR-nya
                </p>
            </div>
        );
    }

    return (
        <div className="space-y-2.5">
            {!kameraGagal && (
                <div className="relative overflow-hidden rounded-wf border border-line bg-ink">
                    {/* `playsInline` wajib: tanpa itu iOS membuka video layar
                        penuh sendiri, dan bingkai bidik di bawah tidak pernah
                        terlihat. */}
                    <video
                        ref={video}
                        playsInline
                        muted
                        autoPlay
                        className="block h-52 w-full object-cover"
                    />

                    <div className="pointer-events-none absolute inset-0 grid place-items-center">
                        <div className="size-36 rounded-wf border-2 border-white/80" />
                    </div>
                </div>
            )}

            {galat !== null && (
                <p className="text-[11px] leading-relaxed text-warn">{galat}</p>
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
                className="tombol w-full"
            >
                Ambil foto QR (alternatif kamera)
            </button>

            <form onSubmit={kirimManual} className="space-y-2">
                <input
                    value={manual}
                    onChange={(e) => setManual(e.target.value)}
                    placeholder="atau ketik kode 16 karakter"
                    autoComplete="off"
                    autoCorrect="off"
                    autoCapitalize="none"
                    spellCheck={false}
                    className={kelasInput + ' text-center font-mono text-xs'}
                    aria-label="Kode presensi"
                />

                {galatManual !== null && (
                    <p className="text-[11px] text-bad">{galatManual}</p>
                )}

                <button type="submit" className="tombol w-full">
                    Kirim kode
                </button>
            </form>

            <button type="button" onClick={tutup} className="tombol tombol--bahaya w-full">
                Tutup
            </button>
        </div>
    );
}

/** Buka laman pengakuan untuk kode hasil pindaian. */
export function bukaPengakuan(kode: string): void {
    router.visit(`/hadir/${kode}`);
}

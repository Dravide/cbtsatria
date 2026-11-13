import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { kelasInput } from '@/Components/ui';

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

/** Apakah peramban ini punya pembaca QR bawaan. */
function adaPembaca(): boolean {
    return typeof window !== 'undefined' && 'BarcodeDetector' in window;
}

export default function PemindaiQr({ padaKode }: Props) {
    const [terbuka, setTerbuka] = useState(false);
    const [galat, setGalat] = useState<string | null>(null);
    const [manual, setManual] = useState('');
    const [galatManual, setGalatManual] = useState<string | null>(null);

    const video = useRef<HTMLVideoElement>(null);
    const aliran = useRef<MediaStream | null>(null);
    const berhenti = useRef(false);

    /**
     * Seluruh sumber daya kamera dilepas di sini, dan hanya di sini.
     *
     * Tanpa `getTracks().forEach(t => t.stop())` kamera tetap menyala
     * sesudah laman ditutup - lampu hijau di ponsel siswa menyala sepanjang
     * ujian, dan yang menyimpulkan aplikasi ini memata-matai mereka adalah
     * pengawas yang melihatnya, bukan siswa.
     */
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
            // Pesannya bukan galat: peramban ini memang tidak punya
            // pembacanya, dan yang bisa dikerjakan siswa sudah tersedia di
            // bawah dalam bentuk kolom kode.
            setGalat('Peramban ini belum bisa membaca QR langsung. Ketik kodenya di bawah.');
            return;
        }

        berhenti.current = false;

        const mulai = async () => {
            try {
                const media = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: { ideal: 'environment' } },
                    audio: false,
                });

                // Komponen sudah ditutup selagi izinnya menunggu - jangan
                // tinggalkan kamera menyala tanpa pemilik.
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
                        const isi = hasil[0]?.rawValue;

                        if (isi !== undefined) {
                            const kode = kodeDari(isi);

                            if (kode !== null) {
                                hentikan();
                                setTerbuka(false);
                                padaKode(kode);

                                return;
                            }

                            // QR yang terbaca tetapi bukan kode presensi.
                            // Dibiarkan berjalan: siswa mungkin memindai QR
                            // lain di meja, lalu mengarahkan ulang kameranya.
                            setGalat('QR itu bukan kode presensi ruang ujian.');
                        }
                    } catch {
                        // `detect()` melempar saat video belum punya bingkai -
                        // itu keadaan normal pada detik pertama, bukan galat.
                    }

                    if (!berhenti.current) {
                        window.setTimeout(putaran, 350);
                    }
                };

                void putaran();
            } catch {
                setGalat(
                    'Kamera tidak bisa dibuka. Beri izin kamera pada peramban ini, '
                    + 'atau ketik kodenya di bawah.',
                );
            }
        };

        void mulai();

        return hentikan;
    }, [terbuka, padaKode]);

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

            {galat !== null && (
                <p className="text-[11px] leading-relaxed text-warn">{galat}</p>
            )}

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

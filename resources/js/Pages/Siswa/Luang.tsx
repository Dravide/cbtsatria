import { Link } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import KerangkaSiswa from '@/Layouts/KerangkaSiswa';

/**
 * Permainan waktu luang - wireframe-waktu-luang.html plate 02, 05, 06.
 * `/siswa/luang`.
 *
 * =====================================================================
 * Panggung ini MENGHITUNG SKORNYA SENDIRI, dan itu boleh
 * =====================================================================
 * Yang dikirim ke server bukan skornya, melainkan `distance` dan
 * `duration_ms`. Server menghitung ulang skornya (`PenjagaSkorLuar`), dan
 * angka yang tampil di HUD hanyalah cermin dari hitungan yang sama di sini.
 *
 * Konsekuensinya yang penting: skor di HUD dan skor yang tersimpan bisa
 * BERBEDA, dan perbedaannya adalah informasi - bila angka di papan
 * peringkat lebih rendah, artinya kirimannya ditolak. Karena itu pesannya
 * ditulis apa adanya ("diperiksa server"), bukan disembunyikan.
 *
 * =====================================================================
 * Jendela waktunya milik SERVER
 * =====================================================================
 * `jendela.berakhir_pada` datang dari server, dan panggung menghitung sisa
 * detiknya sendiri sejak prop itu diterima - sama seperti hitung mundur di
 * beranda. Jam peramban tidak pernah dipakai untuk memutuskan apa pun.
 *
 * Saat sisanya habis, panggung BERHENTI dan menutup dirinya (plate 06):
 * tidak ada tombol "main lagi", tidak ada dialog. Yang tersisa hanya skor
 * dan tautan masuk ujian - karena tujuan jendela ini memang berakhir.
 *
 * =====================================================================
 * Mengapa panggungnya canvas, bukan DOM
 * =====================================================================
 * Enam puluh bingkai per detik dengan `div` berarti enam puluh perubahan tata
 * letak per detik di ponsel yang juga sedang memegang sesi ujian. Canvas
 * tidak menyentuh tata letak sama sekali.
 */

type Props = {
    jendela: {
        berakhir_pada: string;
        sesi_berikut: { label: string; mapel: string | null; dibuka: string } | null;
    };
    rekor: number | null;
    peringkat: { peringkat: number | null; pemain: number; rekor: number | null };
    kelas: string | null;
};

/** Satu bingkai permainan. */
type Keadaan = {
    pemainY: number;
    pemainV: number;
    diTanah: boolean;
    rintangan: { x: number; w: number; h: number }[];
    jarak: number;
    detik: number;
};

const GRAVITASI = 0.62;
const LOMPAT = -11.6;
const KECEPATAN_AWAL = 6.2;

/** Kecepatan bertambah sangat pelan - lihat catatan `KECEPATAN_AWAL`. */
const PERCEPATAN = 0.0012;

export default function Luang({ jendela, rekor, peringkat, kelas }: Props) {
    const panggung = useRef<HTMLDivElement | null>(null);
    const kanvas = useRef<HTMLCanvasElement | null>(null);
    const keadaan = useRef<Keadaan>({
        pemainY: 0,
        pemainV: 0,
        diTanah: true,
        rintangan: [],
        jarak: 0,
        detik: 0,
    });
    const bingkai = useRef<number | null>(null);
    const terakhir = useRef<number>(0);

    const [bermain, setBermain] = useState(false);
    // `habis` = jendelanya tutup (plate 06). `berakhir` = anak terbentur
    // (plate 05). Dua keadaan yang berbeda, dan tombolnya pun berbeda:
    // yang pertama TIDAK punya "main lagi".
    const [habis, setHabis] = useState(false);
    const [berakhir, setBerakhir] = useState(false);

    const [jarakAkhir, setJarakAkhir] = useState(0);

    const [sisa, setSisa] = useState(() => sisaDetik(jendela.berakhir_pada));
    const [menyimpan, setMenyimpan] = useState(false);
    const [pesan, setPesan] = useState<string | null>(null);
    const [tercatat, setTercatat] = useState<number | null>(rekor);

    // Prop `rekor` berganti setiap kali halaman dimuat ulang; state di atas
    // hanya nilai awalnya.
    useEffect(() => setTercatat(rekor), [rekor]);

    /* ------------------------------------------------------- Jam jendela */

    useEffect(() => {
        if (habis) {
            return;
        }

        const tiap = window.setInterval(() => {
            const s = sisaDetik(jendela.berakhir_pada);
            setSisa(s);

            if (s <= 0) {
                // Jendelanya tutup. Permainannya dihentikan di sini, bukan
                // menunggu bingkai berikutnya - dan skornya TIDAK dikirim,
                // karena server akan menolaknya (409) dan pesan penolakan
                // hanya membingungkan anak yang permainannya memang baru
                // dipotong.
                hentikan();
                setHabis(true);
                setBermain(false);
            }
        }, 1000);

        return () => window.clearInterval(tiap);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [jendela.berakhir_pada, habis]);

    /* ------------------------------------------------------ Bingkai canvas */

    const gambar = useCallback(() => {
        const c = kanvas.current;
        const kotak = panggung.current?.getBoundingClientRect();

        if (!c || !kotak) {
            return;
        }

        const k = c.getContext('2d');

        if (!k) {
            return;
        }

        const rasio = Math.min(window.devicePixelRatio || 1, 2);
        const L = kotak.width;
        const T = kotak.height;

        // Kanvas disesuaikan ukurannya SETIAP bingkai: lebar panggungnya
        // berubah saat ponsel diputar, dan kanvas dengan ukuran tetap akan
        // menyisakan pita kosong di satu sisi.
        if (c.width !== Math.round(L * rasio) || c.height !== Math.round(T * rasio)) {
            c.width = Math.round(L * rasio);
            c.height = Math.round(T * rasio);
            k.setTransform(rasio, 0, 0, rasio, 0, 0);
        }

        const tanah = T - 22;
        const skala = T / 150;
        const s = keadaan.current;

        k.clearRect(0, 0, L, T);

        // Garis tanah. Satu garis, bukan lanskap: yang perlu dilihat pemain
        // hanyalah di mana kakinya mendarat.
        k.strokeStyle = '#a9b5c5';
        k.lineWidth = Math.max(1, 1.5 * skala);
        k.beginPath();
        k.moveTo(0, tanah);
        k.lineTo(L, tanah);
        k.stroke();

        k.fillStyle = '#1e4fa3';
        k.fillRect(14 * skala, tanah - 22 * skala + s.pemainY, 14 * skala, 22 * skala);

        k.fillStyle = '#77808f';
        s.rintangan.forEach((r) => k.fillRect(r.x, tanah - r.h, r.w, r.h));
    }, []);

    const langkah = useCallback(
        (waktu: number) => {
            const delta = terakhir.current === 0 ? 16.7 : Math.min(waktu - terakhir.current, 50);
            terakhir.current = waktu;

            const k = kanvas.current;
            const kotak = panggung.current?.getBoundingClientRect();

            if (!k || !kotak) {
                return;
            }

            const L = kotak.width;
            const T = kotak.height;
            const skala = T / 150;
            const tanah = T - 22;
            const s = keadaan.current;

            // Waktu berjalan dalam detik, bukan bingkai: ponsel 120Hz dan
            // ponsel 60Hz harus menghasilkan jarak yang sama untuk durasi
            // yang sama - dan seluruh pemeriksaan server bertumpu pada
            // jarak per detik.
            const dt = delta / 1000;
            s.detik += dt;

            s.pemainV += GRAVITASI * skala * (dt * 60);
            s.pemainY += s.pemainV;

            if (s.pemainY >= 0) {
                s.pemainY = 0;
                s.pemainV = 0;
                s.diTanah = true;
            }

            const kecepatan = KECEPATAN_AWAL + PERCEPATAN * s.detik * 60;
            const geser = kecepatan * skala * (dt * 60);

            s.jarak += geser / skala;

            const terakhirRintangan = s.rintangan[s.rintangan.length - 1];

            if (!terakhirRintangan || terakhirRintangan.x < L - 120 * skala) {
                if (Math.random() < 0.03 * (dt * 60)) {
                    s.rintangan.push({
                        x: L,
                        w: 10 * skala,
                        h: (16 + Math.random() * 13) * skala,
                    });
                }
            }

            s.rintangan.forEach((r) => {
                r.x -= geser;
            });
            s.rintangan = s.rintangan.filter((r) => r.x + r.w > -10);

            const kakiPemain = 14 * skala;
            const lebarPemain = 14 * skala;
            const tinggiPemain = 22 * skala;

            for (const r of s.rintangan) {
                if (
                    r.x < kakiPemain + lebarPemain &&
                    r.x + r.w > kakiPemain &&
                    tanah - tinggiPemain + s.pemainY + tinggiPemain > tanah - r.h
                ) {
                    hentikan();
                    setBerakhir(true);
                    setBermain(false);
                    setJarakAkhir(Math.round(s.jarak));
                    kirim(Math.round(s.jarak), Math.round(s.detik * 1000));
                    return;
                }
            }

            gambar();

            if (sisaDetik(jendela.berakhir_pada) <= 0) {
                return;
            }

            bingkai.current = requestAnimationFrame(langkah);
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [gambar, jendela.berakhir_pada],
    );

    function hentikan() {
        if (bingkai.current !== null) {
            cancelAnimationFrame(bingkai.current);
            bingkai.current = null;
        }
        terakhir.current = 0;
    }

    function mulai() {
        if (habis) {
            return;
        }

        keadaan.current = {
            pemainY: 0,
            pemainV: 0,
            diTanah: true,
            rintangan: [],
            jarak: 0,
            detik: 0,
        };

        terakhir.current = 0;
        setBerakhir(false);
        setBermain(true);
        setPesan(null);
        bingkai.current = requestAnimationFrame(langkah);
    }

    function lompat() {
        if (!bermain) {
            return;
        }

        if (keadaan.current.diTanah) {
            keadaan.current.pemainV = LOMPAT * ((panggung.current?.getBoundingClientRect().height ?? 150) / 150);
            keadaan.current.diTanah = false;
        }
    }

    /**
     * Mengirim hasil permainan ke server.
     *
     * Kirimannya `distance` dan `duration_ms` - BUKAN skornya. Server
     * menghitung ulang, dan bila jawabannya menyebut skor lebih rendah,
     * angka itulah yang benar.
     */
    async function kirim(distance: number, durationMs: number) {
        setMenyimpan(true);

        try {
            const respon = await fetch('/siswa/luang/skor', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': tokenCsrf(),
                },
                body: JSON.stringify({ distance, duration_ms: durationMs }),
            });

            if (respon.status === 409) {
                // Jendelanya tutup di antara bingkai terakhir dan kiriman
                // ini. Panggungnya menutup diri seperti plate 06 - bukan
                // menampilkan galat, karena tidak ada yang bisa diperbaiki.
                setHabis(true);
                return;
            }

            const data = (await respon.json()) as {
                diterima: boolean;
                score: number;
                rekor: number | null;
                peringkat: number | null;
                pesan: string;
            };

            setTercatat(data.rekor);
            setPesan(data.pesan);
        } catch {
            // Jaringan putus. Yang dikatakan laman ini hanya satu: skornya
            // tidak tercatat - bukan "terjadi kesalahan", yang tidak
            // memberi tahu anak apa pun yang bisa ia lakukan.
            setPesan('Skor tidak tersimpan karena koneksi terputus.');
        } finally {
            setMenyimpan(false);
        }
    }

    useEffect(() => {
        gambar();
        return () => hentikan();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [gambar]);

    /* -------------------------------------------------------- Papan ketik */

    useEffect(() => {
        const padaTombol = (e: KeyboardEvent) => {
            if (e.code !== 'Space' && e.code !== 'ArrowUp') {
                return;
            }

            e.preventDefault();

            if (berakhir) {
                return;
            }

            lompat();
        };

        window.addEventListener('keydown', padaTombol);
        return () => window.removeEventListener('keydown', padaTombol);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [bermain, berakhir]);

    const skorSekarang = Math.floor(keadaan.current.jarak);

    return (
        <KerangkaSiswa
            judul="Waktu luang"
            bilah
            kiri={<span className="truncate text-[12px] font-medium text-ink">Waktu luang</span>}
            kanan={
                <span className="border border-line-2 px-1.5 py-0.5 text-[11px] text-ink-2 tabular-nums">
                    {tercatat === null ? 'belum ada rekor' : 'Rekor ' + tercatat.toLocaleString('id-ID')}
                </span>
            }
        >
            <div
                ref={panggung}
                className="relative aspect-[16/10] w-full overflow-hidden rounded-wf border border-line bg-surface"
                onPointerDown={(e) => {
                    e.preventDefault();
                    lompat();
                }}
            >
                <canvas ref={kanvas} className="block h-full w-full" aria-hidden="true" />

                <div className="pointer-events-none absolute inset-x-0 top-0 flex items-baseline gap-2 px-2 py-1.5 font-mono text-[11px] text-ink-3">
                    <span>skor</span>
                    <b className="text-ink tabular-nums">{skorSekarang.toLocaleString('id-ID')}</b>
                    <span className="ml-auto">sisa</span>
                    <b className="text-ink tabular-nums">{formatSisa(sisa)}</b>
                </div>

                {/* Tirai jendela habis - plate 06. Tombol "main lagi" TIDAK
                    ada di sini, dan ketiadaannya adalah pesannya. */}
                {habis && (
                    <div className="absolute inset-0 grid place-content-center justify-items-center gap-1.5 bg-warn-bg/90 px-3 text-center">
                        <b className="font-display text-sm text-warn">
                            {jendela.sesi_berikut?.mapel
                                ? jendela.sesi_berikut.mapel + ' sudah dibuka'
                                : 'Waktu luangnya sudah habis'}
                        </b>
                        <span className="text-[11px] text-ink-2">
                            {jarakAkhir > 0
                                ? 'Skor terakhir ' + jarakAkhir.toLocaleString('id-ID') + ' tersimpan.'
                                : 'Permainan ditutup.'}{' '}
                            Waktunya masuk ujian.
                        </span>
                    </div>
                )}

                {/* Tirai permainan berakhir - plate 05. */}
                {berakhir && !habis && (
                    <div className="absolute inset-0 grid place-content-center justify-items-center gap-1.5 bg-surface/70 px-3 text-center">
                        <b className="font-display text-lg tabular-nums">
                            {jarakAkhir.toLocaleString('id-ID')}
                        </b>
                        <span className="text-[11px] text-ink-2">
                            Terbentur pada rintangan. Ketuk atau tekan Spasi untuk mencoba lagi.
                        </span>
                        <button
                            type="button"
                            className="mt-1 rounded-wf border border-accent bg-accent px-3 py-1.5 font-mono text-[11px] font-medium uppercase tracking-wide text-on-accent"
                            onClick={(e) => {
                                e.stopPropagation();
                                mulai();
                            }}
                        >
                            Main lagi
                        </button>
                    </div>
                )}

                {/* Tirai awal - hanya saat panggungnya belum pernah dimulai. */}
                {!bermain && !berakhir && !habis && (
                    <div className="absolute inset-0 grid place-content-center justify-items-center gap-1.5 bg-surface/60 px-3 text-center">
                        <b className="font-display text-sm">Ketuk untuk mulai</b>
                        <span className="text-[11px] text-ink-3">
                            Ketuk layar atau tekan Spasi untuk melompat.
                        </span>
                        <button
                            type="button"
                            className="mt-1 rounded-wf border border-accent bg-accent px-3 py-1.5 font-mono text-[11px] font-medium uppercase tracking-wide text-on-accent"
                            onClick={(e) => {
                                e.stopPropagation();
                                mulai();
                            }}
                        >
                            Mulai
                        </button>
                    </div>
                )}
            </div>

            {pesan && (
                <p className="border border-line bg-surface-2 px-2.5 py-2 text-[12px] text-ink-2">
                    {pesan}
                </p>
            )}

            {menyimpan && <p className="text-[11px] text-ink-3">Menyimpan skor…</p>}

            <div className="flex items-stretch gap-2">
                <div className="flex-1 border border-line bg-surface px-2.5 py-2">
                    <p className="text-[11px] text-ink-3">Rekor saya</p>
                    <p className="angka text-lg">
                        {tercatat === null ? '—' : tercatat.toLocaleString('id-ID')}
                    </p>
                </div>
                <div className="flex-1 border border-line bg-surface px-2.5 py-2">
                    <p className="text-[11px] text-ink-3">Peringkat</p>
                    <p className="angka text-lg">
                        {peringkat.peringkat === null ? '—' : peringkat.peringkat}
                        <span className="ml-1 text-[11px] text-ink-3">
                            dari {peringkat.pemain}
                        </span>
                    </p>
                </div>
            </div>

            <p className="text-[11px] leading-relaxed text-ink-3">
                Permainan berhenti sendiri bila sesi berikutnya dibuka. Skor diperiksa server
                sebelum dicatat, jadi angka di papan bisa lebih rendah daripada yang tampil di
                panggung.
            </p>

            {habis ? (
                <Link
                    href="/siswa"
                    className="block border border-accent bg-accent px-3 py-2 text-center text-[12px] font-medium text-on-accent"
                >
                    Masuk ujian
                </Link>
            ) : (
                <Link
                    href="/siswa/luang/peringkat"
                    className="block border border-line bg-surface px-3 py-2 text-center text-[12px] font-medium text-ink-2"
                >
                    Lihat papan peringkat{kelas ? ' ' + kelas : ''}
                </Link>
            )}
        </KerangkaSiswa>
    );
}

/** Sisa detik sampai jendelanya tutup, dari jam SERVER. */
function sisaDetik(berakhirPada: string): number {
    return Math.max(0, Math.floor((new Date(berakhirPada).getTime() - Date.now()) / 1000));
}

/** Token CSRF dari cookie - bentuk yang dibaca `PreventRequestForgery`. */
function tokenCsrf(): string {
    const cocok = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return cocok ? decodeURIComponent(cocok[1]) : '';
}

function formatSisa(detik: number): string {
    if (detik <= 0) {
        return '0:00';
    }

    const m = Math.floor(detik / 60);
    const d = detik % 60;

    return m + ':' + String(d).padStart(2, '0');
}

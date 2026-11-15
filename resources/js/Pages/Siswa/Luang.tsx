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
 * Tiga pintu yang bisa menutup panggung, dan ketiganya berbeda
 * =====================================================================
 * Sejak 2026-11-15 permainan tidak lagi hanya terbuka di sela ujian - ia
 * terbuka setiap kali anak itu TIDAK sedang mengerjakan ujian. Yang
 * menutupnya tinggal tiga, dan masing-masing punya kalimatnya sendiri karena
 * yang bisa dilakukan anak itu berbeda:
 *
 *   1. RUANG UJIANNYA DITUTUP (`habis`) - sesi yang masih terpakai berakhir.
 *      Tidak ada tombol "main lagi"; tautannya menuju ujian.
 *   2. KUOTANYA HABIS (`habisKuota`) - sepuluh permainan hari ini terpakai.
 *      Bukan kesalahan, bukan hukuman: besok terbuka lagi.
 *   3. IA TERBENTUR (`berakhir`) - permainan selesai dengan sendirinya.
 *      Inilah satu-satunya yang punya "main lagi".
 *
 * Menggabungkan ketiganya menjadi satu tirai berarti satu kalimat untuk tiga
 * keadaan yang butuh tindakan berbeda - dan anak yang kuotanya habis akan
 * mencari tombol yang memang tidak ada.
 *
 * Batas kerasnya tetap milik SERVER: `jendela.berakhir_pada` dan sisa kuota
 * datang dari sana. Jam peramban hanya dipakai menghitung mundur tampilannya,
 * tidak pernah untuk memutuskan apa pun.
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
        /** Batas keras sesi yang masih terbuka, atau null bila tidak ada. */
        berakhir_pada: string | null;
        sesi_berikut: { label: string; mapel: string | null; dibuka: string } | null;
    };
    rekor: number | null;
    peringkat: { peringkat: number | null; pemain: number; rekor: number | null };
    kelas: string | null;
    kuota: { terpakai: number; sisa: number; batas: number; boleh: boolean };
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

/**
 * Kekuatan lompat, dalam satuan per bingkai 60Hz.
 *
 * Dinaikkan 2026-11-15 dari -11,6 setelah pemainnya berganti dari kotak 22
 * satuan menjadi alien 26 satuan: tinggi lompatan harus melewati rintangan
 * TERTINGGI (29 satuan), bukan tinggi badannya. Dengan gaya lama, alien
 * setinggi itu hanya melewati rintangan pendek - dan permainannya menjadi
 * tidak adil tanpa satu pun angkanya terlihat salah.
 */
const LOMPAT = -13.2;

/**
 * Kecepatan awal, dalam satuan per bingkai 60Hz.
 *
 * Satuannya BINGKAI, bukan detik, karena gerak rintangan dikalikan `dt*60` -
 * dan pada 6,2 itu berarti 372 satuan per detik. Angka itulah yang dikirim ke
 * server sebagai `distance`, dan `PenjagaSkorLuar` menolak di atas 900.
 */
const KECEPATAN_AWAL = 6.2;

/**
 * Pertambahan kecepatan, dalam satuan per bingkai, PER DETIK bermain.
 *
 * ---------------------------------------------------------------------
 * Perlahan dulu, lalu naik - dan pagarnya dihitung, bukan dirasa
 * ---------------------------------------------------------------------
 * Versi pertama memakai 0,0012 per bingkai per bingkai, yang berarti
 * kecepatannya bertambah begitu lambat sehingga dua menit bermain pun tidak
 * terasa berbeda. Yang diminta adalah sebaliknya: mulai pelan, makin lama
 * makin cepat.
 *
 * Angkanya 0,15 per bingkai per detik, jadi:
 *
 *   detik ke-0    6,2  (372/detik)
 *   detik ke-20   9,2  (552/detik)
 *   detik ke-55  14,5  (870/detik)
 *
 * `KECEPATAN_MAKS` di bawah menahannya tepat sebelum batas server (900/detik
 * = 15 per bingkai). Tanpa pagar itu, permainan yang berjalan lama akan
 * menghasilkan jarak yang ditolak `PenjagaSkorLuar` - dan anak yang bermain
 * paling lama justru yang skornya hilang.
 */
const PERCEPATAN_PER_DETIK = 0.15;

/** Pagar kecepatan: 14 per bingkai = 840 per detik, di bawah batas 900. */
const KECEPATAN_MAKS = 14;

/**
 * Bentuk pemain - `pixelated-alien.svg`.
 *
 * Digambar sebagai `Path2D`, bukan `<img>`, karena panggungnya canvas dan
 * gambar yang dimuat belakangan membuat beberapa bingkai pertama tampil
 * kosong. Path-nya disalin apa adanya dari berkas SVG; `viewBox`-nya
 * 587.52 persegi, jadi skalanya satu angka untuk lebar dan tinggi.
 */
const ALIEN_VIEWBOX = 587.52;

const ALIEN_PATH = [
    'M483.896,444.868c0,0.031,0,0.062,0,0.092l0.122-0.092C483.981,444.868,483.938,444.868,483.896,444.868z',
    'M484.013,444.875c0.006-0.007-0.116,0.085-0.116,0.085c0.036,0,0.079,0,0.116,0C484.013,444.936,484.013,444.905,484.013,444.875z',
    'M103.501,444.868l0.122,0.092c0-0.03,0-0.061,0-0.092C103.581,444.868,103.538,444.868,103.501,444.868z',
    'M483.479,91.8c-18.359,0-36.72,0-55.08,0c0,18.36,0,30.6,0,48.96c18.36,0,36.721,0,55.08,0C483.479,122.399,483.479,110.16,483.479,91.8z',
    'M532.439,244.799c-12.239,0-30.6,0-48.96,0c0-18.36,0-36.72,0-55.08c-18.359,0-36.72,0-55.08,0c0-12.24,0-30.6,0-48.96c-18.359,0-36.72,0-55.079,0c0,18.36,0,36.72,0,48.96c-24.48,0-52.981,0-79.561,0c-24.48,0-55.08,0-79.56,0c0-12.24,0-30.6,0-48.96c-18.36,0-36.72,0-55.08,0c0,18.36,0,36.72,0,48.96c-18.36,0-36.72,0-55.08,0c0,18.36,0,36.72,0,55.08c-18.36,0-36.72,0-48.96,0c0-36.72,0-67.32,0-104.04c-18.36,0-36.72,0-55.08,0c0,67.32,0,134.64,0,201.96c18.36,0,30.6,0,48.96,0c0,18.359,0,36.72,0,55.08c18.36,0,36.72,0,55.08,0c0,11.224,0,29.522,0,47.393c-13.941,0.196-27.155,0.178-41.102,0.367c-2.956,0.037-7.858,0.007-7.858,0.007c0,16.793,0,31.793,0,50.153c12.24,0,31.64,0,48.439,0c0-17.778,0.686-32.896,0.729-48.96h0.006c18.293,0,36.58,0,54.866,0c0-18.36,0-36.721,0-48.96c48.96,0,91.8,0,134.64,0c44.297,0,85.68,0,134.64,0c0,12.239,0,30.6,0,48.96c18.293,0,36.58,0,54.866,0c0,0,0.006,0,0.013,0c0.036,16.064,0.728,31.182,0.728,48.96c16.794,0,36.194,0,48.434,0c0-18.36,0-33.36,0-50.153c0,0-4.901,0.03-7.857-0.007c-13.947-0.184-27.161-0.165-41.103-0.367c0-17.87,0-36.169,0-47.393c18.36,0,36.721,0,55.08,0c0-18.36,0-36.721,0-55.08c18.36,0,30.601,0,48.96,0c0-67.32,0-134.64,0-201.96c-18.359,0-36.72,0-55.08,0C532.439,177.479,532.439,208.08,532.439,244.799zM214.2,293.76c0,9.425,0,19.994,0,30.6c-18.36,0-36.72,0-55.08,0c0-10.605,0-21.175,0-30.6c0-6.206,0-12.393,0-18.36c18.36,0,36.72,0,55.08,0C214.2,281.367,214.2,287.554,214.2,293.76zM428.399,293.76c0,9.425,0,19.994,0,30.6c-18.359,0-36.72,0-55.079,0c0-10.605,0-21.175,0-30.6c0-6.206,0-12.393,0-18.36c18.359,0,36.72,0,55.079,0C428.399,281.367,428.399,287.554,428.399,293.76z',
    'M104.04,91.8c0,18.36,0,30.6,0,48.96c18.36,0,36.72,0,55.08,0c0-18.36,0-30.6,0-48.96C140.76,91.8,122.4,91.8,104.04,91.8z',
    'M103.507,444.967c0.037,0,0.08,0,0.116,0c0,0-0.122-0.092-0.116-0.086C103.507,444.905,103.507,444.936,103.507,444.967z',
];

/** Bentuk alien, disiapkan sekali - bukan setiap bingkai. */
const alien: Path2D | null = (() => {
    if (typeof Path2D === 'undefined') {
        return null;
    }

    try {
        return new Path2D(ALIEN_PATH.join(' '));
    } catch {
        // `Path2D` ada tetapi path-nya ditolak: panggungnya jatuh ke kotak
        // biasa, dan permainannya tetap bisa dimainkan.
        return null;
    }
})();

/** Ukuran pemain di panggung, dalam satuan sebelum dikali `skala`. */
const PEMAIN_TINGGI = 26;
const PEMAIN_LEBAR = 26;
const KAKI_PEMAIN = 14;

export default function Luang({ jendela, rekor, peringkat, kelas, kuota }: Props) {
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
    // `habis` = batas kerasnya tempus (sesi yang masih terbuka ditutup).
    // `berakhir` = anak terbentur. Dua keadaan berbeda, dan tombolnya pun
    // berbeda: yang pertama TIDAK punya "main lagi".
    const [habis, setHabis] = useState(false);
    const [berakhir, setBerakhir] = useState(false);

    const [jarakAkhir, setJarakAkhir] = useState(0);

    // Sisa kuota hari ini. Dikirim server, dan diperbarui setiap kiriman -
    // angka yang dihitung peramban sendiri akan berbeda dari yang menolak
    // kirimannya.
    const [sisaKuota, setSisaKuota] = useState(kuota.sisa);
    const habisKuota = sisaKuota <= 0;

    // Hitung mundur ke pembukaan sesi berikutnya. `null` berarti tidak ada
    // ujian lain - dan itu bukan tenggat, hanya keterangan.
    const [sisaWaktu, setSisaWaktu] = useState<number | null>(() =>
        jendela.sesi_berikut === null ? null : sisaDetik(jendela.sesi_berikut.dibuka),
    );

    const [menyimpan, setMenyimpan] = useState(false);
    const [pesan, setPesan] = useState<string | null>(null);
    const [tercatat, setTercatat] = useState<number | null>(rekor);

    // Prop `rekor` berganti setiap kali halaman dimuat ulang; state di atas
    // hanya nilai awalnya.
    useEffect(() => setTercatat(rekor), [rekor]);
    useEffect(() => setSisaKuota(kuota.sisa), [kuota.sisa]);

    /* ------------------------------------------------------- Jam jendela */

    useEffect(() => {
        if (jendela.berakhir_pada === null) {
            // Tidak ada sesi yang masih terbuka - tidak ada yang perlu
            // dihitung. `berakhir_pada` null berarti batasnya hanya kuota
            // harian, dan itu tidak berjalan dengan waktu.
            return;
        }

        if (habis) {
            return;
        }

        const tiap = window.setInterval(() => {
            const s = sisaDetik(jendela.berakhir_pada as string);

            if (s <= 0) {
                // Ruang ujiannya ditutup. Permainannya dihentikan di sini,
                // bukan menunggu bingkai berikutnya - dan skornya TIDAK
                // dikirim, karena server akan menolaknya dan pesan penolakan
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

    /*
     * Hitung mundur ke sesi berikutnya - keterangan, BUKAN tenggat.
     *
     * Sejak 2026-11-15 permainan tidak ditutup oleh jam buka sesi berikutnya;
     * yang ditutup adalah ruang ujian yang masih terpakai (lihat efek di
     * atas). Angka ini tetap ditampilkan karena anak yang menunggu memang
     * ingin tahu berapa lama lagi ia punya waktu.
     */
    useEffect(() => {
        if (jendela.sesi_berikut === null) {
            return;
        }

        const tiap = window.setInterval(() => {
            setSisaWaktu(sisaDetik(jendela.sesi_berikut!.dibuka));
        }, 1000);

        return () => window.clearInterval(tiap);
    }, [jendela.sesi_berikut]);

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

        // Pemain - si alien. Digambar dari `Path2D` yang sudah disiapkan,
        // diskalakan dari `viewBox` 587.52 persegi milik SVGitunya.
        //
        // `fillStyle` sengaja bukan biru kotak lama: alien ini punya mata
        // yang dilubangi, dan warna gelap membuat lubangnya terbaca sebagai
        // mata, bukan sebagai bayangan.
        const tinggi = PEMAIN_TINGGI * skala;
        const lebar = PEMAIN_LEBAR * skala;
        const kiri = KAKI_PEMAIN * skala;
        const atas = tanah - tinggi + s.pemainY;

        if (alien !== null) {
            k.save();
            k.translate(kiri, atas);
            k.scale(tinggi / ALIEN_VIEWBOX, tinggi / ALIEN_VIEWBOX);
            k.fillStyle = '#1e4fa3';
            k.fill(alien);
            k.restore();
        } else {
            // Cadangan bila `Path2D` tidak ada: kotak yang sama dengan
            // pemain versi lama. Bentuknya berbeda, tetapi permainannya tidak
            // pernah kosong.
            k.fillStyle = '#1e4fa3';
            k.fillRect(kiri, atas, lebar, tinggi);
        }

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

            // Kecepatan menaik PER DETIK, lalu ditahan `KECEPATAN_MAKS`.
            // Lihat catatan `PERCEPATAN_PER_DETIK` tentang mengapa pagarnya
            // ada: tanpa itu permainan panjang menghasilkan jarak yang
            // ditolak server, dan anak yang bermain paling lama justru yang
            // skornya hilang.
            const kecepatan = Math.min(
                KECEPATAN_AWAL + PERCEPATAN_PER_DETIK * s.detik,
                KECEPATAN_MAKS,
            );
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

            // Kotak tabrakan pemain, memakai konstanta yang SAMA dengan
            // gambarnya. Dua set angka yang berbeda - satu untuk menggambar,
            // satu untuk menabrak - berarti pemain yang terlihat lewat
            // rintangan, atau terbentur di udara kosong.
            const kakiPemain = KAKI_PEMAIN * skala;
            const lebarPemain = PEMAIN_LEBAR * skala;
            const tinggiPemain = PEMAIN_TINGGI * skala;

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

            if (jendela.berakhir_pada !== null && sisaDetik(jendela.berakhir_pada) <= 0) {
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
        // Dua pintu, dan keduanya harus tertutup di sini - bukan hanya di
        // tombolnya: panggungnya juga bisa dimulai lewat ketukan di
        // area mana pun (lihat penangan pointerdown).
        if (habis || habisKuota) {
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
                // Ujiannya dimulai di antara bingkai terakhir dan kiriman
                // ini. Panggungnya menutup diri - bukan menampilkan galat,
                // karena tidak ada yang bisa diperbaiki anak itu.
                setHabis(true);
                return;
            }

            if (respon.status === 429) {
                // Kuota hariannya habis. Kiriman ini TIDAK tersimpan, dan
                // angka di layar harus ikut berubah - kalau tidak, anak itu
                // akan menekan "Main lagi" berkali-kali dan setiap kali
                // ditolak dengan kalimat yang sama.
                const tolak = (await respon.json()) as {
                    pesan: string;
                    kuota: { sisa: number };
                };

                setSisaKuota(tolak.kuota.sisa);
                setPesan(tolak.pesan);
                setBermain(false);
                return;
            }

            const data = (await respon.json()) as {
                diterima: boolean;
                score: number;
                rekor: number | null;
                peringkat: number | null;
                pesan: string;
                kuota: { sisa: number };
            };

            setTercatat(data.rekor);
            setPesan(data.pesan);
            setSisaKuota(data.kuota.sisa);
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
                    <span className="ml-auto">sisa main</span>
                    <b className="text-ink tabular-nums">{sisaKuota}</b>
                </div>

                {/* Tirai ruang ujian ditutup. Tombol "main lagi" TIDAK ada di
                    sini, dan ketiadaannya adalah pesannya. */}
                {habis && (
                    <div className="absolute inset-0 grid place-content-center justify-items-center gap-1.5 bg-warn-bg/90 px-3 text-center">
                        <b className="font-display text-sm text-warn">
                            {jendela.sesi_berikut?.mapel
                                ? jendela.sesi_berikut.mapel + ' sudah dibuka'
                                : 'Sesi ujiannya sudah ditutup'}
                        </b>
                        <span className="text-[11px] text-ink-2">
                            {jarakAkhir > 0
                                ? 'Skor terakhir ' + jarakAkhir.toLocaleString('id-ID') + ' tersimpan.'
                                : 'Permainan ditutup.'}
                        </span>
                    </div>
                )}

                {/* Kuota harian habis - keadaan ketiga, dan kalimatnya sendiri.
                    Ia BUKAN "terbentur" dan bukan "sesi ditutup": yang habis
                    adalah jatahnya hari ini, dan besok ia bisa main lagi. */}
                {habisKuota && !habis && (
                    <div className="absolute inset-0 grid place-content-center justify-items-center gap-1.5 bg-surface/80 px-3 text-center">
                        <b className="font-display text-sm">
                            Sisa {kuota.batas} permainan hari ini sudah dipakai
                        </b>
                        <span className="text-[11px] text-ink-2">
                            Besok jatahnya terbuka lagi. Rekormu hari ini tetap dihitung.
                        </span>
                    </div>
                )}

                {/* Tirai permainan berakhir. */}
                {berakhir && !habis && !habisKuota && (
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
                {!bermain && !berakhir && !habis && !habisKuota && (
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
                <div className="flex-1 border border-line bg-surface px-2.5 py-2">
                    <p className="text-[11px] text-ink-3">Sisa main</p>
                    <p className={'angka text-lg ' + (habisKuota ? 'text-bad' : '')}>
                        {sisaKuota}
                        <span className="ml-1 text-[11px] text-ink-3">dari {kuota.batas}</span>
                    </p>
                </div>
            </div>

            {/* Hitung mundut ke sesi berikutnya - keterangan, bukan tenggat.
                Tidak dirender sama sekali bila anak itu tidak punya ujian
                lagi, karena "tidak ada sesi berikutnya" bukan hal yang perlu
                diberitahukan sebagai angka. */}
            {jendela.sesi_berikut !== null && sisaWaktu !== null && (
                <p className="text-[11px] leading-relaxed text-ink-3">
                    {jendela.sesi_berikut.mapel ?? 'Ujian berikutnya'} dibuka dalam{' '}
                    <b className="text-ink-2 tabular-nums">{formatSisa(sisaWaktu)}</b>. Setelah
                    itu permainan tetap bisa dibuka selama kamu belum mulai mengerjakan.
                </p>
            )}

            <p className="text-[11px] leading-relaxed text-ink-3">
                Permainan tertutup selama kamu mengerjakan ujian, dan saat ruang ujian yang masih
                terpakai ditutup. Jatahnya {kuota.batas} permainan per hari. Skor diperiksa server
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

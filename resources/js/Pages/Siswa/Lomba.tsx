import { Link, router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import KerangkaSiswa from '@/Layouts/KerangkaSiswa';

/**
 * Lomba hitung cepat dua pemain - undangan lewat kode.
 * `/siswa/luang/lomba` dan `/siswa/luang/lomba/{kode}`.
 *
 * =====================================================================
 * Satu halaman, lima fase
 * =====================================================================
 * `menunggu` (belum ada lawan) - kode undangan dan isian kode.
 * `hitung_mundur` - lawan sudah masuk, jamnya berjalan menuju soal pertama.
 * `berjalan` - enam puluh detik menjawab.
 * `selesai` - skor kedua pemain.
 * `batal` - lomba berhenti sebelum waktunya, dengan sebabnya.
 *
 * Fase TIDAK dihitung di sini; ia datang dari server setiap 1,5 detik.
 * Peramban yang menghitung sendiri akan melihat dua ponsel dengan jam
 * berbeda memulai lomba pada detik yang berbeda - dan itu berarti satu anak
 * punya dua detik lebih banyak untuk menjawab.
 *
 * =====================================================================
 * Soal datang SEKALI, dan jawabannya tidak ikut
 * =====================================================================
 * Yang dikirim server adalah `teks` dan `pilihan`; `jawaban` tinggal di
 * server. Peramban ini TIDAK bisa memeriksa jawabannya sendiri - dan itu
 * disengaja. Kalau kuncinya ikut, anak yang membuka DevTools menang tanpa
 * berhitung.
 *
 * Akibatnya: memilih jawaban yang benar-benar benar tidak memberi umpan
 * balik hijau/merah, karena peramban tidak tahu mana yang benar. Yang
 * ditampilkan hanyalah bahwa pilihannya sudah dikirim, lalu soal berikutnya.
 * Ini lebih jujur daripada memberi umpan balik yang bisa dipalsukan.
 *
 * =====================================================================
 * Skor dikirim SEKALI, sesudah lombanya selesai
 * =====================================================================
 * Bukan per soal, bukan tiap lima detik. Server menolak kiriman sebelum
 * `fase` menjadi `selesai`, dan menolak kiriman kedua pada lomba yang sama -
 * lihat `LombaSiswaController::skor()`.
 */

type Soal = { teks: string; pilihan: number[] };

type Lomba = {
    kode: string;
    fase: 'menunggu' | 'hitung_mundur' | 'berjalan' | 'selesai' | 'batal';
    sisa: number;
    alasan_batal: string | null;
    saya: 'host' | 'tamu';
    host: string;
    tamu: string | null;
    soal: Soal[] | null;
    skor: { host: number | null; tamu: number | null };
};

type Props = {
    jendela: {
        berakhir_pada: string | null;
        sesi_berikut: { label: string; mapel: string | null; dibuka: string } | null;
    };
    lomba: Lomba | null;
    kuota: { terpakai: number; sisa: number; batas: number; boleh: boolean };
    rekor: number | null;
    sekarang: string;
};

/** Jeda antar denyut. Lihat `PembatasLaju::LOMBA_PER_SISWA`. */
const JEDA_DENYUT_MS = 1500;

export default function Lomba({ jendela, lomba: awal, kuota, rekor }: Props) {
    const [lomba, setLomba] = useState<Lomba | null>(awal);
    const [kode, setKode] = useState('');
    const [galat, setGalat] = useState<string | null>(null);

    /* ------------------------------------------------------------ bermain */

    const [nomor, setNomor] = useState(0);
    const [terkirim, setTerkirim] = useState(false);

    // Lembar jawaban: satu angka per soal, berurutan. Server yang menilainya
    // terhadap kunci yang tidak pernah dikirim ke sini - lihat catatan
    // berkas ini tentang mengapa poin tidak dihitung di peramban.
    const lembarRef = useRef<number[]>([]);

    const [sisaMain, setSisaMain] = useState(0);
    const mulaiPada = useRef(0);

    /**
     * ---------------------------------------------------------------------
     * Prop `lomba` bisa berubah TANPA komponennya dipasang ulang
     * ---------------------------------------------------------------------
     * `/siswa/luang/lomba` dan `/siswa/luang/lomba/{kode}` merender
     * komponen yang SAMA, dan Inertia memakai ulang instance yang sudah ada
     * alih-alih memasang yang baru. Akibatnya `useState(awal)` hanya
     * berjalan sekali - saat ruang tunggu masih kosong - dan prop `lomba`
     * yang datang sesudah membuat undangan diabaikan.
     *
     * Gejalanya persis seperti yang terlihat: alamatnya berubah, isinya
     * tidak, sampai halamannya dimuat ulang paksa. Jebakan yang sama sudah
     * tercatat untuk `useForm` + Modal.
     *
     * Karena itu prop-nya disalin lewat efek, dan seluruh keadaan bermain
     * direset bila lombanya berganti - lembar jawaban milik lomba lama tidak
     * boleh terbawa ke lomba baru.
     */
    useEffect(() => {
        setLomba(awal);

        mulaiPada.current = 0;
        lembarRef.current = [];
        setNomor(0);
        setTerkirim(false);
        setGalat(null);
    }, [awal]);

    const habisKuota = kuota.sisa <= 0;
    const kodeLomba = lomba?.kode ?? null;

    // Batas keras sesi yang masih terbuka. Denyut sudah menghentikan lomba
    // saat pemainnya masuk ujian, tetapi angka ini tetap ditampilkan supaya
    // anak yang menunggu lawannya tahu berapa lama ia punya waktu.
    const sisaSesi = jendela.sesi_berikut === null ? null : sisaDetik(jendela.sesi_berikut.dibuka);

    /** Denyut keadaan - satu-satunya sumber kebenaran fase. */
    const denyut = useCallback(async () => {
        if (kodeLomba === null) {
            return;
        }

        try {
            const respon = await fetch(`/siswa/luang/lomba/${kodeLomba}/keadaan`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });

            if (!respon.ok) {
                return;
            }

            const baru = (await respon.json()) as Lomba & { pesan?: string };
            setLomba((lama) => (lama === null ? lama : { ...lama, ...baru }));

            // Hitung mundur dihitung dari `sisa` yang dikirim server, bukan
            // dari jam peramban - lihat catatan berkas ini.
            setSisaMain(baru.sisa ?? 0);
        } catch {
            // Jaringan putus BUKAN galat yang ditampilkan di tengah lomba:
            // layar yang berkedip merah akan membuat anak berhenti menjawab.
            // Angka terakhirnya tetap terbaca, dan denyut berikutnya
            // menyambung sendiri.
        }
    }, [kodeLomba]);

    useEffect(() => {
        if (kodeLomba === null) {
            return;
        }

        void denyut();

        const jam = window.setInterval(() => void denyut(), JEDA_DENYUT_MS);

        return () => window.clearInterval(jam);
    }, [kodeLomba, denyut]);

    /* ------------------------------------------------------- soal berjalan */

    // `mulaiPada` dipasang saat fase berganti ke `berjalan`: `sisa` yang
    // dikirim server adalah titik acuannya, dan satu detik di layar
    // dihitung dari situ.
    useEffect(() => {
        if (lomba?.fase !== 'berjalan') {
            return;
        }

        // Lomba baru dimulai: lembar jawaban kembali kosong.
        if (mulaiPada.current === 0) {
            mulaiPada.current = Date.now();
            setNomor(0);
            lembarRef.current = [];
            setTerkirim(false);
        }
    }, [lomba?.fase]);

    /* ------------------------------------------------------------ kiriman */

    const kirimSkor = useCallback(async () => {
        if (kodeLomba === null || terkirim) {
            return;
        }

        setTerkirim(true);

        const durasi = Math.max(1000, Date.now() - mulaiPada.current);

        try {
            await fetch(`/siswa/luang/lomba/${kodeLomba}/skor`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': tokenCsrf(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({ jawaban: lembarRef.current, duration_ms: durasi }),
            });
        } catch {
            setGalat('Skor tidak tersimpan karena koneksi terputus.');
        }
    }, [kodeLomba, terkirim]);

    // Fase `selesai` memicu SATU kiriman. `terkirim` menjaga agar denyut
    // berikutnya - yang tetap datang tiap 1,5 detik - tidak mengirim ulang.
    useEffect(() => {
        if (lomba?.fase === 'selesai') {
            void kirimSkor();
        }
    }, [lomba?.fase, kirimSkor]);

    const jawab = (pilihan: number) => {
        if (lomba?.fase !== 'berjalan' || lomba.soal === null) {
            return;
        }

        // Soal habis: ketukan berikutnya DIABAIKAN, bukan menambah lembar.
        //
        // Tanpa pagar ini `nomor` mentok di soal terakhir sementara
        // `lembarRef` terus tumbuh - dan anak yang mengetuk cepat di detik
        // terakhir mengirim lembar yang lebih panjang dari daftar soalnya.
        // Server menolak seluruh kirimannya (`max:120`), sehingga skor yang
        // sudah ia kumpulkan hilang karena ketukan sesudah soal terakhir.
        if (lembarRef.current.length >= lomba.soal.length) {
            return;
        }

        // Yang dicatat hanyalah PILIHANNYA. Peramban tidak tahu mana yang
        // benar - lihat catatan berkas ini - jadi ia tidak bisa menghitung
        // skornya, dan juga tidak bisa menampilkan umpan balik benar/salah.
        // Server menilainya sesudah lombanya selesai.
        lembarRef.current = [...lembarRef.current, pilihan];

        setNomor(lembarRef.current.length);
    };

    const buat = () => {
        router.post('/siswa/luang/lomba', {}, { preserveScroll: true });
    };

    const masuk = () => {
        router.post(
            '/siswa/luang/lomba/masuk',
            { kode: kode.toUpperCase() },
            {
                preserveScroll: true,
                onError: () => setGalat('Kode undangan itu tidak berlaku. Periksa lagi, atau minta kode baru.'),
            },
        );
    };

    /* --------------------------------------------------------------- layar */

    return (
        <KerangkaSiswa
            judul="Lomba"
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
                    <span className="truncate text-[12px] font-medium text-ink">Lomba</span>
                </div>
            }
            kanan={
                <span className="border border-line-2 px-1.5 py-0.5 text-[11px] text-ink-2 tabular-nums">
                    {rekor === null ? 'belum ada rekor' : 'Rekor ' + rekor.toLocaleString('id-ID')}
                </span>
            }
        >
            {galat !== null && (
                <p className="border border-bad/40 bg-bad-bg/40 px-2.5 py-2 text-[12px] text-bad">{galat}</p>
            )}

            {lomba === null ? (
                <RuangTunggu
                    kode={kode}
                    padaKode={setKode}
                    padaBuat={buat}
                    padaMasuk={masuk}
                    habisKuota={habisKuota}
                />
            ) : (
                <>
                    {lomba.fase === 'menunggu' && <MenungguUndangan lomba={lomba} />}
                    {lomba.fase === 'hitung_mundur' && <HitungMundur lomba={lomba} sisa={sisaMain} />}
                    {lomba.fase === 'berjalan' && lomba.soal !== null && (
                        <Arena lomba={lomba} nomor={nomor} sisa={sisaMain} padaJawab={jawab} />
                    )}
                    {lomba.fase === 'selesai' && <Hasil lomba={lomba} saya={lomba.saya} />}
                    {lomba.fase === 'batal' && <Batal lomba={lomba} />}
                </>
            )}

            <p className="text-[11px] leading-relaxed text-ink-3">
                Lomba berjalan selama 60 detik dengan soal yang sama untuk kedua pemain. Skor diperiksa
                server sebelum dicatat. Lomba berhenti bila salah satu pemain masuk ujian.
            </p>

            {jendela.sesi_berikut !== null && sisaSesi !== null && (
                <p className="text-[11px] leading-relaxed text-ink-3">
                    {jendela.sesi_berikut.mapel ?? 'Ujian berikutnya'} dibuka dalam{' '}
                    <b className="text-ink-2 tabular-nums">{formatSisa(sisaSesi)}</b>. Ujian selalu
                    didahulukan: masuk ke sana sebelum hitungan itu habis.
                </p>
            )}

            <Link
                href="/siswa/luang"
                className="block border border-line bg-surface px-3 py-2 text-center text-[12px] font-medium text-ink-2 hover:bg-surface-2"
            >
                Kembali ke waktu luang
            </Link>
        </KerangkaSiswa>
    );
}

/* ---------------------------------------------------------------- bagian */

function RuangTunggu({
    kode,
    padaKode,
    padaBuat,
    padaMasuk,
    habisKuota,
}: {
    kode: string;
    padaKode: (v: string) => void;
    padaBuat: () => void;
    padaMasuk: () => void;
    habisKuota: boolean;
}) {
    return (
        <div className="space-y-3">
            <div className="border border-line bg-surface px-3 py-3">
                <b className="font-display text-sm">Buat lomba</b>
                <p className="mt-1 text-[11px] leading-relaxed text-ink-3">
                    Anda mendapat kode enam karakter. Berikan kodenya kepada teman yang akan melawan Anda.
                </p>
                <button
                    type="button"
                    onClick={padaBuat}
                    disabled={habisKuota}
                    className="mt-2 w-full rounded-wf border border-accent bg-accent px-3 py-2 font-mono text-[11px] font-medium uppercase tracking-wide text-on-accent disabled:cursor-not-allowed disabled:opacity-40"
                >
                    {habisKuota ? 'Kuota hari ini habis' : 'Buat undangan'}
                </button>
            </div>

            <div className="border border-line bg-surface px-3 py-3">
                <b className="font-display text-sm">Masukkan kode</b>
                <p className="mt-1 text-[11px] leading-relaxed text-ink-3">
                    Ketik kode undangan dari teman Anda.
                </p>
                <input
                    value={kode}
                    onChange={(e) => padaKode(e.target.value.toUpperCase().slice(0, 6))}
                    maxLength={6}
                    inputMode="text"
                    autoCapitalize="characters"
                    autoComplete="off"
                    placeholder="ABC123"
                    className="mt-2 w-full rounded-wf border border-line bg-surface-2 px-3 py-2 text-center font-mono text-lg tracking-[0.3em] text-ink"
                />
                <button
                    type="button"
                    onClick={padaMasuk}
                    disabled={kode.length !== 6 || habisKuota}
                    className="mt-2 w-full rounded-wf border border-line bg-surface-2 px-3 py-2 font-mono text-[11px] font-medium uppercase tracking-wide text-ink-2 disabled:cursor-not-allowed disabled:opacity-40"
                >
                    Masuk
                </button>
            </div>
        </div>
    );
}

function MenungguUndangan({ lomba }: { lomba: Lomba }) {
    return (
        <div className="border border-line bg-surface px-3 py-4 text-center">
            <p className="text-[11px] text-ink-3">Kode undangan</p>
            <b className="mt-1 block font-mono text-3xl tracking-[0.3em] text-ink">{lomba.kode}</b>
            <p className="mt-2 text-[11px] leading-relaxed text-ink-2">
                Berikan kode ini kepada teman Anda. Lomba dimulai begitu ia masuk.
            </p>
            <p className="mt-2 text-[11px] text-ink-3">Menunggu lawan…</p>
        </div>
    );
}

function HitungMundur({ lomba, sisa }: { lomba: Lomba; sisa: number }) {
    return (
        <div className="border border-line bg-surface px-3 py-6 text-center">
            <p className="text-[11px] text-ink-3">
                {lomba.host} melawan {lomba.tamu ?? '…'}
            </p>
            <b className="mt-2 block font-display text-4xl tabular-nums text-ink">{Math.max(sisa, 0)}</b>
            <p className="mt-2 text-[11px] text-ink-2">Bersiap. Soal pertama muncul sesudah hitungan ini.</p>
        </div>
    );
}

function Arena({
    lomba,
    nomor,
    sisa,
    padaJawab,
}: {
    lomba: Lomba;
    nomor: number;
    sisa: number;
    padaJawab: (n: number) => void;
}) {
    const soal = lomba.soal?.[nomor];

    if (soal === undefined) {
        return null;
    }

    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between border-b border-line pb-2 font-mono text-[11px]">
                <span className="text-ink-3">
                    waktu <b className={'tabular-nums ' + (sisa <= 10 ? 'text-bad' : 'text-ink')}>{sisa}d</b>
                </span>
                <span className="text-ink-3">
                    soal <b className="text-ink tabular-nums">{nomor + 1}</b> dari{' '}
                    <b className="text-ink tabular-nums">{lomba.soal?.length ?? 0}</b>
                </span>
                <span className="text-ink-3">lawan menjawab sendiri</span>
            </div>

            <div className="grid min-h-[5.5rem] place-items-center rounded-wf border border-line bg-surface-2/40 px-3 py-4">
                <span className="font-mono text-2xl font-semibold tracking-wide text-ink tabular-nums">
                    {soal.teks} = ?
                </span>
            </div>

            <div className="grid grid-cols-2 gap-2">
                {soal.pilihan.map((angka, idx) => (
                    <button
                        key={`${nomor}-${angka}-${idx}`}
                        type="button"
                        onClick={() => padaJawab(angka)}
                        className="flex items-center justify-between rounded-wf border border-line bg-surface px-3 py-3 font-mono text-base font-medium text-ink transition-colors hover:border-accent hover:bg-accent-soft/40 active:bg-accent-soft"
                    >
                        <span className="text-[10px] text-ink-3">{idx + 1}</span>
                        <span className="tabular-nums">{angka}</span>
                        <span className="w-2" aria-hidden="true" />
                    </button>
                ))}
            </div>
        </div>
    );
}

function Hasil({ lomba, saya }: { lomba: Lomba; saya: 'host' | 'tamu' }) {
    const { host, tamu } = lomba.skor;
    const sayaPoin = saya === 'host' ? host : tamu;
    const lawanPoin = saya === 'host' ? tamu : host;
    const lawanNama = saya === 'host' ? lomba.tamu : lomba.host;

    const menang = sayaPoin !== null && lawanPoin !== null && sayaPoin > lawanPoin;
    const seri = sayaPoin !== null && lawanPoin !== null && sayaPoin === lawanPoin;

    return (
        <div className="border border-line bg-surface px-3 py-4 text-center">
            <b className={'font-display text-lg ' + (menang ? 'text-ok' : seri ? 'text-ink' : 'text-ink-2')}>
                {menang ? 'Anda menang' : seri ? 'Seri' : 'Anda belum menang'}
            </b>

            <div className="mt-3 flex items-stretch gap-2">
                <div className="flex-1 border border-line bg-surface-2 px-2 py-2">
                    <p className="text-[11px] text-ink-3">Anda</p>
                    <p className="angka text-xl">{sayaPoin === null ? '…' : sayaPoin.toLocaleString('id-ID')}</p>
                </div>
                <div className="flex-1 border border-line bg-surface-2 px-2 py-2">
                    <p className="text-[11px] text-ink-3">{lawanNama ?? 'Lawan'}</p>
                    <p className="angka text-xl">
                        {lawanPoin === null ? '…' : lawanPoin.toLocaleString('id-ID')}
                    </p>
                </div>
            </div>

            {sayaPoin === null && (
                <p className="mt-2 text-[11px] text-ink-3">
                    Skor Anda belum tercatat. Muat ulang laman ini bila lombanya sudah selesai.
                </p>
            )}
        </div>
    );
}

function Batal({ lomba }: { lomba: Lomba }) {
    const kalimat =
        lomba.alasan_batal === 'ujian_dimulai'
            ? 'Ujian sudah dibuka, jadi lomba ini berhenti.'
            : lomba.alasan_batal === 'dibatalkan_pembuat'
              ? 'Lomba ini dibatalkan oleh salah satu pemainnya.'
              : lomba.alasan_batal === 'lawan_putus'
                ? 'Lawan Anda berhenti bermain, jadi lomba ini berakhir.'
                : 'Lomba ini sudah tidak berlaku.';

    return (
        <div className="border border-warn/40 bg-warn-bg/40 px-3 py-4 text-center">
            <b className="font-display text-sm text-warn">Lomba berhenti</b>
            <p className="mt-1 text-[11px] leading-relaxed text-ink-2">{kalimat}</p>
            <Link
                href="/siswa/luang/lomba"
                className="mt-3 inline-block rounded-wf border border-accent bg-accent px-3 py-1.5 font-mono text-[11px] font-medium uppercase tracking-wide text-on-accent"
            >
                Lomba baru
            </Link>
        </div>
    );
}

/** Token CSRF dari cookie - bentuk yang dibaca `PreventRequestForgery`. */
function tokenCsrf(): string {
    const cocok = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return cocok ? decodeURIComponent(cocok[1]) : '';
}

/** Sisa detik sampai jendelanya tutup, dari jam SERVER. */
function sisaDetik(berakhirPada: string): number {
    return Math.max(0, Math.floor((new Date(berakhirPada).getTime() - Date.now()) / 1000));
}

function formatSisa(detik: number): string {
    if (detik <= 0) {
        return '0:00';
    }

    const m = Math.floor(detik / 60);
    const d = detik % 60;

    return m + ':' + String(d).padStart(2, '0');
}

import { Link, router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import KerangkaPonsel from '@/Layouts/KerangkaPonsel';
import { Chip, Inbox, Modal } from '@/Components/ui';
import { BarisKursi, KpiPonsel, KotakPonsel } from '@/Components/ponsel';

/**
 * Plate 12 - presensi ruang, daftar kursi.
 *
 * =====================================================================
 * DUA TINGKAT PER KURSI, bukan tabel lima kolom
 * =====================================================================
 * Aturan yang wajib dituruti, dan ditulis juga di `BarisKursi`: pengawas
 * memegang ponsel dengan satu tangan sambil berdiri. Laman yang menuntut
 * geseran mendatar dibaca sambil menggeser, dan baris yang tergeser membuat
 * orang salah menandai - dan salah tandai di sini berarti siswa yang hadir
 * tercatat tidak hadir lalu ditolak sistem saat menekan Mulai.
 *
 * ---------------------------------------------------------------------
 * Dua kolom yang sengaja dipisah: presensi dan pengerjaan
 * ---------------------------------------------------------------------
 * Presensi adalah keputusan pengawas; pengerjaan adalah keadaan yang
 * dilaporkan server dan tidak bisa diketik siapa pun. Peserta yang hadir
 * tapi belum mulai (mungkin lupa token) dan peserta yang hadir tapi
 * koneksinya putus (mungkin perangkatnya mati) adalah dua masalah berbeda,
 * dan menyatukannya dalam satu kolom menyembunyikan keduanya.
 *
 * ---------------------------------------------------------------------
 * Satu ketukan per siswa
 * ---------------------------------------------------------------------
 * Kalau setiap penandaan butuh konfirmasi, mengawasi 32 siswa memakan waktu
 * lebih lama daripada ujiannya sendiri. Karena itu tombol "Hadir" langsung
 * mengirim, dan yang tersisa hanya "Ubah" untuk membuka lapisan plate 13.
 */
type Kursi = {
    record_id: number;
    peserta_id: number;
    kursi: number;
    nama: string;
    nis: string | null;
    kelas: string | null;
    status: string;
    status_label: string;
    status_pendek: string;
    status_nada: string;
    pengerjaan: {
        ada: boolean;
        label: string;
        nada: string;
        terhubung: boolean | null;
        pelanggaran: number;
        terakhir: string | null;
    };
    sisa: string | null;
    /** `pindai 07:32` / `pengawas 07:28`, atau null bila belum ada presensi. */
    sumber: { jenis: 'pindai' | 'pengawas'; jam: string | null } | null;
};

/** Kode pindai yang sedang tampil - plate A1/A2. */
type Pindai = {
    kode: string;
    url: string;
    qr: string;
    sisa: number;
    umur: number;
};

/** Satu pengakuan siswa yang belum dikonfirmasi - plate A3. */
type Pengakuan = {
    id: number;
    peserta_id: number;
    kursi: number | null;
    nama: string;
    nis: string | null;
    kelas: string | null;
    jam: string | null;
    akan: string;
    akan_label: string;
    akan_nada: string;
    tepat: boolean;
};

type Props = {
    ruang: {
        id: number;
        kode: string;
        nama: string | null;
        lokasi: string | null;
        sesi: string | null;
        ujian: string | null;
        jam: string | null;
        berakhir_pada: string | null;
    };
    pelaku: string;
    rekap: {
        total: number;
        hadir: number;
        terlambat: number;
        tidak_hadir: number;
        izin: number;
        pending: number;
    };
    kursi: Kursi[];
    pindai: Pindai | null;
    pengakuan: Pengakuan[];
};

export default function Presensi({ ruang, rekap, kursi, pindai, pengakuan }: Props) {
    const [cari, setCari] = useState('');
    const [pilih, setPilih] = useState<number[]>([]);
    const [kirim, setKirim] = useState(false);

    // Penyaringan di peramban, bukan di server - dan itu kebalikan dari
    // laman Admin yang selalu menyaring di server. Alasannya berbeda
    // datanya: 32 baris sudah ada di peramban sejak laman dibuka, sementara
    // daftar 1.284 siswa tidak pernah dikirim seluruhnya.
    const tampil = useMemo(() => {
        const q = cari.trim().toLowerCase();

        if (q === '') {
            return kursi;
        }

        return kursi.filter(
            (k) =>
                k.nama.toLowerCase().includes(q) ||
                (k.nis ?? '').toLowerCase().includes(q) ||
                String(k.kursi) === q,
        );
    }, [kursi, cari]);

    const belum = kursi.filter((k) => k.status === 'pending');

    const tandaiSemua = () => {
        if (belum.length === 0) {
            return;
        }

        setKirim(true);
        router.post(
            `/pengawas/ruang/${ruang.id}/presensi/massal`,
            { record_ids: belum.map((k) => k.record_id), status: 'hadir' },
            { preserveScroll: true, onFinish: () => setKirim(false) },
        );
    };

    const tandai = (recordId: number, status: string) => {
        setKirim(true);
        router.post(
            `/pengawas/ruang/${ruang.id}/presensi/verifikasi`,
            { record_id: recordId, status },
            { preserveScroll: true, onFinish: () => setKirim(false) },
        );
    };

    const verifikasiTerpilih = () => {
        if (pilih.length === 0) {
            return;
        }

        setKirim(true);
        router.post(
            `/pengawas/ruang/${ruang.id}/presensi/massal`,
            { record_ids: pilih, status: 'hadir' },
            {
                preserveScroll: true,
                onFinish: () => {
                    setKirim(false);
                    setPilih([]);
                },
            },
        );
    };

    const toggle = (id: number) =>
        setPilih((p) => (p.includes(id) ? p.filter((x) => x !== id) : [...p, id]));

    /* --------------------------------------------------- Pindai presensi */

    // Kode yang sedang tampil. Dimulai dari prop, lalu DIGANTI oleh jawaban
    // endpoint rotasi setiap kali pitanya habis - lihat `useEffect` di bawah.
    const [kode, setKode] = useState<Pindai | null>(pindai);
    const [sisa, setSisa] = useState(pindai?.sisa ?? 0);
    const [layar, setLayar] = useState(false);
    const [lembar, setLembar] = useState(false);
    const [centang, setCentang] = useState<number[]>([]);
    const memutar = useRef(false);

    // Hitungan lokal, supaya angkanya bergerak tanpa menunggu jaringan.
    useEffect(() => {
        if (kode === null) {
            return;
        }

        const jam = window.setInterval(() => setSisa((lama) => Math.max(0, lama - 1)), 1000);

        return () => window.clearInterval(jam);
    }, [kode]);

    // Rotasi. Satu-satunya yang meminta kode baru, dan hanya saat yang lama
    // habis - kode yang diminta lebih cepat akan menghanguskan kode yang
    // masih bisa dipindai siswa di depan pintu.
    useEffect(() => {
        if (kode === null || sisa > 0 || memutar.current) {
            return;
        }

        let batal = false;
        memutar.current = true;

        fetch(`/pengawas/ruang/${ruang.id}/presensi/pindai`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': tokenCsrf(),
            },
        })
            .then((r) => (r.ok ? r.json() : null))
            .then((isi: Pindai | null) => {
                // 409 berarti sesinya berhenti berjalan di antara dua
                // rotasi. Kotak pindainya yang hilang, bukan galatnya yang
                // ditampilkan - pengawas tidak dapat memperbaiki itu.
                if (batal) {
                    return;
                }

                if (isi && isi.kode) {
                    setKode(isi);
                    setSisa(isi.sisa);
                } else {
                    setKode(null);
                }
            })
            .catch(() => {
                // Jaringan putus: coba lagi pada detik berikutnya. Kode lama
                // memang sudah mati, dan yang tersisa hanyalah menunggu.
                if (!batal) {
                    setSisa(1);
                }
            })
            .finally(() => {
                memutar.current = false;
            });

        return () => {
            batal = true;
        };
    }, [kode, sisa, ruang.id]);

    // Lembar konfirmasi: centang bawaan HANYA yang masih di dalam batas
    // masuk. Kalau semua tercentang sejak awal, tombol massalnya berubah
    // menjadi "catat apa pun yang datang" - plate A3.
    const bukaLembar = () => {
        setCentang(pengakuan.filter((p) => p.tepat).map((p) => p.id));
        setLembar(true);
    };

    const putarCentang = (id: number) =>
        setCentang((c) => (c.includes(id) ? c.filter((x) => x !== id) : [...c, id]));

    const kirimPengakuan = () => {
        if (centang.length === 0) {
            return;
        }

        setKirim(true);
        router.post(
            `/pengawas/ruang/${ruang.id}/presensi/konfirmasi`,
            { pengakuan_ids: centang },
            {
                preserveScroll: true,
                onFinish: () => {
                    setKirim(false);
                    setLembar(false);
                    setCentang([]);
                },
            },
        );
    };

    // Yang akan tercatat, dihitung dari centang - bukan dari seluruh daftar.
    // Angka di lembar ini adalah janji tentang apa yang akan ditulis.
    const akan = pengakuan.filter((p) => centang.includes(p.id));
    const akanHadir = akan.filter((p) => p.akan === 'hadir').length;
    const akanTelat = akan.filter((p) => p.akan === 'terlambat').length;

    return (
        <KerangkaPonsel
            judul="Presensi ruang"
            tabbar={{ ruangId: ruang.id, aktif: 'presensi' }}
            kiri={
                <>
                    <Chip nada="info">{ruang.kode}</Chip>
                    <span className="min-w-0 truncate text-[11px] text-ink-3">
                        {[ruang.nama, ruang.sesi].filter(Boolean).join(' · ')}
                    </span>
                </>
            }
            kanan={
                <Link
                    href={`/pengawas/ruang/${ruang.id}/token`}
                    className="inline-grid min-h-11 -my-2 place-items-center rounded-wf border border-line bg-surface px-2.5 text-[11px] text-ink-2"
                >
                    Token
                </Link>
            }
        >
            {/* Lima ubin di semua lebar - dan itu sudah diperiksa, bukan
                dibiarkan. Pada 320px tiap ubin mendapat ~56px, jadi label
                "Izin/alpa" MEMBUNGKUS dua baris. Membungkus bukan terpotong:
                kelima ubin sebaris dan barisnya tumbuh bersama. Memecahnya
                jadi 3+2 justru membuat ubin kelima tampak terpisah dari
                empat yang dibacanya sebagai satu baris. */}
            <div className="grid grid-cols-5 gap-1">
                <KpiPonsel label="Peserta" nilai={rekap.total} />
                <KpiPonsel label="Hadir" nilai={rekap.hadir} nada="ok" />
                <KpiPonsel label="Telat" nilai={rekap.terlambat} nada="info" />
                {/* "Belum" TIDAK memuat yang memindai: hadir dan memindai
                    dijumlahkan bukan total, dan plate A1 menuliskan sebabnya
                    - angka yang tampak seperti kemajuan padahal masih utang
                    adalah angka yang paling mudah disalahbaca sambil
                    berdiri. Yang memindai punya kolomnya sendiri. */}
                <KpiPonsel label="Belum" nilai={rekap.pending} />
                <KpiPonsel
                    label={pengakuan.length > 0 ? 'Memindai' : 'Izin/alpa'}
                    nilai={
                        pengakuan.length > 0
                            ? pengakuan.length
                            : `${rekap.izin}/${rekap.tidak_hadir}`
                    }
                    nada="warn"
                />
            </div>

            {/* Kotak pindai duduk DI ATAS daftar kursi, bukan menggantikannya:
                di depan pintu siswa memindai sementara pengawas tetap
                memanggil kursi 04 yang kosong. Plate A1. */}
            {kode !== null && (
                <KotakPonsel
                    solid
                    judul="Pindai untuk presensi"
                    kanan={<Chip nada={sisa > 0 ? 'ok' : 'warn'}>berlaku</Chip>}
                >
                    <div className="flex items-center gap-2.5">
                        <img
                            src={kode.qr}
                            alt="Kode pindai presensi ruang ini"
                            className="size-20 shrink-0 rounded-wf border border-line bg-white"
                        />
                        <div className="min-w-0 flex-1">
                            <b className="block text-xs text-ink">Tunjukkan layar ini ke siswa</b>
                            <span className="mt-0.5 block text-[11px] leading-snug text-ink-3">
                                Kode berganti sendiri tiap {kode.umur} detik. Pindai dari layar
                                lama ditolak.
                            </span>
                            <div className="mt-1.5 flex items-center gap-1.5">
                                <div className="h-1.5 min-w-0 flex-1 overflow-hidden rounded-full bg-surface-2">
                                    <i
                                        className="block h-full bg-accent transition-[width] duration-1000 ease-linear"
                                        style={{ width: `${(sisa / kode.umur) * 100}%` }}
                                    />
                                </div>
                                <b className="shrink-0 font-mono text-[11px] tabular-nums text-ink-2">
                                    {sisa}s
                                </b>
                            </div>
                        </div>
                    </div>

                    <button
                        type="button"
                        onClick={() => setLayar(true)}
                        className="inline-grid min-h-11 place-items-center justify-self-start rounded-wf border border-line bg-surface px-3 text-[11px] text-ink"
                    >
                        Layar penuh
                    </button>
                </KotakPonsel>
            )}

            {pengakuan.length > 0 && (
                <div className="rounded-wf border border-warn/45 bg-warn-bg/60 px-3 py-2">
                    <div className="flex items-center gap-2">
                        <span className="text-xs font-medium text-warn">
                            {pengakuan.length} siswa memindai
                        </span>
                        <span className="ml-auto shrink-0">
                            <Chip nada="warn">menunggu konfirmasi</Chip>
                        </span>
                    </div>
                    <p className="mt-0.5 text-[11px] leading-snug text-warn">
                        Belum ada satu pun yang tercatat hadir. Yang mencatat tetap Anda.
                    </p>
                    <button
                        type="button"
                        onClick={bukaLembar}
                        className="mt-1.5 inline-grid min-h-11 place-items-center rounded-wf border border-accent bg-accent px-3 text-[11px] font-medium text-on-accent"
                    >
                        Tinjau {pengakuan.length} pengakuan
                    </button>
                </div>
            )}

            <div className="flex flex-wrap items-center gap-1.5">
                <input
                    value={cari}
                    onChange={(e) => setCari(e.target.value)}
                    placeholder="Cari nama / NIS / no kursi"
                    className="min-h-11 min-w-[8rem] flex-1 rounded-wf border border-line bg-surface px-2.5 text-xs text-ink outline-none focus:border-accent"
                />

                {/* Tombol ini hanya muncul saat MASIH ada yang belum
                    ditandai. Menampilkannya dalam keadaan yang membuatnya
                    tidak melakukan apa pun adalah cara membuat orang
                    menekannya dua kali. */}
                {belum.length > 0 && (
                    <button
                        type="button"
                        onClick={tandaiSemua}
                        disabled={kirim}
                        className="inline-grid min-h-11 place-items-center rounded-wf border border-accent bg-accent px-3 text-[11px] font-medium text-on-accent disabled:opacity-50"
                    >
                        Tandai {belum.length} sisa hadir
                    </button>
                )}

                {pilih.length > 0 && (
                    <button
                        type="button"
                        onClick={verifikasiTerpilih}
                        disabled={kirim}
                        className="inline-grid min-h-11 place-items-center rounded-wf border border-line bg-surface px-3 text-[11px] text-ink disabled:opacity-50"
                    >
                        Verifikasi {pilih.length} terpilih
                    </button>
                )}
            </div>

            <KotakPonsel padat>
                {tampil.length === 0 && (
                    <p className="px-1 py-6 text-center text-xs text-ink-3">
                        {kursi.length === 0
                            ? 'Belum ada peserta yang ditempatkan di ruang ini.'
                            : 'Tidak ada nama yang cocok dengan pencarian itu.'}
                    </p>
                )}

                {tampil.map((k) => {
                    const p = k.pengerjaan;
                    const putus = p.ada && p.terhubung === false;

                    return (
                        <BarisKursi
                            key={k.record_id}
                            kursi={k.kursi}
                            nama={k.nama}
                            nada={putus ? 'bad' : null}
                            identitas={[k.nis, k.kelas].filter(Boolean).join(' · ')}
                            chip={
                                <>
                                    {/* Kotaknya 24px karena ia duduk di baris
                                        chip yang sempit, tetapi SASARANNYA 44px:
                                        `after:-inset-2.5` memperluas area tekan
                                        tanpa menggeser satu chip pun. Tombol
                                        yang harus dibidik dengan ujung jempol
                                        di depan pintu adalah tombol yang salah
                                        tekan. */}
                                    <button
                                        type="button"
                                        onClick={() => toggle(k.record_id)}
                                        title="Pilih untuk verifikasi massal"
                                        aria-pressed={pilih.includes(k.record_id)}
                                        className={
                                            'relative grid size-6 place-items-center rounded-wf border text-[11px] ' +
                                            'after:absolute after:-inset-2.5 after:content-[\'\'] ' +
                                            (pilih.includes(k.record_id)
                                                ? 'border-accent bg-accent-soft text-accent'
                                                : 'border-line text-ink-3')
                                        }
                                    >
                                        {pilih.includes(k.record_id) ? '✓' : '○'}
                                    </button>

                                    <Chip nada={k.status_nada as 'ok'}>
                                        {k.status_pendek}
                                    </Chip>

                                    {/* Sumbernya, dan itu bukan kolom
                                        tersembunyi - plate C: "anak saya hadir
                                        atas dasar apa" dijawab dari layar yang
                                        sama. */}
                                    {k.sumber && (
                                        <span className="text-[11px] text-ink-3">
                                            {k.sumber.jenis} {k.sumber.jam ?? '—'}
                                        </span>
                                    )}

                                    {/* Pengerjaan berasal dari server dan
                                        tidak bisa diketik siapa pun. Selama
                                        Fase 6 seluruhnya "belum mulai" - dan
                                        itu memang keadaannya, bukan angka
                                        karangan. */}
                                    <Chip nada={p.nada as 'mute'}>{p.label}</Chip>

                                    {putus && <Chip nada="bad">koneksi putus</Chip>}
                                    {!p.terhubung && p.ada && p.terakhir && (
                                        <span className="text-[11px] text-ink-3">
                                            {p.terakhir}
                                        </span>
                                    )}
                                </>
                            }
                            tindakan={
                                // `min-h-11` = 44px. Ini tombol yang paling
                                // sering ditekan di seluruh aplikasi -
                                // satu ketukan per siswa, sambil berdiri, di
                                // depan pintu - dan `py-1.5` memberinya 30px.
                                k.status === 'pending' ? (
                                    <button
                                        type="button"
                                        onClick={() => tandai(k.record_id, 'hadir')}
                                        disabled={kirim}
                                        className="inline-grid min-h-11 place-items-center rounded-wf border border-accent bg-accent px-3 text-xs font-medium text-on-accent disabled:opacity-50"
                                    >
                                        Hadir
                                    </button>
                                ) : (
                                    <Link
                                        href={`/pengawas/ruang/${ruang.id}/presensi/${k.peserta_id}`}
                                        className="inline-grid min-h-11 place-items-center rounded-wf border border-line bg-surface px-3 text-xs text-ink-2"
                                    >
                                        Ubah
                                    </Link>
                                )
                            }
                        />
                    );
                })}
            </KotakPonsel>

            <p className="px-1 text-center text-[11px] text-ink-3">
                Urut nomor kursi &middot; {tampil.length} dari {kursi.length} ditampilkan
            </p>

            <Inbox>
                Satu ketukan per siswa. <b>Presensi</b> adalah keputusan Anda dan boleh
                diubah; <b>pengerjaan</b> datang dari server dan tidak dapat diketik siapa
                pun.
            </Inbox>

            {/*
             * Plate A2 - layar penuh.
             *
             * Ada karena satu sebab ukuran: kode selebar 6 cm terbaca dari
             * ponsel yang dipegang sendiri, tetapi tidak dari baris kelima.
             * Yang diperbesar HANYA kode dan nama ruang; nomor kursi dan
             * daftar nama tidak ikut ke sini, karena layar ini diarahkan ke
             * siswa.
             */}
            <Modal judul="Kode pindai presensi" terbuka={layar} padaTutup={() => setLayar(false)}>
                <div className="grid justify-items-center gap-2 text-center">
                    <b className="font-display text-[15px] text-ink">
                        {[ruang.ujian, ruang.sesi].filter(Boolean).join(' · ')}
                    </b>
                    <span className="text-[11px] text-ink-3">{ruang.nama}</span>

                    {kode !== null ? (
                        <>
                            <img
                                src={kode.qr}
                                alt={`Kode pindai presensi ${ruang.kode}`}
                                className="mx-auto size-60 max-w-full rounded-wf border border-line bg-white"
                            />

                            <div className="flex w-full max-w-[11rem] items-center gap-1.5">
                                <div className="h-1.5 min-w-0 flex-1 overflow-hidden rounded-full bg-surface-2">
                                    <i
                                        className="block h-full bg-accent transition-[width] duration-1000 ease-linear"
                                        style={{ width: `${(sisa / kode.umur) * 100}%` }}
                                    />
                                </div>
                                <b className="shrink-0 font-mono text-[12px] tabular-nums text-ink-2">
                                    {sisa}s
                                </b>
                            </div>

                            <span className="text-[11px] leading-snug text-ink-3">
                                Arahkan kamera ponsel siswa ke kode ini. Tidak butuh aplikasi -
                                peramban ponsel akan membuka laman presensinya.
                            </span>
                        </>
                    ) : (
                        <span className="py-8 text-[12px] text-ink-2">
                            Kode pindai hanya terbit saat sesi berjalan. Buka sesi ini lebih
                            dulu.
                        </span>
                    )}

                    <button
                        type="button"
                        onClick={() => setLayar(false)}
                        className="inline-grid min-h-11 place-items-center rounded-wf border border-line bg-surface px-4 text-[11px] text-ink-2"
                    >
                        Tutup
                    </button>
                </div>
            </Modal>

            {/*
             * Plate A3 - lembar konfirmasi.
             *
             * `key` berisi jumlah baris supaya Modal memasang ulang isinya saat
             * daftarnya berubah: `useState` di dalam anaknya tidak direset
             * ketika prop berubah, dan centang yang tertinggal dari daftar
             * lama adalah centang pada baris yang sudah dikonfirmasi.
             */}
            <Modal
                key={`lembar-${pengakuan.length}`}
                judul={`${pengakuan.length} siswa memindai`}
                terbuka={lembar}
                padaTutup={() => setLembar(false)}
            >
                <div className="space-y-2">
                    <Inbox>
                        Daftar ini adalah <b>pengakuan siswa</b>, bukan catatan. Tidak satu pun
                        baris di sini mengubah presensi sampai Anda menekan tombolnya.
                    </Inbox>

                    <div className="rounded-wf border border-line bg-surface">
                        {pengakuan.map((p) => (
                            <label
                                key={p.id}
                                className="flex min-h-11 cursor-pointer items-center gap-2 border-b border-line/60 px-3 py-2 last:border-0"
                            >
                                <input
                                    type="checkbox"
                                    checked={centang.includes(p.id)}
                                    onChange={() => putarCentang(p.id)}
                                    className="size-5 shrink-0 accent-[var(--accent)]"
                                />

                                <span className="w-6 shrink-0 font-mono text-[11px] text-ink-3">
                                    {p.kursi !== null ? String(p.kursi).padStart(2, '0') : '—'}
                                </span>

                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-[12px] text-ink">
                                        {p.nama}
                                    </span>
                                    <span className="block truncate text-[11px] text-ink-3">
                                        {[p.kelas, p.nis].filter(Boolean).join(' · ')}
                                    </span>
                                </span>

                                <span className="shrink-0 font-mono text-[11px] text-ink-2">
                                    {p.jam ?? '—'}
                                </span>

                                <Chip nada={p.akan_nada as 'ok'}>{p.akan_label}</Chip>
                            </label>
                        ))}
                    </div>

                    <div className="rounded-wf border border-line bg-surface-2/60 px-3 py-2">
                        <span className="text-[11px] font-medium text-ink-2">
                            Yang akan dicatat
                        </span>
                        <div className="mt-1 grid grid-cols-3 gap-1">
                            <KpiPonsel label="Hadir" nilai={akanHadir} nada="ok" />
                            <KpiPonsel label="Terlambat" nilai={akanTelat} nada="info" />
                            <KpiPonsel
                                label="Tidak hadir"
                                nilai={0}
                                nada="warn"
                            />
                        </div>
                        <p className="mt-1 text-[11px] leading-snug text-ink-3">
                            Baris tanpa centang tetap <b>belum</b> sampai Anda memutuskan -
                            statusnya turun dari jam pindainya, bukan dari jam konfirmasi.
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-1.5">
                        <button
                            type="button"
                            onClick={kirimPengakuan}
                            disabled={kirim || centang.length === 0}
                            className="inline-grid min-h-11 place-items-center rounded-wf border border-accent bg-accent px-3 text-[11px] font-medium text-on-accent disabled:opacity-50"
                        >
                            Catat {centang.length} yang dicentang
                        </button>
                        <button
                            type="button"
                            onClick={() => setLembar(false)}
                            className="inline-grid min-h-11 place-items-center rounded-wf border border-line bg-surface px-3 text-[11px] text-ink-2"
                        >
                            Batal
                        </button>
                    </div>
                </div>
            </Modal>
        </KerangkaPonsel>
    );
}

/** Token CSRF dari cookie - bentuk yang dibaca `PreventRequestForgery`. */
function tokenCsrf(): string {
    const cocok = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return cocok ? decodeURIComponent(cocok[1]) : '';
}

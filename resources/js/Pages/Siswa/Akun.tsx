import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import KerangkaSiswa from '@/Layouts/KerangkaSiswa';
import { konfirmasi } from '@/Components/dialog';
import { Modal, Peringatan } from '@/Components/ui';

/**
 * Akun saya - tab keempat konsol siswa. `/siswa/akun`.
 *
 * =====================================================================
 * Laman ini tidak digambar wireframe mana pun
 * =====================================================================
 * `wireframe-peran.html` menggambar TAB "Akun" di tabbar siswa tiga kali
 * (`:999`, `:1215`, `:1243`) tetapi tidak pernah menggambar lamannya. Isinya
 * karena itu dikunci pemilik produk 2026-11-07: identitas + ganti sandi +
 * keluar.
 *
 * Sejak 2026-11-14 formulir ganti sandi PINDAH ke `/siswa/akun/sandi`
 * (permintaan pemilik produk: "agar laman /siswa/akun fokus ke informasi
 * akun saja"). Yang tinggal di sini identitas, satu tautan ke laman sandi,
 * dan tombol keluar. Alasan pemisahannya ada di blok "Keamanan" di bawah,
 * dekat dengan tempat akibatnya terbaca.
 *
 * =====================================================================
 * Redesign: identitas sebagai PAPAN, bukan daftar label-nilai abu
 * =====================================================================
 * Sampai redesign ini identitas berdiri sebagai enam baris `dt`/`dd` di mana
 * labelnya 12px abu dan nilainya rata kanan - bentuk yang membuat "Nama" dan
 * "Sekolah" sama pentingnya dengan "NISN", dan membuat salah satu dari
 * keenamnya (nama anak itu sendiri) tidak bisa ditemukan lebih cepat daripada
 * yang lain.
 *
 * Di sini keempat yang MENGENALI anak - nama, kelas, NIS, NISN, username,
 * sekolah - tetap lengkap, tetapi barisnya bergaris seperti papan, dan yang
 * pertama jauh lebih besar daripada sisanya. Nama adalah satu-satunya nilai
 * di laman ini yang perlu ditemukan dalam sekejap.
 *
 * =====================================================================
 * "Sandi" di sini, "Password" di layar login
 * =====================================================================
 * Ketidaksamaan itu disengaja dan dicatat supaya terbaca sebagai pilihan.
 * Layar login memakai kata yang dikenali anak dari ponselnya; laman ini
 * memakai kata yang dipakai sekolah. Mengubah layar login ada di luar lingkup
 * dan akan menyentuh satu form yang dipakai empat peran.
 *
 * =====================================================================
 * Peringatan sandi bawaan TIDAK memblokir apa pun
 * =====================================================================
 * Ia satu kartu kuning dan satu kalimat, tidak lebih. Keputusan yang dikunci:
 * anak tidak boleh tertahan di luar ruang ujian karena urusan sandi. Karena
 * itu tidak ada gerbang di sini, tidak ada tombol yang wajib ditekan, dan
 * tidak ada penghitung waktu.
 *
 * =====================================================================
 * UmpanBalik TIDAK dirender di sini
 * =====================================================================
 * `KerangkaSiswa` sudah merendernya di dalam `<main>` - merender dua kali
 * berarti dua kotak pesan yang isinya sama.
 *
 * =====================================================================
 * Tautan keluar ADA DI LAMAN INI, bukan di kaki kerangka
 * =====================================================================
 * Kaki kerangka hanya merender "Keluar dari akun" bila tabbarnya
 * disembunyikan (`KerangkaSiswa`, blok terakhir), dan laman ini mengisi
 * `tabbarAktif`. Tanpa blok keluar di bawah, siswa tidak punya jalan keluar
 * sama sekali dari keempat laman bertabbar.
 */

type Sel = {
    kursi: number;
    meja: number;
    baris: number;
    kolom: number;
    terisi: boolean;
    saya: boolean;
};

type TempatUjian = {
    peserta_id: number;
    ruang: string | null;
    ruang_nama: string | null;
    lokasi: string | null;
    kursi: number | null;
    meja: number | null;
    baris: number | null;
    kolom: number | null;
    mapel: string | null;
    ujian: string | null;
    sesi: string | null;
    jam: string | null;
    tanggal: string | null;
    /** Sesi ini sudah berakhir - mengubah kalimat, bukan menyembunyikan. */
    selesai: boolean;
    denah: {
        kapasitas: number;
        kursi_per_meja: number;
        meja_per_baris: number;
        sel: Sel[];
    };
};

type Props = {
    nama: string;
    username: string;
    nis: string | null;
    nisn: string | null;
    sekolah: string | null;
    kelas: string | null;
    sandi_bawaan: boolean;
    tempat_ujian: TempatUjian | null;
};

export default function Akun({
    nama,
    username,
    nis,
    nisn,
    sekolah,
    kelas,
    sandi_bawaan,
    tempat_ujian,
}: Props) {
    const [denah, setDenah] = useState(false);

    return (
        <KerangkaSiswa
            judul="Akun saya"
            tabbarAktif="akun"
            kiri={<span className="truncate text-[12px] font-medium text-ink">Akun · {nama}</span>}
            kanan={
                kelas ? (
                    <span className="border border-line-2 px-1.5 py-0.5 text-[11px] text-ink-2">
                        {kelas}
                    </span>
                ) : undefined
            }
        >
            {/* Peringatan, bukan penegakan - lihat catatan berkas ini. */}
            {sandi_bawaan && (
                <Peringatan>
                    Sandi Anda masih sandi bawaan dari sekolah. Anda tetap bisa mengikuti ujian
                    dengan sandi ini, tetapi sebaiknya diganti sekarang: sandi bawaan sama untuk
                    semua siswa, sehingga orang lain bisa masuk ke akun Anda.
                </Peringatan>
            )}

            <section className="daftar overflow-hidden">
                <header className="daftar-kepala">
                    <h2>Identitas</h2>
                </header>

                <div className="px-3 py-3">
                    {/* Nama anak itu sendiri, dan ia satu-satunya nilai di sini
                        yang berukuran lebih besar dari badan teks. */}
                    <b className="block truncate font-display text-[17px] leading-tight text-ink">
                        {nama}
                    </b>
                    <span className="catatan mt-1 block truncate">
                        {sekolah ?? 'Sekolah belum terdata'}
                    </span>
                </div>

                {/*
                 * Baris-barisnya bergaris, jadi yang memisahkan dua nilai
                 * adalah garis, bukan jarak. Nilainya rata KIRI dan berdampingan
                 * dengan labelnya - rata kanan pada kolom sempit membuat dua
                 * nilai dengan panjang berbeda tampak berasal dari dua tabel.
                 */}
                <dl>
                    <BarisNilai label="Username" nilai={username} />
                    <BarisNilai label="Kelas" nilai={kelas} />
                    <BarisNilai label="NIS" nilai={nis} />
                    <BarisNilai label="NISN" nilai={nisn} />
                </dl>
            </section>

            {/*
             * Tempat ujian - ditambahkan 2026-11-14.
             *
             * Ditaruh DI ATAS Keamanan, dan itu urutannya bukan selera: anak
             * yang membuka laman ini pada pagi hari ujian mencari "saya duduk
             * di mana", bukan "bagaimana mengganti sandi". Yang paling sering
             * dibutuhkan berdiri lebih dulu.
             *
             * Blok ini tidak dirender saat tidak ada ujian yang belum
             * berakhir - bukan dirender kosong. Blok kosong terbaca sebagai
             * "tempat ujian saya hilang", dan itu kalimat yang salah.
             */}
            {tempat_ujian !== null && (
                <section className="daftar overflow-hidden" style={{ borderColor: 'var(--color-accent)' }}>
                    <header className="daftar-kepala">
                        <h2>{tempat_ujian.selesai ? 'Tempat ujian terakhir' : 'Tempat ujian saya'}</h2>
                    </header>

                    <div className="space-y-1.5 px-3 py-3">
                        <div className="flex flex-wrap items-baseline gap-2">
                            <b className="font-display text-[17px] leading-tight text-ink">
                                {tempat_ujian.ruang ?? 'Ruang belum ditetapkan'}
                            </b>
                            {tempat_ujian.kursi !== null && (
                                <span className="border border-line-2 bg-accent-soft px-1.5 py-0.5 text-[11px] font-medium text-accent">
                                    kursi {tempat_ujian.kursi}
                                </span>
                            )}
                        </div>

                        <p className="catatan">
                            {[
                                tempat_ujian.ruang_nama,
                                tempat_ujian.lokasi,
                                tempat_ujian.mapel,
                                tempat_ujian.sesi,
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                        </p>

                        <p className="angka text-[11px] text-ink-3">
                            {[tempat_ujian.tanggal, tempat_ujian.jam].filter(Boolean).join(' · ')}
                        </p>

                        {/* Kalimat untuk sesi yang SUDAH berakhir. Blok ini
                            tidak disembunyikan sesudah ujiannya lewat - ia
                            menjawab "tadi saya duduk di mana" - tetapi
                            "tempat ujian" pada sesi yang lewat terbaca
                            sebagai ajakan datang ke ruang yang sudah
                            dikosongkan. */}
                        {tempat_ujian.selesai && (
                            <p className="catatan text-ink-3">Sesi ini sudah berakhir.</p>
                        )}

                        {tempat_ujian.denah.sel.length > 0 && (
                            <button
                                type="button"
                                onClick={() => setDenah(true)}
                                className="tombol mt-1 w-full"
                            >
                                Lihat posisi kursi
                            </button>
                        )}
                    </div>
                </section>
            )}

            {/*
             * Ganti sandi PINDAH ke laman sendiri sejak 2026-11-14.
             *
             * Sampai saat itu formulir tiga kolom berdiri di setengah bawah
             * laman ini, dan akibatnya laman yang dibuka untuk "nomor induk
             * saya berapa" memuat tiga kolom sandi yang selalu terlihat -
             * dan formulir yang selalu terlihat adalah formulir yang
             * sesekali tersentuh tanpa niat. Yang tersentuh tanpa niat di
             * sini adalah sandi.
             *
             * Yang tersisa di sini adalah TAUTAN, dan kalimatnya menyebut
             * apa yang menunggu di sana - bukan hanya "Ganti sandi", yang
             * pada tabbar tiga item lain terbaca sebagai label menu keempat.
             */}
            <section className="daftar overflow-hidden">
                <header className="daftar-kepala">
                    <h2>Keamanan</h2>
                </header>

                <div className="px-3 py-3">
                    {/* Kalimatnya TIDAK mengulang peringatan di atas laman.
                        Peringatan itu sudah menjelaskan mengapa sandi bawaan
                        berbahaya; yang dibutuhkan di sini hanya apa yang
                        menunggu di laman sebelah. */}
                    <p className="text-[12px] leading-relaxed text-ink-2">
                        Sandi akun diganti di laman tersendiri, bukan di sini. Menggantinya
                        akan mengeluarkan akun Anda dari perangkat lain.
                    </p>

                    <Link href="/siswa/akun/sandi" className="tombol mt-2.5 w-full">
                        Ganti sandi
                    </Link>
                </div>
            </section>

            <section className="daftar">
                {/* Tombol, bukan `<Link as="button">`: keluar ditanyakan lebih
                    dulu, dan dialognya asinkron - sementara `onClick` pada
                    `Link` harus memutuskan `preventDefault()` saat itu juga.
                    Dengan `router.post` di sini, kunjungannya baru terjadi
                    SETELAH siswa menjawab, jadi tidak ada tombol yang sudah
                    berangkat sebelum pertanyaannya selesai. */}
                <button
                    type="button"
                    onClick={async () => {
                        const setuju = await konfirmasi({
                            judul: 'Keluar dari akun ini?',
                            pesan: 'Pengerjaan yang sedang berjalan tetap tersimpan di server. Masuk lagi dengan NIS dan sandi yang sama.',
                            labelYa: 'Keluar',
                            nada: 'bahaya',
                        });

                        if (setuju) {
                            router.post('/logout');
                        }
                    }}
                    className="tombol tombol--bahaya w-full"
                >
                    Keluar dari akun
                </button>
            </section>

            {/*
             * Panel posisi kursi - dibuka dari blok "Tempat ujian saya".
             *
             * `Modal` dipakai apa adanya (bukan lembar bawah khusus): ia
             * sudah menangani Escape, fokus, dan lapisan. Yang dibuat sendiri
             * hanyalah BENTUK denahnya - dan geometrinya dari server, bukan
             * dihitung di sini.
             */}
            <Modal
                judul={
                    tempat_ujian?.kursi !== null && tempat_ujian?.kursi !== undefined
                        ? `Posisi kursi ${tempat_ujian.kursi}`
                        : 'Posisi kursi'
                }
                terbuka={denah}
                padaTutup={() => setDenah(false)}
            >
                {tempat_ujian !== null && (
                    <DenahKursi tempat={tempat_ujian} />
                )}
            </Modal>
        </KerangkaSiswa>
    );
}

/**
 * Denah kursi - geometri dari server, dipetakan ke meja.
 *
 * =====================================================================
 * Mengapa TIDAK menghitung meja sendiri
 * =====================================================================
 * `NomorKursi::KURSI_PER_MEJA = 2` dan `MEJA_PER_BARIS = 4`, dan angka itu
 * hidup di server karena dipakai juga oleh denah Admin, tablo PDF, dan
 * papan pengawas. Laman yang membaginya sendiri akan berbeda dari kertas
 * begitu salah satunya berubah - dan yang menemukannya anak yang berdiri di
 * depan pintu.
 *
 * Karena itu yang datang adalah `sel[]` lengkap dengan `meja`, `baris`, dan
 * `kolom`; di sini ia hanya DIKELOMPOKKAN, bukan dihitung ulang.
 */
function DenahKursi({ tempat }: { tempat: TempatUjian }) {
    const { denah } = tempat;

    // Kelompokkan per MEJA: kursi 1 dan 2 di meja 1, dst.
    const meja = new Map<number, Sel[]>();
    for (const s of denah.sel) {
        const isi = meja.get(s.meja) ?? [];
        isi.push(s);
        meja.set(s.meja, isi);
    }

    // Baris meja: 4 meja per baris, mengikuti `baris` dari server.
    const perBaris = denah.meja_per_baris || 4;
    const baris = new Map<number, number[]>();
    for (const nomorMeja of meja.keys()) {
        const b = Math.floor((nomorMeja - 1) / perBaris) + 1;
        const isi = baris.get(b) ?? [];
        isi.push(nomorMeja);
        baris.set(b, isi);
    }

    const arah = [
        tempat.meja !== null ? `meja ${tempat.meja}` : null,
        tempat.baris !== null ? `baris ke-${tempat.baris} dari depan` : null,
        tempat.kolom !== null ? `kolom ke-${tempat.kolom} dari kiri` : null,
    ]
        .filter(Boolean)
        .join(', ');

    return (
        <div className="space-y-2.5">
            <p className="text-[12px] leading-relaxed text-ink-2">
                Masuk dari pintu depan.
                {arah ? ` Kursi Anda: ${arah}.` : ''}
            </p>

            <div className="rounded-wf border border-line-2 bg-surface-2 p-2">
                <p className="border-b border-dashed border-line pb-1 text-center font-mono text-[10px] uppercase tracking-wide text-ink-3">
                    papan tulis &amp; meja pengawas
                </p>

                <div className="mt-1.5 space-y-1">
                    {[...baris.entries()]
                        .sort(([a], [b]) => a - b)
                        .map(([b, daftarMeja]) => (
                            <div
                                key={b}
                                className="grid items-stretch gap-1"
                                style={{
                                    gridTemplateColumns: `14px repeat(${perBaris}, minmax(0, 1fr))`,
                                }}
                            >
                                <span className="self-center text-center font-mono text-[9px] text-ink-3">
                                    {b}
                                </span>
                                {daftarMeja
                                    .sort((x, y) => x - y)
                                    .map((nomorMeja) => (
                                        <div
                                            key={nomorMeja}
                                            className="rounded-[3px] border border-line bg-surface p-0.5"
                                        >
                                            <p className="text-center font-mono text-[8px] uppercase text-ink-3">
                                                {nomorMeja}
                                            </p>
                                            <div className="grid grid-cols-2 gap-0.5">
                                                {(meja.get(nomorMeja) ?? [])
                                                    .sort((x, y) => x.kursi - y.kursi)
                                                    .map((s) => (
                                                        <div
                                                            key={s.kursi}
                                                            className={
                                                                'rounded-[2px] border px-0.5 py-0.5 text-center font-mono text-[10px] tabular-nums ' +
                                                                (s.saya
                                                                    ? 'border-2 border-accent bg-accent-soft font-medium text-accent'
                                                                    : s.terisi
                                                                      ? 'border-line/60 bg-surface text-ink-2'
                                                                      : 'border-dashed border-line/60 bg-surface-2 text-ink-3/60')
                                                            }
                                                        >
                                                            {s.kursi}
                                                        </div>
                                                    ))}
                                            </div>
                                        </div>
                                    ))}
                            </div>
                        ))}
                </div>

                <p className="mt-1.5 text-center font-mono text-[9px] uppercase tracking-wide text-accent">
                    pintu masuk
                </p>
            </div>

            <div className="flex flex-wrap items-center gap-3 text-[10px] text-ink-3">
                <span className="flex items-center gap-1">
                    <span className="size-2.5 rounded-[2px] border-2 border-accent bg-accent-soft" />
                    kursi Anda
                </span>
                <span className="flex items-center gap-1">
                    <span className="size-2.5 rounded-[2px] border border-line/60 bg-surface" />
                    terisi
                </span>
                <span className="flex items-center gap-1">
                    <span className="size-2.5 rounded-[2px] border border-dashed border-line/60 bg-surface-2" />
                    kosong
                </span>
                <span className="ml-auto font-mono">
                    {denah.kursi_per_meja} kursi per meja · {perBaris} meja per baris
                </span>
            </div>

            <p className="text-[11px] leading-relaxed text-ink-3">
                Kursi bernomor berisi anak lain; nama mereka tidak ditampilkan. Nomor yang
                sama tercetak di meja dan dibaca pengawas.
            </p>
        </div>
    );
}

/**
 * Satu baris label-nilai.
 *
 * Helper berkas-lokal, meniru `Admin/RuangDetail.tsx` - `ui.tsx` sengaja
 * tidak punya komponen key-value bersama, dan house style-nya memang
 * helper di berkas yang memakainya.
 *
 * Nilai kosong ditulis "belum terdata", bukan tanda pisah. Cara itu sama
 * dengan yang dipakai papan di beranda, dan ia menjelaskan bahwa yang
 * hilang adalah datanya - bukan bahwa nilainya nol atau tidak berlaku.
 */
function BarisNilai({ label, nilai }: { label: string; nilai: string | null }) {
    return (
        <div className="daftar-baris flex items-baseline gap-3 px-3 py-2">
            <dt className="w-[84px] shrink-0 text-[11px] text-ink-3">{label}</dt>
            <dd className="angka min-w-0 flex-1 truncate text-[12px] text-ink">
                {nilai ?? 'belum terdata'}
            </dd>
        </div>
    );
}

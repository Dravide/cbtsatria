import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import KerangkaSiswa from '@/Layouts/KerangkaSiswa';
import { formatDurasi } from '@/Components/angka';

/**
 * Jadwal ujian saya - wireframe-peran.html plate 19. `/siswa`.
 *
 * =====================================================================
 * Satu pita, lalu satu daftar. Hanya itu di beranda ini
 * =====================================================================
 * Anak yang membuka laman ini sedang mencari satu hal - apa yang harus saya
 * kerjakan sekarang - dan jawabannya tidak boleh memerlukan pembacaan tabel.
 * Karena itu jawabannya satu PITA tinta dengan jam terbesar di halaman, dan
 * sisanya daftar yang bisa dipindai ke bawah.
 *
 * Versi sebelumnya menaruh keempat keterangan (mapel, tanggal, jam, kursi)
 * dalam empat kolom bersumbu tetap di dalam ponsel 26rem. Keempatnya muat
 * dengan mengorbankan nama mapel, yang terpotong paling dulu - padahal nama
 * mapel adalah satu-satunya hal yang membedakan dua baris. Di sini baris
 * bertumpuk dua tingkat: mapel dan status di atas, waktu dan ruang di
 * bawahnya. Lebih sedikit yang muat mendatar, lebih banyak yang terbaca.
 *
 * =====================================================================
 * Kartu yang muncul ditentukan SERVER (`utama`), bukan peramban
 * =====================================================================
 * Membandingkan `mulai` dengan jam ponsel berarti jam perangkat yang meleset
 * satu hari menampilkan ujian yang salah sebagai "hari ini" - dan anak yang
 * menekan Mulai pada ujian besok akan menerima penolakan gerbang.
 *
 * `boleh_mulai`, `selesai`, dan seluruh kosakata status sudah diputuskan
 * `JadwalSiswaController`. Laman ini menampilkannya. Satu-satunya yang
 * dihitung di peramban adalah baris mana yang ditampilkan sebagai pita.
 */

type Baris = {
    peserta_id: number;
    mapel: string | null;
    ujian: string | null;
    tanggal: string | null;
    tanggal_iso: string | null;
    jam: string | null;
    mulai: string | null;
    hard_end: string | null;
    ruang: string | null;
    ruang_nama: string | null;
    kursi: number | null;
    /** Detik sampai sesi dibuka; null bila sudah dibuka atau tak ada sesi. */
    hitung_mundur: number | null;
    presensi: {
        status: string | null;
        label: string;
        nada: string;
        jam: string | null;
        membuka_gerbang: boolean;
    };
    utama: boolean;
    boleh_mulai: boolean;
    selesai: boolean;
    status: string;
    nada: string;
};

type Pengumuman = {
    id: number;
    judul: string;
    isi: string;
    nada: string;
    berlaku_sampai: string | null;
};

type Props = {
    nama: string;
    kelas: string | null;
    sekarang: string;
    utama: Baris | null;
    jadwal: Baris[];
    nilai_keluar: boolean;
    pengumuman: Pengumuman[];
    kemajuan: { total: number; selesai: number; menunggu: number };
};

/**
 * Warna sel status.
 *
 * Dipetakan di satu tempat karena ia muncul dua kali dengan bentuk berbeda -
 * sel di pita dan sel di daftar. Dua peta yang berbeda akan membuat "siap
 * dimulai" berwarna rust di satu tempat dan kelabu di tempat lain.
 */
const NADA: Record<string, string> = {
    ok: 'bg-ok-bg text-ok',
    warn: 'bg-warn-bg text-warn',
    bad: 'bg-bad-bg text-bad',
    info: 'bg-info-bg text-info',
    mute: 'bg-surface-2 text-ink-2',
};

/** Sel status - kotak bergaris, bukan chip bulat. Terrazzo tidak membulat. */
function SelStatus({ nada, children }: { nada: string; children: string }) {
    return (
        <span
            className={
                'inline-block border border-line-2 px-1.5 py-0.5 text-[11px] font-medium whitespace-nowrap ' +
                (NADA[nada] ?? NADA.mute)
            }
        >
            {children}
        </span>
    );
}

export default function Jadwal({
    nama,
    kelas,
    utama,
    jadwal,
    nilai_keluar,
    pengumuman,
    kemajuan,
}: Props) {
    // Baris yang sudah tampil sebagai pita TIDAK diulang di daftar di
    // bawahnya. Dua kali menyebut ujian yang sama membuat anak menghitungnya
    // dua kali saat ia bertanya "berapa ujian saya hari ini".
    const sisa = jadwal.filter((b) => b.peserta_id !== utama?.peserta_id);

    return (
        <KerangkaSiswa
            judul="Jadwal ujian saya"
            tabbarAktif="ujian"
            kiri={<span className="truncate text-[12px] font-medium text-ink">Halo, {nama}</span>}
            kanan={
                kelas ? (
                    <span className="border border-line-2 px-1.5 py-0.5 text-[11px] text-ink-2">
                        {kelas}
                    </span>
                ) : undefined
            }
        >
            {/*
             * Pengumuman DULU, sebelum pita. Pita menjawab "kapan saya
             * mengerjakan"; pengumuman menjawab "apakah rencana itu masih
             * berlaku" - dan "sesi 2 mundur 15 menit" membuat seluruh isi
             * pita itu salah. Yang bisa membatalkan jadwal harus dibaca
             * sebelum jadwalnya.
             */}
            {pengumuman.length > 0 && (
                <section className="pengumuman" aria-label="Pengumuman">
                    {pengumuman.map((p) => (
                        <article
                            key={p.id}
                            className={
                                'pengumuman-butir ' +
                                (p.nada === 'penting' ? 'pengumuman-butir--penting' : '')
                            }
                        >
                            <p className="pengumuman-judul">{p.judul}</p>
                            <p className="pengumuman-isi">{p.isi}</p>
                            {p.berlaku_sampai && (
                                <p className="pengumuman-waktu">
                                    berlaku sampai {p.berlaku_sampai}
                                </p>
                            )}
                        </article>
                    ))}
                </section>
            )}

            {utama && <PitaUtama baris={utama} />}

            {/*
             * Kemajuan: "2 dari 5 ujian selesai". Satu baris, di bawah pita
             * dan di atas daftar.
             *
             * Angkanya datang dari SERVER dan dihitung dari baris yang SAMA
             * dengan daftar di bawahnya - bukan dari peramban yang menghitung
             * ulang `jadwal.filter(...)`. Dua cara menghitung dalam satu laman
             * adalah dua angka yang suatu hari berselisih, dan yang
             * membacanya berhenti memercayai keduanya.
             *
             * Tidak dirender saat hanya ada satu ujian: "0 dari 1 selesai"
             * tidak menambah apa pun yang belum terbaca di barisnya sendiri.
             */}
            {kemajuan.total > 1 && (
                <p className="kemajuan" aria-label="Kemajuan ujian">
                    <b className="kemajuan-angka">
                        {kemajuan.selesai}
                        <span className="kemajuan-dari"> / {kemajuan.total}</span>
                    </b>
                    <span className="kemajuan-teks">
                        ujian selesai
                        {kemajuan.menunggu > 0 ? `, ${kemajuan.menunggu} masih menunggu` : ''}
                    </span>
                </p>
            )}

            {/*
             * Daftar. Ia dirender SELALU - juga saat tidak ada satu pun ujian -
             * karena wadah yang tergambar itulah yang membedakan "belum ada
             * isinya" dari "tidak ada apa-apa di sini".
             */}
            <section className="daftar overflow-hidden">
                <header className="daftar-kepala">
                    <h2>Ujian saya</h2>
                    {jadwal.length > 0 && (
                        <span className="angka ml-auto text-[12px] text-ink-2">
                            {jadwal.length}
                        </span>
                    )}
                </header>

                {sisa.length === 0 ? (
                    <div className="px-3 py-6">
                        <p className="text-[13px] leading-relaxed text-ink-2">
                            {jadwal.length === 0
                                ? 'Belum ada ujian yang ditempatkan untuk Anda. Jadwal ditetapkan Admin lewat peserta event, dan akan muncul di sini begitu Anda terdaftar.'
                                : 'Tidak ada ujian lain yang menunggu. Semua yang ditempatkan untuk Anda sudah tampil di atas.'}
                        </p>
                    </div>
                ) : (
                    sisa.map((b) => <BarisDaftar key={b.peserta_id} baris={b} />)
                )}
            </section>

            {nilai_keluar && (
                <p className="keterangan">
                    Ada nilai yang sudah dipublikasikan.{' '}
                    <Link href="/siswa/nilai" className="font-medium underline">
                        Lihat nilai
                    </Link>
                </p>
            )}
        </KerangkaSiswa>
    );
}

/**
 * Satu baris daftar.
 *
 * Dua tingkat: apa ujiannya di atas, kapan dan di mana di bawahnya. Keduanya
 * dipisahkan oleh ukuran dan warna, bukan oleh kolom - kolom memaksa setiap
 * nilai muat mendatar, dan di ponsel yang berarti nama mapel dipotong.
 */
function BarisDaftar({ baris }: { baris: Baris }) {
    const keterangan =
        [
            baris.tanggal,
            baris.jam,
            baris.ruang_nama ?? baris.ruang,
            baris.kursi ? `kursi ${baris.kursi}` : null,
        ]
            .filter(Boolean)
            .join(', ') || null;

    // Presensi di baris daftar hanya untuk ujian yang BELUM selesai. Pada
    // ujian yang sudah lewat, "hadir" adalah catatan sejarah - dan baris
    // yang sudah menjawab "selesai" tidak perlu dijejali keterangan kedua.
    const presensi =
        !baris.selesai && baris.presensi.status !== null && baris.presensi.status !== 'pending'
            ? baris.presensi.label.toLowerCase()
            : null;

    return (
        <Link
            href={`/siswa/ujian/${baris.peserta_id}/gerbang`}
            className="daftar-baris flex items-center gap-2 px-3 py-2.5"
        >
            <div className="min-w-0 flex-1">
                <p className="truncate text-[13px] font-medium text-ink">
                    {baris.mapel ?? baris.ujian ?? 'Tanpa mapel'}
                </p>
                <p className="catatan mt-0.5 truncate">
                    {presensi && <span className="text-ink-2">{presensi} · </span>}
                    {/*
                     * Kalimat pengganti bergantung pada APAKAH ujiannya sudah
                     * terjadi, bukan pada apakah kolom sesinya terisi.
                     *
                     * "waktu dan ruang belum ditetapkan" dulu dicetak untuk
                     * setiap baris tanpa sesi - termasuk ujian yang sudah
                     * SELESAI, yang karena itu terbaca sebagai janji yang
                     * belum ditepati padahal anaknya baru saja mengerjakannya.
                     * Penempatan yang dilepas sesudah ujian selesai memang
                     * mengosongkan kolom sesi, dan itu bukan alasan untuk
                     * menyatakan jadwalnya belum pernah ada.
                     */}
                    {keterangan
                        ?? (baris.selesai
                            ? 'sudah dikerjakan, jadwalnya tidak lagi ditampilkan'
                            : 'waktu dan ruang belum ditetapkan')}
                </p>
            </div>

            <span className="shrink-0">
                <SelStatus nada={baris.nada}>{baris.status}</SelStatus>
            </span>
        </Link>
    );
}

/**
 * Pita ujian yang paling mendesak.
 *
 * Tombolnya menuju GERBANG, bukan langsung ke pengerjaan - dan itu satu-
 * satunya jalan yang benar: gerbang yang memeriksa presensi, token, dan
 * jendela sesi, dan yang menjelaskan penolakannya. Tombol yang melompatinya
 * akan membuat anak mendarat di halaman galat tanpa tahu apa yang kurang.
 *
 * "Lihat aturan" menuju alamat yang sama, dan itu bukan tautan ganda yang
 * lupa dibuang: gerbang yang menolak tetap menampilkan aturan main dan
 * alasan penolakannya, dan justru di situlah anak yang belum dipresensi
 * perlu membacanya.
 */
function PitaUtama({ baris }: { baris: Baris }) {
    const [mulai, selesai] = baris.jam?.split(/[–-]/) ?? [undefined, undefined];

    // Hitung mundur berjalan di peramban, tetapi ANGKANYA dari server - lihat
    // catatan `hitungMundur()` di controller. Jam peramban anak bisa meleset
    // berjam-jam, dan hitung mundur yang memakai jam perangkat akan
    // menjanjikan "3 menit lagi" pada anak yang sesinya baru dibuka dua jam
    // kemudian.
    const [sisa, setSisa] = useState(baris.hitung_mundur);

    useEffect(() => {
        setSisa(baris.hitung_mundur);

        if (baris.hitung_mundur === null) {
            return;
        }

        const jam = window.setInterval(() => {
            setSisa((v) => (v === null || v <= 1 ? 0 : v - 1));
        }, 1000);

        return () => window.clearInterval(jam);
    }, [baris.peserta_id, baris.hitung_mundur]);

    return (
        <section className="pita overflow-hidden">
            <header className="pita-baris flex items-center gap-2 px-3 py-2">
                <span className="angka text-[11px] text-plate-ink-2">
                    {baris.tanggal ?? 'belum dijadwalkan'}
                </span>
                <span className="ml-auto">
                    <SelStatus nada={baris.nada}>{baris.status}</SelStatus>
                </span>
            </header>

            <div className="p-3">
                {/*
                 * Jamnya angka terbesar di laman ini, dan itu memang
                 * maksudnya: satu-satunya pertanyaan yang dibawa anak ke
                 * beranda adalah "jam berapa", dan jawabannya harus terbaca
                 * sebelum kalimatnya.
                 */}
                <div className="flex items-baseline gap-2">
                    <b className="pita-angka text-[2rem]">{mulai ?? '-'}</b>
                    <span className="text-[11px] text-plate-ink-2">
                        {selesai ? `sampai ${selesai}` : 'jam belum ditetapkan'}
                    </span>
                </div>

                {/* Hitung mundur - dan ia HANYA muncul saat sesinya belum
                    dibuka. Sesudah dibuka, "dimulai dalam 0 detik" adalah
                    kebisingan; yang dibutuhkan anak saat itu adalah
                    keadaan presensinya, di baris berikutnya. */}
                {sisa !== null && sisa > 0 && (
                    <p className="mt-1.5 text-[12px] text-plate-ink">
                        dibuka dalam <b className="angka">{formatDurasi(sisa)}</b>
                    </p>
                )}

                <p className="mt-2 truncate font-display text-[15px] leading-tight">
                    {baris.mapel ?? baris.ujian ?? 'Ujian'}
                </p>

                <p className="mt-1 text-[12px] text-plate-ink-2">
                    {baris.ruang_nama ?? baris.ruang ?? 'Ruang belum ditetapkan'}
                    {baris.kursi ? `, kursi ${baris.kursi}` : ''}
                </p>

                {/* --------------------------------------------------
                    Presensi: satu baris, dan ia menjawab pertanyaan yang
                    sampai 2026-11-14 hanya bisa dijawab gerbang
                    --------------------------------------------------
                    Presensi adalah gerbang ujian. Anak yang belum
                    diverifikasi berdiri di depan pintu tanpa cara tahu
                    apakah ia harus memanggil pengawas atau menunggu - ia
                    hanya tahu setelah menekan Mulai dan ditolak.

                    Baris `terverifikasi` yang lama DIHAPUS, bukan
                    disandingkan: ia menyebut hal yang sama untuk satu
                    keadaan saja, dan dua baris yang menjawab pertanyaan
                    sama akan berselisih pada keadaan yang tidak mereka
                    duga bersama.
                    -------------------------------------------------- */}
                <p className="mt-1.5 text-[12px] text-plate-ink">
                    {baris.presensi.status === null ||
                    baris.presensi.status === 'pending' ? (
                        <>Presensi belum diverifikasi Pengawas</>
                    ) : baris.presensi.membuka_gerbang ? (
                        <>
                            Presensi {baris.presensi.label.toLowerCase()}
                            {baris.presensi.jam && ` pukul ${baris.presensi.jam}`}
                        </>
                    ) : (
                        <>
                            Presensi {baris.presensi.label.toLowerCase()} &mdash; hubungi
                            Pengawas
                        </>
                    )}
                </p>

                <div className="mt-3 flex flex-wrap gap-2">
                    {baris.boleh_mulai ? (
                        <Link
                            href={`/siswa/ujian/${baris.peserta_id}/gerbang`}
                            className="tombol tombol--pri flex-1"
                        >
                            Mulai ujian
                        </Link>
                    ) : (
                        <span
                            className="tombol flex-1"
                            aria-disabled="true"
                            title={baris.selesai ? 'Sudah dikerjakan' : 'Belum bisa dimulai'}
                        >
                            {baris.selesai ? 'Sudah dikerjakan' : 'Belum bisa dimulai'}
                        </span>
                    )}

                    <Link
                        href={`/siswa/ujian/${baris.peserta_id}/gerbang`}
                        className="tombol"
                    >
                        Lihat aturan
                    </Link>
                </div>
            </div>
        </section>
    );
}

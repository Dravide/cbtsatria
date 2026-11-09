import { Link } from '@inertiajs/react';
import KerangkaSiswa from '@/Layouts/KerangkaSiswa';
import { Inbox } from '@/Components/ui';

/**
 * Jadwal ujian saya - wireframe-peran.html plate 19. `/siswa`.
 *
 * =====================================================================
 * Papan ujian - dan mengapa beranda ini dulu terasa kosong
 * =====================================================================
 * Sampai redesign ini beranda menampilkan SATU kotak kalimat di atas layar
 * penuh kertas kosong: kalau anak belum ditempatkan pada event mana pun -
 * keadaan yang paling sering terjadi - yang ia lihat hanyalah empat baris
 * teks abu dan sisa layar yang putih. Papan ujian menggantinya.
 *
 * Bedanya bukan hiasan. Papan ini DIGAMBAR bahkan ketika isinya kosong:
 * kepalanya, kepala kolomnya, dan barisnya yang bergaris tetap ada, dan
 * baris kosongnya mengatakan apa yang seharusnya berdiri di situ. Halaman
 * yang menggambar wadahnya terbaca sebagai halaman yang isinya belum
 * datang; halaman yang menggambar satu kalimat terbaca sebagai halaman yang
 * rusak.
 *
 * =====================================================================
 * Ujian yang paling mendesak muncul sebagai SATU PELAT
 * =====================================================================
 * Plate 19 menulis alasannya sendiri: "Anak yang membuka laman ini sedang
 * mencari satu hal - apa yang harus saya kerjakan sekarang - dan jawabannya
 * tidak boleh memerlukan pembacaan tabel."
 *
 * Pelatnya bidang TINTA di dalam garis 2px, seperti papan memuat nomor
 * peron: satu perintah, satu angka. Angkanya amber karena amber hanya
 * terbaca di atas tinta (Wayfind, Do-nots).
 *
 * Kartu mana yang muncul ditentukan SERVER (`utama`), bukan peramban.
 * Membandingkan `mulai` dengan jam ponsel berarti jam perangkat yang meleset
 * satu hari menampilkan ujian yang salah sebagai "hari ini" - dan anak yang
 * menekan Mulai pada ujian besok akan menerima penolakan gerbang.
 *
 * ---------------------------------------------------------------------
 * Yang TIDAK dihitung di sini
 * ---------------------------------------------------------------------
 * `boleh_mulai`, `selesai`, dan seluruh kosakata status sudah diputuskan
 * `JadwalSiswaController`. Laman ini menampilkannya. Satu-satunya yang
 * dihitung di peramban adalah hal yang memang milik tampilan: baris papan
 * mana yang ditampilkan, dan tanggal mana yang ditulis sebagai judul
 * kelompok.
 *
 * ---------------------------------------------------------------------
 * Empat kolom, bukan satu kalimat dengan titik pemisah
 * ---------------------------------------------------------------------
 * Sampai redesign ini tanggal, jam, ruang dan kursi digabung menjadi satu
 * baris `A · B · C · D`. Papan tidak menulis begitu: ia punya KOLOM, dan
 * kolom bisa dibandingkan sekilas ke bawah sedangkan kalimat harus dibaca
 * satu per satu. Karena itu keempatnya punya tempatnya sendiri.
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
    utama: boolean;
    boleh_mulai: boolean;
    selesai: boolean;
    status: string;
    nada: string;
};

type Props = {
    nama: string;
    kelas: string | null;
    sekarang: string;
    utama: Baris | null;
    jadwal: Baris[];
    nilai_keluar: boolean;
};

/**
 * Warna sel status.
 *
 * Dipetakan di satu tempat karena ia muncul dua kali dengan bentuk yang
 * berbeda - sel di papan dan sel di pelat - dan dua peta yang berbeda akan
 * membuat "siap dimulai" berwarna kobalt di satu tempat dan kelabu di
 * tempat lain.
 */
const NADA: Record<string, string> = {
    ok: 'bg-ok-bg text-ok',
    warn: 'bg-warn-bg text-warn',
    bad: 'bg-bad-bg text-bad',
    info: 'bg-info-bg text-info',
    mute: 'bg-surface-2 text-ink-2',
};

/** Sel status - kotak bergaris, bukan chip bulat. Wayfind tidak membulat. */
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

export default function Jadwal({ nama, kelas, utama, jadwal, nilai_keluar }: Props) {
    // Baris yang sudah tampil sebagai pelat TIDAK diulang di papan di
    // bawahnya. Dua kali menyebut ujian yang sama membuat anak menghitungnya
    // dua kali saat ia bertanya "berapa ujian saya hari ini".
    const sisa = jadwal.filter((b) => b.peserta_id !== utama?.peserta_id);

    return (
        <KerangkaSiswa
            judul="Jadwal ujian saya"
            tabbarAktif="ujian"
            kiri={
                <span className="truncate text-[12px] font-medium text-ink">
                    Halo, {nama}
                </span>
            }
            kanan={
                kelas ? (
                    <span className="border border-line-2 px-1.5 py-0.5 text-[11px] text-ink-2">
                        {kelas}
                    </span>
                ) : undefined
            }
        >
            {utama && <PelatUtama baris={utama} />}

            {/*
             * Papan. Ia dirender SELALU - juga saat tidak ada satu pun ujian -
             * karena wadah yang tergambar itulah yang membedakan "belum ada
             * isinya" dari "tidak ada apa-apa di sini".
             */}
            <section className="papan overflow-hidden">
                <header className="papan-kepala">
                    <span>Ujian saya</span>
                    {jadwal.length > 0 && (
                        <span className="angka ml-auto text-ink">{jadwal.length}</span>
                    )}
                </header>

                <div className="papan-kolom flex items-center gap-2 px-3">
                    <span className="min-w-0 flex-1">Mata pelajaran</span>
                    <span className="w-[74px] shrink-0">Tanggal</span>
                    <span className="w-[62px] shrink-0">Jam</span>
                    <span className="w-[52px] shrink-0 text-right">Kursi</span>
                </div>

                {sisa.length === 0 ? (
                    <div className="px-3 py-6">
                        <p className="text-[12px] leading-relaxed text-ink-2">
                            {jadwal.length === 0
                                ? 'Belum ada ujian yang ditempatkan untuk Anda. Jadwal ditetapkan Admin lewat peserta event, dan akan muncul di sini begitu Anda terdaftar.'
                                : 'Tidak ada ujian lain yang menunggu. Semua yang ditempatkan untuk Anda sudah tampil di atas.'}
                        </p>
                    </div>
                ) : (
                    sisa.map((b) => <BarisPapan key={b.peserta_id} baris={b} />)
                )}
            </section>

            {nilai_keluar && (
                <Inbox>
                    Ada nilai yang sudah dipublikasikan.{' '}
                    <Link href="/siswa/nilai" className="font-medium underline">
                        Lihat nilai
                    </Link>
                </Inbox>
            )}
        </KerangkaSiswa>
    );
}

/** Satu baris papan: nama di atas, tempat dan waktu di bawahnya, status di kanan. */
function BarisPapan({ baris }: { baris: Baris }) {
    return (
        <div className="papan-baris flex items-start gap-2 px-3 py-2">
            <div className="min-w-0 flex-1">
                <p className="truncate text-[12px] text-ink">
                    {baris.mapel ?? baris.ujian ?? 'Tanpa mapel'}
                </p>
                <p className="catatan mt-0.5 truncate">
                    {baris.ruang_nama ?? baris.ruang ?? 'Ruang belum ditetapkan'}
                </p>
            </div>

            <span className="angka w-[74px] shrink-0 pt-px text-[11px] font-normal text-ink-2">
                {baris.tanggal ?? 'belum ada'}
            </span>
            <span className="angka w-[62px] shrink-0 pt-px text-[11px] font-normal text-ink-2">
                {baris.jam?.split('–')[0] ?? 'belum ada'}
            </span>
            <span className="angka w-[52px] shrink-0 pt-px text-right text-[11px] font-normal text-ink-2">
                {baris.kursi ?? '-'}
            </span>

            <span className="shrink-0">
                <SelStatus nada={baris.nada}>{baris.status}</SelStatus>
            </span>
        </div>
    );
}

/**
 * Pelat ujian yang paling mendesak.
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
function PelatUtama({ baris }: { baris: Baris }) {
    const terverifikasi = baris.status === 'siap' || baris.status === 'siap dimulai';

    return (
        <section className="pelat overflow-hidden">
            <header className="flex items-center gap-2 border-b border-plate-line px-3 py-2">
                <span className="angka text-[11px] font-normal text-plate-ink-2">
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
                    <b className="angka angka-amber text-2xl leading-none">
                        {baris.jam?.split('–')[0] ?? '-'}
                    </b>
                    <span className="text-[11px] text-plate-ink-2">
                        {baris.jam ? `sampai ${baris.jam.split('–')[1]}` : 'jam belum ditetapkan'}
                    </span>
                </div>

                <p className="mt-1.5 truncate font-display text-sm font-medium text-plate-ink">
                    {baris.mapel ?? baris.ujian ?? 'Ujian'}
                </p>

                <p className="mt-0.5 text-[11px] text-plate-ink-2">
                    {baris.ruang_nama ?? baris.ruang ?? 'Ruang belum ditetapkan'}
                    {baris.kursi ? `, kursi ${baris.kursi}` : ''}
                </p>

                {terverifikasi && (
                    <p className="mt-1.5 text-[11px] text-plate-ink">
                        Presensi sudah diverifikasi Pengawas
                    </p>
                )}

                <div className="mt-2.5 flex flex-wrap gap-2">
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

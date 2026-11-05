import { Link } from '@inertiajs/react';
import KerangkaSiswa from '@/Layouts/KerangkaSiswa';
import { Chip, Inbox } from '@/Components/ui';
import { KotakPonsel } from '@/Components/ponsel';

/**
 * Jadwal ujian saya - wireframe-peran.html plate 19. `/siswa`.
 *
 * =====================================================================
 * Ujian yang paling mendesak muncul sebagai SATU KARTU BESAR
 * =====================================================================
 * Plate 19 menulis alasannya sendiri: "Anak yang membuka laman ini sedang
 * mencari satu hal - apa yang harus saya kerjakan sekarang - dan jawabannya
 * tidak boleh memerlukan pembacaan tabel."
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
 * dihitung di peramban adalah hal yang memang milik tampilan: baris kartu
 * mana yang ditampilkan, dan tanggal mana yang ditulis sebagai judul
 * kelompok.
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

export default function Jadwal({ nama, kelas, utama, jadwal, nilai_keluar }: Props) {
    // Baris yang sudah tampil sebagai kartu besar TIDAK diulang di daftar di
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
            kanan={kelas ? <Chip nada="mute">{kelas}</Chip> : undefined}
        >
            {jadwal.length === 0 && (
                <Inbox>
                    Belum ada ujian yang ditempatkan untuk Anda. Jadwal ditetapkan Admin
                    lewat peserta event, dan akan muncul di sini begitu Anda terdaftar.
                </Inbox>
            )}

            {utama && <KartuUtama baris={utama} />}

            {sisa.length > 0 && (
                <KotakPonsel padat>
                    <div className="mb-1.5 grid grid-cols-[1fr_54px_48px_auto] gap-2 border-b border-line pb-1.5 text-[10px] tracking-wide text-ink-3 uppercase">
                        <span>Mata pelajaran</span>
                        <span>Tanggal</span>
                        <span>Jam</span>
                        <span className="text-right">Status</span>
                    </div>

                    <div className="space-y-0">
                        {sisa.map((b) => (
                            <div
                                key={b.peserta_id}
                                className="grid grid-cols-[1fr_54px_48px_auto] items-center gap-2 border-b border-line/60 py-2 last:border-0"
                            >
                                <span className="min-w-0 truncate text-[12px] text-ink">
                                    {b.mapel ?? 'Tanpa mapel'}
                                </span>
                                <span className="font-mono text-[11px] text-ink-2">
                                    {b.tanggal ?? '—'}
                                </span>
                                <span className="font-mono text-[11px] text-ink-2">
                                    {b.jam?.split('–')[0] ?? '—'}
                                </span>
                                <span className="justify-self-end">
                                    <Chip nada={b.nada as never}>{b.status}</Chip>
                                </span>
                            </div>
                        ))}
                    </div>
                </KotakPonsel>
            )}

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

/**
 * Kartu ujian yang paling mendesak.
 *
 * Tombolnya menuju GERBANG, bukan langsung ke pengerjaan - dan itu satu-
 * satunya jalan yang benar: gerbang yang memeriksa presensi, token, dan
 * jendela sesi, dan yang menjelaskan penolakannya. Tombol yang melompatinya
 * akan membuat anak mendarat di halaman galat tanpa tahu apa yang kurang.
 */
function KartuUtama({ baris }: { baris: Baris }) {
    const terverifikasi = baris.status === 'siap' || baris.status === 'siap dimulai';

    return (
        <KotakPonsel solid padat>
            <div className="border-l-[3px] border-accent pl-2.5">
                <div className="flex items-center gap-2">
                    <b className="min-w-0 truncate font-display text-sm text-ink">
                        {baris.mapel ?? baris.ujian ?? 'Ujian'}
                    </b>
                    <span className="ml-auto">
                        <Chip nada={baris.nada as never}>{baris.status}</Chip>
                    </span>
                </div>

                <p className="mt-0.5 text-[11px] text-ink-2">
                    {[baris.tanggal, baris.jam, baris.ruang, baris.kursi ? `Kursi ${baris.kursi}` : null]
                        .filter(Boolean)
                        .join(' · ')}
                </p>

                {terverifikasi && (
                    <p className="mt-1.5 flex items-center gap-1.5 text-[11px] text-ok">
                        <span className="grid size-3.5 shrink-0 place-items-center rounded-full bg-accent text-[8px] text-on-accent">
                            ✓
                        </span>
                        Presensi sudah diverifikasi Pengawas
                    </p>
                )}

                <div className="mt-2 flex flex-wrap gap-2">
                    {baris.boleh_mulai ? (
                        <Link
                            href={`/siswa/ujian/${baris.peserta_id}/gerbang`}
                            className="flex-1 rounded-wf border border-accent bg-accent px-3 py-2 text-center text-xs font-medium text-on-accent"
                        >
                            Mulai ujian
                        </Link>
                    ) : (
                        <span className="flex-1 rounded-wf border border-line bg-surface-2 px-3 py-2 text-center text-xs text-ink-3">
                            {baris.selesai ? 'Sudah dikerjakan' : 'Belum bisa dimulai'}
                        </span>
                    )}

                    {/* "Lihat aturan" menuju laman yang sama, dan itu bukan
                        tautan ganda yang lupa dibuang: gerbang yang menolak
                        tetap menampilkan aturan main dan alasan penolakannya,
                        dan justru di situlah anak yang belum dipresensi perlu
                        membacanya. */}
                    <Link
                        href={`/siswa/ujian/${baris.peserta_id}/gerbang`}
                        className="rounded-wf border border-line bg-surface px-3 py-2 text-xs text-ink hover:bg-surface-2"
                    >
                        Lihat aturan
                    </Link>
                </div>
            </div>
        </KotakPonsel>
    );
}

import { Link, router } from '@inertiajs/react';
import { useEffect } from 'react';
import KerangkaSiswa from '@/Layouts/KerangkaSiswa';
import { Chip, Inbox, Kotak } from '@/Components/ui';

/**
 * Pindai presensi - wireframe-presensi-qr.html plate B4-B6.
 *
 * =====================================================================
 * SATU komponen untuk TIGA laman
 * =====================================================================
 * Pengakuan, menunggu, dan penolakan berbagi satu alamat karena alamat itu
 * tertanam di dalam QR - dan QR yang sudah tercetak tidak bisa memilih
 * alamat kedua. Yang membedakan ketiganya `keadaan`, bukan URL-nya.
 *
 * =====================================================================
 * Kata di layar siswa adalah "pengakuan", bukan "hadir"
 * =====================================================================
 * Siswa menyatakan sesuatu; pengawas yang mencatat. Layar yang menulis
 * "Anda sudah hadir" sebelum ada yang mengonfirmasi akan membuat siswa
 * berjalan pergi dengan keyakinan yang tidak didukung satu baris data pun -
 * dan keyakinan itu baru runtuh di laporan kehadiran, berhari-hari
 * kemudian, ketika sudah tidak ada yang bisa diperbaiki.
 */

type Ruang = {
    kode: string | null;
    nama: string | null;
    lokasi: string | null;
    sesi: string | null;
    ujian: string | null;
    mapel: string | null;
    jam: string | null;
    sekolah: string | null;
};

type Siswa = {
    nama: string | null;
    nis: string | null;
    kelas: string | null;
    kursi: number | null;
};

type Props = {
    keadaan: 'akui' | 'tunggu' | 'tolak';
    /** Kode mentah dari URL - dipakai tombol akui agar POST ke alamat yang sama. */
    kode: string | null;
    ruang: Ruang | null;
    siswa: Siswa | null;
    penolakan: { judul: string; pesan: string; tenang: boolean } | null;
    tunggu: {
        dikirim: string | null;
        dikonfirmasi: boolean;
        dikonfirmasi_jam: string | null;
        gerbang: boolean;
        status: string | null;
        peserta_id: number | null;
    } | null;
};

/** Selang penyegaran laman tunggu - sama dengan laman gerbang. */
const SELANG_MS = 15_000;

export default function Hadir({ keadaan, kode, ruang, siswa, penolakan, tunggu }: Props) {
    const kepalaRuang = [ruang?.nama, ruang?.mapel, ruang?.sesi].filter(Boolean).join(' · ');

    // Hanya laman TUNGGU yang menyegarkan sendiri. Laman pengakuan tidak:
    // kalau ia menyegarkan diri, tombol "Saya hadir" berpindah di bawah jari
    // anak yang baru akan menekannya.
    useEffect(() => {
        if (keadaan !== 'tunggu') {
            return;
        }

        const jam = window.setInterval(() => {
            router.reload({ only: ['keadaan', 'tunggu'] });
        }, SELANG_MS);

        return () => window.clearInterval(jam);
    }, [keadaan]);

    return (
        <KerangkaSiswa
            judul="Presensi ruang"
            tanpaKeluar
            kiri={
                ruang?.kode ? (
                    <span className="flex items-center gap-2">
                        <Chip nada="info">{ruang.kode}</Chip>
                        <span className="min-w-0 truncate text-[11px] text-ink-3">
                            {[ruang.nama, ruang.mapel, ruang.sesi].filter(Boolean).join(' · ')}
                        </span>
                    </span>
                ) : (
                    <span className="truncate text-[12px] text-ink-2">Presensi ruang</span>
                )
            }
            kanan={
                keadaan === 'tolak' ? (
                    <Chip nada={penolakan?.tenang ? 'ok' : 'bad'}>
                        {penolakan?.tenang ? 'sudah beres' : 'ditolak'}
                    </Chip>
                ) : keadaan === 'tunggu' ? (
                    <Chip nada={tunggu?.dikonfirmasi ? 'ok' : 'warn'}>
                        {tunggu?.dikonfirmasi ? 'dikonfirmasi' : 'menunggu'}
                    </Chip>
                ) : (
                    <Chip nada="ok">kode sah</Chip>
                )
            }
        >
            {/* Ruang dan sesi selalu tampil - pada ketiga keadaan. Yang
                memindai kode dari ruang yang salah harus bisa melihat ruang
                MANA yang sedang ia buka, bukan hanya bahwa ia ditolak. */}
            {ruang !== null && (
                <Kotak
                    judul="Ruang dan sesi"
                    aksi={
                        <span className="font-mono text-[11px] text-ink-3">{ruang.jam ?? ''}</span>
                    }
                >
                    <b className="block font-display text-[12px] text-ink">
                        {[ruang.nama, ruang.mapel].filter(Boolean).join(' · ')}
                    </b>
                    <span className="block text-[11px] text-ink-3">
                        {[ruang.sekolah, ruang.sesi].filter(Boolean).join(' · ')}
                    </span>
                </Kotak>
            )}

            {keadaan === 'akui' && siswa !== null && (
                <>
                    <Kotak
                        judul="Anda"
                        aksi={<Chip nada="info">kursi {siswa.kursi ?? '—'}</Chip>}
                    >
                        <b className="block font-display text-[12px] text-ink">{siswa.nama}</b>
                        <span className="block text-[11px] text-ink-3">
                            {[siswa.kelas, siswa.nis && `NIS ${siswa.nis}`]
                                .filter(Boolean)
                                .join(' · ')}
                        </span>
                        <Inbox>
                            Penempatan Anda memang di ruang dan sesi ini. Menekan tombol di
                            bawah menyatakan Anda hadir di ruang ini sekarang.
                        </Inbox>
                    </Kotak>

                    {/* Tombol konfirmasi kedua ada karena laman ini bisa
                        terbuka di ponsel yang dipinjam, atau tertinggal di
                        tangan orang lain. Satu ketukan tambahan jauh lebih
                        murah daripada satu baris presensi yang salah atas
                        nama anak yang tidak ada di sana. */}
                    <Link
                        href={`/hadir/${kode}`}
                        method="post"
                        as="button"
                        className="w-full rounded-wf border border-accent bg-accent px-3 py-2.5 text-center text-[13px] font-medium text-on-accent"
                    >
                        Saya hadir di ruang ini
                    </Link>

                    <Inbox>
                        Presensi belum tercatat sampai Pengawas mengonfirmasinya. Kalau Anda
                        meninggalkan ruang tanpa dikonfirmasi, status Anda tetap <b>belum</b>.
                    </Inbox>
                </>
            )}

            {keadaan === 'tunggu' && tunggu !== null && (
                <>
                    <div className="grid justify-items-center gap-1 py-3 text-center">
                        <span className="grid size-9 place-items-center rounded-full bg-ok-bg text-ok">
                            &#10003;
                        </span>
                        <b className="font-display text-[14px] text-ink">
                            Pengakuan Anda terkirim
                        </b>
                        <span className="text-[11px] text-ink-3">
                            Dicatat pukul {tunggu.dikirim ?? '—'}
                            {siswa?.kursi ? ` · kursi ${String(siswa.kursi).padStart(2, '0')}` : ''}
                            {ruang?.kode ? ` · ${ruang.kode}` : ''}
                        </span>
                    </div>

                    <div className="rounded-wf border border-line bg-surface">
                        <Baris label="Dikirim" nilai={tunggu.dikirim ?? '—'} nada="ok" />
                        <Baris
                            label="Dikonfirmasi pengawas"
                            nilai={tunggu.dikonfirmasi ? (tunggu.dikonfirmasi_jam ?? 'ya') : 'belum'}
                            nada={tunggu.dikonfirmasi ? 'ok' : 'mute'}
                        />
                        {/* Baris ini ada karena inilah yang SEBENARNYA
                            ditunggu siswa. Tanpa dia, mereka menekan "Mulai"
                            berulang kali dan mengira tokennya yang salah. */}
                        <Baris
                            label="Gerbang ujian"
                            nilai={tunggu.gerbang ? 'terbuka' : 'tertutup'}
                            nada={tunggu.gerbang ? 'ok' : 'mute'}
                            akhir
                        />
                    </div>

                    <Inbox>
                        Gerbang ujian terbuka setelah Pengawas mengonfirmasi. Anda boleh
                        menunggu di kursi; laman ini menyegarkan sendiri.
                    </Inbox>

                    <Kotak judul="Kalau tidak dikonfirmasi">
                        <span className="text-[11px] leading-relaxed text-ink-3">
                            Sebutkan kursi {siswa?.kursi ?? 'Anda'} kepada Pengawas. Ia dapat
                            mencatatnya dari daftar kursi seperti biasa - pindai hanyalah jalan
                            pintas, dan jalan yang lama tetap terbuka.
                        </span>
                    </Kotak>

                    <div className="flex flex-wrap gap-1.5">
                        <button
                            type="button"
                            onClick={() => router.reload()}
                            className="rounded-wf border border-line bg-surface px-3 py-1.5 text-[11px] text-ink-2"
                        >
                            Muat ulang
                        </button>
                        {tunggu.gerbang && tunggu.peserta_id !== null && (
                            <Link
                                href={`/siswa/ujian/${tunggu.peserta_id}/gerbang`}
                                className="rounded-wf border border-accent bg-accent px-3 py-1.5 text-[11px] font-medium text-on-accent"
                            >
                                Buka gerbang ujian
                            </Link>
                        )}
                    </div>
                </>
            )}

            {keadaan === 'tolak' && penolakan !== null && (
                <>
                    <div
                        className={
                            'rounded-wf border px-3 py-2.5 ' +
                            (penolakan.tenang
                                ? 'border-ok/45 bg-ok-bg/60'
                                : 'border-bad/45 bg-bad-bg/60')
                        }
                    >
                        <b
                            className={
                                'block text-[12px] font-medium ' +
                                (penolakan.tenang ? 'text-ok' : 'text-bad')
                            }
                        >
                            {penolakan.judul}
                        </b>
                        <span
                            className={
                                'mt-0.5 block text-[11px] leading-relaxed ' +
                                (penolakan.tenang ? 'text-ink-2' : 'text-bad')
                            }
                        >
                            {penolakan.pesan}
                        </span>
                    </div>

                    {/* Setiap penolakan menyebut APA YANG HARUS DILAKUKAN
                        BERIKUTNYA. Penolakan yang hanya berbunyi "kode tidak
                        sah" membuat siswa menebak, lalu menanyakan hal yang
                        salah kepada pengawas di pintu. */}
                    <Inbox>
                        Pindai ulang kode yang <b>sekarang</b> tampil di layar Pengawas
                        ruang Anda. Kalau layar itu tidak menampilkan kode, sesinya belum
                        dibuka.
                    </Inbox>

                    <Link
                        href="/siswa/jadwal"
                        className="block w-full rounded-wf border border-line bg-surface px-3 py-2 text-center text-[12px] text-ink-2"
                    >
                        Kembali ke jadwal ujian
                    </Link>
                </>
            )}

            <p className="keterangan">
                {kepalaRuang !== '' ? kepalaRuang : 'Presensi ruang ujian'}
            </p>
        </KerangkaSiswa>
    );
}

/** Satu baris kiri-kanan pada kotak keadaan tunggu. */
function Baris({
    label,
    nilai,
    nada,
    akhir = false,
}: {
    label: string;
    nilai: string;
    nada: 'ok' | 'warn' | 'bad' | 'info' | 'mute';
    akhir?: boolean;
}) {
    return (
        <div
            className={
                'flex items-center gap-2 px-3 py-2 ' +
                (akhir ? '' : 'border-b border-line/60')
            }
        >
            <span className="min-w-0 flex-1 truncate text-[12px] text-ink-2">{label}</span>
            <Chip nada={nada}>{nilai}</Chip>
        </div>
    );
}

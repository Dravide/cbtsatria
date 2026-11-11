import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import KerangkaSiswa from '@/Layouts/KerangkaSiswa';
import { Chip } from '@/Components/ui';
import { KotakPonsel } from '@/Components/ponsel';

/**
 * Ujian terkunci - wireframe-peran.html plate 25.
 *
 * =====================================================================
 * WAKTU TETAP BERJALAN, dan laman ini menuliskannya
 * =====================================================================
 * Plate 25 menutup dengan kalimat yang disalin apa adanya di bawah: "Waktu
 * ujian tetap berjalan selama terkunci."
 *
 * Itu bukan hiasan. Siswa yang tidak tahu waktunya terus berkurang akan
 * menunggu dengan tenang, lalu kehabisan waktu - dan menyalahkan sistem,
 * dan kali ini ia benar. Karena itu jamnya DITAMPILKAN, dan terus
 * diperbarui, bukan disembunyikan supaya layarnya terlihat tenang.
 *
 * ---------------------------------------------------------------------
 * Rincian pelanggarannya dibaca ANAK ITU SENDIRI
 * ---------------------------------------------------------------------
 * Dan itu keputusan yang sedikit merugikan pengawas - catatan yang bisa
 * dibaca yang bersangkutan akan selalu ditanyakan. Yang membelanya ada di
 * plate 29: catatan yang tidak dapat dibantah dengan data adalah catatan
 * yang tidak layak dipakai untuk menjatuhkan sanksi.
 *
 * Yang dikirim ke sini adalah catatan yang SAMA dengan yang dibaca pengawas
 * di plate 16, termasuk kolom "Tindakan" hasil `jejakTindakan()`. Tidak ada
 * versi yang diperhalus, karena siswa yang menerima versi ringkas lalu
 * mendengar versi lengkapnya akan menyimpulkan bahwa sistemnya menyembunyikan
 * sesuatu.
 *
 * ---------------------------------------------------------------------
 * Tidak ada tombol apa pun, dan itu disengaja
 * ---------------------------------------------------------------------
 * Yang bisa membuka kunci hanya pengawas atau Admin. Tombol "minta buka"
 * akan menyelesaikan masalah tampilan, bukan masalah anaknya: pengawas tetap
 * harus datang ke kursinya, dan tombol yang tidak mengubah apa pun adalah
 * tombol yang membuat anak menunggu di depan layar.
 */

type PelanggaranRingkas = {
    jenis: string;
    label: string;
    jumlah: number;
    tingkat: string;
    nada: string;
};

type Riwayat = {
    id: number;
    jam: string | null;
    jenis: string;
    tindakan: string;
    tingkat: string;
    nada: string;
    durasi: number | null;
    tindakan_pengawas: boolean;
};

type Props = {
    peserta: { peserta_id: number; ujian: string | null; mapel: string | null; ruang: string | null };
    attempt: {
        id: number;
        alasan: string | null;
        terkunci_pada: string | null;
        jumlah: number;
        sisa_detik: number;
        deadline: string | null;
    };
    ringkasan: PelanggaranRingkas[];
    riwayat: Riwayat[];
    progres: { total: number; terjawab: number; kosong: number; ditandai: number; ringkas: string };
};

/** Selang penyegaran - laman ini tidak punya websocket. */
const SELANG_MS = 20_000;

export default function Terkunci({ peserta, attempt, ringkasan, riwayat, progres }: Props) {
    const [sisa, setSisa] = useState(attempt.sisa_detik);

    // Jamnya berjalan sendiri di sini, dan disegarkan dari server tiap
    // SELANG_MS. Dua-duanya perlu: hitungan lokal membuat angkanya bergerak
    // tanpa menunggu jaringan, dan penyegaran berkala membuatnya TIDAK
    // menyimpang dari jam server pada ponsel yang jamnya salah.
    useEffect(() => {
        const jam = window.setInterval(() => setSisa((lama) => Math.max(0, lama - 1)), 1000);

        return () => window.clearInterval(jam);
    }, []);

    useEffect(() => {
        const jam = window.setInterval(() => {
            router.reload({ only: ['attempt', 'ringkasan', 'riwayat', 'progres'] });
        }, SELANG_MS);

        return () => window.clearInterval(jam);
    }, []);

    return (
        <KerangkaSiswa
            judul="Ujian terkunci"
            gelap
            tanpaKeluar
            kiri={
                <span className="truncate text-[12px] text-ink-2">
                    {[peserta.mapel, peserta.ruang].filter(Boolean).join(' · ') || peserta.ujian}
                </span>
            }
            kanan={<Chip nada="bad">terkunci</Chip>}
        >
            <div className="grid justify-items-center gap-2 py-4 text-center">
                <b className="font-display text-[14px] text-bad">Ujian Anda dikunci</b>

                <p className="max-w-[22rem] text-[12px] leading-relaxed text-ink-2">
                    {attempt.alasan ??
                        `Terdeteksi ${attempt.jumlah} pelanggaran selama ujian berlangsung.`}{' '}
                    Pengerjaan dihentikan sementara. Jawaban yang sudah tersimpan tidak hilang.
                </p>

                {/* Sisa waktu DITAMPILKAN, bukan disembunyikan - lihat catatan
                    berkas ini. `sisa` nol berarti waktunya sudah habis, dan
                    itu keadaan yang berbeda artinya untuk anak. */}
                <p className="text-[12px] text-ink-2">
                    {sisa > 0 ? (
                        <>
                            Sisa waktu ujian{' '}
                            <b className="font-mono text-warn">{formatSisa(sisa)}</b>
                        </>
                    ) : (
                        <b className="text-bad">Waktu ujian sudah habis.</b>
                    )}
                </p>

                <p className="text-[11px] text-ink-3">
                    {progres.terjawab} dari {progres.total} soal sudah tersimpan
                </p>
            </div>

            {ringkasan.length > 0 && (
                <KotakPonsel solid padat judul="Ringkasan pelanggaran">
                    <div className="space-y-0">
                        {ringkasan.map((r) => (
                            <div
                                key={r.jenis}
                                className="flex items-center gap-2 border-b border-line/60 py-2 last:border-0"
                            >
                                <span className="min-w-0 flex-1 truncate text-[12px] text-ink">
                                    {r.label}
                                </span>
                                <span className="shrink-0 font-mono text-[11px] text-ink-2">
                                    {r.jumlah}×
                                </span>
                                <Chip nada={r.nada as never}>{r.tingkat}</Chip>
                            </div>
                        ))}
                    </div>
                </KotakPonsel>
            )}

            {/* Rincian per kejadian, jam demi jam. Kotak ringkasan di atas
                menjawab "apa yang terjadi"; daftar ini menjawab "kapan".
                Anak yang mengajukan keberatan memerlukan yang kedua. */}
            {riwayat.length > 0 && (
                <KotakPonsel padat judul="Riwayat kejadian">
                    <div className="space-y-0">
                        {riwayat.map((r) => (
                            <div
                                key={r.id}
                                className="flex items-center gap-2 border-b border-line/60 py-2 last:border-0"
                            >
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-[12px] text-ink">
                                        {r.jenis}
                                    </span>
                                    <span className="text-[10px] text-ink-3">
                                        {r.tindakan}
                                        {r.durasi !== null && ` · ${r.durasi} detik`}
                                    </span>
                                </span>
                                <span className="shrink-0 font-mono text-[11px] text-ink-3">
                                    {r.jam ?? '—'}
                                </span>
                                <Chip nada={r.nada as never}>{r.tingkat}</Chip>
                            </div>
                        ))}
                    </div>
                </KotakPonsel>
            )}

            <p className="keterangan">
                Hubungi Pengawas ruang untuk membuka kunci. Waktu ujian tetap berjalan selama
                terkunci.
            </p>
        </KerangkaSiswa>
    );
}

/** `mm:ss` atau `h:mm:ss` - jam dinding, bukan jumlah detik. */
function formatSisa(detik: number): string {
    const j = Math.floor(detik / 3600);
    const m = Math.floor((detik % 3600) / 60);
    const d = detik % 60;

    const dua = (n: number) => String(n).padStart(2, '0');

    return j > 0 ? `${j}:${dua(m)}:${dua(d)}` : `${dua(m)}:${dua(d)}`;
}

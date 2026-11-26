import { Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import KerangkaPonsel from '@/Layouts/KerangkaPonsel';
import { Chip, Inbox, Peringatan } from '@/Components/ui';
import { KpiPonsel, KotakPonsel } from '@/Components/ponsel';

/**
 * Plate 16 - riwayat pelanggaran siswa.
 *
 * =====================================================================
 * Tiga tombol tetap ada, dan menolak dengan ALASAN
 * =====================================================================
 * Plate 16 menggambar tiga tombol. Fase 6 belum punya satu pun attempt
 * terkunci untuk dikunci-lawan - yang mengunci adalah tangga eskalasi Fase
 * 7 - dan yang dipilih BUKAN menghapus tombolnya, melainkan membiarkannya
 * menolak dengan kalimat.
 *
 * Alasannya bukan soal tampilan. Tombol yang hilang membuat laman ini
 * berbeda antara Fase 6 dan Fase 7, dan laman yang berubah bentuk saat
 * mesin ujian dinyalakan adalah laman yang belum pernah diuji dalam bentuk
 * yang sebenarnya dipakai. Sementara tombol yang menolak tanpa kalimat akan
 * membuat pengawas menekannya berulang kali lalu menelepon Admin.
 *
 * Server yang memutuskan, bukan tombolnya: yang dijaga di sana bukan "boleh
 * atau tidak", melainkan "keadaannya memungkinkan atau tidak" - dan keadaan
 * itu dibaca dari basis data.
 *
 * ---------------------------------------------------------------------
 * Riwayat ini juga terlihat siswa dari akunnya sendiri
 * ---------------------------------------------------------------------
 * Kalau catatan perilaku hanya bisa dibaca pengawas, siswa yang merasa
 * dirugikan tidak punya cara membantah dengan data - dan sengketa seperti
 * itu biasanya berakhir dengan keputusan berdasarkan siapa yang lebih
 * dipercaya.
 */
type Riwayat = {
    id: number;
    jam: string | null;
    tanggal: string | null;
    jenis: string;
    tingkat: string;
    nada: string;
    severity: number;
    sumber: string;
    durasi: string | null;
    ditangani: boolean;
};

type Props = {
    ruang: { id: number; kode: string; nama: string | null; sesi: string | null; ujian: string | null };
    pelaku: string;
    peserta: { id: number; nama: string; nis: string | null; kelas: string | null };
    angka: { pelanggaran: number; terberat: number; progres: string | null };
    pengerjaan: {
        ada: boolean;
        status: string | null;
        status_label: string;
        status_nada: string;
        terkunci: boolean;
        kunci_alasan: string | null;
        terhubung: boolean | null;
        terakhir: string | null;
        /** Detik tersisa menurut server; null bila belum mulai. */
        sisa_detik: number | null;
        berakhir_pada: string | null;
        akhir_sesi: string | null;
    };
    riwayat: Riwayat[];
};

export default function Pelanggaran({ ruang, peserta, angka, pengerjaan, riwayat }: Props) {
    const [kirim, setKirim] = useState(false);
    const [catatan, setCatatan] = useState(false);
    const [isiCatatan, setIsiCatatan] = useState('');

    /**
     * Hitungan mundur - lokal, satu detik, tanpa jaringan.
     *
     * Angkanya MULAI dari `sisa_detik` yang dihitung server, lalu berkurang
     * sendiri. Server tetap satu-satunya yang memutuskan kapan pengerjaan
     * berakhir (`MesinUjian` menutup attempt yang lewat deadline), jadi
     * hitungan ini adalah tampilan, bukan penentu - jam ponsel pengawas
     * tidak pernah ikut menentukan apa pun.
     *
     * Ia berhenti di nol alih-alih menjadi negatif: "-3:12" akan terbaca
     * seolah masih ada waktu tiga menit lagi.
     */
    const [sisa, setSisa] = useState(pengerjaan.sisa_detik);

    useEffect(() => {
        setSisa(pengerjaan.sisa_detik);
    }, [pengerjaan.sisa_detik]);

    useEffect(() => {
        if (pengerjaan.sisa_detik === null) {
            return;
        }

        const jam = window.setInterval(
            () => setSisa((lama) => (lama === null ? null : Math.max(0, lama - 1))),
            1000,
        );

        return () => window.clearInterval(jam);
    }, [pengerjaan.sisa_detik]);

    const habis = sisa !== null && sisa <= 0;

    const bukaKunci = () => {
        setKirim(true);
        router.post(
            `/pengawas/ruang/${ruang.id}/siswa/${peserta.id}/buka`,
            {},
            { preserveScroll: true, onFinish: () => setKirim(false) },
        );
    };

    // "Tandai sudah dibina" menulis insiden ruang - sebab itulah satu-satunya
    // tempat di modul ini yang punya penulis di Fase 6. Catatan pembinaan
    // yang tidak ditulis ke mana pun adalah catatan yang hilang saat laman
    // ditutup.
    const tandaiDibina = () => {
        const teks = isiCatatan.trim().length >= 4
            ? isiCatatan.trim()
            : `Pembinaan kepada ${peserta.nama} setelah ${angka.pelanggaran} pelanggaran.`;

        setKirim(true);
        router.post(
            `/pengawas/ruang/${ruang.id}/insiden`,
            { jenis: 'lain', keterangan: teks, peserta_id: peserta.id },
            {
                preserveScroll: true,
                onFinish: () => {
                    setKirim(false);
                    setCatatan(false);
                    setIsiCatatan('');
                },
            },
        );
    };

    return (
        <KerangkaPonsel
            judul={`Pelanggaran ${peserta.nama}`}
            bilah={false}
            // Bilah bawah dengan jalan pulang. Laman ini dibuka dari baris
            // nama di papan monitoring, dan sebelumnya satu-satunya cara
            // keluar adalah tombol kembali PERAMBAN - yang tidak ada di
            // ponsel yang dipakai sebagai aplikasi, dan tidak dikenal
            // pengawas yang baru pertama memegangnya. Rutenya disebut
            // eksplisit, bukan `router.back()`, karena laman ini bisa dibuka
            // langsung dari tautan yang dikirim lewat pesan, dan riwayat
            // kosong akan meninggalkannya di tempat yang sama.
            aksiBawah={
                <Link
                    href={`/pengawas/ruang/${ruang.id}/monitoring`}
                    className="inline-flex min-h-11 items-center justify-center gap-1.5 rounded-wf border border-line bg-surface px-3 text-xs font-medium text-ink"
                >
                    <span aria-hidden="true">‹</span> Kembali ke monitoring
                </Link>
            }
        >
            <header className="sticky top-0 z-20 flex items-center gap-2 border-b border-line bg-surface/95 px-3 py-2 backdrop-blur">
                <span className="min-w-0 truncate text-[11px] text-ink-3">
                    {[peserta.nama, peserta.kelas, peserta.nis].filter(Boolean).join(' · ')}
                </span>
                <span className="ml-auto shrink-0">
                    <Chip nada={pengerjaan.terkunci ? 'bad' : pengerjaan.status_nada as 'mute'}>
                        {pengerjaan.terkunci ? 'terkunci' : pengerjaan.status_label}
                    </Chip>
                </span>
            </header>

            <div className="grid grid-cols-3 gap-1">
                <KpiPonsel
                    label="Pelanggaran"
                    nilai={angka.pelanggaran}
                    nada={angka.pelanggaran > 0 ? 'warn' : undefined}
                />
                <KpiPonsel
                    label="Terberat"
                    nilai={angka.terberat}
                    nada={angka.terberat >= 3 ? 'bad' : undefined}
                />
                <KpiPonsel label="Progres" nilai={angka.progres ?? '—'} />
            </div>

            {/* ------------------------------------------------ hitung mundur */}
            {/* Waktu yang tersisa BAGI ANAK INI, bukan sisa sesi ruang.

                Keduanya berbeda, dan pengawas yang tidak bisa membedakannya
                akan mendatangi anak yang masih punya empat puluh menit atau
                meninggalkan anak yang tinggal tiga menit. `deadline_at` sudah
                memuat MIN(durasi, akhir sesi) yang dihitung server, jadi satu
                angka ini menjawab keduanya sekaligus - dan jam berakhirnya
                ditulis di bawah sebagai pembanding. */}
            <KotakPonsel judul="Sisa waktu" padat>
                {sisa === null ? (
                    <p className="text-[11px] leading-relaxed text-ink-3">
                        {pengerjaan.ada
                            ? 'Pengerjaan ini tidak punya batas waktu yang tercatat.'
                            : 'Peserta ini belum mulai mengerjakan, jadi belum ada waktu berjalan.'}
                    </p>
                ) : (
                    <div className="flex items-end gap-2">
                        <span
                            className={
                                'angka font-mono text-2xl leading-none font-semibold tabular-nums ' +
                                // Di bawah lima menit ia memanas: pengawas harus
                                // tahu tanpa membaca angka, karena yang dibacanya
                                // adalah nama anak yang sedang ditangani.
                                (habis || sisa <= 300 ? 'text-bad' : 'text-ink')
                            }
                        >
                            {formatSisa(sisa)}
                        </span>
                        <span className="pb-0.5 text-[11px] text-ink-3">
                            {habis ? 'waktu habis' : 'tersisa'}
                        </span>
                    </div>
                )}

                <p className="mt-1 text-[11px] leading-relaxed text-ink-3">
                    {[
                        pengerjaan.berakhir_pada ? `berakhir ${pengerjaan.berakhir_pada}` : null,
                        pengerjaan.akhir_sesi ? `sesi tutup ${pengerjaan.akhir_sesi}` : null,
                    ]
                        .filter(Boolean)
                        .join(' · ') || '—'}
                </p>

                {/* Angkanya bergerak sendiri tanpa jaringan: yang dikirim
                    server adalah detik sisa, dan laman ini hanya
                    menguranginya. Yang MENUTUP pengerjaan tetap server - jam
                    ponsel pengawas tidak pernah menentukan akhir ujian. */}
                {sisa !== null && (
                    <p className="mt-1 text-[11px] leading-relaxed text-ink-3">
                        Hitungan ini berjalan sendiri di ponsel dan berhenti di nol. Yang
                        menutup pengerjaan adalah server, jadi angka yang sudah nol di sini
                        tidak berarti attempt-nya sudah tertutup.
                    </p>
                )}
            </KotakPonsel>

            {pengerjaan.kunci_alasan && (
                <Peringatan>
                    Dikunci: {pengerjaan.kunci_alasan}
                </Peringatan>
            )}

            <KotakPonsel padat>
                {/* Dua tingkat, bukan empat kolom tetap. `w-11 + w-14 + w-16`
                    berjumlah 212px sebelum satu huruf pun masuk ke kolom
                    "Kejadian" - dan pada ponsel 320px sisa untuk nama kejadian
                    tinggal ~36px. Kolom kanan sekarang menumpuk (tingkat di
                    atas, sumber di bawahnya), jadi yang tetap hanya jam. */}
                <div className="flex items-center gap-2 border-b border-line/70 px-3 py-1.5 text-[11px] text-ink-3">
                    <span className="w-9 shrink-0">Jam</span>
                    <span className="min-w-0 flex-1">Kejadian</span>
                    <span className="shrink-0">Tingkat &middot; sumber</span>
                </div>

                {riwayat.length === 0 && (
                    <p className="px-1 py-6 text-center text-xs text-ink-3">
                        {pengerjaan.ada
                            ? 'Belum ada pelanggaran tercatat.'
                            : 'Peserta ini belum mulai mengerjakan, jadi belum ada catatan.'}
                    </p>
                )}

                {riwayat.map((r) => (
                    <div
                        key={r.id}
                        className="flex items-start gap-2 border-b border-line/60 px-3 py-2 last:border-0"
                    >
                        <span className="w-9 shrink-0 pt-0.5 font-mono text-[11px] text-ink-3">
                            {r.jam ?? '—'}
                        </span>

                        <div className="min-w-0 flex-1">
                            <p className="text-xs text-ink">{r.jenis}</p>
                            <p className="text-[11px] text-ink-3">
                                {[r.tanggal, r.durasi, r.ditangani ? 'ditangani' : null]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </p>
                        </div>

                        <div className="flex shrink-0 flex-col items-end gap-0.5">
                            <Chip nada={r.nada as 'mute'}>{r.tingkat}</Chip>
                            <span className="text-[11px] text-ink-3">{r.sumber}</span>
                        </div>
                    </div>
                ))}
            </KotakPonsel>

            <div className="flex flex-wrap gap-1.5">
                <button
                    type="button"
                    onClick={bukaKunci}
                    disabled={kirim || !pengerjaan.terkunci}
                    className="inline-grid min-h-11 place-items-center rounded-wf border border-accent bg-accent px-3 text-xs font-medium text-on-accent disabled:cursor-not-allowed disabled:border-ink-3/40 disabled:bg-ink-3/40"
                >
                    Buka kunci
                </button>
                <button
                    type="button"
                    onClick={() => setCatatan((c) => !c)}
                    className="inline-grid min-h-11 place-items-center rounded-wf border border-line bg-surface px-3 text-xs text-ink"
                >
                    Tandai sudah dibina
                </button>
            </div>

            {/* Tombol "Buka kunci" dimatikan selama tidak ada yang terkunci -
                dan alasannya ditulis tepat di bawahnya, bukan dibiarkan jadi
                tombol mati tanpa keterangan. */}
            {!pengerjaan.terkunci && (
                <p className="px-1 text-[11px] text-ink-3">
                    {pengerjaan.ada
                        ? 'Pengerjaan peserta ini tidak sedang terkunci, jadi tidak ada yang bisa dibuka.'
                        : 'Peserta ini belum mulai mengerjakan, jadi belum ada yang terkunci.'}
                </p>
            )}

            {catatan && (
                <KotakPonsel padat judul="Catatan pembinaan">
                    <textarea
                        value={isiCatatan}
                        onChange={(e) => setIsiCatatan(e.target.value)}
                        rows={3}
                        placeholder={`Pembinaan kepada ${peserta.nama}…`}
                        className="w-full rounded-wf border border-line bg-surface px-2 py-1.5 text-xs text-ink outline-none focus:border-accent"
                    />
                    <div className="flex flex-wrap gap-1.5">
                        <button
                            type="button"
                            onClick={tandaiDibina}
                            disabled={kirim}
                            className="inline-grid min-h-11 place-items-center rounded-wf border border-accent bg-accent px-3 text-xs font-medium text-on-accent disabled:opacity-50"
                        >
                            Simpan catatan
                        </button>
                        <button
                            type="button"
                            onClick={() => setCatatan(false)}
                            className="inline-grid min-h-11 place-items-center rounded-wf border border-line bg-surface px-3 text-xs text-ink-2"
                        >
                            Batal
                        </button>
                    </div>
                    <p className="text-[11px] text-ink-3">
                        Catatan disimpan sebagai insiden ruang atas nama peserta ini,
                        sehingga terbaca juga oleh panitia dan Admin.
                    </p>
                </KotakPonsel>
            )}

            <Inbox>
                Riwayat ini juga terlihat siswa dari akunnya sendiri. Baris lama tidak
                pernah dihapus; yang dibuka hanyalah pengunciannya, dan pembukaan itu
                meninggalkan jejaknya sendiri.
            </Inbox>
        </KerangkaPonsel>
    );
}

/**
 * Detik sisa sebagai jam:menit:detik.
 *
 * `tabular-nums` di pemanggilnya, bukan di sini: angka yang lebarnya
 * berubah setiap detik membuat teks di sebelahnya bergeser, dan mata yang
 * sedang mengawasi hitungan mundur membaca geseran itu sebagai perubahan
 * angka.
 */
function formatSisa(detik: number): string {
    const j = Math.floor(detik / 3600);
    const m = Math.floor((detik % 3600) / 60);
    const d = detik % 60;
    const dua = (n: number) => String(n).padStart(2, '0');

    return j > 0 ? `${dua(j)}:${dua(m)}:${dua(d)}` : `${dua(m)}:${dua(d)}`;
}

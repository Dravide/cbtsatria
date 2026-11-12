import { router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import KerangkaPonsel from '@/Layouts/KerangkaPonsel';
import { Chip, Inbox, Peringatan } from '@/Components/ui';
import { KpiPonsel, KotakPonsel, PhotoPonsel, TtdPonsel } from '@/Components/ponsel';

/**
 * Plate 18 - berita acara: foto & tanda tangan.
 *
 * =====================================================================
 * Admin boleh MEMBACA, tidak boleh MENANDATANGANI
 * =====================================================================
 * Blueprint `:962` menyatakannya sebagai kalimat: "tanda tangan atas ruang
 * yang tidak ia masuki adalah keterangan palsu."
 *
 * Kalimat itu punya akibat di layar ini, bukan hanya di server: prop
 * `boleh_ttd` false membuat kanvasnya TIDAK MUNCUL, dan sebagai gantinya
 * muncul satu kalimat yang menjelaskan mengapa. Tombol yang pasti ditolak
 * lebih baik tidak ada - dan Admin tetap bisa membaca serta mengunduh
 * berkasnya.
 *
 * ---------------------------------------------------------------------
 * Draf tersimpan otomatis - tidak ada tombol simpan
 * ---------------------------------------------------------------------
 * Setiap unggahan dan setiap tanda tangan menulis langsung. Yang bisa hilang
 * saat pengawas menutup laman adalah pekerjaan yang tidak diberi tombol,
 * jadi tombolnya memang tidak ada. Kotak centang dan catatan punya satu
 * tombol tersendiri karena mereka tidak punya peristiwa yang bisa
 * memicunya - foto punya tombol unggah, tanda tangan punya tombol simpan di
 * kanvas.
 *
 * ---------------------------------------------------------------------
 * Lokasi foto: `rooms.location`, BUKAN geolokasi peramban
 * ---------------------------------------------------------------------
 * Plate 18 meminta "waktu pengambilan dan lokasi", sementara PRD §12.4
 * menetapkan MVP tanpa GPS tracking. Yang dipakai adalah nama lokasi ruang
 * yang memang sudah ada - tidak menuntut izin lokasi di hari-H, dan tidak
 * melanggar batas MVP. Perbedaan ini disengaja dan tidak boleh "diperbaiki"
 * menjadi gelokasi tanpa mengubah PRD-nya lebih dulu.
 */
type Foto = {
    sisi: string;
    label: string;
    petunjuk: string;
    ada: boolean;
    url: string | null;
    diambil_pada: string | null;
    diunggah_pada: string | null;
    ukuran: string | null;
    nama_asli: string | null;
    terlambat: boolean;
};

type Ttd = {
    peran: string;
    label: string;
    wajib: boolean;
    ada: boolean;
    nama: string | null;
    oleh: string | null;
    jam: string | null;
    url: string | null;
};

type Props = {
    ruang: {
        id: number;
        kode: string;
        nama: string | null;
        lokasi: string | null;
        sesi: string | null;
        ujian: string | null;
    };
    pelaku: string;
    boleh_ttd: boolean;
    angka: {
        peserta: number;
        hadir: number;
        terlambat: number;
        tidak_hadir: number;
        insiden: number;
    };
    laporan: {
        id: number;
        status: string;
        final: boolean;
        catatan: string | null;
        pernyataan_presensi: boolean;
        pernyataan_keluar: boolean;
        dikirim_pada: string | null;
        pelaku_label: string | null;
        kurang: string[];
        boleh_final: boolean;
    };
    foto: Foto[];
    tanda_tangan: Ttd[];
};

export default function BeritaAcara({
    ruang,
    pelaku,
    boleh_ttd,
    angka,
    laporan,
    foto,
    tanda_tangan,
}: Props) {
    const [catatan, setCatatan] = useState(laporan.catatan ?? '');
    const [pernyataanPresensi, setPernyataanPresensi] = useState(
        laporan.pernyataan_presensi,
    );
    const [pernyataanKeluar, setPernyataanKeluar] = useState(laporan.pernyataan_keluar);
    const [kirim, setKirim] = useState(false);

    // Dua rujukan berkas, satu per sisi. Dipisah karena `capture` berbeda:
    // "Ambil foto" membuka kamera, "Pilih dari galeri" membuka galeri -
    // dan pada peramban ponsel keduanya memang dua jalur yang berbeda.
    const kamera = useRef<Record<string, HTMLInputElement | null>>({});
    const galeri = useRef<Record<string, HTMLInputElement | null>>({});

    const unggah = (sisi: string, berkas: File | null) => {
        if (berkas === null) {
            return;
        }

        setKirim(true);

        const form = new FormData();
        form.append('sisi', sisi);
        form.append('foto', berkas);
        // Waktu modifikasi berkas, bukan waktu unggah. Selisih keduanya yang
        // membuat "foto yang dipilih dari galeri setelah ujian" tetap
        // tercatat kapan diunggah dan tidak dapat menyamar sebagai bukti
        // saat kejadian.
        form.append('diambil_pada', String(Math.floor(berkas.lastModified / 1000)));

        router.post(`/pengawas/ruang/${ruang.id}/berita-acara/foto`, form, {
            preserveScroll: true,
            forceFormData: true,
            onFinish: () => setKirim(false),
        });
    };

    const simpanCatatan = () => {
        setKirim(true);
        router.post(
            `/pengawas/ruang/${ruang.id}/berita-acara/catatan`,
            {
                catatan,
                pernyataan_presensi: pernyataanPresensi,
                pernyataan_keluar: pernyataanKeluar,
            },
            { preserveScroll: true, onFinish: () => setKirim(false) },
        );
    };

    const kirimFinal = () => {
        setKirim(true);
        router.post(
            `/pengawas/ruang/${ruang.id}/berita-acara/kirim`,
            {},
            { preserveScroll: true, onFinish: () => setKirim(false) },
        );
    };

    const bukaKembali = () => {
        setKirim(true);
        router.post(
            `/pengawas/ruang/${ruang.id}/berita-acara/buka`,
            {},
            { preserveScroll: true, onFinish: () => setKirim(false) },
        );
    };

    const simpanTtd = (data: {
        peran: string;
        nama: string;
        tanda_tangan: string;
    }) => {
        setKirim(true);
        router.post(`/pengawas/ruang/${ruang.id}/berita-acara/ttd`, data, {
            preserveScroll: true,
            onFinish: () => setKirim(false),
        });
    };

    return (
        <KerangkaPonsel
            judul="Berita acara"
            tabbar={{ ruangId: ruang.id, aktif: 'berita-acara' }}
            kiri={
                <>
                    <Chip nada="info">{ruang.kode}</Chip>
                    <span className="min-w-0 truncate text-[11px] text-ink-3">
                        Berita acara &middot; {[ruang.sesi, ruang.ujian].filter(Boolean).join(' · ')}
                    </span>
                </>
            }
            kanan={
                <Chip nada={laporan.final ? 'ok' : 'warn'}>
                    {laporan.final ? 'terkirim' : 'belum lengkap'}
                </Chip>
            }
        >
            <div className="grid grid-cols-4 gap-1">
                <KpiPonsel label="Hadir" nilai={angka.hadir} nada="ok" />
                <KpiPonsel label="Telat" nilai={angka.terlambat} nada="info" />
                <KpiPonsel
                    label="Alpa"
                    nilai={angka.tidak_hadir}
                    nada={angka.tidak_hadir > 0 ? 'warn' : undefined}
                />
                <KpiPonsel
                    label="Insiden"
                    nilai={angka.insiden}
                    nada={angka.insiden > 0 ? 'warn' : undefined}
                />
            </div>

            {laporan.final && (
                <Inbox>
                    Berita acara ini sudah dikirim {laporan.dikirim_pada} oleh{' '}
                    {laporan.pelaku_label ?? '—'}. Ia masih bisa dibuka kembali sebagai
                    draf bila ada yang perlu diperbaiki.
                </Inbox>
            )}

            <KotakPonsel
                solid
                padat
                judul="Foto keadaan ruang"
                kanan={
                    <Chip nada={foto.every((f) => f.ada) ? 'ok' : 'warn'}>
                        {foto.filter((f) => f.ada).length > 0 ? 'ada' : 'belum ada'}
                    </Chip>
                }
            >
                {foto.map((f) => (
                    <PhotoPonsel
                        key={f.sisi}
                        label={f.label}
                        petunjuk={f.petunjuk}
                        ada={f.ada}
                        url={f.url}
                        terlambat={f.terlambat}
                        namaBerkas={f.nama_asli}
                        meta={[
                            f.diambil_pada ? `diambil ${f.diambil_pada}` : null,
                            f.diunggah_pada ? `diunggah ${f.diunggah_pada}` : null,
                            f.ukuran,
                            ruang.lokasi,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                    >
                        {!laporan.final && (
                            <>
                                {/* Dua tombol, dua berkas input. `capture`
                                    membuat ponsel membuka kameranya langsung;
                                    tanpa itu, peramban menawarkan pilihan -
                                    dan "Pilih dari galeri" justru yang
                                    dibutuhkan saat fotonya sudah diambil
                                    sebelumnya. */}
                                <input
                                    ref={(el) => {
                                        kamera.current[f.sisi] = el;
                                    }}
                                    type="file"
                                    accept="image/*"
                                    capture="environment"
                                    className="hidden"
                                    onChange={(e) => unggah(f.sisi, e.target.files?.[0] ?? null)}
                                />
                                <input
                                    ref={(el) => {
                                        galeri.current[f.sisi] = el;
                                    }}
                                    type="file"
                                    accept="image/*"
                                    className="hidden"
                                    onChange={(e) => unggah(f.sisi, e.target.files?.[0] ?? null)}
                                />

                                <button
                                    type="button"
                                    disabled={kirim}
                                    onClick={() => kamera.current[f.sisi]?.click()}
                                    className="rounded-wf border border-accent bg-accent px-3 py-1.5 text-xs font-medium text-on-accent disabled:opacity-50"
                                >
                                    {f.ada ? 'Ganti foto' : 'Ambil foto'}
                                </button>
                                <button
                                    type="button"
                                    disabled={kirim}
                                    onClick={() => galeri.current[f.sisi]?.click()}
                                    className="rounded-wf border border-line bg-surface px-3 py-1.5 text-xs text-ink disabled:opacity-50"
                                >
                                    Pilih dari galeri
                                </button>
                            </>
                        )}
                    </PhotoPonsel>
                ))}

                <p className="text-[11px] text-ink-3">
                    Waktu pengambilan dan lokasi ruang ikut tersimpan bersama berkasnya,
                    bukan hanya gambarnya.
                </p>
            </KotakPonsel>

            <KotakPonsel
                solid
                padat
                judul="Catatan berita acara"
                kanan={<Chip nada="mute">opsional</Chip>}
            >
                <textarea
                    value={catatan}
                    onChange={(e) => setCatatan(e.target.value)}
                    rows={4}
                    placeholder="Kejadian selama sesi…"
                    disabled={laporan.final}
                    className="w-full rounded-wf border border-line bg-surface px-2 py-1.5 text-xs text-ink outline-none focus:border-accent disabled:bg-surface-2 disabled:text-ink-3"
                />

                {/* Dua pernyataan, dan keduanya harus DILIHAT pengawas -
                    bukan muncul setelah ia mengingatnya sendiri. Yang
                    pertama tentang presensi, yang kedua tentang pintu ruang;
                    keduanya adalah hal yang tidak bisa dibuktikan foto. */}
                {[
                    {
                        nilai: pernyataanPresensi,
                        ubah: setPernyataanPresensi,
                        teks: 'Saya menyatakan data presensi ruang ini benar',
                    },
                    {
                        nilai: pernyataanKeluar,
                        ubah: setPernyataanKeluar,
                        teks: 'Tidak ada peserta yang saya izinkan keluar ruang',
                    },
                ].map((c) => (
                    <label key={c.teks} className="flex items-start gap-2">
                        <input
                            type="checkbox"
                            checked={c.nilai}
                            disabled={laporan.final}
                            onChange={(e) => c.ubah(e.target.checked)}
                            className="mt-0.5 size-4 shrink-0 accent-[var(--color-accent)]"
                        />
                        <span className="text-xs leading-relaxed text-ink-2">{c.teks}</span>
                    </label>
                ))}

                {!laporan.final && (
                    <button
                        type="button"
                        onClick={simpanCatatan}
                        disabled={kirim}
                        className="rounded-wf border border-line bg-surface px-3 py-1.5 text-xs text-ink disabled:opacity-50"
                    >
                        Simpan catatan
                    </button>
                )}
            </KotakPonsel>

            <KotakPonsel
                solid
                padat
                judul="Tanda tangan"
                kanan={
                    <Chip nada={tanda_tangan.some((t) => t.ada) ? 'ok' : 'warn'}>
                        {tanda_tangan.some((t) => t.ada) ? 'ditandatangani' : 'wajib'}
                    </Chip>
                }
            >
                {tanda_tangan.map((t) => (
                    <TtdPonsel
                        key={t.peran}
                        peran={t.peran}
                        label={t.label}
                        wajib={t.wajib}
                        ada={t.ada}
                        url={t.url}
                        nama={t.nama}
                        oleh={t.oleh}
                        jam={t.jam}
                        bolehTtd={boleh_ttd && !laporan.final}
                        kirim={simpanTtd}
                        sedangKirim={kirim}
                    />
                ))}

                <p className="text-[11px] text-ink-3">
                    Tanda tangan melekat pada peran dan sesi, bukan pada orang: bila ruang
                    ini digantikan pengawas lain pada sesi berikutnya, tanda tangan yang
                    lama tidak ikut berpindah.
                </p>
            </KotakPonsel>

            {/* Daftar kekurangan datang dari `SessionReport::kekuranganFinal()`
                di server, bukan disusun ulang di sini. Kalau aturannya hidup
                di dua tempat, suatu hari keduanya akan berbeda - dan yang
                berbeda akan berupa laporan yang dikirim padahal kurang. */}
            {!laporan.final && (
                <Peringatan>
                    {laporan.kurang.length > 0
                        ? `Berita acara belum boleh dikirim: ${laporan.kurang.join(', ')}. Yang sudah tersimpan tetap utuh - draf ini tersimpan otomatis, jadi Anda boleh menutupnya dan kembali lagi.`
                        : 'Berita acara sudah lengkap dan siap dikirim. Yang sudah tersimpan tetap utuh bila Anda menutupnya sekarang.'}
                </Peringatan>
            )}

            <div className="flex flex-wrap gap-1.5 pb-1">
                {laporan.final ? (
                    <button
                        type="button"
                        onClick={bukaKembali}
                        disabled={kirim}
                        className="rounded-wf border border-line bg-surface px-3 py-2 text-xs text-ink disabled:opacity-50"
                    >
                        Buka kembali sebagai draf
                    </button>
                ) : (
                    <button
                        type="button"
                        onClick={kirimFinal}
                        disabled={kirim || !laporan.boleh_final}
                        className="rounded-wf border border-accent bg-accent px-4 py-2 text-sm font-medium text-on-accent disabled:cursor-not-allowed disabled:border-ink-3/40 disabled:bg-ink-3/40"
                    >
                        Kirim berita acara
                    </button>
                )}
            </div>

            {!boleh_ttd && (
                <Inbox>
                    Admin dapat membaca, mencetak, dan mengunduh berita acara mana pun di
                    sekolahnya - tetapi tidak menandatanganinya, karena tanda tangan atas
                    ruang yang tidak pernah dimasuki adalah keterangan palsu.
                </Inbox>
            )}

            {pelaku === 'tautan' && (
                <p className="px-1 pb-1 text-center text-[11px] text-ink-3">
                    Anda masuk lewat tautan ruang {ruang.kode}. Berkas foto dan tanda
                    tangan di laman ini hanya dapat dibuka selama tautannya masih berlaku.
                </p>
            )}
        </KerangkaPonsel>
    );
}

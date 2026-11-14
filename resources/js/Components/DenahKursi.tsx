/**
 * Denah kursi - geometri dari server, dipetakan ke meja.
 *
 * =====================================================================
 * Dipindahkan ke sini 2026-11-14, dan itu bukan pemindahan kosong
 * =====================================================================
 * Komponen ini lahir di `Siswa/Akun.tsx`. Beberapa jam kemudian beranda
 * siswa membutuhkan panel yang sama untuk kartu utama - dan dua salinan
 * denah adalah dua salinan yang suatu hari berbeda, sementara yang
 * menemukan bedanya adalah anak yang berdiri di depan pintu ruang.
 *
 * @see \App\Domain\Attendance\Services\TempatUjianSiswa bentuk `tempat`
 *
 * =====================================================================
 * Mengapa TIDAK menghitung meja sendiri
 * =====================================================================
 * `NomorKursi::KURSI_PER_MEJA = 2` dan `MEJA_PER_BARIS = 4`, dan angka itu
 * hidup di server karena dipakai juga oleh denah Admin, tablo PDF, dan
 * papan pengawas. Laman yang membaginya sendiri akan berbeda dari kertas
 * begitu salah satunya berubah.
 *
 * Karena itu yang datang adalah `sel[]` lengkap dengan `meja`, `baris`, dan
 * `kolom`; di sini ia hanya DIKELOMPOKKAN, bukan dihitung ulang.
 */

export type SelDenah = {
    kursi: number;
    meja: number;
    baris: number;
    kolom: number;
    terisi: boolean;
    saya: boolean;
};

/** Bentuk `tempat_ujian` / `tempat` yang dikirim `TempatUjianSiswa`. */
export type TempatUjian = {
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
        sel: SelDenah[];
    };
};

export function DenahKursi({ tempat }: { tempat: TempatUjian }) {
    const { denah } = tempat;

    // Kelompokkan per MEJA: kursi 1 dan 2 di meja 1, dst.
    const meja = new Map<number, SelDenah[]>();
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

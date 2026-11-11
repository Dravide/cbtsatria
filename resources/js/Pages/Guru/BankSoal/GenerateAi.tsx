import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Field, Modal, Peringatan, Tombol, kelasInput } from '@/Components/ui';
import { PratinjauSoalAi, SoalAi } from '@/Components/PratinjauAi';

/**
 * Generate soal dengan AI - dipindahkan dari builder ke DAFTAR bank soal
 * (2026-11-10).
 *
 * =====================================================================
 * Mengapa di daftar, bukan di builder
 * =====================================================================
 * Hasil AI bukan lagi "mengisi form satu soal": ia PRATINJAU berisi banyak
 * soal, dan yang dicentang tersimpan langsung ke bank soal. Menempatkannya
 * di builder berarti Guru harus membuka satu form kosong lebih dulu hanya
 * untuk meminta soal yang tidak akan pernah mengisi form itu - dan setelah
 * sepuluh soal tersimpan, ia tetap berada di builder kosong yang sama.
 *
 * Dari daftar, alurnya lurus: minta, centang, simpan, dan daftar di bawah
 * langsung memuat soal baru.
 *
 * =====================================================================
 * Yang dikirim balik peramban adalah soal yang SAMA
 * =====================================================================
 * Tidak ada pencocokan ulang ke payload builder: `POST
 * /guru/bank-soal/ai/simpan` menerima bentuk ringan yang juga dipakai
 * pratinjau, dan `PenulisSoalAi::bangun()` memetakan lalu memvalidasinya
 * dengan `SkemaJenisSoal` - aturan yang sama yang memvalidasi keluaran model.
 */

export type Mapel = { id: number; nama: string };
export type JenisAi = { nilai: string; label: string; pendek: string };

/** Satu baris pratinjau: soalnya, centangnya, dan tingkat yang DIMINTA. */
type Baris = { soal: SoalAi; dicentang: boolean; tingkat: string };

export function GenerateAi({
    mapel,
    jenisAi,
    terbuka,
    padaTutup,
}: {
    mapel: Mapel[];
    jenisAi: JenisAi[];
    /** Modal dikendalikan laman induk: tombolnya ada di bilah aksi tabel. */
    terbuka: boolean;
    padaTutup: () => void;
}) {
    const [mapelId, setMapelId] = useState(String(mapel[0]?.id ?? ''));
    const [topik, setTopik] = useState('');
    const [tingkat, setTingkat] = useState('sedang');
    const [jumlah, setJumlah] = useState('5');
    // Banyak jenis sekaligus - mode lanjut. Satu jenis pun tetap sah; yang
    // dikirim selalu larik supaya server hanya punya satu bentuk untuk diurus.
    const [jenisPilih, setJenisPilih] = useState<string[]>(() =>
        jenisAi[0] ? [jenisAi[0].nilai] : [],
    );
    const [menyusun, setMenyusun] = useState(false);
    const [menyimpan, setMenyimpan] = useState(false);
    const [galat, setGalat] = useState<string | null>(null);
    const [baris, setBaris] = useState<Baris[]>([]);

    const idMapel = ((): number | null => {
        const angka = Number.parseInt(mapelId, 10);

        return Number.isFinite(angka) && angka > 0 ? angka : null;
    })();

    const tercentang = baris.filter((b) => b.dicentang).length;

    const ubahCentang = (i: number, nilai: boolean) =>
        setBaris((kini) => kini.map((b, j) => (j === i ? { ...b, dicentang: nilai } : b)));

    const centangSemua = (nilai: boolean) =>
        setBaris((kini) => kini.map((b) => ({ ...b, dicentang: nilai })));

    /**
     * Kirim permintaan susun ke server, dengan token CSRF dari cookie.
     *
     * Polanya sama dengan `Siswa/Kerjakan.tsx`: `fetch` dan bukan Inertia,
     * karena jawabannya JSON untuk ditampilkan, bukan laman untuk dituju.
     */
    const susun = async () => {
        setMenyusun(true);
        setGalat(null);

        try {
            const cocok = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

            const jawab = await fetch('/guru/bank-soal/ai', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': cocok ? decodeURIComponent(cocok[1]) : '',
                },
                body: JSON.stringify({
                    subject_id: idMapel,
                    jenis: jenisPilih,
                    topik,
                    tingkat,
                    jumlah: Number.parseInt(jumlah, 10) || 1,
                }),
            });

            // 419 = sesi CSRF kedaluwarsa, yang berarti laman ini sudah
            // terlalu lama terbuka. Muat ulang memulihkan tokennya.
            if (jawab.status === 419) {
                window.location.reload();

                return;
            }

            const isi = (await jawab.json().catch(() => ({}))) as Partial<SoalAi> & {
                soal?: SoalAi[];
                pesan?: string;
                message?: string;
                errors?: Record<string, string[]>;
            };

            if (!jawab.ok) {
                const pertama = isi.errors ? Object.values(isi.errors)[0]?.[0] : undefined;

                setGalat(
                    pertama ??
                        isi.pesan ??
                        isi.message ??
                        'Gagal menyusun soal. Coba lagi, atau susun soalnya secara manual.',
                );

                return;
            }

            // Dua bentuk, dan keduanya sah: satu soal datang datar, beberapa
            // soal datang sebagai `{soal:[...]}`.
            const daftar: SoalAi[] = Array.isArray(isi.soal)
                ? isi.soal
                : typeof isi.pertanyaan === 'string'
                  ? [isi as SoalAi]
                  : [];

            if (daftar.length === 0) {
                setGalat('Penyedia AI menjawab, tetapi tidak ada soal yang bisa dibaca. Coba lagi.');

                return;
            }

            // DITAMBAHKAN, bukan diganti: permintaan kedua saat daftar masih
            // berisi hasil pertama sudah ditagih dua kali, dan membuang yang
            // pertama berarti Guru membayar soal yang tidak pernah ia lihat.
            setBaris((kini) => [
                ...kini,
                ...daftar.map((soal) => ({ soal, dicentang: true, tingkat })),
            ]);

            // Modal ditutup: hasilnya kini tampil di laman, dan modal yang
            // menutupinya hanya membuat Guru mencentang tanpa membaca.
            padaTutup();
            setTopik('');
        } catch {
            setGalat('Tidak bisa menghubungi server. Periksa sambungan internet, lalu coba lagi.');
        } finally {
            setMenyusun(false);
        }
    };

    /**
     * Simpan yang dicentang - satu kiriman, satu transaksi di server.
     *
     * `router.post` supaya jawabannya redirect Inertia yang memuat ulang
     * daftar di bawah, lengkap dengan pesan sukses berisi kode soal.
     */
    const simpan = () => {
        const dipilih = baris.filter((b) => b.dicentang);

        if (dipilih.length === 0 || idMapel === null) {
            return;
        }

        setMenyimpan(true);
        setGalat(null);

        router.post(
            '/guru/bank-soal/ai/simpan',
            {
                subject_id: idMapel,
                // Tingkat per baris ikut DI DALAM soalnya: satu pratinjau bisa
                // memuat hasil dua permintaan dengan tingkat berbeda.
                soal: dipilih.map((b) => ({ ...b.soal, tingkat: b.tingkat })),
            },
            {
                preserveScroll: true,
                onSuccess: () => setBaris([]),
                onError: (e) =>
                    setGalat(
                        (e as Record<string, string>).soal ??
                            'Soal tidak bisa disimpan. Periksa kembali, lalu coba lagi.',
                    ),
                onFinish: () => setMenyimpan(false),
            },
        );
    };

    // Sekolah yang belum mengatur AI tidak melihat panelnya sama sekali.
    if (jenisAi.length === 0) {
        return null;
    }

    return (
        <>
            {/* Pratinjau DI ATAS tabel, bukan di dalam modal: Guru harus bisa
                membandingkan sepuluh soal sekaligus sebelum mencentang. */}
            {baris.length > 0 && (
                <div className="mb-3 rounded-wf border border-accent/30 bg-accent-soft/20">
                    <div className="flex flex-wrap items-center gap-2 border-b border-accent/25 px-3 py-2">
                        <span className="text-xs font-medium text-ink">
                            Hasil AI &mdash; {baris.length} soal, {tercentang} dicentang
                        </span>
                        <button
                            type="button"
                            onClick={() => centangSemua(tercentang !== baris.length)}
                            className="text-[11px] text-accent underline underline-offset-2 hover:no-underline"
                        >
                            {tercentang === baris.length ? 'Hapus centang semua' : 'Centang semua'}
                        </button>
                        <button
                            type="button"
                            onClick={() => setBaris([])}
                            disabled={menyimpan}
                            className="text-[11px] text-ink-3 underline underline-offset-2 hover:no-underline disabled:opacity-50"
                        >
                            Buang semua
                        </button>
                        <span className="ml-auto">
                            <Tombol
                                nada="pri"
                                onClick={simpan}
                                disabled={menyimpan || tercentang === 0}
                            >
                                {menyimpan ? 'Menyimpan...' : `Simpan ${tercentang} soal ke bank`}
                            </Tombol>
                        </span>
                    </div>

                    {galat && (
                        <div className="border-b border-accent/25 px-3 py-2">
                            <Peringatan>{galat}</Peringatan>
                        </div>
                    )}

                    <div className="max-h-[60vh] space-y-2 overflow-y-auto p-2">
                        {baris.map((b, i) => (
                            <PratinjauSoalAi
                                key={i}
                                soal={b.soal}
                                nomor={i + 1}
                                dicentang={b.dicentang}
                                padaCentang={(nilai) => ubahCentang(i, nilai)}
                                jenis={jenisAi}
                            />
                        ))}
                    </div>

                    <p className="border-t border-accent/25 px-3 py-2 text-[11px] leading-relaxed text-ink-3">
                        Yang dicentang tersimpan apa adanya sebagai draf. Yang tidak dicentang
                        hilang saat laman ini ditutup &mdash; periksa dulu, lalu centang.
                    </p>
                </div>
            )}

            <Modal
                judul="Generate soal dengan AI"
                terbuka={terbuka}
                // Selama menunggu, modal TIDAK bisa ditutup: permintaan sudah
                // terkirim dan sudah ditagih, jadi menutupnya hanya
                // menyembunyikan hasil yang tetap dibayar.
                padaTutup={() => {
                    if (!menyusun) {
                        padaTutup();
                    }
                }}
            >
                <div className="space-y-3">
                    <Field label="Mata pelajaran">
                        <select
                            value={mapelId}
                            onChange={(e) => setMapelId(e.target.value)}
                            disabled={menyusun}
                            className={kelasInput}
                        >
                            {mapel.map((m) => (
                                <option key={m.id} value={m.id}>
                                    {m.nama}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <Field
                        label="Jenis soal"
                        petunjuk="Boleh lebih dari satu. Jumlah soal dibagi rata antar jenis."
                    >
                        <div className="flex flex-wrap gap-1.5">
                            {jenisAi.map((j) => {
                                const aktif = jenisPilih.includes(j.nilai);

                                return (
                                    <button
                                        key={j.nilai}
                                        type="button"
                                        disabled={menyusun}
                                        aria-pressed={aktif}
                                        onClick={() =>
                                            setJenisPilih((kini) =>
                                                aktif
                                                    ? kini.filter((x) => x !== j.nilai)
                                                    : [...kini, j.nilai],
                                            )
                                        }
                                        className={
                                            'rounded-wf border px-2.5 py-1 text-xs transition-colors disabled:cursor-not-allowed ' +
                                            (aktif
                                                ? 'border-accent bg-accent-soft text-accent'
                                                : 'border-line bg-surface text-ink-2 hover:bg-surface-2')
                                        }
                                    >
                                        {j.label}
                                    </button>
                                );
                            })}
                        </div>
                    </Field>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field label="Tingkat">
                            <select
                                value={tingkat}
                                onChange={(e) => setTingkat(e.target.value)}
                                disabled={menyusun}
                                className={kelasInput}
                            >
                                <option value="mudah">Mudah</option>
                                <option value="sedang">Sedang</option>
                                <option value="sulit">Sulit</option>
                            </select>
                        </Field>

                        <Field label="Jumlah soal" petunjuk="Total seluruh jenis.">
                            <select
                                value={jumlah}
                                onChange={(e) => setJumlah(e.target.value)}
                                disabled={menyusun}
                                className={kelasInput}
                            >
                                {Array.from({ length: 10 }, (_, i) => String(i + 1)).map((n) => (
                                    <option key={n} value={n}>
                                        {n}
                                    </option>
                                ))}
                            </select>
                        </Field>
                    </div>

                    <Field label="Topik" petunjuk="Semakin spesifik, semakin baik soalnya.">
                        <textarea
                            value={topik}
                            onChange={(e) => setTopik(e.target.value)}
                            disabled={menyusun}
                            rows={3}
                            placeholder="mis. Operasi himpunan, irisan dan gabungan, dua himpunan"
                            className={kelasInput}
                        />
                    </Field>

                    {galat && <Peringatan>{galat}</Peringatan>}

                    {menyusun && (
                        <p className="text-[11px] leading-relaxed text-ink-3">
                            Menyusun soal… Semakin banyak soal dan jenis, semakin lama. Jangan
                            tutup laman ini.
                        </p>
                    )}

                    <div className="flex items-center gap-2">
                        <Tombol
                            nada="pri"
                            onClick={susun}
                            disabled={
                                menyusun ||
                                topik.trim().length < 5 ||
                                jenisPilih.length === 0 ||
                                idMapel === null
                            }
                        >
                            {menyusun ? 'Menyusun soal...' : `Buat ${jumlah} soal`}
                        </Tombol>
                        <Tombol onClick={() => padaTutup()} disabled={menyusun}>
                            Batal
                        </Tombol>
                    </div>

                    <p className="text-[11px] leading-relaxed text-ink-3">
                        Hasilnya tampil sebagai pratinjau &mdash; tidak ada yang tersimpan sampai
                        Anda mencentang dan menekan Simpan. Setiap permintaan ditagih ke akun
                        penyedia sekolah.
                    </p>
                </div>
            </Modal>
        </>
    );
}

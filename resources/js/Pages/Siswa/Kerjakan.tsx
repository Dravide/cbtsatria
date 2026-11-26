import { router } from '@inertiajs/react';
import QRCode from 'qrcode';
import { useCallback, useEffect, useRef, useState } from 'react';
import KerangkaSiswa from '@/Layouts/KerangkaSiswa';
import { Chip, Inbox, Modal } from '@/Components/ui';
import { KotakPonsel } from '@/Components/ponsel';
import { Html } from '@/Components/PratinjauButir';
import { renderRumusDalamHtml } from '@/lib/matematika';
import * as layar from '@/Components/layar';

/**
 * Laman pengerjaan soal siswa. Kunci, rubrik, dan pembahasan tidak pernah dikirim ke klien (`ExamQuestion::untukSiswa()`).
 * Autosave, denyut, dan laporan pelanggaran memakai `fetch` (dengan cookie `XSRF-TOKEN`) agar tidak memicu navigasi Inertia.
 */

/** Satu blok `content_json` berbentuk `{teks: html}`. */
type Blok = { teks?: string } | null;

type OpsiSnapshot = { kunci: string; teks: Blok; media?: MediaButir[] | null };
type ButirSnapshot = {
    kunci: string;
    grup?: string | null;
    teks: Blok;
    pasangan?: string | null;
    posisi?: number | null;
};

type MediaButir = {
    id: number;
    tipe: string;
    mime: string | null;
    nama: string | null;
    ukuran: number | null;
    url: string;
    gambar: boolean;
};

type Butir = {
    id: number;
    nomor: number;
    jenis: string;
    jenis_label: string;
    judul: string | null;
    /** `prompt_json` - `{teks: html}`. */
    soal: Blok;
    opsi: OpsiSnapshot[] | null;
    butir: ButirSnapshot[] | null;
    skor: Record<string, unknown> | null;
    media: MediaButir[] | null;
    bobot: number;
    urutan_opsi: string[] | null;
    urutan_butir: string[] | null;
    jawaban: { isi: Record<string, unknown> | null; versi: number; ditandai: boolean } | null;
    rusak: boolean;
};

type Progres = {
    total: number;
    terjawab: number;
    kosong: number;
    ditandai: number;
    ringkas: string;
};

type Props = {
    peserta: {
        peserta_id: number;
        ujian: string | null;
        mapel: string | null;
        ruang: string | null;
        kursi: number | null;
    };
    attempt: {
        id: number;
        nomor: number;
        status: string;
        sisa_detik: number;
        deadline: string | null;
        boleh_mundur: boolean;
    };
    butir: Butir[];
    progres: Progres;
    simpan_tiap_detik: number;
    /** Event mewajibkan layar penuh (`SchoolEvent::enforce_fullscreen`); ambang pelanggaran tetap di server. */
    wajib_layar_penuh: boolean;
    /** Identitas siswa untuk tanda air karena peramban tidak bisa memblokir tangkapan layar. */
    tanda_air: { nama: string; nomor_induk: string };
    qr_secret?: string;
    /** Penjaga layar dari pengaturan event (`detect_split_screen`, `detect_float_window`, `show_watermark`). */
    deteksi_layar_terbagi: boolean;
    deteksi_jendela_mengambang: boolean;
    tanda_air_aktif: boolean;
};

/** Selang denyut ke server dalam milidetik. */
const SELANG_DENYUT = 30_000;

/** Salinan `SkemaJenisSoal::BATAS_TEKS` untuk penghitung karakter; batas akhir tetap divalidasi server. */
const BATAS_URAIAN = 20_000;

export default function Kerjakan({
    peserta,
    attempt,
    butir,
    progres,
    simpan_tiap_detik,
    wajib_layar_penuh,
    tanda_air,
    deteksi_layar_terbagi,
    deteksi_jendela_mengambang,
    tanda_air_aktif,
    qr_secret,
}: Props) {
    const [indeks, setIndeks] = useState(0);
    const [kodeDarurat, setKodeDarurat] = useState<string | null>(null);
    const [qrDaruratImg, setQrDaruratImg] = useState<string | null>(null);
    const [qrTerlaluBesar, setQrTerlaluBesar] = useState(false);
    const durasiButirRef = useRef<Record<number, number>>({});
    const [sisa, setSisa] = useState(attempt.sisa_detik);
    const [ringkas, setRingkas] = useState(progres);
    const [tersimpan, setTersimpan] = useState<'diam' | 'proses' | 'ok' | 'galat'>('diam');
    const [online, setOnline] = useState(() =>
        typeof navigator !== 'undefined' ? navigator.onLine : true,
    );

    const tertundaRef = useRef<Record<number, Record<string, unknown>>>(
        bacaTertunda(attempt.id),
    );
    const [jumlahTertunda, setJumlahTertunda] = useState(
        () => Object.keys(tertundaRef.current).length,
    );
    const sedangSyncRef = useRef(false);

    const [daftarTerbuka, setDaftarTerbuka] = useState(false);

    // Simpan semua jawaban di state induk agar bertahan saat pindah butir, digabung dengan cadangan offline localStorage.
    const [jawaban, setJawaban] = useState<Record<number, Record<string, unknown>>>(() => ({
        ...Object.fromEntries(
            butir.filter((b) => b.jawaban?.isi).map((b) => [b.id, b.jawaban?.isi ?? {}]),
        ),
        ...tertundaRef.current,
    }));

    const [ditandai, setDitandai] = useState<Record<number, boolean>>(() =>
        Object.fromEntries(butir.map((b) => [b.id, b.jawaban?.ditandai ?? false])),
    );

    // Versi optimistic concurrency per butir; disimpan di ref agar tidak memicu render saat mengetik.
    const versi = useRef<Record<number, number>>(
        Object.fromEntries(butir.map((b) => [b.id, b.jawaban?.versi ?? 0])),
    );

    // Ref jawaban terbaru agar interval autosave tidak di-reset pada setiap ketukan tombol.
    const jawabanRef = useRef(jawaban);
    jawabanRef.current = jawaban;

    // Peringatan pelanggaran tingkat 1; `sisa` dihitung server agar ambang tidak bocor ke klien.
    const [peringatan, setPeringatan] = useState<{ sisa: number | null } | null>(null);

    const mulaiPindahTab = useRef<number | null>(null);

    const aktif = butir[indeks];

    // Ref nomor soal yang sedang dilihat (`display_order`) agar interval denyut tidak di-reset saat berpindah soal.
    const nomorRef = useRef<number>(aktif?.nomor ?? 1);
    nomorRef.current = aktif?.nomor ?? indeks + 1;

    /** Kirim JSON ke endpoint siswa dengan token CSRF dari cookie `XSRF-TOKEN`. */
    const kirimJson = useCallback(async (jalur: string, isi: unknown): Promise<unknown> => {
        const cocok = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

        const tanggapan = await fetch(jalur, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': cocok ? decodeURIComponent(cocok[1]) : '',
            },
            body: JSON.stringify(isi),
        });

        // 419 menandakan sesi CSRF kedaluwarsa; muat ulang halaman untuk memulihkan token dan sisa waktu.
        if (tanggapan.status === 419) {
            window.location.reload();

            return null;
        }

        if (!tanggapan.ok) {
            throw new Error(String(tanggapan.status));
        }

        return tanggapan.json();
    }, []);

    /** Kirim laporan pelanggaran secara fire-and-forget agar tidak menghambat autosave. */
    const lapor = useCallback(
        (jenis: string, durasiMs?: number) => {
            kirimJson(`/siswa/ujian/${peserta.peserta_id}/lapor`, {
                jenis,
                durasi_ms: durasiMs ?? null,
            })
                .then((hasil) => {
                    if (!hasil) {
                        return;
                    }

                    setOnline(true);
                    if (Object.keys(tertundaRef.current).length > 0) {
                        void kirimTertunda();
                    }

                    // Keputusan `dikunci` dan `diperingatkan` berasal dari server (`TindakanPelanggaran`).
                    const t = hasil as {
                        dikunci?: boolean;
                        diperingatkan?: boolean;
                        sisa_menuju_kunci?: number | null;
                    };

                    if (t.dikunci) {
                        router.visit(`/siswa/ujian/${peserta.peserta_id}/terkunci`);

                        return;
                    }

                    if (t.diperingatkan) {
                        setPeringatan({ sisa: t.sisa_menuju_kunci ?? null });
                    }
                })
                .catch(() => {
                    // Sengaja diam agar kegagalan log pelanggaran tidak menahan autosave.
                });
        },
        [kirimJson, peserta.peserta_id],
    );

    const catatTertunda = useCallback(
        (butirId: number, isi: Record<string, unknown>) => {
            tertundaRef.current = { ...tertundaRef.current, [butirId]: isi };
            tulisTertunda(attempt.id, tertundaRef.current);
            setJumlahTertunda(Object.keys(tertundaRef.current).length);
        },
        [attempt.id],
    );

    const hapusTertunda = useCallback(
        (butirId: number) => {
            if (!(butirId in tertundaRef.current)) {
                return;
            }

            const salinan = { ...tertundaRef.current };
            delete salinan[butirId];
            tertundaRef.current = salinan;
            tulisTertunda(attempt.id, salinan);
            setJumlahTertunda(Object.keys(salinan).length);
        },
        [attempt.id],
    );

    /** Simpan satu butir; `senyap` menandakan autosave latar belakang. */
    const simpan = useCallback(
        async (butirId: number, isi: Record<string, unknown>, senyap: boolean): Promise<boolean> => {
            if (!senyap) {
                setTersimpan('proses');
            }

            try {
                const hasil = (await kirimJson(
                    `/siswa/ujian/${peserta.peserta_id}/butir/${butirId}`,
                    { isi, versi: versi.current[butirId] ?? 0 },
                )) as { versi: number; progres: Progres } | null;

                if (!hasil) {
                    return false;
                }

                versi.current[butirId] = hasil.versi;
                setRingkas(hasil.progres);
                hapusTertunda(butirId);
                setOnline(true);
                setTersimpan('ok');

                return true;
            } catch (galat) {
                // 409 (versi basi): muat ulang prop butir tanpa menimpa ketikan yang sedang aktif di layar.
                if (String(galat).includes('409')) {
                    router.reload({ only: ['butir', 'progres'] });
                }

                setTersimpan('galat');

                return false;
            }
        },
        [hapusTertunda, kirimJson, peserta.peserta_id],
    );

    /** Sinkronkan jawaban tertunda dari localStorage saat koneksi pulih. */
    const kirimTertunda = useCallback(async () => {
        if (sedangSyncRef.current) {
            return;
        }

        const entri = Object.entries(tertundaRef.current);

        if (entri.length === 0) {
            return;
        }

        sedangSyncRef.current = true;

        try {
            for (const [idStr, isi] of entri) {
                const berhasil = await simpan(Number(idStr), isi, true);
                if (!berhasil) {
                    break;
                }
            }
        } finally {
            sedangSyncRef.current = false;
        }
    }, [simpan]);

    useEffect(() => {
        const saatOnline = () => {
            setOnline(true);
            void kirimTertunda();
        };
        const saatOffline = () => setOnline(false);

        window.addEventListener('online', saatOnline);
        window.addEventListener('offline', saatOffline);

        if (navigator.onLine && Object.keys(tertundaRef.current).length > 0) {
            void kirimTertunda();
        }

        return () => {
            window.removeEventListener('online', saatOnline);
            window.removeEventListener('offline', saatOffline);
        };
    }, [kirimTertunda]);

    useEffect(() => {
        const aktifId = butir[indeks]?.id;
        if (!aktifId) {
            return;
        }
        const timer = window.setInterval(() => {
            durasiButirRef.current[aktifId] = (durasiButirRef.current[aktifId] ?? 0) + 1;
        }, 1000);
        return () => window.clearInterval(timer);
    }, [indeks, butir]);

    useEffect(() => {
        const perangkat = idPeramban();

        const kirimDenyut = () => {
            const delta = { ...durasiButirRef.current };
            durasiButirRef.current = {};

            kirimJson(`/siswa/ujian/${peserta.peserta_id}/denyut`, {
                perangkat,
                layar_penuh: document.fullscreenElement !== null,
                pelindung: true,
                nomor: nomorRef.current,
                durasi_butir: delta,
            })
                .then((hasil) => {
                    if (!hasil) {
                        return;
                    }

                    setOnline(true);
                    if (Object.keys(tertundaRef.current).length > 0) {
                        void kirimTertunda();
                    }

                    const h = hasil as {
                        terkunci?: boolean;
                        sisa_detik?: number;
                        status?: string;
                        progres?: Progres;
                    };

                    if (typeof h.sisa_detik === 'number') {
                        setSisa(h.sisa_detik);
                    }

                    if (h.progres) {
                        setRingkas(h.progres);
                    }

                    // Alihkan segera bila attempt sudah dikunci atau ditutup dari server/tab lain.
                    if (h.terkunci) {
                        router.visit(`/siswa/ujian/${peserta.peserta_id}/terkunci`);
                    } else if (h.status !== undefined && h.status !== 'berjalan') {
                        router.visit(`/siswa/ujian/${peserta.peserta_id}/selesai`);
                    }
                })
                .catch(() => {});
        };

        kirimDenyut();

        const jam = window.setInterval(kirimDenyut, SELANG_DENYUT);

        return () => window.clearInterval(jam);
    }, [kirimJson, peserta.peserta_id]);

    // State diperlukan agar React merender ulang spanduk saat event fullscreenchange terjadi.
    const [layarPenuh, setLayarPenuh] = useState(() => layar.sedang());

    useEffect(() => {
        // Lewati perangkat tanpa dukungan Fullscreen API agar siswa tidak langsung dilaporkan.
        if (!wajib_layar_penuh || !layar.dukung()) {
            return;
        }

        const saatBerubah = () => {
            const sekarang = layar.sedang();

            setLayarPenuh(sekarang);

            // Laporkan setiap kehilangan layar penuh; tangga pelanggaran dievaluasi di server.
            if (!sekarang) {
                lapor('keluar_layar_penuh');
            }
        };

        document.addEventListener('fullscreenchange', saatBerubah);

        return () => document.removeEventListener('fullscreenchange', saatBerubah);
    }, [wajib_layar_penuh, lapor]);

    useEffect(() => {
        // `layar.dukung()` tidak diperiksa di sini karena deteksi viewport sempit tetap berguna tanpa Fullscreen API.
        if (!wajib_layar_penuh || (!deteksi_layar_terbagi && !deteksi_jendela_mengambang)) {
            return;
        }

        const menyempit = () =>
            (deteksi_layar_terbagi && layar.lebar()) ||
            (deteksi_jendela_mengambang && layar.tinggi());

        // Debounce 4 detik untuk menyaring animasi rotasi layar dan resize sesaat.
        const PENAHAN_MS = 4_000;

        // Laporkan sekali per kejadian menyempit, bukan setiap event resize.
        let dilaporkan = false;
        let jam: number | null = null;

        const periksa = () => {
            if (jam !== null) {
                window.clearTimeout(jam);
                jam = null;
            }

            if (! menyempit()) {
                dilaporkan = false;

                return;
            }

            if (dilaporkan) {
                return;
            }

            jam = window.setTimeout(() => {
                jam = null;

                if (! menyempit()) {
                    return;
                }

                dilaporkan = true;

                // Kirim durasi 0 karena yang dicatat adalah awal kejadian.
                lapor('layar_terbagi', 0);
            }, PENAHAN_MS);
        };

        periksa();

        // Dengar orientationchange karena beberapa peramban ponsel memicu resize sebelum rotasi selesai.
        window.addEventListener('resize', periksa);
        window.addEventListener('orientationchange', periksa);

        return () => {
            if (jam !== null) {
                window.clearTimeout(jam);
            }

            window.removeEventListener('resize', periksa);
            window.removeEventListener('orientationchange', periksa);
        };
    }, [wajib_layar_penuh, deteksi_layar_terbagi, deteksi_jendela_mengambang, lapor]);

    const habis = sisa <= 0;

    useEffect(() => {
        if (habis) {
            return;
        }

        const jam = window.setInterval(() => setSisa((lama) => Math.max(0, lama - 1)), 1000);

        return () => window.clearInterval(jam);
    }, [habis]);

    // Penutupan attempt tetap diputuskan server; klien hanya mengarahkan ke halaman selesai saat hitungan habis.
    useEffect(() => {
        if (habis) {
            router.visit(`/siswa/ujian/${peserta.peserta_id}/selesai`);
        }
    }, [habis, peserta.peserta_id]);

    useEffect(() => {
        if (!aktif || aktif.rusak) {
            return;
        }

        const id = aktif.id;

        const jam = window.setInterval(() => {
            const isi = jawabanRef.current[id];

            if (isi !== undefined) {
                simpan(id, isi, true);
            }
        }, simpan_tiap_detik * 1000);

        return () => window.clearInterval(jam);
    }, [aktif, simpan, simpan_tiap_detik]);

    useEffect(() => {
        const saatSembunyi = () => {
            if (document.visibilityState === 'hidden') {
                mulaiPindahTab.current = Date.now();
            }
        };

        const saatTerlihat = () => {
            if (document.visibilityState !== 'visible' || mulaiPindahTab.current === null) {
                return;
            }

            const durasi = Date.now() - mulaiPindahTab.current;
            mulaiPindahTab.current = null;

            // Tetap kirim semua durasi pindah tab; penyaringan ambang 1,5 detik dilakukan server.
            lapor('pindah_tab', durasi);
        };

        const saatKontek = (e: MouseEvent) => {
            e.preventDefault();
            lapor('klik_kanan');
        };

        // Blokir copy/cut/paste dan laporkan ketiganya sebagai salin_tempel.
        const saatSalin = (e: ClipboardEvent) => {
            e.preventDefault();
            lapor('salin_tempel');
        };

        const saatPintasan = (e: KeyboardEvent) => {
            const tahan = e.ctrlKey || e.metaKey;

            const terlarang =
                e.key === 'F12' ||
                // PrintScreen hanya terdeteksi pada papan tik fisik; tombol tangkap layar ponsel tidak mengirim event.
                e.key === 'PrintScreen' ||
                (tahan && e.shiftKey && ['I', 'J', 'C'].includes(e.key.toUpperCase())) ||
                (tahan && ['U', 'P'].includes(e.key.toUpperCase()));

            if (terlarang) {
                e.preventDefault();
                lapor('pintasan_terlarang');
            }
        };

        // Tangkap jalur cetak di luar pintasan papan tik; konten cetak juga disembunyikan lewat @media print.
        const saatCetak = () => lapor('pintasan_terlarang');

        document.addEventListener('visibilitychange', saatSembunyi);
        document.addEventListener('visibilitychange', saatTerlihat);
        document.addEventListener('contextmenu', saatKontek);
        document.addEventListener('copy', saatSalin);
        document.addEventListener('cut', saatSalin);
        document.addEventListener('paste', saatSalin);
        document.addEventListener('keydown', saatPintasan);
        window.addEventListener('beforeprint', saatCetak);

        return () => {
            document.removeEventListener('visibilitychange', saatSembunyi);
            document.removeEventListener('visibilitychange', saatTerlihat);
            document.removeEventListener('contextmenu', saatKontek);
            document.removeEventListener('copy', saatSalin);
            document.removeEventListener('cut', saatSalin);
            document.removeEventListener('paste', saatSalin);
            document.removeEventListener('keydown', saatPintasan);
            window.removeEventListener('beforeprint', saatCetak);
        };
    }, [lapor]);

    const pindah = useCallback(
        (tujuan: number) => {
            if (tujuan < 0 || tujuan >= butir.length) {
                return;
            }

            if (tujuan < indeks && !attempt.boleh_mundur) {
                return;
            }

            // Simpan butir saat ini sebelum berpindah agar tidak menunggu interval autosave.
            const sekarang = butir[indeks];

            if (sekarang && !sekarang.rusak && jawabanRef.current[sekarang.id] !== undefined) {
                simpan(sekarang.id, jawabanRef.current[sekarang.id], true);
            }

            setIndeks(tujuan);
        },
        [attempt.boleh_mundur, butir, indeks, simpan],
    );

    const ragu = () => {
        if (!aktif) {
            return;
        }

        const baru = !ditandai[aktif.id];

        setDitandai((lama) => ({ ...lama, [aktif.id]: baru }));

        kirimJson(`/siswa/ujian/${peserta.peserta_id}/butir/${aktif.id}/tandai`, {
            ditandai: baru,
        })
            .then((hasil) => {
                const h = hasil as { progres?: Progres } | null;

                if (h?.progres) {
                    setRingkas(h.progres);
                }
            })
            .catch(() => {});
    };

    const setJawabanButir = (potongan: Record<string, unknown>) => {
        if (!aktif) {
            return;
        }

        const id = aktif.id;

        setJawaban((lama) => {
            const baru = { ...(lama[id] ?? {}), ...potongan };
            catatTertunda(id, baru);

            return { ...lama, [id]: baru };
        });
    };

    const terakhir = indeks === butir.length - 1;
    const idAktif = aktif?.id ?? 0;

    return (
        <KerangkaSiswa
            judul={`Soal ${aktif?.nomor ?? 1}`}
            gelap
            tanpaKeluar
            kiri={
                <span className="truncate text-[12px] text-ink-2">
                    Soal <b className="text-ink">{aktif?.nomor ?? '-'}</b> dari {butir.length}
                </span>
            }
            kanan={<Jam sisa={sisa} panas={sisa <= 300} />}
            aksiBawah={
                <div className="space-y-1.5">
                    <div className="flex items-center gap-2">
                        <PenandaSimpan keadaan={tersimpan} tertunda={jumlahTertunda} />

                        <button
                            type="button"
                            onClick={() => setDaftarTerbuka(true)}
                            className="ml-auto flex items-center gap-1.5 rounded-wf border border-line px-2 py-1 text-[11px] text-ink-2"
                        >
                            <span className="text-ink">{ringkas.ringkas}</span>
                            <span className="text-ink-3">Daftar</span>
                        </button>
                    </div>

                    <div className="grid grid-cols-3 gap-1.5">
                        <button
                            type="button"
                            onClick={() => pindah(indeks - 1)}
                            disabled={indeks === 0 || !attempt.boleh_mundur}
                            className={tombolBawah}
                        >
                            ‹ Mundur
                        </button>
                        <button
                            type="button"
                            onClick={ragu}
                            className={tombolBawah + (ditandai[idAktif] ? ' text-warn' : '')}
                        >
                            {ditandai[idAktif] ? 'Ragu ✓' : 'Ragu'}
                        </button>
                        {terakhir ? (
                            <button
                                type="button"
                                onClick={async () => {
                                    if (aktif && jawabanRef.current[aktif.id]) {
                                        await simpan(aktif.id, jawabanRef.current[aktif.id], false);
                                    }
                                    await kirimTertunda();
                                    router.visit(`/siswa/ujian/${peserta.peserta_id}/selesai`);
                                }}
                                className={tombolUtama}
                            >
                                Selesai
                            </button>
                        ) : (
                            <button
                                type="button"
                                onClick={() => pindah(indeks + 1)}
                                className={tombolUtama}
                            >
                                Maju ›
                            </button>
                        )}
                    </div>
                </div>
            }
        >
            {(!online || tersimpan === 'galat') && (
                <div className="rounded-wf border border-bad/50 bg-bad-bg p-3">
                    <b className="block font-display text-sm text-bad">
                        Koneksi internet terputus
                    </b>
                    <p className="mt-1 text-[11px] leading-relaxed text-bad">
                        {jumlahTertunda > 0
                            ? `${jumlahTertunda} jawaban disimpan sementara di perangkat ini dan akan dikirim otomatis saat internet tersambung kembali. `
                            : 'Jawaban Anda sementara disimpan di perangkat ini. '}
                        Segera hubungi pengawas jika koneksi tidak kembali sebelum waktu ujian habis.
                    </p>
                    {qr_secret && (
                        <div className="mt-2">
                            <button
                                type="button"
                                onClick={async () => {
                                    const payload = { a: attempt.id, j: jawabanRef.current };
                                    const json = JSON.stringify(payload);
                                    const enc = new TextEncoder();
                                    const key = await window.crypto.subtle.importKey(
                                        'raw',
                                        enc.encode(qr_secret),
                                        { name: 'HMAC', hash: 'SHA-256' },
                                        false,
                                        ['sign'],
                                    );
                                    const sig = await window.crypto.subtle.sign('HMAC', key, enc.encode(json));
                                    const hex = Array.from(new Uint8Array(sig))
                                        .map((b) => b.toString(16).padStart(2, '0'))
                                        .join('');
                                    const lengkapJson = JSON.stringify({ ...payload, s: hex });

                                    let paket = '';
                                    if (typeof CompressionStream !== 'undefined') {
                                        try {
                                            const cs = new CompressionStream('deflate');
                                            const writer = cs.writable.getWriter();
                                            void writer.write(enc.encode(lengkapJson));
                                            void writer.close();
                                            const buf = await new Response(cs.readable).arrayBuffer();
                                            const bytes = new Uint8Array(buf);
                                            let bin = '';
                                            for (let i = 0; i < bytes.length; i++) {
                                                bin += String.fromCharCode(bytes[i]);
                                            }
                                            paket = 'Z1:' + btoa(bin);
                                        } catch {
                                            paket = btoa(lengkapJson);
                                        }
                                    } else {
                                        paket = btoa(lengkapJson);
                                    }

                                    setKodeDarurat(paket);
                                    if (paket.length <= 2200) {
                                        try {
                                            const dataUrl = await QRCode.toDataURL(paket, {
                                                width: 280,
                                                margin: 2,
                                                errorCorrectionLevel: 'L',
                                            });
                                            setQrDaruratImg(dataUrl);
                                            setQrTerlaluBesar(false);
                                        } catch {
                                            setQrDaruratImg(null);
                                            setQrTerlaluBesar(true);
                                        }
                                    } else {
                                        setQrDaruratImg(null);
                                        setQrTerlaluBesar(true);
                                    }
                                }}
                                className="rounded-wf border border-bad bg-surface px-2.5 py-1 text-[11px] font-medium text-bad"
                            >
                                Buat QR / Paket Darurat Offline
                            </button>
                            {qrDaruratImg && (
                                <div className="mt-2 flex flex-col items-center rounded-wf border border-line bg-white p-3">
                                    <img
                                        src={qrDaruratImg}
                                        alt="QR Paket Darurat Offline"
                                        className="h-56 w-56 object-contain"
                                    />
                                    <p className="mt-1.5 text-center text-[11px] text-ink-2">
                                        Tunjukkan QR ini ke pengawas untuk dipindai.
                                    </p>
                                </div>
                            )}
                            {qrTerlaluBesar && (
                                <p className="mt-1.5 text-[11px] text-bad">
                                    Ukuran jawaban terlalu panjang untuk 1 QR. Salin teks di bawah lalu kirim ke pengawas.
                                </p>
                            )}
                            {kodeDarurat && (
                                <textarea
                                    readOnly
                                    value={kodeDarurat}
                                    rows={2}
                                    onClick={(e) => (e.target as HTMLTextAreaElement).select()}
                                    className="mt-1.5 w-full rounded-wf border border-line bg-surface px-2 py-1 font-mono text-[10px] text-ink"
                                />
                            )}
                        </div>
                    )}
                </div>
            )}

            {/* Spanduk kembali ke layar penuh; browser mewajibkan gestur klik pengguna untuk requestFullscreen(). */}
            {wajib_layar_penuh && layar.dukung() && ! layarPenuh && (
                <div className="rounded-wf border border-warn/50 bg-warn-bg p-3">
                    <b className="block font-display text-sm text-warn">Anda keluar dari layar penuh</b>
                    <p className="mt-1 text-[11px] leading-relaxed text-warn">
                        Ujian ini berjalan dalam layar penuh. Kembali ke layar penuh dan jangan
                        membuka jendela lain di atas soal. Kejadian ini dicatat.
                    </p>
                    <button
                        type="button"
                        onClick={() => layar.minta()}
                        className="mt-2 rounded-wf border border-accent bg-accent px-3 py-1.5 text-xs font-medium text-on-accent"
                    >
                        Kembali ke layar penuh
                    </button>
                </div>
            )}

            {peringatan && (
                <div className="rounded-wf border border-warn/50 bg-warn-bg p-3">
                    <b className="block font-display text-sm text-warn">
                        Anda keluar dari layar ujian
                    </b>
                    <p className="mt-1 text-[11px] leading-relaxed text-warn">
                        Kejadian ini dicatat. Kembali ke layar ujian dan jangan berpindah tab lagi.
                        Pelanggaran berulang dapat mengunci ujian Anda.
                    </p>
                    <div className="mt-2 flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            onClick={() => setPeringatan(null)}
                            className="rounded-wf border border-accent bg-accent px-3 py-1.5 text-xs font-medium text-on-accent"
                        >
                            Kembali ke ujian
                        </button>
                        {/* Hanya tampilkan sisa dari server agar ambang batas sekolah tidak dikirim ke klien. */}
                        {peringatan.sisa !== null && (
                            <span className="text-[11px] text-ink-3">
                                Sisa {peringatan.sisa} sebelum ujian dikunci
                            </span>
                        )}
                    </div>
                </div>
            )}

            <DaftarSoal
                terbuka={daftarTerbuka}
                tutup={() => setDaftarTerbuka(false)}
                butir={butir}
                indeks={indeks}
                ditandai={ditandai}
                jawaban={jawaban}
                progres={ringkas}
                bolehMundur={attempt.boleh_mundur}
                pindah={(i) => {
                    pindah(i);
                    setDaftarTerbuka(false);
                }}
            />

            {aktif && (
                <IsiButir butir={aktif} jawaban={jawaban[aktif.id] ?? {}} set={setJawabanButir} />
            )}

            {tanda_air_aktif && (
                <TandaAir
                    nama={tanda_air.nama}
                    nomorInduk={tanda_air.nomor_induk}
                    ruang={peserta.ruang}
                    kursi={peserta.kursi}
                    nomor={aktif?.nomor ?? 1}
                />
            )}
        </KerangkaSiswa>
    );
}

/** Lapisan tanda air (identitas peserta + nomor soal) dengan `aria-hidden` agar tidak mengganggu pembaca layar. */
function TandaAir({
    nama,
    nomorInduk,
    ruang,
    kursi,
    nomor,
}: {
    nama: string;
    nomorInduk: string;
    ruang: string | null;
    kursi: number | null;
    nomor: number;
}) {
    const isi = [nama, nomorInduk, ruang && `R${ruang}`, kursi && `K${kursi}`, `Soal ${nomor}`]
        .filter(Boolean)
        .join(' · ');

    return (
        <div aria-hidden="true" className="tanda-air">
            {Array.from({ length: 8 }, (_, i) => (
                <span key={i}>{isi}</span>
            ))}
        </div>
    );
}

const tombolBawah =
    'rounded-wf border border-line bg-surface px-2 py-2 text-center text-xs text-ink disabled:opacity-40';

const tombolUtama =
    'rounded-wf border border-accent bg-accent px-2 py-2 text-center text-xs font-medium text-on-accent';

const tombolGeser =
    'rounded-wf border border-line px-1.5 py-0.5 text-xs text-ink-2 disabled:opacity-30';

const kelasJawab =
    'w-full rounded-wf border border-line bg-surface px-2.5 py-2 text-sm text-ink outline-none focus:border-accent';

/** Modal navigasi nomor soal dengan penanda warna dan simbol (titik untuk ragu). */
function DaftarSoal({
    terbuka,
    tutup,
    butir,
    indeks,
    ditandai,
    jawaban,
    progres,
    bolehMundur,
    pindah,
}: {
    terbuka: boolean;
    tutup: () => void;
    butir: Butir[];
    indeks: number;
    ditandai: Record<number, boolean>;
    jawaban: Record<number, Record<string, unknown>>;
    progres: Progres;
    bolehMundur: boolean;
    pindah: (n: number) => void;
}) {
    return (
        // Pakai prop gelap agar backdrop modal sesuai dengan tema .rangka-ujian.
        <Modal judul="Daftar soal" terbuka={terbuka} padaTutup={tutup} gelap>
            <div className="space-y-2.5">
                <p className="text-[11px] text-ink-3">{progres.ringkas}</p>

                <div className="grid grid-cols-6 gap-1">
                    {butir.map((b, i) => {
                        const dijawab = adaIsi(jawaban[b.id]);
                        const ragu = ditandai[b.id];
                        const terlarang = i < indeks && !bolehMundur;

                        return (
                            <button
                                key={b.id}
                                type="button"
                                onClick={() => pindah(i)}
                                disabled={terlarang}
                                aria-current={i === indeks ? 'true' : undefined}
                                className={
                                    'rounded-wf border py-1.5 font-mono text-[11px] transition-colors disabled:opacity-30 ' +
                                    (i === indeks
                                        ? 'border-accent bg-accent text-on-accent'
                                        : ragu
                                          ? 'border-warn/60 bg-warn-bg text-warn'
                                          : dijawab
                                            ? 'border-ok/40 bg-ok-bg text-ok'
                                            : 'border-line bg-surface text-ink-3')
                                }
                            >
                                {b.nomor}
                                {ragu && <span className="ml-0.5">•</span>}
                            </button>
                        );
                    })}
                </div>

                <div className="flex flex-wrap gap-x-3 gap-y-1 text-[10px] text-ink-3">
                    <span className="flex items-center gap-1">
                        <span className="h-2 w-2 rounded-sm border border-ok/40 bg-ok-bg" />
                        sudah dijawab
                    </span>
                    <span className="flex items-center gap-1">
                        <span className="h-2 w-2 rounded-sm border border-warn/60 bg-warn-bg" />
                        ragu •
                    </span>
                    <span className="flex items-center gap-1">
                        <span className="h-2 w-2 rounded-sm border border-line bg-surface" />
                        belum dijawab
                    </span>
                </div>
            </div>
        </Modal>
    );
}

/** Cermin `StudentAnswer::terjawab()` di klien; array kosong (mis. `{"opsi":[]}`) dianggap belum terjawab. */
function adaIsi(isi: Record<string, unknown> | undefined): boolean {
    if (!isi) {
        return false;
    }

    const periksa = (nilai: unknown): boolean => {
        if (Array.isArray(nilai)) {
            // Periksa rekursif karena isian rumpang bisa berisi array string kosong.
            return nilai.some(periksa);
        }

        return nilai !== null && nilai !== undefined && nilai !== '';
    };

    return Object.values(isi).some(periksa);
}

/** Ambil daftar isian rumpang sebagai array string. */
function isiRumpang(jawaban: Record<string, unknown>): string[] {
    if (!Array.isArray(jawaban.rumpang)) {
        return [];
    }

    return (jawaban.rumpang as unknown[]).map((v) => (typeof v === 'string' ? v : ''));
}

/** Render teks soal rumpang dengan memecah penanda `{{n}}` menjadi potongan HTML dan `<input>` inline. */
function TeksRumpang({
    teks,
    isian,
    padaUbah,
}: {
    teks?: string;
    isian: string[];
    padaUbah: (nomor: number, nilai: string) => void;
}) {
    const html = typeof teks === 'string' ? teks : '';

    if (html.trim() === '') {
        return <span className="text-ink-3">Soal ini belum punya teks.</span>;
    }

    const bagian = html.split(/(\{\{\s*\d+\s*\}\})/g);

    return (
        // Paksa <p> menjadi inline karena pemecahan di tengah paragraf membuat browser menutup tag <p> di tiap potongan.
        <div className="text-sm leading-loose text-ink [&_b]:font-semibold [&_p]:m-0 [&_p]:inline [&_sub]:align-sub [&_sub]:text-[0.75em] [&_sup]:align-super [&_sup]:text-[0.75em]">
            {bagian.map((potongan, i) => {
                const cocok = /^\{\{\s*(\d+)\s*\}\}$/.exec(potongan);

                if (cocok === null) {
                    return (
                        <span
                            key={i}
                            dangerouslySetInnerHTML={{ __html: renderRumusDalamHtml(potongan) }}
                        />
                    );
                }

                const nomor = Number.parseInt(cocok[1], 10);

                return (
                    <input
                        key={i}
                        value={isian[nomor - 1] ?? ''}
                        onChange={(e) => padaUbah(nomor, e.target.value)}
                        aria-label={`Rumpang ${nomor}`}
                        autoComplete="off"
                        autoCorrect="off"
                        autoCapitalize="none"
                        spellCheck={false}
                        // Pakai style.width dalam satuan ch (bukan atribut size) agar lebar mengikuti isi jawaban.
                        style={{ width: `${Math.max(8, (isian[nomor - 1] ?? '').length + 2)}ch` }}
                        className="mx-1 inline-block rounded-wf border border-line bg-surface px-1.5 py-0.5 text-center font-mono text-[13px] text-ink outline-none focus:border-accent"
                    />
                );
            })}
        </div>
    );
}

function IsiButir({
    butir,
    jawaban,
    set,
}: {
    butir: Butir;
    jawaban: Record<string, unknown>;
    set: (potongan: Record<string, unknown>) => void;
}) {
    // Butir rusak tetap dipertahankan agar nomor soal berikutnya tidak bergeser.
    if (butir.rusak) {
        return (
            <Inbox>
                Soal nomor {butir.nomor} tidak dapat ditampilkan. Catat nomornya dan beri tahu
                Pengawas ruang - soal ini tetap dihitung, dan nomor sesudahnya tidak bergeser.
            </Inbox>
        );
    }

    // Terapkan urutan acak dari server (`urutan_opsi`/`urutan_butir`); null berarti urutan snapshot.
    const opsi = urutkan(butir.opsi ?? [], butir.urutan_opsi, 'kunci');
    const butirItem = urutkan(butir.butir ?? [], butir.urutan_butir, 'kunci');

    const pilihan = Array.isArray(jawaban.opsi) ? (jawaban.opsi as string[]) : [];
    const mekanisme = typeof butir.skor?.mekanisme === 'string' ? butir.skor.mekanisme : 'pilihan';

    const pakaiOpsi =
        butir.jenis === 'pilihan_ganda' ||
        butir.jenis === 'jawaban_ganda' ||
        butir.jenis === 'benar_salah' ||
        (butir.jenis === 'media' && mekanisme !== 'isian');

    return (
        <KotakPonsel solid padat>
            <div className="space-y-2.5">
                <span className="block text-[11px] text-ink-3">
                    {butir.jenis_label} · {butir.bobot} poin
                </span>

                {butir.jenis === 'isian_rumpang' ? (
                    <TeksRumpang
                        teks={butir.soal?.teks}
                        isian={isiRumpang(jawaban)}
                        padaUbah={(nomor, nilai) => {
                            const baru = [...isiRumpang(jawaban)];

                            while (baru.length < nomor) {
                                baru.push('');
                            }

                            baru[nomor - 1] = nilai;
                            set({ rumpang: baru });
                        }}
                    />
                ) : (
                    <Html nilai={butir.soal?.teks} kosong="Soal ini belum punya teks." />
                )}

                {butir.media && butir.media.length > 0 && (
                    <div className="flex flex-wrap gap-2">
                        {butir.media.map((m) => (
                            <figure key={m.id} className="min-w-0">
                                {m.gambar ? (
                                    <img
                                        src={m.url}
                                        alt={m.nama ?? `Media ${m.id}`}
                                        className="max-h-56 max-w-full rounded-wf border border-line"
                                    />
                                ) : (
                                    <a
                                        href={m.url}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="text-xs text-accent underline"
                                    >
                                        {m.nama ?? 'Buka berkas'}
                                    </a>
                                )}
                            </figure>
                        ))}
                    </div>
                )}

                {pakaiOpsi && (
                    <div className="space-y-1.5">
                        {opsi.map((o) => {
                            const on = pilihan.includes(o.kunci);
                            const banyak = butir.jenis === 'jawaban_ganda';

                            return (
                                <button
                                    key={o.kunci}
                                    type="button"
                                    onClick={() =>
                                        set({
                                            opsi: banyak
                                                ? on
                                                    ? pilihan.filter((k) => k !== o.kunci)
                                                    : [...pilihan, o.kunci]
                                                : [o.kunci],
                                        })
                                    }
                                    className={
                                        'flex w-full items-center gap-2 rounded-wf border px-2.5 py-2 text-left text-[12.5px] transition-colors ' +
                                        (on
                                            ? 'border-accent bg-accent-soft text-ink'
                                            : 'border-line bg-surface text-ink')
                                    }
                                >
                                    {/* Bulat untuk pilihan tunggal, kotak untuk pilihan ganda. */}
                                    <span
                                        className={
                                            'grid size-4 shrink-0 place-items-center border font-mono text-[10px] ' +
                                            (banyak ? 'rounded-wf' : 'rounded-full') +
                                            ' ' +
                                            (on
                                                ? 'border-accent bg-accent text-on-accent'
                                                : 'border-line text-ink-3')
                                        }
                                    >
                                        {on ? '✓' : o.kunci}
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <Html nilai={teksDari(o.teks)} />
                                        {o.media && o.media.length > 0 && (
                                            <span className="mt-1 flex flex-wrap gap-1.5">
                                                {o.media.map((m) => (
                                                    <span key={m.id} className="block">
                                                        {m.gambar ? (
                                                            <img
                                                                src={m.url}
                                                                alt={m.nama ?? `Media ${m.id}`}
                                                                className="max-h-32 max-w-full rounded-wf border border-line"
                                                            />
                                                        ) : (
                                                            /* Jangan pakai <a> di dalam <button> (HTML tidak valid dan memicu pemilihan opsi). */
                                                            <span className="text-[11px] text-ink-3">
                                                                {m.nama ?? 'Berkas'}
                                                            </span>
                                                        )}
                                                    </span>
                                                ))}
                                            </span>
                                        )}
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                )}

                {/* Matikan autocorrect dan spellcheck agar koreksi otomatis ponsel tidak mengubah jawaban singkat. */}
                {(butir.jenis === 'isian_singkat' ||
                    (butir.jenis === 'media' && mekanisme === 'isian')) && (
                    <input
                        value={(jawaban.teks as string) ?? ''}
                        onChange={(e) => set({ teks: e.target.value })}
                        autoComplete="off"
                        autoCorrect="off"
                        autoCapitalize="off"
                        spellCheck={false}
                        className={kelasJawab}
                    />
                )}

                {butir.jenis === 'angka' && (
                    <input
                        value={(jawaban.nilai as string) ?? ''}
                        onChange={(e) => set({ nilai: e.target.value })}
                        inputMode="decimal"
                        autoComplete="off"
                        autoCorrect="off"
                        spellCheck={false}
                        className={kelasJawab + ' font-mono'}
                    />
                )}

                {butir.jenis === 'uraian' && <Uraian jawaban={jawaban} set={set} />}

                {(butir.jenis === 'menjodohkan' || butir.jenis === 'mengurutkan') && (
                    <PasanganButir
                        jenis={butir.jenis}
                        butirItem={butirItem}
                        jawaban={jawaban}
                        set={set}
                    />
                )}
            </div>
        </KotakPonsel>
    );
}

/** Urutkan menurut urutan simpanan; null berarti urutan aslinya. */
function urutkan<T extends Record<string, unknown>>(
    baris: T[],
    urutan: string[] | null,
    kunci: string,
): T[] {
    if (!urutan || urutan.length === 0) {
        return baris;
    }

    const peta = new Map(baris.map((b) => [String(b[kunci]), b]));

    const hasil = urutan.map((k) => peta.get(k)).filter((b): b is T => b !== undefined);

    // Sertakan baris sisa yang tidak tercantum di `urutan` agar tidak ada opsi yang hilang.
    const sisa = baris.filter((b) => !urutan.includes(String(b[kunci])));

    return [...hasil, ...sisa];
}

/** Ambil string HTML dari blok `content_json`, atau string kosong. */
function teksDari(blok: Blok | undefined): string {
    return typeof blok?.teks === 'string' ? blok.teks : '';
}

function Uraian({
    jawaban,
    set,
}: {
    jawaban: Record<string, unknown>;
    set: (p: Record<string, unknown>) => void;
}) {
    const teks = (jawaban.teks as string) ?? '';

    const batas = BATAS_URAIAN;

    return (
        <div className="space-y-1">
            <textarea
                value={teks}
                onChange={(e) => set({ teks: e.target.value.slice(0, batas) })}
                rows={6}
                className={kelasJawab + ' leading-relaxed'}
            />
            <div className="flex items-center gap-2 text-[11px] text-ink-3">
                <span>{Math.max(0, batas - teks.length)} karakter tersisa</span>
                <span className="ml-auto">tempel teks nonaktif</span>
            </div>
        </div>
    );
}

function PasanganButir({
    jenis,
    butirItem,
    jawaban,
    set,
}: {
    jenis: string;
    butirItem: ButirSnapshot[];
    jawaban: Record<string, unknown>;
    set: (p: Record<string, unknown>) => void;
}) {
    if (jenis === 'mengurutkan') {
        const semua = butirItem.map((b) => b.kunci);
        const tersimpan = Array.isArray(jawaban.urutan) ? (jawaban.urutan as string[]) : [];

        // Mulai dari urutan snapshot jika siswa belum pernah mengubah urutan.
        const urutan = tersimpan.length > 0 ? tersimpan : semua;

        const geser = (dari: number, ke: number) => {
            if (ke < 0 || ke >= urutan.length) {
                return;
            }

            const baru = [...urutan];
            const [pindah] = baru.splice(dari, 1);

            baru.splice(ke, 0, pindah);

            set({ urutan: baru });
        };

        return (
            <div className="space-y-1.5">
                {urutan.map((kunci, i) => (
                    <div
                        key={kunci}
                        className="flex items-center gap-2 rounded-wf border border-line bg-surface px-2.5 py-2"
                    >
                        <span className="font-mono text-[11px] text-ink-3">{i + 1}</span>
                        <span className="min-w-0 flex-1 text-[12.5px]">
                            <Html nilai={teksDari(butirItem.find((b) => b.kunci === kunci)?.teks)} />
                        </span>
                        <button
                            type="button"
                            onClick={() => geser(i, i - 1)}
                            disabled={i === 0}
                            className={tombolGeser}
                        >
                            ↑
                        </button>
                        <button
                            type="button"
                            onClick={() => geser(i, i + 1)}
                            disabled={i === urutan.length - 1}
                            className={tombolGeser}
                        >
                            ↓
                        </button>
                    </div>
                ))}
            </div>
        );
    }

    // Baris tanpa grup dimasukkan ke kiri agar soal dengan data kurang lengkap tetap bisa dikerjakan.
    const kiri = butirItem.filter((b) => b.grup !== 'kanan');
    const kanan = butirItem.filter((b) => b.grup === 'kanan');
    const pasangan = (jawaban.pasangan as Record<string, string>) ?? {};

    return (
        <div className="space-y-1.5">
            {kiri.map((b) => (
                <div key={b.kunci} className="flex items-center gap-2">
                    <span className="min-w-0 flex-1 text-[12.5px]">
                        <Html nilai={teksDari(b.teks)} />
                    </span>
                    <span className="h-px w-3 shrink-0 bg-line" aria-hidden="true" />
                    <select
                        value={pasangan[b.kunci] ?? ''}
                        onChange={(e) =>
                            set({ pasangan: { ...pasangan, [b.kunci]: e.target.value } })
                        }
                        className="w-[45%] rounded-wf border border-line bg-surface px-2 py-1.5 text-[12px] text-ink outline-none focus:border-accent"
                    >
                        <option value="">belum dipilih</option>
                        {kanan.map((k) => (
                            <option key={k.kunci} value={k.kunci}>
                                {teksPolos(teksDari(k.teks)) || k.kunci}
                            </option>
                        ))}
                    </select>
                </div>
            ))}
        </div>
    );
}

/** Hapus tag HTML untuk label `<option>`. */
function teksPolos(html: string): string {
    return html.replace(/<[^>]*>/g, '').trim();
}

function Jam({ sisa, panas }: { sisa: number; panas: boolean }) {
    const j = Math.floor(sisa / 3600);
    const m = Math.floor((sisa % 3600) / 60);
    const d = sisa % 60;

    const dua = (n: number) => String(n).padStart(2, '0');

    return (
        <span
            className={
                'rounded-wf px-2 py-0.5 font-mono text-[12px] tabular-nums ' +
                (panas ? 'bg-bad-bg text-bad' : 'bg-surface-2 text-ink-2')
            }
        >
            {j > 0 ? `${dua(j)}:` : ''}
            {dua(m)}:{dua(d)}
        </span>
    );
}

function PenandaSimpan({
    keadaan,
    tertunda = 0,
}: {
    keadaan: 'diam' | 'proses' | 'ok' | 'galat';
    tertunda?: number;
}) {
    if (keadaan === 'diam' && tertunda === 0) {
        return <span className="text-[11px] text-ink-3">Tersimpan otomatis</span>;
    }

    if (keadaan === 'proses') {
        return <span className="text-[11px] text-ink-3">Menyimpan…</span>;
    }

    if (keadaan === 'galat') {
        // Gagal menyimpan ditampilkan terang-terangan (berbeda dari log pelanggaran yang diam).
        return (
            <Chip nada="bad">
                {tertunda > 0 ? `Tertunda (${tertunda}) · di perangkat` : 'Gagal menyimpan'}
            </Chip>
        );
    }

    if (tertunda > 0) {
        return <Chip nada="warn">Menunggu kirim ({tertunda})</Chip>;
    }

    return <Chip nada="ok">tersimpan</Chip>;
}

function kunciCadangan(attemptId: number): string {
    return `cbt_cadangan_attempt_${attemptId}`;
}

function bacaTertunda(attemptId: number): Record<number, Record<string, unknown>> {
    if (typeof window === 'undefined') {
        return {};
    }

    try {
        const mentah = window.localStorage.getItem(kunciCadangan(attemptId));

        if (!mentah) {
            return {};
        }

        const hasil = JSON.parse(mentah) as Record<string, Record<string, unknown>>;

        return Object.fromEntries(
            Object.entries(hasil).map(([k, v]) => [Number(k), v]),
        );
    } catch {
        return {};
    }
}

function tulisTertunda(attemptId: number, data: Record<number, Record<string, unknown>>): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        if (Object.keys(data).length === 0) {
            window.localStorage.removeItem(kunciCadangan(attemptId));
        } else {
            window.localStorage.setItem(kunciCadangan(attemptId), JSON.stringify(data));
        }
    } catch {
        // localStorage penuh atau diblokir mode privat, memori tab tetap jalan.
    }
}

/**
 * Id peramban per tab di `sessionStorage` agar muat ulang tab tidak memicu `sesi_ganda`,
 * namun tab kedua tetap mendapat id berbeda (tidak memakai `localStorage` yang dibagi antar tab).
 */
function idPeramban(): string {
    const kunci = '__cbtPerangkat';
    const wadah = window as unknown as Record<string, string | undefined>;

    try {
        const tersimpan = window.sessionStorage.getItem(kunci);

        if (tersimpan) {
            return tersimpan;
        }
    } catch {
        // Fallback ke memori tab jika sessionStorage diblokir.
    }

    if (wadah[kunci]) {
        return wadah[kunci];
    }

    const baru = Math.random().toString(36).slice(2, 12);

    wadah[kunci] = baru;

    try {
        window.sessionStorage.setItem(kunci, baru);
    } catch {
        // Tetap gunakan id di memori tab jika penulisan sessionStorage gagal.
    }

    return baru;
}

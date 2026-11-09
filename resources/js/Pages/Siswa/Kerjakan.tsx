import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import KerangkaSiswa from '@/Layouts/KerangkaSiswa';
import { Chip, Inbox } from '@/Components/ui';
import { KotakPonsel } from '@/Components/ponsel';
import { Html } from '@/Components/PratinjauButir';

/**
 * Pengerjaan soal - wireframe-peran.html plate 22, 23, dan lapisan 24.
 *
 * =====================================================================
 * Kunci jawaban tidak pernah ada di berkas ini, dan itu bisa diperiksa
 * =====================================================================
 * Seluruh butir datang lewat `ExamQuestion::untukSiswa()` - lihat
 * KerjakanSiswaController. Artinya `kunci_json`, `rubrik_json`, dan
 * `pembahasan_json` memang tidak ada di props. Laman ini karena itu TIDAK
 * bisa menilai jawaban anak, dan memang tidak boleh: penilaian otomatis
 * adalah Fase 8, dan laman yang mengira-ngira benar/salah berarti
 * mengirimkan jawabannya dalam bentuk lain.
 *
 * Laman ini juga TIDAK menampilkan satuan jawaban soal angka, dan itu
 * konsekuensi yang sama: `satuan` disimpan di dalam KUNCI
 * (`SkemaJenisSoal::angka()`), dan kunci tidak dikirim ke sini. Satuan
 * karena itu harus ditulis Guru di dalam teks soalnya.
 *
 * =====================================================================
 * Bentuk opsi dan butir BERBEDA dari bentuk bank soal
 * =====================================================================
 * Yang sampai ke sini adalah `opsi_json` dan `butir_json` hasil
 * `PembekuPaket`, dan bentuknya dipadatkan untuk pembacaan:
 *
 *   opsi   { kunci, teks }                        - bukan { option_key, content_json }
 *   butir  { kunci, grup, teks, pasangan, posisi } - bukan { item_key, item_group, ... }
 *
 * `teks` di keduanya MASIH berupa objek `{teks: html}` - ia adalah
 * `content_json` apa adanya. Karena itu ada `teksDari()` di bawah, bukan
 * `o.teks` yang ditulis langsung di JSX.
 *
 * ---------------------------------------------------------------------
 * Tiga pengirim latar belakang, dan ketiganya gagal dengan diam
 * ---------------------------------------------------------------------
 * Blueprint `:683` menulis aturannya: "Kegagalan mengirim catatan
 * pelanggaran tidak boleh menahan penyimpanan jawaban, karena autosave
 * jauh lebih penting daripada log."
 *
 *   1. SIMPAN   - selang `simpan_tiap_detik` dari pengaturan sekolah, plus
 *                 sekali saat butir berpindah. Jawaban 409 berarti versi
 *                 yang dipegang laman ini sudah basi: butirnya dimuat ulang.
 *   2. DENYUT   - tiap 30 detik (PRD §12.2). Jawabannya yang menentukan:
 *                 `terkunci` memindahkan anak ke plate 25 SEKARANG, bukan
 *                 pada penyimpanan berikutnya.
 *   3. LAPOR    - saat kejadian pantangan terjadi. Dikirim lepas, dan
 *                 hasilnya hanya dipakai untuk penghitung sisa menuju kunci.
 *
 * ---------------------------------------------------------------------
 * `fetch` dan bukan `router.post`
 * ---------------------------------------------------------------------
 * `router.post` adalah navigasi: ia mengganti props halaman, dan pada laman
 * yang sedang dikerjakan itu berarti butir yang sedang dibaca bisa berganti
 * di tengah kalimat. Autosave yang memindahkan anak dari soalnya lebih
 * merugikan daripada autosave yang gagal.
 *
 * Token CSRF dibaca dari cookie `XSRF-TOKEN`, yang MEMANG bisa dibaca JS
 * (`newCookie(..., false)`), dan dikirim sebagai header `X-XSRF-TOKEN` -
 * bentuk yang didekripsi `PreventRequestForgery::getTokenFromRequest()`.
 * Tidak ada meta tag dan tidak ada rute baru untuk itu.
 *
 * ---------------------------------------------------------------------
 * Yang TIDAK dikerjakan di sini, dan disengaja
 * ---------------------------------------------------------------------
 * Tidak ada penilaian, tidak ada pengacakan ulang (urutan datang dari
 * server), dan tidak ada penguncian layar penuh otomatis - `enforce_fullscreen`
 * milik event, dan permintaannya harus datang dari klik pertama siswa.
 */

/** Satu blok `content_json` - bentuknya `{teks: html}`. */
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
};

/** Selang denyut - PRD §12.2, dan tidak dibaca dari pengaturan. */
const SELANG_DENYUT = 30_000;

/**
 * Batas karakter uraian, dan ia SALINAN dari `SkemaJenisSoal::BATAS_TEKS`.
 *
 * Disalin karena batas itu tidak ikut ke peramban: yang tersimpan di
 * `skor_json` hanyalah konfigurasi skor, sementara rubrik - satu-satunya
 * tempat yang memuat batas per soal - sengaja tidak pernah dikirim ke sini.
 * Yang menjaga salinan ini tetap benar: server tetap menolak teks yang lebih
 * panjang, sehingga yang hilang kalau angkanya berubah hanyalah penghitung
 * di layar, bukan kebenarannya.
 */
const BATAS_URAIAN = 20_000;

export default function Kerjakan({ peserta, attempt, butir, progres, simpan_tiap_detik }: Props) {
    const [indeks, setIndeks] = useState(0);
    const [sisa, setSisa] = useState(attempt.sisa_detik);
    const [ringkas, setRingkas] = useState(progres);
    const [tersimpan, setTersimpan] = useState<'diam' | 'proses' | 'ok' | 'galat'>('diam');

    // Jawaban dipegang di satu objek, bukan di state per butir: jawaban
    // harus bertahan saat anak berpindah butir lalu kembali, dan state yang
    // hidup di dalam komponen butir akan hilang begitu butirnya dilepas.
    const [jawaban, setJawaban] = useState<Record<number, Record<string, unknown>>>(() =>
        Object.fromEntries(
            butir.filter((b) => b.jawaban?.isi).map((b) => [b.id, b.jawaban?.isi ?? {}]),
        ),
    );

    const [ditandai, setDitandai] = useState<Record<number, boolean>>(() =>
        Object.fromEntries(butir.map((b) => [b.id, b.jawaban?.ditandai ?? false])),
    );

    // Versi yang dipegang laman ini per butir - dipakai optimistic
    // concurrency. Di ref, bukan state: menaikkannya tidak boleh memicu
    // render, karena render di tengah pengetikan adalah hal yang justru
    // ingin dihindari.
    const versi = useRef<Record<number, number>>(
        Object.fromEntries(butir.map((b) => [b.id, b.jawaban?.versi ?? 0])),
    );

    // Salinan jawaban terbaru untuk autosave dan untuk penyimpanan saat
    // berpindah butir. Keduanya membacanya dari sini, bukan dari state:
    // autosave yang menjadikan `jawaban` sebagai dependensi akan MEMBANGUN
    // ULANG jamnya pada setiap ketukan tombol, dan jam yang selalu dibangun
    // ulang tidak pernah sampai ke detiknya.
    const jawabanRef = useRef(jawaban);
    jawabanRef.current = jawaban;

    // Peringatan tingkat 1 - plate 24. `sisa` datang dari server, bukan
    // dihitung dari ambang - ambang sekolah tidak pernah dikirim ke
    // peramban, dan itu keputusan `TindakanPelanggaran`.
    const [peringatan, setPeringatan] = useState<{ sisa: number | null } | null>(null);

    // Dipakai penjaga plate 24 untuk mengukur lama perpindahan.
    const mulaiPindahTab = useRef<number | null>(null);

    const aktif = butir[indeks];

    /* ------------------------------------------------------------- Pengirim */

    /** Kirim JSON ke endpoint siswa, dengan token CSRF dari cookie. */
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

        // 419 = sesi CSRF kedaluwarsa, dan itu berarti halaman ini sudah
        // terlalu lama terbuka. Yang benar adalah memuat ulang, bukan
        // mengulang kiriman - muat ulang akan memulihkan tokennya sekaligus
        // memperbarui sisa waktu dari server.
        if (tanggapan.status === 419) {
            window.location.reload();

            return null;
        }

        if (!tanggapan.ok) {
            throw new Error(String(tanggapan.status));
        }

        return tanggapan.json();
    }, []);

    /** Laporan pelanggaran - dikirim lepas, tidak pernah ditunggu. */
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

                    // Namanya dari `TindakanPelanggaran::toArray()`, dan
                    // dibaca apa adanya: `dikunci` dan `diperingatkan` adalah
                    // keputusan server atas rangkaiannya, bukan kesimpulan
                    // laman ini atas satu kejadian.
                    const t = hasil as {
                        dikunci?: boolean;
                        diperingatkan?: boolean;
                        sisa_menuju_kunci?: number | null;
                    };

                    // Terkunci seketika (sesi ganda) - pindah ke plate 25
                    // sekarang juga, bukan menunggu denyut berikutnya.
                    if (t.dikunci) {
                        router.visit(`/siswa/ujian/${peserta.peserta_id}/terkunci`);

                        return;
                    }

                    if (t.diperingatkan) {
                        setPeringatan({ sisa: t.sisa_menuju_kunci ?? null });
                    }
                })
                .catch(() => {
                    // Diam, dan disengaja: lihat catatan berkas ini.
                });
        },
        [kirimJson, peserta.peserta_id],
    );

    /** Simpan satu butir. `senyap` berarti autosave, bukan aksi anak. */
    const simpan = useCallback(
        async (butirId: number, isi: Record<string, unknown>, senyap: boolean) => {
            if (!senyap) {
                setTersimpan('proses');
            }

            try {
                const hasil = (await kirimJson(
                    `/siswa/ujian/${peserta.peserta_id}/butir/${butirId}`,
                    { isi, versi: versi.current[butirId] ?? 0 },
                )) as { versi: number; progres: Progres } | null;

                if (!hasil) {
                    return;
                }

                versi.current[butirId] = hasil.versi;
                setRingkas(hasil.progres);
                setTersimpan('ok');
            } catch (galat) {
                // 409 - versi basi. Butirnya dimuat ulang, dan yang lebih
                // baru menang. Jawaban di layar TIDAK ditimpa: anak yang
                // sedang mengetik tidak boleh kehilangan ketikannya karena
                // tab lain menyimpan lebih dulu.
                if (String(galat).includes('409')) {
                    router.reload({ only: ['butir', 'progres'] });
                }

                setTersimpan('galat');
            }
        },
        [kirimJson, peserta.peserta_id],
    );

    /* -------------------------------------------------------------- Denyut */

    useEffect(() => {
        const perangkat = idPeramban();

        const kirimDenyut = () => {
            kirimJson(`/siswa/ujian/${peserta.peserta_id}/denyut`, {
                perangkat,
                layar_penuh: document.fullscreenElement !== null,
                pelindung: true,
            })
                .then((hasil) => {
                    if (!hasil) {
                        return;
                    }

                    // `DenyutService::catat()` mengembalikan tepat kunci-kunci
                    // ini - lihat docblock-nya.
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

                    // Attempt yang ditutup - oleh pengawas, oleh kedaluwarsa,
                    // maupun oleh tab lain - memindahkan anak ke laman yang
                    // benar. Ini satu-satunya jalan anak yang menutup tab lalu
                    // kembali tahu ujiannya sudah berakhir.
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

    /* --------------------------------------------------------- Hitung waktu */

    const habis = sisa <= 0;

    useEffect(() => {
        if (habis) {
            return;
        }

        const jam = window.setInterval(() => setSisa((lama) => Math.max(0, lama - 1)), 1000);

        return () => window.clearInterval(jam);
    }, [habis]);

    // Waktu habis: yang memutuskan adalah server, dan laman ini hanya
    // berhenti menghitung. Menutup attempt di sini berarti jam perangkat yang
    // menentukan akhir ujian - tepat yang dihindari MesinUjian.
    useEffect(() => {
        if (habis) {
            router.visit(`/siswa/ujian/${peserta.peserta_id}/selesai`);
        }
    }, [habis, peserta.peserta_id]);

    /* ------------------------------------------------------- Autosave butir */

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

    /* ------------------------------------------ Penjaga plate 24 dan `:540` */

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

            // Ambang 1,5 detik menyaring gangguan bukan kesengajaan -
            // blueprint `:557` - TANPA menghilangkan catatannya: yang
            // dihilangkan hanya peringatannya, dan kejadiannya tetap dikirim
            // supaya pengawas bisa menilai sendiri. `AmbangPelanggaran` yang
            // memutuskan apa artinya, bukan laman ini.
            lapor('pindah_tab', durasi);
        };

        const saatKontek = (e: MouseEvent) => {
            e.preventDefault();
            lapor('klik_kanan');
        };

        // Salin, potong, dan tempel DIBLOKIR, dan ketiganya dilaporkan
        // sebagai satu jenis yang sama. Blueprint memetakan `copy`, `cut`,
        // dan `paste` ke `salin_tempel` - lihat peta di TanggaPelanggaran -
        // dan yang dilaporkan adalah percobaannya, bukan keberhasilannya.
        const saatSalin = (e: ClipboardEvent) => {
            e.preventDefault();
            lapor('salin_tempel');
        };

        const saatPintasan = (e: KeyboardEvent) => {
            const tahan = e.ctrlKey || e.metaKey;

            const terlarang =
                e.key === 'F12' ||
                (tahan && e.shiftKey && ['I', 'J', 'C'].includes(e.key.toUpperCase())) ||
                (tahan && ['U', 'P'].includes(e.key.toUpperCase()));

            if (terlarang) {
                e.preventDefault();
                lapor('pintasan_terlarang');
            }
        };

        document.addEventListener('visibilitychange', saatSembunyi);
        document.addEventListener('visibilitychange', saatTerlihat);
        document.addEventListener('contextmenu', saatKontek);
        document.addEventListener('copy', saatSalin);
        document.addEventListener('cut', saatSalin);
        document.addEventListener('paste', saatSalin);
        document.addEventListener('keydown', saatPintasan);

        return () => {
            document.removeEventListener('visibilitychange', saatSembunyi);
            document.removeEventListener('visibilitychange', saatTerlihat);
            document.removeEventListener('contextmenu', saatKontek);
            document.removeEventListener('copy', saatSalin);
            document.removeEventListener('cut', saatSalin);
            document.removeEventListener('paste', saatSalin);
            document.removeEventListener('keydown', saatPintasan);
        };
    }, [lapor]);

    /* ------------------------------------------------------------ Navigasi */

    const pindah = useCallback(
        (tujuan: number) => {
            if (tujuan < 0 || tujuan >= butir.length) {
                return;
            }

            // Mundur dimatikan bila ujiannya melarangnya - dan yang melarang
            // adalah `allow_back_navigation` milik ujian, bukan laman ini.
            if (tujuan < indeks && !attempt.boleh_mundur) {
                return;
            }

            // Simpan butir yang ditinggalkan SEBELUM pindah, bukan sesudah:
            // autosave berselang dua puluh detik, dan anak yang mengerjakan
            // cepat bisa berpindah tiga soal di antaranya.
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

        setJawaban((lama) => ({ ...lama, [id]: { ...(lama[id] ?? {}), ...potongan } }));
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
                        <PenandaSimpan keadaan={tersimpan} />
                        <span className="ml-auto text-[11px] text-ink-3">{ringkas.ringkas}</span>
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
                                onClick={() =>
                                    router.visit(`/siswa/ujian/${peserta.peserta_id}/selesai`)
                                }
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
            {/* Plate 24 - sisanya diredupkan dan TIDAK disembunyikan:
                gangguan sepersekian detik dari notifikasi ponsel adalah hal
                biasa, dan menghapus pekerjaannya karena itu adalah hukuman
                yang tidak sepadan. */}
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
                        {/* Angkanya dari `sisa_menuju_kunci`. Penyebutnya
                            sengaja tidak disebut - ambang sekolah tidak pernah
                            sampai ke peramban, dan angka sisa ini pun hanya
                            ada karena server memilih mengirimnya. */}
                        {peringatan.sisa !== null && (
                            <span className="text-[11px] text-ink-3">
                                Sisa {peringatan.sisa} sebelum ujian dikunci
                            </span>
                        )}
                    </div>
                </div>
            )}

            <PemilihNomor
                butir={butir}
                indeks={indeks}
                ditandai={ditandai}
                jawaban={jawaban}
                pindah={pindah}
            />

            {aktif && (
                <IsiButir butir={aktif} jawaban={jawaban[aktif.id] ?? {}} set={setJawabanButir} />
            )}
        </KerangkaSiswa>
    );
}

/* ================================================================== Bagian */

const tombolBawah =
    'rounded-wf border border-line bg-surface px-2 py-2 text-center text-xs text-ink disabled:opacity-40';

const tombolUtama =
    'rounded-wf border border-accent bg-accent px-2 py-2 text-center text-xs font-medium text-on-accent';

const tombolGeser =
    'rounded-wf border border-line px-1.5 py-0.5 text-xs text-ink-2 disabled:opacity-30';

const kelasJawab =
    'w-full rounded-wf border border-line bg-surface px-2.5 py-2 text-sm text-ink outline-none focus:border-accent';

/**
 * Pemilih nomor - plate 22 `.qstrip` dan plate 26 `.qnav`.
 *
 * TIGA penanda, dan warnanya bukan satu-satunya: angka yang sudah dijawab
 * diberi latar, yang ragu diberi tanda titik, dan yang belum dibiarkan
 * kosong. Anak yang buta warna tetap membedakannya, dan itulah alasan plate
 * 22 menulis bahwa warna tidak pernah menjadi satu-satunya penanda.
 */
function PemilihNomor({
    butir,
    indeks,
    ditandai,
    jawaban,
    pindah,
}: {
    butir: Butir[];
    indeks: number;
    ditandai: Record<number, boolean>;
    jawaban: Record<number, Record<string, unknown>>;
    pindah: (n: number) => void;
}) {
    return (
        <div className="grid grid-cols-6 gap-1">
            {butir.map((b, i) => {
                const dijawab = adaIsi(jawaban[b.id]);
                const ragu = ditandai[b.id];

                return (
                    <button
                        key={b.id}
                        type="button"
                        onClick={() => pindah(i)}
                        className={
                            'rounded-wf border py-1.5 font-mono text-[11px] transition-colors ' +
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
    );
}

/**
 * Ada isinya atau tidak - CERMIN dari `StudentAnswer::terjawab()`.
 *
 * Disalin, dan itu perlu: penanda "sudah dijawab" di pemilih nomor harus
 * berubah pada ketukan yang sama anak mengisi jawabannya, sementara server
 * baru mengetahuinya satu simpanan kemudian. Yang dijaga agar kedua sisi
 * tetap sepakat adalah satu aturannya: sel berupa daftar kosong tidak
 * dihitung menjawab, karena `{"opsi":[]}` dan tidak menjawab adalah hal
 * yang sama.
 */
function adaIsi(isi: Record<string, unknown> | undefined): boolean {
    if (!isi) {
        return false;
    }

    for (const nilai of Object.values(isi)) {
        if (Array.isArray(nilai)) {
            if (nilai.length > 0) {
                return true;
            }

            continue;
        }

        if (nilai !== null && nilai !== '') {
            return true;
        }
    }

    return false;
}

/**
 * Badan soal, per jenis - plate 23.
 *
 * Sembilan jenis berbagi satu rangka, dan yang berubah hanya bentuk
 * jawabannya. Plate 23 menulis alasannya: menyeragamkan rangkanya membuat
 * kesembilan jenis terasa sebagai satu ujian, bukan sembilan aplikasi
 * berbeda - dan bagi siswa yang sedang tegang, pengenalan bentuk adalah
 * beban yang tidak perlu.
 */
function IsiButir({
    butir,
    jawaban,
    set,
}: {
    butir: Butir;
    jawaban: Record<string, unknown>;
    set: (potongan: Record<string, unknown>) => void;
}) {
    // Butir rusak: snapshotnya tidak punya soal. Ia TETAP dikirim dan TETAP
    // bernomor - lihat KerjakanSiswaController::butirUntukSiswa() - karena
    // membuangnya akan menggeser nomor seluruh soal sesudahnya, dan nomor
    // soal adalah hal yang disebut pengawas di ruang ujian.
    if (butir.rusak) {
        return (
            <Inbox>
                Soal nomor {butir.nomor} tidak dapat ditampilkan. Catat nomornya dan beri tahu
                Pengawas ruang - soal ini tetap dihitung, dan nomor sesudahnya tidak bergeser.
            </Inbox>
        );
    }

    // Urutan yang dilihat peserta ini. Null berarti "sebagaimana snapshot" -
    // lihat AttemptQuestion - dan itu BUKAN urutan array mentah: kolomnya ada
    // supaya pengacakan tercatat, dan laman yang mengabaikannya menampilkan
    // urutan yang berbeda dari yang pernah dilihat anak ini.
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

                <Html nilai={butir.soal?.teks} kosong="Soal ini belum punya teks." />

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

                {/* Pilihan ganda, ganda, benar/salah, dan media berjawaban
                    pilihan berbagi bentuk yang sama; yang berbeda hanya
                    berapa yang boleh dipilih. */}
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
                                    {/* Pilihan tunggal dan ganda dibedakan
                                        BENTUKNYA - bulat dan kotak - bukan
                                        hanya jumlah yang boleh dipilih.
                                        Ponsel tidak menampilkan kursor, dan
                                        satu-satunya isyarat "boleh lebih dari
                                        satu" yang tersisa adalah bentuk
                                        kotaknya. */}
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
                                        {/* Gambar/audio/video yang menempel
                                            pada pilihan ini tampil DI DALAM
                                            tombolnya - sehingga gambar
                                            pilihan B jelas milik B. Ukurannya
                                            dibatasi supaya tombol tetap bisa
                                            ditekan di ponsel. */}
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
                                                            /* Bukan tautan: <a>
                                                                di dalam <button>
                                                                adalah HTML yang
                                                                tidak sah, dan
                                                                klik tautannya
                                                                tidak boleh ikut
                                                                memilih pilihan
                                                                ini. Berkas non-
                                                                gambar pada opsi
                                                                jarang; namanya
                                                                cukup. */
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

                {/* Isian singkat. `autoCorrect` dan `spellCheck` dimatikan
                    karena koreksi otomatis ponsel mengubah jawaban singkat
                    menjadi kata lain - dan anak melihat jawabannya "salah
                    padahal yang salah adalah ponselnya". */}
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

    // Baris yang tidak disebut urutannya tetap ditampilkan, di belakang.
    // Membuangnya berarti soal yang kehilangan satu pilihan sejak Fase 4
    // tampak sebagai soal yang lebih mudah.
    const sisa = baris.filter((b) => !urutan.includes(String(b[kunci])));

    return [...hasil, ...sisa];
}

/** `content_json` satu blok, atau string kosong. */
function teksDari(blok: Blok | undefined): string {
    return typeof blok?.teks === 'string' ? blok.teks : '';
}

/** Uraian - plate 23, dengan penghitung karakter sisa. */
function Uraian({
    jawaban,
    set,
}: {
    jawaban: Record<string, unknown>;
    set: (p: Record<string, unknown>) => void;
}) {
    const teks = (jawaban.teks as string) ?? '';

    // Batasnya TIDAK datang dari server: `scoring_config_json` tidak memuat
    // `batas_karakter`, dan rubrik - satu-satunya tempat soal bisa membawa
    // batasnya sendiri - sengaja tidak pernah dikirim ke peramban siswa.
    // Yang dipakai karena itu batas teks yang sama dengan yang ditegakkan
    // `SkemaJenisSoal::BATAS_TEKS` saat soal disimpan.
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
                {/* Dinyatakan terbuka, bukan dibiarkan tampak bisa: menempel
                    diblokir penjaga plate 24 di atas, dan anak yang
                    mencobanya perlu tahu bahwa yang menghalangi adalah
                    aturannya, bukan ponselnya yang rusak. */}
                <span className="ml-auto">tempel teks nonaktif</span>
            </div>
        </div>
    );
}

/** Jodohkan dan mengurutkan - plate 23. */
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

        // Belum dijawab: mulainya dari urutan snapshot, bukan dari daftar
        // kosong. Anak yang membuka soal mengurutkan harus melihat itemnya
        // supaya bisa menggesernya - urutan awal yang kosong berarti soal yang
        // tidak bisa dikerjakan sama sekali.
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
                        {/* Tombol naik/turun, bukan geser-seret: menyeret di
                            layar ponsel sambil berdiri adalah gerakan yang
                            paling sering salah, dan salah di sini berarti
                            urutan yang tidak pernah dimaksudkan. */}
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

    // `grup` diisi untuk soal jodoh: `kiri` adalah pernyataannya, `kanan`
    // adalah pilihan pasangannya. Baris tanpa grup ikut ke kiri supaya soal
    // yang butirnya kurang lengkap tetap bisa dikerjakan sebagian, bukan
    // hilang seluruhnya.
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
                    {/* Garis, bukan tanda pisah: yang memisahkan pernyataan
                        dari pilihannya adalah ruang, dan tanda pisah di sini
                        adalah tanda baca yang diketik sebagai kalimat (lantai
                        19). */}
                    <span className="h-px w-3 shrink-0 bg-line" aria-hidden="true" />
                    <select
                        value={pasangan[b.kunci] ?? ''}
                        onChange={(e) =>
                            set({ pasangan: { ...pasangan, [b.kunci]: e.target.value } })
                        }
                        className="w-[45%] rounded-wf border border-line bg-surface px-2 py-1.5 text-[12px] text-ink outline-none focus:border-accent"
                    >
                        {/* `belum dipilih`, bukan `— pilih —`: yang pertama
                            sudah dipakai konsol ini untuk nilai kosong
                            ("belum terdata", "belum ada"), dan yang kedua
                            menuliskan tanda pisah sebagai tanda baca
                            kalimat (lantai 19). */}
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

/** Teks tanpa tag, untuk `<option>` - yang tidak bisa memuat HTML. */
function teksPolos(html: string): string {
    return html.replace(/<[^>]*>/g, '').trim();
}

/* ================================================================ Penunjuk */

/** Jam surut - plate 22 `.timer`, dan ia memanas di bawah lima menit. */
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

/** "Tersimpan" / "Menyimpan…" / "Gagal menyimpan" - plate 22. */
function PenandaSimpan({ keadaan }: { keadaan: 'diam' | 'proses' | 'ok' | 'galat' }) {
    if (keadaan === 'diam') {
        return <span className="text-[11px] text-ink-3">Tersimpan otomatis</span>;
    }

    if (keadaan === 'proses') {
        return <span className="text-[11px] text-ink-3">Menyimpan…</span>;
    }

    if (keadaan === 'galat') {
        // Gagal menyimpan DITAMPILKAN, dan itu kebalikan dari laporan
        // pelanggaran yang gagal dengan diam: kehilangan jawaban adalah hal
        // yang harus diketahui anak saat itu juga, bukan setelah ujian.
        return <Chip nada="bad">Gagal menyimpan</Chip>;
    }

    return <Chip nada="ok">tersimpan</Chip>;
}

/**
 * Id peramban, disimpan di `sessionStorage` tab ini.
 *
 * =====================================================================
 * Menyimpannya di memori saja mengunci ujian pada SETIAP muat ulang
 * =====================================================================
 * Sebelumnya id ini hanya hidup di objek `window`. Muat ulang - `F5`, tab
 * yang dipulihkan peramban ponsel sesudah memorinya habis, layar yang
 * berputar di sebagian peramban - membuat `window` baru, dan idnya lahir
 * lagi secara acak. `DenyutService` membandingkannya dengan yang tersimpan
 * dan menyimpulkan apa yang memang tampak dari luar: PERANGKAT BERUBAH
 * sementara denyut lama masih segar (di bawah 90 detik), jadi ini
 * `sesi_ganda` - dan `sesi_ganda` adalah satu-satunya jenis yang mengunci
 * seketika, `TanggaPelanggaran::SEGERA_KUNCI`.
 *
 * Akibatnya bukan galat yang bisa diabaikan: satu muat ulang, dan anak itu
 * terkunci dari ujiannya sendiri, dengan alasan yang ditampilkan di layar
 * sebagai "Ujian dikunci: Sesi ganda." Tidak ada yang bisa ia lakukan -
 * pengawas pun hanya bisa membukanya lewat jalur pengawas. Dan yang paling
 * sering memicunya bukan kecurangan, melainkan ponsel yang kehabisan
 * memori di tengah ujian - tepat pada perangkat yang paling banyak dipakai.
 *
 * ---------------------------------------------------------------------
 * `sessionStorage`: per tab, dan bertahan melewati muat ulang
 * ---------------------------------------------------------------------
 * Itu persis dua sifat yang dibutuhkan. Dua tab di perangkat yang sama
 * adalah dua sesi penyimpanan yang berbeda, sehingga sesi ganda yang
 * SUNGGUHAN tetap terdeteksi; satu tab yang dimuat ulang tetap satu sesi,
 * sehingga muat ulang tidak lagi dihukum.
 *
 * `localStorage` akan salah di sini: ia dibagi seluruh tab, jadi membuka
 * ujian yang sama di tab kedua akan memakai id yang sama, dan justru
 * penyamaran itulah yang harus dicegah.
 *
 * Kalau `sessionStorage` tidak bisa dibaca - mode privat, penyimpanan
 * diblokir - id sementara di memori tetap dipakai. Ujiannya berjalan; yang
 * hilang cuma ketahanannya melewati muat ulang, dan itu keadaan yang sudah
 * lebih baik daripada menolak anak mengerjakan.
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
        // Penyimpanan diblokir. Jatuh ke memori di bawah - dan id yang
        // tersimpan di wadah itu pun bisa saja sudah ada dari pemanggilan
        // sebelumnya di tab yang sama.
    }

    if (wadah[kunci]) {
        return wadah[kunci];
    }

    const baru = Math.random().toString(36).slice(2, 12);

    wadah[kunci] = baru;

    try {
        window.sessionStorage.setItem(kunci, baru);
    } catch {
        // Idnya tetap berlaku untuk tab ini, hanya tidak melewati muat ulang.
    }

    return baru;
}

import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { konfirmasi } from '@/Components/dialog';
import { Chip, Inbox, Peringatan, Tombol, nadaStatus } from '@/Components/ui';

/**
 * Laman 12 - Ringkasan & kesiapan. Wireframe plate 12.
 *
 * ---------------------------------------------------------------------
 * "Tandai Ready" dirender NONAKTIF, bukan disembunyikan
 * ---------------------------------------------------------------------
 * Wireframe plate 12 menandaskannya: "Admin harus melihat syarat apa yang
 * belum terpenuhi, bukan menebak kenapa tombolnya tidak ada."
 *
 * Tombol yang hilang menghasilkan pertanyaan yang tidak bisa dijawab oleh
 * layar mana pun - Admin melihat event yang jelas belum beres, dan tidak ada
 * tombol apa pun, sehingga ia menyimpulkan laman ini rusak atau ia kurang
 * hak akses. Tombol mati dengan alasan tertulis menghasilkan tindakan.
 *
 * Karena itu tombolnya ada, mati, dan tepat di bawahnya ada daftar syarat
 * dengan TAUTAN PERBAIKAN per baris - menuju laman yang harus dibetulkan.
 *
 * ---------------------------------------------------------------------
 * Tiga keadaan chip, dan yang ketiga paling penting
 * ---------------------------------------------------------------------
 *   ok       - terpenuhi.
 *   belum    - bisa diperbaiki sekarang; gate MENOLAK transisi ke siap.
 *   menunggu - bergantung pada komponen yang belum dibangun (Fase 4-5);
 *              tidak ada tindakan yang bisa diambil, sehingga gate TIDAK
 *              menolaknya.
 *
 * Chip "menunggu" dirender netral - bukan hijau, bukan merah - karena ia
 * bukan kabar baik maupun buruk. Yang salah adalah menganggapnya lulus:
 * angkanya tetap ditulis apa adanya, "9 dari 11 siap", bukan dibulatkan
 * menjadi "semuanya siap".
 */
type Butir = {
    kunci: string;
    label: string;
    keadaan: 'ok' | 'belum' | 'menunggu';
    keterangan: string | null;
    perbaikan: string | null;
};

type Props = {
    event: {
        id: number;
        kode: string;
        nama: string;
        jenis: string | null;
        status: string;
        status_label: string;
        final: boolean;
        periode: string | null;
        tahun: string | null;
        semester: string | null;
        zona_waktu: string | null;
        dibuat_oleh: string | null;
    };
    kesiapan: {
        butir: Butir[];
        lulus: boolean;
        total: number;
        gagal: number;
        menunggu: number;
        siap: number;
    };
    langkah: {
        tujuan: string;
        label: string;
        boleh: boolean;
        alasan: string | null;
        /** Tujuan yang membuka kembali arsip - lihat EventStatusService. */
        pemulihan: boolean;
    }[];
    angka: {
        peserta: number;
        kelas: number;
        mapel: number;
        ruang: number;
        pengawas: number;
        ujian: number;
    };
};

export default function EventShow({ event, kesiapan, langkah, angka }: Props) {
    const [tab, setTab] = useState<'ringkasan' | 'kesiapan'>('ringkasan');

    const pindah = (tujuan: string) =>
        router.post(`/admin/events/${event.id}/status`, { status: tujuan }, { preserveScroll: true });

    /**
     * Memulihkan event dari arsip DITANYAKAN lebih dulu, dan itu bukan
     * kehati-hatian berlebih.
     *
     * Pengarsipan adalah tindakan yang mungkin sudah dibaca orang lain -
     * papan pengumuman, laporan tercetak, dan kebiasaan wali kelas yang
     * berhenti mencari event itu. Membukanya kembali diam-diam mengembalikan
     * event ke layar semua orang, dan satu-satunya orang yang perlu tahu
     * bahwa itu disengaja adalah Admin yang menekannya.
     */
    const pulihkan = async (tujuan: string, label: string) => {
        const setuju = await konfirmasi({
            judul: `Buka kembali event ini ke ${label}?`,
            pesan:
                'Event ini keluar dari arsip dan muncul lagi di daftar, papan, dan laporan ' +
                'sesuai status barunya. Riwayat ujian, nilai, dan berita acara tidak berubah.',
            labelYa: `Pindahkan ke ${label}`,
            nada: 'bahaya',
        });

        if (setuju) {
            pindah(tujuan);
        }
    };

    const ready = langkah.find((l) => l.tujuan === 'siap');

    // Langkah biasa dan langkah PEMULIHAN dipisah: yang kedua bukan
    // kelanjutan alur, melainkan pembatalan arsip. Mencampurnya membuat lima
    // tombol berdiri sejajar tanpa urutan yang masuk akal.
    const biasa = langkah.filter((l) => !l.pemulihan && l.tujuan !== 'siap' && l.tujuan !== 'draft');
    const pemulihan = langkah.filter((l) => l.pemulihan && l.tujuan !== 'draft');

    return (
        <AdminLayout
            judul={event.nama}
            breadcrumb={`/admin/events · ${event.kode} · ${event.status_label}`}
            aksi={
                <>
                    <Link href={`/admin/events/${event.id}/ubah`}>
                        <Tombol>Ubah</Tombol>
                    </Link>

                    {/* Tombol "Tandai Ready" - selalu dirender bila transisinya
                        memang tersedia dari status ini, dan dimatikan bila
                        syaratnya belum lengkap. */}
                    {ready && (
                        <Tombol
                            nada="pri"
                            disabled={!ready.boleh}
                            title={ready.alasan ?? undefined}
                            onClick={() => pindah('siap')}
                        >
                            Tandai Siap
                        </Tombol>
                    )}

                    {/*
                     * Kelompok PEMULIHAN dari arsip - ditambahkan 2026-11-14.
                     *
                     * Ia dirender sebagai deret tersendiri dengan satu
                     * kalimat pengantar, karena lima tombol "Draft /
                     * Persiapan / Siap / Berjalan / Selesai" tanpa konteks
                     * terbaca sebagai lima langkah maju. Yang sedang
                     * dikerjakan Admin di sini adalah MEMBATALKAN
                     * pengarsipan.
                     */}
                    {pemulihan.length > 0 && (
                        <span className="flex flex-wrap items-center gap-1 border-l border-line pl-2">
                            <span className="text-[11px] text-ink-3">Buka kembali:</span>
                            {pemulihan.map((l) => (
                                <Tombol
                                    key={l.tujuan}
                                    nada="sek"
                                    disabled={!l.boleh}
                                    title={l.alasan ?? undefined}
                                    onClick={() => {
                                        void pulihkan(l.tujuan, l.label);
                                    }}
                                >
                                    {l.label}
                                </Tombol>
                            ))}
                        </span>
                    )}

                    {/* Transisi lain - batal, mulai, selesaikan, arsipkan -
                        diturunkan dari graf status di server, bukan ditulis
                        ulang di sini. Menambah satu transisi kelak berarti
                        mengubah satu berkas di Domain/Event/Enums, dan laman
                        ini ikut begitu saja. */}
                    {biasa.map((l) => (
                        <Tombol
                            key={l.tujuan}
                            nada={l.tujuan === 'batal' ? 'bahaya' : 'sek'}
                            disabled={!l.boleh}
                            title={l.alasan ?? undefined}
                            onClick={() => pindah(l.tujuan)}
                        >
                            {l.label}
                        </Tombol>
                    ))}
                </>
            }
        >
            <Head title={event.nama} />

            {/* Peringatan status buntu - dan sejak 2026-11-14 hanya Batal.
                Arsip bisa dibuka kembali, sehingga kalimat "tidak bisa
                dipindahkan lagi" untuk arsip sudah tidak benar dan harus
                hilang bersama aturannya. Yang tetap perlu dibaca Admin
                adalah bahwa komponennya masih terbaca utuh - itu yang
                membedakan "buntu" dari "hilang". */}
            {event.final && (
                <div className="mb-4">
                    <Peringatan>
                        Event berstatus <strong>{event.status_label}</strong> bersifat buntu.
                        Seluruh komponennya tetap bisa dibaca, tetapi statusnya tidak bisa
                        dipindahkan lagi &mdash; dan itu disengaja: event yang dibatalkan tidak
                        pernah dijalankan, sehingga tidak ada keadaan &ldquo;sebelumnya&rdquo; yang
                        benar untuk dikembalikan. Yang dibutuhkan sesudahnya adalah event
                        pengganti, bukan membangkitkan yang sudah dibatalkan.
                    </Peringatan>
                </div>
            )}

            <div className="mb-3 flex flex-wrap items-center gap-2">
                {(['ringkasan', 'kesiapan'] as const).map((t) => (
                    <button
                        key={t}
                        onClick={() => setTab(t)}
                        className={
                            'rounded-wf border px-3 py-1.5 text-xs font-medium capitalize ' +
                            (tab === t
                                ? 'border-accent bg-accent-soft text-accent'
                                : 'border-line bg-surface text-ink-2 hover:bg-surface-2')
                        }
                    >
                        {t}
                        {t === 'kesiapan' && (
                            <span className="ml-1.5 font-mono">
                                {kesiapan.total - kesiapan.gagal}/{kesiapan.total}
                            </span>
                        )}
                    </button>
                ))}
            </div>

            <div className="mb-3 grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
                <KPI label="Peserta" nilai={angka.peserta} />
                <KPI label="Ujian" nilai={angka.ujian} fase="Fase 5" />
                <KPI label="Kelas" nilai={angka.kelas} />
                <KPI label="Ruang" nilai={angka.ruang} />
                <KPI label="Pengawas" nilai={angka.pengawas} />
            </div>

            {tab === 'ringkasan' ? (
                <div className="grid gap-4 lg:grid-cols-[1fr_20rem]">
                    <div className="space-y-4">
                        <div className="rounded-wf border border-line bg-surface">
                            <header className="flex items-center gap-3 border-b border-line px-4 py-2.5">
                                <h2 className="font-display text-sm font-semibold text-ink">
                                    Kesiapan {kesiapan.siap + kesiapan.menunggu} / {kesiapan.total}
                                </h2>
                                <span className="ml-auto flex gap-1.5">
                                    {kesiapan.gagal > 0 && (
                                        <Chip nada="bad">{kesiapan.gagal} belum</Chip>
                                    )}
                                    {kesiapan.menunggu > 0 && (
                                        <Chip nada="mute">{kesiapan.menunggu} menunggu</Chip>
                                    )}
                                </span>
                            </header>

                            <BarisKesiapan butir={kesiapan.butir} ringkas />
                        </div>

                        <Inbox>
                            Komponen event disusun berurutan: kelas peserta dulu, lalu peserta
                            ditarik dari kelas itu, mapel ditetapkan, ruang dialokasikan, dan
                            pengawas ditugaskan. Tiap langkah punya lamanya sendiri di sidebar
                            event.
                        </Inbox>
                    </div>

                    <div className="space-y-4">
                        <div className="rounded-wf border border-line bg-surface p-4">
                            <h2 className="mb-3 font-display text-sm font-semibold text-ink">
                                Identitas
                            </h2>
                            <dl className="space-y-2 text-sm">
                                <Baris label="Kode" nilai={event.kode} mono />
                                <Baris label="Jenis" nilai={event.jenis ?? '-'} />
                                <Baris label="Periode" nilai={event.periode ?? '—'} mono />
                                <Baris
                                    label="Tahun ajaran"
                                    nilai={[event.tahun, event.semester].filter(Boolean).join(' · ') || '-'}
                                />
                                <Baris label="Zona waktu" nilai={event.zona_waktu ?? '-'} mono />
                                <Baris label="Dibuat oleh" nilai={event.dibuat_oleh ?? '-'} />
                                <div className="flex items-center gap-2 pt-1">
                                    <dt className="text-xs text-ink-3">Status</dt>
                                    <dd className="ml-auto">
                                        <Chip nada={nadaStatus(event.status)}>
                                            {event.status_label}
                                        </Chip>
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                </div>
            ) : (
                <BarisKesiapan butir={kesiapan.butir} />
            )}
        </AdminLayout>
    );
}

function BarisKesiapan({ butir, ringkas }: { butir: Butir[]; ringkas?: boolean }) {
    // Pada tab Ringkasan hanya lima baris pertama ditampilkan - daftar penuh
    // ada di tab Kesiapan. Ringkasan yang menampilkan sebelas baris berhenti
    // menjadi ringkasan, dan Admin kehilangan gambaran besarnya.
    const tampil = ringkas ? butir.slice(0, 5) : butir;

    return (
        <div className="divide-y divide-line/60">
            {tampil.map((b) => (
                <div key={b.kunci} className="flex flex-wrap items-center gap-2 px-4 py-2.5">
                    <span className="min-w-0 flex-1 text-sm text-ink">
                        {b.label}
                        {b.keterangan && (
                            <span className="ml-2 text-xs text-ink-3">· {b.keterangan}</span>
                        )}
                    </span>

                    {/* Baris yang gagal menunjuk langsung ke laman yang harus
                        diperbaiki. Tanpa tautannya, daftar ini hanya memberi
                        tahu ADA yang salah, bukan di mana. */}
                    {b.perbaikan && (
                        <Link
                            href={b.perbaikan}
                            className="text-xs text-accent hover:underline"
                        >
                            Perbaiki
                        </Link>
                    )}

                    <Chip
                        nada={
                            b.keadaan === 'ok'
                                ? 'ok'
                                : b.keadaan === 'belum'
                                  ? 'bad'
                                  : 'mute'
                        }
                    >
                        {b.keadaan === 'ok' ? 'ok' : b.keadaan === 'belum' ? 'belum' : 'menunggu'}
                    </Chip>
                </div>
            ))}

            {ringkas && butir.length > tampil.length && (
                <p className="px-4 py-2 text-[11px] text-ink-3">
                    {butir.length - tampil.length} butir lainnya ada di tab Kesiapan.
                </p>
            )}
        </div>
    );
}

function KPI({ label, nilai, fase }: { label: string; nilai: number; fase?: string }) {
    return (
        <div className="rounded-wf border border-line bg-surface px-4 py-3">
            <p className="flex items-center gap-1.5 text-xs text-ink-3">
                {label}
                {fase && <span className="font-mono text-[9px] text-ink-3/70">{fase}</span>}
            </p>
            <p className="mt-1 font-mono text-2xl font-medium text-ink">
                {nilai.toLocaleString('id-ID')}
            </p>
        </div>
    );
}

function Baris({ label, nilai, mono }: { label: string; nilai: string; mono?: boolean }) {
    return (
        <div className="flex items-center gap-2">
            <dt className="text-xs text-ink-3">{label}</dt>
            <dd className={'ml-auto text-ink ' + (mono ? 'font-mono text-xs' : 'text-sm')}>
                {nilai}
            </dd>
        </div>
    );
}

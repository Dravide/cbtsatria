import { Head, Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Chip, Inbox, Kotak, Peringatan, Tombol } from '@/Components/ui';

/**
 * Peluncuran ujian - ditambahkan 2026-11-19.
 *
 * =====================================================================
 * Laman ini menjawab "apa lagi yang kurang", bukan "nyalakan"
 * =====================================================================
 * Tombol besar yang mengerjakan tujuh langkah sekaligus sudah dipertimbangkan
 * dan ditolak: kegagalan di tengahnya meninggalkan setengah ujian dalam
 * keadaan yang lebih sulit dibaca daripada sebelumnya, dan ia tidak menjawab
 * pertanyaan yang sebenarnya Admin punya - "mengapa belum bisa".
 *
 * Karena itu bentuknya EMPAT LANGKAH BERURUTAN, masing-masing memuat butir
 * yang menjadi syaratnya beserta tautan perbaikannya. Urutannya bukan
 * hiasan: peserta sebelum paket, paket sebelum sesi, sesi sebelum token, dan
 * semuanya sebelum "Tandai Siap".
 *
 * =====================================================================
 * Langkah TERTAHAN bukan langkah gagal
 * =====================================================================
 * Langkah yang belum bisa dikerjakan karena langkah sebelumnya belum lulus
 * ditandai `tertahan` - abu-abu, bukan merah. Merah berarti "ada yang salah
 * di sini", dan yang salah ada di atasnya. Menyamakan keduanya membuat Admin
 * memperbaiki baris yang bukan penyebabnya.
 *
 * =====================================================================
 * Aksi massal melaporkan hasil BERPERANGKAT
 * =====================================================================
 * "11 sesi disiapkan, 1 ditolak karena paket Matematika kosong" adalah satu-
 * satunya bentuk yang berguna. Pesan "gagal" akan membuat Admin menekan
 * tombol yang sama lagi dan membaca pesan yang sama.
 */
type Butir = {
    kunci: string;
    label: string;
    keadaan: 'ok' | 'belum' | 'menunggu';
    keterangan: string | null;
    perbaikan: string | null;
};

type Aksi = { label: string; href?: string; aksi?: 'siapkan' | 'buka' | 'siap' };

type Langkah = {
    nomor: number;
    kunci: string;
    label: string;
    keadaan: 'ok' | 'belum' | 'menunggu' | 'tertahan';
    butir: Butir[];
    aksi: Aksi[];
    draf_sesi?: number;
};

type Props = {
    event: {
        id: number;
        kode: string;
        nama: string;
        status: string;
        status_label: string;
        final: boolean;
    };
    kesiapan: { lulus: boolean; gagal: number; menunggu: number; total: number };
    langkah: Langkah[];
    langkah_status: {
        tujuan: string;
        label: string;
        boleh: boolean;
        alasan: string | null;
        pemulihan: boolean;
    }[];
    sesi: { total: number; draft: number; siap: number; berjalan: number };
    ujian: number;
};

/** Nada chip untuk satu keadaan langkah. */
function nadaLangkah(keadaan: Langkah['keadaan']): 'ok' | 'bad' | 'warn' | 'mute' {
    return keadaan === 'ok'
        ? 'ok'
        : keadaan === 'belum'
          ? 'bad'
          : keadaan === 'menunggu'
            ? 'warn'
            : 'mute';
}

function labelLangkah(keadaan: Langkah['keadaan']): string {
    return keadaan === 'ok'
        ? 'beres'
        : keadaan === 'belum'
          ? 'kurang'
          : keadaan === 'menunggu'
            ? 'menunggu'
            : 'tertahan';
}

export default function EventPeluncuran({
    event,
    kesiapan,
    langkah,
    langkah_status,
    sesi,
    ujian,
}: Props) {
    const kirim = (aksi: 'siapkan' | 'buka' | 'siap') => {
        if (aksi === 'siap') {
            // "Tandai Siap" memakai endpoint status yang SUDAH ADA, bukan
            // rute baru: gerbang kesiapannya, jejak aktivitasnya, dan
            // transisi sesudahnya semuanya di sana. Rute kedua untuk
            // pekerjaan yang sama adalah dua tempat yang akan berbeda.
            router.post(
                `/admin/events/${event.id}/status`,
                { status: 'siap' },
                { preserveScroll: true },
            );

            return;
        }

        router.post(`/admin/events/${event.id}/peluncuran/${aksi}`, {}, { preserveScroll: true });
    };

    const totalLangkah = langkah.length;
    const lulus = langkah.filter((l) => l.keadaan === 'ok').length;
    const terbuka = langkah.find((l) => l.keadaan !== 'ok' && l.keadaan !== 'tertahan');

    return (
        <AdminLayout
            judul="Peluncuran ujian"
            breadcrumb={`/admin/events · ${event.kode} · Peluncuran`}
            aksi={
                <>
                    <Link href={`/admin/events/${event.id}/jadwal`}>
                        <Tombol>Jadwal &amp; sesi</Tombol>
                    </Link>
                    <Link href={`/admin/events/${event.id}`}>
                        <Tombol>Ringkasan</Tombol>
                    </Link>
                </>
            }
        >
            <Head title={`Peluncuran · ${event.nama}`} />

            <div className="mb-3 flex flex-wrap items-center gap-2">
                <Chip nada="mute">{event.kode}</Chip>
                <Chip nada={event.final ? 'bad' : 'info'}>{event.status_label}</Chip>
                <span className="text-xs text-ink-3">
                    {lulus} dari {totalLangkah} langkah · {ujian} ujian · {sesi.total} sesi
                </span>
                {kesiapan.gagal > 0 && (
                    <Chip nada="bad">
                        {kesiapan.gagal} dari {kesiapan.total} syarat belum
                    </Chip>
                )}
                {kesiapan.gagal === 0 && kesiapan.menunggu > 0 && (
                    <Chip nada="warn">{kesiapan.menunggu} syarat menunggu</Chip>
                )}
            </div>

            {event.final && (
                <div className="mb-3">
                    <Peringatan>
                        Event berstatus <strong>{event.status_label}</strong> tidak bisa diluncurkan
                        lagi. Komponennya tetap terbaca, tetapi tidak ada sesi baru yang bisa dibuka.
                    </Peringatan>
                </div>
            )}

            {!event.final && lulus === totalLangkah && sesi.siap === 0 && sesi.berjalan > 0 && (
                <div className="mb-3">
                    <Inbox>
                        Seluruh sesi sudah dibuka. Siswa bisa masuk pada jam yang dijadwalkan
                        masing-masing sesi — membuka sesi lebih awal tidak mempercepat jamnya.
                    </Inbox>
                </div>
            )}

            {terbuka !== undefined && (
                <div className="mb-3">
                    <Inbox>
                        Langkah berikutnya: <strong>{terbuka.label}</strong>.{' '}
                        {terbuka.keadaan === 'belum'
                            ? 'Ada syarat yang bisa diperbaiki sekarang — rinciannya di bawah.'
                            : 'Menunggu komponen yang belum ada; tidak ada yang bisa dikerjakan di sini.'}
                    </Inbox>
                </div>
            )}

            <div className="grid gap-3">
                {langkah.map((l) => (
                    <section
                        key={l.kunci}
                        className={
                            'rounded-wf border bg-surface ' +
                            (l.keadaan === 'ok'
                                ? 'border-line'
                                : l.keadaan === 'belum'
                                  ? 'border-bad/40'
                                  : 'border-line')
                        }
                    >
                        <header className="flex flex-wrap items-center gap-3 border-b border-line px-4 py-2.5">
                            <span
                                className={
                                    'grid h-6 w-6 place-items-center rounded-full font-mono text-[11px] ' +
                                    (l.keadaan === 'ok'
                                        ? 'bg-ok-bg text-ok'
                                        : l.keadaan === 'belum'
                                          ? 'bg-bad-bg text-bad'
                                          : 'bg-surface-2 text-ink-3')
                                }
                            >
                                {l.nomor}
                            </span>
                            <h2 className="font-display text-sm font-semibold text-ink">
                                {l.label}
                            </h2>
                            <Chip nada={nadaLangkah(l.keadaan)}>{labelLangkah(l.keadaan)}</Chip>

                            <div className="ml-auto flex flex-wrap gap-2">
                                {l.aksi.map((a) =>
                                    a.href !== undefined ? (
                                        <Link key={a.label} href={a.href}>
                                            <Tombol nada="sek">{a.label}</Tombol>
                                        </Link>
                                    ) : (
                                        <Tombol
                                            key={a.label}
                                            nada={a.aksi === 'buka' ? 'pri' : 'sek'}
                                            onClick={() => kirim(a.aksi as 'siapkan' | 'buka' | 'siap')}
                                        >
                                            {a.label}
                                        </Tombol>
                                    ),
                                )}
                            </div>
                        </header>

                        {l.butir.length > 0 && (
                            <ul className="divide-y divide-line">
                                {l.butir.map((b) => (
                                    <li
                                        key={b.kunci}
                                        className="flex flex-wrap items-baseline gap-x-3 gap-y-1 px-4 py-2"
                                    >
                                        <Chip
                                            nada={
                                                b.keadaan === 'ok'
                                                    ? 'ok'
                                                    : b.keadaan === 'belum'
                                                      ? 'bad'
                                                      : 'mute'
                                            }
                                        >
                                            {b.keadaan === 'ok'
                                                ? 'ok'
                                                : b.keadaan === 'belum'
                                                  ? 'belum'
                                                  : 'menunggu'}
                                        </Chip>
                                        <span className="text-xs font-medium text-ink">
                                            {b.label}
                                        </span>
                                        {b.keterangan !== null && (
                                            <span className="text-[11px] text-ink-3">
                                                {b.keterangan}
                                            </span>
                                        )}
                                        {b.perbaikan !== null && b.keadaan === 'belum' && (
                                            <Link
                                                href={b.perbaikan}
                                                className="ml-auto text-[11px] font-medium text-accent hover:underline"
                                            >
                                                Perbaiki
                                            </Link>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}

                        {l.keadaan === 'tertahan' && (
                            <div className="border-t border-line px-4 py-2">
                                <p className="text-[11px] text-ink-3">
                                    Menunggu langkah di atasnya lulus. Gerbang &ldquo;Tandai
                                    Siap&rdquo; menolak selama masih ada syarat yang belum
                                    terpenuhi.
                                </p>
                            </div>
                        )}
                    </section>
                ))}
            </div>

            <div className="mt-3">
                <Kotak judul="Transisi status event">
                    <p className="mb-2 text-[11px] leading-relaxed text-ink-3">
                        Tombol di bawah memindahkan status event tanpa memeriksa komponennya —
                        kecuali &ldquo;Siap&rdquo;, yang gerbangnya adalah seluruh syarat di atas.
                        Arsip bisa dibuka kembali; Batal tidak.
                    </p>
                    <div className="flex flex-wrap gap-2">
                        {langkah_status.map((t) => (
                            <Tombol
                                key={t.tujuan}
                                nada={t.tujuan === 'batal' ? 'bahaya' : 'sek'}
                                disabled={!t.boleh}
                                title={t.alasan ?? undefined}
                                onClick={() => kirimStatus(event.id, t.tujuan)}
                            >
                                {t.label}
                            </Tombol>
                        ))}
                    </div>
                </Kotak>
            </div>

            <div className="mt-3">
                <p className="text-[11px] leading-relaxed text-ink-3">
                    Membuka sesi <strong>mengizinkan</strong> pintu, bukan membukanya lebih awal:
                    siswa tetap ditolak sampai jam akses sesinya tiba. Status event berpindah ke
                    Berjalan sendiri saat sesi pertama dibuka — selama eventnya sudah
                    <strong> Siap</strong>. Tanpa itu, sesinya berjalan sementara eventnya
                    tertinggal, dan laman ini akan mengatakannya.
                </p>
            </div>
        </AdminLayout>
    );
}

function kirimStatus(id: number, tujuan: string) {
    router.post(`/admin/events/${id}/status`, { status: tujuan }, { preserveScroll: true });
}

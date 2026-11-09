import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Chip, Inbox, Kotak, Modal, Peringatan, Tabel, Tombol } from '@/Components/ui';

/**
 * Laman 16b - Penempatan peserta ke ruang. Wireframe plate 16b, PRD §9.5.
 *
 * ---------------------------------------------------------------------
 * Layar ini menampilkan APA YANG AKAN terjadi, bukan apa yang tersimpan
 * ---------------------------------------------------------------------
 * Seluruh isinya disusun server dengan menjalankan ulang kocokan dari
 * benihnya, bukan dengan membaca `exam_participants.exam_session_room_id`.
 * Itu yang membuat pratinjau mungkin: sebelum Admin menekan Sebar, kursinya
 * belum ada di basis data - dan justru itulah saat ia masih bisa memilih.
 *
 * Konsekuensinya terlihat di sini: tidak ada satu pun state lokal yang
 * menghitung ruang. Kalau React menghitungnya sendiri, ia dan server bisa
 * menghasilkan dua kocokan yang berbeda dari benih yang sama, dan yang salah
 * baru ketahuan saat siswa berdiri di depan pintu.
 *
 * ---------------------------------------------------------------------
 * SATU peta ruang untuk seluruh event
 * ---------------------------------------------------------------------
 * Siswa yang menghafal "saya di R-03" benar untuk SETIAP mapel, bukan hanya
 * untuk mapel pertama - dan itu yang membuat pengumuman di depan pintu bisa
 * dibaca sekali lalu berlaku sampai ujian selesai.
 *
 * Yang dibayar sebagai gantinya terlihat di tabel bawah: satu peta event hanya
 * bisa dipakai sesi yang memuat SELURUH ruang event. Karena itu tabelnya bukan
 * daftar jadwal, melainkan DAFTAR KELAYAKAN - setiap barisnya menjawab "sesi
 * ini bisa memakai petanya atau tidak, dan kalau tidak, mengapa".
 *
 * ---------------------------------------------------------------------
 * Tiga tombol, dan bedanya nyata
 * ---------------------------------------------------------------------
 *   Pratinjau  - menampilkan peta ruang dari sudut satu sesi. Tidak menulis apa pun.
 *   Sebar      - menulis kursinya untuk SELURUH sesi, memakai benih yang SUDAH
 *                tertulis di layar.
 *   Kocok ulang- benih BARU, lalu sebar - membatalkan daftar cetak semua mapel.
 *
 * "Sebar" mengirim benih yang sedang ditampilkan, sehingga siswa tidak
 * berpindah ruang hanya karena Admin membuka halamannya lagi atau menekan
 * tombol yang sama dua kali. "Kocok ulang" sengaja aksi terpisah dengan
 * konfirmasi: ia membatalkan seluruh daftar yang sudah dicetak.
 */
type PesertaPratinjau = {
    id: number;
    nis: string | null;
    nama: string | null;
    kelas: string;
};

type RuangPratinjau = {
    id: number;
    kode: string;
    nama: string | null;
    kapasitas: number;
    /** Kapasitas yang berlaku di sesi yang sedang dilihat; null bila sesi itu tidak memakainya. */
    kapasitas_sesi: number | null;
    dipakai_sesi: boolean;
    terisi: number;
    peserta: PesertaPratinjau[];
};

type Baris = {
    sesi_id: number;
    ujian_id: number;
    ujian: string | null;
    sesi: string;
    tanggal: string | null;
    jam: string | null;
    ruang: number;
    peserta: number;
    bisa: boolean;
    sebab: string | null;
};

type Pratinjau = {
    sesi_id: number;
    label: string;
    ujian: string | null;
    benih: string;
    ruang: RuangPratinjau[];
};

type Props = {
    event: { id: number; kode: string; nama: string; status: string; status_label: string };
    laman: {
        benih: string;
        terkunci: boolean;
        dikocok_pada: string | null;
        dikocok_oleh: string | null;
        peserta: number;
        kapasitas: number;
        kurang: number;
        ruang: number;
        seisi_satu_kelas: number;
        /** Ada bila eventnya belum punya ruang sama sekali. */
        pesan: string | null;
        bisa_sebar: boolean;
    };
    baris: Baris[];
    pratinjau: Pratinjau | null;
    angka: {
        peserta_event: number;
        belum_ditempatkan: number;
        sesi: number;
        bisa: number;
    };
};

export default function EventPenempatan({ event, laman, baris, pratinjau, angka }: Props) {
    const jalur = `/admin/events/${event.id}`;

    // Konfirmasi, bukan aksi langsung: kocok ulang membatalkan daftar ruang
    // yang sudah dicetak untuk SETIAP mapel, dan yang membatalkannya harus
    // berupa keputusan yang terlihat.
    const [konfirmasi, setKonfirmasi] = useState(false);

    const bermasalah = baris.filter((b) => !b.bisa);

    return (
        <AdminLayout
            judul="Penempatan peserta ke ruang"
            breadcrumb={`/admin/events · ${event.kode} · Penempatan`}
            aksi={
                <span className="flex items-center gap-2 rounded-wf border border-line bg-surface px-2.5 py-1.5 text-xs text-ink-2">
                    <span className="text-ink-3">Event:</span>
                    <span className="font-medium text-ink">{event.nama}</span>
                </span>
            }
        >
            <Head title={`Penempatan · ${event.nama}`} />

            <div className="mb-3 grid gap-3 sm:grid-cols-4">
                <KPI label="Peserta event" nilai={angka.peserta_event} catatan={`${laman.peserta} sudah punya kursi`} />
                <KPI
                    label="Kursi seluruh ruang"
                    nilai={laman.kapasitas}
                    nada={laman.kurang > 0 ? 'bad' : undefined}
                    catatan={laman.kurang > 0 ? `kurang ${laman.kurang} kursi` : `${laman.ruang} ruang`}
                />
                <KPI
                    label="Seisi satu kelas"
                    nilai={laman.seisi_satu_kelas}
                    nada={laman.seisi_satu_kelas > 0 ? 'warn' : 'ok'}
                    catatan="ruang yang seluruh pesertanya sekelas"
                />
                <KPI
                    label="Belum ketempatan"
                    nilai={angka.belum_ditempatkan}
                    nada={angka.belum_ditempatkan > 0 ? 'bad' : 'ok'}
                />
            </div>

            {laman.pesan ? (
                <div className="mb-3">
                    <Peringatan>{laman.pesan}</Peringatan>
                </div>
            ) : (
                pratinjau && (
                    <div className="mb-3">
                        <Kotak
                            judul={`Pratinjau peta ruang event · dilihat dari ${pratinjau.label}`}
                            aksi={
                                <>
                                    <Chip nada={laman.terkunci ? 'ok' : 'mute'}>
                                        {laman.terkunci ? 'terkunci' : 'belum disebar'}
                                    </Chip>
                                    <Chip nada="info">benih #{laman.benih}</Chip>
                                    <Tombol
                                        nada="sek"
                                        disabled={!laman.terkunci}
                                        title={
                                            laman.terkunci
                                                ? 'Benih baru - daftar ruang yang sudah dicetak tidak berlaku lagi'
                                                : 'Belum ada kocokan untuk dikocok ulang'
                                        }
                                        onClick={() => setKonfirmasi(true)}
                                    >
                                        Kocok ulang
                                    </Tombol>
                                    <Tombol
                                        nada="pri"
                                        disabled={!laman.bisa_sebar}
                                        title={
                                            laman.bisa_sebar
                                                ? 'Sebar kocokan ini ke seluruh sesi'
                                                : 'Belum bisa disebar - lihat daftar sesi di bawah'
                                        }
                                        onClick={() => sebar(jalur, laman.benih)}
                                    >
                                        {laman.terkunci ? 'Sebar ulang' : 'Sebar peserta'}
                                    </Tombol>
                                </>
                            }
                        >
                            <div className="mb-3 flex flex-wrap gap-2">
                                {pratinjau.ruang.map((r) => (
                                    <span
                                        key={r.id}
                                        className={
                                            'rounded-wf border px-2 py-1 font-mono text-[11px] ' +
                                            (r.dipakai_sesi
                                                ? 'border-line bg-surface text-ink-2'
                                                : 'border-bad/40 bg-bad-bg text-bad')
                                        }
                                        title={
                                            r.dipakai_sesi
                                                ? `${r.nama ?? ''} · ${r.terisi} dari ${r.kapasitas} kursi`
                                                : `${r.nama ?? ''} · sesi ${pratinjau.label} tidak memakai ruang ini`
                                        }
                                    >
                                        {r.kode} {r.terisi}/{r.kapasitas}
                                        {r.kapasitas_sesi !== null && r.kapasitas_sesi !== r.kapasitas && (
                                            <span className="ml-1 text-warn">→{r.kapasitas_sesi}</span>
                                        )}
                                    </span>
                                ))}
                            </div>

                            <RincianRuang ruang={pratinjau.ruang} />

                            <p className="mt-3 text-[11px] leading-relaxed text-ink-3">
                                Peta ini berlaku untuk <strong className="text-ink-2">seluruh event</strong>:
                                seorang siswa memegang satu ruang yang sama sepanjang ujian. Benih yang sama
                                selalu menghasilkan susunan yang sama, sehingga siswa tidak berpindah ruang
                                hanya karena halaman ini dibuka lagi.{' '}
                                <strong className="text-ink-2">Kocok ulang</strong> membuat benih baru &mdash;
                                daftar ruang yang sudah dicetak tidak berlaku lagi.
                                {laman.terkunci && (
                                    <>
                                        {' '}
                                        Kocokan ini dibekukan{' '}
                                        {laman.dikocok_pada}
                                        {laman.dikocok_oleh ? ` oleh ${laman.dikocok_oleh}` : ''}.
                                    </>
                                )}
                            </p>
                        </Kotak>
                    </div>
                )
            )}

            <Kotak judul="Sesi ujian - kelayakan memakai peta ruang event">
                <Tabel<Baris>
                    hal={{ data: baris, total: baris.length }}
                    kunci={(b) => `${b.sesi_id}`}
                    kosong="Belum ada sesi. Susun jadwalnya di laman Jadwal & sesi terlebih dahulu."
                    kolom={[
                        {
                            judul: 'Ujian · sesi',
                            render: (b) => (
                                <span>
                                    <span className="text-sm text-ink">{b.sesi}</span>
                                    <span className="ml-2 text-xs text-ink-2">{b.ujian}</span>
                                    <span className="ml-2 text-[11px] text-ink-3">
                                        {b.tanggal} {b.jam}
                                    </span>
                                </span>
                            ),
                        },
                        {
                            judul: 'Kursi sesi',
                            kelas: 'w-28',
                            render: (b) => <span className="font-mono text-xs text-ink-2">{b.ruang}</span>,
                        },
                        {
                            judul: 'Peserta',
                            kelas: 'w-24',
                            render: (b) => <span className="font-mono text-xs text-ink-2">{b.peserta}</span>,
                        },
                        {
                            judul: 'Kelayakan',
                            render: (b) =>
                                b.bisa ? (
                                    <span className="text-xs text-ok">siap memakai peta</span>
                                ) : (
                                    <span className="text-xs text-bad">{b.sebab}</span>
                                ),
                        },
                        {
                            judul: '',
                            kelas: 'w-28',
                            render: (b) => (
                                <span className="flex justify-end">
                                    <Tombol
                                        nada="sek"
                                        title="Tampilkan peta ruang dari sudut sesi ini"
                                        onClick={() => bukaPratinjau(jalur, b.sesi_id)}
                                    >
                                        Lihat
                                    </Tombol>
                                </span>
                            ),
                        },
                    ]}
                />
            </Kotak>

            {bermasalah.length > 0 && (
                <div className="mt-3">
                    <Peringatan>
                        {bermasalah.length} sesi belum bisa memakai peta ruang event, sehingga
                        penyebaran ditolak seluruhnya &mdash; bukan diterapkan sebagian. Perbaiki
                        ruangnya di laman <strong>Jadwal &amp; sesi</strong> agar setiap sesi memakai
                        seluruh ruang event, lalu sebar lagi.
                    </Peringatan>
                </div>
            )}

            {!laman.terkunci && bermasalah.length === 0 && (
                <div className="mt-3">
                    <Inbox>
                        Kocokan ini belum disebar. Selama belum, susunan yang terlihat di pratinjau
                        masih boleh berubah sebanyak apa pun &mdash; itu gunanya pratinjau. Sesudah
                        disebar, satu-satunya cara mengubahnya adalah <strong>Kocok ulang</strong>,
                        dan itu membatalkan seluruh daftar ruang yang sudah dicetak.
                    </Inbox>
                </div>
            )}

            <div className="mt-3">
                <Kotak judul="Cara mengocok">
                    <div className="space-y-1.5 text-xs text-ink-2">
                        <Baris>Satu peta untuk seluruh event &mdash; siswa hafal satu ruang, berlaku untuk semua mapelnya</Baris>
                        <Baris>Rotasi antar kelas &mdash; satu dari X-1, satu dari XI-2, satu dari XII-1, lalu ulang</Baris>
                        <Baris>Urutannya diacak berbenih; benih disimpan, sehingga halaman ini bisa dibuka ulang dan isinya sama persis</Baris>
                        <Baris>Kocokan dibekukan saat disebar. Daftar ruang yang sudah dicetak tetap cocok dengan layarnya</Baris>
                        <Baris>Setiap sesi harus memakai seluruh ruang event &mdash; sesi yang ruangnya kurang ditolak, bukan ditambal</Baris>
                    </div>
                </Kotak>
            </div>

            {/* Konfirmasi kocok ulang. Bukan kehati-hatian berlebihan: satu
                klik di sini membuat 240 nama berpindah kursi di SETIAP mapel,
                dan daftar yang sudah ditempel di pintu berubah menjadi salah. */}
            <Modal
                judul="Kocok ulang seluruh event?"
                terbuka={konfirmasi}
                padaTutup={() => setKonfirmasi(false)}
            >
                <Peringatan>
                    Siswa akan berpindah ruang <strong>di semua mapel</strong>. Daftar ruang yang
                    sudah dicetak dan ditempel di pintu <strong>tidak berlaku lagi</strong> &mdash;
                    cetak ulang sesudahnya.
                </Peringatan>

                <p className="mt-3 text-xs leading-relaxed text-ink-2">
                    Kocokan sekarang memakai benih{' '}
                    <span className="font-mono text-ink">#{laman.benih}</span> dan berisi{' '}
                    <span className="font-mono text-ink">{laman.peserta}</span> peserta di{' '}
                    <span className="font-mono text-ink">{laman.ruang}</span> ruang. Kocok ulang
                    membuat benih BARU; jumlah pesertanya tetap, susunan ruangnya tidak.
                </p>

                <div className="mt-4 flex justify-end gap-2">
                    <Tombol nada="sek" onClick={() => setKonfirmasi(false)}>
                        Batal
                    </Tombol>
                    <Tombol
                        nada="pri"
                        onClick={() => {
                            kocokUlang(jalur);
                            setKonfirmasi(false);
                        }}
                    >
                        Kocok ulang &amp; sebar
                    </Tombol>
                </div>
            </Modal>
        </AdminLayout>
    );
}

/**
 * Daftar peserta satu ruang yang dibuka bergantian.
 *
 * Daftar namanya ada di sini, bukan di satu modal terpisah, karena pertanyaan
 * yang datang dari pengawas selalu berbentuk "siapa saja di R-04" - dan
 * jawaban yang harus dibuka di layar lain adalah jawaban yang tidak dibaca.
 */
function RincianRuang({ ruang }: { ruang: RuangPratinjau[] }) {
    const [buka, setBuka] = useState<number | null>(null);

    return (
        <div className="space-y-1">
            {ruang.map((r) => (
                <div key={r.id} className="rounded-wf border border-line">
                    <button
                        type="button"
                        onClick={() => setBuka(buka === r.id ? null : r.id)}
                        className="flex w-full items-center gap-2 px-2.5 py-1.5 text-left text-xs hover:bg-surface-2"
                    >
                        <span className="font-mono font-medium text-ink">{r.kode}</span>
                        <span className="text-ink-2">{r.nama}</span>
                        <span className="ml-auto font-mono text-[11px] text-ink-3">
                            {r.terisi}/{r.kapasitas}
                        </span>
                        {!r.dipakai_sesi && <Chip nada="bad">tidak dipakai sesi</Chip>}
                        {r.kapasitas_sesi !== null && r.kapasitas_sesi !== r.kapasitas && (
                            <Chip nada="warn">{r.kapasitas_sesi} kursi di sesi ini</Chip>
                        )}
                        <span className="text-ink-3">{buka === r.id ? '▾' : '▸'}</span>
                    </button>
                    {buka === r.id && (
                        <div className="border-t border-line px-2.5 py-2">
                            {r.peserta.length === 0 ? (
                                <p className="text-[11px] text-ink-3">Ruang ini kosong.</p>
                            ) : (
                                <div className="grid gap-x-4 gap-y-1 sm:grid-cols-2 lg:grid-cols-3">
                                    {r.peserta.map((p) => (
                                        <span key={p.id} className="flex items-baseline gap-2 text-[11px]">
                                            <span className="font-mono text-ink-3">{p.nis}</span>
                                            <span className="text-ink">{p.nama}</span>
                                            <span className="ml-auto text-ink-3">{p.kelas}</span>
                                        </span>
                                    ))}
                                </div>
                            )}
                        </div>
                    )}
                </div>
            ))}
        </div>
    );
}

function Baris({ children }: { children: React.ReactNode }) {
    return (
        <p className="flex gap-2">
            <span className="text-ok">&#10003;</span>
            <span>{children}</span>
        </p>
    );
}

function KPI({
    label,
    nilai,
    nada,
    catatan,
}: {
    label: string;
    nilai: number;
    nada?: 'ok' | 'warn' | 'bad';
    catatan?: string;
}) {
    const warna =
        nada === 'ok'
            ? 'text-ok'
            : nada === 'warn'
              ? 'text-warn'
              : nada === 'bad'
                ? 'text-bad'
                : 'text-ink';

    return (
        <div className="rounded-wf border border-line bg-surface px-4 py-3">
            <p className="text-xs text-ink-3">{label}</p>
            <p className={'mt-1 font-mono text-2xl font-medium ' + warna}>
                {nilai.toLocaleString('id-ID')}
            </p>
            {catatan && <p className="mt-0.5 text-[11px] text-ink-3">{catatan}</p>}
        </div>
    );
}

/* --------------------------------------------------------------- Aksi */

function bukaPratinjau(jalur: string, sesiId: number) {
    router.get(`${jalur}/penempatan`, { sesi: sesiId }, { preserveScroll: true, preserveState: true });
}

function sebar(jalur: string, benih: string) {
    router.post(`${jalur}/penempatan`, { benih }, { preserveScroll: true });
}

function kocokUlang(jalur: string) {
    router.post(`${jalur}/penempatan/kocok-ulang`, {}, { preserveScroll: true });
}

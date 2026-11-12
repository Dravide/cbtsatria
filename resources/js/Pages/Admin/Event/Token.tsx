import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { konfirmasi } from '@/Components/dialog';
import { Chip, Inbox, Kotak, Tombol, kelasInput } from '@/Components/ui';

/**
 * Laman 18 - Token ujian. Wireframe plate 18, PRD §9.7, keputusan §24 #6.
 *
 * ---------------------------------------------------------------------
 * Tokennya TERLIHAT, dan itu berbeda dari kode pengawas
 * ---------------------------------------------------------------------
 * Di plate 09 kode pengawas ditampilkan sekali lalu hilang - ia disimpan
 * sebagai hash, dan tidak ada cara membacanya kembali. Token tidak begitu:
 * PRD §9.7 menuntutnya bisa ditampilkan kembali kepada Admin atau Pengawas
 * berwenang, karena ia dibacakan di depan kelas dan diketik tiga ratus siswa
 * sambil berdiri.
 *
 * Karena itu tombol "Baca" ada di setiap baris, dan menekannya bukan sekadar
 * menampilkan - ia MENULIS SATU BARIS `exam_token_views`. Anotasi plate 18
 * menyatakan mengapa: "kalau token bocor, asalnya bisa ditelusuri ke satu
 * akun, bukan berhenti pada 'entah siapa'."
 *
 * ---------------------------------------------------------------------
 * Nada chip diambil dari server, bukan dari nadaStatus()
 * ---------------------------------------------------------------------
 * Lima status token - draft, aktif, dibuka, kedaluwarsa, dicabut - dan
 * `dibuka` ingin nada `info` sementara `draft` ingin `mute`. `nadaStatus()`
 * di ui.tsx tidak mengenal keduanya: `draft` jatuh ke `mute` (kebetulan
 * benar) dan `dibuka` juga jatuh ke `mute` (salah). Server mengirim nadanya
 * lewat StatusToken::nada(), dan halaman ini memakainya apa adanya.
 */
type Baris = {
    sesi_id: number;
    sesi: string;
    ujian: string | null;
    ruang: number;
    ruang_kode: string[];
    token_id: number | null;
    berlaku: string | null;
    dibuka_oleh: string | null;
    status: string;
    status_label: string;
    status_nada: 'ok' | 'warn' | 'bad' | 'info' | 'mute';
    boleh_terbit: boolean;
    alasan_tolak: string | null;
};

type Riwayat = {
    id: number;
    sesi: string;
    ujian: string | null;
    jenis: string;
    oleh: string;
    ruang: string | null;
    jam: string | null;
    tanggal: string | null;
};

type Props = {
    event: { id: number; kode: string; nama: string; status: string; status_label: string };
    baris: Baris[];
    angka: { sesi: number; bertoken: number; dibuka: number };
    riwayat: Riwayat[];
    tanpa_pengawas: Baris[];
};

export default function EventToken({ event, baris, angka, riwayat, tanpa_pengawas }: Props) {
    const jalur = `/admin/events/${event.id}`;

    const { flash } = usePage().props as unknown as {
        flash: { kode_token?: string; token_sesi?: string };
    };

    return (
        <AdminLayout
            judul="Token ujian"
            breadcrumb={`/admin/events · ${event.kode} · Token`}
            aksi={
                <>
                    <span className="flex items-center gap-2 rounded-wf border border-line bg-surface px-2.5 py-1.5 text-xs text-ink-2">
                        <span className="text-ink-3">Event:</span>
                        <span className="font-medium text-ink">{event.nama}</span>
                    </span>

                    <Tombol
                        onClick={() => window.print()}
                        title="Cetak kartu token untuk dibacakan di ruang"
                    >
                        Cetak kartu token
                    </Tombol>
                </>
            }
        >
            <Head title={`Token · ${event.nama}`} />

            {angka.sesi > 0 && (
                <div className="mb-3 grid gap-3 sm:grid-cols-3">
                    <KPI label="Sesi" nilai={angka.sesi} />
                    <KPI label="Sudah bertoken" nilai={angka.bertoken} nada="ok" />
                    <KPI label="Sudah dibuka" nilai={angka.dibuka} />
                </div>
            )}

            {/* Token yang baru diterbitkan atau baru dibuka hidup TEPAT satu
                respons. Ia ditampilkan di sini, bukan di kotak umpan balik yang
                hilang sendiri setelah enam detik: enam detik bukan cukup untuk
                menyalin kode yang akan dibacakan ke tiga ratus siswa. */}
            {flash?.kode_token && (
                <div className="mb-3">
                    <Kotak
                        judul={
                            flash.token_sesi
                                ? `Token ${flash.token_sesi} - tampil sekali di respons ini`
                                : 'Token - tampil sekali di respons ini'
                        }
                    >
                        <p className="font-mono text-3xl tracking-[0.3em] text-ink">
                            {flash.kode_token}
                        </p>
                        <p className="mt-2 text-xs leading-relaxed text-ink-3">
                            Muat ulang laman ini dan kodenya hilang dari layar &mdash; tetapi tidak
                            hilang dari sistem. Selama ia masih dalam jendela berlakunya, ia bisa
                            dibaca lagi lewat tombol <em>Baca</em>, dan setiap pembacaan itu{' '}
                            <strong>tercatat</strong> di riwayat di bawah.
                        </p>
                    </Kotak>
                </div>
            )}

            {tanpa_pengawas.length > 0 && (
                <div className="mb-3">
                    <div className="rounded-wf border border-warn/30 bg-warn-bg px-3 py-2.5 text-xs leading-relaxed text-warn">
                        {tanpa_pengawas.length} sesi belum bisa diterbitkan tokennya:
                        <ul className="mt-1.5 list-inside list-disc space-y-0.5">
                            {tanpa_pengawas.map((b) => (
                                <li key={b.sesi_id}>
                                    <span className="font-mono">{b.sesi}</span> &mdash;{' '}
                                    {b.alasan_tolak}
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
            )}

            <TabelToken jalur={jalur} baris={baris} />

            <div className="mt-4">
                <Kotak
                    judul="Riwayat pembukaan token"
                    aksi={<Chip nada="mute">tercatat</Chip>}
                >
                    {riwayat.length === 0 ? (
                        <p className="text-xs text-ink-3">
                            Belum ada token yang dibuka. Setiap pembacaan akan tercatat di sini
                            beserta akun dan ruangnya.
                        </p>
                    ) : (
                        <div className="space-y-1.5">
                            {riwayat.map((r) => (
                                <div key={r.id} className="flex flex-wrap items-center gap-3 text-xs">
                                    <span className="font-mono text-ink">{r.sesi}</span>
                                    <span className="text-ink-2">
                                        {r.jenis === 'dibuka' ? 'dibuka' : r.jenis}
                                    </span>
                                    <span className="text-ink">{r.oleh}</span>
                                    <span className="font-mono text-ink-3">{r.jam}</span>
                                    <span className="font-mono text-ink-3">{r.ruang ?? '—'}</span>
                                    <span className="ml-auto font-mono text-[11px] text-ink-3">
                                        {r.tanggal}
                                    </span>
                                </div>
                            ))}
                        </div>
                    )}
                </Kotak>
            </div>

            <div className="mt-3">
                <Inbox>
                    Satu token per sesi (keputusan PRD §24 #6). Menerbitkan ulang{' '}
                    <strong>mencabut yang lama</strong> &mdash; tanpa itu, token yang sudah difoto
                    siswa tetap berlaku, dan tidak ada yang tahu berapa banyak token sah yang
                    beredar untuk satu sesi. Token hanya berguna bila ada pengawas yang
                    memverifikasi di pintu, sehingga sesi tanpa pengawas tidak bisa diterbitkan.
                </Inbox>
            </div>
        </AdminLayout>
    );
}

function TabelToken({ jalur, baris }: { jalur: string; baris: Baris[] }) {
    const [saring, setSaring] = useState('');

    const tampil = saring
        ? baris.filter((b) =>
              `${b.sesi} ${b.ujian ?? ''} ${b.status_label}`
                  .toLowerCase()
                  .includes(saring.toLowerCase()),
          )
        : baris;

    if (baris.length === 0) {
        return (
            <Kotak judul="Token per sesi">
                <p className="text-xs text-ink-3">
                    Belum ada sesi di event ini. Susun jadwal dan sesinya di laman{' '}
                    <strong>Jadwal &amp; sesi</strong> terlebih dahulu.
                </p>
            </Kotak>
        );
    }

    return (
        <Kotak
            judul="Token per sesi"
            aksi={
                <input
                    value={saring}
                    onChange={(e) => setSaring(e.target.value)}
                    placeholder="Saring sesi atau ujian..."
                    className={kelasInput + ' max-w-[14rem]'}
                />
            }
        >
            <div className="overflow-x-auto">
                <table className="w-full min-w-[44rem] text-sm">
                    <thead>
                        <tr className="border-b border-line text-left">
                            {['Sesi', 'Token', 'Berlaku', 'Dibuka oleh', 'Status', ''].map((h) => (
                                <th
                                    key={h}
                                    className="px-3 py-2 text-[11px] font-medium text-ink-3"
                                >
                                    {h}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {tampil.map((b) => (
                            <tr key={b.sesi_id} className="border-b border-line/60 last:border-0">
                                <td className="px-3 py-2">
                                    <span className="font-mono text-xs font-medium text-ink">
                                        {b.sesi}
                                    </span>
                                    <span className="ml-2 text-xs text-ink-2">{b.ujian}</span>
                                </td>

                                <td className="px-3 py-2">
                                    {b.token_id === null ? (
                                        <Chip nada="mute">belum</Chip>
                                    ) : (
                                        <span className="font-mono text-xs text-ink-3">
                                            &#8226;&#8226;&#8226;&#8226;&#8226;&#8226;
                                        </span>
                                    )}
                                </td>

                                <td className="px-3 py-2">
                                    <span className="font-mono text-xs text-ink-2">
                                        {b.berlaku ?? '—'}
                                    </span>
                                </td>

                                <td className="px-3 py-2">
                                    <span className="text-xs text-ink-2">
                                        {b.dibuka_oleh ?? '—'}
                                    </span>
                                </td>

                                <td className="px-3 py-2">
                                    {/* Nada dari server, bukan nadaStatus(): lihat
                                        catatan di kepala berkas ini. */}
                                    <Chip nada={b.status_nada}>{b.status_label}</Chip>
                                </td>

                                <td className="px-3 py-2">
                                    <span className="flex justify-end gap-1.5">
                                        {b.token_id !== null && b.status !== 'dicabut' && (
                                            <Tombol
                                                nada="sek"
                                                onClick={() =>
                                                    router.post(
                                                        `${jalur}/token/${b.token_id}/baca`,
                                                        {},
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            >
                                                Baca
                                            </Tombol>
                                        )}

                                        {b.token_id !== null && b.status !== 'dicabut' && (
                                            <Tombol
                                                nada="bahaya"
                                                onClick={async () => {
                                                    const setuju = await konfirmasi({
                                                        judul: `Cabut token ${b.sesi}?`,
                                                        pesan: 'Siswa tidak akan bisa memakainya lagi, dan sesi yang belum dibuka kehilangan tokennya.',
                                                        labelYa: 'Cabut token',
                                                        nada: 'bahaya',
                                                    });

                                                    if (!setuju) {
                                                        return;
                                                    }

                                                    router.post(
                                                        `${jalur}/token/${b.token_id}/cabut`,
                                                        {},
                                                        { preserveScroll: true },
                                                    );
                                                }}
                                            >
                                                Cabut
                                            </Tombol>
                                        )}

                                        {/* Tombol terbit mengikuti pola plate 12:
                                            nonaktif dengan alasan tertulis, bukan
                                            disembunyikan. Sesi tanpa pengawas
                                            adalah keadaan yang sah pada jadwal
                                            yang baru disusun. */}
                                        {(b.token_id === null || b.status === 'dicabut') && (
                                            <Tombol
                                                nada="pri"
                                                disabled={!b.boleh_terbit}
                                                title={b.alasan_tolak ?? undefined}
                                                onClick={() =>
                                                    router.post(
                                                        `${jalur}/token/sesi/${b.sesi_id}`,
                                                        {},
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            >
                                                Terbitkan
                                            </Tombol>
                                        )}
                                    </span>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </Kotak>
    );
}

function KPI({ label, nilai, nada }: { label: string; nilai: number; nada?: 'ok' | 'bad' }) {
    const warna = nada === 'ok' ? 'text-ok' : nada === 'bad' ? 'text-bad' : 'text-ink';

    return (
        <div className="rounded-wf border border-line bg-surface px-4 py-3">
            <p className="text-xs text-ink-3">{label}</p>
            <p className={'mt-1 font-mono text-2xl font-medium ' + warna}>
                {nilai.toLocaleString('id-ID')}
            </p>
        </div>
    );
}

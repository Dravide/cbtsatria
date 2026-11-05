import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Chip, Inbox, Kotak, Tombol, kelasInput } from '@/Components/ui';

/**
 * Presensi lintas ruang, dari konsol Admin - PRD §3.5.
 *
 * =====================================================================
 * Mengapa daftar kursi pengawas TIDAK diulang di sini
 * =====================================================================
 * Pertanyaan Admin dan pertanyaan pengawas bukan pertanyaan yang sama, dan
 * menyatukan keduanya menghasilkan laman yang menjawab keduanya dengan
 * buruk:
 *
 *   pengawas  "siapa di ruang SAYA yang belum saya tandai"   -> satu ruang
 *   Admin     "ruang mana yang pengawasnya belum selesai"    -> semua ruang
 *
 * Karena itu barisnya adalah RUANG-SESI, dan kolomnya adalah rekap: Admin
 * tidak memanggil nama satu per satu, ia mencari ruangan yang tertinggal.
 *
 * ---------------------------------------------------------------------
 * Admin boleh MENGOREKSI, dan koreksinya menuntut alasan SELALU
 * ---------------------------------------------------------------------
 * Plate 17 membebaskan pengawas dari alasan SELAMA sesi berjalan, dan
 * membebaskannya justru karena ia melihat orangnya berdiri di depan kursi.
 * Admin tidak melihat apa pun - yang sampai kepadanya adalah laporan bahwa
 * "anak saya sebenarnya hadir". Mewarisi keringanan itu akan menghasilkan
 * koreksi tanpa kalimat, tepat pada kasus yang paling perlu kalimat.
 *
 * Karena itu kolom alasan WAJIB di formnya, dan tombol simpannya mati
 * selama kolomnya kosong - bukan sekadar ditolak server.
 *
 * ---------------------------------------------------------------------
 * Yang TIDAK dilakukan Admin di sini: mengubah baris lama
 * ---------------------------------------------------------------------
 * Setiap koreksi menulis baris `attendance_logs` baru. Karena itu laman ini
 * tidak punya tombol hapus, dan tidak akan punya.
 */
type Peserta = {
    peserta_id: number;
    record_id: number | null;
    nama: string;
    nis: string | null;
    status: string | null;
    status_label: string;
    status_nada: string;
    diverifikasi_oleh: string | null;
    diverifikasi_jam: string | null;
};

type Baris = {
    ruang_id: number;
    ruang: string;
    ruang_nama: string | null;
    lokasi: string | null;
    sesi_id: number | null;
    sesi: string | null;
    ujian: string | null;
    jam: string | null;
    pengawas: string[];
    rekap: {
        total: number;
        hadir: number;
        terlambat: number;
        tidak_hadir: number;
        izin: number;
        pending: number;
    };
    insiden: number;
    peserta: Peserta[];
};

type Props = {
    event: { id: number; kode: string; nama: string; status: string; status_label: string };
    baris: Baris[];
    angka: {
        ruang: number;
        belum_tuntas: number;
        hadir: number;
        peserta: number;
        insiden: number;
    };
    opsi: Record<string, string>;
    tanpa_pengawas: string[];
};

export default function EventPresensi({ event, baris, angka, opsi, tanpa_pengawas }: Props) {
    // Ruang yang sedang dibuka daftar anaknya. Satu saja pada satu waktu:
    // laman ini dibuka untuk mencari ruangan yang tertinggal, dan membuka
    // dua daftar sekaligus membuat daftar yang dicari tergeser ke bawah.
    const [buka, setBuka] = useState<number | null>(null);

    return (
        <AdminLayout
            judul="Presensi"
            breadcrumb={`/admin/events · ${event.kode} · Presensi`}
            aksi={
                <span className="flex items-center gap-2 rounded-wf border border-line bg-surface px-2.5 py-1.5 text-xs text-ink-2">
                    <span className="text-ink-3">Event:</span>
                    <span className="font-medium text-ink">{event.nama}</span>
                </span>
            }
        >
            <Head title={`Presensi · ${event.nama}`} />

            <div className="mb-3 grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
                <KPI label="Ruang-sesi" nilai={angka.ruang} />
                <KPI
                    label="Belum tuntas"
                    nilai={angka.belum_tuntas}
                    nada={angka.belum_tuntas > 0 ? 'bad' : 'ok'}
                />
                <KPI label="Hadir" nilai={angka.hadir} nada="ok" />
                <KPI label="Peserta" nilai={angka.peserta} />
                <KPI label="Insiden" nilai={angka.insiden} nada={angka.insiden > 0 ? 'bad' : undefined} />
            </div>

            {tanpa_pengawas.length > 0 && (
                <div className="mb-3">
                    <Inbox>
                        {tanpa_pengawas.length} ruang belum punya pengawas:{' '}
                        <span className="font-mono">{tanpa_pengawas.join(', ')}</span>. Presensi
                        ruang itu tidak akan terisi selama tidak ada yang membukanya.
                    </Inbox>
                </div>
            )}

            <div className="space-y-3">
                {baris.length === 0 && (
                    <Kotak>
                        <p className="py-6 text-center text-sm text-ink-3">
                            Event ini belum punya sesi yang menempatkan peserta di ruang mana pun.
                        </p>
                    </Kotak>
                )}

                {baris.map((b) => (
                    <Kotak
                        key={b.ruang_id}
                        judul={`${b.ruang} · ${b.ruang_nama ?? 'Ruang'}`}
                        aksi={
                            <>
                                <Chip nada={b.sesi ? 'info' : 'mute'}>
                                    {b.sesi ?? 'tanpa sesi'}
                                </Chip>
                                {b.rekap.pending > 0 ? (
                                    <Chip nada="warn">{b.rekap.pending} belum</Chip>
                                ) : (
                                    <Chip nada="ok">tuntas</Chip>
                                )}
                                <Tombol onClick={() => setBuka(buka === b.ruang_id ? null : b.ruang_id)}>
                                    {buka === b.ruang_id ? 'Tutup daftar' : 'Koreksi presensi'}
                                </Tombol>
                            </>
                        }
                    >
                        <div className="grid gap-2 text-xs text-ink-2 sm:grid-cols-2">
                            <p>
                                {[b.ujian, b.lokasi, b.jam].filter(Boolean).join(' · ')}
                            </p>
                            <p className="sm:text-right">
                                Pengawas:{' '}
                                {b.pengawas.length > 0 ? (
                                    b.pengawas.join(', ')
                                ) : (
                                    <span className="text-warn">belum ditetapkan</span>
                                )}
                            </p>
                        </div>

                        <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-6">
                            <Angka label="Peserta" nilai={b.rekap.total} />
                            <Angka label="Hadir" nilai={b.rekap.hadir} nada="ok" />
                            <Angka label="Terlambat" nilai={b.rekap.terlambat} />
                            <Angka label="Tidak hadir" nilai={b.rekap.tidak_hadir} />
                            <Angka label="Izin" nilai={b.rekap.izin} />
                            <Angka label="Belum" nilai={b.rekap.pending} nada={b.rekap.pending > 0 ? 'bad' : undefined} />
                        </div>

                        {b.insiden > 0 && (
                            <p className="mt-2 text-xs text-warn">
                                {b.insiden} insiden belum ditangani di ruang ini.
                            </p>
                        )}

                        {buka === b.ruang_id && (
                            <div className="mt-4 border-t border-line pt-3">
                                <DaftarPeserta
                                    eventId={event.id}
                                    ruang={b.ruang}
                                    peserta={b.peserta}
                                    opsi={opsi}
                                />
                            </div>
                        )}
                    </Kotak>
                ))}
            </div>
        </AdminLayout>
    );
}

/**
 * Daftar anak satu ruang, dengan kolom koreksinya.
 *
 * Baris yang belum punya `record_id` ditampilkan TANPA formnya, bukan
 * dengan form yang pasti gagal: koreksi bekerja atas baris presensi, dan
 * baris presensi dibuat saat penempatannya dibuat. Penempatan yang tidak
 * punya baris presensi berarti penempatannya dibuat sebelum tabel presensi
 * ada - dan itu keadaan yang harus disebutkan, bukan ditutupi tombol.
 */
function DaftarPeserta({
    eventId,
    ruang,
    peserta,
    opsi,
}: {
    eventId: number;
    ruang: string;
    peserta: Peserta[];
    opsi: Record<string, string>;
}) {
    if (peserta.length === 0) {
        return (
            <p className="py-4 text-center text-xs text-ink-3">
                Belum ada peserta yang ditempatkan di ruang ini.
            </p>
        );
    }

    return (
        <div className="space-y-2">
            <p className="text-[11px] text-ink-3">
                Koreksi atas ruang {ruang} selalu menuntut alasan tertulis. Baris lama tidak
                dihapus; koreksi ditulis sebagai perubahan baru.
            </p>

            {peserta.map((p) => (
                <BarisKoreksi
                    key={p.peserta_id}
                    eventId={eventId}
                    peserta={p}
                    opsi={opsi}
                />
            ))}
        </div>
    );
}

function BarisKoreksi({
    eventId,
    peserta,
    opsi,
}: {
    eventId: number;
    peserta: Peserta;
    opsi: Record<string, string>;
}) {
    const [terbuka, setTerbuka] = useState(false);
    const [status, setStatus] = useState(peserta.status ?? 'hadir');
    const [alasan, setAlasan] = useState('');
    const [kirim, setKirim] = useState(false);

    const simpan = () => {
        if (peserta.record_id === null || alasan.trim().length < 4) {
            return;
        }

        setKirim(true);
        router.post(
            `/admin/events/${eventId}/presensi/koreksi`,
            { record_id: peserta.record_id, status, alasan },
            {
                preserveScroll: true,
                onFinish: () => {
                    setKirim(false);
                    setTerbuka(false);
                    setAlasan('');
                },
            },
        );
    };

    return (
        <div className="rounded-wf border border-line/70 bg-surface px-3 py-2">
            <div className="flex flex-wrap items-center gap-2">
                <span className="text-sm text-ink">{peserta.nama}</span>
                {peserta.nis && (
                    <span className="font-mono text-[11px] text-ink-3">{peserta.nis}</span>
                )}

                <span className="ml-auto flex items-center gap-2">
                    <Chip nada={peserta.status_nada as 'mute'}>{peserta.status_label}</Chip>

                    {peserta.record_id === null ? (
                        <span className="text-[11px] text-warn">
                            Baris presensinya belum ada
                        </span>
                    ) : (
                        <Tombol onClick={() => setTerbuka((t) => !t)}>
                            {terbuka ? 'Batal' : 'Ubah'}
                        </Tombol>
                    )}
                </span>
            </div>

            {peserta.diverifikasi_jam && (
                <p className="mt-0.5 text-[11px] text-ink-3">
                    Diverifikasi {peserta.diverifikasi_jam}
                    {peserta.diverifikasi_oleh ? ` oleh ${peserta.diverifikasi_oleh}` : ''}
                </p>
            )}

            {terbuka && peserta.record_id !== null && (
                <div className="mt-2 flex flex-wrap items-end gap-2 border-t border-line/60 pt-2">
                    <label className="block">
                        <span className="mb-1 block text-[11px] text-ink-3">Ubah menjadi</span>
                        <select
                            value={status}
                            onChange={(e) => setStatus(e.target.value)}
                            className={kelasInput}
                        >
                            {Object.entries(opsi).map(([nilai, label]) => (
                                <option key={nilai} value={nilai}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </label>

                    <label className="block min-w-[16rem] flex-1">
                        <span className="mb-1 block text-[11px] text-ink-3">
                            Alasan koreksi (wajib)
                        </span>
                        <textarea
                            value={alasan}
                            onChange={(e) => setAlasan(e.target.value)}
                            rows={2}
                            placeholder="Mis. surat keterangan dokter diterima 04 Nov."
                            className={kelasInput}
                        />
                    </label>

                    <Tombol
                        nada="pri"
                        onClick={simpan}
                        disabled={kirim || alasan.trim().length < 4}
                    >
                        Simpan koreksi
                    </Tombol>
                </div>
            )}
        </div>
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

function Angka({ label, nilai, nada }: { label: string; nilai: number; nada?: 'ok' | 'bad' }) {
    const warna = nada === 'ok' ? 'text-ok' : nada === 'bad' ? 'text-bad' : 'text-ink';

    return (
        <div className="rounded-wf border border-line/70 bg-surface-2/50 px-2.5 py-1.5">
            <p className="text-[10px] tracking-wide text-ink-3 uppercase">{label}</p>
            <p className={'font-mono text-sm ' + warna}>{nilai}</p>
        </div>
    );
}

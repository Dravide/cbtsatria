import { Link, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { Chip, Tombol, kelasInput } from '@/Components/ui';
import SuperLayout from '@/Layouts/SuperLayout';

type GalatDetail = {
    id: number;
    code: string;
    portal: string;
    portal_label: string;
    status_code: number;
    exception_class: string;
    message: string;
    file: string | null;
    line: number | null;
    trace: string | null;
    url: string | null;
    method: string | null;
    route_name: string | null;
    sekolah: string | null;
    pengguna: string | null;
    username: string | null;
    user_role: string | null;
    ip_address: string | null;
    user_agent: string | null;
    context: Record<string, unknown> | null;
    occurrences: number;
    first_seen_at: string | null;
    last_seen_at: string | null;
    resolved_at: string | null;
    penyelesai: string | null;
    resolution_note: string | null;
};

type Props = {
    galat: GalatDetail;
};

export default function GalatShow({ galat }: Props) {
    const [note, setNote] = useState(galat.resolution_note ?? '');
    const [saving, setSaving] = useState(false);
    const [disalin, setDisalin] = useState(false);

    const selesaikan = (e: FormEvent) => {
        e.preventDefault();
        setSaving(true);
        router.post(
            `/super/galat/${galat.id}/selesai`,
            { resolution_note: note },
            {
                preserveScroll: true,
                onFinish: () => setSaving(false),
            },
        );
    };

    const salin = async () => {
        const teks = [
            `Kode: ${galat.code} (HTTP ${galat.status_code})`,
            `Exception: ${galat.exception_class}`,
            `Pesan: ${galat.message}`,
            `Lokasi: ${galat.file ?? '-'}:${galat.line ?? '-'}`,
            `URL: ${galat.method ?? ''} ${galat.url ?? '-'}`,
            `Jumlah: ${galat.occurrences}x`,
        ].join('\n');

        try {
            await navigator.clipboard.writeText(teks);
            setDisalin(true);
            window.setTimeout(() => setDisalin(false), 2000);
        } catch {
            // Abaikan bila clipboard tidak tersedia
        }
    };

    return (
        <SuperLayout
            judul={`Detail Galat · ${galat.code}`}
            breadcrumb={`Super Admin / Log Galat / ${galat.code}`}
            subjudul={galat.exception_class}
            aksi={
                <div className="flex items-center gap-2">
                    <Tombol nada="sek" onClick={salin}>
                        {disalin ? 'Ringkasan Tersalin' : 'Salin Ringkasan'}
                    </Tombol>
                    <Link
                        href="/super/galat"
                        className="rounded-wf border border-line bg-surface px-3 py-1.5 text-xs font-medium text-ink-2 hover:bg-surface-2"
                    >
                        Kembali
                    </Link>
                </div>
            }
        >
            <div className="mb-4 flex flex-wrap items-center gap-2 rounded-wf border border-line bg-surface px-4 py-3">
                <Chip nada="bad">{galat.code}</Chip>
                <Chip nada="mute">
                    {galat.portal} · {galat.portal_label}
                </Chip>
                <Chip nada="mute">HTTP {galat.status_code}</Chip>
                <Chip nada="warn">Terjadi {galat.occurrences}×</Chip>
                {galat.resolved_at ? (
                    <Chip nada="ok">Selesai · {galat.resolved_at}</Chip>
                ) : (
                    <Chip nada="bad">Belum Selesai</Chip>
                )}
            </div>

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <section className="rounded-wf border border-line bg-surface p-4">
                        <h2 className="font-display text-sm font-semibold text-ink">
                            Pesan &amp; Informasi Permintaan
                        </h2>
                        <p className="mt-2 rounded-wf border border-bad/30 bg-bad-bg px-3 py-2 text-xs font-medium text-bad">
                            {galat.message}
                        </p>

                        <dl className="mt-4 grid gap-2 text-xs sm:grid-cols-[140px_1fr]">
                            <dt className="font-mono text-[11px] text-ink-3">Lokasi</dt>
                            <dd className="font-mono text-ink">
                                {galat.file ?? '—'}
                                {galat.line !== null ? `:${galat.line}` : ''}
                            </dd>

                            <dt className="font-mono text-[11px] text-ink-3">URL</dt>
                            <dd className="break-all font-mono text-ink">
                                {galat.method ? `${galat.method} ` : ''}
                                {galat.url ?? '—'}
                            </dd>

                            <dt className="font-mono text-[11px] text-ink-3">Route</dt>
                            <dd className="font-mono text-ink-2">{galat.route_name ?? '—'}</dd>

                            <dt className="font-mono text-[11px] text-ink-3">Sekolah</dt>
                            <dd className="text-ink">{galat.sekolah ?? '—'}</dd>

                            <dt className="font-mono text-[11px] text-ink-3">Pengguna</dt>
                            <dd className="text-ink">
                                {galat.pengguna
                                    ? `${galat.pengguna} (${galat.username ?? '-'}${galat.user_role ? ` · ${galat.user_role}` : ''})`
                                    : 'Tamu / Tanpa Sesi'}
                            </dd>

                            <dt className="font-mono text-[11px] text-ink-3">IP / Browser</dt>
                            <dd className="break-all font-mono text-[11px] text-ink-2">
                                {galat.ip_address ?? '—'}
                                {galat.user_agent ? ` · ${galat.user_agent}` : ''}
                            </dd>

                            <dt className="font-mono text-[11px] text-ink-3">Pertama muncul</dt>
                            <dd className="font-mono text-[11px] text-ink-2">
                                {galat.first_seen_at ?? '—'}
                            </dd>

                            <dt className="font-mono text-[11px] text-ink-3">Terakhir muncul</dt>
                            <dd className="font-mono text-[11px] text-ink-2">
                                {galat.last_seen_at ?? '—'}
                            </dd>
                        </dl>
                    </section>

                    <section className="rounded-wf border border-line bg-surface p-4">
                        <h2 className="font-display text-sm font-semibold text-ink">
                            Stack Trace (25 Frame Teratas)
                        </h2>
                        <pre className="mt-3 max-h-96 overflow-auto rounded-wf border border-line bg-surface-2/60 p-3 font-mono text-[11px] leading-relaxed text-ink-2">
                            {galat.trace || 'Tidak ada stack trace.'}
                        </pre>
                    </section>
                </div>

                <div className="space-y-4">
                    <section className="rounded-wf border border-line bg-surface p-4">
                        <h2 className="font-display text-sm font-semibold text-ink">
                            Konteks &amp; Payload (Ter-sanitasi)
                        </h2>
                        <pre className="mt-3 max-h-72 overflow-auto rounded-wf border border-line bg-surface-2/60 p-3 font-mono text-[11px] leading-relaxed text-ink-2">
                            {JSON.stringify(galat.context ?? {}, null, 2)}
                        </pre>
                    </section>

                    <section className="rounded-wf border border-line bg-surface p-4">
                        <h2 className="font-display text-sm font-semibold text-ink">
                            Status Penanganan
                        </h2>

                        {galat.resolved_at && (
                            <p className="mt-2 text-xs text-ok">
                                Diselesaikan oleh <strong>{galat.penyelesai ?? 'Super Admin'}</strong> pada{' '}
                                {galat.resolved_at}.
                            </p>
                        )}

                        <form onSubmit={selesaikan} className="mt-3 space-y-3">
                            <textarea
                                value={note}
                                onChange={(e) => setNote(e.target.value)}
                                rows={3}
                                placeholder="Catatan akar masalah & perbaikan..."
                                className={kelasInput}
                            />
                            <Tombol nada="pri" type="submit" disabled={saving}>
                                {galat.resolved_at ? 'Perbarui Catatan' : 'Tandai Selesai'}
                            </Tombol>
                        </form>
                    </section>
                </div>
            </div>
        </SuperLayout>
    );
}

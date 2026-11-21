import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

/**
 * Pantau wali - satu kelas - `/wali/{slug}/kelas/{kelas}`, tanpa login.
 *
 * =====================================================================
 * Satu laman, dua keadaan, dan yang membedakannya KODE
 * =====================================================================
 * Sebelum kode: nama, ruang, kursi. Sesudah kode: jam absen, jam mulai,
 * lama mengerjakan, masa terkunci, pengawas, dan foto berita acara.
 *
 * Dua keadaan di SATU alamat, bukan dua alamat. Dua alamat berarti dua
 * tautan yang bisa dibagikan, dan yang kedua akan membuka rincian tanpa
 * kode kepada siapa pun yang menemukannya.
 *
 * =====================================================================
 * "0 menit" tidak pernah ditampilkan untuk anak yang tidak hadir
 * =====================================================================
 * Angka nol di kolom durasi dibaca sebagai "tidak mau mengerjakan", bukan
 * sebagai "tidak ada di ruangan". Untuk keadaan itu yang ditampilkan
 * KALIMAT beserta jam absennya - dan aturan yang sama berlaku di service,
 * yang mengirim `menit_kerja: null`, bukan nol.
 *
 * =====================================================================
 * Rincian dibuka di TEMPAT, bukan di URL sendiri
 * =====================================================================
 * Satu ketukan memperluas barisnya. Tidak ada `/wali/.../siswa/{id}`,
 * karena id yang berurutan bisa ditelusuri dan satu kode benar membuka
 * tiga puluh anak sekaligus.
 */
type Siswa = {
    id: number;
    nama: string;
    ruang: string | null;
    kursi: number | null;
};

type SiswaRinci = Siswa & {
    hadir: string | null;
    hadir_label: string | null;
    absen: string | null;
    masuk_gerbang: boolean;
    status: string | null;
    status_label: string | null;
    mulai: string | null;
    selesai: string | null;
    menit_kerja: number | null;
    menit_terkunci: number | null;
    sisa_menit: number | null;
    jam_sesi: string | null;
};

type Sesi = {
    id: number;
    label: string;
    ujian: string | null;
    mapel: string | null;
    jam: string;
    status: string;
};

type Props = {
    sekolah: string;
    slug: string;
    terbuka: boolean;
    boleh_foto: boolean;
    kelas: { id: number; nama: string };
    sesi: Sesi | null;
    siswa: Siswa[] | SiswaRinci[];
    rekap?: { total: number; hadir: number; mengerjakan: number; selesai: number };
    pengawas?: { ruang: string | null; nama: string }[];
    foto?: { id: number; diunggah: string | null; mime: string | null; sisi: string }[];
    diperbarui?: string;
};

/** Nada chip untuk satu keadaan attempt. */
function nada(nilai: string | null | undefined): string {
    switch (nilai) {
        case 'dikirim':
            return 'border-ok/30 bg-ok-bg text-ok';
        case 'berjalan':
            return 'border-info/30 bg-info-bg text-info';
        case 'dikunci':
            return 'border-warn/30 bg-warn-bg text-warn';
        case 'kedaluwarsa':
        case 'batal':
            return 'border-bad/30 bg-bad-bg text-bad';
        default:
            return 'border-line bg-surface-2 text-ink-3';
    }
}

function labelKeadaan(s: SiswaRinci): string {
    if (s.status !== null) {
        return s.status_label ?? s.status;
    }

    if (s.hadir !== null && s.hadir !== 'hadir' && s.hadir !== 'terlambat') {
        return s.hadir_label ?? 'tidak hadir';
    }

    return 'belum mulai';
}

/** Apakah baris ini punya jam sama sekali. */
function punyaJam(s: SiswaRinci): boolean {
    return s.mulai !== null;
}

export default function WaliKelas({
    sekolah,
    slug,
    terbuka,
    boleh_foto,
    kelas,
    sesi,
    siswa,
    rekap,
    pengawas,
    foto,
    diperbarui,
}: Props) {
    const [terbukaId, setTerbukaId] = useState<number | null>(null);

    const form = useForm({ kode: '' });

    const kirim = (e: FormEvent) => {
        e.preventDefault();

        form.post(`/wali/${slug}/kelas/${kelas.id}`, {
            preserveScroll: true,
            onError: () => form.reset('kode'),
        });
    };

    const rinci = terbuka ? (siswa as SiswaRinci[]) : null;

    return (
        <>
            <Head title={`${kelas.nama} · ${sekolah}`} />

            <div className="laman-publik min-h-screen bg-paper">
                <div className="mx-auto flex min-h-screen w-full max-w-[26rem] flex-col sm:border-x sm:border-line">
                    <header className="flex items-center gap-2 border-b border-line bg-surface px-3 py-2.5">
                        <Link
                            href={`/wali/${slug}`}
                            className="shrink-0 rounded px-1 text-sm text-ink-3 hover:text-ink"
                            aria-label="Kembali ke daftar kelas"
                        >
                            ‹
                        </Link>
                        <span className="min-w-0 flex-1 truncate text-xs font-semibold text-ink">
                            {kelas.nama}
                            {sesi?.mapel ? ` · ${sesi.mapel}` : ''}
                        </span>
                        {terbuka && (
                            <span className="shrink-0 rounded-full border border-ok/30 bg-ok-bg px-2 py-0.5 font-mono text-[10px] text-ok">
                                terbuka
                            </span>
                        )}
                    </header>

                    <main className="flex-1 px-3 py-3">
                        {/* ------------------------------------------- sesi */}
                        {sesi === null ? (
                            <div className="rounded-wf border border-line bg-surface px-4 py-8 text-center">
                                <p className="text-xs text-ink-2">
                                    Belum ada ujian untuk kelas ini hari ini.
                                </p>
                            </div>
                        ) : (
                            <>
                                <div className="mb-3 rounded-wf border border-line bg-surface px-4 py-3">
                                    <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                        <span className="text-xs font-semibold text-ink">
                                            {sesi.mapel ?? sesi.ujian ?? sesi.label}
                                        </span>
                                        <span className="font-mono text-[11px] text-ink-2">
                                            {sesi.jam}
                                        </span>
                                    </div>

                                    {rekap !== undefined && (
                                        <div className="mt-2 grid grid-cols-3 gap-2">
                                            {[
                                                { l: 'Hadir', v: rekap.hadir },
                                                { l: 'Mengerjakan', v: rekap.mengerjakan },
                                                { l: 'Selesai', v: rekap.selesai },
                                            ].map((k) => (
                                                <div
                                                    key={k.l}
                                                    className="rounded border border-line bg-surface-2 px-2 py-1.5"
                                                >
                                                    <span className="block font-mono text-[9px] uppercase tracking-wide text-ink-3">
                                                        {k.l}
                                                    </span>
                                                    <span className="font-mono text-sm text-ink">
                                                        {k.v}
                                                    </span>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </div>

                                {/* --------------------------------- kode */}
                                {!terbuka && (
                                    <form
                                        onSubmit={kirim}
                                        className="mb-3 rounded-wf border border-dashed border-line bg-surface-2/50 px-3 py-3"
                                    >
                                        <label
                                            htmlFor="kode-wali"
                                            className="block font-mono text-[10px] uppercase tracking-wide text-ink-3"
                                        >
                                            Kode kelas
                                        </label>
                                        <div className="mt-1.5 flex gap-2">
                                            <input
                                                id="kode-wali"
                                                inputMode="numeric"
                                                autoComplete="off"
                                                maxLength={6}
                                                placeholder="6 angka"
                                                value={form.data.kode}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'kode',
                                                        e.target.value.replace(/\D/g, ''),
                                                    )
                                                }
                                                className="min-w-0 flex-1 rounded-wf border border-line bg-surface px-2.5 py-1.5 font-mono text-sm tracking-[0.3em] text-ink placeholder:tracking-normal placeholder:text-ink-3"
                                            />
                                            <button
                                                type="submit"
                                                disabled={form.processing || form.data.kode.length !== 6}
                                                className="shrink-0 rounded-wf border border-accent bg-accent px-3 py-1.5 text-xs font-medium text-on-accent transition-colors disabled:opacity-50"
                                            >
                                                Buka
                                            </button>
                                        </div>
                                        {form.errors.kode && (
                                            <p className="mt-1.5 text-[11px] text-bad">
                                                {form.errors.kode}
                                            </p>
                                        )}
                                        <p className="mt-1.5 text-[11px] text-ink-3">
                                            Kode dibagikan wali kelas di grup kelas. Tanpa kode,
                                            jam masuk dan lama mengerjakan tidak ditampilkan.
                                        </p>
                                    </form>
                                )}

                                {/* ---------------------------------- daftar */}
                                <ul className="overflow-hidden rounded-wf border border-line bg-surface">
                                    {siswa.length === 0 ? (
                                        <li className="px-4 py-6 text-center text-xs text-ink-3">
                                            Belum ada peserta di kelas ini.
                                        </li>
                                    ) : (
                                        rinci === null
                                            ? (siswa as Siswa[]).map((s) => (
                                                  <li
                                                      key={s.id}
                                                      className="flex items-center gap-2 border-b border-line px-3 py-2 last:border-b-0"
                                                  >
                                                      <span className="min-w-0 flex-1 truncate text-xs text-ink">
                                                          {s.nama}
                                                      </span>
                                                      <span className="shrink-0 font-mono text-[10px] text-ink-3">
                                                          {s.ruang ?? '—'}
                                                          {s.kursi !== null && ` · ${s.kursi}`}
                                                      </span>
                                                  </li>
                                              ))
                                            : rinci.map((s) => (
                                                  <li
                                                      key={s.id}
                                                      className="border-b border-line last:border-b-0"
                                                  >
                                                      <button
                                                          type="button"
                                                          onClick={() =>
                                                              setTerbukaId(
                                                                  terbukaId === s.id ? null : s.id,
                                                              )
                                                          }
                                                          className="flex w-full items-center gap-2 px-3 py-2 text-left transition-colors hover:bg-surface-2"
                                                          aria-expanded={terbukaId === s.id}
                                                      >
                                                          <span className="min-w-0 flex-1 truncate text-xs text-ink">
                                                              {s.nama}
                                                          </span>
                                                          <span
                                                              className={
                                                                  'shrink-0 rounded-full border px-2 py-0.5 font-mono text-[10px] ' +
                                                                  nada(s.status)
                                                              }
                                                          >
                                                              {labelKeadaan(s)}
                                                          </span>
                                                      </button>

                                                      {terbukaId === s.id && (
                                                          <div className="border-t border-line bg-surface-2/50 px-3 py-2.5">
                                                              {punyaJam(s) ? (
                                                                  <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-[11px]">
                                                                      <dt className="text-ink-3">Ruang</dt>
                                                                      <dd className="text-right font-mono text-ink">
                                                                          {s.ruang ?? '—'}
                                                                          {s.kursi !== null && ` · kursi ${s.kursi}`}
                                                                      </dd>
                                                                      <dt className="text-ink-3">Absen</dt>
                                                                      <dd className="text-right font-mono text-ink">
                                                                          {s.absen ?? '—'}
                                                                      </dd>
                                                                      <dt className="text-ink-3">Mulai</dt>
                                                                      <dd className="text-right font-mono text-ink">
                                                                          {s.mulai}
                                                                      </dd>
                                                                      {s.selesai !== null && (
                                                                          <>
                                                                              <dt className="text-ink-3">Selesai</dt>
                                                                              <dd className="text-right font-mono text-ink">
                                                                                  {s.selesai}
                                                                              </dd>
                                                                          </>
                                                                      )}
                                                                      {s.menit_kerja !== null && (
                                                                          <>
                                                                              <dt className="text-ink-3">Mengerjakan</dt>
                                                                              <dd className="text-right font-mono text-ink">
                                                                                  {s.menit_kerja} menit
                                                                              </dd>
                                                                          </>
                                                                      )}
                                                                      {s.menit_terkunci !== null && (
                                                                          <>
                                                                              <dt className="text-ink-3">Terkunci</dt>
                                                                              <dd className="text-right font-mono text-warn">
                                                                                  {s.menit_terkunci} menit
                                                                              </dd>
                                                                          </>
                                                                      )}
                                                                      {s.sisa_menit !== null && (
                                                                          <>
                                                                              <dt className="text-ink-3">Sisa batas</dt>
                                                                              <dd className="text-right font-mono text-ink">
                                                                                  {s.sisa_menit} menit
                                                                              </dd>
                                                                          </>
                                                                      )}
                                                                  </dl>
                                                              ) : (
                                                                  <p className="text-[11px] leading-relaxed text-ink-2">
                                                                      {s.hadir !== null &&
                                                                      s.hadir !== 'hadir' &&
                                                                      s.hadir !== 'terlambat' ? (
                                                                          <>
                                                                              Tercatat{' '}
                                                                              <strong>
                                                                                  {s.hadir_label ??
                                                                                      'tidak hadir'}
                                                                              </strong>
                                                                              {s.absen !== null &&
                                                                                  ` pukul ${s.absen}`}
                                                                              . Hubungi wali kelas bila
                                                                              menurut Anda ini keliru.
                                                                          </>
                                                                      ) : (
                                                                          <>
                                                                              Anak Anda belum mulai
                                                                              mengerjakan
                                                                              {s.jam_sesi !== null &&
                                                                                  ` — jam ujian ${s.jam_sesi}`}
                                                                              .
                                                                          </>
                                                                      )}
                                                                  </p>
                                                              )}
                                                          </div>
                                                      )}
                                                  </li>
                                              ))
                                    )}
                                </ul>

                                {/* ------------------------------- pengawas */}
                                {pengawas !== undefined && pengawas.length > 0 && (
                                    <div className="mt-3 rounded-wf border border-line bg-surface px-3 py-2.5">
                                        <h2 className="font-mono text-[10px] uppercase tracking-wide text-ink-3">
                                            Pengawas ruang
                                        </h2>
                                        <ul className="mt-1 grid gap-0.5 text-[11px] text-ink-2">
                                            {pengawas.map((p, i) => (
                                                <li key={`${p.ruang}-${i}`} className="flex gap-2">
                                                    <span className="font-mono text-ink-3">
                                                        {p.ruang ?? '—'}
                                                    </span>
                                                    <span>{p.nama}</span>
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                )}

                                {/* ---------------------------------- foto */}
                                {boleh_foto && foto !== undefined && foto.length > 0 && (
                                    <div className="mt-3 rounded-wf border border-line bg-surface px-3 py-2.5">
                                        <h2 className="font-mono text-[10px] uppercase tracking-wide text-ink-3">
                                            Berita acara · {foto[0].sisi}
                                        </h2>
                                        <div className="mt-1.5 grid gap-2">
                                            {foto.map((f) => (
                                                <figure key={f.id} className="grid gap-1">
                                                    <img
                                                        src={`/wali/${slug}/foto/${f.id}`}
                                                        alt="Foto ruang saat ujian berlangsung"
                                                        loading="lazy"
                                                        className="w-full rounded border border-line"
                                                    />
                                                    <figcaption className="text-[10px] leading-relaxed text-ink-3">
                                                        Diunggah pengawas {f.diunggah ?? '—'}.
                                                        Wajah peserta lain ikut terlihat.
                                                    </figcaption>
                                                </figure>
                                            ))}
                                        </div>
                                    </div>
                                )}
                            </>
                        )}
                    </main>

                    <footer className="border-t border-line bg-surface px-4 py-2.5">
                        <p className="text-center text-[10px] leading-relaxed text-ink-3">
                            {terbuka && diperbarui !== undefined
                                ? `Diperbarui ${diperbarui}. `
                                : ''}
                            Laman ini tidak menampilkan nilai.
                        </p>
                    </footer>
                </div>
            </div>
        </>
    );
}

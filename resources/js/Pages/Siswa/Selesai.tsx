import { router } from '@inertiajs/react';
import { useState } from 'react';
import KerangkaSiswa from '@/Layouts/KerangkaSiswa';
import { Chip, Inbox, Peringatan } from '@/Components/ui';
import { KpiPonsel, KotakPonsel } from '@/Components/ponsel';

/**
 * Konfirmasi dan penutupan - wireframe-peran.html plate 26 dan 27.
 *
 * =====================================================================
 * SATU alamat, DUA laman, dan yang memisahkannya `selesai`
 * =====================================================================
 * `/siswa/ujian/{peserta}/selesai` menggambar konfirmasi bagi attempt yang
 * masih berjalan (plate 26) dan ringkasan bagi yang sudah ditutup (plate 27).
 * Bentuk itu dipilih server - lihat SelesaiSiswaController - karena alamat
 * yang sama adalah alamat yang sama bagi siswa yang menekan "Kembali
 * mengerjakan" lalu menekan "Kumpulkan" lagi.
 *
 * ---------------------------------------------------------------------
 * TIDAK ada dialog "Yakin?"
 * ---------------------------------------------------------------------
 * Plate 26 menyatakannya sendiri: "Dialog konfirmasi hanya melatih orang
 * menekan ya tanpa membaca." Karena itu nomor soal yang belum terjawab
 * ditulis LANGSUNG di laman sebagai deretan angka yang bisa diketuk -
 * sehingga siswa yang berubah pikiran berjalan ke soalnya, bukan menekan
 * tombol.
 *
 * Satu angka yang perlu diperhatikan: nomor di deretan itu adalah
 * `display_order`, nomor YANG DILIHAT SISWA. Soal yang diacak punya nomor
 * berbeda dari posisinya di paket, dan menampilkan posisi paket berarti
 * mengirim anak ke soal yang bukan itu.
 *
 * ---------------------------------------------------------------------
 * TIDAK ada nilai di laman ini, dan itu bukan kelalaian
 * ---------------------------------------------------------------------
 * Plate 27 menulis alasannya: menampilkan nilai objektif segera setelah
 * dikumpulkan membuat siswa membandingkannya sebelum uraian selesai dinilai,
 * lalu menagih nilai yang belum final. Sampai Fase 8 memang belum ada tabel
 * nilai sama sekali.
 */

type Peserta = {
    peserta_id: number;
    ujian: string | null;
    mapel: string | null;
    ruang: string | null;
};

type Props = {
    peserta: Peserta;
    selesai: boolean;
    attempt: {
        id: number;
        status: string;
        status_label: string;
        status_nada: string;
        dikumpulkan: string | null;
        jam_kumpul: string | null;
        kedaluwarsa: boolean;
        sisa_detik: number;
    };
    progres: { total: number; terjawab: number; kosong: number; ditandai: number; ringkas: string };
    belum_terjawab: { id: number; nomor: number; ditandai: boolean }[];
};

export default function Selesai({ peserta, selesai, attempt, progres, belum_terjawab }: Props) {
    const [mengirim, setMengirim] = useState(false);

    const kumpulkan = () => {
        setMengirim(true);

        // Bukan `router.post` dengan `preserveScroll` dan bukan `useForm`:
        // yang dikirim tidak punya isi sama sekali, dan satu-satunya hal yang
        // perlu dijaga adalah tombolnya tidak ditekan dua kali. Endpoint-nya
        // sendiri idempotent - lihat SelesaiSiswaController::kumpulkan() -
        // jadi penjagaan di sini hanya soal tampilan.
        router.post(
            `/siswa/ujian/${peserta.peserta_id}/selesai`,
            {},
            {
                onFinish: () => setMengirim(false),
            },
        );
    };

    const crumb = [peserta.mapel, peserta.ruang].filter(Boolean).join(' · ') || peserta.ujian;

    if (selesai) {
        return (
            <KerangkaSiswa
                judul="Ujian dikumpulkan"
                tanpaKeluar
                kiri={<span className="truncate text-[12px] text-ink-2">{crumb}</span>}
                kanan={
                    attempt.kedaluwarsa ? (
                        <Chip nada="warn">waktu habis</Chip>
                    ) : (
                        <Chip nada="ok">dikumpulkan {attempt.jam_kumpul}</Chip>
                    )
                }
                aksiBawah={
                    <a
                        href="/siswa"
                        className="block rounded-wf border border-line bg-surface px-3 py-2.5 text-center text-sm text-ink"
                    >
                        Lihat jadwal berikutnya
                    </a>
                }
            >
                <div className="grid justify-items-center gap-2.5 py-6 text-center">
                    {/* "Jawaban diterima" HANYA muncul pada attempt yang
                        benar-benar dikumpulkan. Attempt yang kehabisan waktu
                        tidak menerima apa pun, dan laman yang menulis
                        "diterima" di atasnya akan membuat siswa menduga
                        ujiannya lengkap. */}
                    {attempt.kedaluwarsa ? (
                        <Chip nada="warn">waktu ujian habis</Chip>
                    ) : (
                        <Chip nada="ok">jawaban diterima</Chip>
                    )}

                    <b className="font-display text-[14px] text-ink">
                        {attempt.kedaluwarsa
                            ? 'Waktu ujian Anda sudah habis'
                            : 'Ujian Anda sudah dikumpulkan'}
                    </b>

                    <p className="text-[12px] leading-relaxed text-ink-2">
                        {progres.terjawab} dari {progres.total} soal terjawab. Anda dapat menutup
                        jendela ini.
                    </p>

                    {attempt.dikumpulkan && (
                        <p className="font-mono text-[11px] text-ink-3">
                            {attempt.status_label}
                        </p>
                    )}
                </div>

                <Inbox>
                    Nilai keluar setelah seluruh uraian selesai dikoreksi Guru. Anda dapat
                    memeriksanya di tab <b>Nilai</b>.
                </Inbox>
            </KerangkaSiswa>
        );
    }

    /* ------------------------------------------------------------ Plate 26 */

    const menitSisa = Math.floor(attempt.sisa_detik / 60);
    const adaKosong = belum_terjawab.length > 0;

    return (
        <KerangkaSiswa
            judul="Selesai ujian"
            tanpaKeluar
            kiri={<span className="truncate text-[12px] text-ink-2">Selesai ujian</span>}
            kanan={
                <span className="rounded-wf bg-surface-2 px-2 py-0.5 font-mono text-[12px] tabular-nums text-ink-2">
                    {formatSisa(attempt.sisa_detik)}
                </span>
            }
            aksiBawah={
                <div className="space-y-1.5">
                    <button
                        type="button"
                        onClick={kumpulkan}
                        disabled={mengirim}
                        className="w-full rounded-wf border border-accent bg-accent px-3 py-2.5 text-sm font-medium text-on-accent disabled:opacity-60"
                    >
                        {mengirim ? 'Mengumpulkan…' : 'Kumpulkan jawaban'}
                    </button>
                    <a
                        href={`/siswa/ujian/${peserta.peserta_id}/kerjakan`}
                        className="block rounded-wf border border-line bg-surface px-3 py-2.5 text-center text-sm text-ink"
                    >
                        Kembali mengerjakan
                    </a>
                </div>
            }
        >
            <div className="grid grid-cols-4 gap-1.5">
                <KpiPonsel label="Terjawab" nilai={progres.terjawab} nada="ok" />
                <KpiPonsel label="Kosong" nilai={progres.kosong} nada={adaKosong ? 'warn' : undefined} />
                <KpiPonsel label="Ditandai" nilai={progres.ditandai} />
                <KpiPonsel label="Sisa" nilai={`${menitSisa}′`} />
            </div>

            {adaKosong && (
                <Peringatan>
                    {/* Nomornya disebut SATU PER SATU, bukan dihitung. Plate 26
                        menulis alasan bentuknya: siswa yang tahu soal mana yang
                        kosong bisa langsung menuju ke sana. */}
                    Soal{' '}
                    {daftarNomor(belum_terjawab.map((b) => b.nomor))} belum terjawab. Setelah
                    dikumpulkan, jawaban tidak dapat diubah lagi.
                </Peringatan>
            )}

            {adaKosong && (
                <KotakPonsel solid padat judul="Nomor yang belum terjawab">
                    <div className="grid grid-cols-6 gap-1">
                        {belum_terjawab.map((b) => (
                            // Diketuk, dan pindah ke butirnya. Deretan angka
                            // yang hanya bisa dilihat berarti memberi siswa
                            // daftar pekerjaan yang tidak bisa ia kerjakan.
                            <a
                                key={b.id}
                                href={`/siswa/ujian/${peserta.peserta_id}/kerjakan`}
                                className={
                                    'rounded-wf border py-1.5 text-center font-mono text-[11px] ' +
                                    (b.ditandai
                                        ? 'border-warn/60 bg-warn-bg text-warn'
                                        : 'border-line bg-surface text-ink')
                                }
                            >
                                {b.nomor}
                            </a>
                        ))}
                    </div>
                </KotakPonsel>
            )}
        </KerangkaSiswa>
    );
}

/** "15", "15 dan 28", "15, 28, dan 39" - kalimat yang bisa dibaca. */
function daftarNomor(nomor: number[]): string {
    if (nomor.length === 1) {
        return String(nomor[0]);
    }

    return nomor.slice(0, -1).join(', ') + ' dan ' + nomor[nomor.length - 1];
}

/** `mm:ss` atau `h:mm:ss`. */
function formatSisa(detik: number): string {
    const j = Math.floor(detik / 3600);
    const m = Math.floor((detik % 3600) / 60);
    const d = detik % 60;

    const dua = (n: number) => String(n).padStart(2, '0');

    return j > 0 ? `${j}:${dua(m)}:${dua(d)}` : `${dua(m)}:${dua(d)}`;
}

import { router } from '@inertiajs/react';
import { useState } from 'react';
import KerangkaSiswa from '@/Layouts/KerangkaSiswa';
import { Inbox, Peringatan } from '@/Components/ui';

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
 *
 * ---------------------------------------------------------------------
 * Redesign: deretan nomor yang benar-benar bisa diketuk
 * ---------------------------------------------------------------------
 * Deretan nomor di plate 26 sudah berupa tautan sejak awal - tetapi
 * petaknya 44px berisi angka 11px yang sama di keempat laman, dan yang
 * menandai "soal ini ditandai ragu" hanyalah warna latar yang tipis.
 *
 * Sekarang petaknya bergaris, angkanya lebih besar, dan soal yang ditandai
 * membawa tanda di sudutnya. Ukuran petaknya juga naik ke 44px: ini deretan
 * angka yang diketuk berulang kali di bawah tekanan waktu, dan salah tekan
 * berarti berpindah dari satu soal kosong ke soal kosong lainnya.
 *
 * Urutan nomornya naik karena ia memang urutan soal, dan deretan angka yang
 * tidak berurut menuntut pembacaan satu per satu.
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
                        <span className="border border-line-2 bg-warn-bg px-1.5 py-0.5 text-[11px] font-medium text-warn">
                            waktu habis
                        </span>
                    ) : (
                        <span className="border border-line-2 bg-ok-bg px-1.5 py-0.5 text-[11px] font-medium text-ok">
                            dikumpulkan {attempt.jam_kumpul}
                        </span>
                    )
                }
                aksiBawah={
                    <a
                        href="/siswa"
                        className="tombol w-full"
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
                        <span className="border border-line-2 bg-warn-bg px-1.5 py-0.5 text-[11px] font-medium text-warn">
                            waktu ujian habis
                        </span>
                    ) : (
                        <span className="border border-line-2 bg-ok-bg px-1.5 py-0.5 text-[11px] font-medium text-ok">
                            jawaban diterima
                        </span>
                    )}

                    <b className="font-display text-base text-ink">
                        {attempt.kedaluwarsa
                            ? 'Waktu ujian Anda sudah habis'
                            : 'Ujian Anda sudah dikumpulkan'}
                    </b>

                    <p className="text-[12px] leading-relaxed text-ink-2">
                        {progres.terjawab} dari {progres.total} soal terjawab. Anda dapat menutup
                        jendela ini.
                    </p>

                    {attempt.dikumpulkan && (
                        <p className="catatan">{attempt.status_label}</p>
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
                <span className="angka border border-line-2 bg-surface-2 px-2 py-0.5 text-[12px] text-ink-2">
                    {formatSisa(attempt.sisa_detik)}
                </span>
            }
            aksiBawah={
                <div className="space-y-1.5">
                    <button
                        type="button"
                        onClick={kumpulkan}
                        disabled={mengirim}
                        className="tombol tombol--pri w-full"
                    >
                        {mengirim ? 'Mengumpulkan…' : 'Kumpulkan jawaban'}
                    </button>
                    <a
                        href={`/siswa/ujian/${peserta.peserta_id}/kerjakan`}
                        className="tombol w-full"
                    >
                        Kembali mengerjakan
                    </a>
                </div>
            }
        >
            <section className="papan grid grid-cols-4 overflow-hidden">
                <SelAngka label="Terjawab" nilai={String(progres.terjawab)} nada="ok" />
                <SelAngka
                    label="Kosong"
                    nilai={String(progres.kosong)}
                    nada={adaKosong ? 'warn' : undefined}
                />
                <SelAngka label="Ditandai" nilai={String(progres.ditandai)} />
                <SelAngka label="Sisa" nilai={`${menitSisa}′`} />
            </section>

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
                <section className="papan overflow-hidden">
                    <header className="papan-kepala">
                        <span>Nomor yang belum terjawab</span>
                    </header>

                    <div className="grid grid-cols-5 gap-1.5 p-3">
                        {belum_terjawab.map((b) => (
                            // Diketuk, dan pindah ke butirnya. Deretan angka
                            // yang hanya bisa dilihat berarti memberi siswa
                            // daftar pekerjaan yang tidak bisa ia kerjakan.
                            <a
                                key={b.id}
                                href={`/siswa/ujian/${peserta.peserta_id}/kerjakan`}
                                className={
                                    'angka grid min-h-[2.75rem] place-items-center border text-sm ' +
                                    (b.ditandai
                                        ? 'border-warn bg-warn-bg text-warn'
                                        : 'border-line-2 bg-surface text-ink')
                                }
                            >
                                {b.nomor}
                            </a>
                        ))}
                    </div>
                </section>
            )}
        </KerangkaSiswa>
    );
}

/** Satu sel angka di papan ringkas. Label di atas, angkanya besar. */
function SelAngka({
    label,
    nilai,
    nada,
}: {
    label: string;
    nilai: string;
    nada?: 'ok' | 'warn';
}) {
    const warna = nada ? { ok: 'text-ok', warn: 'text-warn' }[nada] : 'text-ink';

    return (
        <div className="min-w-0 border-r border-line px-2 py-2 text-center last:border-r-0">
            <b className={'angka block font-display text-lg leading-none ' + warna}>{nilai}</b>
            <span className="mt-1 block truncate text-[11px] text-ink-2">{label}</span>
        </div>
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

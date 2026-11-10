import { Head, Link } from '@inertiajs/react';
import GuruLayout from '@/Layouts/GuruLayout';
import { Inbox } from '@/Components/ui';

/**
 * Laman Guru 01 - dashboard. Wireframe-peran.html plate 01.
 *
 * ---------------------------------------------------------------------
 * Dua KPI, bukan empat - dan yang hilang bukan kelalaian
 * ---------------------------------------------------------------------
 * Wireframe menampilkan empat kotak: soal, paket, perlu koreksi, kelas
 * diampu. Yang dirender di sini tiga: soal, paket, kelas.
 *
 * "Perlu koreksi" TIDAK dirender, dan alasannya ditulis di
 * DashboardGuruController: angkanya akan benar-benar nol, tetapi nol itu
 * berarti "belum ada yang BISA dikoreksi", bukan "sudah tidak ada yang perlu
 * dikoreksi". Dua keadaan yang berbentuk sama di layar, dan yang salah
 * dibaca akan membuat Guru menyimpulkan kelasnya sudah beres.
 *
 * "Jadwal mengajar hari ini" juga tidak dirender: jam ujian adalah properti
 * SESI, bukan properti Guru, dan mengarang jadwal dari waktu sekarang berarti
 * menampilkan baris yang tidak pernah benar-benar terjadi.
 *
 * ---------------------------------------------------------------------
 * Kartu "Bank soal" di bawah KPI bukan hiasan
 * ---------------------------------------------------------------------
 * Tiga KPI tanpa jalan keluar adalah laporan, bukan konsol. Guru yang melihat
 * "Soal saya: 412" hampir selalu ingin menuju soal itu, dan tanpa kartu ini
 * ia harus menemukan sendiri menu di sidebar.
 */
type Props = {
    ringkasan: {
        soal: number;
        paket: number;
        kelas: number;
    };
};

export default function Dashboard({ ringkasan }: Props) {
    const kpi = [
        { label: 'Soal saya', nilai: ringkasan.soal, href: '/guru/bank-soal' },
        { label: 'Paket', nilai: ringkasan.paket, href: '/guru/paket' },
        { label: 'Kelas diampu', nilai: ringkasan.kelas, href: null },
    ];

    return (
        <GuruLayout judul="Beranda" breadcrumb="/guru">
            <Head title="Beranda Guru" />

            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {kpi.map((k) => {
                    const isi = (
                        <>
                            <p className="text-xs text-ink-3">{k.label}</p>
                            <p className="mt-1 font-mono text-2xl font-medium text-ink">
                                {k.nilai.toLocaleString('id-ID')}
                            </p>
                        </>
                    );

                    const kelas =
                        'block rounded-wf border border-line bg-surface px-4 py-3';

                    return k.href ? (
                        <Link key={k.label} href={k.href} className={kelas + ' hover:bg-surface-2'}>
                            {isi}
                        </Link>
                    ) : (
                        <div key={k.label} className={kelas}>
                            {isi}
                        </div>
                    );
                })}
            </div>

            <div className="mt-4">
                <Inbox>
                    Bank soal berlaku lintas event: soal yang Anda tulis dapat dipakai
                    di ujian mana pun, dan soal Guru lain untuk mata pelajaran yang
                    sama tampil di bank soal Anda. Yang menentukan cakupannya adalah
                    penugasan mengajar yang diisi Admin di laman Mata pelajaran.
                </Inbox>
            </div>

            <div className="mt-4 grid gap-3 lg:grid-cols-2">
                <div className="rounded-wf border border-line bg-surface p-4">
                    <p className="text-xs font-medium tracking-wide text-ink-2 uppercase">
                        Menyusun soal
                    </p>
                    <ul className="mt-3 space-y-2 text-sm text-ink-2">
                        <li>
                            <Link href="/guru/bank-soal/baru" className="text-accent hover:underline">
                                Tulis satu soal baru
                            </Link>{' '}
                            &mdash; sepuluh jenis, satu tab per jenis.
                        </li>
                        <li>
                            <Link href="/guru/bank-soal/impor" className="text-accent hover:underline">
                                Impor dari berkas
                            </Link>{' '}
                            &mdash; XLSX atau CSV, selalu berhenti di pratinjau dulu.
                        </li>
                        <li>
                            <Link href="/guru/paket" className="text-accent hover:underline">
                                Susun paket soal
                            </Link>{' '}
                            &mdash; kumpulkan butir dari bank dan tetapkan poinnya.
                        </li>
                    </ul>
                </div>

                <div className="rounded-wf border border-line bg-surface p-4">
                    <p className="text-xs font-medium tracking-wide text-ink-2 uppercase">
                        Koreksi &amp; nilai
                    </p>
                    <p className="mt-3 text-sm text-ink-3">
                        Koreksi uraian, nilai kelas, dan analisis butir membaca jawaban
                        siswa. Jawaban itu baru ada setelah mesin ujian berjalan, jadi
                        keempat menunya baru dibuka pada Fase 8.
                    </p>
                </div>
            </div>
        </GuruLayout>
    );
}

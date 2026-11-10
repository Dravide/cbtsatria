import { usePage } from '@inertiajs/react';
import { ReactNode } from 'react';
import KonsolLayout, { ItemNav, KelompokNav } from './KonsolLayout';

/**
 * Kerangka konsol Admin - anatomi beranotasi di wireframe-admin.html.
 *
 * Tiga kelompok sidebar: Master data selalu ada, Sistem menyusul di bawahnya,
 * dan kelompok Event hanya muncul setelah satu event dipilih. Tanpa event
 * aktif kelompok ketiga disembunyikan, bukan ditampilkan kosong (anatomi
 * butir 1).
 *
 * Kelompok Event dirender utuh sejak Fase 3 - kesebelas itemnya - meskipun
 * laman yang dituju sebagian baru ada di Fase 5-8. Itu keputusan yang sengaja
 * dan batasnya jelas: setiap tautan yang tampil AKAN ada, dan urutannya
 * mengikuti sidebar wireframe.
 *
 * ---------------------------------------------------------------------
 * Badannya pindah ke KonsolLayout di Fase 4, dan berkas ini sengaja tetap ada
 * ---------------------------------------------------------------------
 * Guru mendapat konsolnya sendiri di Fase 4, dan menyalin 200 baris sidebar
 * berarti dua salinan yang akan berbeda dalam tiga fase. Yang benar-benar
 * berbeda antara kedua konsol hanyalah DAFTAR MENUNYA - seluruh anatomi lain
 * sama - sehingga yang tinggal di sini hanya daftar itu, dan sisanya di
 * KonsolLayout.
 *
 * Nama berkas ini dipertahankan karena 26 laman memakainya, dan mengganti
 * nama impor di 26 tempat demi kerapian berarti 26 kesempatan salah ketik
 * untuk keuntungan nol.
 */

type Props = {
    judul: string;
    breadcrumb?: string;
    aksi?: ReactNode;
    children: ReactNode;
};

const MASTER: ItemNav[] = [
    { label: 'Dashboard', href: '/admin', awalan: false, ikon: 'dashboard' },
    { label: 'Profil sekolah', href: '/admin/sekolah', awalan: false, ikon: 'sekolah' },
    { label: 'Tahun ajaran', href: '/admin/tahun-ajaran', awalan: false, ikon: 'kalender' },
    { label: 'Kelas', href: '/admin/kelas', awalan: false, ikon: 'kelas' },
    { label: 'Mata pelajaran', href: '/admin/mapel', awalan: false, ikon: 'buku' },
    // Rincian ruang (/admin/ruang/R-01) harus tetap menyalakan "Ruang" di
    // sidebar; tanpa awalan ini, Admin yang membuka satu ruang melihat tidak
    // ada menu yang aktif dan merasa tersesat.
    { label: 'Ruang', href: '/admin/ruang', awalan: true, ikon: 'ruang' },
    { label: 'Pengguna', href: '/admin/pengguna', awalan: false, ikon: 'pengguna' },
];

const SISTEM: ItemNav[] = [
    { label: 'Log aktivitas', href: '/admin/log', awalan: false, ikon: 'daftar' },
    { label: 'Pengaturan', href: '/admin/pengaturan', awalan: false, ikon: 'pengaturan' },
];

/**
 * Sebelas kelompok event - sidebar wireframe.
 *
 * `fase` menandai yang belum bisa dibuka; lihat KonsolLayout untuk mengapa
 * item itu dirender sebagai teks abu, bukan tautan mati.
 *
 * `awalan: false` untuk SELURUH item event, dan itu bukan kelalaian:
 * /admin/events/12 dan /admin/events/12/jadwal adalah dua menu yang berbeda,
 * dan yang pertama tidak boleh menyala saat yang kedua dibuka.
 */
type MenuEvent = {
    label: string;
    segmen?: string;
    href?: string;
    fase?: string;
    awalan?: boolean;
    ikon?: ItemNav['ikon'];
};

const EVENT: MenuEvent[] = [
    { label: 'Daftar event', href: '', segmen: '', ikon: 'bendera' },
    { label: 'Ringkasan', segmen: '', ikon: 'ringkasan' },
    { label: 'Kepanitiaan', segmen: 'panitia', ikon: 'panitia' },
    { label: 'Kelas & peserta', segmen: 'peserta', ikon: 'peserta' },
    { label: 'Paket soal', segmen: 'mapel', ikon: 'paket' },
    { label: 'Jadwal & sesi', segmen: 'jadwal', ikon: 'jam' },
    // Penempatan duduk SEBELUM Pengawas, dan urutannya bukan selera: ruang
    // harus terisi lebih dulu sebelum ada yang bisa ditugaskan menjaganya.
    // Menaruhnya sesudah berarti sidebar menyuruh Admin menugaskan pengawas
    // untuk ruang yang belum tentu dipakai sesi mana pun.
    //
    // `awalan: true` - satu-satunya item event yang memakainya, dan alasannya
    // sama dengan Ruang: denah kursi beralamat /penempatan/denah, dan Admin
    // yang membuka denah sedang mengerjakan penempatan. Tanpa awalan ini,
    // tidak ada satu pun menu yang menyala di laman itu dan ia merasa
    // tersesat di konsolnya sendiri.
    { label: 'Penempatan', segmen: 'penempatan', awalan: true, ikon: 'peserta' },
    { label: 'Pengawas', segmen: 'pengawas', ikon: 'perisai' },
    { label: 'Token', segmen: 'token', ikon: 'kunci' },
    { label: 'Presensi', segmen: 'presensi', ikon: 'presensi' },
    { label: 'Monitoring', segmen: 'monitoring', ikon: 'denyut' },
    { label: 'Hasil', segmen: 'hasil', ikon: 'grafik' },
    // Laporan BUKAN di bawah event, dan bedanya nyata: plate 22 beralamat
    // /admin/laporan, dan laporannya mengikuti event AKTIF dari sesi - bukan
    // event yang idnya ada di URL. Admin yang berpindah event melakukannya
    // lewat sidebar, dan laporannya ikut berpindah.
    { label: 'Laporan', href: '/admin/laporan', ikon: 'laporan' },
];

export default function AdminLayout({ judul, breadcrumb, aksi, children }: Props) {
    // Event aktif dibaca di sini untuk menyusun ALAMAT menu; KonsolLayout
    // membacanya sendiri untuk kartu sidebar dan pemilih di bar atas. Dua
    // pembacaan, satu bentuk - dan bentuknya sama karena keduanya membaca
    // prop yang sama, bukan karena satu menitipkan hasilnya ke yang lain.
    const { eventAktif } = usePage().props as unknown as {
        eventAktif: { id: number; kode: string; nama: string; status_label: string } | null;
    };

    const dasarEvent = eventAktif ? `/admin/events/${eventAktif.id}` : null;

    const kelompok: KelompokNav[] = [
        { judul: 'Master', item: MASTER },
        // Daftar event selalu bisa dijangkau - bahkan saat tidak ada event
        // aktif, dan itulah satu-satunya jalan masuk ke kelompok di bawahnya.
        {
            item: [
                {
                    label: 'Event',
                    href: '/admin/events',
                    awalan: false,
                    ikon: 'bendera',
                },
            ],
        },
    ];

    // Kelompok event hanya saat satu event dibuka (anatomi butir 1). Saat
    // tidak ada, kelompok ini tidak dirender sama sekali - bukan kosong.
    if (dasarEvent) {
        kelompok.push({
            kartuEvent: true,
            item: EVENT.map((item) => ({
                label: item.label,
                // Ringkasan dan Daftar event keduanya menunjuk
                // /admin/events/{id} - yang satu adalah pintu masuk, yang lain
                // adalah isinya. Hanya satu yang boleh menyala, dan yang
                // menyala adalah Ringkasan: ia yang sedang dibuka.
                //
                // `item.href` menang atas `item.segmen`: Laporan punya alamat
                // sendiri di luar /admin/events/{id}, karena laporannya
                // mengikuti event AKTIF, bukan event di URL.
                href: item.href ?? (item.segmen ? `${dasarEvent}/${item.segmen}` : dasarEvent),
                awalan: item.awalan ?? false,
                fase: item.fase,
                ikon: item.ikon,
            })),
        });
    }

    kelompok.push({ judul: 'Sistem', item: SISTEM });

    return (
        <KonsolLayout
            judul={judul}
            breadcrumb={breadcrumb}
            aksi={aksi}
            marka={{ inisial: 'S', nada: 'admin' }}
            kelompok={kelompok}
        >
            {children}
        </KonsolLayout>
    );
}

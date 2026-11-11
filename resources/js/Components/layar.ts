/**
 * Layar penuh - satu tempat, dipakai gerbang dan laman pengerjaan.
 *
 * ---------------------------------------------------------------------
 * Mengapa modul, padahal isinya tiga baris
 * ---------------------------------------------------------------------
 * `requestFullscreen()`, `document.fullscreenElement`, dan deteksi
 * dukungannya dipakai DUA laman (`Siswa/Gerbang` memintanya, `Siswa/Kerjakan`
 * menjaganya). Disalin ke keduanya, yang berbeda diam-diam adalah deteksi
 * dukungannya - dan perbedaan itu berupa satu perangkat yang dianggap
 * melanggar padahal perambannya memang tidak bisa layar penuh.
 *
 * ---------------------------------------------------------------------
 * Perangkat yang tidak mendukung TIDAK dilaporkan, dan itu keputusan
 * ---------------------------------------------------------------------
 * `requestFullscreen()` pada elemen biasa baru ada sejak Safari 16.4
 * (sebelumnya iOS hanya mengizinkannya untuk <video>). Build Chrome di
 * ponsel sekolah lama, WebView bawaan, dan peramban dalam aplikasi lain
 * sama-sama bisa tidak punya API ini.
 *
 * Yang terjadi bila perangkat seperti itu tetap dianggap "keluar dari layar
 * penuh": setiap siswa di perangkat itu menerima laporan pelanggaran pada
 * detik pertama, dan ujiannya terkunci karena peramban yang dipakai
 * sekolahnya - bukan karena perbuatan siapa pun. Karena itu dukungannya
 * diperiksa LEBIH DULU, dan `dukung()` yang menentukan apakah laman berhak
 * menuntut apa pun.
 *
 * ---------------------------------------------------------------------
 * Tidak ada awalan `webkit`
 * ---------------------------------------------------------------------
 * Awalan itu hanya relevan untuk iOS yang lebih tua dari 16.4 - dan di sana
 * ia pun hanya bekerja untuk <video>, bukan untuk halaman. Menambahkannya
 * berarti menulis jalur yang tidak pernah menuntut apa pun, lalu membuat
 * `dukung()` berkata `true` pada perangkat yang sebenarnya tidak bisa
 * memenuhinya.
 */

type ElemenLayar = HTMLElement & {
    requestFullscreen?: () => Promise<void> | void;
};

/** Apakah peramban ini benar-benar bisa memenjarakan satu elemen ke layar penuh. */
export function dukung(): boolean {
    if (typeof document === 'undefined') {
        return false;
    }

    const akar = document.documentElement as ElemenLayar | null;

    return typeof akar?.requestFullscreen === 'function';
}

/** Apakah dokumen ini sedang memenuhi layar. */
export function sedang(): boolean {
    return typeof document !== 'undefined' && document.fullscreenElement !== null;
}

/**
 * Minta layar penuh untuk seluruh dokumen.
 *
 * HARUS dipanggil dari dalam penangan klik: peramban menolak permintaan yang
 * datang dari kode yang berjalan sesudah gesturnya selesai, dan penolakan itu
 * hanya muncul sebagai promise yang ditolak - bukan galat yang bisa
 * ditampilkan. Karena itu pemanggilnya tidak perlu menunggu hasilnya untuk
 * memutuskan apa pun: ujian tetap dimulai walau izinnya ditolak.
 */
export function minta(): void {
    if (! dukung() || sedang()) {
        return;
    }

    const akar = document.documentElement as ElemenLayar;

    try {
        const hasil = akar.requestFullscreen?.();

        if (hasil instanceof Promise) {
            hasil.catch(() => {});
        }
    } catch {
        // Peramban yang melempar sinkron (iOS lama) diperlakukan sama dengan
        // yang menolak promise-nya: ujian berjalan tanpa layar penuh.
    }
}

/**
 * Apakah layar ini terlalu sempit untuk menampilkan soal dengan utuh.
 *
 * ---------------------------------------------------------------------
 * `visualViewport` TIDAK dipakai, dan itu bagian terpentingnya
 * ---------------------------------------------------------------------
 * Papan tik Android menyusutkan `visualViewport` tanpa mengubah layout
 * halaman. Mengukurnya dari sana berarti SETIAP anak yang mengetik jawaban
 * uraian dianggap membelah layar - laporan palsu pada fitur yang justru
 * paling sering dipakai, dan pada perangkat yang tidak bisa berbuat lain.
 *
 * `innerWidth`/`innerHeight` adalah layout viewport: papan tik tidak
 * menyentuhnya, tetapi split screen, jendela mengambang, dan jendela
 * pengubah-ukuran menyentuhnya.
 *
 * ---------------------------------------------------------------------
 * Dua ambang berbeda, dan bukan satu - itu yang menyelamatkan dari laporan palsu
 * ---------------------------------------------------------------------
 * LEBAR 0,72. Browser chrome di ponsel tidak menyentuh lebar, jadi
 * perbandingan ini bersih: split screen Android memberi sekitar 55-60% layar
 * ke satu aplikasi, dan jendela mengambang bisa lebih lebar.
 *
 * TINGGI 0,55, dan angkanya jauh lebih longgar dengan sengaja. Bilah alamat
 * dan bilah bawah Safari iOS memakan sekitar 180px dari 844px, sehingga
 * `innerHeight` pada ponsel yang TIDAK membelah layar pun hanya sekitar 0,78
 * dari `availHeight`. Ambang 0,72 di sini akan menandai sebagian besar iPhone
 * sebagai "layar terbagi" pada detik pertama ujian - dan yang terkunci karena
 * itu adalah anak yang sama sekali tidak berbuat apa-apa.
 *
 * 0,55 hanya tercapai bila tinggi benar-benar terpotong separuh, yang tidak
 * dilakukan browser chrome mana pun.
 *
 * `availHeight` dipakai untuk tinggi karena `screen.height` memuat bilah
 * sistem yang tidak pernah menjadi bagian halaman.
 */
export function sempit(): boolean {
    if (typeof window === 'undefined' || typeof screen === 'undefined') {
        return false;
    }

    const lebar = screen.width > 0 && window.innerWidth < screen.width * 0.72;

    const tinggi = screen.availHeight > 0 && window.innerHeight < screen.availHeight * 0.55;

    // Salah satu cukup. Jendela mengambang bisa memakan lebar tanpa
    // menyentuh tinggi, dan jendela yang ditumpuk di atas bisa memakan
    // tinggi tanpa menyentuh lebar.
    return Boolean(lebar || tinggi);
}

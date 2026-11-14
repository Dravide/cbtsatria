/**
 * Pemformat angka untuk laman nilai - plate 06-08, 21, 22, 28.
 *
 * ---------------------------------------------------------------------
 * Mengapa tidak `toLocaleString('id-ID')`
 * ---------------------------------------------------------------------
 * `toLocaleString` dengan locale Indonesia bergantung pada data ICU di
 * peramban pengguna. Di sebagian peramban ia menghasilkan "5,4", di peramban
 * lain yang ICU-nya dipangkas (Android lama, WebView sekolah) menjadi "5.4" -
 * dan angka berkoma yang salah baca pada laporan nilai adalah kesalahan yang
 * tidak pernah terlihat di mesin pengembang.
 *
 * Fungsi di sini menghasilkan bentuk yang sama di mana pun: koma desimal,
 * titik ribuan, dan tanda hubung untuk nilai yang BELUM ADA.
 *
 * ---------------------------------------------------------------------
 * `null` adalah tanda hubung, bukan nol
 * ---------------------------------------------------------------------
 * Nol berarti "dinilai, tidak mendapat poin". `null` berarti "belum dinilai".
 * Keduanya kalimat yang sangat berbeda di laporan nilai, dan perbedaan itu
 * harus bertahan sampai layar.
 */

/** Angka dengan koma desimal, maksimal dua digit di belakang koma. */
export function formatPoin(n: number | null | undefined): string {
    if (n === null || n === undefined || Number.isNaN(n)) {
        return '—';
    }

    const bulat = Math.round(n * 100) / 100;

    return bulat % 1 === 0 ? String(bulat) : bulat.toFixed(2).replace('.', ',');
}

/** Persen dengan satu digit di belakang koma, mis. "78,4%". */
export function formatPersen(n: number | null | undefined): string {
    if (n === null || n === undefined || Number.isNaN(n)) {
        return '—';
    }

    return (Math.round(n * 10) / 10).toFixed(1).replace('.', ',') + '%';
}

/** Daya beda: dua digit, dan NEGATIF ditampilkan apa adanya. */
export function formatDayaBeda(n: number | null | undefined): string {
    if (n === null || n === undefined || Number.isNaN(n)) {
        return '—';
    }

    // Tanda minus tipis, bukan hyphen: pada angka negatif ia lebih terbaca
    // di tabel rapat, dan kekeliruan membaca tanda adalah keseluruhan makna
    // kolom ini.
    return (n < 0 ? '−' : '') + Math.abs(Math.round(n * 100) / 100).toFixed(2).replace('.', ',');
}

/** Ukuran berkas, mis. "1,2 MB". */
export function formatUkuran(byte: number | null | undefined): string {
    if (byte === null || byte === undefined) {
        return '—';
    }

    if (byte < 1024) {
        return `${byte} B`;
    }

    if (byte < 1024 * 1024) {
        return `${Math.round((byte / 1024) * 10) / 10}`.replace('.', ',') + ' KB';
    }

    return `${Math.round((byte / (1024 * 1024)) * 10) / 10}`.replace('.', ',') + ' MB';
}

/**
 * Sisa waktu sebagai jam dinding - "12:05" atau "1:02:05".
 *
 * ---------------------------------------------------------------------
 * Mengapa bukan "12 menit lagi"
 * ---------------------------------------------------------------------
 * Kalimat itu benar pada satu saat dan tetap terbaca SESUDAHNYA, dan angka
 * yang tidak bergerak sendiri sesudah dibaca adalah angka yang menipu.
 * Bentuk jam dinding setara di semua jarak: 5 detik dan 40 menit sama-sama
 * terbaca tanpa diterjemahkan.
 *
 * ---------------------------------------------------------------------
 * Mengapa tinggal di sini, bukan di dua laman
 * ---------------------------------------------------------------------
 * Hitung mundur muncul di beranda dan di laman terkunci, dan dua salinan
 * fungsi yang sama adalah dua salinan yang suatu hari berbeda pada detik
 * yang dihabiskan untuk format. Nilai di bawah 0 dikembalikan "00:00",
 * bukan angka negatif: waktu yang sudah lewat bukan "minus 12 detik".
 */
export function formatDurasi(detik: number): string {
    const sisa = Math.max(0, Math.floor(detik));
    const j = Math.floor(sisa / 3600);
    const m = Math.floor((sisa % 3600) / 60);
    const d = sisa % 60;
    const dua = (n: number) => String(n).padStart(2, '0');

    return j > 0 ? `${j}:${dua(m)}:${dua(d)}` : `${dua(m)}:${dua(d)}`;
}

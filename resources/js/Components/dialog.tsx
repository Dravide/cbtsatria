import Swal from 'sweetalert2';

/**
 * Dialog perhatian - konfirmasi dan satu kolom teks, lewat SweetAlert2.
 *
 * =====================================================================
 * Mengapa dibungkus, bukan `Swal.fire()` dipanggil di tiap laman
 * =====================================================================
 * Dua hal yang harus sama di SELURUH dialog aplikasi ini, dan yang akan
 * berbeda begitu tiap laman menyusun opsinya sendiri:
 *
 *   1. Kata tombolnya. `confirm()` bawaan peramban menulis "OK"/"Cancel",
 *      dan "OK" pada pertanyaan "Hapus ruang A-1?" adalah kata yang tidak
 *      menerangkan apa pun tentang apa yang akan terjadi.
 *   2. Posisi fokus dan tombol batalnya. Aksi yang menghapus harus bisa
 *      dibatalkan dengan Escape DAN dengan tombol yang terfokus, dan
 *      urutan tombolnya batal-di-kiri.
 *
 * =====================================================================
 * Warnanya dari token aplikasi, bukan dari tema bawaan SweetAlert2
 * =====================================================================
 * SweetAlert2 membawa paletnya sendiri (ungu `#7066e0`, abu `#545454`), dan
 * dialog yang lahir dengan palet itu akan terbaca sebagai kotak asing yang
 * menempel di atas aplikasi ini. Kelas `.swal-cbt` di `app.css` memetakan
 * seluruh variabel `--swal2-*` ke token yang sudah ada, sehingga dialognya
 * ikut tema yang sedang berlaku.
 *
 * Satu hal yang tidak bisa diselesaikan CSS saja: popupnya dipasang di
 * `<body>`, sedangkan tema gelap `.rangka-ujian` adalah penimpaan token pada
 * SUBTREE halaman. Karena itu `sedangGelap()` membaca kelasnya dari DOM dan
 * menambahkan `.swal-cbt--gelap` - lihat fungsinya di bawah.
 *
 * =====================================================================
 * Dua fungsi, dan itu batasnya
 * =====================================================================
 * `konfirmasi()` untuk "lanjutkan atau tidak", `tanyaTeks()` untuk satu
 * kolom wajib-atau-boleh-kosong. Formulir yang butuh LEBIH dari satu kolom
 * tidak memakai dialog: ia panel di lamannya sendiri (lihat `Modal` di
 * `ui.tsx`), karena formulir tiga kolom di dalam kotak selebar 32em adalah
 * formulir yang dibaca sambil menggulir.
 */

const KELAS = {
    popup: 'swal-cbt',
    title: 'swal-cbt-judul',
    htmlContainer: 'swal-cbt-isi',
    actions: 'swal-cbt-aksi',
    confirmButton: 'swal-cbt-ya',
    cancelButton: 'swal-cbt-tidak',
    input: 'swal-cbt-input',
    validationMessage: 'swal-cbt-galat',
} as const;

/** Opsi yang sama untuk kedua fungsi - satu tempat, bukan dua salinan. */
const DASAR = {
    buttonsStyling: false,
    reverseButtons: true,
    // Escape menutup, dan itu sama dengan "batal" - dua jalan yang sama
    // menuju jawaban yang sama tidak boleh menghasilkan dua jawaban.
    focusCancel: true,
    returnFocus: false,
} as const;

type Nada = 'bahaya' | 'tanya';

/**
 * Apakah laman yang sedang tampil memakai rangka ujian gelap.
 *
 * Dibaca dari DOM, bukan dari parameter, karena popupnya dipasang di
 * `<body>` - di luar subtree `.rangka-ujian` - sehingga tidak mewarisi
 * paletnya sendiri. Satu-satunya tempat yang TAHU tema laman adalah laman
 * itu, dan ia sudah menuliskannya sebagai kelas. Meminta setiap pemanggil
 * mengirim `gelap: true` berarti setiap laman baru berpeluang lupa, dan
 * dialog putih di atas halaman gelap adalah kegagalan yang terlihat.
 */
function sedangGelap(): boolean {
    return document.querySelector('.rangka-ujian') !== null;
}

type KelasPopup = { [K in keyof typeof KELAS]: string };

function kelasPopup(nada: Nada): KelasPopup {
    const popup = [KELAS.popup, nada === 'bahaya' ? 'swal-cbt--bahaya' : '', sedangGelap() ? 'swal-cbt--gelap' : '']
        .filter(Boolean)
        .join(' ');

    return { ...KELAS, popup };
}

export type OpsiKonfirmasi = {
    judul: string;
    pesan?: string;
    /** Kata tombol yang menyetujui. Sebut AKIBATNYA, bukan "OK". */
    labelYa?: string;
    labelTidak?: string;
    /**
     * `bahaya` memberi ikon peringatan dan tombol merah. Dipakai untuk
     * tindakan yang menghapus atau tidak bisa dibatalkan - bukan untuk
     * setiap konfirmasi, karena ikon peringatan pada "Simpan?" melatih orang
     * mengabaikannya.
     */
    nada?: Nada;
};

/**
 * Tanya izin sebelum satu aksi. `true` berarti lanjutkan.
 *
 * Nilainya `false` juga saat dialognya DITUTUP - tekan Escape, klik latarnya,
 * atau tombol batal. Tidak ada keadaan ketiga: aksi yang tidak disetujui
 * sama dengan aksi yang tidak dijalankan.
 */
export async function konfirmasi({
    judul,
    pesan,
    labelYa = 'Lanjutkan',
    labelTidak = 'Batal',
    nada = 'tanya',
}: OpsiKonfirmasi): Promise<boolean> {
    const hasil = await Swal.fire({
        ...DASAR,
        customClass: kelasPopup(nada),
        title: judul,
        text: pesan,
        icon: nada === 'bahaya' ? 'warning' : undefined,
        showCancelButton: true,
        confirmButtonText: labelYa,
        cancelButtonText: labelTidak,
    });

    return hasil.isConfirmed;
}

export type OpsiTanyaTeks = {
    judul: string;
    pesan?: string;
    label?: string;
    petunjuk?: string;
    nilaiAwal?: string;
    /** `false` membuat kolom kosong tetap diterima - lihat `alasan` sesi. */
    wajib?: boolean;
    /** Karakter minimum bila wajib. Bawaannya 4, sama dengan validasi server. */
    minimal?: number;
    maksimal?: number;
    labelYa?: string;
    labelTidak?: string;
};

/**
 * Tanya satu kalimat. `null` berarti dialognya dibatalkan.
 *
 * Bedanya dengan `nilaiAwal` kosong yang dikonfirmasi: string kosong berarti
 * "saya memang tidak menulis apa pun", dan `null` berarti "saya tidak jadi".
 * Pemanggil yang menaruh keduanya di tempat yang sama akan mengirim
 * permintaan yang tidak diminta siapa pun.
 */
export async function tanyaTeks({
    judul,
    pesan,
    label,
    petunjuk,
    nilaiAwal = '',
    wajib = true,
    minimal = 4,
    maksimal = 200,
    labelYa = 'Kirim',
    labelTidak = 'Batal',
}: OpsiTanyaTeks): Promise<string | null> {
    const hasil = await Swal.fire({
        ...DASAR,
        customClass: kelasPopup('tanya'),
        title: judul,
        text: pesan,
        input: 'textarea',
        inputValue: nilaiAwal,
        inputLabel: label,
        inputAttributes: {
            maxlength: String(maksimal),
            rows: '3',
            // `petunjuk` masuk ke `aria-describedby`, bukan ditempel di
            // placeholder: placeholder hilang begitu orang mulai mengetik,
            // dan justru saat itulah keterangannya dibutuhkan.
            'aria-label': label ?? judul,
        },
        inputValidator: (nilai) => {
            const teks = (nilai ?? '').trim();

            if (!wajib && teks === '') {
                return undefined;
            }

            if (teks.length < minimal) {
                return `Tulis minimal ${minimal} karakter supaya alasannya bisa dipertanggungjawabkan.`;
            }

            return undefined;
        },
        showCancelButton: true,
        confirmButtonText: labelYa,
        cancelButtonText: labelTidak,
        footer: petunjuk,
    });

    return hasil.isConfirmed ? ((hasil.value as string | undefined) ?? '').trim() : null;
}

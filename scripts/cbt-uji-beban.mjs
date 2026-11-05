/*
| cbt-uji-beban - uji beban klien HTTP langsung untuk PRD §19.3.
|
| ---------------------------------------------------------------------------
| Klien HTTP, bukan peramban
| ---------------------------------------------------------------------------
| Satu peramban per siswa tidak mungkin: 300 salinan Chrome memakan belasan
| gigabita RAM pada mesin yang menguji, dan yang akan terukur adalah mesin
| pengujinya, bukan aplikasinya. Yang diukur di sini adalah BIAYA PERMINTAAN
| aplikasi - dan jalur HTTP-nya persis sama, karena setiap permintaan dikirim
| ke rute yang sama dengan yang dikirim peramban siswa.
|
| Satu hal yang memang TIDAK terukur dengan cara ini: biaya render React di
| perangkat siswa. Itu biaya yang tidak ditanggung server, dan karena itu
| memang tidak masuk target PRD §19.3.
|
| ---------------------------------------------------------------------------
| Yang dijalankan adalah jalur yang SUNGGUH dipakai
| ---------------------------------------------------------------------------
| Alurnya nyata dari awal sampai akhir: masuk sebagai siswa sungguhan lewat
| POST /login, membuka gerbang dengan token sungguhan, lalu menyimpan jawaban
| ke butir yang diambil dari halaman kerjakan siswa itu sendiri. Butirnya
| TIDAK dikarang di sini - kalau dikarang, yang diuji adalah jalur yang tidak
| ada, dan itu akan lulus sambil tidak membuktikan apa pun.
|
| ---------------------------------------------------------------------------
| Yang dilaporkan, dan mengapa p95 dan bukan rata-rata
| ---------------------------------------------------------------------------
| PRD §19.3 menulis P95 ≤ 2 detik untuk penyimpanan jawaban dan ≤ 3 detik
| untuk halaman operasional. Rata-rata yang memenuhi target sementara p95-nya
| tidak berarti sebagian siswa menunggu jauh lebih lama daripada yang lain -
| dan merekalah yang menekan tombol dua kali, lalu mengira jawabannya hilang
| padahal tersimpan.
|
| ---------------------------------------------------------------------------
| Pemakaian
| ---------------------------------------------------------------------------
|   CBT_BASE=http://127.0.0.1:8781 \
|   CBT_SISWA=20 CBT_PUTARAN=5 CBT_TOKEN=123456 \
|   node scripts/cbt-uji-beban.mjs
|
| Untuk 300 konkuren, lihat docs/BEBAN.md - dan jalankan di server target.
| Pada `php artisan serve` angka apa pun adalah batas biaya per-permintaan,
| bukan kapasitas server; skrip ini menuliskannya sendiri di keluarannya
| supaya tidak ada yang salah membaca.
|
|   CBT_BERSIHKAN=1 ... node scripts/cbt-uji-beban.mjs
|
| Mengakhiri uji beban: menghapus attempt milik akun `beban*` (beserta butir,
| jawaban, hasil, dan pelanggarannya, lewat foreign key) dan menghapus akun
| itu sendiri dari basis data CBT_BASE melayani. Penempatan dan presensinya
| ikut hilang bersama akunnya, jadi basis data kembali ke keadaan sebelum
| uji disiapkan dari sisi peniru.
|
| Akun contoh dari `DatabaseSeeder` TIDAK disentuh. Uji beban yang
| membersihkan pemandangannya sendiri tidak akan pernah dijalankan orang.
*/
import { access, readFile } from 'node:fs/promises';
import { BASE } from './cek-common.mjs';

const BERSIHKAN = process.env.CBT_BERSIHKAN === '1';

const JUMLAH_SISWA = Number(process.env.CBT_SISWA ?? 20);
const PUTARAN = Number(process.env.CBT_PUTARAN ?? 5);
const TUNGGU_MULAI_MS = Number(process.env.CBT_TUNGGU_MULAI ?? 400);

/** Kata sandi peniru. Sama untuk semuanya - lihat catatan di peniru(). */
const SANDI = process.env.CBT_SANDI ?? 'password';

/*
 * Dua sasaran PRD §19.3, dan angkanya BERBEDA - itu bukan salah ketik.
 *
 * Simpan jawaban 2 detik, halaman operasional 3 detik. Menyamakan keduanya
 * menjadi 2 detik berarti laporan yang menyatakan gagal padahal lulus, dan
 * laporan yang salah lebih buruk daripada tidak ada laporan: ia mengirim
 * orang mengejar penyebab yang tidak ada.
 *
 * Keduanya hanya berarti pada KONKURENSI 300. Pada `php artisan serve`
 * (satu utas) tanda OK di bawah ini berarti biaya satu permintaan di bawah
 * sasaran, bukan bahwa seratus permintaan bersamaan juga demikian. Lihat
 * kepala berkas docs/BEBAN.md.
 */
const SASARAN_JAWAB_MS = 2000;
const SASARAN_PAPAN_MS = 3000;

/*
 * Konkurensi yang menuntut penilaian lulus/tidak, dan mengapa ia bisa diubah.
 *
 * PRD §19.3 menuntut 300 siswa, dan angkanya mengikat pada LINGKUNGAN UJI YANG
 * DISEPAKATI - jadi server target yang menyepakati angka lain boleh
 * menyetelnya lewat CBT_KONKUREN_MENGIKAT tanpa menyentuh kode ini.
 *
 * Bawaannya tetap 300. Menurunkannya bukan cara membuat uji ini lulus: yang
 * berubah hanya apakah skripnya berani menjatuhkan vonis, bukan apakah p95-nya
 * memenuhi sasaran. Angka yang dilaporkannya tetap angka yang sama.
 */
const KONKUREN_MENGIKAT = Number(process.env.CBT_KONKUREN_MENGIKAT ?? 300);

const hasil = { jawab: [], papan: [] };
const galat = [];

/* =====================================================================
 | Cookie jar - satu per peniru
 |===================================================================== */

function jarBaru() {
    return new Map();
}

function simpanCookie(jar, respons) {
    // `getSetCookie()` mengembalikan array; `headers.get('set-cookie')`
    // menggabungkan beberapa Set-Cookie menjadi satu string, dan
    // menggabungkannya membuat cookie kedua tidak terbaca sama sekali.
    const daftar = respons.headers.getSetCookie?.() ?? [];

    for (const satu of daftar) {
        const [pasangan] = satu.split(';');
        const idx = pasangan.indexOf('=');

        if (idx > 0) {
            jar.set(pasangan.slice(0, idx).trim(), pasangan.slice(idx + 1).trim());
        }
    }
}

function headerCookie(jar) {
    return [...jar.entries()].map(([k, v]) => `${k}=${v}`).join('; ');
}

function tokenXsrf(jar) {
    const mentah = jar.get('XSRF-TOKEN');

    return mentah === undefined ? '' : decodeURIComponent(mentah);
}

/**
 * Satu permintaan ber-cookie, dengan latensi diukur di sekelilingnya.
 *
 * Latensinya mencakup penulisan cookie DAN pembacaan badannya - `await
 * respons.text()` di luar pengukuran akan mengeluarkan waktu transfer badan
 * dari angka yang dilaporkan, dan pada halaman papan monitoring badannya
 * bukan hal yang kecil.
 *
 * ---------------------------------------------------------------------
 * Kegagalan SAMBUNGAN dikembalikan, bukan dilempar
 * ---------------------------------------------------------------------
 * Pada 300 peniru, server yang kewalahan menutup sambungan - dan itu justru
 * TEMUAN yang uji ini ada untuk mendapatkannya. Kalau `minta()` melempar,
 * yang terjadi bukan laporan berisi 300 galat sambungan, melainkan satu
 * pengecualian yang menghentikan seluruh proses dan mencetak tumpukan
 * panggilan Node. Temuannya hilang, dan yang tersisa adalah kesan bahwa
 * SKRIPNYA yang rusak.
 *
 * Karena itu sambungan yang gagal menjadi respons tiruan dengan status 0 -
 * angka yang tidak mungkin datang dari HTTP, sehingga tidak bisa tertukar
 * dengan 4xx/5xx sungguhan. Pemeriksaan status di bawahnya lalu mencatatnya
 * seperti galat biasa, dan laporan akhirnya memuat barisnya.
 */
const RESPONS_GAGAL_SAMBUNGAN = Object.freeze({
    status: 0,
    headers: { get: () => null, getSetCookie: () => [] },
});

async function minta(jar, jalur, opsi = {}) {
    const mulai = performance.now();

    let respons;

    try {
        respons = await fetch(`${BASE}${jalur}`, {
            ...opsi,
            redirect: 'manual',
            headers: {
                Accept: 'text/html, application/xhtml+xml, application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(jar.size > 0 ? { Cookie: headerCookie(jar) } : {}),
                ...(opsi.method !== undefined && opsi.method !== 'GET'
                    ? { 'X-XSRF-TOKEN': tokenXsrf(jar) }
                    : {}),
                ...(opsi.headers ?? {}),
            },
        });
    } catch (e) {
        // Termasuk tenggat waktu kehabisan dan sambungan yang ditutup server.
        return {
            respons: RESPONS_GAGAL_SAMBUNGAN,
            badan: '',
            ms: performance.now() - mulai,
            galatSambungan: e?.cause?.code ?? e?.code ?? e?.message ?? 'tidak diketahui',
        };
    }

    simpanCookie(jar, respons);

    const badan = await respons.text();
    const ms = performance.now() - mulai;

    return { respons, badan, ms };
}

function form(data) {
    return {
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(data).toString(),
    };
}

/**
 * Rincian tambahan untuk sebuah hasil `minta()`, bila sambungannya gagal.
 *
 * Dipisah supaya setiap tempat yang mencatat galat menyebut SEBABNYA, bukan
 * hanya status 0. "login/0" dan "login/0 (ECONNREFUSED)" menuntut dua
 * tindakan yang berbeda: yang pertama masih perlu diperiksa, yang kedua sudah
 * menjawab bahwa servernya yang tidak ada.
 */
function rincianSambungan(r) {
    return r?.galatSambungan === undefined ? {} : { sambungan: r.galatSambungan };
}

function json(data) {
    return {
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data),
    };
}

/* =====================================================================
 | Statistik
 |===================================================================== */

function persentil(angka, p) {
    if (angka.length === 0) return NaN;

    const urut = [...angka].sort((a, b) => a - b);
    // Indeks terdekat ke atas: dengan 100 sampel, p95 harus menjadi sampel
    // ke-95, bukan interpolasi antara ke-94 dan ke-95. Interpolasi
    // menghasilkan angka yang tidak pernah benar-benar terjadi.
    const idx = Math.min(urut.length - 1, Math.ceil((p / 100) * urut.length) - 1);

    return urut[idx];
}

function ringkas(angka) {
    if (angka.length === 0) return { n: 0 };

    return {
        n: angka.length,
        min: Math.round(Math.min(...angka)),
        p50: Math.round(persentil(angka, 50)),
        p95: Math.round(persentil(angka, 95)),
        p99: Math.round(persentil(angka, 99)),
        maks: Math.round(Math.max(...angka)),
    };
}

function barisRingkas(label, angka, sasaranMs) {
    const r = ringkas(angka);

    if (r.n === 0) {
        console.log(`  ${label.padEnd(22)} (tidak ada sampel)`);

        return;
    }

    const pad = (n) => String(n).padStart(5);
    const tanda = r.p95 <= sasaranMs ? 'OK  ' : 'LEWAT';

    console.log(
        `  ${label.padEnd(22)} n=${String(r.n).padEnd(5)} ` +
            `min=${pad(r.min)}ms  p50=${pad(r.p50)}ms  ` +
            `p95=${pad(r.p95)}ms  p99=${pad(r.p99)}ms  maks=${pad(r.maks)}ms  ` +
            `[${tanda} p95 <= ${sasaranMs}ms]`,
    );
}

/* =====================================================================
 | Halaman Inertia
 |===================================================================== */

/**
 * Props Inertia dari satu badan HTML.
 *
 * Atribut `data-page` berisi ID elemennya ("app"), BUKAN JSON-nya - dan itu
 * jebakan yang tidak berbunyi: pola `data-page="([^"]*)"` cocok dengan rapi,
 * mengembalikan "app", dan `JSON.parse` gagal pada string pendek yang tampak
 * seperti data. JSON-nya ada di ISI elemen itu.
 */
function propsDariHalaman(badan) {
    const cocok = badan.match(/<script[^>]*data-page="app"[^>]*>([\s\S]*?)<\/script>/);

    if (cocok === null) return null;

    try {
        return JSON.parse(cocok[1])?.props ?? null;
    } catch {
        return null;
    }
}

/* =====================================================================
 | Akun peniru
 |===================================================================== */

/**
 * Daftar siswa yang dipakai peniru, dibaca dari basis data lewat Tinker
 * sekali di awal - BUKAN dikarang di sini.
 *
 * Mengarang `siswa.beban.1..N` akan menghasilkan N kegagalan login yang
 * terlihat seperti masalah beban. Yang dibutuhkan adalah akun yang benar-
 * benar ada, sudah diverifikasi presensinya, dan punya penempatan di sesi
 * yang sedang berjalan - dan itu hanya bisa dijawab basis datanya.
 */
/**
 * Apakah `public/hot` ada - penanda dev server Vite.
 *
 * Dibaca dari sistem berkas, bukan dengan memanggil Laravel: skrip ini klien
 * HTTP dan tidak boleh menyentuh basis data atau aplikasi di luar permintaan
 * yang diukurnya. Jalurnya relatif terhadap direktori kerja, sama seperti
 * `storage/app/private/beban-akun.txt` di `daftarSiswa()`.
 */
async function adaBerkasHot() {
    try {
        await access('public/hot');
        return true;
    } catch {
        return false;
    }
}

async function daftarSiswa() {
    const dariEnv = (process.env.CBT_SISWA_DAFTAR ?? '')
        .split(',')
        .map((s) => s.trim())
        .filter(Boolean);

    if (dariEnv.length > 0) {
        return dariEnv.map((username) => ({ username }));
    }

    // Berkas yang ditulis `SeederBeban`. Membacanya langsung menghapus satu
    // langkah salin-tempel yang panjangnya tiga ratus username - dan salah
    // satu username yang keliru akan terbaca di laporan sebagai kegagalan
    // login, yaitu sebagai masalah kapasitas padahal masalah salinan.
    const berkas = process.env.CBT_BEBAN_AKUN
        ?? new URL('../storage/app/private/beban-akun.txt', import.meta.url);

    try {
        const isi = await readFile(berkas, 'utf8');

        const daftar = isi.split('\n').map((s) => s.trim()).filter(Boolean);

        if (daftar.length > 0) {
            return daftar.map((username) => ({ username }));
        }
    } catch {
        // Tidak ada berkasnya - pesan di bawah yang menjelaskan.
    }

    return null;
}

/* =====================================================================
 | Alur satu peniru
 |===================================================================== */

/** Masuk sebagai satu akun. 302 ke dashboard = berhasil. */
async function masuk(jar, username) {
    await minta(jar, '/login');

    const r = await minta(jar, '/login', {
        method: 'POST',
        ...form({ username, password: SANDI }),
    });

    const lokasi = r.respons.headers.get('location') ?? '';
    const ok = r.respons.status === 302 && !lokasi.endsWith('/login');

    // Field-nya dibiarkan bernama `galatSambungan` supaya pemanggilnya bisa
    // memakai `rincianSambungan()` seperti pada hasil `minta()` yang lain -
    // kalau di sini ia diganti nama, pembungkusan kedua tidak menemukan apa
    // pun dan sebab sambungannya hilang tepat pada tahap yang paling sering
    // gagal.
    return { ok, lokasi, status: r.respons.status, galatSambungan: r.galatSambungan };
}

/**
 * Bentuk `isi` yang sah untuk satu butir, dari jenis dan opsinya.
 *
 * `isi` BUKAN `jawaban`: `JawabanService::simpan()` membaca kunci `isi`,
 * dan nama yang salah akan tersimpan sebagai jawaban kosong tanpa galat -
 * yang berarti permintaannya tetap 200 dan waktunya tetap terukur, tetapi
 * yang diukur bukan penyimpanan jawaban. Itu persis kegagalan yang paling
 * sulit terlihat dari laporan: angkanya bagus dan artinya salah.
 */
function isiUntuk(butir) {
    const opsi = (butir.opsi ?? []).map((o) => o?.kunci).filter((k) => typeof k === 'string');

    switch (butir.jenis) {
        case 'pilihan_ganda':
        case 'benar_salah':
        case 'media':
            return opsi.length > 0 ? { opsi: [opsi[0]] } : { teks: 'Jawaban uji beban.' };

        case 'jawaban_ganda':
            return { opsi: opsi.slice(0, 2) };

        case 'angka':
            return { nilai: '12' };

        case 'menjodohkan': {
            const kiri = (butir.butir ?? []).map((b) => b?.kunci).filter(Boolean);
            const kanan = (butir.pasangan ?? []).map((p) => p?.kunci).filter(Boolean);

            if (kiri.length === 0 || kanan.length === 0) {
                return { teks: 'Jawaban uji beban.' };
            }

            const pasangan = {};

            kiri.forEach((k, i) => {
                pasangan[k] = kanan[i % kanan.length];
            });

            return { pasangan };
        }

        case 'mengurutkan': {
            const item = (butir.butir ?? []).map((b) => b?.kunci).filter(Boolean);

            return item.length > 0 ? { urutan: item } : { teks: 'Jawaban uji beban.' };
        }

        default:
            return { teks: 'Jawaban uji beban.' };
    }
}

/** Satu peniru: masuk, buka gerbang, lalu menyimpan jawaban berulang kali. */
async function peniru(nomor, akun) {
    const jar = jarBaru();

    const login = await masuk(jar, akun.username);

    if (!login.ok) {
        galat.push({
            nomor,
            tahap: 'login',
            status: login.status,
            lokasi: login.lokasi,
            ...rincianSambungan(login),
        });

        return;
    }

    // Penempatan dibaca dari halaman jadwal siswa itu sendiri, bukan dari
    // env: id penempatan bergantung pada apa yang di-seed, dan id yang
    // dikarang akan menghasilkan 404 yang tampak seperti masalah beban.
    const jadwal = await minta(jar, '/siswa');
    const propsJadwal = propsDariHalaman(jadwal.badan) ?? {};

    const baris = propsJadwal.utama ?? (propsJadwal.jadwal ?? [])[0] ?? null;
    const pesertaId = Number(baris?.peserta_id ?? 0);

    if (pesertaId === 0) {
        galat.push({
            nomor,
            tahap: 'baca-peserta',
            status: jadwal.respons.status,
            komponen: propsJadwal.component ?? '?',
            ...rincianSambungan(jadwal),
        });

        return;
    }

    // Gerbang: halaman, lalu POST token. Anak yang sudah pernah membuka
    // gerbangnya memakai jalur yang sama - tombolnya berbunyi "Lanjutkan".
    await minta(jar, `/siswa/ujian/${pesertaId}/gerbang`);

    const buka = await minta(jar, `/siswa/ujian/${pesertaId}/gerbang`, {
        method: 'POST',
        ...form({ token: process.env.CBT_TOKEN ?? '' }),
    });

    // 302 ke /kerjakan = attempt berjalan. Sesi yang tidak ber-token tetap
    // mengembalikan 302, jadi keduanya tidak perlu dibedakan di sini.
    const keKerjakan = (buka.respons.headers.get('location') ?? '').includes('/kerjakan');

    const kerjakan = await minta(jar, `/siswa/ujian/${pesertaId}/kerjakan`);

    if (kerjakan.respons.status !== 200) {
        galat.push({
            nomor,
            tahap: 'kerjakan',
            status: kerjakan.respons.status,
            buka: buka.respons.status,
            keKerjakan,
            ...rincianSambungan(kerjakan),
        });

        return;
    }

    const props = propsDariHalaman(kerjakan.badan) ?? {};
    const butir = (props.butir ?? []).filter((b) => typeof b?.id === 'number');

    if (butir.length === 0) {
        galat.push({
            nomor,
            tahap: 'baca-butir',
            status: kerjakan.respons.status,
            ...rincianSambungan(kerjakan),
        });

        return;
    }

    // Versi per butir dipegang klien, sama seperti peramban siswa - dan
    // tanpa itu setiap simpanan kedua akan dijawab 409 oleh pemeriksaan
    // optimistic concurrency.
    const versi = new Map(
        butir.map((b) => [b.id, Number(b.jawaban?.versi ?? 0)]),
    );

    for (let i = 0; i < PUTARAN; i++) {
        const satu = butir[i % butir.length];

        const r = await minta(jar, `/siswa/ujian/${pesertaId}/butir/${satu.id}`, {
            method: 'POST',
            ...json({ isi: isiUntuk(satu), versi: versi.get(satu.id) ?? 0 }),
        });

        if (r.respons.status >= 400) {
            galat.push({
                nomor,
                tahap: 'jawab',
                status: r.respons.status,
                butir: satu.id,
                ...rincianSambungan(r),
            });

            continue;
        }

        try {
            const jawab = JSON.parse(r.badan);

            if (typeof jawab.versi === 'number') {
                versi.set(satu.id, jawab.versi);
            }
        } catch {
            // Badan yang bukan JSON pada respons 2xx tidak menggagalkan
            // pengukuran latensi - yang dicatat adalah waktunya.
        }

        hasil.jawab.push(r.ms);
    }
}

/* =====================================================================
 | Papan operasional
 |===================================================================== */

async function papanOperasional() {
    const username = process.env.CBT_ADMIN ?? '';

    if (username === '') {
        console.log('  (papan operasional dilewati - CBT_ADMIN tidak disetel)');

        return;
    }

    const jar = jarBaru();

    await masuk(jar, username);

    const jalur = process.env.CBT_PAPAN === ''
        ? []
        : (process.env.CBT_PAPAN ?? '/admin/monitoring').split(',');

    for (const satu of jalur) {
        for (let i = 0; i < PUTARAN; i++) {
            const r = await minta(jar, satu);

            if (r.respons.status >= 400) {
                galat.push({
                    tahap: 'papan',
                    status: r.respons.status,
                    jalur: satu,
                    ...rincianSambungan(r),
                });

                continue;
            }

            hasil.papan.push(r.ms);
        }
    }
}

/* =====================================================================
 | Jalan
 |===================================================================== */

/**
 * Akhiri uji beban: hapus peniru, attempt-nya, dan semua turunannya.
 *
 * Dipanggil lewat `CBT_BERSIHKAN=1` dan **tidak** menjalankan uji apa pun -
 * ia membersihkan lalu keluar. Alasannya di docblock atas: basis data uji
 * yang masih berisi tiga ratus akun `beban*` dari sesi lalu membuat seeder
 * berikutnya berjalan di atas puing, dan puing itu terbaca sebagai kegagalan
 * uji (khususnya attempt yang kesempatannya sudah habis).
 */
async function bersihkan() {
    // Pengaman yang sama seperti `SeederBeban`: nama basis data harus
    // berakhiran `_beban`. Pelindung terakhir terhadap satu salah ketik
    // `DB_DATABASE` yang menghapus tiga ratus siswa sungguhan.
    const lingkungan = await readFile('.env', 'utf8').catch(() => '');
    const baris = lingkungan.split('\n').find((b) => b.startsWith('DB_DATABASE='));
    const nama = process.env.DB_DATABASE ?? baris?.slice('DB_DATABASE='.length).trim() ?? '';

    if (! nama.endsWith('_beban')) {
        console.error(`DITOLAK: basis data '${nama || '(tidak diketahui)'}' bukan basis data beban.`);
        console.error('CBT_BERSIHKAN menghapus akun dan attempt. Namanya harus berakhiran _beban.');
        process.exit(2);
    }

    console.log(`Membersihkan sisa uji beban - ${BASE} (basis data ${nama})`);
    console.log('');

    // Lewat Tinker, karena skrip ini klien HTTP murni dan tidak memegang
    // koneksi basis data. `tanpaTenant()` dipakai eksplisit dengan alasan
    // yang sama seperti di `SeederBeban`: tanpa pengguna, scope sekolah
    // diam - tetapi membersihkan lintas sekolah TIDAK boleh bergantung
    // pada kebetulan itu.
    // Nama kelas DITULIS LENGKAP, bukan lewat `use`: Tinker `--execute`
    // menempelkan potongan ini sebagai BADAN method, dan `use` tidak sah di
    // sana.
    //
    // `forceDelete`, bukan `delete`. `User` memakai `SoftDeletes`, jadi
    // `delete()` hanya mengisi `deleted_at`: barisnya tetap ada, username
    // `beban1` tetap terpakai indeks uniknya, dan seeder berikutnya gagal
    // dengan galat duplikat - puing yang bentuknya berbeda tetapi sebabnya
    // sama. Yang dihapus fisik, cascade FK `student_id` yang merambat:
    // `exam_participants` -> `exam_attempts` -> `attempt_questions` ->
    // `student_answers`, plus `attendance_records`, `class_enrollments`,
    // `event_participants`, dan `student_profiles`.
    //
    // Urutannya akun dulu, sengaja: attempt yang dihapus lebih dulu hanya
    // memindahkan kerja yang sama ke beberapa kueri, sementara hapus akun
    // adalah SATU pernyataan yang membiarkan MySQL menelusuri sendiri rantai
    // cascade-nya.
    //
    // Satu baris, dan kutip tunggal di dalamnya: kode ini dibungkus kutip
    // tunggal shell supaya variabelnya tidak dikembangkan lebih dulu.
    const kode = [
        "$sebelum = App\\Domain\\Identity\\Models\\User::withTrashed()",
        "->where('username', 'like', 'beban%')->count();",
        "$akun = App\\Domain\\Identity\\Models\\User::withTrashed()",
        "->where('username', 'like', 'beban%')->pluck('id');",
        "$jejak = Illuminate\\Support\\Facades\\DB::table('activity_logs')",
        "->whereIn('user_id', $akun)->delete();",
        "$peserta = App\\Domain\\Exam\\Models\\ExamParticipant::query()",
        "->whereIn('student_id', $akun)->count();",
        "$attempt = App\\Domain\\Attendance\\Models\\ExamAttempt::tanpaTenant()",
        "->whereIn('exam_participant_id', App\\Domain\\Exam\\Models\\ExamParticipant::query()",
        "->whereIn('student_id', $akun)->pluck('id'))->count();",
        "$dihapus = App\\Domain\\Identity\\Models\\User::withTrashed()",
        "->whereIn('id', $akun)->forceDelete();",
        "$sisa = App\\Domain\\Identity\\Models\\User::withTrashed()",
        "->where('username', 'like', 'beban%')->count();",
        "$lain = App\\Domain\\Identity\\Models\\User::query()",
        "->where('username', 'not like', 'beban%')->count();",
        'echo "sebelum: akun={$sebelum} peserta={$peserta} attempt={$attempt}\\n";',
        'echo "sesudah: akun={$sisa} akun_dihapus={$dihapus} log_dihapus={$jejak} non_beban_tersisa={$lain}\\n";',
    ].join(' ');

    const { execFileSync } = await import('node:child_process');

    // PHP diambil dari `PHP_BINARY` bila ada, bukan dari `php` di PATH.
    // Pada mesin ini (Git Bash di Windows) `php` tidak ada di PATH yang
    // dilihat skrip - dan menuliskan jalurnya di sini berarti skrip ini
    // hanya jalan di satu mesin. Tanpa `PHP_BINARY` ia jatuh ke `php` biasa,
    // yang benar di server Linux.
    const php = process.env.PHP_BINARY || 'php';

    // `execFileSync` dengan daftar argumen, BUKAN `execSync` dengan satu
    // string dan `shell: true`. Ini bukan selera; versi sebelumnya gagal
    // karena dua sebab sekaligus:
    //
    // 1. `shell: true` di Windows menjalankan cmd.exe, dan cmd.exe TIDAK
    //    mengenal kutip tunggal. Kode yang dibungkus `'...'` sampai ke PHP
    //    lengkap dengan kutipnya, sehingga kodenya bukan PHP yang sah.
    // 2. Sebelum itu, kutip ganda membuat bash mengembangkan `$akun` dan
    //    kawan-kawannya sebelum PHP sempat melihatnya.
    //
    // Dengan daftar argumen tidak ada shell sama sekali, jadi tidak ada
    // yang mengembang atau memotong apa pun. Kode berisi kutip ganda di
    // dalamnya - itu sah karena ia satu argumen utuh.
    //
    // Kedua aliran ditangkap. Tinker menulis galatnya ke STDOUT, bukan
    // STDERR - tanpa ini, Tinker yang gagal keluar dengan pesan kosong dan
    // penyebabnya hilang, tepat seperti `fetch()` di catatan (e).
    try {
        const keluaran = execFileSync(php, ['artisan', 'tinker', '--execute', kode], {
            env: { ...process.env, DB_DATABASE: nama },
            encoding: 'utf8',
            stdio: ['ignore', 'pipe', 'pipe'],
        });
        console.log(keluaran.trim());
    } catch (e) {
        const galat = String(e.stderr ?? '') + String(e.stdout ?? '') + e.message;
        console.error('Gagal membersihkan:', galat.slice(0, 3000));
        process.exit(2);
    }

    console.log('');
    console.log('Sisa uji beban dihapus. Akun contoh dari DatabaseSeeder tidak disentuh.');
    process.exit(0);
}

async function utama() {
    console.log(`Uji beban CBT - ${BASE}`);

    // `public/hot` menandai dev server Vite sedang berjalan, dan uji beban
    // seharusnya mengukur jalur produksi - di sana berkas itu tidak ada.
    //
    // Dua akibatnya berbeda besar. Yang pertama sudah ditutup: selama berkas
    // ini ada, paket Inertia (bawaannya `ssr.enabled = true`) mengira SSR
    // hidup lalu mengirim setiap halaman ke endpoint SSR yang tidak ada,
    // ~1,4 detik per halaman. `.env` sekarang memuat
    // `INERTIA_SSR_ENABLED=false`, jadi biaya itu hilang. Yang KEDUA tetap
    // ada dan tidak bisa ditutup dari sini: asetnya dilayani dev server,
    // bukan `public/build`.
    //
    // Karena itu diperiksa dan diberitahukan, bukan diam-diam diterima. Uji
    // beban yang mengukur jalur pengembangan lalu melaporkannya sebagai
    // biaya produksi adalah uji beban yang mengirim orang mengejar penyebab
    // yang tidak ada di server target.
    if (await adaBerkasHot()) {
        console.log('');
        console.log('CATATAN: public/hot ada, jadi aset dilayani dev server Vite -');
        console.log('  bukan jalur produksi. Kalau `INERTIA_SSR_ENABLED=false` belum');
        console.log('  ada di .env, setiap halaman Inertia TAMBAH membayar ~1,4 detik');
        console.log('  percobaan SSR yang tidak pernah ada, dan halaman operasional');
        console.log('  yang paling terpukul. Untuk mengukur jalur produksi:');
        console.log('');
        console.log('    mv public/hot /tmp/hot && node scripts/cbt-uji-beban.mjs');
        console.log('    mv /tmp/hot public/hot');
        console.log('');
    }

    const daftar = await daftarSiswa();

    if (daftar === null) {
        console.error('CBT_SISWA_DAFTAR belum disetel, dan berkas daftar akun');
        console.error('(`storage/app/private/beban-akun.txt`) belum ada. Isinya ditulis');
        console.error('oleh SeederBeban, satu username per baris:');
        console.error('');
        console.error('  DB_DATABASE=cbt_beban CBT_BEBAN_SISWA=40 \\');
        console.error('    php artisan db:seed --class=SeederBeban --force');
        console.error('');
        console.error('  CBT_TOKEN=<kode dari keluaran seeder> CBT_SISWA=40 \\');
        console.error('    node scripts/cbt-uji-beban.mjs');
        console.error('');
        console.error('Daftarnya juga boleh diberikan langsung lewat CBT_SISWA_DAFTAR,');
        console.error('dipisah koma. Sengaja tidak dikarang di sini: peniru bernama');
        console.error('`beban0001` yang tidak ada akan menghasilkan kegagalan login yang');
        console.error('terbaca seperti masalah beban.');
        process.exit(2);
    }

    if (daftar.length < JUMLAH_SISWA) {
        console.error(
            `Daftar akun memuat ${daftar.length} username, tetapi CBT_SISWA=${JUMLAH_SISWA}.`,
        );
        process.exit(2);
    }

    console.log(`  peniru: ${JUMLAH_SISWA}   putaran jawaban: ${PUTARAN}`);
    console.log('');

    const mulai = performance.now();

    // Digelombangkan dengan jeda kecil, bukan dilepas serentak: 300 koneksi
    // TCP yang lahir pada milidetik yang sama mengukur SYN backlog kernel,
    // bukan aplikasinya. Jeda ini menirukan siswa yang menekan tombol
    // bergiliran dalam beberapa detik pertama ujian.
    const gelombang = [];

    for (let i = 1; i <= JUMLAH_SISWA; i++) {
        gelombang.push(peniru(i, daftar[i - 1]));

        if (i % 10 === 0) {
            await new Promise((r) => setTimeout(r, TUNGGU_MULAI_MS));
        }
    }

    await Promise.all(gelombang);

    await papanOperasional();

    const durasi = Math.round((performance.now() - mulai) / 1000);

    console.log('');
    console.log(`Selesai dalam ${durasi} detik.`);
    console.log('');
    console.log('--- penyimpanan jawaban (PRD §19.3: p95 <= 2000ms) ---');
    barisRingkas('simpan jawaban', hasil.jawab, SASARAN_JAWAB_MS);
    console.log('');
    console.log('--- pemuatan halaman operasional (PRD §19.3: p95 <= 3000ms) ---');
    barisRingkas('papan operasional', hasil.papan, SASARAN_PAPAN_MS);

    if (galat.length > 0) {
        console.log('');
        console.log(`--- ${galat.length} permintaan GAGAL (tidak dirata-ratakan) ---`);

        const perTahap = new Map();

        for (const g of galat) {
            // Sebab sambungan ikut ke dalam kuncinya. "jawab/0" tanpa
            // keterangan memaksa orang membuka log server untuk tahu apakah
            // yang gagal itu aplikasinya atau soketnya - dan pada uji beban,
            // itu perbedaan antara dua kesimpulan yang berlawanan.
            const kunci = `${g.tahap}/${g.status ?? '?'}${g.sambungan ? ` (${g.sambungan})` : ''}`;
            perTahap.set(kunci, (perTahap.get(kunci) ?? 0) + 1);
        }

        for (const [kunci, n] of perTahap) {
            console.log(`  ${kunci}: ${n}`);
        }
    }

    console.log('');
    console.log('CATATAN: pada `php artisan serve` angka di atas adalah BATAS biaya');
    console.log('per-permintaan, bukan kapasitas server - servernya satu-utas. Baca');
    console.log('docs/BEBAN.md sebelum menyimpulkan apa pun dari keluaran ini.');

    /*
     * Kode keluar 3 = "selesai, tetapi lewat sasaran".
     *
     * Dipisahkan dari kode 1 (ada permintaan gagal) dan 2 (tidak bisa jalan),
     * karena ketiganya menuntut tindakan yang berbeda. Dan ia hanya dipakai
     * pada konkurensi yang mengikat PRD §19.3 - 300. Di bawah itu, "lewat
     * sasaran" adalah keadaan yang DIHARAPKAN pada server satu-utas, dan
     * menjadikannya kegagalan berarti setiap jalan pengembangan berakhir
     * merah tanpa ada yang salah.
     *
     * Selisihnya bukan hiasan: pada server target, langkah 3 di docs/BEBAN.md
     * §6 menuntut orang menjalankan remediasi SATU LANGKAH pada satu waktu.
     * Kode keluar yang bisa dibaca skrip membuat "sudah lulus?" dijawab mesin,
     * bukan mata.
     *
     * ---------------------------------------------------------------------
     * Galat diperiksa LEBIH DULU, dan itu urutan yang penting
     * ---------------------------------------------------------------------
     * Percobaan pertama pada 300 mengembalikan 150 galat (132 di antaranya
     * sambungan yang ditolak) DAN p95 lewat sasaran - dan vonis yang keluar
     * hanya "p95 lewat sasaran". Itu kesimpulan yang salah: dari 750 sampel
     * yang tersisa, tidak satu pun diambil pada saat server benar-benar
     * melayani tiga ratus peniru. Yang terukur adalah aplikasi yang sudah
     * kehabisan tenaga, bukan aplikasi di bawah beban.
     *
     * Karena itu galat yang lebih dulu diputuskan: pengukuran yang tidak sah
     * tidak boleh menghasilkan vonis kapasitas, apa pun angka p95-nya.
     */
    if (galat.length > 0) {
        console.log('');
        console.log(
            `TIDAK SAH: ${galat.length} permintaan gagal, jadi p95 di atas ` +
                'TIDAK mengukur aplikasi di bawah beban penuh. Jelaskan dan ' +
                'perbaiki galatnya lebih dulu - lihat docs/BEBAN.md §5.',
        );

        process.exit(1);
    }

    const mengikat = JUMLAH_SISWA >= KONKUREN_MENGIKAT;
    const lewat = [];
    const rJawab = ringkas(hasil.jawab);
    const rPapan = ringkas(hasil.papan);

    if (rJawab.n > 0 && rJawab.p95 > SASARAN_JAWAB_MS) lewat.push('simpan jawaban');
    if (rPapan.n > 0 && rPapan.p95 > SASARAN_PAPAN_MS) lewat.push('halaman operasional');

    if (lewat.length > 0 && mengikat) {
        console.log('');
        console.log(`TIDAK LULUS: p95 lewat sasaran pada ${lewat.join(' dan ')}.`);
        console.log('Remediasi ada di docs/BEBAN.md §3.4 - satu langkah pada satu waktu.');

        process.exit(3);
    }

    if (lewat.length > 0) {
        console.log('');
        console.log(
            `Catatan: p95 lewat sasaran pada ${lewat.join(' dan ')}, tetapi ` +
                `CBT_SISWA=${JUMLAH_SISWA} < ${KONKUREN_MENGIKAT} - pada server ` +
                'satu-utas itu yang diharapkan. Ini bukan penilaian lulus/tidak.',
        );
    }

    process.exit(0);
}

// `CBT_BERSIHKAN=1` mengakhiri uji beban: menghapus peniru dan seluruh jejaknya
// lalu keluar. Diperiksa SEBELUM `utama()`, karena ia bukan mode uji - ia
// pembersihan, dan menjalankan uji apa pun dalam mode ini hanya akan mengukur
// basis data yang sedang dihapus.
(BERSIHKAN ? bersihkan() : utama()).catch((e) => {
    console.error('Gagal menjalankan uji beban:', e);
    process.exit(2);
});

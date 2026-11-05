/*
| Shared helpers for the headless page checks in this folder.
|
| ---------------------------------------------------------------------------
| Why this folder exists at all
| ---------------------------------------------------------------------------
| Fase 6 shipped with docblocks referring to `cbt-cek-laman.cjs`,
| `cbt-cek-bentrok.cjs` and `cbt-cek-pintu.cjs` - and none of the three were
| ever in the repository. The verification those files promised had therefore
| never actually run: the docblocks described a check that no one could
| perform, which is worse than having no docblock, because it reads as evidence.
|
| They are written fresh here, inside the repo, so the same thing cannot happen
| again. Small enough to read in one sitting on purpose.
|
| ---------------------------------------------------------------------------
| No new dependency
| ---------------------------------------------------------------------------
| `puppeteer-core` is already installed globally on this machine and Chrome is
| already installed at its default path. `puppeteer` would download a second
| browser copy for no reason. The import below resolves the global install by
| absolute path, so `npm install` never has to grow a dependency for a
| development-only script.
|
| ---------------------------------------------------------------------------
| What these checks are for - and what they are NOT
| ---------------------------------------------------------------------------
| Pest already proves every route returns 200 with the right props. What Pest
| cannot see is whether the BROWSER renders it: a React component that throws
| during render returns a perfectly good 200 from the server, and the page is
| blank. That is the entire failure class these scripts cover.
|
| They deliberately do NOT assert on pixels or copy. A check that breaks every
| time a label is reworded is a check people learn to ignore.
*/
import { createRequire } from 'node:module';
import { mkdir, rm, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const AKAR = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

export const BASE = process.env.CBT_BASE ?? 'http://127.0.0.1:8781';
export const KELUARAN = path.join(AKAR, 'storage', 'framework', 'cek-laman');

const KANDIDAT_PUPPETEER = [
    'puppeteer-core',
    process.env.CBT_PUPPETEER ?? '',
    'C:/laragon/bin/nodejs/node-v23/node_modules/puppeteer-core',
].filter(Boolean);

const KANDIDAT_CHROME = [
    process.env.CBT_CHROME ?? '',
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
].filter(Boolean);

export async function puppeteer() {
    for (const kandidat of KANDIDAT_PUPPETEER) {
        try {
            // Jalur absolut diimpor lewat `file://`, dan entry point-nya
            // ditulis eksplisit: `import('C:/...')` bukan URL yang sah, dan
            // menunjuk direktorinya saja tidak selalu menyeleksi kondisi
            // `import` pada peta `exports` paket ini. Jalur di bawah adalah
            // nilai `exports["."].import` di package.json-nya.
            const modul = kandidat.startsWith('C:')
                ? await import(`file://${kandidat}/lib/puppeteer/puppeteer-core.js`)
                : await import(kandidat);

            return modul.default ?? modul;
        } catch {
            // Coba kandidat berikutnya. Kegagalan di sini adalah "tidak ada di
            // sana", bukan galat yang perlu ditampilkan - yang ditampilkan
            // adalah pesan di ujung fungsi ini.
        }
    }

    throw new Error(
        'puppeteer-core tidak ditemukan. Pasang `npm i -g puppeteer-core`, ' +
            'atau set CBT_PUPPETEER ke jalur modulnya.',
    );
}

export function chromePath() {
    return KANDIDAT_CHROME[0];
}

export async function bukaPeramban({ lebar = 1440, tinggi = 900 } = {}) {
    const p = await puppeteer();

    const peramban = await p.launch({
        executablePath: chromePath(),
        headless: true,
        args: ['--no-sandbox', '--disable-dev-shm-usage'],
    });

    const halaman = await peramban.newPage();
    await halaman.setViewport({ width: lebar, height: tinggi });

    // Galat React adalah hal yang justru dicari di sini, jadi console tidak
    // boleh dibungkam. Yang dikumpulkan hanya yang berbunyi seperti galat -
    // peringatan deprecation pihak ketiga akan menenggelamkan yang penting.
    const galat = [];
    halaman.on('console', (pesan) => {
        if (pesan.type() === 'error') {
            galat.push(`console: ${pesan.text()}`);
        }
    });
    halaman.on('pageerror', (e) => galat.push(`pageerror: ${e.message}`));

    return { peramban, halaman, galat };
}

/**
 * Isi form login milik aplikasi ini. Lihat Auth/Login.tsx untuk `name`-nya.
 *
 * =====================================================================
 * Harus dokumen BARU, dan itu bukan kehati-hatian berlebihan
 * =====================================================================
 * Login di aplikasi ini berjalan lewat Inertia: klik "Masuk" mengirim XHR
 * dan menukar komponennya di tempat, sehingga ALAMAT berubah menjadi
 * `/siswa` sementara DOKUMEN-nya masih dokumen laman login yang lama.
 * Kembali ke `/login` sesudah itu membuat peramban memulihkan dokumen lama
 * itu dari cache, dan di dalamnya React sudah lepas dari DOM-nya: `#app`
 * masih berisi markup yang terlihat benar, `input[name=username]` masih
 * ada, tetapi `onChange`-nya tidak lagi terpasang.
 *
 * Akibatnya bukan galat, melainkan kesunyian. `page.type()` menekan tombol
 * satu per satu, React tidak pernah menerimanya, kolomnya kembali kosong,
 * formulirnya terkirim dengan dua isian kosong - dan `waitForNavigation`
 * menunggu perpindahan yang tidak akan datang sampai batas waktunya habis.
 * Yang terbaca di ujungnya adalah "Navigation timeout", yang menunjuk ke
 * navigasi padahal yang gagal adalah pengetikan.
 *
 * `about:blank` di antaranya memaksa dokumen yang benar-benar baru, dan
 * pemulihan cache tidak melintasi dokumen kosong. Hanya perlu satu kali
 * untuk login KEDUA dan seterusnya; login pertama selalu segar, dan itulah
 * sebabnya gejalanya tidak muncul saat memeriksa satu peran saja.
 */
export async function masuk(halaman, username, password = 'password') {
    // Dokumen kosong lebih dulu, SELALU - bukan hanya bila terlihat basi.
    // Mendeteksi dokumen basi dari luar tidak bisa dipercaya: markahnya
    // tampak benar, dan yang hilang hanya pendengar React-nya. Satu
    // perpindahan tambahan jauh lebih murah daripada pemeriksaan yang
    // melaporkan "lolos" untuk laman yang tidak pernah terbuka.
    await halaman.goto('about:blank');

    await halaman.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });

    // `#app` berisi = React sudah memasang formnya.
    await halaman.waitForFunction(
        () => (document.querySelector('#app')?.innerHTML.length ?? 0) > 200,
        { timeout: 10000 },
    );

    await halaman.waitForSelector('input[name="username"]', { visible: true });

    await halaman.type('input[name="username"]', username);
    await halaman.type('input[name="password"]', password);

    // Isian yang benar-benar sampai ke React, bukan yang kita kira kita
    // ketik. Kalau dokumennya yang basi, ini yang menangkapnya - dengan
    // pesan yang menyebut sebabnya, bukan "Navigation timeout" yang
    // menyesatkan.
    const terisi = await halaman.$eval('input[name="username"]', (el) => el.value);

    if (terisi !== username) {
        throw new Error(
            `Formulir login tidak menerima isiannya: kolom username berisi ` +
                `"${terisi}", seharusnya "${username}". Dokumennya kemungkinan ` +
                `dipulihkan dari cache sesudah login Inertia sebelumnya.`,
        );
    }

    await Promise.all([
        halaman.waitForNavigation({ waitUntil: 'networkidle0' }),
        halaman.click('button[type="submit"]'),
    ]);
}

/**
 * Buang sesi yang sedang berjalan, tanpa menekan tombol keluar.
 *
 * =====================================================================
 * Mengapa menghapus cookie, bukan mengklik "Keluar"
 * =====================================================================
 * Satu permintaan lebih sedikit, satu selektor lebih sedikit, dan - yang
 * paling menentukan - tidak bergantung pada letak tombol keluar. Skrip ini
 * memeriksa BENTUK laman; kalau ia juga harus tahu di mana tombol keluar
 * berada, setiap perubahan tata letak beranda membuat pemeriksaan laman
 * siswa gagal dengan pesan yang menyesatkan.
 *
 * `deleteCookie()` menuntut objek cookie UTUH, bukan namanya - menerima nama
 * akan menghapus cookie yang namanya sama persis dengan nama itu dan tidak
 * melakukan apa pun sisanya, diam-diam. Cookie-nya diambil dulu, lalu
 * diberikan kembali apa adanya.
 */
export async function hapusSesi(halaman) {
    const cookie = await halaman.cookies();

    if (cookie.length > 0) {
        await halaman.deleteCookie(...cookie);
    }
}

/**
 * Buka satu jalur dan periksa bahwa React benar-benar merender sesuatu.
 *
 * Tiga pemeriksaan, dan yang ketiga yang paling sering menangkap kerusakan:
 *
 *   1. status HTTP 200
 *   2. tidak ada galat konsol / pageerror
 *   3. `#app` berisi lebih dari beberapa karakter
 *
 * Pemeriksaan ketiga ada karena kegagalan yang paling khas di sini adalah
 * komponen yang melempar saat render: servernya menjawab 200 dengan benar,
 * `<div id="app"></div>` tetap kosong, dan tidak ada galat yang tercatat di
 * mana pun kalau React-nya sendiri yang menyerah.
 */
export async function periksa(halaman, jalur, { minimal = 80 } = {}) {
    const galat = [];
    const catat = (pesan) => galat.push(pesan);

    halaman.removeAllListeners('pageerror');
    halaman.removeAllListeners('console');
    halaman.on('pageerror', (e) => catat(`pageerror: ${e.message}`));
    halaman.on('console', (pesan) => {
        if (pesan.type() === 'error') {
            catat(`console: ${pesan.text()}`);
        }
    });

    const respons = await halaman.goto(`${BASE}${jalur}`, { waitUntil: 'networkidle0' });
    const status = respons?.status() ?? 0;

    // Satu tik tambahan: Inertia memasang komponennya setelah respons selesai,
    // dan `networkidle0` bisa terpenuhi lebih dulu pada halaman yang tidak
    // memuat apa-apa.
    await halaman.waitForFunction(
        () => (document.querySelector('#app')?.innerHTML.length ?? 0) > 200,
        { timeout: 5000 },
    ).catch(() => {});

    const panjang = await halaman.evaluate(
        () => document.querySelector('#app')?.innerHTML.length ?? 0,
    );

    const keluhan = [...galat];

    if (status !== 200) {
        keluhan.unshift(`HTTP ${status}`);
    }

    if (panjang < minimal) {
        keluhan.push(`#app hanya ${panjang} karakter - komponennya kemungkinan gagal dirender`);
    }

    return { jalur, status, panjang, keluhan, ok: keluhan.length === 0 };
}

/** Simpan tangkapan layar laman yang gagal - bukti yang bisa dibuka manusia. */export async function simpanBukti(halaman, nama) {
    await mkdir(KELUARAN, { recursive: true });
    const berkas = path.join(KELUARAN, `${nama.replace(/[^a-z0-9]+/gi, '-')}.png`);
    await halaman.screenshot({ path: berkas, fullPage: true });

    return berkas;
}

export async function bersihkanKeluar() {
    await rm(KELUARAN, { recursive: true, force: true });
}

/** Laporkan hasil, dan keluar dengan kode yang bisa dibaca skrip lain. */
export async function laporkan(nama, hasil) {
    const gagal = hasil.filter((h) => !h.ok);

    await mkdir(KELUARAN, { recursive: true });
    await writeFile(
        path.join(KELUARAN, `${nama}.json`),
        JSON.stringify({ nama, waktu: new Date().toISOString(), hasil }, null, 4),
    );

    for (const h of hasil) {
        const tanda = h.ok ? 'OK  ' : 'GAGAL';
        console.log(`${tanda} ${h.jalur} (${h.status}, ${h.panjang} kar.)`);

        for (const keluhan of h.keluhan) {
            console.log(`      - ${keluhan}`);
        }
    }

    console.log(`\n${hasil.length - gagal.length}/${hasil.length} laman lolos.`);

    if (gagal.length > 0) {
        console.log(`Tangkapan layar: ${KELUARAN}`);
        process.exitCode = 1;
    }
}

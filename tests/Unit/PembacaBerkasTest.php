<?php

use App\Support\Impor\BerkasImportTidakTerbaca;
use App\Support\Impor\PembacaBerkas;
use App\Support\Impor\PembacaCsv;
use App\Support\Impor\PembacaXlsx;
use Tests\TestCase;

/*
 * Berkas ini BUTUH aplikasi ter-boot untuk `storage_path()`/`base_path()`
 * yang dipakai helper di bawah, dan bukan karena ia menyentuh basis data.
 */
uses(TestCase::class);

/**
 * Pembaca XLSX & CSV tanpa dependensi.
 *
 * =====================================================================
 * Berkas ujinya DIBANGUN di sini, bukan disimpan sebagai fixture biner
 * =====================================================================
 * Rencana awal menyebut `tests/Fixtures/xlsx/`, dan berkas ini menyimpang dari
 * itu dengan alasan yang bisa diperiksa.
 *
 * Fixture biner hanya berguna bila ia dibuat oleh alat LAIN: satu berkas dari
 * Excel sungguhan membuktikan bahwa yang ditulis Excel memang terbaca. Berkas
 * yang dibangun generator buatan sendiri membuktikan sebaliknya - ia hanya
 * membuktikan pembacanya sepakat dengan penulisnya, dan penulisnya adalah kode
 * yang ditulis orang yang sama pada hari yang sama.
 *
 * Karena itu bentuk yang menentukan di sini adalah XML-nya, ditulis terbuka
 * di dalam testnya: sel bersama, sel yang dilubangi, teks terpecah. Setiap
 * kasus menyebut bentuknya sendiri, dan bentuk itu bisa dibaca dan diperiksa
 * di tempat - bukan di dalam zip yang harus dibongkar lebih dulu.
 *
 * =====================================================================
 * Yang diuji adalah NOMOR BARIS, bukan isinya
 * =====================================================================
 * Kriteria selesai blueprint menuntut galat dilaporkan "tepat barisnya", dan
 * nomor itu adalah nomor yang dibaca Guru di tepi kiri Excel. Karena itu
 * sebagian besar test di bawah memeriksa KUNCI array-nya - nomor barisnya -
 * dan bukan hanya nilai selnya. Pembaca yang mengembalikan isi benar dengan
 * nomor baris bergeser adalah pembaca yang laporannya menyesatkan.
 */
function berkasSementara(string $nama, string $isi): string
{
    $jalur = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cbt-impor-'.uniqid().'-'.$nama;

    file_put_contents($jalur, $isi);

    return $jalur;
}

/**
 * Bangun berkas .xlsx dari bagian XML-nya.
 *
 * @param  array<string, string>  $bagian  jalur di dalam arsip => isi XML
 */
function xlsxDari(array $bagian): string
{
    $jalur = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cbt-impor-'.uniqid().'.xlsx';

    $zip = new ZipArchive;
    $zip->open($jalur, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    foreach ($bagian as $nama => $isi) {
        $zip->addFromString($nama, $isi);
    }

    $zip->close();

    return $jalur;
}

/** Lembar kerja minimal dari baris-baris sel, apa adanya. */
function lembar(string $isiSheetData): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<sheetData>'.$isiSheetData.'</sheetData></worksheet>';
}

/** Lembar kerja lengkap dengan workbook.xml dan relasinya. */
function xlsxLengkap(string $isiSheetData, string $isiSharedStrings = ''): string
{
    $bagian = [
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Soal" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1"'
            .' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
            .' Target="worksheets/sheet1.xml"/></Relationships>',
        'xl/worksheets/sheet1.xml' => lembar($isiSheetData),
    ];

    if ($isiSharedStrings !== '') {
        $bagian['xl/sharedStrings.xml'] = $isiSharedStrings;
    }

    return xlsxDari($bagian);
}

/* ============================================================== XLSX */

it('membaca sel teks yang disimpan di tabel bersama', function () {
    // Bentuk yang paling sering muncul, dan yang paling mudah terlewat: Excel
    // TIDAK menulis teks ke dalam selnya. Ia menulis indeks ke tabel bersama.
    $jalur = xlsxLengkap(
        '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
        .'<row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2" t="s"><v>3</v></c></row>',
        '<?xml version="1.0" encoding="UTF-8"?>'
        .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<si><t>kode</t></si><si><t>judul</t></si>'
        .'<si><t>Q-1-1</t></si><si><t>Berapa 2 + 2?</t></si></sst>',
    );

    $baris = app(PembacaXlsx::class)->baca($jalur);

    // Nomor barisnya adalah nomor yang dibaca Guru di Excel.
    expect($baris)->toHaveKeys([1, 2])
        ->and($baris[1])->toBe(['kode', 'judul'])
        ->and($baris[2])->toBe(['Q-1-1', 'Berapa 2 + 2?']);
});

it('menggabungkan teks yang terpecah menjadi beberapa potongan', function () {
    // Excel memecah teks begitu ada huruf yang berbeda formatnya. Membaca
    // potongannya satu per satu menghasilkan kalimat yang terbelah di tengah.
    $jalur = xlsxLengkap(
        '<row r="1"><c r="A1" t="s"><v>0</v></c></row>',
        '<?xml version="1.0" encoding="UTF-8"?>'
        .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<si><r><rPr><b/></rPr><t>Ja</t></r><r><t>karta</t></r></si></sst>',
    );

    expect(app(PembacaXlsx::class)->baca($jalur)[1])->toBe(['Jakarta']);
});

it('membaca angka apa adanya, tanpa ekor pecahan', function () {
    // Kolom bobot berisi angka, dan "2.0" ditolak pemeriksa bobot sebagai
    // bukan bilangan bulat - galat yang menunjuk ke kolom yang salah.
    $jalur = xlsxLengkap('<row r="1"><c r="A1"><v>2</v></c><c r="B1" t="str"><v>=SUM(A1)</v></c></row>');

    expect(app(PembacaXlsx::class)->baca($jalur)[1])->toBe(['2', '=SUM(A1)']);
});

it('membiarkan lubang kolom tetap di tempatnya', function () {
    // Excel MENGHILANGKAN sel yang isinya sama dengan sebelumnya, dan
    // berikutnya bisa melompat dari B ke F. Pembaca yang mengandalkan urutan
    // akan menggeser seluruh kolom sesudahnya.
    $jalur = xlsxLengkap('<row r="1"><c r="A1" t="s"><v>0</v></c><c r="D1" t="s"><v>1</v></c></row>',
        '<?xml version="1.0" encoding="UTF-8"?>'
        .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<si><t>pertama</t></si><si><t>keempat</t></si></sst>',
    );

    // Indeks 3, bukan 1 - dan itu menentukan: `kunci` ada di kolom D.
    expect(app(PembacaXlsx::class)->baca($jalur)[1])->toBe(['pertama', '', '', 'keempat']);
});

it('membaca kolom dua huruf seperti AA', function () {
    $jalur = xlsxLengkap('<row r="1"><c r="Z1" t="s"><v>0</v></c><c r="AA1" t="s"><v>1</v></c></row>',
        '<?xml version="1.0" encoding="UTF-8"?>'
        .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<si><t>dua puluh enam</t></si><si><t>dua puluh tujuh</t></si></sst>',
    );

    $baris = app(PembacaXlsx::class)->baca($jalur)[1];

    expect($baris[25])->toBe('dua puluh enam')
        ->and($baris[26])->toBe('dua puluh tujuh');
});

it('mempertahankan nomor baris meski ada baris yang dikosongkan', function () {
    // Guru mengosongkan baris 2 saat memperbaiki berkasnya. Nomor baris 3
    // harus tetap 3, karena laporan galat menyebut nomor yang ia lihat.
    $jalur = xlsxLengkap(
        '<row r="1"><c r="A1" t="s"><v>0</v></c></row>'
        .'<row r="2"></row>'
        .'<row r="3"><c r="A3" t="s"><v>1</v></c></row>',
        '<?xml version="1.0" encoding="UTF-8"?>'
        .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<si><t>judul kolom</t></si><si><t>soal ketiga</t></si></sst>',
    );

    $baris = app(PembacaXlsx::class)->baca($jalur);

    expect($baris)->toHaveKeys([1, 2, 3])
        ->and($baris[2])->toBe([])
        ->and($baris[3])->toBe(['soal ketiga']);
});

it('membaca teks yang ditulis langsung di dalam selnya', function () {
    $jalur = xlsxLengkap('<row r="1"><c r="A1" t="inlineStr"><is><t>langsung</t></is></c></row>');

    expect(app(PembacaXlsx::class)->baca($jalur)[1])->toBe(['langsung']);
});

it('menolak berkas yang bukan arsip zip dengan pesan tentang .xls lama', function () {
    // Berkas .xls (Excel 97-2003) adalah OLE2, bukan zip. Guru yang menyimpan
    // berkasnya sebagai .xls akan mengulangi langkah yang sama bila pesannya
    // tidak menyebutkan sebabnya.
    $jalur = berkasSementara('lama.xls', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\x00", 200));

    expect(fn () => app(PembacaXlsx::class)->baca($jalur))
        ->toThrow(BerkasImportTidakTerbaca::class, '.xls lama');
});

it('menolak berkas xlsx tanpa satu pun lembar', function () {
    $jalur = xlsxDari(['docProps/core.xml' => '<x/>']);

    expect(fn () => app(PembacaXlsx::class)->baca($jalur))
        ->toThrow(BerkasImportTidakTerbaca::class, 'lembar kerja');
});

it('menolak berkas kosong', function () {
    $jalur = berkasSementara('kosong.xlsx', '');

    expect(fn () => app(PembacaXlsx::class)->baca($jalur))
        ->toThrow(BerkasImportTidakTerbaca::class, 'kosong');
});

it('menolak berkas yang melebihi batas baris SEBELUM membacanya seluruhnya', function () {
    // Berkas 50.000 baris yang ditolak setelah dibaca seluruhnya tetap
    // menghabiskan waktu dan memori server - penolakan yang mahal sama tidak
    // bergunanya dengan tidak ada penolakan.
    $isi = '';

    foreach (range(1, PembacaBerkas::BARIS_MAKS + 5) as $i) {
        $isi .= '<row r="'.$i.'"><c r="A'.$i.'" t="s"><v>0</v></c></row>';
    }

    $jalur = xlsxLengkap($isi, '<?xml version="1.0" encoding="UTF-8"?>'
        .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>x</t></si></sst>');

    expect(fn () => app(PembacaXlsx::class)->baca($jalur))
        ->toThrow(BerkasImportTidakTerbaca::class, 'batasnya');
});

/* =============================================================== CSV */

it('membaca CSV berpemisah koma', function () {
    $jalur = berkasSementara('soal.csv', "kode,judul\nQ-1-1,Berapa 2 + 2?\n");

    expect(app(PembacaCsv::class)->baca($jalur))->toBe([
        1 => ['kode', 'judul'],
        2 => ['Q-1-1', 'Berapa 2 + 2?'],
    ]);
});

it('membaca CSV berpemisah titik koma dari Excel berbahasa Indonesia', function () {
    // Excel Indonesia memakai `;` sebagai pemisah daftar, dan ekspornya
    // mengikuti setelan itu. Pembaca yang hanya menerima koma melihat seluruh
    // barisnya sebagai SATU kolom, lalu melaporkan "kolom kunci tidak
    // ditemukan" untuk berkas yang sebenarnya sempurna.
    $jalur = berkasSementara('soal.csv', "kode;judul;kunci\nQ-1-1;Berapa 2 + 2?;a\n");

    expect(app(PembacaCsv::class)->baca($jalur)[1])->toBe(['kode', 'judul', 'kunci'])
        ->and(app(PembacaCsv::class)->baca($jalur)[2])->toBe(['Q-1-1', 'Berapa 2 + 2?', 'a']);
});

it('membuang BOM di awal berkas', function () {
    // Tanpa dibuang, nama kolom pertama terbaca "\u{FEFF}kode" - dan
    // pemeriksaan nama kolom gagal pada kolom PERTAMA saja, sehingga gejalanya
    // adalah "kode" hilang sementara "judul" dan "kunci" ditemukan.
    $jalur = berkasSementara('bom.csv', "\xEF\xBB\xBFkode;judul\nQ-1-1;Halo\n");

    expect(app(PembacaCsv::class)->baca($jalur)[1])->toBe(['kode', 'judul']);
});

it('membaca koma dan baris baru di dalam sel berpengapit', function () {
    // Teks soal memuat koma, dan memecahnya dengan explode merusak soal itu
    // secara SENYAP: kolomnya bertambah, dan yang terbaca sebagai "kunci"
    // adalah potongan kalimat.
    $jalur = berkasSementara('soal.csv', "kode,judul\nQ-1-1,\"Berapa 2 + 2, dan mengapa?\"\n");

    expect(app(PembacaCsv::class)->baca($jalur)[2])->toBe(['Q-1-1', 'Berapa 2 + 2, dan mengapa?']);
});

it('menolak CSV yang tidak memuat pemisah kolom', function () {
    $jalur = berkasSementara('satu-kolom.csv', "kode judul kunci\nsatu dua tiga\n");

    expect(fn () => app(PembacaCsv::class)->baca($jalur))
        ->toThrow(BerkasImportTidakTerbaca::class, 'pemisah kolom');
});

it('menolak CSV kosong', function () {
    $jalur = berkasSementara('kosong.csv', '');

    expect(fn () => app(PembacaCsv::class)->baca($jalur))
        ->toThrow(BerkasImportTidakTerbaca::class, 'kosong');
});

/* ============================================================== Kontrak */

it('memilih pembaca menurut ekstensinya', function () {
    $xlsx = app(PembacaXlsx::class);
    $csv = app(PembacaCsv::class);

    expect($xlsx->dukung('xlsx'))->toBeTrue()
        ->and($xlsx->dukung('XLSX'))->toBeTrue()
        ->and($xlsx->dukung('csv'))->toBeFalse()
        ->and($csv->dukung('csv'))->toBeTrue()
        ->and($csv->dukung('CSV'))->toBeTrue()
        // .xls SENGAJA tidak didukung. Format lama itu bukan zip, dan
        // menerimanya di sini berarti galatnya muncul jauh dari sebabnya.
        ->and($xlsx->dukung('xls'))->toBeFalse()
        ->and($csv->dukung('xlsx'))->toBeFalse();
});

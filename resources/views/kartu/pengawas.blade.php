{{--
    Kartu panitia & pengawas - PDF siap cetak, wireframe-admin.html plate 13.

    ---------------------------------------------------------------------
    Ditulis tanpa CSS aplikasi, dan itu disengaja
    ---------------------------------------------------------------------
    DomPDF tidak menjalankan Tailwind, tidak mengenal custom property, dan
    tidak punya flexbox. Berkas ini memakai HTML telanjang dengan gaya inline
    - bentuk yang sama dengan `laporan/pdf.blade.php`, dan alasannya sama:
    memakai kelas aplikasi di sini berarti kartu yang tampak benar di
    peramban dan berantakan saat dicetak, padahal kartu ini HANYA untuk
    dicetak.

    ---------------------------------------------------------------------
    Delapan kartu per halaman, dihitung dengan tabel - bukan grid CSS
    ---------------------------------------------------------------------
    `PER_HALAMAN` di `KartuPengawas` bernilai 8 (dua kolom, empat baris), dan
    angka itu yang menentukan tinggi kartunya: 297mm dibagi empat, dikurangi
    margin halaman. Kartu dipecah per baris tabel, bukan dengan `page-break`
    pada div - DomPDF menangani pemecahan tabel jauh lebih dapat diandalkan
    daripada pemecahan blok bersarang.

    Tingginya ditulis TETAP, bukan `auto`. Kartu yang tingginya mengikuti
    isinya akan membuat sepuluh kartu berjabatan pendek termuat dalam satu
    halaman dan tiga puluh kartu berjabatan panjang melimpah ke halaman
    kelima - dan Admin yang sudah memotong kertasnya menurut hitungan delapan
    per lembar akan mendapat potongan yang tidak pas.

    ---------------------------------------------------------------------
    Kode ditulis BESAR dan berjarak lebar
    ---------------------------------------------------------------------
    Ia dibaca sambil berdiri di depan pintu, kadang sambil memegang kertasnya
    sendiri di bawah cahaya yang tidak ideal. `letter-spacing` membuat enam
    digit tidak saling menempel, dan itu satu-satunya hal yang membedakan
    "1" dan "7" pada cetakan printer yang tonernya menipis.

    ---------------------------------------------------------------------
    Baris tanpa kode tidak mencetak kotak kosong
    ---------------------------------------------------------------------
    Ketua dan koordinator tetap mendapat kartu, tetapi kartunya tidak memuat
    kotak kode kosong: kotak kosong terbaca sebagai "kodenya gagal tercetak",
    dan pengawas yang menerimanya akan meminta Admin menerbitkannya ulang -
    pekerjaan yang sia-sia untuk orang yang memang tidak memegang kode.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Kartu panitia {{ $event['kode'] }}</title>
    <style>
        @page { margin: 10mm 8mm; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 9px;
            color: #161b24;
        }

        table { width: 100%; border-collapse: separate; border-spacing: 4mm 4mm; }

        td.kartu {
            width: 50%;
            height: 66mm;
            border: 1px solid #8e97a6;
            border-radius: 3mm;
            padding: 5mm;
            vertical-align: top;
        }

        .kop { border-bottom: 1px solid #d3d9e2; padding-bottom: 2mm; margin-bottom: 3mm; }

        .sekolah { font-size: 10px; font-weight: bold; }
        .npsn { font-size: 7px; color: #4a5565; }
        .event { font-size: 7px; color: #4a5565; margin-top: 1mm; }

        .nama { font-size: 14px; font-weight: bold; margin: 0 0 1mm; }

        .jabatan {
            font-size: 7px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: #4a5565;
            margin: 0 0 3mm;
        }

        .label { font-size: 7px; color: #77808f; text-transform: uppercase; }

        .cakupan { font-size: 9px; margin-top: 0.5mm; }

        .garis { border-top: 1px dashed #d3d9e2; margin: 3mm 0; }

        .kode {
            font-family: DejaVu Sans Mono, monospace;
            font-size: 22px;
            font-weight: bold;
            letter-spacing: 3px;
        }

        .petunjuk { font-size: 6.5px; color: #77808f; margin-top: 1mm; line-height: 1.35; }

        .akun { font-family: DejaVu Sans Mono, monospace; font-size: 7px; color: #4a5565; }

        .logo { max-height: 10mm; max-width: 22mm; }
    </style>
</head>
<body>
    {{-- Dua kolom per baris, jadi `chunk(2)` - dan SEL kosong yang tersisa
         pada baris terakhir diisi kartu kosong supaya lebarnya tidak
         melar mengisi seluruh halaman. --}}
    @foreach (collect($kartu)->chunk(2) as $dua)
        <table>
            <tr>
                @foreach ($dua as $satu)
                    <td class="kartu">
                        <div class="kop">
                            <table style="border-spacing:0">
                                <tr>
                                    @if (! empty($sekolah['logo']))
                                        <td style="width:24mm; vertical-align:top">
                                            <img class="logo" src="{{ $sekolah['logo'] }}" alt="">
                                        </td>
                                    @endif
                                    <td style="vertical-align:top">
                                        <div class="sekolah">{{ $sekolah['nama'] }}</div>
                                        @if (! empty($sekolah['npsn']))
                                            <div class="npsn">NPSN {{ $sekolah['npsn'] }}</div>
                                        @endif
                                        <div class="event">{{ $event['nama'] }} &middot; {{ $event['kode'] }}</div>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <p class="nama">{{ $satu['nama'] }}</p>
                        <p class="jabatan">{{ $satu['jabatan'] }}</p>

                        <span class="label">Cakupan</span>
                        <div class="cakupan">{{ $satu['cakupan'] }}</div>

                        @if ($satu['kode'] !== null)
                            <div class="garis"></div>

                            <span class="label">Kode pengawas</span>
                            <div class="kode">{{ $satu['kode'] }}</div>

                            <p class="petunjuk">
                                Diperlihatkan kepada pengawas di pintu. Kode ini milik ORANG,
                                bukan ruang - berlaku di ruang mana pun yang ditugaskan.
                            </p>
                        @endif

                        @if ($satu['akun'] !== null)
                            <p class="petunjuk" style="margin-top:2mm">
                                Akun: <span class="akun">{{ $satu['akun'] }}</span>
                            </p>
                        @endif
                    </td>
                @endforeach

                @for ($i = $dua->count(); $i < 2; $i++)
                    <td class="kartu" style="border:none"></td>
                @endfor
            </tr>
        </table>
    @endforeach
</body>
</html>

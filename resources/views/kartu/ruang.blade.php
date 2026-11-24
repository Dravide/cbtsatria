{{--
    Kartu pintu ruang - satu lembar A4 untuk SETIAP ruang.

    ---------------------------------------------------------------------
    Ditulis tanpa CSS aplikasi, dan itu disengaja
    ---------------------------------------------------------------------
    DomPDF tidak mengenal Tailwind, custom property, maupun flexbox/grid.
    Berkas ini memakai HTML dengan gaya tersemat yang kompatibel dengan
    DomPDF, menyelaraskan token warna `resources/css/app.css`
    (#161b24, #4a5565, #d3d9e2, #f2f4f8, #1e4fa3) dengan struktur
    kartu editorial pada `kartu/akun-guru.blade.php`.

    ---------------------------------------------------------------------
    Satu halaman per ruang, dipaksa dengan `page-break-after`
    ---------------------------------------------------------------------
    Setiap blok `.halaman` diberi `page-break-after: always`, KECUALI yang
    terakhir - halaman kosong di belakang kartu terakhir adalah pemborosan
    kertas yang selalu diperhatikan orang yang mencetak tiga puluh lembar.

    ---------------------------------------------------------------------
    Yang TIDAK ada di sini: kode pengawas
    ---------------------------------------------------------------------
    Hanya tautan (`/p/{slug}`) yang tercetak. Kode milik ORANG dan ada di
    kartu panitia. Memfoto kertas di pintu tidak boleh cukup untuk masuk -
    pembagian yang berlaku sejak 2026-11-07.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Kartu Pintu Ruang</title>
    <style>
        @include('partials.pdf-fonts')

        @page { margin: 12mm; }

        body {
            margin: 0;
            padding: 0;
            font-family: 'Bricolage Grotesque', 'DejaVu Sans', sans-serif;
            font-size: 9px;
            color: #161b24;
            background: #ffffff;
        }

        .halaman {
            page-break-after: always;
        }

        .halaman.terakhir {
            page-break-after: auto;
        }

        .kartu {
            border: 1px solid #d3d9e2;
            border-top: 3px solid #161b24;
            border-radius: 2mm;
            padding: 8mm 9mm 7mm;
            background: #ffffff;
        }

        /* Kop Minimalis */
        .kop {
            padding-bottom: 4.5mm;
            margin-bottom: 7mm;
            border-bottom: 1px solid #e6eaf0;
        }

        .kop-table {
            width: 100%;
            border-collapse: collapse;
        }

        .logo {
            max-height: 12mm;
            max-width: 24mm;
        }

        .sekolah {
            font-size: 12px;
            font-weight: bold;
            color: #161b24;
        }

        .npsn {
            font-size: 8px;
            color: #4a5565;
            margin-top: 0.6mm;
        }

        .lencana {
            display: inline-block;
            padding: 1mm 2.5mm;
            border: 1px solid #d3d9e2;
            border-radius: 1.5mm;
            font-size: 7.5px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #161b24;
            background: #f2f4f8;
        }

        /* Identitas Ruang */
        .identitas {
            text-align: center;
            margin-bottom: 7mm;
        }

        .label {
            font-size: 8px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #4a5565;
        }

        .kode {
            font-size: 40px;
            font-weight: bold;
            color: #161b24;
            margin: 1.5mm 0 1mm;
            line-height: 1.1;
        }

        .nama {
            font-size: 15px;
            font-weight: bold;
            color: #161b24;
            margin: 0 0 1mm;
        }

        .lokasi {
            font-size: 10px;
            color: #4a5565;
            margin: 0;
        }

        /* Blok QR & Tautan */
        .qr-panel {
            border: 1px solid #e6eaf0;
            border-radius: 2mm;
            background: #f2f4f8;
            padding: 7mm 6mm 6mm;
            text-align: center;
            margin-bottom: 7mm;
        }

        .qr-bingkai {
            display: inline-block;
            background: #ffffff;
            border: 1px solid #d3d9e2;
            border-radius: 1.5mm;
            padding: 3.5mm;
        }

        .qr {
            width: 84mm;
            height: 84mm;
            display: block;
        }

        .petunjuk {
            font-size: 10px;
            color: #4a5565;
            margin: 5mm 0 3.5mm;
        }

        .tautan {
            font-family: 'IBM Plex Mono', 'DejaVu Sans Mono', monospace;
            font-size: 10.5px;
            font-weight: bold;
            color: #1e4fa3;
            background: #ffffff;
            border: 1px solid #d3d9e2;
            border-radius: 1.5mm;
            padding: 2.8mm 4.5mm;
            display: inline-block;
            word-break: break-all;
        }

        .kedaluwarsa {
            font-size: 8px;
            color: #4a5565;
            margin-top: 3mm;
        }

        /* Pemberitahuan Ruang Tanpa Tautan */
        .kosong {
            margin: 14mm 0;
            padding: 10mm 8mm;
            border: 1.5px dashed #9b2530;
            background: #fae4e6;
            color: #9b2530;
            border-radius: 2mm;
            text-align: center;
        }

        .kosong-judul {
            font-size: 12px;
            font-weight: bold;
            margin-bottom: 2mm;
        }

        .kosong-isi {
            font-size: 10px;
            color: #161b24;
            line-height: 1.5;
        }

        /* Kaki Kartu */
        .kaki {
            border-top: 1px solid #e6eaf0;
            padding-top: 3.5mm;
            font-size: 8px;
            color: #4a5565;
            line-height: 1.5;
        }

        .kaki-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 2mm;
            font-size: 7.5px;
            color: #565c68;
        }
    </style>
</head>
<body>
    @foreach ($halaman as $i => $h)
        @php $terakhir = $i === count($halaman) - 1; @endphp
        <div class="halaman {{ $terakhir ? 'terakhir' : '' }}">
            <div class="kartu">
                <div class="kop">
                    <table class="kop-table">
                        <tr>
                            @if (! empty($sekolah['logo']))
                                <td style="width: 16mm; vertical-align: middle; padding-right: 3.5mm;">
                                    <img class="logo" src="{{ $sekolah['logo'] }}" alt="">
                                </td>
                            @endif
                            <td style="vertical-align: middle;">
                                <div class="sekolah">{{ $sekolah['nama'] }}</div>
                                @if (! empty($sekolah['npsn']))
                                    <div class="npsn">NPSN {{ $sekolah['npsn'] }}</div>
                                @endif
                            </td>
                            <td style="vertical-align: middle; text-align: right; width: 42mm;">
                                <span class="lencana">Kartu Pintu Ruang</span>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="identitas">
                    <span class="label">Ruang Ujian</span>
                    <div class="kode">{{ $h['kode'] }}</div>
                    @if (! empty($h['nama']))
                        <div class="nama">{{ $h['nama'] }}</div>
                    @endif
                    @if (! empty($h['lokasi']))
                        <div class="lokasi">{{ $h['lokasi'] }}</div>
                    @else
                        <div class="lokasi">&nbsp;</div>
                    @endif
                </div>

                @if ($h['qr'] !== null)
                    <div class="qr-panel">
                        <div class="qr-bingkai">
                            <img class="qr" width="317" height="317" src="{{ $h['qr'] }}" alt="QR pintu ruang {{ $h['kode'] }}">
                        </div>

                        <p class="petunjuk">Pindai QR ini untuk membuka pintu ruang.</p>

                        <div class="tautan">{{ $h['url'] }}</div>

                        @if (! empty($h['kedaluwarsa']))
                            <div class="kedaluwarsa">Tautan berlaku sampai {{ $h['kedaluwarsa'] }}. Hubungi Admin bila kedaluwarsa.</div>
                        @endif
                    </div>
                @else
                    <div class="kosong">
                        <div class="kosong-judul">Tautan pintu ruang ini belum diterbitkan.</div>
                        <div class="kosong-isi">Terbitkan dulu di laman Ruang, lalu cetak ulang kartu ini.</div>
                    </div>
                @endif

                <div class="kaki">
                    <div>
                        Tempel kartu ini di daun pintu ruang ujian. Kode pengawas tidak dicetak pada lembar ini.
                    </div>
                    <table class="kaki-table">
                        <tr>
                            <td>Kartu Pintu Ruang Ujian</td>
                            <td style="text-align: right; font-family: 'IBM Plex Mono', 'DejaVu Sans Mono', monospace;">
                                {{ now()->format('d/m/Y') }}
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    @endforeach
</body>
</html>

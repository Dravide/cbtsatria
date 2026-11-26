{{--
    Kartu pintu ruang - satu lembar A4 untuk SETIAP ruang.
    Menyelaraskan tampilan dengan sistem desain konsol Admin (warna tema dari
    /admin/sekolah/identitas, font Bricolage Grotesque & IBM Plex Mono,
    permukaan #ffffff, panel #f2f4f8, garis #d3d9e2).
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Kartu Pintu Ruang</title>
    <style>
        @include('partials.pdf-fonts')
        @include('partials.pdf-tema')

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
            border-radius: 2.5mm;
            overflow: hidden;
            background: #ffffff;
        }

        .logo {
            max-height: 11mm;
            max-width: 22mm;
            display: block;
        }

        .isi {
            padding: 7mm 8.5mm 6.5mm;
        }

        /* Identitas Ruang */
        .identitas {
            text-align: center;
            margin-bottom: 6.5mm;
        }

        .label {
            font-size: 8px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: #77808f;
        }

        .kode {
            font-size: 42px;
            font-weight: bold;
            color: #161b24;
            margin: 1.5mm 0 1mm;
            line-height: 1.05;
        }

        .nama {
            font-size: 16px;
            font-weight: bold;
            color: #161b24;
            margin: 0;
        }

        .lokasi {
            font-size: 10px;
            color: #4a5565;
            margin-top: 1mm;
        }

        /* Panel QR */
        .qr-panel {
            border: 1px solid #d3d9e2;
            border-radius: 2mm;
            background: #f2f4f8;
            padding: 7.5mm 6mm 6mm;
            text-align: center;
            margin-bottom: 6.5mm;
        }

        .qr-bingkai {
            display: inline-block;
            background: #ffffff;
            border: 1px solid #d3d9e2;
            border-radius: 2mm;
            padding: 4mm;
        }

        .qr {
            width: 82mm;
            height: 82mm;
            display: block;
        }

        .petunjuk {
            font-size: 10px;
            color: #4a5565;
            margin: 5mm 0 3.5mm;
        }

        .tautan {
            font-family: 'IBM Plex Mono', 'DejaVu Sans Mono', monospace;
            font-size: 10px;
            font-weight: bold;
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
            color: #77808f;
        }
    </style>
</head>
<body>
    @foreach ($halaman as $i => $h)
        @php $terakhir = $i === count($halaman) - 1; @endphp
        <div class="halaman {{ $terakhir ? 'terakhir' : '' }}">
            <div class="kartu">
                @include('partials.pdf-kop-kartu', [
                    'lencana' => 'Kartu Pintu Ruang',
                ])

                <div class="isi">
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
                                <img class="qr" width="310" height="310" src="{{ $h['qr'] }}" alt="QR pintu ruang {{ $h['kode'] }}">
                            </div>

                            <p class="petunjuk">Pindai QR ini untuk membuka pintu ruang.</p>

                            <div class="tautan pdf-aksen">{{ $h['url'] }}</div>

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
        </div>
    @endforeach
</body>
</html>

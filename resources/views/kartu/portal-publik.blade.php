{{--
    Poster cetak 1 QR untuk portal publik sekolah (/info/{slug}), A4 portrait.
    Menyelaraskan sistem desain konsol Admin (warna tema, Bricolage Grotesque & IBM Plex Mono).
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Poster QR Portal Publik · {{ $kop['nama'] }}</title>
    <style>
        @include('partials.pdf-fonts')
        @include('partials.pdf-tema')

        @page { margin: 14mm; }

        body {
            margin: 0;
            padding: 0;
            font-family: 'Bricolage Grotesque', 'DejaVu Sans', sans-serif;
            font-size: 11px;
            color: #161b24;
            background: #ffffff;
        }

        .poster {
            border: 1px solid #d3d9e2;
            border-radius: 3mm;
            overflow: hidden;
            background: #ffffff;
        }

        .poster-kepala {
            padding: 8mm 10mm 7mm;
            text-align: center;
        }

        .logo {
            max-height: 16mm;
            max-width: 48mm;
            display: block;
        }

        .poster-judul {
            margin: 3.5mm 0 0;
            font-size: 21px;
            font-weight: bold;
            line-height: 1.2;
        }

        .poster-npsn {
            margin: 1.5mm 0 0;
            font-size: 9.5px;
        }

        .isi {
            padding: 8mm 10mm 7mm;
            text-align: center;
        }

        .sub {
            margin: 0 0 6mm;
            font-size: 11px;
            color: #4a5565;
            line-height: 1.5;
        }

        .qr-panel {
            border: 1px solid #d3d9e2;
            border-radius: 2.5mm;
            background: #f2f4f8;
            padding: 6.5mm 6mm 5.5mm;
            margin-bottom: 6.5mm;
        }

        .qr-box {
            display: inline-block;
            padding: 4mm;
            border: 1px solid #d3d9e2;
            border-radius: 2mm;
            background: #ffffff;
        }

        .qr-box img {
            display: block;
            width: 68mm;
            height: 68mm;
        }

        .url-wrap {
            margin-top: 5mm;
        }

        .url {
            display: inline-block;
            font-family: 'IBM Plex Mono', 'DejaVu Sans Mono', monospace;
            font-size: 10px;
            font-weight: bold;
            background: #ffffff;
            border: 1px solid #d3d9e2;
            padding: 2.2mm 4.5mm;
            border-radius: 1.5mm;
            word-break: break-all;
        }

        .daftar {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            margin-bottom: 5mm;
        }

        .daftar td {
            padding: 2.8mm 2mm;
            vertical-align: top;
            font-size: 10.5px;
            color: #4a5565;
            line-height: 1.45;
            border-bottom: 1px solid #e6eaf0;
        }

        .daftar tr:last-child td {
            border-bottom: none;
        }

        .daftar td strong {
            color: #161b24;
        }

        .daftar td.no {
            width: 10mm;
            font-family: 'IBM Plex Mono', 'DejaVu Sans Mono', monospace;
            font-weight: bold;
        }

        .catatan {
            padding-top: 4mm;
            border-top: 1px solid #e6eaf0;
            font-size: 8.5px;
            color: #77808f;
            margin: 0;
        }
    </style>
</head>
<body>
    <div class="poster">
        <div class="pdf-kop-bar-kartu poster-kepala">
            @if ($kop['logo'])
                <div style="margin-bottom: 3.5mm;">
                    <span class="pdf-logo-bingkai">
                        <img src="{{ $kop['logo'] }}" alt="{{ $kop['nama'] }}" class="logo">
                    </span>
                </div>
            @endif

            <div>
                <span class="pdf-kop-lencana">Portal Informasi Ujian CBT</span>
            </div>
            <div class="poster-judul pdf-kop-sekolah" style="font-size: 21px;">{{ $kop['nama'] }}</div>
            @if ($kop['npsn'])
                <div class="poster-npsn pdf-kop-sub" style="font-size: 9.5px;">NPSN {{ $kop['npsn'] }}</div>
            @endif
        </div>

        <div class="isi">
            <p class="sub">
                Pindai kode QR di bawah ini menggunakan kamera ponsel<br>
                untuk siswa &amp; orang tua, tanpa perlu login.
            </p>

            <div class="qr-panel">
                <div class="qr-box">
                    <img src="{{ $qrDataUri }}" alt="QR Portal Publik {{ $kop['nama'] }}">
                </div>

                <div class="url-wrap">
                    <span class="url pdf-aksen">{{ $portalUrl }}</span>
                </div>
            </div>

            <table class="daftar">
                <tr>
                    <td class="no pdf-aksen">01</td>
                    <td><strong>Jadwal &amp; Pengumuman</strong>: jadwal ujian lintas hari dan pengumuman resmi panitia</td>
                </tr>
                <tr>
                    <td class="no pdf-aksen">02</td>
                    <td><strong>Cek Ruang &amp; Nomor Kursi</strong>: cari ruang dan nomor meja memakai Kelas dan NIS</td>
                </tr>
                <tr>
                    <td class="no pdf-aksen">03</td>
                    <td><strong>Pantau Wali per Kelas</strong>: pantau kehadiran &amp; waktu pengerjaan anak dengan kode kelas</td>
                </tr>
            </table>

            <p class="catatan">
                Portal ini tidak menampilkan nilai, token, atau data personal siswa · Dicetak {{ $dicetak }}
            </p>
        </div>
    </div>
</body>
</html>

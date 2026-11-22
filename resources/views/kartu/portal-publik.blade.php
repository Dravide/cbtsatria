{{--
    Poster cetak 1 QR untuk portal publik sekolah (/info/{slug}), A4 portrait.
    100% kompatibel dengan DomPDF (tanpa flex/grid, memakai DejaVu Sans dan data URI).
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Poster QR Portal Publik · {{ $kop['nama'] }}</title>
    <style>
        @page { margin: 16mm; }

        body {
            margin: 0;
            padding: 0;
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            color: #161b24;
            background: #ffffff;
        }

        .poster {
            border: 1.5px solid #161b24;
            border-top: 5px solid #1e4fa3;
            border-radius: 4mm;
            padding: 14mm 12mm 11mm;
            text-align: center;
        }

        .logo {
            max-height: 20mm;
            max-width: 55mm;
            margin-bottom: 5mm;
        }

        .eyebrow {
            font-size: 10px;
            font-weight: bold;
            letter-spacing: 1.8px;
            text-transform: uppercase;
            color: #1e4fa3;
            margin: 0 0 2.5mm;
        }

        h1 {
            margin: 0;
            font-size: 22px;
            line-height: 1.2;
            color: #161b24;
        }

        .npsn {
            margin: 2mm 0 0;
            font-size: 10px;
            color: #64748b;
        }

        .sub {
            margin: 5mm 0 8mm;
            font-size: 12px;
            color: #4a5565;
            line-height: 1.5;
        }

        .qr-box {
            display: inline-block;
            padding: 5mm;
            border: 1.5px solid #161b24;
            border-radius: 4mm;
            background: #ffffff;
        }

        .qr-box img {
            display: block;
            width: 72mm;
            height: 72mm;
        }

        .url-wrap {
            margin: 6mm 0 9mm;
        }

        .url {
            display: inline-block;
            font-family: DejaVu Sans Mono, monospace;
            font-size: 10.5px;
            color: #1e4fa3;
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            padding: 2.5mm 4.5mm;
            border-radius: 2mm;
        }

        .daftar {
            width: 100%;
            border-collapse: collapse;
            border-top: 1px dashed #cbd5e1;
            margin-top: 2mm;
            text-align: left;
        }

        .daftar td {
            padding: 3.5mm 2mm 1mm;
            vertical-align: top;
            font-size: 11.5px;
            color: #334155;
            line-height: 1.45;
        }

        .daftar td.no {
            width: 10mm;
            font-family: DejaVu Sans Mono, monospace;
            font-weight: bold;
            color: #1e4fa3;
        }

        .catatan {
            margin-top: 9mm;
            padding-top: 4mm;
            border-top: 1px solid #e6eaf0;
            font-size: 9.5px;
            color: #64748b;
        }
    </style>
</head>
<body>
    <div class="poster">
        @if ($kop['logo'])
            <div>
                <img src="{{ $kop['logo'] }}" alt="{{ $kop['nama'] }}" class="logo">
            </div>
        @endif

        <p class="eyebrow">Portal Informasi Ujian CBT</p>
        <h1>{{ $kop['nama'] }}</h1>
        @if ($kop['npsn'])
            <p class="npsn">NPSN {{ $kop['npsn'] }}</p>
        @endif

        <p class="sub">
            Pindai kode QR di bawah ini menggunakan kamera ponsel<br>
            untuk siswa &amp; orang tua — tanpa perlu login.
        </p>

        <div class="qr-box">
            <img src="{{ $qrDataUri }}" alt="QR Portal Publik {{ $kop['nama'] }}">
        </div>

        <div class="url-wrap">
            <span class="url">{{ $portalUrl }}</span>
        </div>

        <table class="daftar">
            <tr>
                <td class="no">01</td>
                <td><strong>Jadwal &amp; Pengumuman</strong> — jadwal ujian lintas hari dan pengumuman resmi panitia</td>
            </tr>
            <tr>
                <td class="no">02</td>
                <td><strong>Cek Ruang &amp; Nomor Kursi</strong> — cari ruang dan nomor meja memakai Kelas dan NIS</td>
            </tr>
            <tr>
                <td class="no">03</td>
                <td><strong>Pantau Wali per Kelas</strong> — pantau kehadiran &amp; waktu pengerjaan anak (dengan kode kelas)</td>
            </tr>
        </table>

        <p class="catatan">
            Portal ini tidak menampilkan nilai, token, atau data personal siswa · Dicetak {{ $dicetak }}
        </p>
    </div>
</body>
</html>

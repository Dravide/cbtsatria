{{--
    Kartu pantau wali kelas - satu kartu satu lembar, PDF siap cetak & bagikan.
    Menyelaraskan gaya editorial minimalis `kartu/akun-guru.blade.php` dengan
    token warna `resources/css/app.css` dan 100% kompatibel dengan DomPDF.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Kartu Pantau Wali {{ $kartu['nama'] }}</title>
    <style>
        @page { margin: 3mm; }

        body {
            margin: 0;
            padding: 0;
            font-family: DejaVu Sans, sans-serif;
            font-size: 9px;
            color: #161b24;
            background: #ffffff;
        }

        .kartu {
            border: 1px solid #d3d9e2;
            border-top: 3px solid #161b24;
            border-radius: 2mm;
            padding: 5.5mm 6mm 5mm;
            background: #ffffff;
        }

        .kop {
            padding-bottom: 4mm;
            margin-bottom: 5mm;
            border-bottom: 1px solid #e6eaf0;
        }

        .kop-table {
            width: 100%;
            border-collapse: collapse;
        }

        .logo {
            max-height: 11mm;
            max-width: 22mm;
        }

        .sekolah {
            font-size: 11px;
            font-weight: bold;
            color: #161b24;
        }

        .npsn {
            font-size: 7.5px;
            color: #4a5565;
            margin-top: 0.5mm;
        }

        .lencana {
            display: inline-block;
            padding: 1mm 2.2mm;
            border: 1px solid #d3d9e2;
            border-radius: 1.5mm;
            font-size: 7px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #161b24;
            background: #f2f4f8;
        }

        .identitas {
            margin-bottom: 4.5mm;
        }

        .label {
            font-size: 7.5px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #4a5565;
        }

        .nama-kelas {
            font-size: 20px;
            font-weight: bold;
            color: #161b24;
            margin: 0.8mm 0 1mm;
            line-height: 1.15;
        }

        .wali {
            font-size: 9px;
            color: #4a5565;
            margin: 0;
        }

        .panel-qr {
            border: 1px solid #e6eaf0;
            border-radius: 2mm;
            background: #f2f4f8;
            padding: 4.5mm 4mm 4mm;
            text-align: center;
            margin-bottom: 4.5mm;
        }

        .qr-bingkai {
            display: inline-block;
            background: #ffffff;
            border: 1px solid #d3d9e2;
            border-radius: 1.5mm;
            padding: 2.5mm;
            margin-bottom: 3.5mm;
        }

        .qr {
            width: 44mm;
            height: 44mm;
            display: block;
        }

        .kode-kotak {
            background: #ffffff;
            border: 1px solid #d3d9e2;
            border-radius: 1.5mm;
            padding: 2.5mm 4mm;
            margin-bottom: 3mm;
        }

        .kode-nilai {
            font-family: DejaVu Sans Mono, monospace;
            font-size: 20px;
            font-weight: bold;
            color: #161b24;
            letter-spacing: 3px;
            margin-top: 0.8mm;
        }

        .tautan {
            font-family: DejaVu Sans Mono, monospace;
            font-size: 8.5px;
            font-weight: bold;
            color: #1e4fa3;
            background: #ffffff;
            border: 1px solid #d3d9e2;
            border-radius: 1.5mm;
            padding: 2mm 3mm;
            word-break: break-all;
            text-align: left;
        }

        .kosong {
            margin: 3mm 0 4.5mm;
            padding: 5mm 4.5mm;
            border: 1.5px dashed #9b2530;
            background: #fae4e6;
            color: #9b2530;
            border-radius: 2mm;
            text-align: center;
        }

        .kosong-judul {
            font-size: 10px;
            font-weight: bold;
            margin-bottom: 1.5mm;
        }

        .kosong-isi {
            font-size: 8.5px;
            color: #161b24;
            line-height: 1.45;
        }

        .kaki {
            border-top: 1px solid #e6eaf0;
            padding-top: 3mm;
            font-size: 7.5px;
            color: #4a5565;
            line-height: 1.45;
        }

        .kaki-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1.5mm;
            font-size: 7px;
            color: #565c68;
        }
    </style>
</head>
<body>
    <div class="kartu">
        <div class="kop">
            <table class="kop-table">
                <tr>
                    @if (! empty($sekolah['logo']))
                        <td style="width: 14mm; vertical-align: middle; padding-right: 3mm;">
                            <img class="logo" src="{{ $sekolah['logo'] }}" alt="">
                        </td>
                    @endif
                    <td style="vertical-align: middle;">
                        <div class="sekolah">{{ $sekolah['nama'] }}</div>
                        @if (! empty($sekolah['npsn']))
                            <div class="npsn">NPSN {{ $sekolah['npsn'] }}</div>
                        @endif
                    </td>
                    <td style="vertical-align: middle; text-align: right; width: 34mm;">
                        <span class="lencana">Kartu Pantau Wali</span>
                    </td>
                </tr>
            </table>
        </div>

        <div class="identitas">
            <span class="label">
                Kelas{{ ! empty($kartu['tahun']) ? ' · TA '.$kartu['tahun'] : '' }}
            </span>
            <p class="nama-kelas">{{ $kartu['nama'] }}</p>
            <p class="wali">Wali kelas: {{ $kartu['wali'] ?? 'Belum ditentukan' }}</p>
        </div>

        @if ($kartu['qr'] !== null && $kartu['url'] !== null)
            <div class="panel-qr">
                <div class="qr-bingkai">
                    <img class="qr" width="166" height="166" src="{{ $kartu['qr'] }}" alt="QR pantau wali kelas {{ $kartu['nama'] }}">
                </div>

                @if (! empty($kartu['kode']))
                    <div class="kode-kotak">
                        <span class="label">Kode Kelas</span>
                        <div class="kode-nilai">{{ $kartu['kode'] }}</div>
                    </div>
                @endif

                <div class="tautan">{{ $kartu['url'] }}</div>
            </div>
        @else
            <div class="kosong">
                @if (! $kartu['tayang'])
                    <div class="kosong-judul">Pantau wali belum dinyalakan.</div>
                    <div class="kosong-isi">Nyalakan pantau wali di laman Kelas terlebih dahulu, lalu cetak ulang kartu ini.</div>
                @else
                    <div class="kosong-judul">Papan publik sekolah belum dinyalakan.</div>
                    <div class="kosong-isi">Nyalakan papan publik di menu Pengaturan agar tautan dan QR kelas dapat dibuat.</div>
                @endif
            </div>
        @endif

        <div class="kaki">
            <div>
                Bagikan kartu ini kepada wali kelas untuk memantau kehadiran dan progres ujian siswa.
            </div>
            <table class="kaki-table">
                <tr>
                    <td>Kartu Pantau Wali Kelas</td>
                    <td style="text-align: right; font-family: DejaVu Sans Mono, monospace;">
                        {{ now()->format('d/m/Y') }}
                    </td>
                </tr>
            </table>
        </div>
    </div>
</body>
</html>

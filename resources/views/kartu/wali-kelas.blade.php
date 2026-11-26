{{--
    Kartu pantau wali kelas - satu kartu satu lembar, PDF siap cetak & bagikan.
    Menyelaraskan sistem desain konsol Admin (warna tema dari /admin/sekolah/identitas,
    font Bricolage Grotesque & IBM Plex Mono, token permukaan & teks Admin).
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Kartu Pantau Wali {{ $kartu['nama'] }}</title>
    <style>
        @include('partials.pdf-fonts')
        @include('partials.pdf-tema')

        @page { margin: 3mm; }

        body {
            margin: 0;
            padding: 0;
            font-family: 'Bricolage Grotesque', 'DejaVu Sans', sans-serif;
            font-size: 9px;
            color: #161b24;
            background: #ffffff;
        }

        .kartu {
            border: 1px solid #d3d9e2;
            border-radius: 2.5mm;
            overflow: hidden;
            background: #ffffff;
        }

        .logo {
            max-height: 10mm;
            max-width: 20mm;
            display: block;
        }

        .isi {
            padding: 4.5mm 5.5mm 4.5mm;
        }

        .identitas {
            margin-bottom: 4mm;
        }

        .label {
            font-size: 7.5px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: #77808f;
        }

        .nama-kelas {
            font-size: 19px;
            font-weight: bold;
            color: #161b24;
            margin: 0.8mm 0 1mm;
            line-height: 1.15;
        }

        .wali {
            font-size: 8.5px;
            color: #4a5565;
            margin: 0;
        }

        .panel-qr {
            border: 1px solid #d3d9e2;
            border-radius: 2mm;
            background: #f2f4f8;
            padding: 4mm 4mm 3.5mm;
            text-align: center;
            margin-bottom: 4mm;
        }

        .qr-bingkai {
            display: inline-block;
            background: #ffffff;
            border: 1px solid #d3d9e2;
            border-radius: 1.5mm;
            padding: 2.5mm;
            margin-bottom: 3mm;
        }

        .qr {
            width: 42mm;
            height: 42mm;
            display: block;
        }

        .kode-kotak {
            background: #ffffff;
            border: 1px solid #d3d9e2;
            border-radius: 1.5mm;
            padding: 2.2mm 4mm;
            margin-bottom: 2.5mm;
        }

        .kode-nilai {
            font-family: 'IBM Plex Mono', 'DejaVu Sans Mono', monospace;
            font-size: 19px;
            font-weight: bold;
            color: #161b24;
            letter-spacing: 3px;
            margin-top: 0.6mm;
        }

        .tautan {
            font-family: 'IBM Plex Mono', 'DejaVu Sans Mono', monospace;
            font-size: 8px;
            font-weight: bold;
            background: #ffffff;
            border: 1px solid #d3d9e2;
            border-radius: 1.5mm;
            padding: 2mm 3mm;
            word-break: break-all;
            text-align: left;
        }

        .kosong {
            margin: 3mm 0 4mm;
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
            padding-top: 2.8mm;
            font-size: 7.5px;
            color: #4a5565;
            line-height: 1.45;
        }

        .kaki-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1.5mm;
            font-size: 7px;
            color: #77808f;
        }
    </style>
</head>
<body>
    <div class="kartu">
        @include('partials.pdf-kop-kartu', [
            'lencana' => 'Kartu Pantau Wali',
        ])

        <div class="isi">
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
                        <img class="qr" width="159" height="159" src="{{ $kartu['qr'] }}" alt="QR pantau wali kelas {{ $kartu['nama'] }}">
                    </div>

                    @if (! empty($kartu['kode']))
                        <div class="kode-kotak">
                            <span class="label">Kode Kelas</span>
                            <div class="kode-nilai">{{ $kartu['kode'] }}</div>
                        </div>
                    @endif

                    <div class="tautan pdf-aksen">{{ $kartu['url'] }}</div>
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
                        <td style="text-align: right; font-family: 'IBM Plex Mono', 'DejaVu Sans Mono', monospace;">
                            {{ now()->format('d/m/Y') }}
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</body>
</html>

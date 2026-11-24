{{--
    Kartu akun guru - satu kartu satu lembar, PDF siap cetak & bagikan.
    Menyelaraskan sistem desain konsol Admin (warna tema, font Bricolage Grotesque
    & IBM Plex Mono, token #161b24 / #4a5565 / #77808f / #d3d9e2 / #f2f4f8).
    Sandi hanya dicetak bila MASIH sandi bawaan (`$kartu['sandi'] !== null`).
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Kartu Akun {{ $kartu['nama'] }}</title>
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

        /* Identitas Guru */
        .identitas {
            margin-bottom: 4.5mm;
        }

        .label {
            font-size: 7.5px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: #77808f;
        }

        .nama {
            font-size: 17px;
            font-weight: bold;
            color: #161b24;
            margin: 0.8mm 0 1mm;
            line-height: 1.2;
        }

        .nip {
            font-family: 'IBM Plex Mono', 'DejaVu Sans Mono', monospace;
            font-size: 8.5px;
            color: #4a5565;
            margin: 0;
        }

        /* Blok Kredensial */
        .kredensial-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-bottom: 4.5mm;
            border: 1px solid #d3d9e2;
            border-radius: 2mm;
            background: #f2f4f8;
        }

        .kredensial-sel {
            padding: 3.5mm 4mm;
            vertical-align: top;
        }

        .kredensial-sel-kanan {
            padding: 3.5mm 4mm;
            vertical-align: top;
            border-left: 1px solid #d3d9e2;
        }

        .nilai-akun {
            font-family: 'IBM Plex Mono', 'DejaVu Sans Mono', monospace;
            font-size: 15px;
            font-weight: bold;
            color: #161b24;
            letter-spacing: 0.4px;
            margin-top: 1mm;
            word-break: break-all;
        }

        .nilai-sandi {
            font-family: 'IBM Plex Mono', 'DejaVu Sans Mono', monospace;
            font-size: 15px;
            font-weight: bold;
            letter-spacing: 1px;
            margin-top: 1mm;
        }

        .catatan-kecil {
            font-size: 7.5px;
            color: #77808f;
            margin-top: 1mm;
        }

        /* Daftar Mapel */
        .mapel-bagian {
            margin-bottom: 4.5mm;
        }

        .mapel-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1.5mm;
        }

        .mapel-table td {
            padding: 1.6mm 0;
            border-bottom: 1px solid #e6eaf0;
            font-size: 8.5px;
            vertical-align: baseline;
        }

        .mapel-table tr:last-child td {
            border-bottom: none;
        }

        .mapel-kode {
            font-family: 'IBM Plex Mono', 'DejaVu Sans Mono', monospace;
            font-size: 8px;
            color: #77808f;
            width: 16%;
            padding-right: 2mm;
        }

        .mapel-nama {
            font-weight: bold;
            color: #161b24;
        }

        .mapel-kelas {
            color: #4a5565;
            text-align: right;
            font-size: 8px;
        }

        /* Kaki Kartu */
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
            margin-top: 1.8mm;
            font-size: 7px;
            color: #77808f;
        }

        .url {
            font-family: 'IBM Plex Mono', 'DejaVu Sans Mono', monospace;
            color: #161b24;
        }
    </style>
</head>
<body>
    <div class="kartu">
        <div class="pdf-kop-bar-kartu">
            <table class="pdf-kop-table">
                <tr>
                    @if (! empty($sekolah['logo']))
                        <td style="width: 16mm; vertical-align: middle; padding-right: 3mm;">
                            <span class="pdf-logo-bingkai">
                                <img class="logo" src="{{ $sekolah['logo'] }}" alt="">
                            </span>
                        </td>
                    @endif
                    <td style="vertical-align: middle;">
                        <div class="pdf-kop-sekolah">{{ $sekolah['nama'] }}</div>
                        @if (! empty($sekolah['npsn']))
                            <div class="pdf-kop-sub">NPSN {{ $sekolah['npsn'] }}</div>
                        @endif
                    </td>
                    <td style="vertical-align: middle; text-align: right; width: 38mm;">
                        <span class="pdf-kop-lencana">Kartu Akun Guru</span>
                    </td>
                </tr>
            </table>
        </div>

        <div class="isi">
            <div class="identitas">
                <span class="label">Pengajar</span>
                <p class="nama">{{ $kartu['nama'] }}</p>
                <p class="nip">{{ $kartu['nip'] ? 'NIP '.$kartu['nip'] : 'NIP belum terdata' }}</p>
            </div>

            <table class="kredensial-table">
                <tr>
                    <td class="kredensial-sel" style="width: {{ $kartu['sandi'] !== null ? '54%' : '100%' }};">
                        <span class="label">Username</span>
                        <div class="nilai-akun">{{ $kartu['username'] }}</div>
                    </td>
                    @if ($kartu['sandi'] !== null)
                        <td class="kredensial-sel-kanan" style="width: 46%;">
                            <span class="label">Sandi Awal</span>
                            <div class="nilai-sandi pdf-aksen">{{ $kartu['sandi'] }}</div>
                            <div class="catatan-kecil">Ubah setelah masuk pertama kali</div>
                        </td>
                    @endif
                </tr>
            </table>

            @if (! empty($kartu['mapel']))
                <div class="mapel-bagian">
                    <span class="label">Mata Pelajaran yang Diampu</span>
                    <table class="mapel-table">
                        @foreach ($kartu['mapel'] as $mapel)
                            <tr>
                                <td class="mapel-kode">{{ $mapel['kode'] }}</td>
                                <td class="mapel-nama">{{ $mapel['nama'] }}</td>
                                <td class="mapel-kelas">
                                    {{ ! empty($mapel['kelas']) ? implode(', ', $mapel['kelas']) : '-' }}
                                </td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            @endif

            <div class="kaki">
                <div>
                    Masuk melalui <span class="url">{{ url('/login') }}</span>.
                    Simpan kartu ini secara pribadi.
                    @if ($kartu['sandi'] !== null)
                        Sandi di atas adalah sandi awal.
                    @endif
                </div>
                <table class="kaki-table">
                    <tr>
                        <td>Kredensial Akun Guru</td>
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

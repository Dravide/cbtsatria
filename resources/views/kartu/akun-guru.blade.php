{{--
    Kartu akun guru - satu kartu satu lembar, PDF siap cetak & bagikan.
    Dirancang khusus dengan CSS yang didukung penuh oleh DomPDF (table layout,
    inline/block, tanpa flexbox/grid/CSS variables).

    Sandi hanya dicetak bila MASIH sandi bawaan (`$kartu['sandi'] !== null`).
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Kartu Akun {{ $kartu['nama'] }}</title>
    <style>
        @page { margin: 12mm; }

        body {
            margin: 0;
            padding: 0;
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            color: #161b24;
            background: #ffffff;
        }

        .kartu {
            border: 1.5px solid #cbd5e1;
            border-radius: 5mm;
            overflow: hidden;
            background: #ffffff;
        }

        /* Header Banner Sekolah */
        .header {
            background: #1e4fa3;
            color: #ffffff;
            padding: 6mm 7mm 5.5mm;
            border-bottom: 3px solid #e0a82e;
        }

        .header-table {
            width: 100%;
            border-collapse: collapse;
        }

        .logo-box {
            width: 16mm;
            height: 16mm;
            background: #ffffff;
            border-radius: 3mm;
            text-align: center;
            vertical-align: middle;
            padding: 1.5mm;
        }

        .logo {
            max-height: 13mm;
            max-width: 13mm;
        }

        .sekolah {
            font-size: 13px;
            font-weight: bold;
            letter-spacing: 0.2px;
            line-height: 1.25;
            color: #ffffff;
        }

        .npsn {
            font-size: 8.5px;
            color: #dbeafe;
            margin-top: 1mm;
        }

        .badge-jenis {
            display: inline-block;
            margin-top: 2mm;
            padding: 1mm 2.8mm;
            background: #143778;
            border: 1px solid #60a5fa;
            border-radius: 2.5mm;
            font-size: 7.5px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #f8fafc;
        }

        /* Badan Kartu */
        .badan {
            padding: 6.5mm 7mm 6mm;
        }

        .identitas {
            margin-bottom: 5.5mm;
            padding-bottom: 4.5mm;
            border-bottom: 1px solid #e2e8f0;
        }

        .label-kecil {
            font-size: 7.5px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #64748b;
        }

        .nama {
            font-size: 17px;
            font-weight: bold;
            color: #0f172a;
            margin: 1mm 0 1.5mm;
            line-height: 1.2;
        }

        .nip-pill {
            display: inline-block;
            padding: 1mm 2.5mm;
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            border-radius: 2mm;
            font-family: DejaVu Sans Mono, monospace;
            font-size: 8.5px;
            color: #334155;
        }

        /* Kotak Kredensial */
        .kredensial-box {
            background: #f4f7fc;
            border: 1px solid #cbd5e1;
            border-left: 4px solid #1e4fa3;
            border-radius: 3mm;
            padding: 4.5mm 5mm;
            margin-bottom: 5.5mm;
        }

        .kredensial-table {
            width: 100%;
            border-collapse: collapse;
        }

        .kredensial-cell {
            vertical-align: top;
            padding: 0;
        }

        .kredensial-cell-kanan {
            vertical-align: top;
            padding-left: 5mm;
            border-left: 1px dashed #cbd5e1;
        }

        .nilai-mono {
            font-family: DejaVu Sans Mono, monospace;
            font-size: 15px;
            font-weight: bold;
            color: #0f172a;
            letter-spacing: 0.5px;
            margin-top: 1.2mm;
            word-break: break-all;
        }

        .sandi-mono {
            font-family: DejaVu Sans Mono, monospace;
            font-size: 15px;
            font-weight: bold;
            color: #1e4fa3;
            letter-spacing: 1.2px;
            margin-top: 1.2mm;
        }

        .catatan-sandi {
            font-size: 7.5px;
            color: #9a3412;
            margin-top: 1mm;
        }

        /* Tabel Mapel */
        .mapel-wrap {
            margin-bottom: 5.5mm;
        }

        .mapel-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 2mm;
            border: 1px solid #e2e8f0;
        }

        .mapel-table th {
            background: #f8fafc;
            color: #475569;
            font-size: 7.5px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            text-align: left;
            padding: 2mm 2.5mm;
            border-bottom: 1px solid #cbd5e1;
        }

        .mapel-table td {
            padding: 2mm 2.5mm;
            font-size: 9px;
            color: #1e293b;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: top;
        }

        .mapel-table tr:last-child td {
            border-bottom: none;
        }

        .kode-chip {
            display: inline-block;
            padding: 0.5mm 1.8mm;
            background: #e3ebf9;
            color: #1e4fa3;
            border-radius: 1.5mm;
            font-family: DejaVu Sans Mono, monospace;
            font-size: 7.5px;
            font-weight: bold;
        }

        .kelas-teks {
            color: #475569;
            font-size: 8.5px;
        }

        /* Info Portal & Footer */
        .portal-box {
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 2.5mm;
            padding: 3mm 4mm;
            font-size: 8px;
            line-height: 1.5;
            color: #78350f;
        }

        .portal-url {
            font-family: DejaVu Sans Mono, monospace;
            font-weight: bold;
            color: #1e4fa3;
        }

        .footer {
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            padding: 2.8mm 7mm;
            font-size: 7.5px;
            color: #64748b;
        }

        .footer-table {
            width: 100%;
            border-collapse: collapse;
        }
    </style>
</head>
<body>
    <div class="kartu">
        <div class="header">
            <table class="header-table">
                <tr>
                    @if (! empty($sekolah['logo']))
                        <td style="width: 19mm; vertical-align: middle; padding-right: 4mm;">
                            <div class="logo-box">
                                <img class="logo" src="{{ $sekolah['logo'] }}" alt="">
                            </div>
                        </td>
                    @endif
                    <td style="vertical-align: middle;">
                        <div class="sekolah">{{ $sekolah['nama'] }}</div>
                        @if (! empty($sekolah['npsn']))
                            <div class="npsn">NPSN {{ $sekolah['npsn'] }}</div>
                        @endif
                        <div class="badge-jenis">Kartu Akun Guru</div>
                    </td>
                </tr>
            </table>
        </div>

        <div class="badan">
            <div class="identitas">
                <div class="label-kecil">Nama Lengkap &amp; Identitas</div>
                <p class="nama">{{ $kartu['nama'] }}</p>
                <span class="nip-pill">
                    {{ $kartu['nip'] ? 'NIP '.$kartu['nip'] : 'NIP belum terdata' }}
                </span>
            </div>

            <div class="kredensial-box">
                <table class="kredensial-table">
                    <tr>
                        <td class="kredensial-cell" style="width: {{ $kartu['sandi'] !== null ? '55%' : '100%' }};">
                            <div class="label-kecil">Username Login</div>
                            <div class="nilai-mono">{{ $kartu['username'] }}</div>
                        </td>
                        @if ($kartu['sandi'] !== null)
                            <td class="kredensial-cell-kanan" style="width: 45%;">
                                <div class="label-kecil">Sandi Awal</div>
                                <div class="sandi-mono">{{ $kartu['sandi'] }}</div>
                                <div class="catatan-sandi">Ganti segera setelah masuk</div>
                            </td>
                        @endif
                    </tr>
                </table>
            </div>

            @if (! empty($kartu['mapel']))
                <div class="mapel-wrap">
                    <div class="label-kecil">Mata Pelajaran yang Diampu</div>
                    <table class="mapel-table">
                        <thead>
                            <tr>
                                <th style="width: 18%;">Kode</th>
                                <th style="width: 47%;">Mata Pelajaran</th>
                                <th style="width: 35%;">Kelas</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($kartu['mapel'] as $mapel)
                                <tr>
                                    <td><span class="kode-chip">{{ $mapel['kode'] }}</span></td>
                                    <td><strong>{{ $mapel['nama'] }}</strong></td>
                                    <td class="kelas-teks">
                                        {{ ! empty($mapel['kelas']) ? implode(', ', $mapel['kelas']) : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <div class="portal-box">
                <strong>Petunjuk Penggunaan:</strong>
                Simpan kartu ini secara pribadi. Gunakan username dan sandi di atas untuk masuk melalui portal
                <span class="portal-url">{{ url('/login') }}</span>.
                @if ($kartu['sandi'] !== null)
                    Demi keamanan, segera ubah sandi awal setelah login pertama kali.
                @endif
            </div>
        </div>

        <div class="footer">
            <table class="footer-table">
                <tr>
                    <td>Dokumen kredensial resmi sekolah · Jangan disebarluaskan</td>
                    <td style="text-align: right; font-family: DejaVu Sans Mono, monospace;">
                        Dicetak {{ now()->format('d/m/Y') }}
                    </td>
                </tr>
            </table>
        </div>
    </div>
</body>
</html>

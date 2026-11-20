{{--
    Kartu akun guru - satu kartu satu lembar, PDF siap cetak & bagikan.
    Gaya minimalis editorial: bersih, hemat tinta saat dicetak, proporsional
    saat dibuka di ponsel (WhatsApp), dan 100% kompatibel dengan DomPDF.

    Sandi hanya dicetak bila MASIH sandi bawaan (`$kartu['sandi'] !== null`).
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Kartu Akun {{ $kartu['nama'] }}</title>
    <style>
        @page { margin: 14mm; }

        body {
            margin: 0;
            padding: 0;
            font-family: DejaVu Sans, sans-serif;
            font-size: 9.5px;
            color: #111827;
            background: #ffffff;
        }

        .kartu {
            border: 1px solid #d1d5db;
            border-top: 3.5px solid #111827;
            border-radius: 3mm;
            padding: 7mm 7.5mm 6mm;
            background: #ffffff;
        }

        /* Kop Minimalis */
        .kop {
            padding-bottom: 4.5mm;
            margin-bottom: 5.5mm;
            border-bottom: 1px solid #e5e7eb;
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
            font-size: 11.5px;
            font-weight: bold;
            color: #111827;
            letter-spacing: 0.1px;
        }

        .npsn {
            font-size: 8px;
            color: #6b7280;
            margin-top: 0.6mm;
        }

        .lencana {
            display: inline-block;
            padding: 1mm 2.5mm;
            border: 1px solid #d1d5db;
            border-radius: 2mm;
            font-size: 7.5px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #374151;
            background: #f9fafb;
        }

        /* Identitas Guru */
        .identitas {
            margin-bottom: 5.5mm;
        }

        .label {
            font-size: 7.5px;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #6b7280;
        }

        .nama {
            font-size: 18px;
            font-weight: bold;
            color: #111827;
            margin: 0.8mm 0 1mm;
            line-height: 1.2;
        }

        .nip {
            font-family: DejaVu Sans Mono, monospace;
            font-size: 9px;
            color: #4b5563;
            margin: 0;
        }

        /* Blok Kredensial Minimalis */
        .kredensial-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-bottom: 5.5mm;
            border: 1px solid #e5e7eb;
            border-radius: 2.5mm;
            background: #f9fafb;
        }

        .kredensial-sel {
            padding: 4mm 4.5mm;
            vertical-align: top;
        }

        .kredensial-sel-kanan {
            padding: 4mm 4.5mm;
            vertical-align: top;
            border-left: 1px solid #e5e7eb;
        }

        .nilai-akun {
            font-family: DejaVu Sans Mono, monospace;
            font-size: 16px;
            font-weight: bold;
            color: #111827;
            letter-spacing: 0.4px;
            margin-top: 1.2mm;
            word-break: break-all;
        }

        .nilai-sandi {
            font-family: DejaVu Sans Mono, monospace;
            font-size: 16px;
            font-weight: bold;
            color: #1e4fa3;
            letter-spacing: 1.2px;
            margin-top: 1.2mm;
        }

        .catatan-kecil {
            font-size: 7.5px;
            color: #6b7280;
            margin-top: 1mm;
        }

        /* Daftar Mapel Minimalis */
        .mapel-bagian {
            margin-bottom: 5.5mm;
        }

        .mapel-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1.8mm;
        }

        .mapel-table td {
            padding: 1.8mm 0;
            border-bottom: 1px solid #f3f4f6;
            font-size: 9px;
            vertical-align: baseline;
        }

        .mapel-table tr:last-child td {
            border-bottom: none;
        }

        .mapel-kode {
            font-family: DejaVu Sans Mono, monospace;
            font-size: 8px;
            color: #6b7280;
            width: 16%;
            padding-right: 2mm;
        }

        .mapel-nama {
            font-weight: bold;
            color: #111827;
        }

        .mapel-kelas {
            color: #4b5563;
            text-align: right;
            font-size: 8.5px;
        }

        /* Kaki Kartu */
        .kaki {
            border-top: 1px solid #e5e7eb;
            padding-top: 3.5mm;
            font-size: 8px;
            color: #6b7280;
            line-height: 1.5;
        }

        .kaki-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 2mm;
            font-size: 7.5px;
            color: #9ca3af;
        }

        .url {
            font-family: DejaVu Sans Mono, monospace;
            color: #111827;
        }
    </style>
</head>
<body>
    <div class="kartu">
        <div class="kop">
            <table class="kop-table">
                <tr>
                    @if (! empty($sekolah['logo']))
                        <td style="width: 15mm; vertical-align: middle; padding-right: 3mm;">
                            <img class="logo" src="{{ $sekolah['logo'] }}" alt="">
                        </td>
                    @endif
                    <td style="vertical-align: middle;">
                        <div class="sekolah">{{ $sekolah['nama'] }}</div>
                        @if (! empty($sekolah['npsn']))
                            <div class="npsn">NPSN {{ $sekolah['npsn'] }}</div>
                        @endif
                    </td>
                    <td style="vertical-align: middle; text-align: right; width: 36mm;">
                        <span class="lencana">Kartu Akun Guru</span>
                    </td>
                </tr>
            </table>
        </div>

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
                        <div class="nilai-sandi">{{ $kartu['sandi'] }}</div>
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
                                {{ ! empty($mapel['kelas']) ? implode(', ', $mapel['kelas']) : '—' }}
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
                    <td style="text-align: right; font-family: DejaVu Sans Mono, monospace;">
                        {{ now()->format('d/m/Y') }}
                    </td>
                </tr>
            </table>
        </div>
    </div>
</body>
</html>

{{--
    Kartu panitia & pengawas - 8 kartu per lembar A4.
    Menyelaraskan sistem desain konsol Admin (warna tema, Bricolage Grotesque & IBM Plex Mono).
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Kartu panitia {{ $event['kode'] }}</title>
    <style>
        @include('partials.pdf-fonts')
        @include('partials.pdf-tema')

        @page { margin: 10mm 8mm; }

        body {
            margin: 0;
            font-family: 'Bricolage Grotesque', 'DejaVu Sans', sans-serif;
            font-size: 9px;
            color: #161b24;
        }

        table.lembar { width: 100%; border-collapse: separate; border-spacing: 4mm 4mm; }

        td.kartu {
            width: 50%;
            height: 66mm;
            border: 1px solid #d3d9e2;
            border-radius: 2.5mm;
            padding: 0;
            vertical-align: top;
            overflow: hidden;
            background: #ffffff;
        }

        .kartu-kop {
            padding: 2.8mm 4mm;
        }

        .kartu-isi {
            padding: 3.5mm 4mm 3mm;
        }

        .sekolah { font-size: 9.5px; font-weight: bold; line-height: 1.2; }
        .npsn { font-size: 6.8px; margin-top: 0.4mm; }
        .event { font-size: 6.8px; margin-top: 0.6mm; }

        .nama { font-size: 13px; font-weight: bold; color: #161b24; margin: 0 0 0.8mm; }

        .jabatan {
            display: inline-block;
            font-size: 6.8px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #4a5565;
            background: #f2f4f8;
            border: 1px solid #d3d9e2;
            border-radius: 1mm;
            padding: 0.5mm 1.8mm;
            margin: 0 0 2.5mm;
        }

        .label { font-size: 6.8px; font-weight: bold; color: #77808f; text-transform: uppercase; letter-spacing: 0.4px; }

        .cakupan { font-size: 8.5px; color: #161b24; margin-top: 0.5mm; }

        .kode-box {
            margin-top: 2.5mm;
            padding: 2mm 3mm;
            background: #f2f4f8;
            border: 1px solid #d3d9e2;
            border-radius: 1.5mm;
        }

        .kode {
            font-family: 'IBM Plex Mono', 'DejaVu Sans Mono', monospace;
            font-size: 20px;
            font-weight: bold;
            letter-spacing: 3px;
            line-height: 1.15;
            margin-top: 0.4mm;
        }

        .petunjuk { font-size: 6.5px; color: #77808f; margin: 1.2mm 0 0; line-height: 1.35; }

        .akun { font-family: 'IBM Plex Mono', 'DejaVu Sans Mono', monospace; font-size: 7px; color: #4a5565; }

        .logo { max-height: 8.5mm; max-width: 18mm; display: block; }
    </style>
</head>
<body>
    @foreach (collect($kartu)->chunk(2) as $dua)
        <table class="lembar">
            <tr>
                @foreach ($dua as $satu)
                    <td class="kartu">
                        @include('partials.pdf-kop-kartu', [
                            'kelas' => 'kartu-kop',
                            'kelasNama' => 'sekolah',
                            'kelasSub' => 'npsn',
                            'kelasSub2' => 'event',
                            'sub' => $event['nama'] . ' · ' . $event['kode'],
                        ])

                        <div class="kartu-isi">
                            <p class="nama">{{ $satu['nama'] }}</p>
                            <span class="jabatan">{{ $satu['jabatan'] }}</span>

                            <div>
                                <span class="label">Cakupan</span>
                                <div class="cakupan">{{ $satu['cakupan'] }}</div>
                            </div>

                            @if ($satu['kode'] !== null)
                                <div class="kode-box">
                                    <span class="label">Kode pengawas</span>
                                    <div class="kode pdf-aksen">{{ $satu['kode'] }}</div>
                                </div>

                                <p class="petunjuk">
                                    Kode milik orang, bukan ruang. Berlaku di ruang mana pun yang ditugaskan.
                                </p>
                            @endif

                            @if ($satu['akun'] !== null)
                                <p class="petunjuk" style="margin-top:1.5mm">
                                    Akun: <span class="akun">{{ $satu['akun'] }}</span>
                                </p>
                            @endif
                        </div>
                    </td>
                @endforeach

                @for ($i = $dua->count(); $i < 2; $i++)
                    <td class="kartu" style="border:none; background:none;"></td>
                @endfor
            </tr>
        </table>
    @endforeach
</body>
</html>

{{--
    Kartu pintu ruang - satu lembar A4 untuk SETIAP ruang, wireframe plate 09.

    ---------------------------------------------------------------------
    Ditulis tanpa CSS aplikasi, dan itu disengaja
    ---------------------------------------------------------------------
    Bentuk dan alasannya sama dengan `kartu/pengawas.blade.php`: DomPDF tidak
    mengenal Tailwind, custom property, maupun flexbox. Berkas ini HTML
    telanjang dengan gaya inline, karena satu-satunya tujuannya adalah
    dicetak.

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
    <title>Kartu pintu ruang</title>
    <style>
        @page { margin: 14mm; }

        body {
            font-family: DejaVu Sans, sans-serif;
            color: #161b24;
        }

        .halaman {
            page-break-after: always;
            text-align: center;
        }

        .halaman.terakhir { page-break-after: auto; }

        .kop {
            border-bottom: 2px solid #161b24;
            padding-bottom: 4mm;
            margin-bottom: 12mm;
            text-align: left;
        }

        .sekolah { font-size: 13px; font-weight: bold; }
        .npsn { font-size: 9px; color: #4a5565; }
        .logo { max-height: 16mm; max-width: 34mm; }

        .label { font-size: 10px; color: #77808f; text-transform: uppercase; letter-spacing: 1px; }

        .kode { font-size: 40px; font-weight: bold; margin: 2mm 0 1mm; }

        .nama { font-size: 16px; margin: 0 0 1mm; }
        .lokasi { font-size: 10px; color: #4a5565; margin-bottom: 10mm; }

        .qr { width: 88mm; height: 88mm; }

        .petunjuk { font-size: 10px; color: #4a5565; margin-top: 8mm; }

        .tautan {
            font-family: DejaVu Sans Mono, monospace;
            font-size: 11px;
            margin-top: 4mm;
            word-break: break-all;
            padding: 3mm 4mm;
            border: 1px solid #8e97a6;
            border-radius: 2mm;
            display: inline-block;
        }

        .kedaluwarsa { font-size: 8px; color: #77808f; margin-top: 3mm; }

        .kosong {
            margin-top: 30mm;
            padding: 10mm;
            border: 2px dashed #b3261e;
            color: #b3261e;
            font-size: 12px;
            border-radius: 3mm;
        }
    </style>
</head>
<body>
    @foreach ($halaman as $i => $h)
        @php $terakhir = $i === count($halaman) - 1; @endphp
        <div class="halaman {{ $terakhir ? 'terakhir' : '' }}">
            <div class="kop">
                <table style="width:100%; border-collapse:collapse">
                    <tr>
                        @if (! empty($sekolah['logo']))
                            <td style="width:38mm; vertical-align:middle">
                                <img class="logo" src="{{ $sekolah['logo'] }}" alt="">
                            </td>
                        @endif
                        <td style="vertical-align:middle">
                            <div class="sekolah">{{ $sekolah['nama'] }}</div>
                            @if (! empty($sekolah['npsn']))
                                <div class="npsn">NPSN {{ $sekolah['npsn'] }}</div>
                            @endif
                        </td>
                    </tr>
                </table>
            </div>

            <div class="label">Ruang ujian</div>
            <div class="kode">{{ $h['kode'] }}</div>
            @if (! empty($h['nama']))
                <div class="nama">{{ $h['nama'] }}</div>
            @endif
            @if (! empty($h['lokasi']))
                <div class="lokasi">{{ $h['lokasi'] }}</div>
            @else
                <div class="lokasi">&nbsp;</div>
            @endif

            @if ($h['qr'] !== null)
                <img class="qr" src="{{ $h['qr'] }}" alt="QR pintu ruang {{ $h['kode'] }}">

                <p class="petunjuk">Pindai QR ini untuk membuka pintu ruang.</p>

                <div class="tautan">{{ $h['url'] }}</div>

                @if (! empty($h['kedaluwarsa']))
                    <div class="kedaluwarsa">Tautan berlaku sampai {{ $h['kedaluwarsa'] }}. Hubungi Admin bila kedaluwarsa.</div>
                @endif
            @else
                <div class="kosong">
                    Tautan pintu ruang ini belum diterbitkan.<br>
                    Terbitkan dulu di laman Ruang, lalu cetak ulang kartu ini.
                </div>
            @endif
        </div>
    @endforeach
</body>
</html>

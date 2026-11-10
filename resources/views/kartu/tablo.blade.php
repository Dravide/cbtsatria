{{--
    Tablo - daftar hadir per ruang, wireframe plate 16d.

    ---------------------------------------------------------------------
    Ditulis tanpa CSS aplikasi, dan itu disengaja
    ---------------------------------------------------------------------
    Sama dengan `kartu/ruang.blade.php` dan `kartu/pengawas.blade.php`:
    DomPDF tidak mengenal Tailwind, custom property, maupun flexbox. Berkas
    ini HTML telanjang dengan gaya inline, karena satu-satunya tujuannya
    adalah dicetak dan ditandatangani.

    ---------------------------------------------------------------------
    Kolom Tanda tangan sengaja KOSONG dan berlebar tetap
    ---------------------------------------------------------------------
    Kolom itu bukan hiasan: pengawas menandai kehadiran anak dengan pena, dan
    kolom yang sempit membuat tanda centangnya meluber ke kolom Nama. Lebarnya
    ditetapkan di header tabel, dan setiap barisnya mewarisinya.

    ---------------------------------------------------------------------
    Baris kursi kosong ikut tercetak
    ---------------------------------------------------------------------
    Kursi yang belum terisi tetap mendapat barisnya, dengan nama kosong dan
    garis bawah. Pengawas memakainya untuk menuliskan siswa pindahan, dan
    tablo yang berhenti di kursi terakhir yang kebetulan terisi membuat
    ruangan tampak lebih penuh daripada kenyataannya.

    ---------------------------------------------------------------------
    Halaman terakhir tanpa `page-break-after`
    ---------------------------------------------------------------------
    Halaman kosong di belakang lembar terakhir adalah pemborosan kertas yang
    selalu diperhatikan orang yang mencetak tiga puluh lembar.

    ---------------------------------------------------------------------
    Yang TIDAK boleh dilakukan dengan lembar ini
    ---------------------------------------------------------------------
    Tablo memuat empat puluh nama anak. Halaman `/p/{slug}` di pintu sengaja
    tidak memuat satu pun nama, dan tablo yang ditempel di daun pintu bersama
    kartu QR membatalkan penjagaan itu. Yang dicetak untuk pintu adalah kartu
    QR; tablo berhenti di meja pengawas.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Tablo daftar hadir</title>
    <style>
        @page { margin: 12mm; }

        body {
            font-family: DejaVu Sans, sans-serif;
            color: #161b24;
            font-size: 9px;
        }

        .halaman {
            page-break-after: always;
        }

        .halaman.terakhir { page-break-after: auto; }

        .kop {
            border-bottom: 2px solid #161b24;
            padding-bottom: 3mm;
            margin-bottom: 4mm;
        }

        .sekolah { font-size: 12px; font-weight: bold; }
        .npsn { font-size: 8px; color: #4a5565; }
        .logo { max-height: 13mm; max-width: 28mm; }

        .judul { font-size: 13px; font-weight: bold; margin: 3mm 0 1mm; }
        .anak { font-size: 9px; color: #4a5565; margin-bottom: 2mm; }

        table.tablo {
            width: 100%;
            border-collapse: collapse;
        }

        table.tablo th {
            background: #eef1f5;
            border: 1px solid #8e97a6;
            padding: 1.6mm 1.5mm;
            font-size: 8px;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: .4px;
        }

        table.tablo td {
            border: 1px solid #c3cad4;
            padding: 1.4mm 1.5mm;
            font-size: 9px;
        }

        .c { text-align: center; }
        .mono { font-family: DejaVu Sans Mono, monospace; }

        .ttd { border-bottom: 1px dotted #8e97a6; }

        .kaki {
            margin-top: 4mm;
            font-size: 9px;
        }

        .tanda {
            margin-top: 10mm;
            text-align: right;
        }

        .tanda .nama { margin-top: 16mm; border-top: 1px solid #161b24; padding-top: 1mm; display: inline-block; min-width: 55mm; }
        .tanda .label { font-size: 8px; color: #4a5565; }
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
                            <td style="width:32mm; vertical-align:middle">
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

            <div class="judul">Daftar hadir &mdash; Ruang {{ $h['kode'] }}{{ $h['nama'] ? ' · '.$h['nama'] : '' }}</div>
            <div class="anak">
                {{ $h['ujian'] ?? 'Ujian' }}
                @if ($h['sesi']) · {{ $h['sesi'] }} @endif
                @if ($h['jam']) · {{ $h['jam'] }} @endif
                · {{ $h['terisi'] }} peserta dari {{ $h['kapasitas'] }} kursi
                · {{ $h['jumlah_meja'] }} meja, {{ $h['kursi_per_meja'] }} kursi per meja
            </div>

            <table class="tablo">
                <thead>
                    <tr>
                        <th class="c" style="width:14mm">Kursi</th>
                        <th>Nama peserta</th>
                        <th style="width:24mm">NIS</th>
                        <th style="width:20mm">Kelas</th>
                        <th class="c" style="width:14mm">Meja</th>
                        <th class="c" style="width:26mm">Tanda tangan</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($h['baris'] as $b)
                        <tr>
                            <td class="c mono">{{ str_pad($b['kursi'], 2, '0', STR_PAD_LEFT) }}</td>
                            <td>{{ $b['nama'] }}</td>
                            <td class="mono">{{ $b['nis'] ?? '' }}</td>
                            <td>{{ $b['kelas'] ?? '' }}</td>
                            <td class="c mono">M-{{ $b['meja'] }}</td>
                            <td class="ttd"></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="kaki">
                Jumlah peserta: <span class="mono">{{ $h['terisi'] }}</span>
                &nbsp;·&nbsp; Hadir: <span class="mono">____________</span>
                &nbsp;·&nbsp; Tidak hadir: <span class="mono">____________</span>
            </div>

            <div class="tanda">
                <div class="label">Pengawas ruang {{ $h['kode'] }}</div>
                <div class="nama">&nbsp;</div>
            </div>
        </div>
    @endforeach
</body>
</html>

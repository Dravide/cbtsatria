{{--
    Kartu akun guru - satu kartu satu lembar, PDF siap cetak.

    ---------------------------------------------------------------------
    Ditulis tanpa CSS aplikasi, dan itu disengaja
    ---------------------------------------------------------------------
    Bentuk dan alasannya sama dengan `kartu/pengawas.blade.php`: DomPDF tidak
    mengenal Tailwind, custom property, maupun flexbox. Berkas ini HTML
    telanjang dengan gaya inline, karena satu-satunya tujuannya adalah
    dicetak dan dibagikan ke guru.

    ---------------------------------------------------------------------
    Satu kartu per LEMBAR, bukan delapan per halaman
    ---------------------------------------------------------------------
    Kartu pengawas dipadatkan delapan per A4 karena dicetak Admin lalu
    dipotong sendiri. Kartu ini dibagikan ke ORANG dan sering dilaminasi
    atau diselipkan di dompet - jadi ia mendapat lembar penuh supaya
    username dan sandinya terbaca dari jarak tangan, bukan dari jarak
    sejengkal.

    ---------------------------------------------------------------------
    Sandi hanya dicetak bila MASIH sandi bawaan
    ---------------------------------------------------------------------
    `$kartu['sandi']` bernilai null untuk guru yang sudah mengganti sandinya.
    Mencetak "password" pada akun seperti itu adalah kartu yang menuntun
    guru memakai sandi yang salah. Baris "Sandi" karena itu HILANG, bukan
    tercetak kosong - baris kosong terbaca sebagai "sandinya gagal tercetak",
    dan guru akan meminta Admin mencetak ulang.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Kartu akun {{ $kartu['nama'] }}</title>
    <style>
        @page { margin: 18mm 16mm; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            color: #161b24;
        }

        .kartu {
            border: 1px solid #8e97a6;
            border-radius: 4mm;
            padding: 8mm;
        }

        .kop {
            border-bottom: 2px solid #161b24;
            padding-bottom: 4mm;
            margin-bottom: 6mm;
        }

        .sekolah { font-size: 13px; font-weight: bold; }
        .npsn { font-size: 8px; color: #4a5565; }
        .jenis {
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #4a5565;
            margin-top: 1mm;
        }

        .logo { max-height: 14mm; max-width: 30mm; }

        .nama { font-size: 18px; font-weight: bold; margin: 0 0 1mm; }
        .nip { font-family: DejaVu Sans Mono, monospace; font-size: 10px; color: #4a5565; margin: 0 0 6mm; }

        .label { font-size: 8px; color: #77808f; text-transform: uppercase; letter-spacing: 0.5px; }

        .kolom { margin-bottom: 5mm; }

        .nilai {
            font-family: DejaVu Sans Mono, monospace;
            font-size: 20px;
            font-weight: bold;
            letter-spacing: 0.5px;
            margin-top: 1mm;
        }

        .sandi {
            font-family: DejaVu Sans Mono, monospace;
            font-size: 20px;
            font-weight: bold;
            letter-spacing: 1.5px;
            margin-top: 1mm;
        }

        .garis { border-top: 1px dashed #d3d9e2; margin: 5mm 0; }

        .mapel { font-size: 10px; margin-top: 1mm; line-height: 1.5; }
        .mapel-kode {
            font-family: DejaVu Sans Mono, monospace;
            font-size: 8px;
            color: #77808f;
        }
        .kelas { color: #4a5565; }

        .petunjuk {
            font-size: 8px;
            color: #77808f;
            margin-top: 6mm;
            line-height: 1.5;
            border-top: 1px solid #d3d9e2;
            padding-top: 3mm;
        }
    </style>
</head>
<body>
    <div class="kartu">
        <div class="kop">
            <table style="width:100%; border-spacing:0">
                <tr>
                    @if (! empty($sekolah['logo']))
                        <td style="width:34mm; vertical-align:top">
                            <img class="logo" src="{{ $sekolah['logo'] }}" alt="">
                        </td>
                    @endif
                    <td style="vertical-align:top">
                        <div class="sekolah">{{ $sekolah['nama'] }}</div>
                        @if (! empty($sekolah['npsn']))
                            <div class="npsn">NPSN {{ $sekolah['npsn'] }}</div>
                        @endif
                        <div class="jenis">Kartu akun guru</div>
                    </td>
                </tr>
            </table>
        </div>

        <p class="nama">{{ $kartu['nama'] }}</p>
        <p class="nip">{{ $kartu['nip'] ?? 'NIP belum terdata' }}</p>

        <div class="kolom">
            <span class="label">Username</span>
            <div class="nilai">{{ $kartu['username'] }}</div>
        </div>

        @if ($kartu['sandi'] !== null)
            <div class="kolom">
                <span class="label">Sandi awal</span>
                <div class="sandi">{{ $kartu['sandi'] }}</div>
            </div>
        @endif

        @if (! empty($kartu['mapel']))
            <div class="garis"></div>

            <span class="label">Mata pelajaran yang diampu</span>
            <div class="mapel">
                @foreach ($kartu['mapel'] as $mapel)
                    <div>
                        <span class="mapel-kode">{{ $mapel['kode'] }}</span>
                        {{ $mapel['nama'] }}
                        @if (! empty($mapel['kelas']))
                            <span class="kelas">&mdash; {{ implode(', ', $mapel['kelas']) }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        <div class="petunjuk">
            Simpan kartu ini. Username dan sandi dipakai untuk masuk ke aplikasi ujian sekolah.
            @if ($kartu['sandi'] !== null)
                Sandi di atas adalah sandi awal &mdash; ganti segera setelah masuk pertama kali.
            @endif
        </div>
    </div>
</body>
</html>

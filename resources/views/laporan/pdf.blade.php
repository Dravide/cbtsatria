{{--
    Berkas PDF ekspor laporan - wireframe-admin.html plate 22.

    ---------------------------------------------------------------------
    Ditulis tanpa CSS aplikasi, dan itu disengaja
    ---------------------------------------------------------------------
    DomPDF tidak menjalankan Tailwind, tidak mengenal custom property, dan
    tidak punya flexbox. Berkas ini memakai HTML tabel telanjang dengan gaya
    inline, karena ia satu-satunya bentuk yang dijamin dikenali DomPDF apa
    adanya. Memakai kelas aplikasi di sini berarti berkas yang tampak benar di
    peramban dan berantakan saat dicetak - dan PDF adalah format yang DICETAK.

    ---------------------------------------------------------------------
    Lima puluh baris, bukan semuanya
    ---------------------------------------------------------------------
    Pemotongan dilakukan pemanggil (`BuatEksporLaporan::tulisPdf()`), bukan di
    sini: yang mengetahui jumlah total dan batasnya adalah job-nya. Kalimat
    "menampilkan 50 dari N" di bawah karena itu membaca `$total` yang
    dikirimnya, supaya Admin yang menerima PDF terpotong tahu bahwa laporannya
    terpotong - bukan mengira angkatannya cuma lima puluh orang.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $judul }}</title>
    <style>
        @page { margin: 18mm 12mm; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 8px;
            color: #161b24;
        }

        h1 {
            font-size: 13px;
            margin: 0 0 2px;
        }

        .sub {
            font-size: 8px;
            color: #4a5565;
            margin: 0 0 10px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            border: 1px solid #d3d9e2;
            padding: 3px 4px;
            text-align: left;
        }

        th {
            background: #e4e9f0;
            font-size: 7px;
            text-transform: uppercase;
        }

        .angka { text-align: right; }

        .catatan {
            margin-top: 8px;
            font-size: 7px;
            color: #77808f;
        }
    </style>
</head>
<body>
    <h1>{{ $judul }}</h1>
    <p class="sub">Dibuat {{ $dibuat }} &middot; {{ $total }} baris</p>

    <table>
        <thead>
            <tr>
                @foreach ($kolom as $label)
                    <th>{{ $label }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($baris as $satu)
                <tr>
                    @foreach ($satu as $sel)
                        {{-- Sel kosong dicetak sebagai tanda hubung. Sel kosong
                             di tabel bergaris terbaca sebagai baris yang gagal
                             dirender, bukan sebagai "nilai ini memang tidak
                             ada" - dan pada laporan nilai keduanya kalimat
                             yang sangat berbeda. --}}
                        <td>{{ $sel ?? '—' }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($kolom) }}">Tidak ada baris untuk penyaring ini.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if ($total > count($baris))
        <p class="catatan">
            Menampilkan {{ count($baris) }} dari {{ $total }} baris. Untuk daftar
            lengkap, unduh berkas XLSX dari laman Laporan &amp; ekspor.
        </p>
    @endif
</body>
</html>

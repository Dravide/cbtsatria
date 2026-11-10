{{--
    Denah tempat duduk - lembar yang DITEMPPEL di meja pengawas.

    ---------------------------------------------------------------------
    Ditulis tanpa CSS aplikasi, dan itu disengaja
    ---------------------------------------------------------------------
    Sama dengan `kartu/tablo.blade.php`: DomPDF tidak mengenal Tailwind,
    custom property, maupun flexbox/grid. Berkas ini HTML telanjang dengan
    gaya inline. Deretan meja digambar sebagai TABEL dengan kolom tetap -
    satu-satunya cara DomPDF meletakkan kotak berdampingan.

    ---------------------------------------------------------------------
    Bentuk Z: empat kolom meja, disusur satu baris penuh lalu turun
    ---------------------------------------------------------------------
    Kursi 1..8 berurutan sepanjang satu jalur masuk, sehingga pengawas yang
    menyusur dari pintu tidak perlu menyeberang ruangan untuk menemukan kursi
    berikutnya. Yang menentukan nomor dan mejanya `NomorKursi` di server;
    berkas ini hanya menggambarnya.

    ---------------------------------------------------------------------
    Nama ikut tercetak, dan lembar ini TIDAK boleh pindah ke koridor
    ---------------------------------------------------------------------
    Kartu pintu sengaja tidak memuat satu pun nama. Lembar ini sebaliknya -
    ia ditempel di DALAM ruang ujian, dan gunanya justru menunjukkan siapa
    duduk di mana. Orang yang mencetaknya perlu tahu bedanya.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Denah tempat duduk</title>
    <style>
        @page { margin: 10mm; }

        body {
            font-family: DejaVu Sans, sans-serif;
            color: #161b24;
            font-size: 9px;
        }

        .halaman { page-break-after: always; }
        .halaman.terakhir { page-break-after: auto; }

        .kop {
            border-bottom: 2px solid #161b24;
            padding-bottom: 2.5mm;
            margin-bottom: 3mm;
        }

        .sekolah { font-size: 12px; font-weight: bold; }
        .npsn { font-size: 8px; color: #4a5565; }
        .logo { max-height: 12mm; max-width: 26mm; }

        .judul { font-size: 13px; font-weight: bold; margin: 2mm 0 1mm; }
        .anak { font-size: 9px; color: #4a5565; }

        .papan {
            margin: 3mm 0 2mm;
            border: 1px solid #8e97a6;
            background: #eef1f5;
            text-align: center;
            padding: 1.2mm;
            font-size: 7px;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: #4a5565;
        }

        table.denah { width: 100%; border-collapse: separate; border-spacing: 1.6mm; }

        table.denah td.baris-kosong {
            border: none;
            background: none;
        }

        .meja {
            border: 1px solid #8e97a6;
            background: #f6f8fa;
            padding: 1mm;
        }

        table.kursi { width: 100%; border-collapse: separate; border-spacing: 1mm; }

        td.kursi {
            border: 1px solid #c3cad4;
            background: #fff;
            padding: 1.2mm 1mm;
            vertical-align: top;
            width: 50%;
        }

        td.kursi.kosong {
            border: 1px dashed #c3cad4;
            background: #fbfcfd;
        }

        .nomor { font-family: DejaVu Sans Mono, monospace; font-size: 8px; color: #6b7686; }
        .nama { font-size: 9px; }
        .kecil { font-family: DejaVu Sans Mono, monospace; font-size: 7px; color: #6b7686; }

        .kaki { margin-top: 3mm; font-size: 8px; color: #4a5565; }
        .kaki .mono { font-family: DejaVu Sans Mono, monospace; color: #161b24; }

        .tanda { margin-top: 8mm; text-align: right; }
        .tanda .nama { margin-top: 14mm; border-top: 1px solid #161b24; padding-top: 1mm; display: inline-block; min-width: 55mm; }
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
                            <td style="width:30mm; vertical-align:middle">
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

            <div class="judul">Denah tempat duduk &mdash; Ruang {{ $h['kode'] }}{{ $h['nama'] ? ' · '.$h['nama'] : '' }}</div>
            <div class="anak">
                {{ $h['ujian'] ?? 'Ujian' }}
                @if ($h['sesi']) · {{ $h['sesi'] }} @endif
                @if ($h['tanggal']) · {{ $h['tanggal'] }} @endif
                @if ($h['jam']) · {{ $h['jam'] }} @endif
                · {{ $h['terisi'] }} peserta dari {{ $h['kapasitas'] }} kursi
                · {{ $h['jumlah_meja'] }} meja, {{ $h['kursi_per_meja'] }} kursi per meja
            </div>

            <div class="papan">Papan tulis / arah depan &mdash; pintu masuk di sisi bawah</div>

            <table class="denah">
                @foreach ($h['baris_meja'] as $baris)
                    <tr>
                        @for ($k = 0; $k < $h['meja_per_baris']; $k++)
                            @php $meja = $baris[$k] ?? null; @endphp
                            <td class="{{ $meja === null ? 'baris-kosong' : '' }}" style="width:{{ round(100 / $h['meja_per_baris'], 2) }}%">
                                @if ($meja !== null)
                                    <div class="meja">
                                        <table class="kursi">
                                            <tr>
                                                @foreach ($meja as $s)
                                                    <td class="kursi {{ $s['peserta'] === null ? 'kosong' : '' }}">
                                                        <div class="nomor">{{ str_pad($s['kursi'], 2, '0', STR_PAD_LEFT) }}</div>
                                                        @if ($s['peserta'] === null)
                                                            <div class="nomor">&mdash;</div>
                                                        @else
                                                            <div class="nama">{{ $s['peserta']['nama'] }}</div>
                                                            <div class="kecil">
                                                                {{ $s['peserta']['kelas'] ?? '—' }}@if ($s['peserta']['nis']) · {{ $s['peserta']['nis'] }}@endif
                                                            </div>
                                                        @endif
                                                    </td>
                                                @endforeach
                                            </tr>
                                        </table>
                                        <div class="kecil" style="text-align:right">M-{{ $meja[0]['meja'] }}</div>
                                    </div>
                                @endif
                            </td>
                        @endfor
                    </tr>
                @endforeach
            </table>

            <div class="kaki">
                Kursi terisi: <span class="mono">{{ $h['terisi'] }}</span>
                &nbsp;·&nbsp; Kursi kosong: <span class="mono">{{ max(0, $h['kapasitas'] - $h['terisi']) }}</span>
                &nbsp;·&nbsp; Nomor kursi sama dengan yang dibaca pengawas di daftar hadir.
                &nbsp;·&nbsp; Lembar ini memuat nama &mdash; simpan di dalam ruang, jangan di koridor.
            </div>

            <div class="tanda">
                <div class="label">Pengawas ruang {{ $h['kode'] }}</div>
                <div class="nama">&nbsp;</div>
            </div>
        </div>
    @endforeach
</body>
</html>

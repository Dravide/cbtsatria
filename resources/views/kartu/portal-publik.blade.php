<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Poster QR Portal Publik · {{ $sekolah->name }}</title>
    <style>
        @page { size: A4 portrait; margin: 16mm; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 24px 16px;
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            background: #eef2f7;
            color: #161b24;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .aksi {
            max-width: 560px;
            margin: 0 auto 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }
        .btn {
            appearance: none;
            border: 1px solid #1e4fa3;
            background: #1e4fa3;
            color: #ffffff;
            font-size: 13px;
            font-weight: 600;
            padding: 8px 16px;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
        }
        .btn-alt {
            border-color: #cbd5e1;
            background: #ffffff;
            color: #334155;
        }
        .poster {
            max-width: 560px;
            margin: 0 auto;
            background: #ffffff;
            border: 2px solid #161b24;
            border-radius: 16px;
            padding: 36px 32px;
            text-align: center;
            box-shadow: 0 12px 30px -18px rgba(16, 22, 32, 0.35);
        }
        .logo {
            max-height: 54px;
            max-width: 180px;
            object-fit: contain;
            margin-bottom: 14px;
        }
        .eyebrow {
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #1e4fa3;
            margin: 0 0 6px;
        }
        h1 {
            margin: 0;
            font-size: 26px;
            line-height: 1.2;
        }
        .sub {
            margin: 8px 0 24px;
            font-size: 14px;
            color: #4a5565;
        }
        .qr-box {
            display: inline-block;
            padding: 16px;
            border: 2px solid #161b24;
            border-radius: 16px;
            background: #ffffff;
        }
        .qr-box img {
            display: block;
            width: 240px;
            height: 240px;
        }
        .url {
            margin: 18px auto 24px;
            display: inline-block;
            font-family: ui-monospace, Menlo, Consolas, monospace;
            font-size: 13px;
            color: #1e4fa3;
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            padding: 7px 14px;
            border-radius: 8px;
            word-break: break-all;
        }
        .daftar {
            text-align: left;
            border-top: 1px dashed #cbd5e1;
            padding-top: 20px;
            margin: 0;
            list-style: none;
            display: grid;
            gap: 10px;
            font-size: 14px;
            color: #334155;
        }
        .daftar li {
            display: flex;
            gap: 10px;
            align-items: baseline;
        }
        .daftar strong {
            font-family: ui-monospace, Menlo, Consolas, monospace;
            color: #1e4fa3;
            font-size: 13px;
        }
        .catatan {
            margin-top: 22px;
            font-size: 11px;
            color: #64748b;
        }
        @media print {
            body { background: #ffffff; padding: 0; }
            .aksi { display: none; }
            .poster {
                max-width: none;
                box-shadow: none;
                border-width: 1.5px;
            }
        }
    </style>
</head>
<body>
    <div class="aksi">
        <a href="{{ url('/admin/sekolah') }}" class="btn btn-alt">&larr; Kembali ke Sekolah</a>
        <button type="button" class="btn" onclick="window.print()">Cetak / Simpan PDF</button>
    </div>

    <main class="poster">
        @if ($logoUrl)
            <img src="{{ $logoUrl }}" alt="{{ $sekolah->name }}" class="logo">
        @endif

        <p class="eyebrow">Portal Informasi Ujian CBT</p>
        <h1>{{ $sekolah->name }}</h1>
        <p class="sub">Pindai kode QR di bawah ini menggunakan kamera ponsel — tanpa perlu login.</p>

        <div class="qr-box">
            <img src="{{ $qrDataUri }}" alt="QR Portal Publik {{ $sekolah->name }}">
        </div>

        <div>
            <span class="url">{{ $portalUrl }}</span>
        </div>

        <ul class="daftar">
            <li><strong>01</strong><span>Jadwal ujian lengkap lintas hari &amp; pengumuman resmi panitia</span></li>
            <li><strong>02</strong><span>Cek ruang ujian &amp; nomor kursi menggunakan Kelas dan NIS</span></li>
            <li><strong>03</strong><span>Papan pengawas &amp; pantau aktivitas ujian secara langsung</span></li>
            <li><strong>04</strong><span>Pantau wali murid per kelas (menggunakan kode kelas)</span></li>
        </ul>

        <p class="catatan">
            Portal ini tidak menampilkan nilai, token, atau data personal siswa.
        </p>
    </main>
</body>
</html>

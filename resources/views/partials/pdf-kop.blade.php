@php
    $sekolahModel = $sekolahModel ?? \App\Domain\Tenancy\Support\IdentitasAplikasi::sekolah();

    $kopNama = $nama ?? (is_array($sekolah ?? null) ? ($sekolah['nama'] ?? null) : ($sekolah->name ?? null)) ?? $sekolahModel?->name ?? config('app.name');
    $kopNpsn = $npsn ?? (is_array($sekolah ?? null) ? ($sekolah['npsn'] ?? null) : ($sekolah->npsn ?? null)) ?? $sekolahModel?->npsn;
    $kopAlamat = $alamat ?? (is_array($sekolah ?? null) ? ($sekolah['alamat'] ?? null) : ($sekolah->address ?? null)) ?? $sekolahModel?->address;
    $kopTelepon = $telepon ?? (is_array($sekolah ?? null) ? ($sekolah['telepon'] ?? null) : ($sekolah->phone ?? null)) ?? $sekolahModel?->phone;
    $kopEmail = $email ?? (is_array($sekolah ?? null) ? ($sekolah['email'] ?? null) : ($sekolah->email ?? null)) ?? $sekolahModel?->email;

    $kopLogo = $logo ?? (is_array($sekolah ?? null) ? ($sekolah['logo'] ?? null) : null);
    if (empty($kopLogo) && ! empty($sekolahModel?->logo_path)) {
        $jalurLogo = storage_path('app/public/' . ltrim((string) $sekolahModel->logo_path, '/'));
        if (is_file($jalurLogo)) {
            $ext = strtolower(pathinfo($jalurLogo, PATHINFO_EXTENSION));
            $mime = match ($ext) {
                'png' => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                'webp' => 'image/webp',
                'svg' => 'image/svg+xml',
                default => 'image/png',
            };
            $isiLogo = @file_get_contents($jalurLogo);
            if ($isiLogo !== false) {
                $kopLogo = 'data:' . $mime . ';base64,' . base64_encode($isiLogo);
            }
        }
    }

    $kopKontak = collect([
        $kopNpsn ? 'NPSN ' . $kopNpsn : null,
        $kopTelepon ? 'Telp. ' . $kopTelepon : null,
        $kopEmail ?: null,
    ])->filter()->implode(' · ');
@endphp

<div class="pdf-kop-bar">
    <table class="pdf-kop-table">
        <tr>
            @if (! empty($kopLogo))
                <td style="width: 18mm; vertical-align: middle; padding-right: 3mm;">
                    <span class="pdf-logo-bingkai">
                        <img class="pdf-kop-logo-img" src="{{ $kopLogo }}" alt="">
                    </span>
                </td>
            @endif
            <td style="vertical-align: middle;">
                <div class="pdf-kop-sekolah">{{ $kopNama }}</div>
                @if (! empty($sub))
                    <div class="pdf-kop-sub">{{ $sub }}</div>
                @endif
                @if (! empty($kopAlamat))
                    <div class="pdf-kop-sub">{{ $kopAlamat }}</div>
                @endif
                @if ($kopKontak !== '')
                    <div class="pdf-kop-sub">{{ $kopKontak }}</div>
                @endif
            </td>
            <td style="vertical-align: middle; text-align: right; width: 46mm;">
                <span class="pdf-kop-lencana">{{ $lencana ?? 'Dokumen' }}</span>
                @if (! empty($kananSub))
                    <div class="pdf-kop-sub-kanan">{{ $kananSub }}</div>
                @endif
            </td>
        </tr>
    </table>
</div>

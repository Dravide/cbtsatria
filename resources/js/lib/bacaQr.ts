import jsQR from 'jsqr';

type BarcodeDetectorLike = {
    detect: (sumber: ImageBitmapSource) => Promise<{ rawValue: string }[]>;
};

export function buatDetectorBawaan(): BarcodeDetectorLike | null {
    if (typeof window === 'undefined' || !('BarcodeDetector' in window)) {
        return null;
    }

    try {
        const Ctor = (
            window as unknown as {
                BarcodeDetector: new (o: { formats: string[] }) => BarcodeDetectorLike;
            }
        ).BarcodeDetector;

        return new Ctor({ formats: ['qr_code'] });
    } catch {
        return null;
    }
}

export async function bacaQrDariVideo(
    video: HTMLVideoElement,
    kanvas: HTMLCanvasElement,
    detector: BarcodeDetectorLike | null,
): Promise<string | null> {
    if (video.readyState < 2 || video.videoWidth === 0 || video.videoHeight === 0) {
        return null;
    }

    if (detector !== null) {
        try {
            const hasil = await detector.detect(video);
            const isi = hasil[0]?.rawValue?.trim();
            if (isi) {
                return isi;
            }
        } catch {
            // Fallback ke jsQR bila detektor native gagal pada bingkai ini.
        }
    }

    const maksLebar = 640;
    const skala = Math.min(1, maksLebar / video.videoWidth);
    const w = Math.max(1, Math.round(video.videoWidth * skala));
    const h = Math.max(1, Math.round(video.videoHeight * skala));

    if (kanvas.width !== w || kanvas.height !== h) {
        kanvas.width = w;
        kanvas.height = h;
    }

    const ctx = kanvas.getContext('2d', { willReadFrequently: true });
    if (ctx === null) {
        return null;
    }

    ctx.drawImage(video, 0, 0, w, h);
    const img = ctx.getImageData(0, 0, w, h);
    const decoded = jsQR(img.data, w, h, { inversionAttempts: 'dontInvert' });

    return decoded?.data?.trim() || null;
}

export async function bacaQrDariBerkas(berkas: File): Promise<string | null> {
    const url = URL.createObjectURL(berkas);

    try {
        const gambar = await new Promise<HTMLImageElement>((resolve, reject) => {
            const img = new Image();
            img.onload = () => resolve(img);
            img.onerror = () => reject(new Error('Gagal memuat gambar'));
            img.src = url;
        });

        const maks = 1200;
        const skala = Math.min(1, maks / Math.max(gambar.naturalWidth, gambar.naturalHeight, 1));
        const w = Math.max(1, Math.round(gambar.naturalWidth * skala));
        const h = Math.max(1, Math.round(gambar.naturalHeight * skala));

        const kanvas = document.createElement('canvas');
        kanvas.width = w;
        kanvas.height = h;
        const ctx = kanvas.getContext('2d', { willReadFrequently: true });
        if (ctx === null) {
            return null;
        }

        ctx.drawImage(gambar, 0, 0, w, h);
        const imgData = ctx.getImageData(0, 0, w, h);
        const decoded = jsQR(imgData.data, w, h, { inversionAttempts: 'attemptBoth' });

        return decoded?.data?.trim() || null;
    } finally {
        URL.revokeObjectURL(url);
    }
}

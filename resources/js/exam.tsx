/*
| Entry kedua: permukaan ujian siswa.
|
| Dipisah dari app.tsx sejak Fase 1 karena halaman ujian memerlukan chrome
| minimal yang tidak boleh mewarisi sidebar dan bar atas konsol - dan
| memisahkannya belakangan berarti membongkar bundel yang sudah jalan.
| Isinya baru diisi pada Fase 7 (Mesin ujian siswa).
*/
import '../css/app.css';

import { ResolvedComponent, createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { MEREK } from './merek';

// Halaman ujian belum ada di Fase 1. Glob-nya tetap ditulis supaya
// penambahan berkas pertama di Fase 7 tidak menabrak galat tipe di sini.
const halaman = import.meta.glob<ResolvedComponent>('./Pages/Exam/**/*.tsx');

createInertiaApp({
    title: (title) => (title ? `${title} · ${MEREK}` : MEREK),

    resolve: (name) => resolvePageComponent(`./Pages/Exam/${name}.tsx`, halaman),

    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
});

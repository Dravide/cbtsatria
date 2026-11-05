import '../css/app.css';

import { ResolvedComponent, createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';

const appName = import.meta.env.VITE_APP_NAME ?? 'School CBT';

// Glob-nya dianotasi sebagai komponen, bukan sebagai modul. Tanpa itu
// resolvePageComponent menyimpulkan T dari bentuk modulnya dan resolver
// Inertia menolak hasilnya.
const halaman = import.meta.glob<ResolvedComponent>('./Pages/**/*.tsx');

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),

    resolve: (name) => resolvePageComponent(`./Pages/${name}.tsx`, halaman),

    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },

    progress: {
        color: '#1e4fa3',
    },
});

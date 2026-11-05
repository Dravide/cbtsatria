import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
    plugins: [
        laravel({
            // Dua entry, sesuai blueprint Fase 1: "dua entry layout (app dan ujian)".
            // app.tsx  -> konsol Admin/Guru (lebar)
            // exam.tsx -> permukaan ujian siswa (chrome minimal, phone-first)
            input: [
                'resources/css/app.css',
                'resources/js/app.tsx',
                'resources/js/exam.tsx',
            ],
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    server: {
        // Port eksplisit, jauh dari 5173 (dipakai bersama proyek lain di mesin
        // ini). strictPort membuat tabrakan gagal dengan jelas, bukan diam-diam
        // pindah port lalu HMR menunjuk ke tempat yang salah.
        port: 5179,
        strictPort: true,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});

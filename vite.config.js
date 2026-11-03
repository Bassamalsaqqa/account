import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import { readFileSync } from 'node:fs';

export default defineConfig({
    build: {
        sourcemap: false,
        emptyOutDir: true,
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
        {
            name: 'ibm-plex-license',
            apply: 'build',
            generateBundle() {
                const originalFileName = 'resources/fonts/ibm-plex-sans-arabic/LICENSE.txt';
                this.emitFile({
                    type: 'asset', name: 'IBM-Plex-LICENSE.txt', originalFileName,
                    source: readFileSync(new URL(`./${originalFileName}`, import.meta.url), 'utf8'),
                });
            },
        },
    ],
});

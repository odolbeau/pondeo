import { defineConfig } from 'vite';
import Symfony from '@symfony/reprise/vite';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    input: {
        app: './assets/app.js',
    },
    server: {
        // Bind to all interfaces (reachable from nginx's `node:5173` on the
        // Docker network). nginx reverse-proxies /build/ (see
        // .docker/nginx/default.conf) so the browser only ever talks to
        // https://pondeo.localhost — a self-signed cert only that origin is
        // trusted for would otherwise fail (or need its own browser
        // exception) on the dev server's own port, and a plain :5173 origin
        // would be cross-origin/mixed-content from the https page.
        host: true,
        origin: 'https://pondeo.localhost',
    },
    optimizeDeps: {
        // maplibre-gl spawns its own worker via a relative import.meta.url,
        // which esbuild's dependency pre-bundling breaks (the worker chunk
        // 404s at runtime) — see https://github.com/maplibre/maplibre-gl-js/issues/2202.
        exclude: ['maplibre-gl'],
    },
    plugins: [
        Symfony({
            stimulus: './assets/controllers.json',
        }),
        tailwindcss(),
    ],
});

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
        // .docker/nginx/default.conf) so the browser only ever talks to the
        // worktree's https origin, set by compose.yaml: the dev server's own
        // port isn't published and would be cross-origin/mixed-content.
        host: true,
        origin: process.env.PONDEO_ORIGIN,
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

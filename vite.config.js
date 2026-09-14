import { defineConfig } from 'vite';
import Symfony from '@symfony/reprise/vite';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    input: {
        app: './assets/app.js',
    },
    server: {
        // Bind to all interfaces (reachable from the host through the
        // `node` container's port mapping), but advertise the browser-facing
        // URL as localhost — otherwise Vite echoes back the bind address
        // (0.0.0.0) in the HMR client/asset URLs it injects into the page.
        host: true,
        origin: 'http://localhost:5173',
    },
    plugins: [
        Symfony({
            stimulus: './assets/controllers.json',
        }),
        tailwindcss(),
    ],
});

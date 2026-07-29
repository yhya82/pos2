import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
    ],
    server: {
        // Bind to all interfaces (not just loopback) so devices on the same
        // network — e.g. a phone hitting this PC's LAN IP — can reach the
        // dev server at all.
        host: '0.0.0.0',
        // Without this, the browser blocks the cross-origin <script
        // type="module"> requests Laravel's page (served from :8000) makes
        // to this dev server (:5173) — Vite's documented cors:true default
        // isn't taking effect once `host` is set explicitly, so it's spelled
        // out here instead of relied on.
        cors: true,
        hmr: {
            // Without this, Vite advertises its own bind address (e.g.
            // ::1/localhost) in the asset/HMR URLs it writes to
            // public/hot — which resolves to the requesting device itself,
            // not this PC. Point it at this machine's actual LAN IP instead.
            // If your PC's IP changes (new network, DHCP renewal), update
            // this to match `ipconfig`'s current IPv4 address.
            host: '192.168.100.6',
        },
    },
});

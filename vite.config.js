import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import dgram from 'dgram';

/**
 * Resolves to whatever this machine's current LAN-facing IPv4 address is —
 * computed fresh every time Vite starts rather than hardcoded, since a
 * hardcoded address goes stale (and silently breaks every asset/HMR
 * request with a connection timeout) the moment this PC switches networks
 * or gets a new DHCP lease.
 *
 * Deliberately not os.networkInterfaces()'s first non-internal entry: on a
 * machine with a hypervisor installed (VirtualBox, VMware, WSL, Hyper-V),
 * that's frequently a host-only virtual adapter, not the real network —
 * confirmed the hard way on this exact machine, where it returned
 * VirtualBox's 192.168.56.1 instead of the Wi-Fi adapter's real address.
 * Connecting a UDP socket (no packet actually sent — connect() on a
 * datagram socket just asks the OS to pick a route) reproduces the
 * decision the OS itself would make for real outbound traffic, which
 * correctly skips host-only adapters that aren't in the default route.
 */
function currentLanIp() {
    return new Promise((resolve) => {
        const socket = dgram.createSocket('udp4');

        socket.once('error', () => {
            socket.close();
            resolve('localhost');
        });

        socket.connect(80, '8.8.8.8', () => {
            const { address } = socket.address();
            socket.close();
            resolve(address);
        });
    });
}

export default defineConfig(async () => ({
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
            // not this PC. Point it at this machine's actual LAN IP,
            // recomputed on every Vite start so a network change just
            // needs a restart, not a manual edit here.
            host: await currentLanIp(),
        },
    },
}));

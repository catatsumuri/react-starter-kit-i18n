import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite-plus';

const file = (path: string) => fileURLToPath(new URL(path, import.meta.url));

// PHP-free preview of the real Laravel/Inertia pages with fixture props.
export default defineConfig({
    plugins: [react(), tailwindcss()],
    resolve: {
        alias: [
            {
                find: '@inertiajs/react',
                replacement: file('./mock/inertia.tsx'),
            },
            {
                find: '@erag/lang-sync-inertia/react',
                replacement: file('./mock/lang.ts'),
            },
            { find: '@/routes', replacement: file('./mock/routes.ts') },
            { find: /^@\//, replacement: file('./resources/js/') },
        ],
    },
    server: {
        host: '0.0.0.0',
        allowedHosts: ['.vercel.run'],
    },
});

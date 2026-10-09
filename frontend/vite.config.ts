import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [react(), tailwindcss()],
    server: {
        // Même adresse que FRONTEND_URL côté Laravel (liens de réinitialisation, retour SSO).
        host: '127.0.0.1',
        port: 5173,
        strictPort: true,
        proxy: {
            '/api': 'http://127.0.0.1:8001',
            '/sanctum': 'http://127.0.0.1:8001',
        },
    },
});

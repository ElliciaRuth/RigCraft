import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

// The app lives at http://127.0.0.1/RigCraft/ (Apache serves dist/, see ../.htaccess)
// and talks to the CodeIgniter API at /RigCraft/api.
export default defineConfig({
  base: '/RigCraft/',
  plugins: [vue()],
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  server: {
    port: 5173,
    // `npm run dev`: forward API calls to XAMPP's Apache so cookies stay same-origin.
    proxy: {
      '/RigCraft/api': { target: 'http://127.0.0.1', changeOrigin: true },
    },
  },
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    chunkSizeWarningLimit: 900,
  },
});

import path from 'node:path';
import { fileURLToPath } from 'node:url';
import react from '@vitejs/plugin-react';
import { defineConfig, loadEnv } from 'vite';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, projectRoot, '');
  const apiTarget = env.VITE_API_PROXY_TARGET || `http://localhost:${env.PORT || 4000}`;
  return {
    plugins: [react()],
    server: {
      port: 5173,
      // Same-origin /api in development, so the session cookie works without CORS.
      proxy: { '/api': { target: apiTarget, changeOrigin: false } },
    },
    preview: {
      port: 4173,
      proxy: { '/api': { target: apiTarget, changeOrigin: false } },
    },
    build: {
      outDir: 'dist',
      sourcemap: false,
      chunkSizeWarningLimit: 700,
    },
  };
});

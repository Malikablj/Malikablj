/// <reference types="vitest/config" />
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { viteSingleFile } from 'vite-plugin-singlefile';
import { fileURLToPath, URL } from 'node:url';

// `vite build --mode single` bundles everything into one self-contained index.html
// (handy for sharing a demo build); the default build keeps route-level code splitting.
// `--mode artifact` is the same single file tuned for sandboxed previews (see src/env.d.ts).
export default defineConfig(({ mode }) => ({
  plugins: [react(), tailwindcss(), ...(mode === 'single' || mode === 'artifact' ? [viteSingleFile()] : [])],
  define: {
    __SANDBOX__: JSON.stringify(mode === 'artifact'),
  },
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  build: {
    outDir: mode === 'single' ? 'dist-single' : mode === 'artifact' ? 'dist-artifact' : 'dist',
  },
  test: {
    environment: 'node',
    include: ['src/**/*.test.ts'],
  },
}));

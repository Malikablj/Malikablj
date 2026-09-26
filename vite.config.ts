/// <reference types="vitest/config" />
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { viteSingleFile } from 'vite-plugin-singlefile';
import { fileURLToPath, URL } from 'node:url';

// `vite build --mode single` bundles everything into one self-contained index.html
// (handy for sharing a demo build); the default build keeps route-level code splitting.
export default defineConfig(({ mode }) => ({
  plugins: [react(), tailwindcss(), ...(mode === 'single' ? [viteSingleFile()] : [])],
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  build: {
    outDir: mode === 'single' ? 'dist-single' : 'dist',
  },
  test: {
    environment: 'node',
    include: ['src/**/*.test.ts'],
  },
}));

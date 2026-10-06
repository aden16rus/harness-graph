import { defineConfig } from 'vite';
import { svelte } from '@sveltejs/vite-plugin-svelte';
import tailwindcss from '@tailwindcss/vite';

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [tailwindcss(), svelte()],
  server: {
    port: 3000,
    proxy: {
      '/ws': {
        target: 'ws://127.0.0.1:8080',
        ws: true,
      },
      '/api': {
        target: 'http://127.0.0.1:8080',
      },
    },
  },
  build: {
    outDir: 'dist',
    emptyOutDir: true,
  },
});

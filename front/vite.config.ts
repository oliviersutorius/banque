import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';

// En dev, Vite sert le front sur :5173 et relaie /api vers le serveur PHP sur :8000.
export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    proxy: {
      '/api': 'http://localhost:8000',
    },
  },
  test: {
    environment: 'node',
  },
});

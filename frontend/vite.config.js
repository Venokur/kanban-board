import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  server: {
    host: true,
    port: 5173,
    watch: {
      usePolling: true,
      interval: 200,
    },
    proxy: {
      '/api': {
        target: 'http://app:9501',
        changeOrigin: true,
      },
      '/ws': {
        target: 'ws://app:9501',
        ws: true,
      },
    },
  },
  build: {
    outDir: 'dist',
    target: 'es2020',
  },
})

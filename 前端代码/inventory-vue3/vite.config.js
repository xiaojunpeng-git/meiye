import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  base: process.env.VITE_ASSET_BASE || '/view_inventory_v3/',
  plugins: [vue()],
  resolve: {
    preserveSymlinks: true,
    dedupe: ['vue', '@lucide/vue'],
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) }
  },
  server: {
    proxy: {
      '/storeapi': {
        target: process.env.VITE_LOCAL_BACKEND_ORIGIN || 'http://127.0.0.1:8080',
        changeOrigin: true
      },
      '/adminapi': {
        target: process.env.VITE_LOCAL_BACKEND_ORIGIN || 'http://127.0.0.1:8080',
        changeOrigin: true
      }
    }
  },
  build: { outDir: 'dist', assetsDir: 'assets', sourcemap: false }
})

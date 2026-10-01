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
  // 盘点导出在用户点击时才动态引入 ExcelJS；开发服务必须在启动期间预构建，避免平台 iframe 首次访问时整个库存工作台空白。
  optimizeDeps: { include: ['exceljs'] },
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

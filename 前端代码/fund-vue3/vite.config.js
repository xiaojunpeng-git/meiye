import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

const storeApiTarget = process.env.FUND_V3_STORE_API_PROXY_TARGET || 'http://127.0.0.1:18092'
const platformApiTarget = process.env.FUND_V3_PLATFORM_API_PROXY_TARGET || 'http://127.0.0.1:18093'

export default defineConfig({
  base: process.env.VITE_ASSET_BASE || '/view_fund_v3/',
  plugins: [vue()],
  // 共享周期控件经本地包链接加载时，依赖仍由费用工程统一提供。
  resolve: { preserveSymlinks: true, dedupe: ['vue', '@lucide/vue'] },
  server: {
    proxy: {
      '/storeapi': { target: storeApiTarget, changeOrigin: false },
      '/adminapi': { target: platformApiTarget, changeOrigin: false }
    }
  },
  preview: {
    proxy: {
      '/storeapi': { target: storeApiTarget, changeOrigin: false },
      '/adminapi': { target: platformApiTarget, changeOrigin: false }
    }
  }
})

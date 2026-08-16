import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

const storeApiTarget = process.env.FUND_V3_STORE_API_PROXY_TARGET || 'http://127.0.0.1:18092'
const platformApiTarget = process.env.FUND_V3_PLATFORM_API_PROXY_TARGET || 'http://127.0.0.1:18093'

export default defineConfig({
  base: process.env.VITE_ASSET_BASE || '/view_fund_v3/',
  plugins: [vue()],
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

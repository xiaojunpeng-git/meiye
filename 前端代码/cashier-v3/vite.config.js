import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

const cashierApiTarget = process.env.CASHIER_V3_API_PROXY_TARGET || 'http://127.0.0.1:8080'
const cashierSourceRoot = fileURLToPath(new URL('.', import.meta.url))
const inventoryPackageRoot = fileURLToPath(new URL('../inventory-vue3', import.meta.url))
const unifiedQueryPackageRoot = fileURLToPath(new URL('../shared/unified-query-vue3', import.meta.url))
const cashierApiProxy = {
  '/cashierapi': {
    target: cashierApiTarget,
    changeOrigin: false
  },
  '/storeapi': {
    target: cashierApiTarget,
    changeOrigin: false
  }
}

export default defineConfig({
  base: process.env.VITE_ASSET_BASE || '/view_cashier_v3/',
  plugins: [vue()],
  resolve: {
    preserveSymlinks: true,
    dedupe: ['vue', '@lucide/vue'],
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url))
    }
  },
  optimizeDeps: {
    exclude: ['@mohe/inventory-vue3', '@mohe/unified-query-vue3']
  },
  // 本地热更新页面仍复用 8080 的同源门店会话与收银 Gateway，避免开发页
  // 因请求落到 Vite 自身而退化为无权限的空壳页面。
  server: {
    fs: {
      allow: [cashierSourceRoot, inventoryPackageRoot, unifiedQueryPackageRoot]
    },
    proxy: cashierApiProxy
  },
  // 独立验收候选包也必须走同一 Gateway；否则带哈希的静态页面只能打开、
  // 不能登录，容易被误判为业务权限失败。该代理仅用于本地 vite preview。
  preview: {
    proxy: cashierApiProxy
  },
  build: {
    outDir: 'dist',
    assetsDir: 'assets',
    sourcemap: false
  }
})

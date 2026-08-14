import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// 收银 V3 热更新使用自己的本地后端入口；8080 是集成构建/平台入口。
const cashierApiTarget = process.env.CASHIER_V3_API_PROXY_TARGET || 'http://127.0.0.1:18092'
const cashierSourceRoot = fileURLToPath(new URL('.', import.meta.url))
const inventoryPackageRoot = fileURLToPath(new URL('../inventory-vue3', import.meta.url))
const unifiedQueryPackageRoot = fileURLToPath(new URL('../shared/unified-query-vue3', import.meta.url))
const cashierApiProxy = {
  '/cashierapi': {
    target: cashierApiTarget,
    changeOrigin: false
  },
  // 库存 V3 作为收银工作台的共享模块直接挂载时，请求仍以
  // /storeapi 开头；必须和收银 Gateway 使用同一个独立后端实例。
  '/storeapi': {
    target: cashierApiTarget,
    changeOrigin: false
  },
  // 库存模块在集团模式下可能访问 adminapi，也固定走收银专用后端。
  '/adminapi': {
    target: cashierApiTarget,
    changeOrigin: false
  },
  // 员工默认头像等公共资源由收银专用后端提供，避免 18091 Vite 直接 404。
  '/static': {
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
  // 本地热更新页面使用 18092 的收银专用后端，避免请求落到 8080 或
  // Vite 自身而退化为旧路由/404。
  server: {
    fs: {
      allow: [cashierSourceRoot, inventoryPackageRoot, unifiedQueryPackageRoot]
    },
    proxy: cashierApiProxy
  },
  // 独立验收候选包也必须走收银专用 Gateway；该代理仅用于本地 vite preview。
  preview: {
    proxy: cashierApiProxy
  },
  build: {
    outDir: 'dist',
    assetsDir: 'assets',
    sourcemap: false
  }
})

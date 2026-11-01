import { createApp } from 'vue'
import { createMemoryHistory, createRouter, RouterView } from 'vue-router'
import BasicReportsView from '@/views/BasicReportsView.vue'
import { useCashierV3State } from '@/services/cashierV3Bridge'
import '@/styles/base.css'

// 仅用于独立浏览器组件测试，不进入正式路由或主入口；所有接口由测试上下文拦截。
if (!import.meta.env.DEV) throw new Error('R51 fixture is development-only')
const platform = new URLSearchParams(window.location.search).get('mode') !== 'store'
useCashierV3State().currentStore = { id: 901, name: '测试一店' }
const router = createRouter({ history: createMemoryHistory(), routes: [
  { path: '/basic/:report?', name: 'r51-fixture', component: BasicReportsView, meta: { platformReport: platform } }
] })
await router.push('/basic/basic_brush_registration')
const app = createApp(RouterView)
app.use(router)
await router.isReady()
app.mount('#app')

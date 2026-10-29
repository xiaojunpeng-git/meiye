import { createApp } from 'vue'
import { createMemoryHistory, createRouter, RouterView } from 'vue-router'
import BasicReportsView from '@/views/BasicReportsView.vue'
import { useCashierV3State } from '@/services/cashierV3Bridge'
import '@/styles/base.css'

// 只供自动化挂载真实报表组件，接口由本地测试注入脱敏原单证据；正式入口不引用本文件。
if (!import.meta.env.DEV) throw new Error('R11 fixture is development-only')
const params = new URLSearchParams(location.search)
const platform = params.get('mode') !== 'store'
useCashierV3State().currentStore = { id: Number(params.get('storeId')), name: '对账测试门店' }
const router = createRouter({ history: createMemoryHistory(), routes: [
  { path: '/basic/:report?', name: 'r11-fixture', component: BasicReportsView, meta: { platformReport: platform } }
] })
await router.push('/basic/basic_store_reconciliation')
const app = createApp(RouterView)
app.use(router)
await router.isReady()
app.mount('#app')

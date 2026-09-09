import { createApp } from 'vue'
import App from './App.vue'
import router, { ensureCashierV3RouterBootstrap } from './router'
import './styles/base.css'
import { installCashierV3HttpAdapter } from './services/cashierV3HttpAdapter'

const isPlatformOrderCenter = String(window.location.hash || '').startsWith('#/platform/order-center')
installCashierV3HttpAdapter(isPlatformOrderCenter ? { mode: 'platform-order-center' } : {})
if (!isPlatformOrderCenter && !String(window.location.hash || '').startsWith('#/login')) {
  ensureCashierV3RouterBootstrap({ reason: 'app-boot', silent: false })
}
createApp(App).use(router).mount('#app')

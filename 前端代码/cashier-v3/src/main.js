import { createApp } from 'vue'
import App from './App.vue'
import router, { ensureCashierV3RouterBootstrap } from './router'
import './styles/base.css'
import { installCashierV3HttpAdapter } from './services/cashierV3HttpAdapter'

const isPlatformOrderCenter = String(window.location.hash || '').startsWith('#/platform/order-center')
const isPlatformStaffRoster = String(window.location.hash || '').startsWith('#/platform/staff-roster')
const isPlatformMemberCenter = String(window.location.hash || '').startsWith('#/platform/member-list')
// 平台会员页拥有独立的管理员适配器，不能顺带启动/读取门店工作台会话。
if (!isPlatformMemberCenter && !isPlatformStaffRoster) installCashierV3HttpAdapter(isPlatformOrderCenter ? { mode: 'platform-order-center' } : {})
if (!isPlatformMemberCenter && !isPlatformStaffRoster && !isPlatformOrderCenter && !String(window.location.hash || '').startsWith('#/login')) {
  ensureCashierV3RouterBootstrap({ reason: 'app-boot', silent: false })
}
createApp(App).use(router).mount('#app')

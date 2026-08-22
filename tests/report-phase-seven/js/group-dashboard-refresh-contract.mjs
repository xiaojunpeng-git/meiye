import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const source = fs.readFileSync(path.join(root, '前端代码/cashier-v3/src/views/GroupManagementDashboardView.vue'), 'utf8')

const assert = (condition, message) => {
  if (!condition) {
    console.error(`FAIL: ${message}`)
    process.exit(1)
  }
}

assert(source.includes('const DASHBOARD_REFRESH_INTERVAL_MS = 15 * 60 * 1000'), 'dashboard refresh interval is fixed at 15 minutes')
assert(source.includes('window.setInterval(refreshDashboardOnSchedule, DASHBOARD_REFRESH_INTERVAL_MS)'), 'dashboard schedules a periodic refresh')
assert(source.includes('window.clearInterval(dashboardRefreshTimer)'), 'dashboard clears the refresh timer on unmount')
assert(source.includes('if (!loading.value) void loadDashboard()'), 'scheduled refresh does not overlap an active request')
assert(source.includes('queryGroupManagementDashboardScope'), 'group dashboard reads the unified permission scope')
assert(source.includes('<OrganizationStoreScopePicker'), 'permission scope reuses the unified organization/store picker')
assert(source.includes('v-model="scopePicker.selectedStoreIds"'), 'permission scope binds selected stores to the unified picker')
assert(source.includes('store_ids: scopePicker.value.selectedStoreIds.join'), 'selected stores are passed back to the dashboard query')
assert(source.includes('function changeScope({ storeIds, label })'), 'permission picker changes reload the dashboard within scope')
assert(source.includes('--metric-consumption:#b7791f'), 'consumption trend uses the consumption card color')

console.log('group dashboard refresh contract: PASS')

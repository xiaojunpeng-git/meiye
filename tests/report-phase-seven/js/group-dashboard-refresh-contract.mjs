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

console.log('group dashboard refresh contract: PASS')

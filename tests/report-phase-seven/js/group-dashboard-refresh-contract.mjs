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
assert(!source.includes('>目标管理</button>') && !source.includes('>管理目标</button>'), 'dashboard does not expose target management actions')
assert(source.includes('dashboard-drilldown__conditions') && source.includes('dashboard-drilldown__table'), 'drilldown separates filters from a labeled detail table')
assert(source.includes('repeat(auto-fit,minmax(min(100%,250px),1fr))'), 'category performance cards auto-fit up to six per row')
// Parent cards use actual performance; fixed sale types may remain meaningful
// when refunds net the parent amount to zero. Empty types alone do not show it.
assert(source.includes('Number(category?.amount_cents) !== 0 || hasRanking || hasSaleType'), 'category performance hides cards without valid data')
assert(source.includes('category.sale_types') && !source.includes('secondary_categories'), 'fixed sale types replace secondary category rendering')
assert(source.indexOf('{{ type.title }}') < source.indexOf('Number(type.sales_quantity || 0)')
  && source.indexOf('Number(type.sales_quantity || 0)') < source.indexOf('formatMoney(type.cash_performance_cents)'), 'sale types show name then quantity then cash amount')
assert(source.includes('grid-template-columns:repeat(3,minmax(0,1fr))'), 'each category keeps three stable horizontal tracks')
assert(source.includes('.group-dashboard__sale-type-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr))'), 'top sale types use three equal desktop tracks')
assert(!source.includes('categories.value.sort(') && !source.includes('categoryCards.value.sort('), 'category cards preserve the backend configured order')

console.log('group dashboard refresh contract: PASS')

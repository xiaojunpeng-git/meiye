import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const app = fs.readFileSync(path.join(root, '前端代码/inventory-vue3/src/App.vue'), 'utf8')
const toolbar = fs.readFileSync(path.join(root, '前端代码/inventory-vue3/src/components/InventoryOperationalUnifiedQueryToolbar.vue'), 'utf8')
let failed = 0

function check(name, condition) {
  console.log(`${condition ? 'PASS' : 'FAIL'} ${name}`)
  if (!condition) failed += 1
}

check('dashboard renders every server-owned expiry bucket instead of one placeholder sentence',
  app.includes('dashboardExpiryBuckets')
    && app.includes('v-for="bucket in dashboardExpiryBuckets"')
    && app.includes('expiryRiskPercent(bucket)')
    && !app.includes('90 天内临期批次：'))
check('risk bucket clicks enter the expiry page with a structured server filter',
  ['OVERDUE', 'WITHIN_30', 'DAYS_31_60', 'DAYS_61_90', 'SAFE_OVER_90', 'UNKNOWN'].every((code) => app.includes(code))
    && app.includes("fieldKey = 'remaining_shelf_life_days'")
    && app.includes("filterRelation: 'all'")
    && app.includes("operator: 'is_null'")
    && app.includes("selectPage('statistics', { tab: 'expiry'"))
check('store statistics toolbar applies the selected dashboard filter on first load',
  app.includes(':initial-query="statisticsInitialQuery"')
    && toolbar.includes('initialQuery: { type: Object')
    && toolbar.includes("emit('query', { ...toolbar.value?.querySnapshot(), ...props.initialQuery })"))

console.log(`INVENTORY_EXPIRY_RISK_FRONTEND_RESULT failed=${failed}`)
process.exit(failed === 0 ? 0 : 1)

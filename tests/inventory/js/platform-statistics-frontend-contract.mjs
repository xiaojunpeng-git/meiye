import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const here = path.dirname(fileURLToPath(import.meta.url))
const root = path.resolve(here, '../../..')
const appPath = path.join(root, '前端代码/inventory-vue3/src/App.vue')
const source = fs.readFileSync(appPath, 'utf8')

let passed = 0
let failed = 0
function check(name, condition) {
  if (condition) {
    passed += 1
    console.log(`PASS ${name}`)
    return
  }
  failed += 1
  console.log(`FAIL ${name}`)
}

const statisticsStart = source.indexOf('async function queryStatisticsPage(query)')
const statisticsEnd = source.indexOf('\nasync function loadDashboard()', statisticsStart)
const statisticsSource = source.slice(statisticsStart, statisticsEnd)

check('statistics select the platform authority for platform inventory',
  statisticsSource.includes("const client = mode.value === 'platform' ? platformInventoryApi : inventoryApi")
    && statisticsSource.includes('await client.unifiedOperationalQuery('))
check('platform statistics explicitly request the headquarters subject and selected headquarters location',
  statisticsSource.includes("subject: 'HQ'")
    && statisticsSource.includes("hq_location_id: Number(platformHqLocationId.value)"))
check('expiry results render the authoritative batch balance without browser aggregation',
  source.includes("['batch_balance_quantity', '当前剩余库存', 'decimal']")
    && statisticsSource.includes('batchAnalysis.value = { list: records')
    && source.includes('text(row.batch_balance_quantity)')
    && !statisticsSource.includes('reduce(')
    && !statisticsSource.includes('Number(row.batch_balance_quantity)'))
check('expiry table presents current remaining stock as its own column',
  source.includes('<th v-if="activeStatisticsTab === \'expiry\'">当前剩余库存</th>')
    && source.includes(":colspan=\"activeStatisticsTab === 'expiry' ? 8 : 7\""))

console.log(`INVENTORY_PLATFORM_STATISTICS_FRONTEND_RESULT passed=${passed} failed=${failed}`)
process.exit(failed === 0 ? 0 : 1)

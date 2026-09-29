import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const app = readFileSync(new URL('../../../前端代码/inventory-vue3/src/App.vue', import.meta.url), 'utf8')
const styles = readFileSync(new URL('../../../前端代码/inventory-vue3/src/styles.css', import.meta.url), 'utf8')
const claim = readFileSync(new URL('../../../前端代码/cashier-v3/src/views/PresaleClaimView.vue', import.meta.url), 'utf8')

// 有业务日期的库存列表统一复用周期控件，并在首次进入时按当天筛选。
for (const page of ['request', 'transfer', 'inbound', 'outbound', 'usage', 'import']) {
  assert.match(app, new RegExp(`const businessDatePages = new Set\\([^\\n]*'${page}'`))
}
assert.match(app, /const businessDateRange = ref\(todayRange\(\)\)/)
assert.match(app, /if \(usesBusinessDatePeriod\(key\)\) businessDateRange\.value = todayRange\(\)/)
assert.match(app, /UnifiedQueryDateRange :model-value="businessDateRange"/)
assert.doesNotMatch(app, /v-model="businessDate(?:From|To)" type="date"/)
assert.match(app, /field: 'business_date', operator: 'between', value: \[businessPeriod\.min, businessPeriod\.max\]/)
assert.match(app, /query\.business_date_from = businessPeriod\.min/)
assert.match(app, /query\.start_time = `\$\{businessPeriod\.min\} 00:00:00`/)

// 盘点按完成日期，入出库统计按业务日期；快照类统计不应被周期误筛。
assert.match(app, /const countDateRange = ref\(todayRange\(\)\)/)
assert.match(app, /field: 'count_date', operator: 'between'/)
assert.match(app, /const statisticsDateRange = ref\(todayRange\(\)\)/)
assert.match(app, /\['inbound', 'outbound'\]\.includes\(activeStatisticsTab\)/)
assert.match(app, /isMovement && period\.min && period\.max/)
assert.match(styles, /\.inventory-period-filter, \.count-period-filter/)

// 客户领用只在查看非可领用状态时按销售日筛选，不隐藏历史可领用余额。
assert.match(claim, /const salesDateRange = ref\(\{ min: today\(\), max: today\(\) \}\)/)
assert.match(claim, /shouldShowSalesDateFilter\.value \? salesDateRange\.value\.min : ''/)
assert.match(claim, /UnifiedQueryDateRange :model-value="salesDateRange"/)
assert.doesNotMatch(claim, /v-model="sales(?:Start|End)Date" type="date"/)
console.log('INVENTORY_PERIOD_CONTROLS_CONTRACT_OK')

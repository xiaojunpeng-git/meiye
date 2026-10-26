import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const view = readFileSync('前端代码/cashier-v3/src/views/StoreBusinessReportView.vue', 'utf8')
const picker = readFileSync('前端代码/shared/unified-query-vue3/src/components/UnifiedQueryDateRange.vue', 'utf8')
const vite = readFileSync('前端代码/cashier-v3/vite.config.js', 'utf8')

// 门店报表只替换筛选呈现；后端查询、路由回显和导出仍共用 reportParams。
assert.match(view, /v-if="!isPlatformReport" class="store-business-report__period-picker"/)
assert.match(view, /<UnifiedQueryDateRange :model-value="reportDateRange" :mode="reportPeriodMode"/)
assert.match(view, /:min-date="isFirstPhaseReport \? LEGACY_COVERAGE_START : ''" :allow-clear="false"/)
assert.match(view, /const reportPeriodMode = computed\(\(\) => !isPlatformRuntimeRoute\(\) \? 'range' : usesAnnualYearFilter\.value \? 'year' : usesNaturalMonthFilter\.value \? 'month' : 'range'\)/)
assert.match(view, /function applyReportDateRange\(\{ min, max \}\)/)
assert.match(view, /const params = reportParams\(\{ page: undefined, limit: undefined \}\)/)
assert.match(view, /exportStoreBusinessReport\(reportParams\(\{ page: 1, limit: 100 \}\)/)
assert.match(picker, /if \(props\.mode === 'month' \|\| props\.mode === 'year'\)/)
assert.match(picker, /\.uq-period-calendars \{ grid-template-columns:minmax\(0,1fr\); \}/)
assert.match(picker, /\.uq-period-month:nth-child\(2\) \{ display:none; \}/)
assert.match(vite, /watch: \{ usePolling: true, interval: 500 \}/)

console.log('PASS store report period picker preserves query contract and responsive layout')

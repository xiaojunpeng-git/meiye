import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const view = read('前端代码/cashier-v3/src/views/StoreBusinessReportView.vue')
const admin = read('后端代码/app/controller/admin/v1/report/UnifiedReport.php')
const cashier = read('后端代码/app/controller/cashier/v3/Report.php')
const store = read('后端代码/app/controller/store/report/UnifiedReport.php')
const service = read('后端代码/app/services/report/StoreUnifiedReportServices.php')

// 平台与门店共享固定列契约；平台权限范围仍走原控件，旧筛选仅保留内部参数用途。
assert.match(view, /const isSharedStoreReport = computed\(\(\) => STORE_REPORT_TABS\.some/)
assert.match(view, /const resultFilterColumns = computed\(\(\) => isSharedStoreReport\.value\s*\? columns\.value\.filter\(\(column\) => \['month', 'text'\]/)
assert.match(view, /function encodedResultFilters\(\) \{\s*if \(!isSharedStoreReport\.value\) return ''/)
assert.match(view, /v-for="field in isPlatformReport && !isSharedStoreReport \? filterSchema : \[\]"/)
assert.match(view, /v-if="resultFilterColumns\.length && isAdvancedFiltersOpen"/)
assert.match(view, /row_filters: encodedResultFilters\(\)/)
assert.match(view, /exportStoreBusinessReport\(reportParams\(/)
assert.match(view, /selectedStoreIds\.join\(','\)/)
assert.match(admin, /\['row_filters', ''\]/)
assert.match(cashier, /\['row_filters', ''\]/)
assert.match(store, /\['row_filters',''\]/)
assert.match(service, /StoreReportResultRowFilterServices::filter\(/)
console.log('PASS R86 platform/store shared fixed-field filters, scope and export contract')

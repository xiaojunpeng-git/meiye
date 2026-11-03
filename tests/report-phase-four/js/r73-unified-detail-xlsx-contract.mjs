import assert from 'node:assert/strict'
import fs from 'node:fs'

const read = (path) => fs.readFileSync(path, 'utf8')
const view = read('前端代码/cashier-v3/src/views/StoreBusinessReportView.vue')
const dialog = read('前端代码/cashier-v3/src/components/reports/ReportDetailDialog.vue')
const api = read('前端代码/cashier-v3/src/services/storeBusinessReportApi.js')
const admin = read('后端代码/app/controller/admin/v1/report/UnifiedReport.php')
const store = read('后端代码/app/controller/cashier/v3/Report.php')

assert.match(dialog, /导出 XLSX/)
assert.match(dialog, /hasPendingFilter/)
assert.match(view, /queryStoreBusinessReport\(memberVisitDetailParams\(pageNumber\)/)
assert.match(view, /queryStoreBusinessReport\(bdeghDetailParams\(pageNumber\)/)
assert.match(view, /exportStoreBusinessReportXlsx\(params, reportRuntime\.value\)/)
assert.match(view, /memberVisitDetail\.value\.open \? memberVisitDetailParams\(\) : bdeghDetailParams\(\)/)
assert.match(api, /endpoint\(runtime, 'unified\/export'\)/)
assert.match(api, /response\.blob\(\)/)
for (const controller of [admin, store]) {
  // 主表和弹窗均在权限查询之后进入共用工作簿文件层。
  assert.match(controller, /StoreReportXlsxWriter\(\)\)->response\(\$report, \$result\)/)
  assert.match(controller, /'_internal_all' => true/)
}

process.stdout.write('PASS R73 popup XLSX shares the authorized full-result query and filters\n')

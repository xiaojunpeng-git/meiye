import assert from 'node:assert/strict'
import fs from 'node:fs'
import vm from 'node:vm'

// Verify real DOM attachment, cleanup and rejection of JSON/empty blobs without making browser or network calls.
const source = fs.readFileSync('前端代码/cashier-v3/src/services/reportWorkbookDownload.js', 'utf8').replaceAll('export function', 'function')
const calls = []
class TestBlob { constructor(type, size = 5) { this.type = type; this.size = size } }
const context = { Blob: TestBlob, URL: { createObjectURL: () => { calls.push('url'); return 'blob:test' }, revokeObjectURL: () => calls.push('revoke') },
  document: { body: { appendChild: () => calls.push('append') }, createElement: () => ({ click: () => calls.push('click'), remove: () => calls.push('remove') }) },
  setTimeout: fn => fn() }
vm.createContext(context);vm.runInContext(source, context)
for (const blob of [new TestBlob('application/json'), new TestBlob('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 0)]) {
  assert.throws(() => context.saveReportWorkbook(blob, '报表.xlsx'))
}
assert.equal(calls.length, 0)
assert.equal(context.reportWorkbookFilename("attachment; filename=\"report.xlsx\"; filename*=UTF-8''%E5%AE%A2%E6%88%B7.xlsx"), '客户.xlsx')
assert.equal(context.reportWorkbookFilename('attachment; filename="35%报表.xlsx"'), '35%报表.xlsx')
assert.equal(context.reportWorkbookFilename("attachment; filename*=UTF-8''%broken", '默认.xlsx'), '默认.xlsx')

context.saveReportWorkbook(new TestBlob('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'), '报表.xlsx')
assert.deepEqual(calls, ['url', 'append', 'click', 'remove', 'revoke'])
const main = fs.readFileSync('前端代码/cashier-v3/src/views/StoreBusinessReportView.vue', 'utf8')
assert.doesNotMatch(main, /text\/csv|\.csv|function csvValue/)
assert.match(main, /exportStoreBusinessReportXlsx\(reportParams/)
const customer = fs.readFileSync('前端代码/cashier-v3/src/views/CustomerAnalyticsView.vue', 'utf8')
assert.match(customer, /Object\.freeze\(\{ \.\.\.submittedQuery \}\)/)
assert.match(customer, /exportCustomerAnalytics\(\{ \.\.\.executedExportQuery\.value \}\)/)
assert.doesNotMatch(customer, /class="link-button">导出/)
console.log('PASS shared XLSX browser download, cleanup, format rejection and committed query binding')

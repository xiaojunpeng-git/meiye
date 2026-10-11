import { createServer } from 'node:http'
import { readFileSync } from 'node:fs'

// Browser-only print regression fixture: reuse the canonical renderer and app CSS,
// but intercept window.print so verification can never enqueue a physical receipt.
const source = new URL('../../../前端代码/cashier-v3/src/services/salesOrderReceiptPrint.js', import.meta.url)
const appCss = new URL('../../../前端代码/cashier-v3/src/styles/base.css', import.meta.url)
const page = `<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="/app.css"></head>
<body><div id="app">Receipt pagination test only</div><script type="module">
import { mountSalesOrderReceiptPreview } from '/receipt.js'
const mode = new URL(location.href).searchParams.get('mode') || 'mixed'
const count = mode === 'long' ? 30 : 1
const order = {
  salesOrderNo: 'TEST-PAGINATION-' + mode,
  storeName: '打印分页测试门店', memberName: '测试顾客', businessDate: '2026-10-11', cashierName: '测试收银员',
  storeAddress: '江苏省扬州市测试路二楼',
  receiptMode: mode === 'entitlement' ? 'service' : 'sales',
  items: mode === 'entitlement' ? [] : [...Array.from({length: count}, (_, i) => ({name: '购买商品-' + (i + 1), quantity: 1, payableAmount: 0})),
    {name: '权益项目（手工）', businessTag: '权益', quantity: 1, remainingTimes: 30}],
  serviceRecords: mode === 'entitlement' ? [{serviceProject: '纯权益服务项目', usedTimes: 1}] : [],
  paymentDetails: mode === 'entitlement' ? [] : [{methodName: '其他收款', amount: 0}],
  amountSummary: mode === 'entitlement' ? {} : {payableAmount: 0, actualReceivedAmount: 0}
}
const host = {
  document, DOMParser,
  addEventListener: window.addEventListener.bind(window),
  removeEventListener: window.removeEventListener.bind(window),
  // Keep the isolated screen layout until CDP's PDF print or the fixture close button.
  print() { document.documentElement.dataset.printPrepared = 'true' },
  setTimeout() { return null }, clearTimeout() {}
}
mountSalesOrderReceiptPreview(order, host, {paperSize: '76'})
</script></body></html>`

const server = createServer((request, response) => {
  const path = new URL(request.url, 'http://127.0.0.1').pathname
  const asset = path === '/receipt.js' ? source : path === '/app.css' ? appCss : null
  response.setHeader('Content-Type', asset ? path.endsWith('.js') ? 'text/javascript; charset=utf-8' : 'text/css; charset=utf-8' : 'text/html; charset=utf-8')
  response.end(asset ? readFileSync(asset) : page)
})
server.listen(0, '127.0.0.1', () => process.stdout.write('http://127.0.0.1:' + server.address().port + '/?mode=mixed\n'))

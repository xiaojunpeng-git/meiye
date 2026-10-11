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
// Real order-center pure entitlement receipts still use sales mode, including
// empty payment/summary sections. Service-only receipts are not a valid control
// for the mixed-order printing regression reported against FW101000974.
const pure = mode === 'pure' || mode === 'long-pure'
const order = {
  salesOrderNo: pure ? 'FW101000974' : 'XS26101100163',
  storeName: '扬州欧尚店', memberName: pure ? '孙玲G14' : '点点', businessDate: pure ? '2026-10-10' : '2026-10-11', cashierName: '孟自文',
  storeAddress: '江苏省扬州市邗江区江阳中路欧尚超市二楼',
  receiptMode: mode === 'entitlement' ? 'service' : 'sales',
  items: pure ? Array.from({length: mode === 'long-pure' ? 30 : 4}, (_, i) => ({name: i === 3 ? '面(收店)' : '舒缓灸', businessTag: '权益', quantity: 1, remainingTimes: i === 3 ? 7 : 23}))
    : mode === 'entitlement' ? [] : [...Array.from({length: count}, (_, i) => ({name: i === 0 ? '幸福霜' : '购买商品-' + (i + 1), quantity: 1, payableAmount: 0})),
    {name: '贵族名媛(手工)', businessTag: '权益', quantity: 1, remainingTimes: 30}],
  serviceRecords: mode === 'entitlement' ? [{serviceProject: '纯权益服务项目', usedTimes: 1}] : [],
  paymentDetails: pure || mode === 'entitlement' ? [] : [{methodName: '其他收款', amount: 0}],
  amountSummary: pure || mode === 'entitlement' ? {} : {payableAmount: 0, actualReceivedAmount: 0}
}
// Reproduce the later paid mixed-order report from its visible snapshot only;
// no API, member lookup or business write is performed by this fixture.
if (mode === 'paid-mixed') Object.assign(order, {
  salesOrderNo: 'XS26101100012', storeName: '合肥世纪中心店', memberName: '张体', cashierName: '鲍兰芳',
  storeAddress: '福建省厦门市思明区梧村街道湖滨南路310号之8B厦门市思明区邻邻柒の头部SPA(万象城店)',
  items: [{name: '3333招牌卡', quantity: 1, payableAmount: 3333},
    {name: '背部spa', businessTag: '权益', quantity: 1, remainingTimes: 30}],
  paymentDetails: [{methodName: '现金', amount: 3333}],
  amountSummary: {payableAmount: 3333, actualReceivedAmount: 3333}
})
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

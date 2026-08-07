import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import {
  buildSalesOrderReceiptHtml,
  openSalesOrderReceiptPrint,
  salesOrderReceiptFromCheckout
} from '../../../前端代码/cashier-v3/src/services/salesOrderReceiptPrint.js'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const orderOverlay = readFileSync(path.join(root, '前端代码/cashier-v3/src/components/order/SalesOrderDetailOverlay.vue'), 'utf8')
const checkoutOverlay = readFileSync(path.join(root, '前端代码/cashier-v3/src/components/cashier/CashierCheckoutOverlay.vue'), 'utf8')
const printButton = readFileSync(path.join(root, '前端代码/cashier-v3/src/components/order/SalesOrderReceiptPrintButton.vue'), 'utf8')

let passed = 0
function ok(label, callback) {
  callback()
  passed += 1
  process.stdout.write(`[PASS] ${label}\n`)
}

ok('小票只展示订单冻结快照且转义动态文本', () => {
  const html = buildSalesOrderReceiptHtml({
    salesOrderNo: 'SO-1001',
    storeName: '<门店>',
    isGuest: true,
    orderNote: '<script>alert(1)</script>',
    items: [{ name: '<项目>', quantity: 1, payableAmount: 99 }],
    paymentDetails: [{ methodName: '微信', amount: 99 }],
    amountSummary: { payableAmount: 99, actualReceivedAmount: 99 }
  })
  assert.match(html, /SO-1001/)
  assert.match(html, /&lt;门店&gt;/)
  assert.match(html, /&lt;script&gt;alert\(1\)&lt;\/script&gt;/)
  assert.doesNotMatch(html, /<script>alert\(1\)<\/script>/)
  assert.match(html, /游客/)
})

ok('结账成功页只把已存在的结账快照适配为小票数据', () => {
  const receipt = salesOrderReceiptFromCheckout({
    salesOrderNo: 'SO-1002',
    member: { name: '叶萍' },
    orderLines: [{ name: '护理项目', quantity: 1, payableAmount: 128 }],
    summary: { receivableAmount: 128, discountAmount: 0 },
    payment: { resultLines: [{ name: '微信', amount: 128 }] }
  })
  assert.equal(receipt.salesOrderNo, 'SO-1002')
  assert.equal(receipt.items[0].name, '护理项目')
  assert.equal(receipt.paymentDetails[0].name, '微信')
})

ok('用户点击后在新窗口展示小票预览，并由预览页明确触发打印', () => {
  let writtenHtml = ''
  const popup = {
    closed: false,
    document: {
      open() {},
      write(value) { writtenHtml = value },
      close() {}
    },
    focus() {}
  }
  const result = openSalesOrderReceiptPrint({ salesOrderNo: 'SO-1003' }, {
    open() { return popup }
  })
  assert.equal(result.ok, true)
  assert.match(writtenHtml, /SO-1003/)
  assert.match(writtenHtml, /小票预览操作/)
  assert.match(writtenHtml, /onclick="window\.print\(\)"/)
  assert.match(writtenHtml, /onclick="window\.close\(\)"/)
})

ok('订单中心打印不再发送后端打印动作', () => {
  assert.match(orderOverlay, /SalesOrderReceiptPrintButton/)
  assert.match(printButton, /openSalesOrderReceiptPrint/)
  assert.match(printButton, /@click="printReceipt"/)
  assert.doesNotMatch(orderOverlay, /runAction\('print-sales-order-receipt'\)/)
})

ok('结账成功页提供本地打印且不经收银命令链', () => {
  assert.match(checkoutOverlay, /salesOrderReceiptFromCheckout/)
  assert.match(checkoutOverlay, /v-if="canPrintReceipt"/)
  assert.match(checkoutOverlay, />打印小票<\/button>/)
  assert.doesNotMatch(checkoutOverlay, /request\('print-sales-order-receipt'/)
})

process.stdout.write(`ASSERT_PASSED=${passed}\n`)
process.stdout.write('ASSERT_FAILED=0\n')

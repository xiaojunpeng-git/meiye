import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { buildSalesOrderReceiptHtml, salesOrderReceiptFromCheckout } from '../../../前端代码/cashier-v3/src/services/salesOrderReceiptPrint.js'
const cashierWorkbench = readFileSync(new URL('../../../前端代码/cashier-v3/src/views/CashierWorkbenchView.vue', import.meta.url), 'utf8')
const receiptPrint = readFileSync(new URL('../../../前端代码/cashier-v3/src/services/salesOrderReceiptPrint.js', import.meta.url), 'utf8')
function ok(label, run) { run(); console.log('PASS ' + label) }
ok('收银成功页补入认证收银员展示名，已有订单人员优先且不修改业务快照', () => {
  const checkout = Object.freeze({ lines: [] })
  const context = { cashierName: '肖<收银>&员' }
  const receipt = salesOrderReceiptFromCheckout(checkout, null, context)
  context.cashierName = '之后切换的账号'
  assert.equal(receipt.cashierName, '肖<收银>&员')
  assert.match(buildSalesOrderReceiptHtml(receipt), /收银员<\/span><span>肖&lt;收银&gt;&amp;员<\/span>/)
  assert.equal(checkout.cashierName, undefined)
  assert.equal(salesOrderReceiptFromCheckout({ cashierName: '原订单人员' }, null, context).cashierName, '原订单人员')
  assert.match(cashierWorkbench, /salesOrderReceiptFromCheckout\(preview, submission, \{\s*cashierName: String\(state\.operator\?\.name/)
})

ok('销售项目逐行显示美容师，多人换行且不串用销售或其他项目人员', () => {
  for (const paperSize of ['58', '76', '80']) {
    const receipt = salesOrderReceiptFromCheckout({ lines: [
      { lineRole: 'sale', name: '六维馥玉', quantity: 1, payableAmount: 1999,
        craftsmen: [{ name: '张<一>' }, { employeeName: '李&二' }], salespeople: [{ name: '销售甲' }] },
      { lineRole: 'sale', name: '产品乙', quantity: 2, payableAmount: 100, craftsmen: [] }
    ] })
    const html = buildSalesOrderReceiptHtml(receipt, { paperSize })
    assert.match(html, /六维馥玉<\/td><td class="right">x1<\/td><td class="right">¥1,999<\/td><\/tr><tr class="receipt-item-personnel"><td colspan="3"><small class="receipt-line-beautician">美容师：张&lt;一&gt;、李&amp;二<\/small>/)
    assert.match(html, /产品乙<\/td><td class="right">x2<\/td><td class="right">¥100<\/td>/)
    assert.doesNotMatch(html, /销售甲/)
    assert.match(html, /\.receipt-line-beautician \{ display: block;[^}]*overflow-wrap: anywhere/)
  }
  assert.match(receiptPrint, /\$\{root\} \.receipt-line-beautician \{ display: block;/)
})

ok('全部订单的纯权益单也使用整行美容师，次数及零余次保持原值', () => {
  const items = Object.freeze([
    Object.freeze({ businessTag: '权益', name: '腿部放松', quantity: 1, remainingTimesAfter: 18,
      craftsmen: [{ name: '张淮' }] }),
    Object.freeze({ businessTag: '权益', name: '另一权益', quantity: 2, remainingTimesAfter: 0,
      craftsmen: [{ name: '汪永梅' }, { name: '张青青' }] })
  ])
  for (const paperSize of ['58', '76', '80']) {
    const html = buildSalesOrderReceiptHtml({ salesOrderNo: 'FW110200741', items }, { paperSize })
    assert.match(html, /<th>名称<\/th><th class="right">次数<\/th><th class="right">余次<\/th>/)
    assert.doesNotMatch(html, /<th class="beautician">|colspan="4"/)
    assert.match(html, /腿部放松<\/td><td class="right">1<\/td><td class="right">18<\/td><\/tr><tr class="receipt-item-personnel"><td colspan="3"><small class="receipt-line-beautician">美容师：张淮/)
    assert.match(html, /另一权益<\/td><td class="right">2<\/td><td class="right">0<\/td>/)
    assert.match(html, /美容师：汪永梅、张青青/)
  }
  assert.equal(items[0].remainingTimesAfter, 18)
  assert.equal(items[1].remainingTimesAfter, 0)
})

// 两个入口的载荷形状不同；相同业务内容必须产出完全相同的打印文档。
ok('收银成功页和全部订单共用当前小票格式，三种纸宽的同单文档一致', () => {
  const meta = { salesOrderNo: 'XS-PRINT-PARITY', storeName: '测试门店', memberName: '测试会员',
    businessDate: '2026-11-02', storeAddress: '测试门店地址', orderNote: '测试备注' }
  const payment = [{ methodName: '现金', amount: 1999 }]
  const summary = { payableAmount: 1999, actualReceivedAmount: 1999 }
  const checkout = salesOrderReceiptFromCheckout({ ...meta,
    lines: [{ lineRole: 'sale', name: '六维馥玉', quantity: 1, payableAmount: 1999,
      craftsmen: [{ name: '张一' }, { name: '李二' }] },
      { id: 'entitlement-line', lineRole: 'entitlement_service', name: '腿部放松', quantity: 1,
        craftsmen: [{ name: '张淮' }] }],
    payment: { resultLines: payment }, summary
  }, { requestStatus: 'succeeded', entitlementCompletion: { persistenceStatus: 'persisted',
    receiptEntitlements: [{ lineId: 'entitlement-line', remainingTimesAfter: 18 }] }
  }, { cashierName: '原收银员' })
  const detail = { ...meta, cashierName: '原收银员',
    items: [{ businessTag: '购买', name: '六维馥玉', quantity: 1, payableAmount: 1999,
      craftsmen: [], craftsmenListAllocations: [{ employeeName: '张一' }, { employeeName: '李二' }] },
      { businessTag: '权益', name: '腿部放松', quantity: 1, remainingTimesAfter: 18,
        craftsmen: [{ name: '张淮' }] }],
    paymentDetails: payment, amountSummary: summary }
  for (const paperSize of ['58', '76', '80']) {
    assert.equal(buildSalesOrderReceiptHtml(checkout, { paperSize }), buildSalesOrderReceiptHtml(detail, { paperSize }))
  }
  const center = readFileSync(new URL('../../../前端代码/cashier-v3/src/views/OrderCenterView.vue', import.meta.url), 'utf8')
  const button = readFileSync(new URL('../../../前端代码/cashier-v3/src/components/order/SalesOrderReceiptPrintButton.vue', import.meta.url), 'utf8')
  const overlay = readFileSync(new URL('../../../前端代码/cashier-v3/src/components/cashier/CashierCheckoutOverlay.vue', import.meta.url), 'utf8')
  assert.match(center, /openSalesOrderReceiptPrint\(detail\)/)
  assert.match(button, /openSalesOrderReceiptPrint\(props\.order\)/)
  assert.match(overlay, /openSalesOrderReceiptPrint\(checkoutReceipt\)/)
})

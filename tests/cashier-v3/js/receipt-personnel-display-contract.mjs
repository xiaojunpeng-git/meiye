import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { buildSalesOrderReceiptHtml } from '../../../前端代码/cashier-v3/src/services/salesOrderReceiptPrint.js'
const cashierWorkbench = readFileSync(new URL('../../../前端代码/cashier-v3/src/views/CashierWorkbenchView.vue', import.meta.url), 'utf8')
const receiptPrint = readFileSync(new URL('../../../前端代码/cashier-v3/src/services/salesOrderReceiptPrint.js', import.meta.url), 'utf8')
function ok(label, run) { run(); console.log('PASS ' + label) }


ok('销售项目逐行显示美容师，多人换行且不串用销售或其他项目人员', () => {
  for (const paperSize of ['58', '76', '80']) {
    const receipt = { items: [
      { lineRole: 'sale', name: '六维馥玉', quantity: 1, payableAmount: 1999,
        craftsmen: [{ name: '张<一>' }, { employeeName: '李&二' }], salespeople: [{ name: '销售甲' }] },
      { lineRole: 'sale', name: '产品乙', quantity: 2, payableAmount: 100, craftsmen: [] }
    ] }
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


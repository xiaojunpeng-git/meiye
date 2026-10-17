import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import { buildSalesOrderReceiptHtml } from '../../../前端代码/cashier-v3/src/services/salesOrderReceiptPrint.js'
import {
  businessReceiptFromRecord,
  businessReceiptRefreshQuery,
  refreshedBusinessReceiptRecord
} from '../../../前端代码/cashier-v3/src/services/orderCenterBusinessReceipt.js'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const view = readFileSync(path.join(root, '前端代码/cashier-v3/src/views/OrderCenterView.vue'), 'utf8')

const base = { id: 'legacy-supplement:9', supplementOrderNo: 'BJ26101400001', businessDate: '2026-10-14' }
assert.deepEqual(businessReceiptRefreshQuery('supplement', base), {
  recordType: 'supplement', keyword: 'BJ26101400001', businessDateFrom: '2026-10-14',
  businessDateTo: '2026-10-14', dataScope: 'all', page: 1, pageSize: 100
})
assert.deepEqual(businessReceiptRefreshQuery('supplement', { ...base, businessDate: '' }), {
  recordType: 'supplement', keyword: 'BJ26101400001', dataScope: 'all', page: 1, pageSize: 100
})
assert.equal(refreshedBusinessReceiptRecord({ recordsByType: { supplement: [
  { ...base, id: 'legacy-supplement:8' }, { ...base, supplementAmount: '1000.00' }
] } }, 'supplement', base)?.supplementAmount, '1000.00')
assert.equal(refreshedBusinessReceiptRecord({ recordsByType: { supplement: [] } }, 'supplement', base), null)

const supplement = businessReceiptFromRecord('supplement', {
  ...base, storeName: '东台店', memberName: '陈某', operatorName: '前台',
  productName: '腿部放松', sourceOrderNo: 'XS1', debtNo: 'QK1',
  supplementAmount: '1000.00', paymentMethod: '微信、现金', paymentStatus: '补交成功'
})
const supplementHtml = buildSalesOrderReceiptHtml(supplement)
assert.match(supplementHtml, /补交记录小票/)
assert.match(supplementHtml, /补交明细/)
assert.match(supplementHtml, /BJ26101400001/)
assert.match(supplementHtml, /补交金额.*¥1,000/s)
assert.match(supplementHtml, /微信、现金/)
assert.doesNotMatch(supplementHtml, /应付金额|销售明细/)

const recharge = businessReceiptFromRecord('recharge', {
  id: 'recharge:7', rechargeOrderNo: 'CZ7', businessDate: '2026-10-14',
  rechargeAmount: '2980.00', giftAmount: '200.00', actualReceivedAmount: null,
  economicsDataStatus: 'not_ready', paymentMethod: '微信', orderStatus: '正常'
})
const rechargeHtml = buildSalesOrderReceiptHtml(recharge)
assert.match(rechargeHtml, /充值订单小票|充值明细/)
assert.match(rechargeHtml, /实收数据.*未就绪/s)
assert.doesNotMatch(rechargeHtml, /记账收款|收款明细|实收金额/)
assert.equal(businessReceiptFromRecord('recharge', { ...recharge, rechargeAmount: null }), null)

const refund = businessReceiptFromRecord('refund', {
  id: 'refund:one', refundOrderNo: 'TK1', businessDate: '2026-10-14',
  refundAmount: '0.00', refundMethod: '手工登记', refundStatus: '已作废',
  refundSummary: '转入余额 100 元', productName: '<项目>', sourceOrderNo: 'XS1'
})
const refundHtml = buildSalesOrderReceiptHtml(refund)
assert.match(refundHtml, /退款记录小票/)
assert.match(refundHtml, /登记现金退款.*¥0/s)
assert.match(refundHtml, /已作废/)
assert.match(refundHtml, /转入余额 100 元/)
assert.match(refundHtml, /&lt;项目&gt;/)
assert.doesNotMatch(refundHtml, /<项目>|收款明细|实收金额/)

// 三类打印必须同时出现在全部订单和各自页签，并复用销售小票的纸张配置。
assert.match(view, /v-if="canPrintBusinessRecord\(record\)"[\s\S]*?@click="printBusinessRecord\(record\)"/)
assert.ok((view.match(/@click="printBusinessRecord\(record\)"/g) || []).length >= 2)
assert.match(view, /query-order-center-records', query/)
assert.match(view, /openSalesOrderReceiptPrint\(receipt\)/)
console.log('order-center business receipts: passed')

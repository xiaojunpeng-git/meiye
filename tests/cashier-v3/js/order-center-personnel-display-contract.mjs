import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import {
  salespeoplePerformanceText,
  serviceCraftsmenPerformanceText
} from '../../../前端代码/cashier-v3/src/services/orderCenterPersonnelDisplay.js'

// Display uses each person's server-assigned amount; it must not split the
// order payable total or suppress a legitimate zero-amount assignment.
assert.equal(salespeoplePerformanceText([
  { name: '汤静静', salesPerformanceAmount: 2980, roleSnapshot: 'salesperson:postsale' },
  { name: '许长娥', salesPerformanceAmount: 0, roleSnapshot: 'salesperson:presale' }
]), '汤静静（2980），许长娥（售前、0）')
assert.equal(salespeoplePerformanceText([{ name: '汤静静', salesPerformanceAmount: 2980, isPreSale: true }]), '汤静静（售前、2980）')
assert.equal(salespeoplePerformanceText([]), '—')
assert.equal(salespeoplePerformanceText([{ name: '汤静静' }]), '汤静静（—）')

assert.equal(serviceCraftsmenPerformanceText({ craftsmenListAllocations: [
  { employeeName: '许长娥', isPointCustomer: false, amount: 0, laborFeeAmount: 45, projectCount: '1' },
  { employeeName: '汤静静', isPointCustomer: true, amount: 2980, laborFeeAmount: 0, projectCount: '0.3' }
] }), '许长娥（轮、0、45、1），汤静静（点、2980、0、0.3）')
assert.equal(serviceCraftsmenPerformanceText({ craftsmenSummary: '历史手艺人（轮）' }), '历史手艺人（轮）')
assert.equal(serviceCraftsmenPerformanceText({ craftsmenListAllocations: [{ employeeName: '未知', amount: null }] }), '未知（轮、—、—、—）')
assert.equal(serviceCraftsmenPerformanceText({ craftsmenListAllocations: [
  { employeeName: '周琦博', isPointCustomer: null, amount: 0, laborFeeAmount: 5, projectCount: null }
] }), '周琦博（轮、0、5、—）')

const view = readFileSync(new URL('../../../前端代码/cashier-v3/src/views/OrderCenterView.vue', import.meta.url), 'utf8')
assert.match(view, /'销售人（业绩）'/)
assert.match(view, /salespeoplePerformanceText\(item\.salespeople\)/)
assert.match(view, /record\?\.serviceStatus === '已作废'/)
assert.match(view, /record\?\.reportCraftsmenListAllocations/)
assert.match(view, /return serviceCraftsmenPerformanceText\(record\)/)
console.log('order center personnel display contract: PASS')

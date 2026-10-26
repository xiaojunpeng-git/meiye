import assert from 'node:assert/strict'
import fs from 'node:fs'

const read = (path) => fs.readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8')

const api = read('前端代码/uniapp/api/order.js')
const form = read('前端代码/uniapp/pages/activity/reservation/index.vue')
const list = read('前端代码/uniapp/pages/goods/reservation_list/index.vue')
const detail = read('前端代码/uniapp/pages/goods/reservation_details/index.vue')
const result = read('前端代码/uniapp/pages/goods/reservation_status/index.vue')

for (const route of [
  'reservation/v3/order/create/${id}',
  'reservation/v3/order/list',
  'reservation/v3/order/detail/${id}',
  'reservation/v3/order/cancel/${id}',
  'reservation/v3/order/del/${id}',
]) {
  assert.ok(api.includes(route), `missing V3 member route: ${route}`)
}

for (const field of [
  'cart_num:this.cartNum',
  'reservation_time:this.targetDay',
  'reservation_start:',
  'reservation_end:',
  'custom_form:this.confirm',
  'cart_info_id:this.cartInfoId',
  'store_id:this.storeId',
  'service_duration_minutes:',
  'addon_items:',
  'mark:',
]) {
  assert.ok(form.includes(field), `member form lost original field: ${field}`)
}

assert.ok(form.includes('res.data.reservationId'), 'create result must retain the V3 reservation id')
assert.ok(result.includes('reservation_details/index?id=${this.reservationId}'), 'result page must open the new V3 detail')

for (const status of ['PENDING_CONFIRMATION', 'UNSTARTED', 'IN_SERVICE', 'COMPLETED', 'REJECTED', 'CANCELLED']) {
  assert.ok(list.includes(status), `member list missing status: ${status}`)
}

assert.ok(list.includes("String(item.status) === 'PENDING_CONFIRMATION'"), 'only pending confirmation may be cancelled from the list')
assert.ok(detail.includes("['UNSTARTED', 'IN_SERVICE']"), 'verification code must only render after confirmation')
assert.ok(detail.includes("['CANCELLED', 'REJECTED']"), 'terminal V3 appointments must expose the original delete flow')

console.log('member reservation V3 frontend contract: PASS')

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { validateInboundDates } from '../../../前端代码/inventory-vue3/src/inboundDateValidation.js'

const valid = validateInboundDates([{ manufactured_date: '2026-06-30', expire_date: '2029-06-30' }])
assert.equal(valid.valid, true)

const impossible = validateInboundDates([{ manufactured_date: '2026-06-31', expire_date: '2029-06-30' }])
assert.deepEqual(impossible, {
  valid: false,
  message: '第 1 行生产日期不是有效日历日期（例如 6 月没有 31 日），请重新选择。'
})

const browserRejected = validateInboundDates([{ manufactured_date: '', manufactured_date_bad_input: true, expire_date: '2029-06-30' }])
assert.match(browserRejected.message, /第 1 行生产日期不是有效日历日期/)

const missing = validateInboundDates([{ manufactured_date: '2026-06-30', expire_date: '' }])
assert.equal(missing.message, '请填写第 1 行商品的生产日期和到期日。')

const reversed = validateInboundDates([{ manufactured_date: '2029-06-30', expire_date: '2026-06-30' }])
assert.equal(reversed.message, '第 1 行到期日不能早于生产日期。')

const modal = readFileSync(new URL('../../../前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue', import.meta.url), 'utf8')
assert.match(modal, /validateInboundDates\(selectedRows\.value\)/)
assert.match(modal, /validity && !target\.validity\.valid/)
assert.match(modal, /selectedRows\[rowIndex\]\?\.sku_id/)
console.log('INBOUND_DATE_VALIDATION_CONTRACT_OK')

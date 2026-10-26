import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const page = read('前端代码/cashier-v3/src/views/OrderCenterView.vue')
const bridge = read('前端代码/cashier-v3/src/services/cashierV3Bridge.js')
const manifest = read('前端代码/cashier-v3/src/services/cashierV3ActionManifest.js')

// The imported-card refund is editable in the salesperson column only; date correction stays narrower.
assert.match(page, /editableRefundSourceTypes = new Set\(\['card', 'document', 'catalog'\]\)/)
assert.match(page, /editableRefundPersonnelSourceTypes = new Set\(\[\.\.\.editableRefundSourceTypes, 'imported_card'\]\)/)
assert.match(page, /field === 'business_date'\s*&& editableRefundSourceTypes\.has\(record\?\.refundSourceType\)/)
assert.match(page, /fieldItem\.key === 'salesperson'.*editableRefundPersonnelSourceTypes\.has\(record\.refundSourceType\)/)
assert.match(page, /adjust: refundSourceType === 'imported_card'\s*\? 'adjust-imported-refund-personnel' : 'adjust-refund-personnel'/)
assert.match(page, /recordPersonnelActionNames\(target\.type, target\.refundSourceType\)/)
assert.match(manifest, /'adjust-imported-refund-personnel': FEATURE_ORDER_STAFF_ADJUST/)
assert.match(bridge, /'adjust-imported-refund-personnel'/)
console.log('PASS R42 imported-card refund salesperson contract')

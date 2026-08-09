import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const workbench = read('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue')
const manifest = read('前端代码/cashier-v3/src/services/cashierV3ActionManifest.js')

assert.doesNotMatch(workbench, /disabledReason: '改价功能暂未开放'/)
assert.doesNotMatch(workbench, /disabledReason: '补单功能暂未开放'/)
assert.match(workbench, /moreActionEditor\.value = \{ type: 'order-note'/)
assert.match(workbench, /moreActionEditor\.value = \{ type: 'price-change'/)
assert.match(workbench, /moreActionEditor\.value = \{ type: 'supplement'/)
assert.match(workbench, /payload = \{ lineId: editor\.line\.id, lineAmountCents, reason \}/)
assert.match(workbench, /payload = \{ businessDate, reason \}/)
assert.match(workbench, /cashierToday = new Intl\.DateTimeFormat\('en-CA', \{ timeZone: 'Asia\/Shanghai' \}\)/)
assert.match(workbench, /@click="openSupplementDateEditor"/)
assert.match(workbench, /responseDataBlock\(result\)\.cashierDraft/)
assert.match(workbench, /const committedSupplement = localCashierDraft\.value\?\.supplement/)
assert.match(workbench, /return committedSupplement\.enabled \? committedSupplement : null/)

for (const action of [
  'update-cashier-order-note',
  'update-cashier-line-price',
  'update-cashier-supplement'
]) {
  assert.match(manifest, new RegExp(`'${action}': FEATURE_CASHIER`))
}

console.log('CASHIER_MORE_ACTIONS_FRONTEND_CONTRACT_OK')

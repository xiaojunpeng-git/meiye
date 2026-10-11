import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const page = read('前端代码/cashier-v3/src/views/OrderCenterView.vue')
const bridge = read('前端代码/cashier-v3/src/services/cashierV3Bridge.js')
const manifest = read('前端代码/cashier-v3/src/services/cashierV3ActionManifest.js')
const module = read('后端代码/app/services/cashier/v3/order/CashierV3OrderLifecycleModule.php')
const service = read('后端代码/app/services/cashier/v3/order/CashierV3RefundAttributionServices.php')
const list = read('后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php')
const sql = read('后端代码/database/upgrades/2026-10-11-退款导购销售经理改派/02-正式升级.sql')

assert.match(page, /fieldItem\.key === 'refund_attribution' && activeTabKey === 'refund' && editableRefundSourceTypes\.has\(record\.refundSourceType\)/)
assert.match(page, /@click="openRefundAttributionEditor\(record\)"/)
assert.match(page, /v-if="!isPlatformReadOnly && refundAttributionEditorOpen && refundAttributionEntry"[\s\S]*?hide-guide-rounds[\s\S]*?@confirm="prepareRefundAttributionReason"/)
assert.match(page, /requestAction\('adjust-refund-attribution',[\s\S]*?recordVersion: entry\.recordVersion,[\s\S]*?reason, idempotencyKey/)
assert.match(bridge, /'adjust-refund-attribution'/)
assert.match(manifest, /'open-refund-attribution-adjustment': FEATURE_ORDER_STAFF_ADJUST/)
assert.match(module, /registerCommand\('adjust-refund-attribution'/)
assert.match(service, /where\('store_id', \$operator->storeId\(\)\)/)
assert.match(service, /if \(\$this->identities\(\$old\) === \$this->identities\(\$new\)\)/)
assert.match(service, /CashierV3TransactionGuard::assertInTransaction\('refundAttribution\.adjustInTx'\)/)
assert.match(list, /改派版本是完整快照；即使人员被全部清空，也必须覆盖原始退款归属/)
assert.match(sql, /UNIQUE KEY `uk_operation_version`/)
assert.match(sql, /UNIQUE KEY `uk_command`/)
assert.doesNotMatch(sql, /DROP TABLE|DELETE FROM|UPDATE `eb_cashier_v3_refund_attribution`/)
console.log('PASS R42 refund attribution adjustment contract')

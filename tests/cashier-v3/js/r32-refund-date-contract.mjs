import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const read = (name) => fs.readFileSync(path.join(root, name), 'utf8')
const page = read('前端代码/cashier-v3/src/views/OrderCenterView.vue')
const refund = read('后端代码/app/services/cashier/v3/order/CashierV3UnifiedRefundServices.php')
const correction = read('后端代码/app/services/cashier/v3/order/CashierV3OrderAttributionCorrectionServices.php')
const module = read('后端代码/app/services/cashier/v3/order/CashierV3OrderLifecycleModule.php')

// All manual refund kinds share the business-date correction, including catalog refunds without an original order.
assert.match(page, /unifiedRefundBusinessDate\.value = refundBusinessToday\(\)/)
assert.match(page, /id="unified-refund-business-date"[^>]*v-model="unifiedRefundBusinessDate"/)
assert.match(page, /balanceKind: 'bonus',\s*businessDate,/)
assert.match(page, /defaultOrderCenterDateQuery\(businessDate\), recordType: 'refund'/)
assert.match(page, /unified-refund-dialog__field-row/)
assert.match(page, /editableRefundSourceTypes = new Set\(\['card', 'document', 'catalog'\]\)/)
assert.match(page, /field === 'business_date'\s*&& editableRefundSourceTypes\.has\(record\?\.refundSourceType\)/)
assert.match(page, /fieldItem\.key === 'salesperson'.*editableRefundSourceTypes\.has\(record\.refundSourceType\)/)
assert.match(refund, /\$date = self::refundBusinessDate\(\$payload\['businessDate'\] \?\? \$actualDate, \$actualDate\)/)
assert.match(refund, /'unified_refund', \$operationId, \$actualDate, \$now/)
assert.match(refund, /'business_date' => \$date, 'occurred_at' => \$now/)

// Corrections are scoped, audited and move only this refund's economic facts.
assert.match(module, /'correct-refund-date'/)
assert.match(correction, /whereIn\('source_type', \['card', 'document', 'catalog'\]\)/)
assert.match(correction, /'cashier_v3_refund_adjustment'.*?where\('tenant_id', \$tenant\)/s)
for (const fact of ['cashier_v3_payment_fact', 'cashier_v3_balance_fact']) {
  assert.ok(correction.includes(`Db::name('${fact}')->where('tenant_id', $tenant)`), fact)
}
assert.match(correction, /\$this->log\(\$operationId, \$key, \$fingerprint/)
console.log('PASS R32 refund date contract')

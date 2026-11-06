import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'

const here = dirname(fileURLToPath(import.meta.url))
const root = resolve(here, '../../..')
const orderCenter = readFileSync(resolve(root, '前端代码/cashier-v3/src/views/OrderCenterView.vue'), 'utf8')
const refundService = readFileSync(resolve(root, '后端代码/app/services/cashier/v3/order/CashierV3UnifiedRefundServices.php'), 'utf8')

// 现金退款与转入余额是独立手填事实：两者可为 0，但仍只允许非负整数元。
assert.match(orderCenter, /id="imported-card-refund-amount"[^>]*pattern="\(\?:0\|\[1-9\]\[0-9\]\*\)"[^>]*placeholder="整数元，可填 0"/)
assert.doesNotMatch(orderCenter, /mode !== 'card' && Number\(amount\) <= 0/)
assert.doesNotMatch(orderCenter, /mode === 'document' && \(Number\(amount\) > remaining/)
assert.match(refundService, /enteredWholeYuanCents\(\$payload\['refundAmount'\] \?\? null, true\)/)
assert.doesNotMatch(refundService, /refund_amount_exceeds_remaining|refund_coupon_tender_exceeds_cash/)

// 超出单据余值时只把权益扣减封顶到 0，不改写手填退款和转入余额事实。
assert.match(refundService, /\$mode === 'document' \? min\(\$refundCents, max\(0, \(int\)\$target\['remainingAmountCents'\]\)\)/)
assert.match(refundService, /\$this->adjustLine\(\$snapshot, \$rightsRefundCents, \$void\)/)

console.log('PASS R20 manual refund amount contract')

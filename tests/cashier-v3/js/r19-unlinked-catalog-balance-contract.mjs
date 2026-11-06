import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const page = fs.readFileSync(path.join(root, '前端代码/cashier-v3/src/views/OrderCenterView.vue'), 'utf8')
const refund = fs.readFileSync(path.join(root, '后端代码/app/services/cashier/v3/order/CashierV3UnifiedRefundServices.php'), 'utf8')

// 无原单商品只提供退款展示身份，页面仍必须显示独立的转入余额字段。
assert.match(page, /id="unified-refund-balance"[^>]*inputmode="numeric"/)
assert.doesNotMatch(page, /v-if="effectiveRefundMode !== 'catalog'"[^>]*class="unified-refund-dialog__field"/)
assert.match(page, /无原单商品只确定退款展示身份；现金退款与转入余额仍是独立手工事实/)

// 服务端允许 catalog 模式转入赠金，但仍禁止伪造作废原单。
assert.match(refund, /\$mode === 'catalog' && \(\$catalogItemId <= 0 \|\| \$void\)/)
assert.doesNotMatch(refund, /\$mode === 'catalog' && \([^\n]*\$balanceCents !== 0/)
assert.match(refund, /现金退款和转入赠金分别留事实/)
assert.match(refund, /'balance_credit_cents' => \$mode === 'balance' \? 0 : \$balanceCents/)
assert.match(refund, /\$this->creditBalance\(\$memberId, \$balanceCents/)

console.log('PASS R19 unlinked catalog balance contract')

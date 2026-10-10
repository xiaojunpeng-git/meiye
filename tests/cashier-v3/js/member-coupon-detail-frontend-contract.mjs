import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

const root = fileURLToPath(new URL('../../../', import.meta.url))
const overlay = readFileSync(new URL('前端代码/cashier-v3/src/components/member/MemberDetailOverlay.vue', `file://${root}`), 'utf8')
const shell = readFileSync(new URL('前端代码/cashier-v3/src/layouts/CashierShell.vue', `file://${root}`), 'utf8')

// The coupon entitlement has its own tab; the former asset-panel placement
// must not silently return when the member detail layout changes.
assert.match(overlay, /\{ key: 'coupons', label: '优惠券' \},\s*\{ key: 'sales', label: '销售订单' \}/)
assert.match(overlay, /<section v-else-if="activeTab === 'coupons'"[^>]*aria-label="优惠券"[\s\S]*?<h3>优惠券<\/h3>/)
assert.ok(overlay.indexOf('activeTab === \'coupons\'') > overlay.indexOf('activeTab === \'assets\''))
assert.ok(overlay.indexOf('activeTab === \'coupons\'') < overlay.indexOf('activeTab === \'sales\''))
assert.match(overlay, /\['assets', 'gift', 'coupons'\]\.includes\(tab\.key\)/)
assert.match(shell, /\['assets', 'gift', 'coupons'\]\.includes\(memberDetailInitialTab\.value\)/)
console.log('MEMBER_COUPON_DETAIL_FRONTEND_CONTRACT_OK')

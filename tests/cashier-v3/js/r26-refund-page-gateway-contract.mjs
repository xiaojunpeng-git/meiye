import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { CASHIER_V3_ACTION_MANIFEST } from '../../../前端代码/cashier-v3/src/services/cashierV3ActionManifest.js'
import { nextWholeYuanInputValue } from '../../../前端代码/cashier-v3/src/services/refundWholeYuanInput.js'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const bridge = fs.readFileSync(path.join(root, '前端代码/cashier-v3/src/services/cashierV3Bridge.js'), 'utf8')
const orderCenter = fs.readFileSync(path.join(root, '前端代码/cashier-v3/src/views/OrderCenterView.vue'), 'utf8')
const refundService = fs.readFileSync(path.join(root, '后端代码/app/services/cashier/v3/order/CashierV3UnifiedRefundServices.php'), 'utf8')
const recordQuery = fs.readFileSync(path.join(root, '后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php'), 'utf8')
const numberService = fs.readFileSync(path.join(root, '后端代码/app/services/cashier/v3/CashierV3BusinessDocumentNumberServices.php'), 'utf8')
const memberDetail = fs.readFileSync(path.join(root, '后端代码/app/services/cashier/v3/member/CashierV3MemberDetailQueryServices.php'), 'utf8')
const memberModule = fs.readFileSync(path.join(root, '后端代码/app/services/cashier/v3/member/CashierV3MemberModule.php'), 'utf8')
const memberOverlay = fs.readFileSync(path.join(root, '前端代码/cashier-v3/src/components/member/MemberDetailOverlay.vue'), 'utf8')

// The browser must recognize both actions before it can exercise the server transaction.
for (const [action, type] of [
  ['query-unified-refund-candidates', 'projection'],
  ['refund-card-or-line', 'command']
]) {
  assert.equal(CASHIER_V3_ACTION_MANIFEST[action]?.type, type, `${action} type`)
  assert.equal(CASHIER_V3_ACTION_MANIFEST[action]?.owner, 'C5', `${action} owner`)
  assert.equal(CASHIER_V3_ACTION_MANIFEST[action]?.permission, 'cashier.v3.order.refund', `${action} permission`)
}

// Refund candidates are advisory; the command must send no stale workspace context.
const contextBranch = bridge.match(/if \(\['void-order-center-supplement'[\s\S]*?return \{ invalid: false, contexts: \[\] \}/)?.[0] || ''
const serverDiscovery = bridge.match(/const serverDiscoverableContextActions = \[[\s\S]*?\]/)?.[0] || ''
assert.match(contextBranch, /'refund-card-or-line'/)
assert.match(serverDiscovery, /'refund-card-or-line'/)
// 退款改派走同一条服务端锁单路径，两个前端上下文门禁必须一致。
assert.match(contextBranch, /'adjust-refund-personnel'/)
assert.match(serverDiscovery, /'adjust-refund-personnel'/)
assert.match(orderCenter, /recordPersonnelTarget\.type === 'refund' \? '退款记录'/)
assert.match(orderCenter, /recordPersonnelTarget\.recordNo \|\| recordPersonnelTarget\.recordId/)

// Both refund modes credit bonus only; a stale client cannot select principal.
const closeDialog = orderCenter.match(/function closeImportedCardRefund\(\) \{[\s\S]*?\n\}/)?.[0] || ''
assert.match(closeDialog, /unifiedRefundMode\.value = 'card'/)
assert.doesNotMatch(orderCenter, /unifiedRefundBalanceKind|aria-label="余额类型"/)
assert.match(orderCenter, /balanceKind: 'bonus'/)
assert.match(refundService, /if \(\$balanceKind !== 'bonus'\)/)
assert.match(refundService, /'restored_principal_cents' => 0/)
assert.match(refundService, /'restored_bonus_cents' => \$balanceCents/)

// Both modes query the scoped server candidate set before pagination, not just visible cards.
assert.match(orderCenter, /<div class="unified-refund-dialog__search">/)
assert.match(orderCenter, /type="search"[\s\S]*unifiedRefundMode === 'card' \? '搜索卡号、卡名或来源单号'/)
assert.match(orderCenter, /\.unified-refund-dialog__search \{ display: flex; align-items: center; gap: 8px; \}/)
assert.match(orderCenter, /keyword: unifiedRefundKeyword\.value/)
assert.match(refundService, /\$query->where\(function \(\$search\) use \(\$like\)/)
for (const column of ['h.card_no', 'h.card_name', 'o.order_id']) {
  assert.ok(refundService.includes(`'${column}', 'like', $like`), `${column} card search`)
}

// Both modes use integer-yuan inputs and the same server-side validation; old rows keep their numbers.
assert.match(orderCenter, /id="imported-card-refund-amount"[^>]*inputmode="numeric"[^>]*effectiveRefundMode === 'card' \? '\(\?:0\|\[1-9\]\[0-9\]\*\)'/)
assert.match(orderCenter, /id="unified-refund-balance"[^>]*inputmode="numeric"/)
for (const id of ['imported-card-refund-amount', 'unified-refund-balance']) {
  assert.match(orderCenter, new RegExp(`id="${id}"[^>]*@beforeinput="blockNonDigitRefundInput"[^>]*@input="updateWholeYuanRefundInput`))
}
assert.match(orderCenter, /nextWholeYuanInputValue\(amountRef\.value, event\.target\.value\)/)
for (const [previous, candidate, accepted] of [
  ['', '25', '25'], ['25', '25.5', '25'], ['25', '-25', '25'],
  ['0', '0.01', '0'], ['0', '', ''], ['', '120', '120']
]) {
  assert.equal(nextWholeYuanInputValue(previous, candidate), accepted)
}
assert.match(orderCenter, /const unifiedRefundBalanceAmount = ref\('0'\)/)
assert.doesNotMatch(orderCenter, /watch\(importedCardRefundAmount/)
assert.match(orderCenter, /selectedUnifiedRefundCard\.value\?\.ruleType === 'choice_count' \? '0' : ''/)
assert.match(refundService, /shared_remaining_times' => \$newTimes/)
assert.match(refundService, /'card_component_id' => \$mode === 'card' \? \$componentId : 0/)
assert.match(refundService, /金额只能填写整数元/)
assert.match(refundService, /CashierV3BusinessDocumentNumberServices::REFUND/)
assert.match(numberService, /self::REFUND => 'TK'/)
assert.match(numberService, /self::DEBT, self::REFUND\], true\) \? 4 : 5/)
// A complete success closes the editor and refreshes the authoritative list,
// while an incomplete success retains its idempotency key for safe replay.
assert.doesNotMatch(orderCenter, /unifiedRefundSuccess|unified-refund-result/)
assert.match(orderCenter, /if \(!result\?\.operationNo\) \{[\s\S]*importedCardRefundUncertain\.value = true/)
const refundSubmit = orderCenter.match(/async function submitImportedCardRefund\(\) \{[\s\S]*?\n\}\n/)?.[0] || ''
assert.match(refundSubmit, /if \(!result\?\.operationNo\) \{[\s\S]*?return[\s\S]*?\n      \}/)
assert.match(refundSubmit, /closeImportedCardRefund\(\)[\s\S]*activeTabKey\.value = 'refund'[\s\S]*queryModelByType\.value = \{ \.\.\.queryModelByType\.value, refund: \{\} \}[\s\S]*executedQueryByType\.value = \{ \.\.\.executedQueryByType\.value, refund: null \}[\s\S]*await queryRecords\(\{ \.\.\.defaultOrderCenterDateQuery\(businessDate\), recordType: 'refund', page: 1, keyword: '' \}, true\)/)
assert.match(refundService, /'targetName' => \(string\)\(\$saved\['target_name_snapshot'\]/)
// The V3 entitlement order carries a v3c... ID, but card refunds must show
// the sale number through the tenant-scoped issuance receipt, including old rows.
assert.match(refundService, /where\('cpr\.tenant_id', \$tenantId\)->where\('cpr\.card_holder_id', \$holderId\)/)
assert.match(refundService, /where\('cpr\.legacy_order_id', \$originId\)->where\('cpr\.status', 'completed'\)/)
assert.match(refundService, /'sourceOrderNo' => \$salesOrderNo !== '' \? \$salesOrderNo : \(string\)\$candidate\['order_id'\]/)
// The card picker and the command cap must use debt-adjusted rights, while
// the immutable gross remainder stays available for refund history updates.
assert.match(orderCenter, /实际剩余金额 <strong>\{\{ importedCardRefundMoney\(card\.usableRemainingAmountCents\) \}\}<\/strong>/)
assert.match(orderCenter, /component\.usableRemainingAmountCents <= 0/)
assert.match(orderCenter, /selectedUnifiedRefundCard\.purchaseAmountCents === 0 && component\.remainingTimes > 0/)
assert.match(orderCenter, /mode !== 'card' && Number\(amount\) <= 0/)
assert.match(refundService, /enteredWholeYuanCents\(\$payload\['refundAmount'\] \?\? null, \$mode === 'card'\)/)
assert.match(refundService, /\$zeroPriceBonusGrant = \$mode === 'card' && \(int\)\$snapshot\['purchaseAmountCents'\] === 0/)
assert.match(refundService, /'zeroPriceBonusGrantCents' => \$zeroPriceBonusGrant \? \$balanceCents : 0/)
assert.match(refundService, /\$mode === 'document' && \(\$refundCents > \$remaining[\s\S]*?\$balanceCents > \(int\)\$snapshot\['usableRemainingAmountCents'\]/)
assert.match(refundService, /\$cashRefundRemainingCents = \$otherCollection \? 0 : min\(\$cashRefundRemainingCents, \$usableSum\)/)
const refundRecords = recordQuery.match(/private function readRefund\(array \$criteria\): array\s*\{[\s\S]*?private function /)?.[0] || ''
assert.match(refundRecords, /cpr\.tenant_id = rlo\.tenant_id AND cpr\.card_holder_id = ra\.card_holder_id AND cpr\.legacy_order_id = rlo\.source_order_id/)
assert.match(refundRecords, /cso\.order_no AS card_sales_order_no/)
assert.match(refundRecords, /'sourceOrderNo' => \(string\)\$row\['source_type'\] === 'card'[\s\S]*\$row\['card_sales_order_no'\]/)
// Member history remains a scoped read action; neither screen exposes internal refund codes.
assert.equal(CASHIER_V3_ACTION_MANIFEST['query-member-card-history']?.type, 'projection')
assert.equal(CASHIER_V3_ACTION_MANIFEST['query-member-card-history']?.permission, 'cashier.v3.member')
assert.match(memberModule, /registerProjection\('query-member-card-history'/)
assert.match(memberDetail, /'manual_refund_credit' => '退款转入余额'/)
assert.match(memberDetail, /'manual_refund' => '退款记录'/)
assert.match(memberOverlay, /response\?\.data\?\.data\?\.cardHistory/)
assert.match(memberOverlay, /Number\(timeline\.holderId\) !== Number\(card\.id\)/)
console.log('PASS R26 refund browser gateway contract')

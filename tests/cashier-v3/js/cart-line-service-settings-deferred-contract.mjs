#!/usr/bin/env node
import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'

const dirname = path.dirname(fileURLToPath(import.meta.url))
const repo = path.resolve(dirname, '../../..')
const sourcePath = path.join(repo, '前端代码/cashier-v3/src/views/CashierWorkbenchView.vue')
const source = fs.readFileSync(sourcePath, 'utf8')
const personnelOverlay = fs.readFileSync(
  path.join(repo, '前端代码/cashier-v3/src/components/cashier/PersonnelPerformanceOverlay.vue'),
  'utf8'
)

let failed = 0
function check(condition, message) {
  if (condition) console.log(`PASS: ${message}`)
  else {
    failed += 1
    console.error(`FAIL: ${message}`)
  }
}

function body(functionName) {
  const marker = `function ${functionName}`
  const start = source.indexOf(marker)
  if (start < 0) return ''
  const next = source.indexOf('\nfunction ', start + marker.length)
  const asyncNext = source.indexOf('\nasync function ', start + marker.length)
  const end = [next, asyncNext].filter((value) => value >= 0).sort((a, b) => a - b)[0]
  return source.slice(start, end === undefined ? source.length : end)
}

const serviceObjectToggle = body('setCartLineServiceObject(line, serviceObject, friendCountsAsCustomer = true)')
const experienceToggle = body('toggleCartLineExperience(line)')
const deferredPersistence = body('persistDeferredLineServiceSettings()')
const localPreview = body('localCheckoutPreviewSnapshot()')
const checkoutEntryStart = source.indexOf('async function openCheckout()')
const checkoutEntryEnd = source.indexOf('\nasync function openHangOrder', checkoutEntryStart)
const checkoutEntry = source.slice(checkoutEntryStart, checkoutEntryEnd)
const resetContext = body('resetCashierLocalContext()')
const clearCart = body('confirmClearCart()')
const personnelAssignment = source.slice(
  source.indexOf('async function confirmPersonnelAssignment'),
  source.indexOf('async function applyPersonnelAssignmentToAll')
)
const personnelAssignmentAll = source.slice(
  source.indexOf('async function applyPersonnelAssignmentToAll'),
  source.indexOf('\nfunction cartLinePersonnelNames', source.indexOf('async function applyPersonnelAssignmentToAll'))
)
const checkoutBar = source.match(/<footer class="cashier-checkout-bar">[\s\S]*?<\/footer>/)?.[0] || ''
const discard = fs.readFileSync(path.join(repo, '后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutDraftDiscardServices.php'), 'utf8')

check(
  /localLineServiceSettings\.value\s*=/.test(serviceObjectToggle)
    && !/requestAction\(|mutateCashierDraft\(/.test(serviceObjectToggle),
  'service-object and friend customer-count toggles update local state without a request'
)
check(
  /localLineServiceSettings\.value\s*=/.test(experienceToggle)
    && !/requestAction\(|mutateCashierDraft\(/.test(experienceToggle),
  'experience toggle updates local state without a request'
)
check(
  /return true/.test(deferredPersistence)
    && !/requestAction\(|mutateCashierDraft\(/.test(deferredPersistence),
  'deferred service-setting hook remains browser-only and does not write a server draft'
)
const localDraftRecalculation = source.slice(
  source.indexOf('function recalculateLocalCashierDraft'),
  source.indexOf('function commitLocalCashierDraft')
)
check(
  /recalculateLocalCashierDraft\(/.test(localPreview)
    && /localCashierDraft\.value \|\| localDraftBase\(\)/.test(localPreview)
    && /localLineServiceSettings/.test(localDraftRecalculation)
    && /serviceObject/.test(localDraftRecalculation)
    && /friendCountsAsCustomer/.test(localDraftRecalculation)
    && /isExperience/.test(localDraftRecalculation),
  'immediate checkout merges the latest browser service settings into the local snapshot'
)
check(
  /canonicalCheckoutCraftsmen\(craftsmen\)/.test(localPreview)
    && /function canonicalCheckoutCraftsmen/.test(source)
    && /employeeId/.test(source.slice(source.indexOf('function canonicalCheckoutCraftsmen'), source.indexOf('function commitLocalCashierDraft')))
    && /storeId/.test(source.slice(source.indexOf('function canonicalCheckoutCraftsmen'), source.indexOf('function commitLocalCashierDraft')))
    && /isPrimary/.test(source.slice(source.indexOf('function canonicalCheckoutCraftsmen'), source.indexOf('function commitLocalCashierDraft')))
    && /sequence/.test(source.slice(source.indexOf('function canonicalCheckoutCraftsmen'), source.indexOf('function commitLocalCashierDraft'))),
  'sale-project craftsmen are canonicalized only when the final browser checkout snapshot is built'
)
check(
  /const salespeople = Array\.isArray\(localPersonnel\.salespeople\)/.test(localPreview)
    && /const guideSelections = Array\.isArray\(localPersonnel\.guideSelections\)/.test(localPreview)
    && /const salesManagerSelections = Array\.isArray\(localPersonnel\.salesManagerSelections\)/.test(localPreview)
    && /canonicalCheckoutSalespeople\(salespeople\)/.test(localPreview)
    && /canonicalCheckoutAttributions\(guideSelections, true\)/.test(localPreview)
    && /canonicalCheckoutAttributions\(salesManagerSelections\)/.test(localPreview)
    && /function canonicalCheckoutSalespeople/.test(source)
    && /function canonicalCheckoutAttributions/.test(source),
  'all personnel IDs are read and canonicalized from browser state at the final snapshot boundary'
)
check(
  /selectedCraftsmenPayload[\s\S]*employeeId/.test(personnelOverlay)
    && /selectedCraftsmenPayload[\s\S]*storeId/.test(personnelOverlay)
    && /const assignment = \{[\s\S]*?craftsmen: selectedCraftsmen\.map\([\s\S]*?storeId: Number\(item\.storeId \|\| item\.store_id \|\| props\.storeId \|\| 0\)/.test(personnelOverlay)
    && /storeId: \{ type: \[Number, String\]/.test(personnelOverlay),
  'both personnel confirmation paths carry employee and store identity into the browser snapshot boundary'
)
check(
  /storeId: Number\(record\.storeId \?\? record\.store_id \?\? 0\)/.test(personnelAssignment)
    && /storeId: Number\(record\.storeId \?\? record\.store_id \?\? 0\)/.test(personnelAssignmentAll),
  'single-line and all-line personnel saves retain the selector store identity for final checkout snapshots'
)
check(
  /const preview = localCheckoutPreviewSnapshot\(\)/.test(checkoutEntry)
    && /localCheckoutPreview\.value = preview/.test(checkoutEntry)
    && /firstCartLineMissingCraftsmen/.test(checkoutEntry)
    && /CASHIER_CRAFTSMAN_REQUIRED/.test(checkoutEntry)
    && /请先选择手艺人/.test(checkoutEntry)
    && !/persistDeferredLineServiceSettings|synchronizeLocalCashierDraft/.test(checkoutEntry),
  '立即结账先提示未选择手艺人，不提前写服务端草稿'
)
check(
  /localLineServiceSettings\.value\s*=\s*\{\}/.test(resetContext),
  'local deferred settings are discarded when the cashier context resets'
)
check(
  /draftCommandRecovery\.clear\(\)/.test(clearCart)
    && /clear-negative-state/.test(clearCart)
    && /if \(isClearingCart\.value\) return/.test(clearCart)
    && !/hasCartLines\.value/.test(clearCart)
    && /cashier-checkout-actions__clear[\s\S]*?:disabled="isClearingCart"[\s\S]*?@click="confirmClearCart"/.test(checkoutBar)
    && clearCart.indexOf("await requestAction('open-cashier-workbench'") < clearCart.indexOf('clear-negative-state'),
  '清空始终可用作恢复出口，同时释放未决状态并关闭结果未知提示'
)
check(
  /protectedRequestIds/.test(discard)
    && /protectedRequestCount/.test(discard)
    && !/不能直接清空，请先核对收款结果/.test(discard),
  '已有正式事实时保留原请求作为查询依据，但不阻塞清空当前购物车'
)

if (failed > 0) process.exit(1)
console.log('CART_LINE_SERVICE_SETTINGS_DEFERRED_CONTRACT=PASS')

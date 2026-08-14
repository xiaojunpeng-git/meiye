#!/usr/bin/env node
import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'

const dirname = path.dirname(fileURLToPath(import.meta.url))
const repo = path.resolve(dirname, '../../..')
const sourcePath = path.join(repo, '前端代码/cashier-v3/src/views/CashierWorkbenchView.vue')
const source = fs.readFileSync(sourcePath, 'utf8')

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
const checkoutEntry = body('openCheckout()')
const resetContext = body('resetCashierLocalContext()')
const clearCart = body('confirmClearCart()')
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
  /mutateCashierDraft\('update-cart-line-service-settings', line, payload\)/.test(deferredPersistence)
    && /payload\.friendCountsAsCustomer/.test(deferredPersistence)
    && /payload\.isExperience/.test(deferredPersistence),
  'deferred save writes both friend customer-count and experience settings through the existing draft command'
)
check(
  /if \(!await persistDeferredLineServiceSettings\(\)\)/.test(checkoutEntry)
    && checkoutEntry.indexOf('persistDeferredLineServiceSettings') < checkoutEntry.indexOf('firstCartLineMissingCraftsmen'),
  'checkout persists deferred settings before service prerequisites and checkout preparation'
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

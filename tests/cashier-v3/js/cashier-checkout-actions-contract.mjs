import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const workbench = fs.readFileSync(
  path.join(root, '前端代码/cashier-v3/src/views/CashierWorkbenchView.vue'),
  'utf8'
)
const shell = fs.readFileSync(
  path.join(root, '前端代码/cashier-v3/src/layouts/CashierShell.vue'),
  'utf8'
)
const styles = fs.readFileSync(
  path.join(root, '前端代码/cashier-v3/src/styles/base.css'),
  'utf8'
)
const checkoutBar = workbench.match(/<footer class="cashier-checkout-bar">[\s\S]*?<\/footer>/)?.[0] || ''

assert.match(
  checkoutBar,
  /@click="openMoreAction\('open-price-change'\)"[\s\S]*?改价[\s\S]*?@click="openMoreAction\('open-order-note'\)"[\s\S]*?备注[\s\S]*?挂单[\s\S]*?checkoutEntryLabel/
)
assert.match(checkoutBar, /cashier-checkout-actions__clear[\s\S]*?confirmClearCart[\s\S]*?清空[\s\S]*?cashier-checkout-actions__price/)
assert.doesNotMatch(checkoutBar, />\s*更多操作\s*</)
assert.doesNotMatch(workbench, /open-supplement', label: '补单'/)
assert.match(workbench, /function openSupplementDateEditor()[\s\S]*?type: 'supplement'/)
assert.doesNotMatch(shell, /cashier-workflow-clear-cart|cashier-v3:clear-cart/)
assert.match(styles, /\.cashier-checkout-actions__clear,[\s\S]*?\.cashier-checkout-actions__price/)

console.log('cashier checkout actions frontend contract: PASS')

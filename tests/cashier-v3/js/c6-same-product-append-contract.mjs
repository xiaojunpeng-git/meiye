import assert from 'node:assert/strict'
import fs from 'node:fs'

const view = fs.readFileSync(
  new URL('../../../前端代码/cashier-v3/src/views/CashierWorkbenchView.vue', import.meta.url),
  'utf8'
)
const bridge = fs.readFileSync(
  new URL('../../../前端代码/cashier-v3/src/services/cashierV3Bridge.js', import.meta.url),
  'utf8'
)
const workspace = fs.readFileSync(
  new URL('../../../后端代码/app/services/cashier/v3/cashier/CashierV3CashierWorkspaceServices.php', import.meta.url),
  'utf8'
)
const catalog = fs.readFileSync(
  new URL('../../../后端代码/app/services/cashier/v3/cashier/CashierV3SaleCatalogServices.php', import.meta.url),
  'utf8'
)

assert.match(view, /async function appendCatalogItemToDraft\(itemId\)/)
assert.match(view, /每次用户点击都由 action bridge 生成新的命令标识/)
assert.match(view, /requestAction\('choose-catalog-item', \{ itemId \}\)/)
assert.match(bridge, /idempotencyKey \|\| createCashierV3CommandId\(\)/)
assert.match(catalog, /'line_key' => 'sale:' \. substr\(hash\('sha256', \$idempotencyKey\)/)
assert.match(workspace, /每次商品点击追加一条独立 sale 行；同一商品不会在草稿层合并/)
assert.match(workspace, /\(string\)\(\$row\['line_key'\] \?\? ''\) === \(string\)\(\$line\['line_key'\] \?\? ''\)/)

console.log('C6 same-product append contract: PASS')

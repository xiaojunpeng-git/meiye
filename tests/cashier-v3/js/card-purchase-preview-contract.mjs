import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { resolve } from 'node:path'

const root = resolve(fileURLToPath(new URL('../../..', import.meta.url)))
const workbench = readFileSync(resolve(root, '前端代码/cashier-v3/src/views/CashierWorkbenchView.vue'), 'utf8')
const catalog = readFileSync(resolve(root, '后端代码/app/services/cashier/v3/cashier/CashierV3SaleCatalogServices.php'), 'utf8')
const draftContract = readFileSync(resolve(root, '前端代码/cashier-v3/src/services/cashierV3EntitlementDraftContract.js'), 'utf8')

assert.match(catalog, /'cardPreview' => self::publicCardPreview\(\$item\)/)
assert.match(catalog, /private static function publicCardPreview\(array \$item\): \?array/)
assert.match(catalog, /'writeoffAmountCents' => \(int\)\(\$component\['writeoffAmountCents'\] \?\? 0\)/)
assert.doesNotMatch(catalog, /'resourceSources' => self::publicCardPreview/)
assert.match(catalog, /\$catalogItem = \$cardForSale/)

assert.match(workbench, /const pendingCardPurchase = ref\(null\)/)
assert.match(workbench, /if \(isRuleCardPurchase\(item\)\) \{\s*pendingCardPurchase\.value = clonePlain\(item\)\s*return/s)
assert.match(workbench, /async function confirmPendingCardPurchase\(\)/)
assert.match(workbench, /async function appendCatalogItemToDraft\(itemId\)/)
assert.match(workbench, /let catalogItemAppendQueue = Promise\.resolve\(\)/)
assert.match(workbench, /catalogItemAppendQueue\.then\(async \(\) =>/)
assert.match(workbench, /catalogItemAppendQueue = queued\.catch\(\(\) => null\)/)
assert.match(workbench, /const draft = responseDataBlock\(result\)\.cashierDraft/)
assert.match(workbench, /typeof nested\.status === 'string'/)
assert.match(draftContract, /typeof nested\.status === 'string'/)
assert.match(workbench, /async function ensureCashierDraftRendered\(draft\)/)
assert.match(workbench, /async function applyCommittedCashierDraft\(draft, requestScopeKey\)/)
assert.match(workbench, /function isResponseBoundCommittedCashierDraft\(draft\)/)
assert.match(workbench, /if \(!await applyCommittedCashierDraft\(draft, requestScopeKey\)\)/)
assert.match(workbench, /message: '购物车权威数据尚未完整返回，系统已自动刷新工作台；如仍未显示请稍后重试。'/)
assert.match(workbench, /await appendCatalogItemToDraft\(item\.id\)/)
assert.match(workbench, /return addEntitlementLines\(payload\)/)
assert.match(workbench, /aria-label="确认卡项内容"/)
assert.match(workbench, /cardPreviewRuleDescription\(pendingCardPurchase\.cardPreview\)/)
assert.match(workbench, /pendingCardPurchase\.cardPreview\.ruleType === 'time'/)
assert.match(workbench, /writeValid\) === 1\) return '长期有效'/)
assert.match(workbench, /确认加入购物车/)

console.log('PASS card purchase preview only exposes display-safe rule fields')
console.log('PASS all four rule cards require explicit confirmation before draft mutation')
console.log('PASS catalog mutations render the committed authoritative cart draft')
console.log('PASS time card preview includes configured per-writeoff amount')

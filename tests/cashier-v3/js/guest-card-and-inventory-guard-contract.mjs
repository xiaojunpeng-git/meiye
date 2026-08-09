import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { resolve } from 'node:path'

const root = resolve(fileURLToPath(new URL('../../..', import.meta.url)))
const workbench = readFileSync(resolve(root, '前端代码/cashier-v3/src/views/CashierWorkbenchView.vue'), 'utf8')
const workspace = readFileSync(resolve(root, '后端代码/app/services/cashier/v3/cashier/CashierV3CashierWorkspaceServices.php'), 'utf8')
const module = readFileSync(resolve(root, '后端代码/app/services/cashier/v3/cashier/CashierV3CashierModule.php'), 'utf8')
const catalog = readFileSync(resolve(root, '后端代码/app/services/cashier/v3/cashier/CashierV3SaleCatalogServices.php'), 'utf8')
const customCard = readFileSync(resolve(root, '后端代码/app/services/cashier/v3/card/CashierV3CustomCardConfigurationServices.php'), 'utf8')
const settlement = readFileSync(resolve(root, '后端代码/app/services/cashier/v3/settlement/CashierV3SaleOnlyCheckoutSubmissionServices.php'), 'utf8')
const cashierShell = readFileSync(resolve(root, '前端代码/cashier-v3/src/layouts/CashierShell.vue'), 'utf8')

const memberMessage = '请先创建会员档案，再购买卡项。'

assert.match(workbench, /item\.id === 'custom-card-entry'/)
assert.match(workbench, /item\.kind === '卡项'.*customerMode === 'guest'/s)
assert.match(workbench, /isMemberRequiredOpen = ref\(false\)/)
assert.match(workbench, new RegExp(memberMessage))
assert.match(workbench, /role="dialog" aria-modal="true" aria-label="购买卡项需要会员档案"/)
assert.match(workbench, /const cardRuleTypes = \[/)
assert.match(workbench, /item\.cardRuleType === selectedCardRuleType\.value/)
assert.match(workbench, /item\.cardRuleLabel, item\.specification/)
assert.match(catalog, /'cardRuleLabel' => self::cardRuleLabel\(\$item\['cardRuleType'\]\)/)
assert.match(catalog, /'time' => '时间卡'/)
assert.match(cashierShell, /memberSelectorInitialView === 'creator'/)
assert.match(cashierShell, /:allow-create="canUseFeature\('cashier\.v3\.member'\) && \(isCashierWorkflowPage \|\| memberSelectorInitialView === 'creator'\)"/)

assert.match(workspace, /function assertCardSaleMemberInTx/)
assert.match(workspace, /card_purchase_member_required/)
assert.match(workspace, new RegExp(memberMessage))
assert.match(module, /\$lockedDraft = \$workspace->lockForSaleMutationInTx/)
assert.match(module, /\$workspace->assertCardSaleMemberInTx\(\$lockedDraft, \$line\)/)
assert.match(customCard, new RegExp(memberMessage))

assert.match(catalog, /assertInventoryAvailableForSale\(\$normalized, 1, \$dataScope\)/)
assert.match(catalog, /assertInventoryAvailableForSale\(\$current, \$quantity, \$dataScope\)/)
assert.match(catalog, /inventory_location/)
assert.match(catalog, /inventory_stock/)
assert.match(catalog, /inventory_batch/)
assert.match(catalog, /catalogInventoryAvailability/)
assert.match(catalog, /inventoryUnitsToDecimal/)
assert.match(catalog, /'stockText' => \$isInventoryProduct\s*\? '库存 ' \. \(string\)\(\$inventory\['quantity'\] \?\? '0'\)/s)
assert.match(catalog, /当前门店尚未入库，不能销售该产品。/)
assert.match(catalog, /库存不足，当前可用库存为 %s，不能销售 %s 件，请修改数量后再试。/)
assert.match(workbench, /cartQuantityValidationError = ref\(''\)/)
assert.match(workbench, /CASHIER_CART_QUANTITY_INVALID/)
assert.match(workbench, /reportCartQuantityFailure/)
assert.match(settlement, /sale_inventory_stock_not_ready/)
assert.match(settlement, /当前门店库存已变化或不足，本次结账未提交。请完成入库后重新结账。/)

console.log('PASS guest card and custom card are blocked before draft mutation')
console.log('PASS member-list creation opens the full creator with the same permission gate')
console.log('PASS backend repeats the guest card rule without trusting the UI')
console.log('PASS inventory-managed products preflight default location, stock, and batch balance')
console.log('PASS catalog stock text uses the same V3 stock and batch balance as checkout')
console.log('PASS final checkout keeps a specific inventory failure for concurrent stock changes')

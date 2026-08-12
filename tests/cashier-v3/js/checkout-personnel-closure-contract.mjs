import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const workbench = read('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue')
const rechargeCheckout = read('前端代码/cashier-v3/src/composables/useRechargeCheckout.js')
const checkout = read('前端代码/cashier-v3/src/components/cashier/CashierCheckoutOverlay.vue')
const styles = read('前端代码/cashier-v3/src/styles/base.css')
const workspace = read('后端代码/app/services/cashier/v3/cashier/CashierV3CashierWorkspaceServices.php')
const catalog = read('后端代码/app/services/cashier/v3/cashier/CashierV3SaleCatalogServices.php')
const moreActions = read('后端代码/app/services/cashier/v3/cashier/CashierV3CashierMoreActionServices.php')

assert.match(workbench, /function isCustomCardPurchase[\s\S]*?cardPurchaseSnapshot\?\.sourceKind[\s\S]*?'custom_card'/)
assert.match(workbench, /Number\(line\.productType\) === 6 && !isCustomCardPurchase\(line\)/)
assert.match(workbench, /firstCartLineMissingCraftsmen[\s\S]*?!isCustomCardPurchase\(line\)/)
assert.match(workspace, /applyCraftsmenToAllServiceLinesInTx[\s\S]*?\$isCustomCard[\s\S]*?&& !\$isCustomCard/)
assert.match(catalog, /\$isServiceProject = \$normalized\['productType'\] === 6[\s\S]*?!== 'custom_card'/)

assert.match(workbench, /checkoutDraftMutationTail\.then\(run, run\)/)
assert.match(workbench, /requestCheckoutDraftMutationWithSingleConflictReplay/)
assert.match(rechargeCheckout, /requestRechargeCheckoutMutationWithSingleConflictReplay/)
assert.match(rechargeCheckout, /\['add-payment-method', 'update-payment-line', 'remove-payment-line', 'update-checkout-business-source'\]\.includes\(action\)/)
assert.match(rechargeCheckout, /requestCashierV3Action\('reload-recharge-checkout'/)
assert.match(checkout, /收款金额必须为(?:正整数|大于0的整数)/)
assert.match(styles, /\.checkout-payment-line__amount[\s\S]*?justify-content: flex-start/)
assert.match(styles, /\.checkout-payment-line__amount-input[\s\S]*?text-align: left/)

assert.match(workbench, /moreActionValidationMessage\.value = !reason \? '请输入补单原因。'/)
assert.doesNotMatch(workbench, /moreActionEditor\.type === 'supplement'[\s\S]{0,160}!moreActionReason\.trim\(\)/)
assert.match(moreActions, /customCardConfiguredCostCents/)
assert.match(moreActions, /\$isCustomCard[\s\S]*?\$minimumLineAmountCents[\s\S]*?\$lineAmountCents < \$minimumLineAmountCents/)
assert.match(moreActions, /不能低于卡内项目成本合计/)
assert.match(catalog, /\$settlementOriginalUnitPriceCents = max\([\s\S]*?\$unitPriceCents\)/)

console.log('PASS checkout personnel closure contract')

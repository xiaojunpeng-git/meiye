import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const read = relative => fs.readFileSync(path.join(root, relative), 'utf8')
const workbench = read('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue')
const rechargeCheckout = read('前端代码/cashier-v3/src/composables/useRechargeCheckout.js')
const manifest = read('前端代码/cashier-v3/src/services/cashierV3ActionManifest.js')
const bridge = read('前端代码/cashier-v3/src/services/cashierV3Bridge.js')
const selector = read('前端代码/cashier-v3/src/components/cashier/CheckoutBusinessSourceOverlay.vue')

assert.match(workbench, /loadCheckoutBusinessCatalog/, '来源选择器必须只读取正式收银配置目录')
assert.match(workbench, /update-checkout-business-source/, '销售结账必须提交正式来源更新命令')
assert.match(rechargeCheckout, /update-recharge-checkout-business-source/, '充值结账必须提交正式来源更新命令')
assert.match(workbench, /sourceSelectionVersion/, '来源更新必须携带独立选择版本')
assert.match(workbench, /sourceSelectable === false/, '补交来源必须由后端投影为不可修改')
assert.match(manifest, /'update-checkout-business-source': FEATURE_CASHIER/, '销售来源更新必须登记为收银命令')
assert.match(manifest, /'update-recharge-checkout-business-source': FEATURE_MEMBER/, '充值来源更新必须登记为会员命令')
assert.match(bridge, /action === 'update-recharge-checkout-business-source'/, '充值来源更新必须带会员和余额版本上下文')
assert.match(selector, /requiresSecondary/, '来源选择器必须执行后端下发的二级必选规则')
assert.match(selector, /emit\('confirm', \{ primarySourceId: primaryId.value, secondarySourceId: secondaryId.value \}\)/, '前端只能提交来源 ID，名称由后端快照')
console.log('cashier business-source checkout frontend contract: PASS')

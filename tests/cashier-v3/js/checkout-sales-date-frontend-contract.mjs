import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const read = relative => fs.readFileSync(path.join(root, relative), 'utf8')
const overlay = read('前端代码/cashier-v3/src/components/cashier/CashierCheckoutOverlay.vue')
const workbench = read('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue')
const manifest = read('前端代码/cashier-v3/src/services/cashierV3ActionManifest.js')
const saveSalesDate = workbench.slice(
  workbench.indexOf('async function saveCheckoutSalesDate(selection = {})'),
  workbench.indexOf('function isCustomCardPurchase', workbench.indexOf('async function saveCheckoutSalesDate(selection = {})'))
)

assert.match(overlay, /const dateLabel = computed\(\(\) => isRechargeCheckout\.value \? '充值日期' : '销售日期'\)/, '销售/充值流程必须使用各自日期文案')
assert.match(overlay, /<dt>\{\{ dateLabel \}\}<\/dt>/, '结账第一步必须显示日期字段')
assert.match(overlay, /type="date"/, '销售日期必须使用日期控件')
assert.match(overlay, /salesDateIsHistorical/, '历史销售日期必须进入补单原因分支')
assert.match(overlay, /:placeholder="`历史日期请填写\$\{dateReasonLabel\}`"/, '历史日期必须提示填写原因')
assert.match(overlay, /if \(salesDateIsDirty\.value\)/, '未保存日期时禁止进入收款步骤')
assert.match(saveSalesDate, /localCheckoutBusinessDate\.value = businessDate/, '销售日期编辑必须先保存到工具栏前端态')
assert.match(saveSalesDate, /localCheckoutBusinessDateReason\.value = reason/, '历史销售日期原因必须保存到工具栏前端态')
assert.doesNotMatch(saveSalesDate, /requestAction\(|enqueueCheckoutAction\(/, '销售日期编辑不得提前写入服务端结账草稿')
assert.match(workbench, /action === 'update-checkout-sales-date'/, '最终结账预览仍需支持回放业务日期到快照')
assert.match(manifest, /'update-checkout-sales-date': FEATURE_CASHIER/, '销售日期命令必须进入动作清单')
assert.match(workbench, /action: 'update-recharge-business-date'/, '充值日期必须提交充值结账草稿命令')
assert.match(overlay, /'recharge-date-change'/, '充值日期必须复用结账日期保存事件')

console.log('cashier checkout sales-date frontend contract: PASS')

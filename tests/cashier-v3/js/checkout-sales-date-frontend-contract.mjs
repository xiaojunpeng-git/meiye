import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const read = relative => fs.readFileSync(path.join(root, relative), 'utf8')
const overlay = read('前端代码/cashier-v3/src/components/cashier/CashierCheckoutOverlay.vue')
const workbench = read('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue')
const manifest = read('前端代码/cashier-v3/src/services/cashierV3ActionManifest.js')

assert.match(overlay, /<dt>销售日期<\/dt>/, '结账第一步必须显示销售日期')
assert.match(overlay, /type="date"/, '销售日期必须使用日期控件')
assert.match(overlay, /salesDateIsHistorical/, '历史销售日期必须进入补单原因分支')
assert.match(overlay, /placeholder="历史日期请填写补单原因"/, '历史日期必须提示填写原因')
assert.match(overlay, /currentStep === 1 && salesDateIsDirty/, '未保存销售日期时禁止进入收款步骤')
assert.match(workbench, /action: 'update-checkout-sales-date'/, '销售日期必须提交正式结账草稿命令')
assert.match(workbench, /createCashierV3CommandId\('CHECKOUT'\)/, '销售日期必须使用已登记的结账幂等前缀')
assert.match(manifest, /'update-checkout-sales-date': FEATURE_CASHIER/, '销售日期命令必须进入动作清单')

console.log('cashier checkout sales-date frontend contract: PASS')

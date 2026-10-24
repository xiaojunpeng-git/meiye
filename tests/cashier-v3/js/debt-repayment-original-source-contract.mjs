import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const read = relative => fs.readFileSync(path.join(root, relative), 'utf8')
const shell = read('前端代码/cashier-v3/src/layouts/CashierShell.vue')
const workbench = read('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue')
const overlay = read('前端代码/cashier-v3/src/components/cashier/CashierCheckoutOverlay.vue')
const debtOverlay = read('前端代码/cashier-v3/src/components/member/MemberDebtOverlay.vue')

// R57 defaults are per-business policy, never the original sale/recharge.
assert.match(debtOverlay, /Number\(initialRepaymentSource\.value\.primarySourceId/, '补交按统一策略初始化')
assert.doesNotMatch(debtOverlay, /record\.originalBusinessSource\?\.primarySourceId/, '手动模式不得继承原单来源')
assert.match(debtOverlay, /请选择本次补交的客户来源/, '下一步必须拦截空来源')
assert.match(debtOverlay, /loadCheckoutBusinessCatalog/, '选择只能来自正式来源目录')
assert.match(shell, /const source = payload\.businessSource \|\| \{\}/, '交接必须使用欠款弹窗已选来源')
assert.match(workbench, /sourceSelectable: false/, '收款确认页不能二次修改补交来源')
assert.match(workbench, /preview\.businessType === 'debt_repayment'/, '本地来源更新不得改写补交来源')
assert.match(workbench, /source: isRecord\(snapshot\.source\)/, '恢复补交时不得用工具栏来源覆盖已选来源')
assert.match(overlay, /本次补交来源（已确认）/, '收款确认应展示本次补交来源')

console.log('debt repayment selected source frontend contract: PASS')

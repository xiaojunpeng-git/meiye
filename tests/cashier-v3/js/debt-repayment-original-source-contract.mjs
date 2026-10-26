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

// 原单来源是默认值；历史空来源必须由操作员选定，结账页不能再悄悄改写。
assert.match(debtOverlay, /record\.originalBusinessSource\?\.primarySourceId/, '去还款必须默认原单来源')
assert.match(debtOverlay, /请选择本次补交的客户来源/, '下一步必须拦截空来源')
assert.match(debtOverlay, /loadCheckoutBusinessCatalog/, '选择只能来自正式来源目录')
assert.match(shell, /const source = payload\.businessSource \|\| \{\}/, '交接必须使用欠款弹窗已选来源')
assert.match(workbench, /sourceSelectable: false/, '收款确认页不能二次修改补交来源')
assert.match(workbench, /preview\.businessType === 'debt_repayment'/, '本地来源更新不得改写补交来源')
assert.match(workbench, /source: isRecord\(snapshot\.source\)/, '恢复补交时不得用工具栏来源覆盖已选来源')
assert.match(overlay, /本次补交来源（已确认）/, '收款确认应展示本次补交来源')

console.log('debt repayment selected source frontend contract: PASS')

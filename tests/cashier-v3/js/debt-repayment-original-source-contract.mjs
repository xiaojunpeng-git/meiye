import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const read = relative => fs.readFileSync(path.join(root, relative), 'utf8')
const shell = read('前端代码/cashier-v3/src/layouts/CashierShell.vue')
const workbench = read('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue')
const overlay = read('前端代码/cashier-v3/src/components/cashier/CashierCheckoutOverlay.vue')

// 两种欠款从同一个去还款入口进入，都只能使用原单快照。
assert.match(shell, /payload\.record\?\.originalBusinessSource \|\| \{\}/, '去还款交接必须从原单读取来源')
assert.match(workbench, /sourceSelectable: false/, '补交结账来源必须不可选择')
assert.match(workbench, /preview\.businessType === 'debt_repayment'/, '本地来源更新不得改写补交来源')
assert.match(workbench, /source: isRecord\(snapshot\.source\)/, '恢复补交时不得用工具栏来源覆盖原单来源')
assert.match(overlay, /原单来源（不可修改）/, '收款确认应展示只读原单来源')

console.log('debt repayment original source frontend contract: PASS')

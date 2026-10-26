import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = resolve(fileURLToPath(new URL('.', import.meta.url)), '../../..')
const overlay = await readFile(resolve(root, '前端代码/cashier-v3/src/components/member/MemberDebtOverlay.vue'), 'utf8')
const shell = await readFile(resolve(root, '前端代码/cashier-v3/src/layouts/CashierShell.vue'), 'utf8')

assert.match(overlay, /businessDate:\s*\{\s*type:\s*String/, '欠款补交必须接收当前业务日期')
assert.match(overlay, /member-debt-overlay__repayment-context/, '业务日期与销售人分配必须并列呈现')
assert.match(overlay, /<label for="member-debt-repayment-business-date">业务日期<\/label>/, '欠款补交必须展示业务日期')
assert.match(overlay, /type="date"[^>]*aria-label="补交业务日期"/, '欠款补交业务日期必须可选择')
assert.match(overlay, /businessDate:\s*repaymentBusinessDate\.value/, '补交草稿必须提交操作员选择的业务日期')
assert.match(shell, /businessDate: String\(payload\.businessDate \|\| toolbarBusinessDate\.value \|\| ''\)/, '收银壳不得用工具栏日期覆盖补交弹层选择的日期')
assert.match(overlay, /performanceIndependent: Boolean\(item\.performanceIndependent\)/, '本次选择必须保留独立核算展示元数据')
assert.match(overlay, /performanceAmountCents: Math\.max\(0, Math\.trunc\(Number\(item\.performanceAmountCents \|\| 0\)\)\)/, '补交提交必须携带手填业绩金额')
assert.match(overlay, /performanceAmountManual: Boolean\(item\.performanceAmountManual\)/, '补交提交必须标识手填业绩金额')
assert.match(overlay, /:performance-base-amount-cents="Number\(repayAmount \|\| 0\) \* 100"/, '补交销售人选择必须以当前补交金额计算业绩金额')
assert.doesNotMatch(overlay, /销售人分配比例合计必须为 100%/, '前端不得以全局 100% 阻断独立核算职位')
assert.match(shell, /:business-date="toolbarBusinessDate"/, '欠款补交日期必须与收银工具栏一致')

console.log('MEMBER_DEBT_SALESPERSON_ALLOCATION_CONTRACT_OK')

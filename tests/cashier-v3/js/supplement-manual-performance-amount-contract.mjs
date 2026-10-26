import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = resolve(fileURLToPath(new URL('.', import.meta.url)), '../../..')
const orderCenter = await readFile(resolve(root, '前端代码/cashier-v3/src/views/OrderCenterView.vue'), 'utf8')

assert.match(orderCenter, /performanceAmountCents: Math\.max\(0, Math\.trunc\(Number\(item\.performanceAmountCents \|\| 0\)\)\)/, '历史补交业绩调整必须提交手填金额')
assert.match(orderCenter, /performanceAmountManual: Boolean\(item\.performanceAmountManual\)/, '历史补交业绩调整必须提交手填标记')

console.log('SUPPLEMENT_MANUAL_PERFORMANCE_AMOUNT_CONTRACT_OK')

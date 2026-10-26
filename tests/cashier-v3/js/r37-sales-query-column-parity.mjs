// 固定消费明细表头必须全部注册查询字段；保护查询改动不能顺带修改写操作函数。
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
const path = '前端代码/cashier-v3/src/views/OrderCenterView.vue'
const source = readFileSync(path, 'utf8')
const sales = source.slice(source.indexOf('const ORDER_TABS'), source.indexOf("key: 'recharge'"))
const fields = new Map([...sales.matchAll(/field\('([^']+)', '([^']+)'/g)].map((m) => [m[1], m[2]]))
const keys = source.match(/const salesOrderListColumnKeys = \[([^\]]+)\]/)[1].match(/'[^']+'/g).map((s) => s.slice(1,-1))
const contract = readFileSync('后端代码/app/services/cashier/v3/order/CashierV3OrderCenterUnifiedQueryContract.php','utf8').split('$definitions = [')[1].split("'recharge' =>")[0]
for (const key of keys) {
  assert.ok(fields.has(key), `missing front field ${key}`)
  assert.ok(contract.includes(`['${key}', '${fields.get(key)}'`), `backend label mismatch ${key}`)
}
// R38 首屏参数修复由 r38-initial-query 独立行为测试覆盖；其余函数继续逐字保护，尤其所有写操作。
const baseline = execFileSync('git',['show',`7ee36755:${path}`],{encoding:'utf8'})
const functions = (text) => [...text.matchAll(/^(?:async )?function [\s\S]*?^}/gm)].map((m)=>m[0]).filter((body) => !body.startsWith('function initialOrderCenterQuery('))
assert.deepEqual(functions(source), functions(baseline))
console.log(`PASS: ${keys.length} table columns registered with matching labels; all page operation functions unchanged`)

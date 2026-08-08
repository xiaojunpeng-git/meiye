import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const source = fs.readFileSync(path.join(root, '前端代码/cashier-v3/src/components/member/DirectGiftOverlay.vue'), 'utf8')

assert.match(source, /\['project', '项目'\].*\['product', '产品'\].*\['coupon', '优惠券'\]/s, '赠送清单必须允许项目、产品、优惠券混合选择')
assert.match(source, /existing\.quantity = Math\.min\(999, Number\(existing\.quantity \|\| 0\) \+ 1\)/, '同一内容重复点击必须累加数量而不是移除')
assert.match(source, /function removeSelected\(item\)/, '已选内容必须通过独立移除操作删除')
assert.match(source, /v-model="item\.quantity"/, '每项赠送数量必须可编辑')
assert.match(source, /v-model="item\.validityEnd" type="date"/, '每项赠送内容必须独立设置有效期')
assert.match(source, /validityEnd: item\.validityEnd \|\| ''/, '提交时必须按明细携带有效期')
assert.doesNotMatch(source, /const validityEnd = ref\('/, '赠送弹层不得再使用全局有效期')
console.log('cashier direct-gift batch frontend contract: PASS')

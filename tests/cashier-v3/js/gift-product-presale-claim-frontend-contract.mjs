import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const source = fs.readFileSync(path.join(root, '前端代码/cashier-v3/src/views/PresaleClaimView.vue'), 'utf8')

assert.match(source, /const sourceKind = ref\('PRESALE'\)/, '默认筛选必须是预售')
assert.match(source, /预售[\s\S]*赠送/, '页面必须提供预售和赠送两个来源选项')
assert.match(source, /source_kind: sourceKind\.value/, '查询必须把来源传给服务端')
assert.match(source, /source-claim-source-switch__thumb|presale-claim-source-switch__thumb/, '来源选项必须使用滑块视觉状态')
assert.match(source, /赠送产品出库单/, '赠送领用成功提示必须说明生成赠送出库单')
assert.match(source, /作废领用/, '赠送和预售必须都支持逐条作废')
console.log('cashier gift-product presale-claim frontend contract: PASS')

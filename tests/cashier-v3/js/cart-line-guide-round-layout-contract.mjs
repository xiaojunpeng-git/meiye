import assert from 'node:assert/strict'
import fs from 'node:fs'
import vm from 'node:vm'

// 从实际购物车摘要函数取出纯展示逻辑，避免测试自造一套轮次算法。
const root = new URL('../../../', import.meta.url)
const source = fs.readFileSync(new URL('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue', root), 'utf8')
const css = fs.readFileSync(new URL('前端代码/cashier-v3/src/styles/base.css', root), 'utf8')
const start = source.indexOf('function attributionDisplaySummary(')
const end = source.indexOf('\nasync function confirmPreviewCardOperation(', start)
assert.ok(start >= 0 && end > start, '购物车归属摘要函数存在')
const context = { localPersonnelAssignments: { value: {} } }
vm.runInNewContext(source.slice(start, end), context)

const line = {
  id: 'line-1',
  guideSelections: [{ name: '李倩', guideRoundNo: 2 }],
  salesManagerSelections: [{ name: '李倩' }]
}
assert.equal(context.groupAttributionDisplaySummary(line), '李倩(第2轮)、李倩', '只给导购标记所选轮次')
assert.equal(context.groupAttributionDisplaySummary({ ...line, guideSelections: [{ name: '李倩', guideRoundNo: 0 }] }),
  '李倩(无轮次)、李倩', '明确选择“无”时不伪造第0轮')
assert.equal(context.groupAttributionDisplaySummary({ ...line, guideSelections: [{ name: '李倩' }] }),
  '李倩、李倩', '旧记录缺轮次时不推断')
context.localPersonnelAssignments.value['line-1'] = { guideSelections: [{ name: '王明', guideRoundNo: 3 }] }
assert.equal(context.groupAttributionDisplaySummary(line), '王明(第3轮)、李倩', '本地未提交展示优先使用当前行选择')

assert.match(css, /\.cart-line__meta-slot--attribution,\s*\.cart-line__meta-slot--coupon\s*\{ transform: translateX\(calc\(-50% \+ 20px\)\); \}/,
  '较窄四列布局保留欠款文字间隙')
assert.match(css, /@container \(min-width: 750px\)\s*\{\s*\.cart-line__meta-slot--attribution,\s*\.cart-line__meta-slot--coupon\s*\{ transform: translateX\(-50%\); \}/,
  '宽屏导购与优惠券一起左移半列')
assert.match(css, /@container \(max-width: 630px\)[\s\S]*?\.cart-line__meta-slot--attribution,\s*\.cart-line__meta-slot--coupon\s*\{ transform: none; \}/,
  '两列布局恢复原位')

console.log('CART_LINE_GUIDE_ROUND_LAYOUT_CONTRACT=PASS')

import assert from 'node:assert/strict'
import fs from 'node:fs'

// 只执行购物车人员摘要的纯展示函数；用已提交的本地回执验证姓名来源，不写草稿。
const workbench = fs.readFileSync(new URL('../../../前端代码/cashier-v3/src/views/CashierWorkbenchView.vue', import.meta.url), 'utf8')
const css = fs.readFileSync(new URL('../../../前端代码/cashier-v3/src/styles/base.css', import.meta.url), 'utf8')
const labelStart = workbench.indexOf('const emptyCartSummaries =')
const labelEnd = workbench.indexOf('\nconst isServiceOrder =', labelStart)
assert.ok(labelStart >= 0 && labelEnd > labelStart, '购物车操作摘要函数必须存在')
const { cartActionValue, cartActionLabel } = new Function(
  `${workbench.slice(labelStart, labelEnd)}\nreturn { cartActionValue, cartActionLabel }`
)()
assert.equal(cartActionValue('老友答谢券'), '老友答谢券')
assert.equal(cartActionValue('未选择'), '')
assert.equal(cartActionLabel('优惠券', '未选择'), '优惠券')
assert.equal(cartActionLabel('优惠券', '老友答谢券'), '优惠券:老友答谢券')
const start = workbench.indexOf('function craftsmenDisplayValue(')
const end = workbench.indexOf('\nfunction attributionDisplaySummary(', start)
assert.ok(start >= 0 && end > start, '手艺人和销售人摘要函数必须存在')
const localPersonnelAssignments = { value: {} }
const { craftsmenDisplayValue, craftsmenDisplaySummary, salespersonDisplaySummary } = new Function(
  'localPersonnelAssignments',
  'hasCartLineCraftsmen',
  `${workbench.slice(start, end)}\nreturn { craftsmenDisplayValue, craftsmenDisplaySummary, salespersonDisplaySummary }`
)(localPersonnelAssignments, (line) => Array.isArray(line.craftsmen) && line.craftsmen.length > 0)

const crafts = [
  { name: '王盼盼' },
  { staffName: '李美玲', isPointCustomer: true },
  { name: '张晓丽' }
]
const sales = [
  { name: '王盼盼', isPreSale: true },
  { staffName: '李美玲' },
  { name: '张晓丽' }
]
assert.equal(craftsmenDisplaySummary({ id: 'c0' }), '待选择手艺人')
assert.equal(craftsmenDisplaySummary({ id: 'c1', craftsmen: crafts.slice(0, 1) }), '手艺人:王盼盼(轮)')
assert.equal(craftsmenDisplaySummary({ id: 'c2', craftsmen: crafts.slice(0, 2) }), '手艺人:王盼盼(轮)、李美玲(点)')
assert.equal(craftsmenDisplaySummary({ id: 'c3', craftsmen: crafts }), '手艺人:王盼盼(轮)、李美玲(点)等3人')
assert.equal(craftsmenDisplayValue({ id: 'c3', craftsmen: crafts }), '王盼盼(轮)、李美玲(点)等3人')
assert.equal(salespersonDisplaySummary({ id: 's0' }), '')
assert.equal(salespersonDisplaySummary({ id: 's1', salespeople: sales.slice(0, 1) }), '王盼盼(售前)')
assert.equal(salespersonDisplaySummary({ id: 's2', salespeople: sales.slice(0, 2) }), '王盼盼(售前)、李美玲')
assert.equal(salespersonDisplaySummary({ id: 's3', salespeople: sales }), '王盼盼(售前)、李美玲等3人')

localPersonnelAssignments.value.saved = { craftsmen: crafts.slice(0, 2), salespeople: sales.slice(0, 2) }
assert.equal(craftsmenDisplaySummary({ id: 'saved', craftsmen: crafts.slice(0, 1) }), '手艺人:王盼盼(轮)、李美玲(点)')
assert.equal(salespersonDisplaySummary({ id: 'saved', salespeople: sales.slice(0, 1) }), '王盼盼(售前)、李美玲')
assert.match(css, /\.cart-line__meta-slot--salesperson \.cart-line__meta-action,[\s\S]*?\.cart-line__meta-slot--craftsmen \.cart-line__meta-action \{[^}]*white-space: normal;[^}]*overflow-wrap: anywhere;/, '窄列应允许两位姓名换行显示')
assert.match(workbench, /:title="craftsmenDisplaySummary\(line\)"/, '手艺人悬停标题不能重复加前缀')
// 操作名和冒号继续使用按钮蓝色；只有已选摘要落在黑色 span 中。
for (const label of ['销售人', '导购/销售经理', '优惠券', '手艺人']) {
  assert.match(workbench, new RegExp(`${label}<template v-if="[^\"]+">:<span class="cart-line__meta-value">|${label}:<span class="cart-line__meta-value">`), `${label}的已选摘要应单独着色`)
}
assert.match(css, /\.cart-line__meta-action \.cart-line__meta-value \{[^}]*color: #303133;/, '已选摘要应为黑色')

console.log('CART_LINE_PERSONNEL_SUMMARY_CONTRACT=PASS')

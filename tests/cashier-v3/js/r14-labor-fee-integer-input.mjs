import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const here = path.dirname(fileURLToPath(import.meta.url))
const root = path.resolve(here, '../../..')
const component = fs.readFileSync(
  path.join(root, '前端代码/cashier-v3/src/components/cashier/PersonnelPerformanceOverlay.vue'),
  'utf8'
)
const baseCss = fs.readFileSync(
  path.join(root, '前端代码/cashier-v3/src/styles/base.css'),
  'utf8'
)

const blockSource = component.match(/function blockNonDigitLaborFeeInput\(event\) \{[\s\S]*?\n\}/)?.[0]
const updateSource = component.match(/function updateWholeYuanLaborFee\(item = \{\}, event\) \{[\s\S]*?\n\}/)?.[0]
assert.ok(blockSource && updateSource, '手工费整数输入函数必须存在')

// Use the real shared eligibility helpers rather than assuming every employee can edit fees.
const eligibilitySource = component.slice(component.indexOf('function configuredCraftsmanType'), component.indexOf('function defaultLaborFeeCents'))
const handlers = new Function(`${eligibilitySource}\n${blockSource}\n${updateSource}\nreturn { blockNonDigitLaborFeeInput, updateWholeYuanLaborFee }`)()
for (const data of ['.', '3.5', '-', '+', 'e']) {
  let blocked = false
  handlers.blockNonDigitLaborFeeInput({ data, preventDefault() { blocked = true } })
  assert.equal(blocked, true, `${data} 必须在写入输入框前被拦截`)
}

let blocked = false
handlers.blockNonDigitLaborFeeInput({ data: '3', preventDefault() { blocked = true } })
assert.equal(blocked, false, '整数数字必须允许录入')

const item = { laborFeeYuan: 3, laborFeeCents: 300 }
const validInput = { value: '12' }
handlers.updateWholeYuanLaborFee(item, { target: validInput })
assert.deepEqual(item, { laborFeeYuan: 12, laborFeeCents: 1200 }, '12 元必须换算为 1200 分')

const invalidInput = { value: '12.5' }
handlers.updateWholeYuanLaborFee(item, { target: invalidInput })
assert.equal(invalidInput.value, '12', '绕过输入前拦截的小数必须恢复最近的整数值')
assert.deepEqual(item, { laborFeeYuan: 12, laborFeeCents: 1200 }, '非法小数不得改变待保存手工费')

const emptyInput = { value: '' }
handlers.updateWholeYuanLaborFee(item, { target: emptyInput })
assert.deepEqual(item, { laborFeeYuan: '', laborFeeCents: 0 }, '清空输入必须清除待保存手工费')

const laborInput = component.split('\n').find((line) => line.includes('aria-label="每人手工费"')) || ''
for (const token of [
  'type="text"',
  'inputmode="numeric"',
  'pattern="[0-9]*"',
  '@beforeinput="blockNonDigitLaborFeeInput"',
  '@input="updateWholeYuanLaborFee(item, $event)"'
]) {
  assert.ok(laborInput.includes(token), `手工费控件缺少整数输入边界：${token}`)
}
assert.match(component, /personnel-allocation-input--project[\s\S]*?step="any"[\s\S]*?inputmode="decimal"/, '项目数继续保留小数输入能力')

// 完整分配有十列业务字段：宽屏必须同屏对齐，小屏只允许表格区域滚动，
// 不能让表格撑乱弹窗或把底部操作按钮推出视口。
assert.match(component, /personnel-performance-panel--full': mode === 'full'/, '完整分配必须启用专用宽版弹窗')
assert.match(component, /<div class="personnel-full-table-scroll">[\s\S]*?<div class="personnel-full-table"/, '完整分配表格必须具有独立滚动容器')
assert.match(baseCss, /\.personnel-performance-panel--full\s*\{[\s\S]*?width:\s*min\(1460px, calc\(100vw - 48px\)\)/, '完整分配宽屏宽度必须覆盖十列字段')
assert.match(baseCss, /\.personnel-full-table-scroll\s*\{[\s\S]*?min-height:\s*0;[\s\S]*?overflow:\s*auto;/, '表格区域必须独立滚动')
assert.match(baseCss, /\.personnel-full-table--craftsmen\s*\{[\s\S]*?min-width:\s*1120px;/, '手艺人十列表格必须保留稳定最小宽度')
assert.match(baseCss, /\.personnel-allocation-input--labor input\s*\{[\s\S]*?width:\s*68px;/, '手工费输入框必须使用固定列内宽度')

console.log('PASS r14 labor fee integer input and allocation layout')

import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { numericRangePresets, numericRangePresetMode, numericRangePresetFilters } from '../../../前端代码/shared/unified-query-vue3/src/contracts/numericRangePresets.js'

// 快捷模式只生成服务端条件；边界 0、单边、自定义小数和切换后的旧区间均不得被前端误算。
const field = 'available_quantity'
const filters = (mode, range) => numericRangePresetFilters(field, mode, range)
assert.deepEqual(numericRangePresets.map(x => x.label), ['大于0', '等于0', '自定义', '全部'])
assert.equal(numericRangePresetMode(undefined), 'positive')
assert.equal(numericRangePresetMode('invalid'), 'positive')
assert.deepEqual(filters('positive'), [{ field, operator: 'gt', value: '0' }])
assert.deepEqual(filters('zero'), [{ field, operator: 'eq', value: '0' }])
assert.deepEqual(filters('all'), [{ field, operator: 'gte', value: '0' }])
assert.deepEqual(filters('custom', { min: '130', max: '200' }), [{ field, operator: 'gte', value: '130' }, { field, operator: 'lte', value: '200' }])
assert.deepEqual(filters('custom', { min: 0, max: '' }), [{ field, operator: 'gte', value: 0 }])
assert.deepEqual(filters('custom', { min: '', max: '0' }), [{ field, operator: 'lte', value: '0' }])
assert.deepEqual(filters('custom', { min: '0.25', max: '2.50' }), [{ field, operator: 'gte', value: '0.25' }, { field, operator: 'lte', value: '2.50' }])
assert.deepEqual(filters('custom'), filters('all'))
assert.deepEqual(filters('positive', { min: '200', max: '130' }), filters('positive'))
assert.deepEqual(filters('zero', { min: '130', max: '200' }), filters('zero'))
assert.deepEqual(filters('all', { min: '130', max: '200' }), filters('all'))
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const app = fs.readFileSync(path.join(root, '前端代码/inventory-vue3/src/App.vue'), 'utf8')
const component = fs.readFileSync(path.join(root, '前端代码/shared/unified-query-vue3/src/components/UnifiedQueryNumericRangePresets.vue'), 'utf8')
assert.ok(app.includes("mode.value === 'store' && field.key === 'available_quantity'"))
assert.ok(app.includes("{ label: '库存数量', quickRangePresets: true }"))
assert.ok(component.includes('v-if="mode === \'custom\'"'))
assert.ok(component.includes(':aria-pressed="mode === option.value"'))
assert.ok(component.includes(':aria-label="option.label"'))
console.log('STOCK_QUANTITY_PRESETS_CONTRACT_OK checks=19')

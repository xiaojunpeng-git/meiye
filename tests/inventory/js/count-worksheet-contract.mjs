import assert from 'node:assert/strict'
import { exportCountCsv, parseCountCsv, COUNT_COLUMNS } from '../../../前端代码/inventory-vue3/src/components/countWorksheet.js'

// Export contains exactly the visible count columns and never truncates a large draft.
const rows = Array.from({ length: 151 }, (_, index) => [
  String(index + 1), index === 0 ? '=测试,"商品"' : `商品${index + 1}`, '规格', '0000123',
  '0', '0', '0', '', '', '', ''
])
const exported = exportCountCsv(rows)
const restored = parseCountCsv(exported)
assert.deepEqual(restored, rows)
assert.deepEqual(COUNT_COLUMNS, ['商品ID', '商品名称', '商品规格', '商品条码', '账面库存', '实盘库存', '库存盈亏', '盘盈批次号', '盘盈单价', '生产日期', '到期日'])
assert.equal(restored.length, 151)
assert.throws(() => parseCountCsv(exported.replace('商品ID', '内部ID')), /表头/)
assert.throws(() => parseCountCsv(exported.slice(0, -1)), /引号/)
console.log('COUNT_WORKSHEET_CONTRACT_OK')

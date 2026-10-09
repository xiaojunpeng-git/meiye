import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

// 固定两端共用的导入/确认接线：历史账面和文件盈亏不得成为实盘提交门禁。
const modal = readFileSync(new URL('../../../前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue', import.meta.url), 'utf8')
const importer = modal.slice(modal.indexOf('async function importCountRows('), modal.indexOf('async function loadSupplyParties('))
assert.doesNotMatch(importer, /Number\(cells\[5\]\)|Number\(cells\[7\]\)|账面库存已变化/)
assert.match(importer, /book_quantity: String\(mode === 'BATCH' \? \(batch\?\.quantity \?\? 0\) : \(sku.available_quantity \?\? 0\)\), counted_quantity: cells\[6\]/)
assert.match(modal, /lines: physicalRows\.map/)
// 页面只预检预计盘盈资料；所有实盘都提交，不能把快照当作服务端结算门禁。
assert.match(modal, /validateCountSubmissionRows\(selectedRows.value\)/)
const backend = readFileSync(new URL('../../../后端代码/app/services/product/inventory/InventoryStockCountServices.php', import.meta.url), 'utf8')
const confirmLine = backend.slice(backend.indexOf('private function confirmLine('), backend.indexOf('private function countUnits('))
assert.doesNotMatch(confirmLine, /countUnits\(\$line\['expectedBook'\]/)
assert.match(confirmLine, /\$difference = \$counted - \$book/)
assert.doesNotMatch(backend, /inventory_stock_count_no_difference/)
assert.doesNotMatch(confirmLine, /if \(\$difference === 0\) return/)
assert.match(backend, /where\('version', \(int\)\$stock\['version'\]\)/)
console.log('COUNT_PHYSICAL_AUTHORITY_CONTRACT_OK')

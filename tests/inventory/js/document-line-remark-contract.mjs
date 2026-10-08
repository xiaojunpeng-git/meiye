import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const root = new URL('../../../', import.meta.url)
const read = (path) => readFileSync(new URL(path, root), 'utf8')
const modal = read('前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue')
const upgrade = read('后端代码/database/upgrades/2026-10-08-库存单据明细备注/01-正式升级.sql')
const inventory = '后端代码/app/services/product/inventory/'

// 备注是每条业务明细的快照，提交、数据库和详情读取必须成对覆盖五类单据。
for (const table of [
  'eb_inventory_batch_movement_fact',
  'eb_inventory_stock_request_line',
  'eb_inventory_cross_transfer_line',
  'eb_inventory_salon_usage_line',
]) assert.ok(upgrade.includes(`TABLE_NAME='${table}'`), `missing ${table} migration`)

for (const service of [
  'InventoryManualInboundServices.php', 'InventoryManualOutboundServices.php',
  'InventoryStockRequestServices.php', 'InventoryCrossSubjectTransferServices.php',
  'InventorySalonUsageServices.php',
]) assert.ok(read(inventory + service).includes("$line['detail_remark']"), `missing ${service} input`)

for (const reader of [
  'InventoryManualInboundReadServices.php', 'InventoryMovementQueryServices.php',
  'InventoryPlatformHqInboundServices.php', 'InventoryPlatformHqOutboundServices.php',
  'InventoryStockRequestQueryServices.php', 'InventorySalonUsageServices.php',
]) assert.ok(read(inventory + reader).includes('detail_remark'), `missing ${reader} projection`)

assert.ok(read(inventory + 'completion/InventoryBatchMovementFactServices.php').includes("'detail_remark' => $movement['detailRemark']"))
assert.ok(modal.includes("columns[cellIndex] === '明细备注'"))
assert.ok(modal.includes('v-model="selectedRows[rowIndex].detail_remark"'))
assert.equal((modal.match(/detail_remark: String\(row\.detail_remark \|\| ''\)/g) || []).length, 5)
assert.ok(modal.includes("if (props.pageKey === 'count') return [...product, '商品条码'"))
for (const page of ['inbound', 'outbound', 'request', 'transfer', 'usage']) {
  const branch = modal.split(`pageKey === '${page}' && detail`)[1]?.split('</template>')[0]
  assert.ok(branch?.includes('<th>明细备注</th>'), `${page} detail is missing remark`)
  assert.ok(!branch.includes('<th>条码</th>'), `${page} detail still shows barcode`)
}

console.log('INVENTORY_DOCUMENT_LINE_REMARK_CONTRACT_OK')

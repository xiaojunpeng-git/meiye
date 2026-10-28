import fs from 'node:fs'
import { inventoryStatusLabel } from '../../../前端代码/inventory-vue3/src/statusLabels.js'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const here = path.dirname(fileURLToPath(import.meta.url))
const root = path.resolve(here, '../../..')
const labels = fs.readFileSync(path.join(root, '前端代码/inventory-vue3/src/statusLabels.js'), 'utf8')
const app = fs.readFileSync(path.join(root, '前端代码/inventory-vue3/src/App.vue'), 'utf8')
const modal = fs.readFileSync(path.join(root, '前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue'), 'utf8')
const checks = [
  ['SETTLED is projected to Chinese text', labels.includes("SETTLED: '已结算'")],
  ['known and unknown statuses remain Chinese while historical Chinese text stays intact', inventoryStatusLabel('SETTLED') === '已结算' && inventoryStatusLabel('NEW_INTERNAL_ENUM') === '未知状态' && inventoryStatusLabel('历史状态') === '历史状态' && inventoryStatusLabel(null) === '-'],
  ['inventory list rows use the status projection', app.includes('inventoryStatusLabel(row.status_name') && app.includes('inventoryStatusLabel(row.document_status')],
  ['inventory detail uses the same status projection', modal.includes('function detailStatus(detail') && modal.includes('detailStatus(detail.document)')]
]
let failed = 0
for (const [name, ok] of checks) {
  console.log(`${ok ? 'PASS' : 'FAIL'} ${name}`)
  if (!ok) failed += 1
}
if (failed) process.exit(1)

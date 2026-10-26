import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const here = path.dirname(fileURLToPath(import.meta.url))
const root = path.resolve(here, '../../..')
const labels = fs.readFileSync(path.join(root, '前端代码/inventory-vue3/src/statusLabels.js'), 'utf8')
const app = fs.readFileSync(path.join(root, '前端代码/inventory-vue3/src/App.vue'), 'utf8')
const modal = fs.readFileSync(path.join(root, '前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue'), 'utf8')
const checks = [
  ['SETTLED is projected to Chinese text', labels.includes("SETTLED: '已结算'")],
  ['status projection preserves unknown non-empty values', labels.includes('return inventoryStatusLabels[raw.toUpperCase()] || raw')],
  ['inventory list rows use the status projection', app.includes('inventoryStatusLabel(row.status_name') && app.includes('inventoryStatusLabel(row.document_status')],
  ['inventory detail uses the same status projection', modal.includes('function detailStatus(detail') && modal.includes('detailStatus(detail.document)')]
]
let failed = 0
for (const [name, ok] of checks) {
  console.log(`${ok ? 'PASS' : 'FAIL'} ${name}`)
  if (!ok) failed += 1
}
if (failed) process.exit(1)

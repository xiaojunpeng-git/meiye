import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const app = fs.readFileSync(path.join(root, '前端代码/inventory-vue3/src/App.vue'), 'utf8')

const required = [
  "columns: ['出库单号', '出库日期'",
  "'状态', '操作日期', '操作人']",
  "field: 'business_date', operator: 'between'",
  "outbound: '出库日期周期'",
  'isOperationalStatusCell(active, cellIndex)',
  'text(row.business_date)',
  'text(row.operator_name)',
]
for (const token of required) {
  if (!app.includes(token)) throw new Error(`missing operation audit UI contract: ${token}`)
}
console.log('operation audit columns UI contract passed')

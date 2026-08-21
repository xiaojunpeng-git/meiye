import fs from 'node:fs'

const root = decodeURIComponent(new URL('../../', import.meta.url).pathname)
const read = (path) => fs.readFileSync(`${root}/${path}`, 'utf8')
const reportRoutes = read('前端代码/admin/src/router/modules/report.js')
const api = read('前端代码/cashier-v3/src/services/engineeringLedgerApi.js')
const view = read('前端代码/cashier-v3/src/views/EngineeringManagementView.vue')
const shell = read('前端代码/cashier-v3/src/layouts/CashierShell.vue')
const management = read('前端代码/cashier-v3/src/views/ManagementCenterView.vue')

for (const type of ['store_building', 'engineering_quality', 'engineering_repair', 'rent_renewal']) {
  if (!reportRoutes.includes(`'${type}'`)) throw new Error(`missing platform route type: ${type}`)
}
for (const path of ['/list', '/save', '/export']) {
  if (!api.includes(path)) throw new Error(`missing API path: ${path}`)
}
if (api.includes("'/records'")) throw new Error('stale engineering ledger API path')
if (!view.includes('建店明细') || !view.includes('工程质量') || !view.includes('工程维修') || !view.includes('降租续签')) {
  throw new Error('missing four ledger forms')
}
if (!view.includes('result?.columns') || !view.includes('result?.allowed_store_ids')) {
  throw new Error('ledger view does not consume the API column or organization-scope contract')
}
for (const token of ['start_date', 'end_date', 'pagination', 'load(1)']) {
  if (!view.includes(token)) throw new Error(`missing list interaction: ${token}`)
}
if (view.includes('activeSchema.slice(0, 8)')) throw new Error('ledger table truncates form columns')
if (!shell.includes("key: 'management'") || !management.includes('EngineeringManagementView')) {
  throw new Error('engineering management is not under the management menu')
}
console.log('business-ledger frontend contract: PASS')

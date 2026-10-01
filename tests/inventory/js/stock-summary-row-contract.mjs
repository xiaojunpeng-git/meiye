import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { formatIntegerQuantity } from '../../../前端代码/inventory-vue3/src/quantityFormat.js'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const app = fs.readFileSync(path.join(root, '前端代码/inventory-vue3/src/App.vue'), 'utf8')
const css = fs.readFileSync(path.join(root, '前端代码/inventory-vue3/src/styles.css'), 'utf8')

const checks = [
  ['first body row is the server-backed stock total', app.includes('class="table-summary-row"') && app.includes('stockSummaryRow')],
  ['total is never reduced from browser rows', app.includes('never recompute it from browser rows') && !/stockSummaryRow[\s\S]{0,500}\.reduce\(/.test(app)],
  ['table fixed behavior follows backend layout metadata', app.includes("'table-card--stock-scroll': stockTableFixed") && app.includes('stockTableLayout.value.fixed === true')],
  ['amount total displays two decimal yuan from server cents', app.includes('centsMoney(stockSummary.value.inventory_amount_cents)') && app.includes('number.toFixed(2)')],
  ['quantity total removes zero-only fixed-point padding', app.includes('formatIntegerQuantity(stockSummary.value.available_quantity)') && formatIntegerQuantity('1332.000000') === '1332' && formatIntegerQuantity('1332') === '1332'],
  ['quantity total rejects fractional inventory instead of rounding it', formatIntegerQuantity('12.340000') === '-' && formatIntegerQuantity('9007199254740993.000000') === '9007199254740993'],
  ['summary row remains fixed below the fixed header', css.includes('.table-card--stock-scroll .table-summary-row td') && css.includes('top: 43px')],
]

let failed = 0
for (const [name, ok] of checks) {
  console.log(`${ok ? 'PASS' : 'FAIL'} ${name}`)
  if (!ok) failed++
}
console.log(`INVENTORY_STOCK_SUMMARY_ROW_RESULT failed=${failed}`)
process.exit(failed === 0 ? 0 : 1)

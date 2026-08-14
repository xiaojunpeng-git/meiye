import fs from 'node:fs'
import path from 'node:path'

const root = decodeURIComponent(new URL('../../../', import.meta.url).pathname)
const selector = fs.readFileSync(path.join(root, '后端代码/app/services/cashier/v3/member/CashierV3QueryEntitySelectorServices.php'), 'utf8')
const overlay = fs.readFileSync(path.join(root, '前端代码/cashier-v3/src/components/cashier/PersonnelPerformanceOverlay.vue'), 'utf8')
const annotation = fs.readFileSync(path.join(root, '后端代码/app/services/report/StoreOperationsReportAnnotationServices.php'), 'utf8')

for (const needle of [
  'partnerDefaultRatioForProject',
  "'partnerDefaultRatio' => $partnerDefaultRatio",
  'partner_default_ratio',
]) {
  if (!selector.includes(needle) && !annotation.includes(needle)) throw new Error(`missing backend ratio contract: ${needle}`)
}
for (const needle of [
  'salespersonDefaultWeights',
  "employeeTypeCode || '').toLowerCase() === 'partner'",
  'partnerDefaultRatio',
  'performanceTouched',
]) {
  if (!overlay.includes(needle)) throw new Error(`missing frontend ratio contract: ${needle}`)
}
console.log('salesperson partner default ratio contract: PASS')

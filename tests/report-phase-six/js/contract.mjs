import fs from 'node:fs'
import path from 'node:path'
import process from 'node:process'
import { fileURLToPath } from 'node:url'

const repo = process.env.REPO_ROOT || path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const read = (file) => fs.readFileSync(path.join(repo, file), 'utf8')
const admin = read('前端代码/admin/src/libs/phaseSixReports.js')
const router = read('前端代码/admin/src/router/modules/report.js')
const frame = read('前端代码/admin/src/pages/report/data/store_business.vue')
const adminProductionEnv = read('前端代码/admin/.env.production')
const cashier = read('前端代码/cashier-v3/src/constants/phaseSixReports.js')
const view = read('前端代码/cashier-v3/src/views/StoreBusinessReportView.vue')
const reports = [
  ['phase_six_garden_item_analysis', '花园品项分析表'], ['phase_six_monthly_featured_item', '月主推数据统计表'],
  ['phase_six_headquarters_acquisition', '总部拓客数据统计表'], ['phase_six_other_multi_payment', '其他多收款业绩表'],
  ['phase_six_salary_summary', '员工薪资汇总月报表'], ['phase_six_salary_detail', '员工薪资明细月报表'],
  ['phase_six_training_employee', '教培员工需求统计表'], ['phase_six_acquisition_source', '拓客部门客户来源数据分析表'],
  ['phase_six_human_store_health', '人力-院店健康报表']
]
let failed = 0
const check = (name, condition) => { if (condition) console.log(`PASS ${name}`); else { failed += 1; console.error(`FAIL ${name}`) } }
for (const [code, title] of reports) check(`report ${code}`, admin.includes(code) && admin.includes(title) && cashier.includes(code) && cashier.includes(title))
check('platform other reports route', router.includes('other-reports/${report.code}') && router.includes('admin-report-phase-six-${report.code}'))
check('platform frame accepts phase six code', frame.includes('PHASE_SIX_REPORT_CODES') && frame.includes('allowed.includes(requested)'))
// 版本号随每轮发布变化；这里只验证平台 iframe 始终使用非空的部署版本，而非固定旧 SHA。
check('platform frame cache-busts the embedded cashier release',
  frame.includes("VUE_APP_CASHIER_V3_REPORT_VERSION || '7fec5256'")
  && /^VUE_APP_CASHIER_V3_REPORT_VERSION='[^']+'$/m.test(adminProductionEnv))
check('store only exposes three cross-end reports', view.includes('phase_six_other_multi_payment') && view.includes('phase_six_salary_summary') && view.includes('phase_six_salary_detail') && view.includes('PHASE_SIX_CROSS_END_REPORT_TABS'))
check('platform-only phase six reports are blocked in direct store runtime', view.includes('phaseSixBlocked') && view.includes('isPlatformRuntimeRoute()'))
check('embedded report re-syncs route after async catalog load', view.includes('catalog.value = Array.isArray(response) ? response : []') && view.includes('syncActiveReportFromRoute()\n    syncFiltersFromRoute()\n    await loadReport()'))
if (failed) process.exit(1)
console.log('PASS phase-six frontend contract')

import { readFileSync } from 'node:fs'
import { resolve, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'
const sourceRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../../..')
const app = readFileSync(resolve(sourceRoot, '前端代码/fund-vue3/src/App.vue'), 'utf8')
const scopePicker = readFileSync(resolve(sourceRoot, '前端代码/fund-vue3/src/components/FundPlatformStoreScopePicker.vue'), 'utf8')
const vite = readFileSync(resolve(sourceRoot, '前端代码/fund-vue3/vite.config.js'), 'utf8')
const platformRoute = readFileSync(resolve(sourceRoot, '前端代码/admin/src/router/modules/fundManage.js'), 'utf8')
const platformMenuMigration = readFileSync(resolve(sourceRoot, '后端代码/database/upgrades/2026-08-17-费用模块平台权限入口/02-正式升级.sql'), 'utf8')
const integrationBuild = readFileSync(resolve(sourceRoot, 'scripts/build-local-8080-integration.sh'), 'utf8')
for (const text of ['收支单录入', '收支台账', '费用统计与专项报表', "active==='settings'", '撤销审核', '冲销']) {
  if (!app.includes(text)) throw new Error(`资金 Vue3 工作台缺少功能：${text}`)
}
if (!app.includes("v-if=\"platform\"")) throw new Error('平台专属设置未隔离')
if (!app.includes("'Authori-zation':")) throw new Error('资金 Vue3 工作台未传递系统认证请求头')
if (!app.includes("sessionStorage.getItem('cashier-v3:session-token')")) throw new Error('资金 Vue3 工作台未复用门店会话')
if (!app.includes("cookie('admin-token')")) throw new Error('资金 Vue3 工作台未复用平台会话')
if (!app.includes('const reportFilters=ref(') || !app.includes('const ledgerFilters=ref(')) throw new Error('资金 Vue3 台账和报表未隔离筛选状态')
if (!app.includes("subject_type:'NORMAL'") || !app.includes('团建费')) throw new Error('资金 Vue3 台账缺少默认普通费用与团建费筛选')
if (!app.includes('monthStart()') || !app.includes("'期初'")) throw new Error('资金 Vue3 台账缺少月初默认筛选或期初展示行')
if (!app.includes("<th>门店</th><th>日期</th>") || !app.includes("<th>门店</th><th>科目</th>")) throw new Error('资金 Vue3 台账和报表缺少门店列')
if (!app.includes('FundPlatformStoreScopePicker') || !app.includes('requirePlatformStore()') || !scopePicker.includes('组织 / 门店') || !scopePicker.includes('findStore')) throw new Error('资金 Vue3 平台端缺少组织门店必选控件')
for (const text of ['reportFilters', 'income_amount', 'expense_amount', 'net_amount', "exportData('ledger')", "exportData('report')", 'downloadFile', '/export-files/', 'file_key', '费用数据.xlsx']) if (!app.includes(text)) throw new Error(`资金 Vue3 缺少报表筛选、净额或导出：${text}`)
if (!vite.includes("'http://127.0.0.1:18092'") || !vite.includes("'http://127.0.0.1:18093'")) throw new Error('资金 Vue3 本地代理未对接独立门店和平台 API')
if (!platformRoute.includes('/finance/store_fund') || !platformRoute.includes("admin-fund-manage")) throw new Error('平台费用路由未绑定独立权限入口')
if (!platformMenuMigration.includes("admin-store-finance") || !platformMenuMigration.includes("admin-fund-manage") || platformMenuMigration.includes('1043')) throw new Error('平台费用菜单迁移未按权限规则定位门店财务')
if (!integrationBuild.includes('build_vite_app fund-vue3') || !integrationBuild.includes('switch_target view_fund_v3 view_fund_v3')) throw new Error('8080 集成构建未纳入费用前端产物')
console.log('fund-vue3-contract: PASS')

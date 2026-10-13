import { readFileSync } from 'node:fs'
import { resolve, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'
const sourceRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../../..')
const app = readFileSync(resolve(sourceRoot, '前端代码/fund-vue3/src/App.vue'), 'utf8')
const fundStyles = readFileSync(resolve(sourceRoot, '前端代码/fund-vue3/src/styles.css'), 'utf8')
const scopePicker = readFileSync(resolve(sourceRoot, '前端代码/fund-vue3/src/components/FundPlatformStoreScopePicker.vue'), 'utf8')
const vite = readFileSync(resolve(sourceRoot, '前端代码/fund-vue3/vite.config.js'), 'utf8')
const fundPackage = readFileSync(resolve(sourceRoot, '前端代码/fund-vue3/package.json'), 'utf8')
const localStartup = readFileSync(resolve(sourceRoot, 'scripts/start-local.sh'), 'utf8')
const platformRoute = readFileSync(resolve(sourceRoot, '前端代码/admin/src/router/modules/fundManage.js'), 'utf8')
const platformMenuMigration = readFileSync(resolve(sourceRoot, '后端代码/database/upgrades/2026-08-17-费用模块平台权限入口/02-正式升级.sql'), 'utf8')
const integrationBuild = readFileSync(resolve(sourceRoot, 'scripts/build-local-8080-integration.sh'), 'utf8')
for (const text of ['收支单录入', '收支台账', '费用统计与专项报表', "active==='settings'", '撤销审核', '冲销']) {
  if (!app.includes(text)) throw new Error(`资金 Vue3 工作台缺少功能：${text}`)
}
// 门店移除冲销入口，但共享工程的既有平台操作仍保留。
if (!app.includes('v-if="platform&&r.document_status===\'APPROVED\'" class="btn danger" @click="reverse(r)"')) throw new Error('冲销入口必须只在平台端保留')
// 门店编辑入口随服务端审核状态显隐，平台既有入口不随本轮变化。
const editVisibility = app.match(/<button v-if="([^"]+)" class="btn light" @click="edit\(r\)">编辑<\/button>/)?.[1]
if (!editVisibility) throw new Error('编辑按钮缺少审核状态控制')
const showEdit = new Function('platform', 'r', `return ${editVisibility}`)
for (const [platform, status, expected] of [[false,'DRAFT',true],[false,'APPROVED',false],[true,'DRAFT',true],[true,'APPROVED',true]]) {
  if (showEdit(platform, { document_status: status }) !== expected) throw new Error(`编辑按钮边界错误：${platform}/${status}`)
}
// 删除仅对门店未审核单据开放，确认后调用后端，不在页面直接伪造删除结果。
if (!app.includes('v-if="!platform&&r.document_status===\'DRAFT\'"') || !app.includes('@click="deleteDraft(r)"') || !app.includes('aria-label="删除未审核收支单"')) throw new Error('未审核单据删除入口缺失或边界错误')
if (!app.includes('@click="deleteDraft(r)">删除</button>')) throw new Error('单据删除按钮应显示删除两个字')
if (!app.includes("confirm('删除这张未审核收支单？')") || !app.includes("{method:'DELETE'}")) throw new Error('删除缺少确认或真实 DELETE 请求')
if (!app.includes("v-if=\"platform\"")) throw new Error('平台专属设置未隔离')
if (!app.includes("'Authori-zation':")) throw new Error('资金 Vue3 工作台未传递系统认证请求头')
if (!app.includes("sessionStorage.getItem('cashier-v3:session-token')")) throw new Error('资金 Vue3 工作台未复用门店会话')
if (!app.includes("cookie('admin-token')")) throw new Error('资金 Vue3 工作台未复用平台会话')
if (!app.includes('const reportFilters=ref(') || !app.includes('const ledgerFilters=ref(')) throw new Error('资金 Vue3 台账和报表未隔离筛选状态')
if (!app.includes("subject_type:'NORMAL'") || !app.includes('团建费')) throw new Error('资金 Vue3 台账缺少默认普通费用与团建费筛选')
if (!app.includes('monthStart()') || !app.includes("'期初'")) throw new Error('资金 Vue3 台账缺少月初默认筛选或期初展示行')
if (!fundPackage.includes('"@mohe/unified-query-vue3"') || !localStartup.includes('-v "$ROOT/前端代码/shared:/shared:ro"')) throw new Error('费用工程缺少共享 Vue3 周期控件依赖或本地源码挂载')
for (const field of ['documentRange', 'ledgerRange', 'reportRange']) if (!app.includes(`:model-value="${field}"`)) throw new Error(`费用视图缺少统一查询周期：${field}`)
const dateRangeHandler = app.match(/function applyDateRange\(target,\{min,max\}\)\{[^}]+\}/)?.[0]
if (!dateRangeHandler) throw new Error('费用周期回调缺失')
const updateDateRange = new Function(`${dateRangeHandler}; return applyDateRange`)()
const draftFilters = { date_from: '2026-10-01', date_to: '2026-10-07' }
updateDateRange(draftFilters, { min: '2026-09-21', max: '2026-09-29' })
if (draftFilters.date_from !== '2026-09-21' || draftFilters.date_to !== '2026-09-29') throw new Error('选择历史日期后未更新当前视图筛选条件')
if (!app.includes("const filters=ref({date_from:monthStart(),date_to:today()")) throw new Error('收支单没有默认本月日期')
if (!app.includes('page:documentsPage.value,limit:documentsLimit.value') || !app.includes('documentsCount.value=Number(data.count)') || !app.includes('changeDocumentPage(documentsPage+1)')) throw new Error('收支单没有使用服务端分页与总数')
if (!app.includes('documentsLimit=ref(100)') || !app.includes('每页 100 张') || app.includes('changeDocumentLimit')) throw new Error('收支单必须按当前业务规则固定每页 100 张')
if (!app.includes('class="fund-document-group"') || !app.includes('v-for="line in r.lines"') || !app.includes('line.subject_name_snapshot')) throw new Error('收支单没有按单据头和科目明细分组展示')
if (!app.includes('function displayMoney(cents)') || !app.includes('displayMoney(line.amount_cents)')) throw new Error('新增明细金额没有按元四舍五入为整数展示')
if (!app.includes('class="panel entry-panel"') || !app.includes('class="table-wrap entry-table-wrap"')) throw new Error('收支单结果区缺少固定分页与独立滚动布局')
if (!app.includes('class="fund-pagination entry-pagination"') || !app.includes('class="fund-pagination__current">{{documentsPage}}')) throw new Error('收支单分页栏没有沿用会员领用的右侧页码样式')
if (!fundStyles.includes('.entry-panel{display:flex;flex-direction:column;height:calc(100vh - 96px);min-height:420px;overflow:hidden;padding-bottom:0}') || !fundStyles.includes('.entry-panel .fund-pagination.entry-pagination{justify-content:flex-end;margin:0;padding:12px}') || !fundStyles.includes('.entry-pagination>span{margin-right:0}')) throw new Error('收支单分页没有固定置底或未按会员领用样式右对齐')
if (app.includes('<div class="section-head"><h2>收支单</h2>') || !app.includes('<button class="btn filter-submit" @click="searchDocuments"><Search :size="16"/> 查询</button><button class="btn filter-submit" @click="newDocument"><Plus :size="15"/> 新增收支单</button>')) throw new Error('新增收支单应紧邻查询按钮且不保留独立标题行')
if (!app.includes('appliedDocumentFilters.value=scopedFilters({...filters.value})') || !app.includes('...appliedDocumentFilters.value,page:documentsPage.value')) throw new Error('翻页没有沿用上次已查询的筛选条件')
if (!app.includes('ledgerPage=ref(1), ledgerLimit=100, ledgerCount=ref(0)') || !app.includes('page:ledgerPage.value,limit:ledgerLimit') || !app.includes('ledgerCount.value=Number(data.count)') || !app.includes('ledgerPage.value===1&&ledgerOpening.value') || !app.includes('changeLedgerPage(ledgerPage+1)')) throw new Error('台账没有使用固定 100 条服务端分页或错误重复期初行')
if (!app.includes('function searchLedger(){ledgerPage.value=1;return loadLedger()}') || !app.includes('class="fund-pagination ledger-pagination"') || !app.includes('每页 100 条') || !app.includes('class="fund-pagination__current">{{ledgerPage}}') || !app.includes("exportData('ledger')")) throw new Error('台账分页样式、重查归第一页或全量导出边界缺失')
if (!fundStyles.includes('.ledger-panel{display:flex;flex-direction:column;height:calc(100vh - 96px);min-height:420px;overflow:hidden;padding-bottom:0}') || !fundStyles.includes('.ledger-table-wrap{flex:1 1 auto;min-height:0}') || !fundStyles.includes('.ledger-panel .fund-pagination.ledger-pagination{flex:0 0 auto;justify-content:flex-end;margin:0;padding:12px')) throw new Error('台账结果区没有独立滚动或分页没有固定置底')
if (!app.includes("<th>门店</th><th>日期</th>") || !app.includes("<th>门店</th><th>科目</th>")) throw new Error('资金 Vue3 台账和报表缺少门店列')
if (!app.includes('FundPlatformStoreScopePicker') || !app.includes('requirePlatformStore()') || !scopePicker.includes('组织 / 门店') || !scopePicker.includes('findStore')) throw new Error('资金 Vue3 平台端缺少组织门店必选控件')
for (const text of ['reportFilters', 'income_amount', 'expense_amount', 'net_amount', "exportData('ledger')", "exportData('report')", 'downloadFile', '/export-files/', 'file_key', '费用数据.xlsx']) if (!app.includes(text)) throw new Error(`资金 Vue3 缺少报表筛选、净额或导出：${text}`)
if (!vite.includes("'http://127.0.0.1:18092'") || !vite.includes("'http://127.0.0.1:18093'")) throw new Error('资金 Vue3 本地代理未对接独立门店和平台 API')
if (!platformRoute.includes('/finance/store_fund') || !platformRoute.includes("admin-fund-manage")) throw new Error('平台费用路由未绑定独立权限入口')
if (!platformMenuMigration.includes("admin-store-finance") || !platformMenuMigration.includes("admin-fund-manage") || platformMenuMigration.includes('1043')) throw new Error('平台费用菜单迁移未按权限规则定位门店财务')
if (!integrationBuild.includes('build_vite_app fund-vue3') || !integrationBuild.includes('switch_target view_fund_v3 view_fund_v3')) throw new Error('8080 集成构建未纳入费用前端产物')
console.log('fund-vue3-contract: PASS')

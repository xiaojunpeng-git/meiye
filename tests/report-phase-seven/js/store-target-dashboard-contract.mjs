import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8')
const view = read('前端代码/cashier-v3/src/views/StoreTargetDashboardView.vue')
const shell = read('前端代码/cashier-v3/src/layouts/CashierShell.vue')
const router = read('前端代码/cashier-v3/src/router/index.js')
const manifest = read('前端代码/cashier-v3/src/services/cashierV3ActionManifest.js')

const checks = [
  ['目标页面请求唯一门店看板 action', view.includes("query-store-target-dashboard") && view.includes('当前门店')],
  ['默认本月并支持今日本年自定义', view.includes("period = ref('month')") && view.includes("{ key: 'today'") && view.includes("{ key: 'year'") && view.includes("{ key: 'custom'" )],
  ['目标进度月年卡只读', view.includes('本月目标进度') && view.includes('本年目标进度') && view.includes('只读') && !view.includes('目标管理')],
  ['现金排行列完整且按现金业绩降序展示', view.includes('员工现金业绩排行') && view.includes('<th>现金业绩</th>') && view.includes('<th>销售数量</th>') && view.includes('<th>成交人数</th>') && view.includes('现金业绩降序')],
  ['消耗排行列完整且按消耗业绩降序展示', view.includes('员工消耗业绩排行') && view.includes('<th>消耗业绩</th>') && view.includes('<th>手工</th>') && view.includes('<th>项目数</th>') && view.includes('<th>服务人次</th>') && view.includes('<th>服务人数</th>') && view.includes('消耗业绩降序')],
  ['空数据和错误状态明确', view.includes('暂无现金业绩排行数据') && view.includes('暂无消耗业绩排行数据') && view.includes('store-target-state--error')],
  ['排行名次颜色和来源说明明确', view.includes('store-target-rank-badge--1') && view.includes('store-target-rank-badge--2') && view.includes('store-target-rank-badge--3') && view.includes('store-target-explanation') && view.includes('列名取值来源')],
  ['数据下方独立显示目标入口', shell.includes("key: 'targets'") && shell.includes("label: '目标'") && shell.includes("cashier-v3-store-target-dashboard") && !shell.includes('submenu: dataFeatureItems')],
  ['目标路由和管理中心权限存在', router.includes("name: 'cashier-v3-store-target-dashboard'") && router.includes("cashier.v3.management_center")],
  ['V3 projection action manifest contains target action', manifest.includes("'query-store-target-dashboard': FEATURE_MANAGEMENT")],
]

const failed = checks.filter(([, passed]) => !passed)
for (const [name, passed] of checks) console.log(`${passed ? 'PASS' : 'FAIL'} ${name}`)
if (failed.length) process.exit(1)
console.log(`PASS ${checks.length} store target dashboard frontend contract checks`)

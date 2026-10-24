import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { readFileSync, mkdirSync, writeFileSync } from 'node:fs'
import path from 'node:path'
import os from 'node:os'
import { fileURLToPath } from 'node:url'
import { createHash } from 'node:crypto'
import { execFileSync } from 'node:child_process'
import { orderBusinessSourceFields, orderBusinessSourceQuery, orderReportFields } from '../../前端代码/cashier-v3/src/services/orderCenterReportFilters.js'

// 编译正本真实筛选组件，服务端字段来自真实注册器；传输和策略配置均隔离，不写业务库。
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..')
const cashier = path.join(root, '前端代码/cashier-v3')
const shared = path.join(root, '前端代码/shared/unified-query-vue3/src')
const require = createRequire(path.join(cashier, 'package.json'))
const { parse, compileScript, compileStyle } = require('@vue/compiler-sfc')
const { build } = require('esbuild')
const { chromium } = require(path.join(os.homedir(), '.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright'))
const backend = JSON.parse(execFileSync('php', [path.join(root, 'tests/order-center/r57-business-source-policy.php')], { encoding: 'utf8' }))
assert.equal(backend.status, 'passed')
let checks = backend.checks
const check = (actual, expected, message) => { assert.deepEqual(actual, expected, message); checks++ }
const legacy = { pageCode: 'order_center_all', page: 4, limit: 20, dataScope: 'all', filterRelation: 'any', storeIds: [9],
  filters: [{ field: 'source', operator: 'eq', value: '来源A' }, { field: 'member_name', operator: 'contains', value: '测试' }],
  topFilters: [{ fieldKey: 'source', operator: 'is_null' }, { field: 'business_date', operator: 'gte', value: '2026-10-01' }],
  visibleFields: ['source', 'source_order_no'], sorts: [{ field_key: 'source' }, { field: 'event_completed_at', direction: 'desc' }],
  groupBy: ['source', 'source_card'], querySettings: { filters: [{ field: 'source' }] }, export: { fields: ['source', 'source_order_no'] } }
const before = JSON.stringify(legacy)
const cleaned = orderBusinessSourceQuery(legacy, false)
check(cleaned.filters.length, 1, '关闭撤下旧来源组合条件')
check(cleaned.topFilters.length, 1, '关闭撤下旧来源顶栏条件')
check(cleaned.filterRelation, 'any', '不改其他 OR 关系')
check(cleaned.groupBy, ['source_card'], '保留卡技术来源')
check(cleaned.export.fields, ['source_order_no'], '导出只移除业务来源')
check(JSON.stringify(legacy), before, '不改原偏好和唯一执行快照')
check(orderBusinessSourceQuery(legacy, true), legacy, '开启不改原查询')
const hostFile = path.join(cashier, 'src/views/OrderCenterView.vue')
const hostSource = readFileSync(hostFile, 'utf8')
const hostSfc = parse(hostSource, { filename: hostFile })
check(hostSfc.errors, [], '宿主解析')
compileScript(hostSfc.descriptor, { id: 'r57-order-host', inlineTemplate: true })
// 同时执行宿主真实计算段，不复制一套列排序实现；各页签来源来自其实际声明。
const vue = require('vue')
const field = (key, label, type = 'text', extra = {}) => ({ key, label, type, defaultVisible: true, ...extra })
const tabsCode = hostSource.slice(hostSource.indexOf('const ORDER_TABS ='), hostSource.indexOf('const FIELD_ALIASES ='))
const tabs = new Function('field', 'orderRecordTypeLabel', `${tabsCode}; return ORDER_TABS`)(field, type => type)
const columnsCode = hostSource.slice(hostSource.indexOf('const queryFields = computed'), hostSource.indexOf('const allowedSalesOrderDetailActions'))
for (const tab of tabs) {
  for (const enabled of [true, false]) {
    const columns = new Function('computed', 'activeTab', 'activeTabKey', 'businessSourceEnabled', 'sourceFilterOptions', 'querySettings', 'serviceReportDrilldown', 'genericDetailRecord', 'recordTab', 'field', 'orderBusinessSourceFields', `${columnsCode}; return {visible:visibleFields.value, detail:detailFields.value}`)(
      vue.computed, vue.ref(tab), vue.ref(tab.key), vue.ref(enabled), vue.ref([]), vue.ref({ visibleFields: tab.fields.map(field => field.key) }), vue.ref(null), vue.ref({}), () => tabs.find(item => item.key === 'recharge'), field, orderBusinessSourceFields)
    check(columns.visible.every(Boolean), true, `${tab.key}无空列`)
    check(columns.visible.some(field => field.key === 'source'), enabled && tab.fields.some(field => field.key === 'source'), `${tab.key}列表来源显隐`)
    check(columns.detail.some(field => field.key === 'source'), enabled && (tab.key === 'all' || tab.fields.some(field => field.key === 'source')), `${tab.key}详情来源显隐`)
    const technical = tab.fields.filter(field => ['source_type', 'source_card', 'source_order_no', 'gift_source', 'entitlement_source', 'source_card_no'].includes(field.key))
    for (const field of technical) check(columns.visible.some(item => item.key === field.key), true, `${tab.key}保留${field.key}`)
  }
}
const styles = []
const bundle = await build({
  stdin: { contents: `import {createApp,h,reactive} from 'vue'; import Toolbar from './src/components/query/OrderCenterReportQueryToolbar.vue'; import Detail from './src/components/order/SalesOrderDetailOverlay.vue';
    window.fixture=reactive({pageCode:'order_center_all',pageName:'全部订单',businessSourceEnabled:true,fields:[],executedQuery:${JSON.stringify(legacy)},settings:{visibleFields:['source','source_order_no']},queryCapability:{enabled:true,pageCode:'order_center_all',commandContext:{kind:'store',id:9},exportCapability:{enabled:true,allowCurrentQuery:true}}});
    window.queries=[];window.exports=[];createApp({render:()=>window.fixture.showDetail ? h(Detail,{businessSourceEnabled:window.fixture.businessSourceEnabled,order:{id:'test',salesOrderNo:'XS-test',memberId:8,source:'客户来源测试',memberName:'测试会员',items:[{id:1,name:'权益项目',businessTag:'权益',cardName:'卡项技术来源',cardNo:'CARD-001'}]},attributionChanges:[{field:'source',oldValue:'旧来源',newValue:'新来源',recordNo:'来源更正'},{field:'business_date',oldValue:'2026-10-24',newValue:'2026-10-25',recordNo:'日期更正'}]}) : h(Toolbar,{...window.fixture,onQuery:q=>window.queries.push(q),onCreateExport:async q=>{window.exports.push(q);return {status:'failed',message:'隔离测试不建任务'}}})}).mount('#app');`, resolveDir: cashier, loader: 'js' },
  plugins: [{ name: 'canonical-vue', setup(build) {
    build.onResolve({ filter: /^@\/services\/cashierV3Bridge$/ }, () => ({ path: 'bridge', namespace: 'r57' }))
    build.onLoad({ filter: /.*/, namespace: 'r57' }, () => ({ contents: `export const canUseCashierV3Operation=()=>false; export const formatMoney=v=>'¥'+Math.round(Number(v)||0);`, loader: 'js' }))
    build.onResolve({ filter: /^@\// }, args => ({ path: path.join(cashier, 'src', args.path.slice(2)) + (path.extname(args.path) ? '' : '.js') }))
    build.onResolve({ filter: /^@mohe\/unified-query-vue3$/ }, () => ({ path: path.join(shared, 'index.js') }))
    build.onLoad({ filter: /\.vue$/ }, args => {
      const { descriptor, errors } = parse(readFileSync(args.path, 'utf8'), { filename: args.path })
      assert.deepEqual(errors, [])
      const id = createHash('sha256').update(args.path).digest('hex').slice(0, 10)
      const script = compileScript(descriptor, { id, inlineTemplate: true, genDefaultAs: 'Component' })
      for (const style of descriptor.styles) {
        const result = compileStyle({ source: style.content, filename: args.path, id: `data-v-${id}`, scoped: style.scoped })
        assert.deepEqual(result.errors, []); styles.push(result.code)
      }
      return { contents: `${script.content}\nComponent.__scopeId='data-v-${id}'; export default Component`, loader: 'js', resolveDir: path.dirname(args.path) }
    })
  } }], nodePaths: [path.join(cashier, 'node_modules')], bundle: true, write: false, format: 'iife', platform: 'browser'
})
const evidence = path.join(root, 'tests/order-center/evidence/r57-business-source-policy')
mkdirSync(evidence, { recursive: true })
const browser = await chromium.launch({ headless: true, executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' })
try {
  const page = await browser.newPage({ viewport: { width: 1480, height: 1009 } })
  const errors = []; page.on('pageerror', error => errors.push(error.message))
  await page.setContent('<html><head><meta charset="utf-8"></head><body><div id="app"></div></body></html>')
  await page.addStyleTag({ content: `body{margin:0;padding:12px;background:#f5f7fa;font:13px Arial}*{box-sizing:border-box}.button{border:1px solid #d8e0ea;border-radius:6px;padding:6px 11px;background:white;color:#515a6e}.button--primary{background:#086ad8;color:white}${styles.join('\n')}` })
  await page.addScriptTag({ content: bundle.outputFiles[0].text })
  for (const [pageCode, modes] of Object.entries(backend.pages)) {
    // 初始仍保留旧来源条件，模拟偏好/已执行快照；关闭不能偷偷继续筛来源。
    await page.evaluate(({ pageCode, fields, legacy }) => Object.assign(window.fixture, { pageCode, fields, businessSourceEnabled: true, executedQuery: { ...legacy, pageCode }, queryCapability: { enabled: true, pageCode, commandContext: {kind:'store',id:9},exportCapability:{enabled:true,allowCurrentQuery:true} } }), { pageCode, fields: modes.enabled, legacy })
    await page.getByRole('button', { name: '更多筛选', exact: true }).click()
    check(await page.locator('.unified-report-query-toolbar__field').count(), orderReportFields(modes.enabled).length, `${pageCode}开启字段`)
    await page.evaluate(() => { window.fixture.businessSourceEnabled = false })
    check(await page.getByLabel(/^(客户来源|来源)筛选$/).count(), 0, `${pageCode}关闭不显示来源`)
    check(await page.locator('.unified-report-query-toolbar__field').count(), orderReportFields(modes.disabled).length, `${pageCode}关闭字段与后端一致`)
    await page.getByRole('button', { name: '查询', exact: true }).click()
    check(await page.evaluate(() => [...window.queries.at(-1).filters, ...window.queries.at(-1).topFilters].some(item => (item.field || item.fieldKey || item.field_key) === 'source')), false, `${pageCode}关闭查询无隐藏来源`)
    await page.getByRole('button', { name: '导出', exact: true }).click()
    const exported = await page.evaluate(() => window.exports.at(-1))
    check(exported.configuration.fields.includes('source'), false, `${pageCode}导出无来源列`)
    check(exported.query.filters.some(item => item.field === 'source'), false, `${pageCode}导出不沿用隐藏来源`)
    check(exported.query.page, 4, `${pageCode}导出仍使用成功快照页码`)
    if (pageCode === 'order_center_service') await page.screenshot({ path: path.join(evidence, 'service-source-disabled.png'), fullPage: true })
    await page.evaluate(() => { window.fixture.businessSourceEnabled = true })
    check(await page.locator('.unified-report-query-toolbar__field').count(), orderReportFields(modes.enabled).length, `${pageCode}重新开启恢复`)
  }
  await page.evaluate(() => { window.fixture.showDetail = true; window.fixture.businessSourceEnabled = false })
  check(await page.getByText('客户来源测试', { exact: true }).count(), 0, '销售详情客户来源隐藏')
  check(await page.getByText('来源更正', { exact: false }).count(), 0, '销售详情来源变更隐藏')
  check(await page.getByText('日期更正', { exact: false }).count(), 1, '销售详情日期变更保留')
  check(await page.getByText('卡项技术来源', { exact: false }).count() > 0, true, '销售详情权益来源保留')
  await page.screenshot({ path: path.join(evidence, 'sales-detail-source-disabled.png'), fullPage: true })
  await page.evaluate(() => { window.fixture.businessSourceEnabled = true })
  check(await page.getByText('客户来源测试', { exact: true }).count(), 1, '销售详情开启恢复来源')
  check(await page.getByText('来源更正', { exact: false }).count(), 1, '销售详情开启恢复变更')
  check(errors, [], '浏览器无错误')
} finally { await browser.close() }
const changedSources = ['前端代码/cashier-v3/src/views/OrderCenterView.vue', '前端代码/cashier-v3/src/components/query/OrderCenterReportQueryToolbar.vue',
  '前端代码/cashier-v3/src/services/orderCenterReportFilters.js', '前端代码/cashier-v3/src/components/order/SalesOrderDetailOverlay.vue',
  ...['AbstractCashierV3OrderCenterUnifiedQueryProvider', 'CashierV3OrderCenterQueryExecutionServices', 'CashierV3OrderCenterUnifiedQueryContract', 'CashierV3OrderCenterUnifiedQueryRegistrar'].map(name => `后端代码/app/services/cashier/v3/order/${name}.php`)]
const fingerprints = Object.fromEntries([...changedSources, 'tests/order-center/r57-business-source-policy.php', 'tests/order-center/r57-business-source-policy.mjs'].map(file => [file, createHash('sha256').update(readFileSync(path.join(root, file))).digest('hex')]))
writeFileSync(path.join(evidence, 'result.json'), JSON.stringify({ status: 'passed', checks, backendChecks: backend.checks,
  baselineHead: execFileSync('git', ['rev-parse', 'HEAD'], {cwd:root,encoding:'utf8'}).trim(), fingerprints,
  online: '不适用', businessWrites: false, scope: '实际九页宿主列计算、真实Vue筛选及销售详情组件、后端真实注册器与查询执行器；配置/传输隔离',
  retained: ['来源订单号', '欠款来源(单据类型)', '赠送来源(赠送出处)', '权益来源', '来源卡名称/卡号', '全部历史事实'],
  savedFilters: '关闭时仅在执行查询/导出副本中移除source的条件、排序、分组、可见列和导出列；保留用户原偏好及其他OR条件',
  commentsUpdated: changedSources, migrations: [], scripts: [],
  limitations: ['未执行完整登录态端到端或真实异步导出任务；待主任务配置/根状态集成后联调', '已冻结且包含来源列的旧导出任务由既有字段可用性门禁拒绝，需按关闭后的新字段重新导出', '源码与暂存区既有脏改动未还原，未stage/commit/deploy/remote/写库']
}, null, 2))
console.log(`R57 order source policy: PASS ${checks} checks (backend ${backend.checks})`)

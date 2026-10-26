import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { readFileSync, mkdirSync, writeFileSync } from 'node:fs'
import path from 'node:path'
import os from 'node:os'
import { fileURLToPath } from 'node:url'
import { createHash } from 'node:crypto'
import { execFileSync } from 'node:child_process'
import { orderReportFields, orderReportDate, orderReportDraftSnapshot, buildOrderReportQuery, EMPTY_ORDER_SOURCE } from '../../前端代码/cashier-v3/src/services/orderCenterReportFilters.js'

// 编译唯一源码中的真实组件并验证查询载荷；无业务写入，不冒充生产或产品验收。
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..')
const cashier = path.join(root, '前端代码/cashier-v3')
const shared = path.join(root, '前端代码/shared/unified-query-vue3/src')
const require = createRequire(path.join(cashier, 'package.json'))
const { parse, compileScript, compileStyle } = require('@vue/compiler-sfc')
const { build } = require('esbuild')
const { chromium } = require(path.join(os.homedir(), '.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright'))
const fields = [
  ...['business_type', 'order_no', 'source'].map(key => ({ key, label: { business_type: '业务类型', order_no: '订单号', source: '来源' }[key], type: 'text', capabilities: { export: true, filter: true }, ...(key === 'source' ? { options: [{ value: 'A老客首护', label: 'A老客首护' }] } : {}) })),
  { key: 'member_name', label: '会员姓名', type: 'text', capabilities: { filter: true } },
  { key: 'paid_amount', label: '已收金额', type: 'money', queryType: 'amount', capabilities: { filter: true } },
  { key: 'event_completed_at', label: '办理时间', type: 'datetime', capabilities: { filter: true } },
  { key: 'is_paid', label: '已付清', type: 'boolean', capabilities: { filter: true } },
  { key: 'business_date', label: '业务日期', type: 'date', capabilities: { filter: true } },
  { key: 'internal_id', label: '隐藏身份', type: 'text', hidden: true, capabilities: { filter: true } },
  { key: 'display_only', label: '仅展示字段', type: 'text', defaultQuick: true, capabilities: { filter: false } },
  { key: 'retired', label: '停用字段', type: 'text', status: 'archived', capabilities: { filter: true } }
]
const dynamicFields = orderReportFields(fields)
// 直接取真实 PHP 注册契约；测试自身不加载运行配置或业务库。
const backend = JSON.parse(execFileSync('php', [path.join(root, 'tests/order-center/r52-dynamic-metadata.php')], { encoding: 'utf8' }))
const otherPages = ['sales', 'recharge', 'refund', 'debt', 'service', 'supplement', 'gift', 'card_operation'].map(type => JSON.parse(execFileSync('php', [path.join(root, 'tests/order-center/r52-dynamic-metadata.php'), `order_center_${type}`], { encoding: 'utf8' })))
assert.equal(backend.status, 'passed')
assert.equal(orderReportFields(backend.fields).length, backend.fields.filter(field => field.capabilities.filter && field.key !== 'business_date').length)
assert.ok(orderReportFields(backend.fields).length > 3, '真实后端字段不能退回固定三项')
const original = { pageCode: 'order_center_all', page: 6, limit: 50, keyword: '不能暗中保留', dataScope: 'normal', businessStatus: '', store_ids: [19], filters: [{ field: 'member_name', operator: 'contains', value: '李' }, { field: 'member_mobile', operator: 'contains', value: '138' }], filterRelation: 'any', sorts: [{ field: 'event_completed_at', direction: 'desc' }], topFilters: [
  { field: 'business_date', operator: 'gte', value: '2026-10-01' }, { field: 'business_date', operator: 'lte', value: '2026-10-24' },
  { field: 'business_type', operator: 'eq', value: '消费' }, { field: 'source', operator: 'is_null', value: null }
] }
const frozen = JSON.stringify(original)
assert.equal(dynamicFields.length, 7)
assert.equal(dynamicFields[0].filter.type, 'enum')
assert.equal(dynamicFields.find(field => field.key === 'paid_amount').filter.type, 'number')
assert.deepEqual(orderReportDate(original), { min: '2026-10-01', max: '2026-10-24' })
assert.equal(orderReportDraftSnapshot(original, dynamicFields).filters.at(-1).value, EMPTY_ORDER_SOURCE)
const payload = buildOrderReportQuery(original, { filters: [{ field: 'business_type', operator: 'eq', value: '退款' }, { field: 'source', operator: 'eq', value: EMPTY_ORDER_SOURCE }] }, { min: '2026-09-01', max: '2026-09-30' }, 'all', 50, false, dynamicFields)
assert.equal(payload.page, 1)
assert.equal(payload.limit, 50)
assert.equal(payload.keyword, '')
assert.equal(payload.filterRelation, 'any')
assert.deepEqual(payload.filters, original.filters)
assert.deepEqual(payload.store_ids, [19])
assert.equal(payload.topFilters.filter(item => item.field === 'business_type').length, 1)
assert.deepEqual(payload.topFilters.find(item => item.field === 'source'), { field: 'source', operator: 'is_null', value: null })
assert.equal(JSON.stringify(original), frozen)
assert.deepEqual(buildOrderReportQuery(original, { filters: [] }, orderReportDate(original), 'normal', 20, true, dynamicFields).filters, [])

const styles = []
const bundle = await build({
  stdin: { contents: `import { createApp, h, reactive } from 'vue';
    import Toolbar from './src/components/query/OrderCenterReportQueryToolbar.vue';
    const state = reactive({ pageCode: 'order_center_all', pageName: '全部订单', fields: ${JSON.stringify(fields)}, executedQuery: ${JSON.stringify(original)}, pageSize: 50, isQueryLoading: false, settings: { visibleFields: ['order_no'] }, resultCount: 123, queryCapability: { enabled: true, pageCode: 'order_center_all', commandContext: {kind:'store', id:19}, exportCapability:{enabled:true,allowCurrentQuery:true} } });
    window.fixture=state; window.queries=[]; window.exports=[]; window.refundClicks=0;
    createApp({ render: () => h(Toolbar, { ...state, onQuery:q=>window.queries.push(q), onCreateExport: async q=>{ window.exports.push(q); return {status:'failed',message:'测试不创建业务导出任务'} } }, {'primary-actions':()=>state.pageCode==='order_center_refund' ? h('button', {type:'button', onClick:()=>window.refundClicks++}, '退款') : null}) }).mount('#app');`, resolveDir: cashier, loader: 'js' },
  plugins: [{ name: 'canonical-vue', setup(build) {
    build.onResolve({ filter: /^@mohe\/unified-query-vue3$/ }, () => ({ path: 'shared', namespace: 'r52' }))
    build.onLoad({ filter: /.*/, namespace: 'r52' }, () => ({ contents: `export { default as UnifiedReportQueryToolbar } from '${shared}/components/UnifiedReportQueryToolbar.vue'; export { default as UnifiedQueryDateRange } from '${shared}/components/UnifiedQueryDateRange.vue'; export { default as UnifiedQueryExportDrawer } from '${shared}/components/UnifiedQueryExportDrawer.vue'; export * from '${shared}/contracts/unifiedQueryContract.js';`, loader: 'js', resolveDir: shared }))
    build.onLoad({ filter: /\.vue$/ }, args => {
      const { descriptor, errors } = parse(readFileSync(args.path, 'utf8'), { filename: args.path })
      assert.deepEqual(errors, [])
      const id = createHash('sha256').update(args.path).digest('hex').slice(0, 10)
      const script = compileScript(descriptor, { id, inlineTemplate: true, genDefaultAs: 'Component' })
      for (const style of descriptor.styles) {
        const result = compileStyle({ source: style.content, filename: args.path, id: `data-v-${id}`, scoped: style.scoped })
        assert.deepEqual(result.errors, [])
        styles.push(result.code)
      }
      return { contents: `${script.content}\nComponent.__scopeId='data-v-${id}'; export default Component`, loader: 'js', resolveDir: path.dirname(args.path) }
    })
  } }],
  nodePaths: [path.join(cashier, 'node_modules')], bundle: true, write: false, format: 'iife', platform: 'browser'
})
// 宿主页保留旧页签分支，且新增 SFC 本身可以由 Vue 编译器解析。
const host = path.join(cashier, 'src/views/OrderCenterView.vue')
const hostSource = readFileSync(host, 'utf8')
const parsed = parse(hostSource, { filename: host })
assert.deepEqual(parsed.errors, [])
compileScript(parsed.descriptor, { id: 'r52-host', inlineTemplate: true })
// 扣除本轮唯一宿主接入块，必须逐字节还原开工前脏工作区；不覆盖其他任务改动。
const previousHost = hostSource.replace("import OrderCenterReportQueryToolbar from '@/components/query/OrderCenterReportQueryToolbar.vue'\n", '')
  .replace(/    <!-- 门店各订单页复用动态统计筛选；平台只读和报表下钻仍保留原入口及约束。 -->[\s\S]*?    <UnifiedQueryToolbar\n      v-else-if=/, '    <UnifiedQueryToolbar\n      v-if=')
  .replace('    </UnifiedQueryToolbar>', `      <template #primary-actions>
        <button
          v-if="!isPlatformReadOnly && activeTabKey === 'refund' && canUseCashierV3Operation('cashier.v3.order.refund')"
          type="button"
          class="button button--secondary"
          @click="openImportedCardRefundMemberSelector"
        >退款</button>
      </template>
    </UnifiedQueryToolbar>`)
assert.equal(createHash('sha256').update(previousHost).digest('hex'), 'b97f03cd1215ad8f02ed2e8b8c23cc19e900f9500cd1840ebfe1de5b8d174b15')

const evidence = path.join(root, 'tests/order-center/evidence/r52-report-filters')
mkdirSync(evidence, { recursive: true })
const browser = await chromium.launch({ headless: true, executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' })
const results = []
try {
  for (const width of [1515, 610, 375]) {
    const page = await browser.newPage({ viewport: { width, height: 1009 } })
    const errors = []
    page.on('pageerror', error => errors.push(error.message))
    await page.setContent('<html><head><meta charset="utf-8"></head><body><div id="app"></div></body></html>')
    await page.addStyleTag({ content: `body{margin:0;padding:12px;background:#f5f7fa;font:13px Arial}*{box-sizing:border-box}.button{border:1px solid #d8e0ea;border-radius:6px;padding:6px 11px;background:white;color:#515a6e;cursor:pointer}.button--primary{background:#086ad8;color:white;border-color:#086ad8}${styles.join('\n')}` })
    await page.addScriptTag({ content: bundle.outputFiles[0].text })
    await page.getByRole('button', { name: '更多筛选', exact: true }).waitFor()
    assert.equal(await page.getByLabel('订单号筛选').count(), 0)
    for (const name of ['设置', '重置']) assert.equal(await page.getByRole('button', { name, exact: true }).count(), 0)
    assert.equal(await page.locator('input').count(), 0, '顶部无订单号或关键词输入框')
    await page.getByRole('button', { name: '更多筛选', exact: true }).click()
    assert.equal(await page.getByLabel('仅展示字段筛选').count(), 0)
    assert.equal(await page.getByLabel('隐藏身份筛选').count(), 0)
    assert.equal(await page.getByLabel('停用字段筛选').count(), 0)
    assert.equal(await page.getByLabel('业务日期筛选').count(), 0, '日期顶部展示，不重复生成')
    assert.equal(await page.getByLabel('业务类型筛选').inputValue(), '消费')
    assert.equal(await page.getByLabel('来源筛选').inputValue(), EMPTY_ORDER_SOURCE)
    await page.getByLabel('业务类型筛选').selectOption('补交')
    await page.getByLabel('订单号筛选').fill('SO-88')
    await page.getByLabel('会员姓名筛选').fill('张')
    await page.getByLabel('已收金额最小值').fill('100')
    await page.getByLabel('已收金额最大值').fill('300')
    await page.getByLabel('办理时间筛选').fill('2026-10-24T10:30:22')
    await page.getByLabel('已付清筛选').selectOption({ label: '否' })
    assert.equal(await page.evaluate(() => window.queries.length), 0, '编辑草稿不查询')
    await page.getByRole('button', { name: '导出', exact: true }).click()
    assert.equal(await page.evaluate(() => window.exports.at(-1).query.page), 6, '导出使用已执行查询而非草稿')
    await page.getByRole('button', { name: '查询', exact: true }).click()
    const query = await page.evaluate(() => window.queries.at(-1))
    assert.equal(query.page, 1)
    assert.equal(query.limit, 50)
    assert.equal(query.keyword, '')
    assert.equal(query.filterRelation, 'any')
    assert.deepEqual(query.filters, original.filters)
    assert.deepEqual(query.topFilters.find(item => item.field === 'business_type'), { field: 'business_type', operator: 'eq', value: '补交' })
    assert.equal(query.topFilters.find(item => item.field === 'order_no').value, 'SO-88')
    assert.deepEqual(query.topFilters.find(item => item.field === 'member_name'), { field: 'member_name', operator: 'contains', value: '张' })
    assert.deepEqual(query.topFilters.filter(item => item.field === 'paid_amount'), [{ field: 'paid_amount', operator: 'gte', value: 100 }, { field: 'paid_amount', operator: 'lte', value: 300 }])
    assert.deepEqual(query.topFilters.find(item => item.field === 'event_completed_at'), { field: 'event_completed_at', operator: 'eq', value: '2026-10-24 10:30:22' })
    assert.equal(query.topFilters.find(item => item.field === 'is_paid').value, false)
    // 新字段无需修改页面代码即可由异步元数据出现；撤销权限后不再提交旧草稿。
    await page.evaluate(() => { window.fixture.fields = [...window.fixture.fields, { key: 'new_metric', label: '新增指标', type: 'integer', capabilities: { filter: true } }] })
    await page.getByLabel('新增指标最小值').fill('2')
    await page.evaluate(() => { window.fixture.fields = window.fixture.fields.map(field => field.key === 'new_metric' ? { ...field, capabilities: { filter: false } } : field) })
    assert.equal(await page.getByLabel('新增指标最小值').count(), 0)
    // 元数据更新及查询失败不能清掉用户正在修改的条件。
    await page.evaluate(() => { window.fixture.fields = [...window.fixture.fields]; window.fixture.executedQuery = null })
    assert.equal(await page.getByLabel('订单号筛选').inputValue(), 'SO-88')
    assert.equal(await page.getByRole('button', { name: '导出', exact: true }).count(), 0)
    await page.getByRole('button', { name: '清空筛选', exact: true }).click()
    assert.equal(await page.evaluate(() => window.queries.length), 1)
    await page.getByRole('button', { name: '查询', exact: true }).click()
    const cleared = await page.evaluate(() => window.queries.at(-1))
    assert.deepEqual(cleared.filters, [])
    assert.equal(cleared.topFilters.length, 2)
    assert.deepEqual(cleared.store_ids, [19])
    assert.equal(cleared.dateFrom, '2026-10-01')
    await page.getByRole('button', { name: '全部', exact: true }).click()
    assert.equal(await page.evaluate(() => window.queries.at(-1).dataScope), 'all')
    const beforeDate = await page.evaluate(() => window.queries.length)
    await page.getByRole('button', { name: '查询周期', exact: true }).click()
    await page.getByRole('button', { name: '昨天', exact: true }).click()
    await page.getByRole('button', { name: '确定', exact: true }).click()
    assert.equal(await page.evaluate(() => window.queries.length), beforeDate, '确认日期仅更新草稿')
    await page.getByRole('button', { name: '查询', exact: true }).click()
    const dated = await page.evaluate(() => window.queries.at(-1))
    assert.equal(dated.dateFrom, dated.dateTo)
    assert.equal(dated.businessDateFrom, dated.topFilters.find(item => item.operator === 'gte').value)
    assert.notEqual(dated.dateFrom, '2026-10-01')
    await page.evaluate(() => { window.fixture.isQueryLoading = true })
    assert.equal(await page.getByRole('button', { name: '查询中', exact: true }).isDisabled(), true)
    assert.equal(await page.getByRole('button', { name: '正常', exact: true }).isDisabled(), true)
    await page.evaluate(() => { window.fixture.isQueryLoading = false })
    await page.evaluate(fields => { window.fixture.fields = fields }, backend.fields)
    assert.equal(await page.locator('.unified-report-query-toolbar__field').count(), orderReportFields(backend.fields).length)
    await page.getByLabel('退款金额最小值').fill('500')
    await page.getByRole('button', { name: '查询', exact: true }).click()
    assert.deepEqual(await page.evaluate(() => window.queries.at(-1).topFilters.find(item => item.field === 'refund_amount')), { field: 'refund_amount', operator: 'gte', value: 500 })
    assert.equal(await page.getByRole('button', { name: '查询', exact: true }).isVisible(), true, '大量动态字段滚动时查询按钮仍可达')
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, '窄屏无查询栏横向裁切')
    await page.screenshot({ path: path.join(evidence, `expanded-${width}.png`), fullPage: true })
    for (const entry of otherPages) {
      assert.equal(entry.status, 'passed')
      const current = { pageCode: entry.pageCode, topFilters: [{field:'business_date',operator:'gte',value:'2026-10-01'},{field:'business_date',operator:'lte',value:'2026-10-24'}], filters: [], dataScope:'normal', limit:20 }
      await page.evaluate(({entry,current}) => {
        window.fixture.pageCode=entry.pageCode; window.fixture.pageName=entry.pageName;
        window.fixture.fields=entry.fields; window.fixture.executedQuery=current;
        window.fixture.pageSize=20; window.fixture.sourceOptions=[{value:'D线上引流 / 抖音',label:'D线上引流 / 抖音'}];
        window.fixture.queryCapability={enabled:true,pageCode:entry.pageCode,commandContext:{kind:'store',id:19},exportCapability:{enabled:true,allowCurrentQuery:true}};
      }, {entry,current})
      await page.getByRole('button', { name: '更多筛选', exact: true }).click()
      assert.equal(await page.locator('.unified-report-query-toolbar__field').count(), orderReportFields(entry.fields).length)
      const firstText=entry.fields.find(field=>field.capabilities.filter && field.type==='text' && field.key!=='source')
      await page.getByLabel(`${firstText.label}筛选`, {exact:true}).fill(`${entry.pageCode}-only`)
      if (entry.fields.some(field=>field.key==='source')) {
        await page.getByLabel('来源筛选', {exact:true}).count() ? await page.getByLabel('来源筛选', {exact:true}).selectOption('D线上引流 / 抖音')
          : await page.getByLabel('客户来源筛选', {exact:true}).selectOption('D线上引流 / 抖音')
      }
      await page.getByRole('button', {name:'查询',exact:true}).click()
      const emitted=await page.evaluate(()=>window.queries.at(-1))
      assert.equal(emitted.pageCode,entry.pageCode)
      assert.equal(emitted.limit,20)
      assert.equal(emitted.page,1)
      assert.equal(emitted.dateFrom,'2026-10-01')
      assert.equal(emitted.topFilters.some(item=>item.value===`${entry.pageCode}-only`),true)
      assert.equal(emitted.topFilters.some(item=>item.field==='business_type'),false,'页签切换不携带全部订单条件')
      assert.equal(emitted.topFilters.some(item=>item.field==='refund_amount' && item.value===500),false,'页签切换不携带旧金额草稿')
      if(entry.pageCode==='order_center_refund') {
        await page.getByRole('button',{name:'退款',exact:true}).click()
        assert.equal(await page.evaluate(()=>window.refundClicks),1,'保留宿主退款按钮')
      }
      await page.getByRole('button',{name:'导出',exact:true}).click()
      const exported=await page.evaluate(()=>window.exports.at(-1))
      assert.equal(exported.query.pageCode,entry.pageCode)
      assert.equal(exported.configuration.fileName.startsWith(`${entry.pageName}_`),true)
      assert.equal(exported.query.topFilters.some(item=>item.value===`${entry.pageCode}-only`),false,'导出不携带未执行草稿')
      await page.evaluate(()=>{window.fixture.queryCapability.exportCapability.enabled=false})
      assert.equal(await page.getByRole('button',{name:'导出',exact:true}).count(),0)
      assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true)
      if(width===1515) await page.screenshot({path:path.join(evidence,`${entry.pageCode}.png`),fullPage:true})
    }
    assert.deepEqual(errors, [])
    results.push({ width, status: 'passed', checks: 'draft/query/empty-source/OR/export/failure/clear/scope/loading/fit' })
    await page.close()
  }
} finally { await browser.close() }
const files = [host, path.join(cashier, 'src/components/query/OrderCenterReportQueryToolbar.vue'), path.join(cashier, 'src/services/orderCenterReportFilters.js'), path.join(shared, 'components/UnifiedReportQueryToolbar.vue'), path.join(shared, 'contracts/reportFilters.js'), path.join(root, '后端代码/app/services/cashier/v3/order/AbstractCashierV3OrderCenterUnifiedQueryProvider.php'), path.join(root, 'tests/order-center/r52-dynamic-metadata.php'), path.join(root, 'tests/report-basic/js/report-filter-contract.mjs'), fileURLToPath(import.meta.url)]
const fingerprints = Object.fromEntries(files.map(file => [path.relative(root, file), createHash('sha256').update(readFileSync(file)).digest('hex')]))
writeFileSync(path.join(evidence, 'result.json'), JSON.stringify({ baselineHead: 'df65e60a4d916f0f021ad00576314061eaeab1bf', previousHostSha256: 'b97f03cd1215ad8f02ed2e8b8c23cc19e900f9500cd1840ebfe1de5b8d174b15', previousSharedToolbarSha256: '56d89f29de5a10754c542bc532a89af961d8530dd08a7bf15c9632bdace5dc53', online: '不适用；本轮仅本地', businessWrites: false, backendChecks: backend.checks, backendDynamicFields: orderReportFields(backend.fields).length, otherPages: otherPages.map(entry=>({pageCode:entry.pageCode,checks:entry.checks,dynamicFields:orderReportFields(entry.fields).length})), fingerprints, results }, null, 2))
console.log('R52 adapter + actual Vue toolbar + host compilation: PASS at 1515/610/375')

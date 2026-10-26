import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { createRequire } from 'node:module'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { execFileSync } from 'node:child_process'
import crypto from 'node:crypto'

// Actual MemberListView, actual query composable/toolbar/period/export/pagination.
// Only transport and the unrelated unopened member editor are isolated fixtures.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const front = path.join(root, '前端代码/cashier-v3'), shared = path.join(root, '前端代码/shared/unified-query-vue3/src')
const require = createRequire(path.join(front, 'package.json'))
const { parse, compileScript, compileStyle } = require('@vue/compiler-sfc'), { build } = require('esbuild')
const { chromium } = require('/Users/xiaojunpeng/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright')
const evidence = path.join(root, 'tests/cashier-v3/evidence/R55-member-filters')
const backend = JSON.parse(execFileSync('php', ['tests/cashier-v3/php/r55-member-filter-metadata.php'], { cwd: root, encoding: 'utf8' }))
fs.writeFileSync(path.join(evidence, 'backend-contract.json'), JSON.stringify(backend, null, 2))
const helperSource = fs.readFileSync(path.join(front, 'src/services/memberReportFilters.js'), 'utf8')
  .replace("'@mohe/unified-query-vue3'", JSON.stringify(pathToFileURL(path.join(shared, 'contracts/unifiedQueryContract.js')).href))
const { memberReportFields, memberReportDraftSnapshot, buildMemberReportQuery } = await import('data:text/javascript;base64,' + Buffer.from(helperSource).toString('base64'))
let checks = 0
const check = (value, message) => { assert.ok(value, message); checks++ }
const fields = [...backend.fields, { key: 'r55_extra', label: '动态扩展字段', type: 'text', capabilities: { filter: true, export: true } },
  { key: 'r55_display_only', label: '仅显示字段', type: 'text', capabilities: { filter: false } },
  { key: 'r55_hidden', label: '内部隐藏字段', type: 'text', hidden: true, capabilities: { filter: true } }]
check(memberReportFields(fields).length === backend.fields.length + 1, 'all authorized fields, not just three quick inputs')
check(memberReportFields(fields).find(f => f.key === 'account_balance').filter.type === 'number', 'amount type uses numeric bounds')
check(memberReportFields(fields).find(f => f.key === 'latest_visit_date').filter.range, 'date uses period picker')
const original = { pageCode: 'member_list', page: 3, limit: 20, keyword: 'original', dataScope: 'all', store_ids: [9], fieldVersions: { r55_extra: 1 }, filters: [{ field: 'phone', operator: 'contains', value: 'seed' }], filterRelation: 'any', topFilters: [{ field: 'member_no', operator: 'contains', value: '100' }], sorts: [{ field: 'created_at', direction: 'desc' }] }
const before = JSON.stringify(original)
const built = buildMemberReportQuery(original, { filters: [{ field: 'account_balance', operator: 'gte', value: 0 }] }, ' draft ', 'normal', 20)
check(built.page === 1 && built.keyword === 'draft' && built.dataScope === 'normal', 'explicit query resets page and preserves keyword')
assert.deepEqual(built.filters, original.filters); checks++
check(built.filterRelation === 'any' && JSON.stringify(original) === before, 'saved OR filters and external query are not mutated')
assert.deepEqual(built.store_ids, [9]); checks++
assert.deepEqual(memberReportDraftSnapshot(original).filters, original.topFilters); checks++
assert.deepEqual(buildMemberReportQuery(original, { filters: [] }, 'keyword', 'all', 20, true).filters, []); checks++

const styles = [fs.readFileSync(path.join(front, 'src/styles/base.css'), 'utf8')]
const bridge = `import {reactive} from 'vue';
  const fields=${JSON.stringify(fields)};
  const state=reactive({stateContextId:'r55-context',operator:{account:'fixture'},currentStore:{id:9,name:'Local fixture store'},memberCenter:{canBatchOperate:false,statusOptions:[{value:'disabled',label:'已停用',normal:false}]}});
  window.fixture={state,queries:[],exports:[],fail:false,fields,capabilityFailure:false,capabilityDelay:0};
  export const useCashierV3State=()=>state;
  export const canUseCashierV3Operation=()=>true;
  export const formatMoney=value=>'¥'+Math.round(Number(value||0)).toLocaleString('en-US');
  export const openCashierV3QueryEntitySelector=async()=>null;
  export async function requestCashierV3Action(action,payload={}){
    if(action==='query-unified-query-capabilities'){
      const failure=window.fixture.capabilityFailure,delay=window.fixture.capabilityDelay;
      if(delay)await new Promise(resolve=>setTimeout(resolve,delay));
      if(failure)throw new Error('请登录');
      return{result:{status:'success'},data:{pageCode:'member_list',enabled:true,schemaVersion:1,fields,commandContext:{kind:'query_preference',id:'member_list'},queryCutoffDate:'2026-10-24',export:true,exportCapability:{enabled:true,format:'xlsx',allowCurrentQuery:true,allowCurrentPage:true},querySettings:{visibleFields:['member_name','phone','member_no','birthday','member_tag','account_balance','active_card_count','created_at'],sorts:[{field:'created_at',direction:'desc'}],filters:[{field:'phone',operator:'contains',value:'seed'}],filterRelation:'any'}}};
    }
    if(action==='query-members'){
      window.fixture.queries.push(JSON.parse(JSON.stringify(payload)));
      if(window.fixture.fail)return{result:{status:'failed',message:'Local fixture read failure'}};
      const total=window.fixture.empty?0:45,page=payload.page||1,limit=payload.limit||20;
      const records=Array.from({length:Math.max(0,Math.min(limit,total-(page-1)*limit))},(_,n)=>({id:(page-1)*limit+n+1,name:'Fixture member '+((page-1)*limit+n+1),phone:'13000000000',memberNo:'TEST'+n,accountBalance:n,activeCardCount:1}));
      return{result:{status:'success'},data:{memberCenter:{records,total,page,pageSize:limit}}};
    }
    if(action==='create-unified-query-export'){window.fixture.exports.push(JSON.parse(JSON.stringify(payload)));return{result:{status:'failed',message:'Fixture creates no export task'}};}
    throw Error('Unexpected fixture action '+action);
  }`
const bundle = await build({
  stdin: { contents: `import{createApp,h}from'vue';import View from './src/views/MemberListView.vue';createApp({render:()=>h(View)}).mount('#app')`, resolveDir: front, loader: 'js' },
  plugins: [{ name: 'r55-real-sfc', setup(builder) {
    builder.onResolve({ filter: /^@\/services\/cashierV3Bridge$/ }, () => ({ path: 'bridge', namespace: 'r55' }))
    builder.onResolve({ filter: /MemberCreatorPanel\.vue$/ }, () => ({ path: 'unopened-editor', namespace: 'r55' }))
    builder.onResolve({ filter: /^@mohe\/unified-query-vue3(?:\/(contract|composable))?$/ }, args => ({ path: args.path.endsWith('/contract') ? path.join(shared, 'contracts/unifiedQueryContract.js') : args.path.endsWith('/composable') ? path.join(shared, 'composables/useUnifiedQueryPage.js') : path.join(shared, 'index.js') }))
    builder.onResolve({ filter: /^@\// }, args => ({ path: path.join(front, 'src', args.path.slice(2)) + (path.extname(args.path) ? '' : '.js') }))
    builder.onLoad({ filter: /.*/, namespace: 'r55' }, args => ({ contents: args.path === 'bridge' ? bridge : 'export default {render(){return null}}', loader: 'js', resolveDir: front }))
    builder.onLoad({ filter: /\.vue$/ }, args => {
      const { descriptor, errors } = parse(fs.readFileSync(args.path, 'utf8'), { filename: args.path }); assert.deepEqual(errors, [])
      const id = crypto.createHash('sha256').update(args.path).digest('hex').slice(0, 10)
      const script = compileScript(descriptor, { id, inlineTemplate: true, genDefaultAs: 'Component' })
      for (const style of descriptor.styles) { const out = compileStyle({ source: style.content, filename: args.path, id: 'data-v-' + id, scoped: style.scoped }); assert.deepEqual(out.errors, []); styles.push(out.code) }
      return { contents: script.content + '\nComponent.__scopeId="data-v-' + id + '";export default Component;', loader: 'js', resolveDir: path.dirname(args.path) }
    })
  } }], nodePaths: [path.join(front, 'node_modules')], bundle: true, write: false, format: 'iife', platform: 'browser'
})
const browser = await chromium.launch({ headless: true, executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' })
const views = []
try {
  for (const width of [1480, 610, 375]) {
    const page = await browser.newPage({ viewport: { width, height: 1009 } }), errors = []
    page.on('pageerror', e => errors.push(e.message))
    await page.setContent('<html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><div id="app"></div></body></html>')
    // Existing cashier shell intentionally has a desktop minimum width. Isolate
    // this view's container for small-width component tests, without editing it.
    await page.addStyleTag({ content: styles.join('\n') + '\nhtml,body,#app{min-width:0;width:100%}body{margin:0}#app{height:100vh;overflow:hidden}' })
    await page.addScriptTag({ content: bundle.outputFiles[0].text })
    await page.getByRole('button', { name: '更多筛选', exact: true }).waitFor()
    await page.getByRole('button', { name: '导出', exact: true }).waitFor()
    await page.screenshot({ path: path.join(evidence, 'collapsed-' + width + '.png') })
    check(await page.locator('input').count() === 1, 'collapsed toolbar has only comprehensive keyword input')
    check(await page.getByRole('searchbox', { name: '综合查询' }).getAttribute('placeholder') === '搜索会员姓名、完整手机号或会员姓名首字母', 'keyword hint advertises existing name initials search rather than member number')
    const headings = await page.locator('thead th').allTextContents()
    check(!headings.includes('会员标签'), 'member tag is removed from list columns')
    check(headings.indexOf('会员编号') >= 0 && headings[headings.indexOf('会员编号') + 1] === '建档时间', 'member number immediately precedes creation date')
    for (const name of ['查询设置', '重置', '收起']) check(await page.getByRole('button', { name, exact: true }).count() === 0, 'removed ' + name)
    const more = await page.getByRole('button', { name: '更多筛选', exact: true }).boundingBox(), queryButton = await page.getByRole('button', { name: '查询', exact: true }).boundingBox()
    check(more.x < queryButton.x && more.y === queryButton.y, 'more filters immediately left of query')
    await page.getByRole('button', { name: '下一页', exact: true }).click()
    await page.waitForFunction(() => window.fixture.queries.at(-1).page === 2)
    check(await page.locator('tbody tr').count() === 20, 'actual host renders second server page')
    const executed = await page.evaluate(() => window.fixture.queries.at(-1))
    await page.getByRole('button', { name: '更多筛选', exact: true }).click()
    check(await page.getByLabel('动态扩展字段筛选').count() === 1, 'non-quick metadata dynamically creates a filter')
    check(await page.getByLabel('仅显示字段筛选').count() === 0 && await page.getByLabel('内部隐藏字段筛选').count() === 0, 'display/hidden fields cannot become filters')
    await page.getByLabel('会员姓名筛选', { exact: true }).fill('Draft member')
    await page.getByLabel('账户余额最小值').fill('0')
    await page.getByRole('searchbox', { name: '综合查询' }).fill('Draft keyword')
    check(await page.evaluate(() => window.fixture.queries.length) === 2, 'edits stay draft')
    await page.getByRole('button', { name: '最近到店日期周期', exact: true }).click()
    const calendar = page.getByRole('dialog', { name: '最近到店日期周期选择', exact: true })
    await calendar.getByRole('button', { name: '上月', exact: true }).click()
    await calendar.getByRole('button', { name: '确定', exact: true }).click()
    check(await page.evaluate(() => window.fixture.queries.length) === 2, 'confirmed calendar is still unsubmitted filter draft')
    await page.getByRole('button', { name: '导出', exact: true }).click()
    await page.getByRole('button', { name: '创建导出任务', exact: true }).click()
    await page.waitForFunction(() => window.fixture.exports.length === 1)
    const exported = await page.evaluate(() => window.fixture.exports[0].query)
    check(exported.page === 2 && exported.keyword === executed.keyword && exported.topFilters.length === 0, 'export uses successful second-page snapshot, not draft')
    await page.getByRole('button', { name: '取消', exact: true }).click()
    await page.getByRole('button', { name: '查询', exact: true }).click()
    await page.waitForFunction(() => window.fixture.queries.length === 3)
    const query = await page.evaluate(() => window.fixture.queries.at(-1))
    check(query.page === 1 && query.keyword === 'Draft keyword' && query.filterRelation === 'any', 'query resets page and preserves saved OR relation')
    check(query.topFilters.some(f => f.field === 'account_balance' && f.operator === 'gte' && f.value === 0), 'zero bound retained')
    check(query.topFilters.some(f => f.field === 'latest_visit_date' && f.operator === 'gte' && f.value === '2026-09-01'), 'period start serialized')
    check(query.topFilters.some(f => f.field === 'latest_visit_date' && f.operator === 'lte' && f.value === '2026-09-30'), 'period end serialized')
    await page.getByLabel('会员姓名筛选', { exact: true }).fill('Retry draft')
    await page.evaluate(() => { window.fixture.fail = true })
    await page.getByRole('button', { name: '查询', exact: true }).click()
    await page.getByRole('alert').waitFor()
    check(await page.getByRole('button', { name: '导出', exact: true }).count() === 0, 'failure disables export')
    check(await page.getByLabel('会员姓名筛选', { exact: true }).inputValue() === 'Retry draft', 'failure keeps retry draft')
    await page.evaluate(() => { window.fixture.fail = false })
    await page.getByRole('button', { name: '查询', exact: true }).click()
    await page.getByRole('button', { name: '导出', exact: true }).waitFor()
    const count = await page.evaluate(() => window.fixture.queries.length)
    await page.getByRole('button', { name: '清空筛选', exact: true }).click()
    check(await page.evaluate(() => window.fixture.queries.length) === count, 'clear changes only draft')
    await page.getByRole('button', { name: '查询', exact: true }).click()
    await page.waitForFunction(n => window.fixture.queries.length > n, count)
    const cleared = await page.evaluate(() => window.fixture.queries.at(-1))
    check(cleared.filters.length === 0 && cleared.topFilters.length === 0 && cleared.keyword === 'Draft keyword', 'clear removes extra filters without erasing keyword')
    await page.getByRole('button', { name: '全部数据', exact: true }).click()
    await page.waitForFunction(() => window.fixture.queries.at(-1).dataScope === 'all')
    await page.getByRole('combobox', { name: '其他状态', exact: true }).selectOption('disabled')
    await page.waitForFunction(() => window.fixture.queries.at(-1).businessStatus === 'disabled')
    check(await page.getByRole('combobox', { name: '其他状态', exact: true }).inputValue() === 'disabled', 'all-data business status shortcut retained')
    await page.getByRole('button', { name: '下一页', exact: true }).click()
    await page.getByRole('button', { name: '下一页', exact: true }).click()
    await page.waitForFunction(() => window.fixture.queries.at(-1).page === 3)
    check(await page.locator('tbody tr').count() === 5, 'last page renders server remainder')
    check(await page.getByRole('button', { name: '下一页', exact: true }).isDisabled(), 'last-page boundary disabled')
    await page.evaluate(() => { window.fixture.empty = true })
    await page.getByRole('button', { name: '查询', exact: true }).click()
    await page.waitForFunction(() => window.fixture.queries.at(-1).page === 1)
    await page.locator('.member-list-wrap').screenshot({ path: path.join(evidence, 'empty-' + width + '.png') })
    await page.evaluate(() => { window.fixture.empty = false })
    await page.getByRole('button', { name: '查询', exact: true }).click()
    await page.getByRole('button', { name: '下一页', exact: true }).waitFor()
    await page.screenshot({ path: path.join(evidence, 'expanded-' + width + '.png') })
    const pagination = await page.locator('.table-pagination').boundingBox()
    check(pagination && pagination.y + pagination.height <= 1009, 'pagination remains inside viewport with expanded filters')
    check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'no page-level horizontal overflow')
    await page.evaluate(() => { window.fixture.state.stateContextId = 'r55-other-context' })
    await page.getByRole('button', { name: '更多筛选', exact: true }).waitFor()
    check(await page.getByRole('searchbox', { name: '综合查询' }).inputValue() === '', 'account/store context remount clears previous draft')
    // 元数据失败/延迟与真空结果必须区分；入口稳定，但绝不能渲染未授权的本地字段。
    await page.evaluate(() => { window.fixture.capabilityFailure = true; window.fixture.state.stateContextId = 'r55-failed-context' })
    await page.getByRole('alert').filter({ hasText: '请登录' }).waitFor()
    check(await page.getByRole('button', { name: '更多筛选', exact: true }).isVisible(), 'capability failure retains filter entry')
    check(await page.locator('.member-list-empty').count() === 0, 'initialization failure is not represented as no matching members')
    const queryCount = await page.evaluate(() => window.fixture.queries.length)
    await page.getByRole('button', { name: '更多筛选', exact: true }).click()
    check(await page.getByLabel('会员姓名筛选', { exact: true }).count() === 0, 'capability failure never falls back to local unauthorized fields')
    await page.screenshot({ path: path.join(evidence, 'capability-failed-' + width + '.png') })
    await page.evaluate(() => { window.fixture.capabilityFailure = false; window.fixture.capabilityDelay = 250 })
    await page.getByRole('button', { name: '查询', exact: true }).click()
    check(await page.getByRole('button', { name: '收起筛选', exact: true }).isVisible(), 'filter entry remains during metadata reload')
    await page.getByLabel('会员姓名筛选', { exact: true }).waitFor()
    await page.getByRole('button', { name: '导出', exact: true }).waitFor()
    check(await page.evaluate(n => window.fixture.queries.length === n + 1, queryCount), 'retry reloads capability then performs exactly one member query')
    check(await page.locator('tbody tr').count() === 20, 'successful retry restores actual member projection')
    check(errors.length === 0, 'no Vue/runtime errors'); views.push({ width, result: 'PASS' }); await page.close()
  }
} catch (error) {
  for (const [index, context] of browser.contexts().entries()) for (const page of context.pages()) {
    await page.screenshot({ path: path.join(evidence, 'failed-' + index + '.png') }).catch(() => {})
    fs.writeFileSync(path.join(evidence, 'failed-' + index + '.txt'), await page.locator('body').innerText())
  }
  throw error
} finally { await browser.close() }
fs.writeFileSync(path.join(evidence, 'result.json'), JSON.stringify({ result: 'PASS', checks, views, backendChecks: backend.checks, transport: 'isolated fixture; real MemberListView and query components', financialWrites: 0 }, null, 2))
console.log('R55_MEMBER_FILTERS_PASS checks=' + checks)

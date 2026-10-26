import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { readFileSync, mkdirSync, writeFileSync } from 'node:fs'
import { createHash } from 'node:crypto'
import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import os from 'node:os'
import { buildReportFilterQuery, reportFilterDraft, reportFilterFields, reportFilterOptions } from '../../../前端代码/shared/unified-query-vue3/src/contracts/reportFilters.js'

// 纯合同与真实 Vue 组件同时验证；合成字段不连接业务接口，不代替产品经理业务验收。
const repo = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const shared = path.join(repo, '前端代码/shared/unified-query-vue3/src')
const fields = [
  { key: 'name_snapshot', label: '名称', type: 'text', defaultQuick: true },
  { key: 'business_date', label: '业务日期', type: 'date', filter: { enabled: true } },
  { key: 'award_month', label: '归属月份', type: 'month', defaultQuick: true },
  { key: 'amount', label: '金额', type: 'decimal', defaultQuick: true },
  { key: 'status_code', label: '状态', type: 'enum', defaultQuick: true, options: [{ value: 0, label: '未完成' }, { value: false, label: '关闭' }, { value: 'done', label: '完成' }] },
  { key: 'hidden', label: '不允许筛选', type: 'text' }
]
assert.deepEqual(reportFilterFields(fields).map(item => item.key), fields.slice(0, 5).map(item => item.key))
assert.equal(reportFilterOptions(fields[4])[0].value, 0)
assert.equal(reportFilterOptions(fields[4])[1].value, false)
assert.deepEqual(reportFilterOptions({ options: [{ label: '不能反推身份' }] }), [])
const external = { pageCode: 'basic_a', page: 4, limit: 20, store_ids: [901], filters: [
  { field_key: 'name_snapshot', operator: 'contains', value: '旧名称' },
  { field: 'amount', operator: 'between', value: [0, 200] },
  { field: 'business_date', operator: 'equal', value: '2026-10-23' },
  { field: 'status_code', operator: 'eq', value: 0 },
  { field: 'drilldown_identity', operator: 'eq', value: 9901 }
] }
const original = JSON.stringify(external)
const restored = reportFilterDraft(fields, external)
assert.deepEqual(restored.amount, { min: 0, max: 200 })
assert.equal(restored.name_snapshot, '旧名称')
assert.equal(restored.business_date, '2026-10-23')
assert.equal(restored.status_code, 0)
const draft = { ...restored, name_snapshot: ' 新名称 ', award_month: '2026-09', amount: { min: '0', max: '100' } }
const payload = buildReportFilterQuery(fields, draft, external, 'basic_a')
assert.deepEqual(payload.filters, [
  { field: 'drilldown_identity', operator: 'eq', value: 9901 },
  { field: 'name_snapshot', operator: 'contains', value: '新名称' },
  { field: 'business_date', operator: 'eq', value: '2026-10-23' },
  { field: 'award_month', operator: 'eq', value: '2026-09' },
  { field: 'amount', operator: 'gte', value: '0' },
  { field: 'amount', operator: 'lte', value: '100' },
  { field: 'status_code', operator: 'eq', value: 0 }
])
assert.equal(payload.page, 1)
assert.equal(payload.filterRelation, 'all')
assert.deepEqual(reportFilterDraft(fields, null), reportFilterDraft(fields))
assert.deepEqual(buildReportFilterQuery(fields, {}, null).filters, [])
assert.deepEqual(buildReportFilterQuery(fields, {}, { filters: null }).filters, [])
const exportSnapshot = { ...external, export: { scope: 'query', fields: ['amount'] }, taskId: 'old-download' }
const nextQuery = buildReportFilterQuery(fields, restored, exportSnapshot, 'basic_a')
assert.equal(Object.hasOwn(nextQuery, 'export'), false)
assert.equal(Object.hasOwn(nextQuery, 'taskId'), false)
assert.deepEqual(exportSnapshot.export, { scope: 'query', fields: ['amount'] })
payload.store_ids.push(902)
assert.equal(JSON.stringify(external), original, '查询转换不能修改外部快照')
assert.deepEqual(buildReportFilterQuery(fields, reportFilterDraft(fields)).filters, [])
assert.throws(() => buildReportFilterQuery(fields, { amount: { min: 10, max: 0 } }), /最小值/)
assert.throws(() => buildReportFilterQuery(fields, { amount: { min: 'NaN' } }), /有效数值/)
assert.deepEqual(reportFilterDraft(fields, { filters: [{ field: 'amount', operator: 'greater_or_equal', value: 0 }] }).amount, { min: 0, max: '' })

// 内存编译当前正本 SFC，避免另建可编辑页面或改动任何既有消费者。
const require = createRequire(path.join(repo, '前端代码/cashier-v3/package.json'))
const { parse, compileScript, compileStyle } = require('@vue/compiler-sfc')
const { build } = require('esbuild')
const { chromium } = require(process.env.PLAYWRIGHT_ROOT || path.join(os.homedir(), '.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright'))
const filename = path.join(shared, 'components/UnifiedReportQueryToolbar.vue')
const { descriptor, errors } = parse(readFileSync(filename, 'utf8'), { filename })
assert.deepEqual(errors, [])
const script = compileScript(descriptor, { id: 'report-filter-test', inlineTemplate: true, genDefaultAs: 'ReportToolbar' })
const style = compileStyle({ source: descriptor.styles[0].content, filename, id: 'data-v-report-filter-test', scoped: true })
assert.deepEqual(style.errors, [])
const bundled = await build({
  stdin: { contents: `${script.content}
    import { createApp, h, reactive } from 'vue';
    ReportToolbar.__scopeId = 'data-v-report-filter-test';
    const state = reactive({ fields: ${JSON.stringify(fields)}, pageCode: 'basic_a', executedQuery: null, isQueryLoading: false });
    window.fixture = state; window.queries = [];
    createApp({ render: () => h(ReportToolbar, { ...state, onQuery: q => window.queries.push(q) }, {
      'leading-controls': () => [h('button', { class: 'button' }, '当前权限范围'), h('label', { class: 'date' }, ['从', h('input', { type: 'date', value: '2026-10-01' })]), h('label', { class: 'date' }, ['至', h('input', { type: 'date', value: '2026-10-23' })])],
      'primary-actions': () => ['列名取值来源', '刷新', '导出'].map(label => h('button', { class: 'button' }, label))
    }) }).mount('#app');`, resolveDir: path.dirname(filename), loader: 'js' },
  nodePaths: [path.join(repo, '前端代码/cashier-v3/node_modules')], bundle: true, write: false, format: 'iife', platform: 'browser'
})
const browser = await chromium.launch({ headless: true, executablePath: process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' })
const evidence = path.join(repo, 'tests/report-basic/evidence')
mkdirSync(evidence, { recursive: true })
try {
  for (const width of [1515, 610, 375]) {
    const page = await browser.newPage({ viewport: { width, height: 996 } })
    const errors = []
    page.on('pageerror', error => errors.push(error.message))
    await page.setContent('<html><head><meta charset="utf-8"></head><body><div id="app"></div></body></html>')
    await page.addStyleTag({ content: `body{margin:0;padding:12px;background:#f5f7fa;font:13px Arial}*{box-sizing:border-box}.button{border:1px solid #d8e0ea;border-radius:6px;padding:6px 11px;background:white;color:#515a6e;cursor:pointer}.button--primary{background:#2d8cf0;color:white;border-color:#2d8cf0}.date{display:flex;align-items:center;gap:6px}.date input{width:116px;min-height:34px;border:1px solid #d8e0ea;border-radius:6px;padding:6px 8px}${style.code}` })
    await page.addScriptTag({ content: bundled.outputFiles[0].text })
    const toggle = page.getByRole('button', { name: '更多筛选', exact: true })
    await toggle.waitFor()
    assert.equal(await toggle.getAttribute('aria-expanded'), 'false')
    assert.equal(await page.getByLabel('名称筛选').count(), 0)
    assert.equal(await page.locator('input[type=search]').count(), 0)
    const buttons = await page.getByRole('button').allTextContents()
    assert.deepEqual(buttons, ['当前权限范围', '更多筛选', '查询', '列名取值来源', '刷新', '导出'])
    await page.getByRole('button', { name: '查询', exact: true }).click()
    assert.deepEqual(await page.evaluate(() => window.queries.at(-1).filters), [], '初始 null 快照可正常查询')
    await page.evaluate(query => { window.fixture.executedQuery = query; window.queries = [] }, external)
    await toggle.click()
    assert.equal(await page.getByLabel('名称筛选').inputValue(), '旧名称')
    assert.equal(await page.getByLabel('金额最小值').inputValue(), '0')
    await page.getByLabel('名称筛选').fill('新名称')
    await page.getByLabel('金额最大值').fill('100')
    await page.getByLabel('状态筛选').selectOption({ label: '未完成' })
    assert.equal(await page.evaluate(() => window.queries.length), 0, '编辑与展开不查询')
    await page.getByRole('button', { name: '查询', exact: true }).click()
    const query = await page.evaluate(() => window.queries.at(-1))
    assert.equal(query.filters.find(item => item.field === 'name_snapshot').value, '新名称')
    assert.equal(query.filters.find(item => item.field === 'status_code').value, 0)
    assert.equal(query.filters.find(item => item.operator === 'lte').value, 100)
    // 宿主失败时只撤销可导出结果；保留尚未成功的草稿及上次外部快照的隐藏条件。
    await page.getByLabel('名称筛选').fill('失败后待重试')
    await page.getByLabel('金额最小值').fill('5')
    await page.getByLabel('金额最大值').fill('88')
    await page.getByLabel('状态筛选').selectOption({ label: '完成' })
    await page.evaluate(() => { window.fixture.executedQuery = null })
    assert.equal(await page.getByLabel('名称筛选').inputValue(), '失败后待重试')
    assert.equal(await page.getByLabel('金额最小值').inputValue(), '5')
    assert.equal(await page.getByLabel('金额最大值').inputValue(), '88')
    assert.equal(await page.getByLabel('业务日期筛选').inputValue(), '2026-10-23')
    assert.equal(await page.getByLabel('状态筛选').inputValue(), 'done')
    assert.equal(await page.evaluate(() => window.queries.length), 1, '失败置 null 不触发查询')
    await page.getByRole('button', { name: '查询', exact: true }).click()
    const retry = await page.evaluate(() => window.queries.at(-1))
    assert.equal(retry.page, 1)
    assert.equal(retry.filters.find(item => item.field === 'name_snapshot').value, '失败后待重试')
    assert.equal(retry.filters.find(item => item.field === 'drilldown_identity').value, 9901)
    assert.equal(retry.filters.find(item => item.operator === 'lte').value, 88)
    await page.getByRole('button', { name: '清空筛选', exact: true }).click()
    assert.equal(await page.evaluate(() => window.queries.length), 2, '清空只改草稿')
    assert.equal(await page.getByLabel('名称筛选').inputValue(), '')
    assert.equal(await page.getByLabel('金额最小值').inputValue(), '')
    assert.equal(await page.getByLabel('金额最大值').inputValue(), '')
    assert.equal(await page.getByLabel('业务日期筛选').inputValue(), '')
    assert.equal(await page.getByLabel('状态筛选').inputValue(), '')
    assert.equal(await page.locator('.date input').first().inputValue(), '2026-10-01')
    await page.getByLabel('金额最小值').fill('20')
    await page.getByLabel('金额最大值').fill('10')
    await page.getByRole('button', { name: '查询', exact: true }).click()
    assert.match(await page.getByRole('alert').innerText(), /最小值/)
    assert.equal(await page.evaluate(() => window.queries.length), 2)
    await page.getByLabel('金额最大值').fill('30')
    await page.getByLabel('名称筛选').press('Enter')
    assert.equal(await page.evaluate(() => window.queries.length), 3)
    assert.deepEqual(await page.evaluate(() => window.queries.at(-1).filters), [
      { field: 'drilldown_identity', operator: 'eq', value: 9901 },
      { field: 'amount', operator: 'gte', value: 20 },
      { field: 'amount', operator: 'lte', value: 30 }
    ], '清空后的查询删除可见筛选，隐藏下钻条件仍保留')
    await page.evaluate(() => { window.fixture.isQueryLoading = true })
    assert.equal(await page.getByRole('button', { name: '查询中', exact: true }).isDisabled(), true)
    await page.getByLabel('名称筛选').press('Enter')
    assert.equal(await page.evaluate(() => window.queries.length), 3)
    await page.evaluate(query => {
      window.fixture.isQueryLoading = false
      window.fixture.executedQuery = query
      window.fixture.executedQuery.filters[0].value = '外部恢复'
    }, external)
    await page.waitForFunction(() => document.querySelector('[aria-label="名称筛选"]').value === '外部恢复')
    await page.screenshot({ path: path.join(evidence, `report-filter-toolbar-${width}.png`), fullPage: true })
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true, '小屏不撑宽页面')
    assert.equal(await page.getByRole('button', { name: '清空筛选' }).isVisible(), true)
    await page.evaluate(() => { window.fixture.pageCode = 'basic_b' })
    await page.getByRole('button', { name: '更多筛选', exact: true }).waitFor()
    await page.getByRole('button', { name: '更多筛选', exact: true }).click()
    assert.equal(await page.getByLabel('名称筛选').inputValue(), '', '切页清空草稿')
    await page.getByRole('button', { name: '查询', exact: true }).click()
    assert.deepEqual(await page.evaluate(() => window.queries.at(-1).filters), [], '切页不携带旧下钻条件')
    await page.evaluate(() => { window.fixture.fields = [] })
    assert.equal(await page.getByRole('button', { name: '更多筛选', exact: true }).count(), 0)
    await page.evaluate(() => { window.fixture.executedQuery = null })
    await page.getByRole('button', { name: '查询', exact: true }).click()
    assert.deepEqual(await page.evaluate(() => window.queries.at(-1).filters), [])
    await page.evaluate(() => {
      window.fixture.executedQuery = { pageCode: 'basic_b', page: 9, filters: [], export: { scope: 'query', fields: ['amount'] }, taskId: 'old-task' }
    })
    await page.getByRole('button', { name: '查询', exact: true }).click()
    const normalQuery = await page.evaluate(() => window.queries.at(-1))
    assert.equal(normalQuery.page, 1, '外部第九页快照查询必须返回第一页')
    assert.equal(Object.hasOwn(normalQuery, 'export'), false, '浏览器普通查询不能重放导出')
    assert.equal(Object.hasOwn(normalQuery, 'taskId'), false)
    await page.evaluate(fields => {
      window.fixture.fields = fields
      window.fixture.executedQuery = { pageCode: 'basic_b', filters: [
        { field: 'name_snapshot', operator: 'contains', value: '下一页草稿' },
        { field: 'drilldown_identity', operator: 'eq', value: 9902 }
      ] }
    }, fields)
    await page.getByLabel('名称筛选').waitFor()
    await page.evaluate(() => { window.fixture.executedQuery = null })
    assert.equal(await page.getByLabel('名称筛选').inputValue(), '下一页草稿')
    await page.evaluate(() => { window.fixture.pageCode = 'basic_c' })
    await page.getByRole('button', { name: '更多筛选', exact: true }).click()
    assert.equal(await page.getByLabel('名称筛选').inputValue(), '', '失败 null 状态切页仍彻底清空')
    await page.getByRole('button', { name: '查询', exact: true }).click()
    assert.deepEqual(await page.evaluate(() => window.queries.at(-1).filters), [], 'null 状态切页不能遗留隐藏条件')
    assert.deepEqual(errors, [])
    await page.close()
  }
} finally { await browser.close() }
// 自测证据固定实际正本文件与基线；线上实例不适用，不能将夹具测试写成业务验收。
const changedFiles = [
  '前端代码/shared/unified-query-vue3/src/components/UnifiedReportQueryToolbar.vue',
  '前端代码/shared/unified-query-vue3/src/contracts/reportFilters.js',
  '前端代码/shared/unified-query-vue3/src/index.js',
  'tests/report-basic/js/report-filter-contract.mjs'
]
writeFileSync(path.join(evidence, 'report-filter-contract.json'), JSON.stringify({
  baseline: execFileSync('git', ['rev-parse', 'HEAD'], { cwd: repo, encoding: 'utf8' }).trim(),
  files: changedFiles.map(file => ({ file, sha256: createHash('sha256').update(readFileSync(path.join(repo, file))).digest('hex') })),
  result: 'PASS', viewports: [1515, 610, 375], synthetic: true, remote: '不适用',
  failureRetry: '同页 object→null 保留文本/日期/数值/枚举草稿与隐藏条件；清空只删除可见草稿；null 状态切页彻底隔离。',
  dataArchitecture: '仅生成后端 UnifiedQuery 筛选，不读取结果行、不计算指标、不改变事实、事务、历史或权限。',
  commentsUpdated: changedFiles.slice(0, 2),
  scope: '新增共享报表工具栏与转换合同；既有消费者未修改；reportPresentation 总入口导出由主代理新增并保留。',
  acceptance: '开发与自测完成，待产品经理确认验收'
}, null, 2))
console.log('PASS report filters: contract conversion, actual Vue interactions, external restore, page isolation, 1515/610/375px layout; synthetic fields only')

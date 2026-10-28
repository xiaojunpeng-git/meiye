import { createRequire } from 'node:module'
import { execFileSync } from 'node:child_process'
import { mkdirSync, writeFileSync } from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

// Real backend metadata + real Vue/UQ components; synthetic API rows test presentation only.
const require = createRequire(import.meta.url)
const { chromium } = require(path.join(os.homedir(), '.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright'))
const repo = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const evidence = process.env.R51_BUSINESS_EVIDENCE_DIR || path.join(repo, 'tests/report-basic/evidence')
mkdirSync(evidence, { recursive: true })
const php = 'require "vendor/autoload.php"; (new \\think\\App())->initialize(); echo json_encode(\\app\\services\\report\\BasicReportHostServices::catalog(), JSON_UNESCAPED_UNICODE);'
const catalog = JSON.parse(execFileSync('docker', ['exec', 'mohe-cashier-app', 'php', '-r', php], { encoding: 'utf8', maxBuffer: 1024 * 1024 }))
// 动态门店/逐日对账矩阵由专用浏览器测试使用原单对账证据验证，不套固定列合成夹具。
const reports = catalog.filter(report => !['basic_brush_registration', 'basic_store_reconciliation'].includes(report.code))
const fixtureBase = process.env.R51_FIXTURE_BASE_URL || 'http://127.0.0.1:18091'
const results = []
let checks = 0
function check(ok, label) { checks++; if (!ok) throw new Error(label) }
const browser = await chromium.launch({ headless: true, executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' })
try {
  for (const mode of ['platform', 'store']) for (const width of [1515, 610]) {
    const context = await browser.newContext({ viewport: { width, height: 1009 } })
    const page = await context.newPage()
    const errors = []; const requests = []
    page.on('pageerror', error => errors.push(error.message))
    await page.route('**/r51-business.html*', route => route.fulfill({ contentType: 'text/html', body: '<!doctype html><html><head><meta charset="utf-8"><style>html,body,#app{margin:0;height:100%;width:100%}*{box-sizing:border-box}</style></head><body><div id="app"></div><script type="module" src="/view_cashier_v3/src/dev/r51BasicReportFixture.js"></script></body></html>' }))
    await page.route('**/*api/**', async route => {
      const url = new URL(route.request().url()); let data
      if (url.pathname.endsWith('/basic/catalog')) data = catalog
      else if (url.pathname.endsWith('/basic/scope')) data = { allowed_store_ids: [901], authorization_mode: 'all', tree: [{ id: 1, node_type: 'org', name: '合成组织', children: [{ id: 901, store_id: 901, node_type: 'store', name: '合成门店' }] }] }
      else if (url.pathname.endsWith('/basic/query')) {
        const query = JSON.parse(url.searchParams.get('query')); requests.push(query)
        const report = catalog.find(report => report.code === query.pageCode)
        const queryFields = report.queryFields.length ? report.queryFields : [{ key: 'registration_id', label: '登记ID', type: 'integer', defaultVisible: true, width: 100 }]
        const rows = Array.from({ length: 25 }, (_, i) => Object.fromEntries(queryFields.map(field => [field.key,
          field.key === 'row_id' ? `${report.code}:${i + 1}` : field.key === 'store_id' ? 901 : field.key === 'store_name' ? '合成门店'
            : field.type === 'date' ? '2026-10-23' : field.money ? 123.45 : ['integer', 'decimal'].includes(field.type) ? i + 1 : `合成${field.label}${i + 1}`])))
        const pageNumber = query.page || 1
        data = { rows: rows.slice((pageNumber - 1) * 20, pageNumber * 20), queryFields,
          pagination: { total: 25, page: pageNumber }, can_edit: false,
          summaries: Object.fromEntries(queryFields.filter(field => field.summable).map(field => [`${field.key}:sum`, field.money ? 3086.25 : 325])),
          field_sources: Object.fromEntries(queryFields.map(field => [field.key, `合成夹具的${field.label}来源，不代表真实业务验收。`])),
          table_layout: { fixed: true }, exportRows: rows.map(row => Object.fromEntries((query.export?.fields || []).map(key => [key, row[key]]))) }
      } else { await route.abort(); return }
      await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ status: 200, data }) })
    })
    await page.goto(`${fixtureBase}/r51-business.html?mode=${mode}`)
    for (const report of reports) {
      checks = 0
      await page.getByRole('button', { name: report.name, exact: true }).click()
      await page.locator('tbody tr').first().waitFor()
      check(await page.locator('tbody tr').count() === 20, `${report.code}: twenty rows`)
      check(await page.locator('.basic-reports__header h1').innerText() === report.name, 'Correct report title')
      check(await page.getByRole('button', { name: '新增登记', exact: true }).count() === 0, 'Business reports cannot edit manual registrations')
      check(await page.locator('.organization-store-scope-picker').count() === (mode === 'platform' ? 1 : 0), 'Scope terminal boundary')
      // 21 张表共用主筛选行；门店范围、周期、筛选、查询和操作按钮不能上下错位。
      const toolbar = await page.locator('.unified-report-query-toolbar__primary').evaluate(element => {
        const children = [...element.children].filter(child => getComputedStyle(child).display !== 'none')
        return { tops: children.map(child => Math.round(child.getBoundingClientRect().top)),
          heights: children.map(child => Math.round(child.getBoundingClientRect().height)),
          scrollWidth: element.scrollWidth, clientWidth: element.clientWidth }
      })
      const centers = toolbar.tops.map((top, index) => top + toolbar.heights[index] / 2)
      check(Math.max(...centers) - Math.min(...centers) <= 3, `All primary filters share one row: ${JSON.stringify(toolbar)}`)
      check(toolbar.heights.every(height => height <= 40), 'Primary filter controls keep compact height')
      if (mode === 'platform') check(await page.getByRole('button', { name: '全部门店', exact: true }).count() === 1, 'Platform all-store scope label')
      check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'No document horizontal overflow')
      const box = await page.locator('.table-pagination').boundingBox()
      check(box && box.y + box.height <= 1010, 'Pagination inside viewport')
      check(await page.locator('.table-pagination__summary').isVisible(), 'Full count visible')
      const date = report.time_mode === 'realtime_month' ? '工资月份' : report.time_mode === 'as_of' ? '截止日期' : report.time_mode === 'current' ? null : '报表查询周期'
      if (date) check(await page.getByLabel(date, { exact: true }).isVisible(), 'Correct date mode')
      if (report.time_mode === 'current') {
        const today = await page.evaluate(() => new Intl.DateTimeFormat('sv-SE', { timeZone: 'Asia/Shanghai' }).format(new Date()))
        check(requests.at(-1).as_of_date === today && requests.at(-1).end_date === today, 'Live assets never inherit a prior report date')
        check(await page.getByLabel('截止日期', { exact: true }).count() === 0, 'Approved live table offers no historical cutoff')
      }
      if (report.time_mode === 'realtime_month') {
        // Leave a past-month draft behind to exercise the next realtime report on the same mounted page.
        const response = page.waitForResponse(value => value.url().includes('/basic/query'))
        await page.getByLabel('工资月份', { exact: true }).fill('2026-09')
        await page.getByRole('button', { name: '查询', exact: true }).click()
        await response
        check(requests.at(-1).start_date === '2026-09-01', 'Prior salary month submitted before live report transition')
      }
      check(await page.getByLabel('报表高级筛选', { exact: true }).count() === 0, 'More filters collapsed by default')
      const filter = report.queryFields.find(field => field.defaultQuick && field.type === 'text')
      if (filter) {
        await page.getByRole('button', { name: '更多筛选', exact: true }).click()
        await page.getByLabel(`${filter.label}筛选`, { exact: true }).fill('合成条件')
        const beforeClear = requests.length
        await page.getByRole('button', { name: '清空筛选', exact: true }).click()
        check(requests.length === beforeClear, 'Clear only changes draft')
        await page.getByLabel(`${filter.label}筛选`, { exact: true }).fill('合成条件')
        const response = page.waitForResponse(value => value.url().includes('/basic/query'))
        await page.getByRole('button', { name: '查询', exact: true }).click()
        await response
        check(requests.at(-1).filters.some(item => item.field === filter.key && item.operator === 'contains' && item.value === '合成条件'), 'Stable backend field filter submitted')
        check(requests.at(-1).page === 1, 'Filter submission resets page one')
        await page.getByRole('button', { name: '收起筛选', exact: true }).click()
      }
      await page.getByRole('button', { name: '下一页', exact: true }).click()
      await page.waitForFunction(() => document.querySelectorAll('tbody tr').length === 5)
      check(requests.at(-1).page === 2 && requests.at(-1).pageCode === report.code, 'Same report second-page request')
      const money = report.queryFields.find(field => field.money && field.defaultVisible)
      // 跨店结算的两项金额已被产品单独确认为保留分，其余报表仍按统一整元展示。
      if (money) check((await page.locator('tbody tr').first().innerText()).includes('123.45') === (report.code === 'basic_cross_store_settlement'), 'Money follows approved report precision')
      await page.getByRole('button', { name: '列名取值来源', exact: true }).click()
      await page.getByRole('dialog', { name: '列名取值来源' }).waitFor()
      check(await page.locator('.basic-report-dialog dd').count() === report.queryFields.filter(field => field.defaultVisible).length, 'Sources cover visible columns')
      await page.getByRole('button', { name: '关闭', exact: true }).click()
      const download = page.waitForEvent('download')
      await page.getByRole('button', { name: '导出', exact: true }).click()
      const file = await download
      check(file.suggestedFilename() === report.name + '.csv', 'Export named after current report')
      check(requests.at(-1).export.scope === 'query', 'Full same-query export')
      if (['basic_salary_realtime', 'basic_daily_reconciliation', 'basic_unconsumed_detail'].includes(report.code)) await page.screenshot({ path: path.join(evidence, `${report.code}-${mode}-${width}.png`) })
      results.push({ code: report.code, mode, width, checks, pass: true, mock_api: true })
      await page.getByRole('button', { name: '返回基础报表', exact: true }).click()
      await page.getByRole('heading', { name: '基础报表', exact: true }).waitFor()
    }
    await page.getByRole('button', { name: '刷单登记', exact: true }).click()
    await page.locator('.unified-report-query-toolbar__primary').waitFor()
    const brushCenters = await page.locator('.unified-report-query-toolbar__primary').evaluate(element => [...element.children]
      .filter(child => getComputedStyle(child).display !== 'none')
      .map(child => { const box = child.getBoundingClientRect(); return box.top + box.height / 2 }))
    check(Math.max(...brushCenters) - Math.min(...brushCenters) <= 3, 'Brush registration also keeps one primary filter row')
    check(await page.locator('.organization-store-scope-picker').count() === (mode === 'platform' ? 1 : 0), 'Brush registration terminal scope boundary')
    if (mode === 'platform') {
      await page.getByRole('button', { name: '全部门店', exact: true }).click()
      const panel = await page.locator('.organization-store-scope-picker__panel').boundingBox()
      check(panel && panel.width > 200 && panel.x >= 0 && panel.x + panel.width <= width,
        'Store picker remains reachable outside the single-row scroll area')
      const hit = await page.evaluate(() => {
        const panel = document.querySelector('.organization-store-scope-picker__panel')
        const box = panel.getBoundingClientRect()
        return panel.contains(document.elementFromPoint(box.left + box.width / 2, box.top + 20))
      })
      check(hit, 'Store picker is not clipped by horizontal toolbar scrolling')
    }
    results.push({ code: 'basic_brush_registration', mode, width, checks: mode === 'platform' ? 4 : 2, pass: true, mock_api: true })
    check(errors.length === 0, errors.join('\n'))
    await context.close()
  }
} finally { await browser.close() }
writeFileSync(path.join(evidence, 'r51-business-browser.json'), JSON.stringify({ pass: true, results,
  limitation: 'Real metadata/components with synthetic API; not authenticated business acceptance.' }, null, 2))
console.log(JSON.stringify({ pass: true, cases: results.length, assertions: results.reduce((n, row) => n + row.checks, 0) }))

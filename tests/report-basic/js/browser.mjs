import { createRequire } from 'node:module'
import { execFileSync } from 'node:child_process'
import { mkdirSync, writeFileSync } from 'node:fs'
import path from 'node:path'
import os from 'node:os'
import { fileURLToPath } from 'node:url'

// 页面使用真实Vue组件/UQ查询栏，接口为明确的合成夹具；不冒充真实HTTP业务验收。
const require = createRequire(import.meta.url)
const runtime = process.env.PLAYWRIGHT_ROOT || path.join(os.homedir(), '.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright')
const { chromium } = require(runtime)
const repo = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const evidence = path.join(repo, 'tests/report-basic/evidence')
mkdirSync(evidence, { recursive: true })
const catalog = JSON.parse(execFileSync('php', ['-r', 'require "后端代码/app/services/report/BasicReportCatalogServices.php";echo json_encode(\\app\\services\\report\\BasicReportCatalogServices::entries(), JSON_UNESCAPED_UNICODE);'], { cwd: repo, encoding: 'utf8' }))
catalog.forEach(item => { item.available = item.code === 'basic_brush_registration' })
const fields = [
  ['registration_id', '登记编号', 'integer', false, false, 100, ''],
  ['platform', '平台', 'text', true, true, 90, 'left'],
  ['store_name', '门店', 'text', true, false, 150, 'left'],
  ['registered_date', '核销日期', 'date', true, true, 130, ''],
  ['coupon_code', '劵码', 'text', true, true, 180, ''],
  ['amount', '金额', 'decimal', true, true, 120, ''],
  ['registration_type', '类型', 'text', true, true, 100, '']
].map(([key, label, type, defaultVisible, defaultQuick, width, fixed]) => ({ key, label, type, defaultVisible, defaultQuick, width, fixed }))
const results = []
const browserBase = process.env.BASIC_REPORT_BROWSER_BASE || 'http://127.0.0.1:18091'
const browser = await chromium.launch({ headless: true,
  executablePath: process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' })
let checks = 0
function assert(ok, label) { if (!ok) throw new Error(label); checks += 1 }
try {
  for (const mode of ['platform', 'store']) {
    for (const viewport of [{ width: 1515, height: 1009 }, { width: 610, height: 996 }]) {
      checks = 0
      const context = await browser.newContext({ viewport })
      const page = await context.newPage()
      const errors = []
      const requests = []
      page.on('pageerror', e => errors.push(e.message))
      page.on('requestfailed', request => errors.push(`${request.url()}: ${request.failure()?.errorText}`))
      const rows = Array.from({ length: 44 }, (_, index) => ({
        registration_id: index + 1, store_id: 901, store_name: '测试一店', platform: '抖音',
        registered_date: index === 0 ? null : '2026-10-23', coupon_code: `R51-${index + 1}`,
        amount: 100, amount_cents: 10000, registration_type: '门店', record_version: 1, source_hash: 'a'.repeat(64)
      }))
      await page.route('**/r51-fixture.html*', route => route.fulfill({ contentType: 'text/html', body: '<!doctype html><html><head><meta charset="utf-8"><style>html,body,#app{margin:0;height:100%;width:100%}*{box-sizing:border-box}</style></head><body><div id="app"></div><script type="module" src="/view_cashier_v3/src/dev/r51BasicReportFixture.js"></script></body></html>' }))
      await page.route('**/*api/**', async route => {
        const url = new URL(route.request().url())
        const path = url.pathname
        let data
        if (path.endsWith('/basic/catalog')) data = catalog
        else if (path.endsWith('/basic/scope')) data = { allowed_store_ids: [901], tree: [{ id: 1, node_type: 'org', name: '测试组织', children: [{ id: 901, store_id: 901, node_type: 'store', name: '测试一店' }] }] }
        else if (path.endsWith('/basic/query')) {
          const query = JSON.parse(url.searchParams.get('query') || '{}')
          requests.push(query)
          const number = Number(query.page || 1)
          data = { rows: rows.slice((number - 1) * 20, number * 20),
            pagination: { page: number, limit: 20, total: rows.length }, summaries: { 'amount:sum': 4400 },
            exportRows: rows.map(row => Object.fromEntries((query.export?.fields || []).map(key => [key, row[key]]))),
            queryFields: fields, can_edit: true, field_sources: { amount: '该条人工登记的金额，不自动计入现金业绩。' }, table_layout: { fixed: true } }
        } else if (path.endsWith('/basic/save')) {
          const body = route.request().postDataJSON()
          assert(body.amount === '200.25', 'Editor sends exact cent-precision text')
          const row = rows.find(item => item.registration_id === body.id)
          if (row) Object.assign(row, body, { amount: Number(body.amount), amount_cents: Number(body.amount) * 100 })
          data = { id: body.id, record_version: 2 }
        } else { await route.abort(); return }
        await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ status: 200, data }) })
      })
      await page.goto(`${browserBase}/r51-fixture.html?mode=${mode}`)
      await page.getByRole('heading', { name: '刷单登记', exact: true }).waitFor().catch(async error => {
        console.error(JSON.stringify({ mode, viewport, url: page.url(), errors, body: await page.locator('body').innerText() }))
        throw error
      })
      await page.getByRole('button', { name: '编辑', exact: true }).first().waitFor({ timeout: 10000 }).catch(async error => {
        await page.screenshot({ path: path.join(evidence, 'registration-browser-failure.png'), fullPage: true })
        console.error(JSON.stringify({ mode, viewport, errors, requests, body: await page.locator('body').innerText() }))
        throw error
      })
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'Result scroll must not widen whole page')
      assert(await page.locator('tbody tr').count() === 20, 'Actual UI has twenty rows')
      assert(await page.getByText('未填写', { exact: true }).count() === 1, 'Legacy missing date not invented')
      assert(await page.locator('.organization-store-scope-picker').count() === (mode === 'platform' ? 1 : 0), 'Scope picker terminal boundary')
      await page.locator('.basic-reports__scroll').evaluate(element => { element.scrollTop = element.scrollHeight })
      const pagination = await page.locator('.table-pagination').boundingBox()
      assert(pagination && pagination.y >= 0 && pagination.y + pagination.height <= viewport.height + 1, 'Pagination remains in viewport')
      assert(await page.locator('.table-pagination__summary').isVisible(), 'Record total and page summary remain visible on narrow screens')
      await page.getByRole('button', { name: '下一页', exact: true }).click()
      await page.waitForFunction(() => document.querySelector('tbody tr')?.textContent.includes('R51-21'))
      assert(requests.at(-1).page === 2, 'Page request keeps real page number')
      await page.getByRole('button', { name: '编辑', exact: true }).first().click()
      await page.locator('.basic-report-dialog').getByLabel('金额', { exact: true }).fill('1.234')
      await page.getByRole('button', { name: '保存', exact: true }).click()
      await page.getByRole('alert').filter({ hasText: '最多保留两位小数' }).waitFor()
      await page.locator('.basic-report-dialog').getByLabel('金额', { exact: true }).fill('200.25')
      await page.getByRole('button', { name: '保存', exact: true }).click()
      await page.locator('.basic-report-dialog').waitFor({ state: 'hidden' })
      assert(requests.at(-1).page === 1, 'Save refresh returns to first page')
      await page.getByRole('button', { name: '下一页', exact: true }).click()
      await page.getByRole('button', { name: '编辑', exact: true }).first().click()
      assert(await page.locator('.basic-report-dialog').getByLabel('金额', { exact: true }).inputValue() === '200.25', 'Saved amount survives requery and edit without rounding')
      await page.locator('.basic-report-dialog').getByRole('button', { name: '取消', exact: true }).click()
      await page.getByRole('button', { name: '列名取值来源', exact: true }).click()
      await page.getByText('该条人工登记的金额，不自动计入现金业绩。', { exact: true }).waitFor()
      await page.getByRole('button', { name: '关闭', exact: true }).click()
      const download = page.waitForEvent('download')
      await page.getByRole('button', { name: '导出', exact: true }).click()
      await download
      assert(requests.at(-1).export.scope === 'query', 'Export requests full same query')
      await page.screenshot({ path: path.join(evidence, `registration-${mode}-${viewport.width}.png`), fullPage: true })
      assert(errors.length === 0, errors.join('\n'))
      results.push({ mode, viewport, pass: true, mock_api: true, checks })
      await context.close()
    }
  }
} finally { await browser.close() }
writeFileSync(path.join(evidence, 'r51-browser-component.json'), JSON.stringify({ results, limitation: 'Real Vue/UQ components; API fixtures. Real database contract tested separately; no production transaction.' }, null, 2))
console.log(JSON.stringify({ pass: true, results }, null, 2))

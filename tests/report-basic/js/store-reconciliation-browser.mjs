import { createRequire } from 'node:module'
import { execFileSync } from 'node:child_process'
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs'
import path from 'node:path'
import os from 'node:os'
import { fileURLToPath } from 'node:url'
const require = createRequire(import.meta.url)
const { chromium } = require(path.join(os.homedir(), '.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright'))
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const evidence = path.join(root, 'tests/customer-010/evidence/round11-store-reconciliation')
const inputFile = path.join(evidence, 'local-oracle-results.json')
const input = JSON.parse(readFileSync(inputFile, 'utf8'))
const dataset = input.datasets[1]
const stores = dataset.source.stores
const id = Number(stores[0].id)
const fixture = 'http://127.0.0.1:18211'
mkdirSync(evidence, { recursive: true })
let checks = 0
const check = (condition, message) => { checks++; if (!condition) throw new Error(message) }
const browser = await chromium.launch({ headless: true, executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' })
const results = []
try {
  for (const mode of ['platform', 'store']) for (const width of [1475, 610]) {
    const context = await browser.newContext({ viewport: { width, height: 996 }, acceptDownloads: true })
    const page = await context.newPage()
    await page.clock.install({ time: new Date('2026-10-28T04:00:00Z') })
    const errors = []; const queries = []
    page.on('pageerror', error => errors.push(error.message))
    await page.route('**/r11.html*', route => route.fulfill({ contentType: 'text/html', body: '<!doctype html><html><head><meta charset="utf-8"><style>html,body,#app{height:100%;width:100%;margin:0}*{box-sizing:border-box}</style></head><body><div id="app"></div><script type="module" src="/view_cashier_v3/src/dev/r11StoreReconciliationFixture.js"></script></body></html>' }))
    await page.route('**/*api/**', async route => {
      const url = new URL(route.request().url()); let data
      if (url.pathname.endsWith('/catalog')) data = [{ code: 'basic_store_reconciliation', name: '门店对账单', group: '门店统计', time_mode: 'period', available: true, queryFields: [] }]
      else if (url.pathname.endsWith('/scope')) data = { authorization_mode: 'all', allowed_store_ids: stores.map(store => Number(store.id)), tree: [{ id: 999, title: '测试组织', node_type: 'organization', children: stores.map(store => ({ id: store.id, store_id: store.id, title: store.name, node_type: 'store' })) }] }
      else if (url.pathname.endsWith('/query')) {
        const query = JSON.parse(url.searchParams.get('query')); queries.push(query)
        const ids = mode === 'store' ? [id] : url.searchParams.get('store_ids')?.split(',').map(Number) || stores.map(store => Number(store.id))
        const payload = Buffer.from(JSON.stringify({ query, store_ids: ids })).toString('base64')
        try {
          const result = JSON.parse(execFileSync('php', [path.join(root, 'tests/report-basic/php/store-reconciliation-query.php'), inputFile, payload], { encoding: 'utf8', maxBuffer: 20 * 1024 * 1024 }))
          await route.fulfill({ contentType: 'application/json', body: JSON.stringify(result) }); return
        } catch (error) {
          const result = error.stdout?.toString() || JSON.stringify({ status: 400, msg: error.message })
          await route.fulfill({ contentType: 'application/json', body: result }); return
        }
      } else { await route.abort(); return }
      await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ status: 200, data }) })
    })
    await page.goto(`${fixture}/r11.html?mode=${mode}&storeId=${id}`)
    await page.locator('.store-reconciliation tbody tr').first().waitFor()
    check(await page.getByRole('heading', { name: '门店对账单', exact: true }).count() === 1, 'Single business title')
    check(await page.locator('.organization-store-scope-picker').count() === (mode === 'platform' ? 1 : 0), 'Terminal scope boundary')
    if (mode === 'platform') {
      await page.getByLabel('下单时间', { exact: true }).click()
      await page.getByRole('button', { name: '上月', exact: true }).click()
      await page.getByRole('button', { name: '确定', exact: true }).click()
    } else await page.getByLabel('对账月份', { exact: true }).fill('2026-09')
    const queried = page.waitForResponse(response => response.url().includes('/basic/query'))
    await page.getByRole('button', { name: '查询', exact: true }).click()
    await queried
    await page.locator('.store-reconciliation tbody tr').first().waitFor()
    check(queries.at(-1).start_date === '2026-09-01' || queries.at(-1).month === '2026-09', 'Submitted September period')
    check(await page.locator('.store-reconciliation__results thead th').count() === (mode === 'platform' ? 12 : 32), 'Store/daily dynamic columns')
    check(await page.locator('.store-reconciliation__results tbody tr').count() === dataset.matrix.rows.length, 'Configured payment rows include zero methods')
    const expected = mode === 'platform' ? dataset.matrix.rows[0].total_amount : dataset.daily.rows[0].total_amount
    const displayed = await page.locator('.store-reconciliation__results tbody tr').first().locator('td').nth(1).innerText()
    check(displayed.includes(Math.round(Number(expected)).toLocaleString('en-US')), 'Amounts match real backend oracle')
    check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'No document overflow')
    const controls = await page.locator('.unified-report-query-toolbar__primary').evaluate(node => [...node.children].filter(child => getComputedStyle(child).display !== 'none').map(child => { const rect = child.getBoundingClientRect(); return { center: rect.top + rect.height / 2, height: rect.height } }))
    check(Math.max(...controls.map(control => control.center)) - Math.min(...controls.map(control => control.center)) <= 3 && controls.every(control => control.height <= 40), 'All main filters stay compact on one line')
    const box = await page.locator('.store-reconciliation .table-pagination').boundingBox()
    check(box && box.y + box.height <= 996, 'Pagination remains within viewport')
    await page.locator('.store-reconciliation__results tbody tr').first().getByRole('button').first().click()
    await page.locator('.store-reconciliation__dialog tbody tr').first().waitFor()
    check(await page.getByRole('dialog').count() === 1, 'Precise detail dialog')
    check(await page.locator('.basic-reports').getAttribute('inert') === '', 'Modal isolates background keyboard interaction')
    check(queries.at(-1).detail.column_key === (mode === 'platform' ? 'store_' + id : 'day_1'), 'Stable backend column key')
    check(await page.locator('.store-reconciliation__detail-actions').innerText().then(text => text.includes('合计')), 'Detail complete-result total')
    const download = page.waitForEvent('download')
    await page.getByRole('button', { name: '导出明细', exact: true }).click()
    const file = await download
    const csv = readFileSync(await file.path(), 'utf8')
    check(csv.includes('列名取值来源') && csv.includes('单据号'), 'Detail export includes source explanations')
    await page.getByRole('button', { name: '关闭', exact: true }).click()
    check(await page.getByRole('dialog').count() === 0, 'Return retains matrix')
    check(await page.evaluate(() => document.activeElement?.classList.contains('store-reconciliation__cell')), 'Closing detail restores source-cell focus')
    const matrixDownload = page.waitForEvent('download')
    await page.getByRole('button', { name: '导出', exact: true }).click()
    const matrixFile = await matrixDownload
    const matrixCsv = readFileSync(await matrixFile.path(), 'utf8')
    check(matrixCsv.includes(expected), 'Matrix export keeps exact cents')
    await page.screenshot({ path: path.join(evidence, `reconciliation-${mode}-${width}.png`) })
    if (mode === 'platform') {
      const beforeSelection = queries.length
      await page.getByRole('button', { name: '全部门店', exact: true }).click()
      const panel = page.locator('.organization-store-scope-picker__panel')
      await panel.getByRole('button', { name: '测试组织', exact: true }).click()
      await panel.locator('.organization-store-scope-picker__stores').getByRole('button', { name: stores[0].name, exact: true }).click()
      check(queries.length === beforeSelection, 'Changing draft scope does not query until submission')
      await page.getByLabel('对账月份', { exact: true }).fill('2026-09')
      const singleResponse = page.waitForResponse(response => response.url().includes('/basic/query'))
      await page.getByRole('button', { name: '查询', exact: true }).click(); await singleResponse
      await page.waitForFunction(() => document.querySelectorAll('.store-reconciliation__results thead th').length === 32)
      check(queries.at(-1).reconciliation_store_id === id && queries.at(-1).reconciliation_mode === 'days', 'Single-store selection submits verified stable ID')
      await page.getByLabel('对账月份', { exact: true }).fill('2026-08')
      const frozenDownload = page.waitForEvent('download')
      await page.getByRole('button', { name: '导出', exact: true }).click(); await frozenDownload
      check(queries.at(-1).month === '2026-09', 'Unsubmitted month draft cannot change export')
      await page.getByRole('button', { name: stores[0].name, exact: true }).click()
      await panel.getByRole('button', { name: '当前权限范围', exact: true }).click()
      const allResponse = page.waitForResponse(response => response.url().includes('/basic/query'))
      await page.getByRole('button', { name: '查询', exact: true }).click(); await allResponse
      await page.waitForFunction(() => document.querySelectorAll('.store-reconciliation__results thead th').length === 12)
      check(queries.at(-1).reconciliation_mode === 'stores' && queries.at(-1).page === 1, 'Restoring scope returns matrix page one')
    }
    check(errors.length === 0, errors.join('\n'))
    results.push({ mode, width, passed: true })
    await context.close()
  }
} finally { await browser.close() }
writeFileSync(path.join(evidence, 'browser-results.json'), JSON.stringify({ passed: true, checks, results, evidence: 'Actual Vue components and PHP unified query with source-oracle-proven anonymized receipt data; authenticated online deployment is pending acceptance.' }, null, 2))
console.log(JSON.stringify({ passed: true, checks, cases: results.length }))

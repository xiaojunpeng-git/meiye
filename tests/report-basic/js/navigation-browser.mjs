import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { execFileSync } from 'node:child_process'
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import os from 'node:os'

// Tests canonical navigation + locally persisted rules with synthetic API catalogs, not an authenticated checkout.
const repo = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const app = path.join(repo, '前端代码/cashier-v3')
const require = createRequire(path.join(app, 'package.json'))
const { parse, compileScript, compileStyle } = require('@vue/compiler-sfc')
const { build } = require('esbuild')
const { chromium } = require(path.join(os.homedir(), '.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright'))
const php = 'require "vendor/autoload.php";(new \\think\\App())->initialize();$c=config("database.connections.mysql");if(!in_array($c["hostname"],["mohe-mysql","mysql"],true))throw new RuntimeException("Local only");echo json_encode(["rules"=>json_decode(\\think\\facade\\Db::name("store_v3_permission_catalog")->where("id",1)->value("rules_json"),true),"catalog"=>\\app\\services\\report\\BasicReportHostServices::catalog()],JSON_UNESCAPED_UNICODE);'
const data = JSON.parse(execFileSync('docker', ['exec', 'mohe-cashier-app', 'php', '-r', php], { encoding: 'utf8', maxBuffer: 2 * 1024 * 1024 }))
assert.equal(data.catalog.length, 21)
assert.equal(data.rules.find(row => row.id === -29023).parent_id, -2804)
assert.equal(data.rules.filter(row => row.parent_id === -29023).length, 21)
const oldCatalog = data.rules.filter(row => row.navigation_key?.startsWith('report.') && !row.navigation_key.startsWith('report.basic'))
  .map(row => ({ code: row.navigation_key.slice('report.'.length) }))
const entries = [
  { key: 'reports', label: '全部报表', featureCode: 'cashier.v3.data', to: { name: 'cashier-v3-store-business-reports' } },
  { key: 'basic-reports', navigationKey: 'report.basic', label: '基础报表', featureCode: 'cashier.v3.data', to: { name: 'cashier-v3-basic-reports' } }
]
const filename = path.join(app, 'src/components/navigation/StoreTopNavigation.vue')
const { descriptor, errors } = parse(readFileSync(filename, 'utf8'), { filename })
assert.deepEqual(errors, [])
const component = compileScript(descriptor, { id: 'r51-navigation', inlineTemplate: true, genDefaultAs: 'Navigation' })
const style = compileStyle({ source: descriptor.styles.map(row => row.content).join('\n'), filename, id: 'data-v-r51-navigation', scoped: true })
assert.deepEqual(style.errors, [])
const bundle = await build({
  stdin: { contents: `${component.content}
    import {createApp,h,reactive} from 'vue';import {createRouter,createMemoryHistory} from 'vue-router';
    const router=createRouter({history:createMemoryHistory(),routes:[
      {path:'/',name:'cashier-v3-cashier',component:{}},
      {path:'/basic/:report?',name:'cashier-v3-basic-reports',component:{}},
      {path:'/reports/:report?',name:'cashier-v3-store-business-reports',component:{}}
    ]});
    const state=reactive({rules:${JSON.stringify(data.rules)},entries:${JSON.stringify(entries)},contextId:'test-a'});
    window.fixture=state;window.navigations=[];Navigation.__scopeId='data-v-r51-navigation';
    const instance=createApp({render:()=>h(Navigation,{...state,storeName:'导航测试门店',operator:'测试操作员',onNavigate:entry=>{window.navigations.push(entry);router.push(entry.to)}})});
    instance.use(router);router.push('/').then(()=>instance.mount('#app'));`, resolveDir: path.dirname(filename), loader: 'js' },
  alias: { '@': path.join(app, 'src') }, nodePaths: [path.join(app, 'node_modules')],
  bundle: true, write: false, format: 'iife', platform: 'browser'
})
const evidence = path.join(repo, 'tests/report-basic/evidence')
mkdirSync(evidence, { recursive: true })
const browser = await chromium.launch({ headless: true, executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' })
const results = []
try {
  for (const width of [1515, 610, 375]) {
    const page = await browser.newPage({ viewport: { width, height: 1009 } })
    const errors = []
    let failBasicCatalog = false
    page.on('pageerror', error => errors.push(error.message))
    await page.route('**/r51-navigation.html', route => route.fulfill({ contentType: 'text/html', body: '<html><head><meta charset="utf-8"></head><body><div id="app"></div></body></html>' }))
    await page.route('**/cashierapi/v3/report/**', route => {
      const basic = route.request().url().endsWith('/basic/catalog')
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify(basic && failBasicCatalog
        ? { status: 400, msg: '合成目录失败' } : { status: 200, data: basic ? data.catalog : oldCatalog }) })
    })
    await page.goto('http://127.0.0.1:18091/r51-navigation.html')
    await page.addStyleTag({ content: `body{margin:0;font:13px Arial}*{box-sizing:border-box}${style.code}` })
    await page.addScriptTag({ content: bundle.outputFiles[0].text })
    const trigger = page.getByRole('button', { name: '数据中心', exact: true })
    await trigger.click()
    const heading = page.locator('.store-menu__heading[data-rule-id="-29023"]')
    await heading.waitFor()
    assert.equal(await heading.getAttribute('data-depth'), '0')
    assert.equal(await heading.innerText(), '基础报表')
    assert.equal(await page.locator('.store-menu__links>[data-rule-id="301200"]').getAttribute('data-depth'), '0')
    assert.equal(await page.getByRole('button', { name: '基础报表', exact: true }).count(), 0)
    assert.equal(await page.getByRole('button', { name: '合作方品项明细', exact: true }).count(), 1)
    for (const report of data.catalog) {
      const entry = page.getByRole('button', { name: report.name, exact: true })
      await entry.waitFor()
      assert.equal(await entry.getAttribute('data-depth'), '1')
    }
    await heading.scrollIntoViewIfNeeded()
    await page.screenshot({ path: path.join(evidence, `basic-report-navigation-${width}.png`) })
    for (const report of data.catalog) {
      await page.getByRole('button', { name: report.name, exact: true }).click()
      await page.waitForFunction(code => window.navigations.at(-1)?.to.params.report === code, report.code)
      const selected = await page.evaluate(() => window.navigations.at(-1))
      assert.equal(selected.to.name, 'cashier-v3-basic-reports')
      await trigger.click()
      assert.equal(await page.locator('.store-menu__link.is-active').innerText(), report.name)
    }
    const box = await page.locator('.store-menu').boundingBox()
    assert.ok(box.x >= 0 && box.x + box.width <= width + 1 && box.y + box.height <= 1009)
    await page.evaluate(() => { window.fixture.rules.find(row => row.id === -29023).enabled = 0 })
    await heading.waitFor({ state: 'detached' })
    assert.equal(await page.getByRole('button', { name: data.catalog[0].name, exact: true }).count(), 0)
    assert.equal(await page.getByRole('button', { name: '合作方品项明细', exact: true }).count(), 1)
    await page.evaluate(() => {
      window.fixture.rules.find(row => row.id === -29023).enabled = 1
      window.fixture.entries = window.fixture.entries.filter(row => row.navigationKey !== 'report.basic')
      window.fixture.contextId = 'test-without-basic-handler'
    })
    await page.waitForFunction(() => !document.querySelector('[data-rule-id="-29023"]'))
    assert.equal(await page.getByRole('button', { name: data.catalog[0].name, exact: true }).count(), 0)
    failBasicCatalog = true
    await page.evaluate(value => { window.fixture.entries = value; window.fixture.contextId = 'test-catalog-failure' }, entries)
    await trigger.click()
    await page.getByRole('alert').filter({ hasText: '基础报表目录加载失败' }).waitFor()
    assert.equal(await page.getByRole('button', { name: '合作方品项明细', exact: true }).count(), 1)
    assert.equal(await heading.count(), 0)
    assert.deepEqual(errors, [])
    results.push({ width, reports: 21, tier: 'data_center/basic_group/report', pass: true, api_fixture: true })
    await page.close()
  }
} finally { await browser.close() }
writeFileSync(path.join(evidence, 'basic-report-navigation-browser.json'), JSON.stringify({ pass: true, results, authenticated_business_acceptance: false }, null, 2))
console.log(JSON.stringify({ pass: true, viewports: results.length, report_navigation_checks: results.length * 21 }))

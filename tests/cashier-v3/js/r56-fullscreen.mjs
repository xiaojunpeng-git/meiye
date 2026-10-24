import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import crypto from 'node:crypto'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'
import { execFileSync } from 'node:child_process'

// Render the real navigation and native Fullscreen API in an isolated browser.
// Route/catalog transport is stubbed; no business API, login or write is invoked.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const front = path.join(root, '前端代码/cashier-v3')
const require = createRequire(path.join(front, 'package.json'))
const { parse, compileScript, compileStyle } = require('@vue/compiler-sfc')
const { build } = require('esbuild')
const { chromium } = require('/Users/xiaojunpeng/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright')
const evidence = path.join(root, 'tests/cashier-v3/evidence/R56-fullscreen')
fs.mkdirSync(evidence, { recursive: true })
const files = ['前端代码/cashier-v3/src/components/navigation/StoreTopNavigation.vue', '前端代码/cashier-v3/src/components/navigation/StoreFullscreenButton.vue', 'tests/cashier-v3/js/r56-fullscreen.mjs']
const hash = bytes => crypto.createHash('sha256').update(bytes).digest('hex')
const before = Object.fromEntries(files.map(f => [f, hash(fs.readFileSync(path.join(root, f)))]))
const styles = [fs.readFileSync(path.join(front, 'src/styles/base.css'), 'utf8')]
const bundle = await build({
  stdin: { contents: `import{createApp,h,ref}from'vue';import Nav from './src/components/navigation/StoreTopNavigation.vue';
    const shown=ref(true);window.unmountNavigation=()=>shown.value=false;
    createApp({render:()=>h('div',[shown.value?h(Nav,{storeName:'测试门店',operator:'测试操作员',operatorProfile:{name:'测试操作员'},contextId:'r56'}):null,h('input',{id:'draft',value:'unchanged draft'}),h('main',{style:'min-height:300px;background:#f5f7fa'},'页面内容')])}).mount('#app');`, resolveDir: front, loader: 'js' },
  bundle: true, write: false, format: 'iife', platform: 'browser', nodePaths: [path.join(front, 'node_modules')],
  plugins: [{ name: 'actual-navigation', setup(builder) {
    builder.onResolve({ filter: /^vue-router$/ }, () => ({ path: 'router', namespace: 'r56' }))
    builder.onResolve({ filter: /^@\/services\/(storeBusinessReportApi|basicReportApi)$/ }, () => ({ path: 'catalog', namespace: 'r56' }))
    builder.onLoad({ filter: /.*/, namespace: 'r56' }, args => ({ contents: args.path === 'router'
      ? "export const useRoute=()=>({name:'cashier-v3-member',fullPath:'/member',params:{},query:{}})"
      : 'export const queryStoreBusinessReportCatalog=async()=>[];export const loadBasicReportCatalog=async()=>[]', loader: 'js' }))
    builder.onResolve({ filter: /^@\// }, args => ({ path: path.join(front, 'src', args.path.slice(2)) + (path.extname(args.path) ? '' : '.js') }))
    builder.onLoad({ filter: /\.vue$/ }, args => {
      const { descriptor, errors } = parse(fs.readFileSync(args.path, 'utf8'), { filename: args.path }); assert.deepEqual(errors, [])
      const id = hash(args.path).slice(0, 10), script = compileScript(descriptor, { id, inlineTemplate: true, genDefaultAs: 'Component' })
      for (const style of descriptor.styles) { const out = compileStyle({ source: style.content, filename: args.path, id: 'data-v-' + id, scoped: style.scoped }); assert.deepEqual(out.errors, []); styles.push(out.code) }
      return { contents: script.content + '\nComponent.__scopeId="data-v-' + id + '";export default Component;', loader: 'js', resolveDir: path.dirname(args.path) }
    })
  } }]
})
let checks = 0
const check = (ok, label) => { assert.ok(ok, label); checks++ }
const browser = await chromium.launch({ headless: true, executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' })
async function mount(width, unsupported = false) {
  const page = await browser.newPage({ viewport: { width, height: 1009 } })
  await page.setContent('<div id="app"></div>')
  // Test this navigation's responsive container, not a redesigned cashier shell.
  await page.addStyleTag({ content: styles.join('\n') + '\nhtml,body,#app{min-width:0;width:100%;margin:0}' })
  if (unsupported) await page.evaluate(() => Object.defineProperty(document, 'fullscreenEnabled', { value: false, configurable: true }))
  await page.addScriptTag({ content: bundle.outputFiles[0].text })
  return page
}
try {
  for (const width of [1480, 960, 375]) {
    const page = await mount(width), errors = []
    page.on('pageerror', e => errors.push(e.message))
    const button = page.getByRole('button', { name: '进入全屏', exact: true })
    await button.waitFor()
    check(await button.isEnabled(), 'native fullscreen is available')
    const b = await button.boundingBox(), account = await page.getByRole('button', { name: '查看操作员信息' }).boundingBox()
    check(b.x + b.width <= account.x && Math.abs(b.y - account.y) < 18, 'fullscreen icon is immediately left of operator')
    check(b.width === 32 && b.height === 32, 'icon has stable square dimensions')
    await page.screenshot({ path: path.join(evidence, 'normal-' + width + '.png') })
    await button.click()
    await page.waitForFunction(() => document.fullscreenElement === document.documentElement)
    const exit = page.getByRole('button', { name: '退出全屏', exact: true })
    check(await exit.getAttribute('aria-pressed') === 'true', 'native entry updates icon and accessible state')
    check(await page.locator('#draft').inputValue() === 'unchanged draft', 'fullscreen preserves existing page draft')
    await page.screenshot({ path: path.join(evidence, 'fullscreen-' + width + '.png') })
    await exit.click()
    await page.waitForFunction(() => !document.fullscreenElement)
    check(await button.getAttribute('aria-pressed') === 'false', 'second click exits native fullscreen')
    await button.click()
    await page.waitForFunction(() => document.fullscreenElement)
    // Browser-initiated exits (including Esc) trigger the same fullscreenchange;
    // use the native exit API, since headless Chrome does not model window Esc.
    await page.evaluate(() => document.exitFullscreen())
    await button.waitFor()
    check(await button.isVisible(), 'external native exit synchronizes the control')
    await page.getByRole('button', { name: '查看操作员信息' }).click()
    check(await page.locator('#store-operator-profile').isVisible(), 'operator control remains independent')
    await page.getByRole('button', { name: '查看操作员信息' }).click()
    await page.evaluate(() => { window.nativeRequest = document.documentElement.requestFullscreen; document.documentElement.requestFullscreen = () => Promise.reject(new Error('denied')) })
    await button.click()
    await page.getByRole('alert').waitFor()
    check(await button.isEnabled() && await page.evaluate(() => !document.fullscreenElement), 'denial keeps normal state and allows retry')
    await page.evaluate(() => { document.documentElement.requestFullscreen = window.nativeRequest })
    await button.click()
    await page.waitForFunction(() => document.fullscreenElement)
    await page.evaluate(() => document.exitFullscreen())
    await button.waitFor()
    check(await page.getByRole('alert').count() === 0, 'successful retry clears previous error')
    await page.evaluate(() => {
      window.fullscreenCalls = 0
      document.documentElement.requestFullscreen = () => { window.fullscreenCalls++; return new Promise((_, reject) => { window.rejectFullscreen = reject }) }
    })
    await button.click()
    check(await button.isDisabled(), 'pending fullscreen transition disables repeated clicks')
    await page.evaluate(() => { document.querySelector('.store-fullscreen__button').click(); window.rejectFullscreen(new Error('denied')) })
    await page.getByRole('alert').waitFor()
    check(await page.evaluate(() => window.fullscreenCalls === 1), 'pending transition issues only one browser request')
    await page.evaluate(() => window.unmountNavigation())
    await page.waitForFunction(() => !document.querySelector('.store-navigation'))
    await page.evaluate(() => document.dispatchEvent(new Event('fullscreenchange')))
    check(errors.length === 0, 'unmount and external events produce no runtime errors')
    await page.close()
  }
  const unsupported = await mount(1480, true)
  const disabled = unsupported.getByRole('button', { name: '进入全屏', exact: true })
  check(await disabled.isDisabled(), 'unsupported browser disables native action')
  check((await disabled.getAttribute('title')).includes('不支持'), 'unsupported action has an explanatory tooltip')
  await unsupported.close()
} finally { await browser.close() }
for (const file of files) assert.equal(hash(fs.readFileSync(path.join(root, file))), before[file], 'source stable during verification')
fs.writeFileSync(path.join(evidence, 'fixed-files.json'), JSON.stringify({ head: execFileSync('git', ['rev-parse', 'HEAD'], { cwd: root, encoding: 'utf8' }).trim(), files: before,
  baselineNavigationBlob: 'e6beb4f6f72263f83191752e9bf0a9d9e0489c1b', result: 'PASS', checks, nativeFullscreen: true,
  limits: 'isolated navigation and native API; browser-chrome Esc simulated through the identical native exit event, not an OS-window test',
  onlineInstances: 'Not applicable: local only', businessWrites: 0, commit: false, push: false, deployment: false }, null, 2))
console.log('R56_FULLSCREEN_PASS checks=' + checks)

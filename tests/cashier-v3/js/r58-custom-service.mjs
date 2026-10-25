import assert from 'node:assert/strict'
import fs from 'node:fs'
import vm from 'node:vm'
import path from 'node:path'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'
const root = fileURLToPath(new URL('../../../', import.meta.url))
const require = createRequire(path.join(root, '前端代码/cashier-v3/package.json'))
const memberRequire = createRequire('/Applications/HBuilderX.app/Contents/HBuilderX/plugins/uniapp-cli/package.json')
const { parse, compileTemplate } = require('@vue/compiler-sfc')
const { transformSync, build } = require('esbuild')
const read = file => fs.readFileSync(path.join(root, file), 'utf8')
const evidence = path.join(root, 'tests/cashier-v3/evidence/R58-custom-service')
fs.mkdirSync(evidence, { recursive: true })
let checks = 0
const check = (value, expected, label) => { assert.deepEqual(value, expected, label); checks++ }
function options(source, extra = {}) {
  const context = vm.createContext({ module: { exports: {} }, uni: {},
    require: name => new Proxy({ __esModule: true, default: {} }, { get: (object, key) => key in object ? object[key] : extra[key] || (() => {}) }), ...extra })
  vm.runInContext(transformSync(parse(source).descriptor.script.content, { loader: 'js', format: 'cjs' }).code, context)
  return context.module.exports.default
}
const form = options(read('前端代码/uniapp/pages/activity/reservation/index.vue'))
const pending = []
const fixture = { storeId: 1, orderId: 1, customServices: [], customServiceEnabled: false, customServiceEpoch: 0, customServiceError: '', butlerEdit: false }
const formWithTransport = options(read('前端代码/uniapp/pages/activity/reservation/index.vue'), {
  getReservationCustomServices: id => new Promise(resolve => pending.push({ id, resolve }))
})
fixture.loadCustomServices = formWithTransport.methods.loadCustomServices.bind(fixture)
const a = fixture.loadCustomServices(); fixture.storeId = 2; const b = fixture.loadCustomServices(); fixture.storeId = 1; const c = fixture.loadCustomServices()
pending[0].resolve({ data: { enabled: true } }); await a
check(fixture.customServiceEnabled, false, 'A-B-A late response ignored')
pending[1].resolve({ data: { enabled: true } }); await b
check(fixture.customServiceEnabled, false, 'Other store response ignored')
pending[2].resolve({ data: { enabled: true } }); await c
check(fixture.customServiceEnabled, true, 'Current menu accepted')
fixture.customServices = [{ id: 1, name: '馄饨', quantity: 2 }]
form.watch.storeId.call(fixture, 1, '1')
check(fixture.customServices.length, 1, 'Same numeric store identity preserves selection')
fixture.storeId = 2; form.watch.storeId.call(fixture, 2, 1)
check(fixture.customServices.length, 0, 'Actual store switch clears selection')
// Compile every actual detail surface; legacy records have optional empty lines.
for (const file of ['前端代码/cashier-v3/src/components/reservation/ReservationDetailOverlay.vue',
  '前端代码/mobile-vue3/src/merchant/pages/reservation-confirmations/index.uvue',
  '前端代码/mobile-vue3/src/merchant/pages/reservations/index.uvue']) {
  const { descriptor, errors } = parse(read(file)); check(errors, [], file)
  check(compileTemplate({ source: descriptor.template.content, filename: file, id: 'r58' }).errors, [], 'Detail template compiles')
  if (descriptor.scriptSetup?.lang === 'uts') transformSync(descriptor.scriptSetup.content, { loader: 'ts' })
  check(descriptor.template.content.includes('customServices'), true, 'Snapshot visible')
}
const picker = parse(read('前端代码/uniapp/pages/activity/reservation_custom_services/index.vue')).descriptor
const compiler = memberRequire('vue-template-compiler')
// Bridge uni view/text to HTML for this isolated Vue2 browser renderer;
// otherwise web Vue misinterprets both names as SVG elements.
const compiled = compiler.compile(picker.template.content, { modules: [{ preTransformNode(node) {
  if (node.tag === 'view') node.tag = 'div'
  else if (node.tag === 'text') node.tag = 'span'
  return node
} }] })
check(compiled.errors, [], 'Vue2 picker compiles')
check(compiler.compile(parse(read('前端代码/uniapp/pages/activity/reservation/index.vue')).descriptor.template.content).errors, [], 'Vue2 booking compiles')
const runtime = memberRequire.resolve('vue/dist/vue.esm.js')
const script = picker.script.content.replace(/^import .*$/gm, '').replace('export default', 'const options =')
const bundle = await build({ stdin: { contents: `import Vue from ${JSON.stringify(runtime)};
  const colors={data(){return {colorStyle:''}}}; const channel={on:(name,fn)=>{window.initial=fn},emit:(name,data)=>window.chosen=data};
  const uni={navigateBack:()=>window.back=true};window.requests=[];
  const getReservationCustomServices=id=>new Promise((resolve,reject)=>window.requests.push({id,resolve,reject}));
  ${script}
  options.render=new Function(${JSON.stringify(compiled.render)});options.staticRenderFns=${JSON.stringify(compiled.staticRenderFns)}.map(code=>new Function(code));
  options.methods.getOpenerEventChannel=()=>channel;
  window.component=new Vue(options).$mount('#app');options.onLoad.call(window.component,{store_id:135});`, loader: 'js', resolveDir: root }, bundle: true, write: false,
  define: { 'process.env.NODE_ENV': '"production"' }, format: 'iife', platform: 'browser' })
const { chromium } = require('/Users/xiaojunpeng/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright')
const browser = await chromium.launch({ executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true })
try {
  for (const width of [390, 1465]) {
    const page = await browser.newPage({ viewport: { width, height: 844 } }), errors = []
    page.on('pageerror', error => errors.push(error.message))
    await page.setContent('<html><head><meta charset="utf-8"></head><body style="margin:0"><div id="app"></div></body></html>')
    await page.addStyleTag({ content: picker.styles[0].content + 'view,text{display:block}button{border:0;cursor:pointer}*{box-sizing:border-box}' })
    await page.addScriptTag({ content: bundle.outputFiles[0].text })
    check(await page.getByText('正在加载…').count(), 1, 'Loading state')
    await page.evaluate(() => { window.initial([{ id: 1, quantity: 2 }]);window.requests[0].resolve({ data: { enabled: true, records: [{id:1,name:'馄饨'},{id:2,name:'花茶'},{id:3,name:'名称很长的定制餐点用于验证换行不遮挡数量控件'}] } }) })
    await page.evaluate(() => new Promise(resolve => window.component.$nextTick(resolve)))
    await page.getByRole('button', { name: '增加花茶', exact: true }).click()
    check(await page.locator('.quantity').allTextContents(), ['2', '1', '0'], 'Independent quantities and restored selection')
    check(await page.getByRole('button', { name: '减少名称很长的定制餐点用于验证换行不遮挡数量控件', exact: true }).isDisabled(), true, 'Zero lower bound')
    check(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true, 'No horizontal overflow')
    await page.screenshot({ path: path.join(evidence, `picker-${width}.png`), fullPage: true })
    await page.getByRole('button', { name: '确定', exact: true }).click()
    check(await page.evaluate(() => window.chosen.map(row => ({ id:row.id,quantity:row.quantity }))), [{id:1,quantity:2},{id:2,quantity:1}], 'Confirm returns only selected rows')
    await page.evaluate(() => { window.component.load();window.requests[1].resolve({data:{enabled:false,records:[]}}) })
    await page.getByText('门店暂未开启定制服务').waitFor()
    await page.getByRole('button', { name: '确定', exact: true }).click()
    check(await page.evaluate(() => window.chosen), [], 'Closed menu can clear stale selection')
    await page.evaluate(() => { window.component.load();window.requests[2].reject(new Error('offline')) })
    await page.getByText('定制服务加载失败，请重试').waitFor()
    check(await page.getByRole('button', { name: '确定', exact: true }).isDisabled(), true, 'Failure cannot confirm empty data')
    await page.getByRole('button', {name:'重试',exact:true}).click()
    await page.evaluate(() => window.requests[3].resolve({data:{enabled:true,records:[]}}))
    await page.getByText('暂无可选定制服务').waitFor()
    check(errors, [], 'No browser errors'); await page.close()
  }
} finally { await browser.close() }
fs.writeFileSync(path.join(evidence, 'frontend.json'), JSON.stringify({ result: 'PASS', checks, fixtureTransport: true, businessWrites: false }, null, 2) + '\n')
console.log('R58_FRONTEND_PASS checks=' + checks)

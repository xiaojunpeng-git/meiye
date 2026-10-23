// Mount the actual reservation SFC and stylesheet in an isolated browser.
// Only transport and unrelated overlays are fixtures; no real bookings are written.
const fs = require('node:fs'), path = require('node:path'), http = require('node:http'), assert = require('node:assert/strict')
const root = path.resolve(__dirname, '../../..')
const project = path.join(root, '前端代码/cashier-v3')
const { parse, compileScript } = require(path.join(project, 'node_modules/@vue/compiler-sfc'))
const runtime = process.env.CODEX_NODE_MODULES || '/Users/xiaojunpeng/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules'
const { chromium } = require(path.join(runtime, 'playwright'))
// Test the real bridge too: a mocked component transport alone cannot detect
// missing client manifest registration or accidental empty-context rejection.
const realBridge = require(path.join(project, 'node_modules/esbuild')).buildSync({
  entryPoints: [path.join(project, 'src/services/cashierV3Bridge.js')], bundle: true,
  format: 'esm', platform: 'browser', write: false, external: ['vue'], define: { 'import.meta.env.DEV': 'false' }
}).outputFiles[0].text.replace(/from ["']vue["']/g, "from '/vue.mjs'")
const source = fs.readFileSync(path.join(project, 'src/views/ReservationListView.vue'), 'utf8')
const { descriptor } = parse(source)
let component = compileScript(descriptor, { id: 'r27', inlineTemplate: true }).content
component = component.replace(/from ['"]vue['"]/g, "from '/vue.mjs'")
  .replace(/import \{ useRouter \} from 'vue-router'/, 'const useRouter = () => ({ push() {} })')
  .replace(/import (\w+) from ['"]@\/components\/[^'"]+['"]/g, (_, name) => `const ${name} = ${name === 'UnifiedQueryToolbar' ? "{template: '<div class=\"r27-fixture-toolbar\"><slot name=\"leading-controls\"/><slot name=\"primary-actions\"/></div>'}" : "{template:'<div/>'}"}`)
  .replace(/from ['"]@\/services\/cashierV3Bridge['"]/, "from '/bridge.mjs'")
const colors = new Map(), requests = []
let failNext = false
let holdService = false, releaseService = null
const blocks = [
  { reservationId: 27, memberName: '吕后芹99', start: '10:00', end: '11:00', projectCount: 0, status: '待服务', roomName: '待分配房间' },
  { reservationId: 28, memberName: '许菊红', start: '09:00', end: '10:00', projectCount: 1, status: '待确认', roomName: '玫瑰' },
  { reservationId: 29, memberName: '房萌萌', start: '11:00', end: '13:00', projectCount: 2, status: '服务中', roomName: '水仙' }
]
function fixture() {
  return { currentStore: { name: '本地隔离测试' }, reservation: { records: blocks.map(b => ({ ...b, id: b.reservationId, projects: b.projectCount ? [{ projectId: 1 }] : [], craftsmen: [{ id: 1 }] })), total: 3, calendar: {
    date: '2026-10-23', timeSlots: Array.from({ length: 18 }, (_, i) => `${String(9 + Math.floor(i / 2)).padStart(2, '0')}:${i % 2 ? '30' : '00'}`),
    resources: [...['汪永梅', '王盼盼', '孟自文', '张青青', '王圣慧', '王美红', '胡佩秋', '未分配'].map((name, i) => ({id: 'staff:' + (i + 1), name, type: 'staff'})), { id: 'room:1', name: '房间', type: 'room' }],
    blocks: blocks.flatMap((b, i) => [`staff:${i % 2 + 1}`, 'room:1'].map(resourceId => ({ ...b, resourceId, id: `${b.reservationId}:${resourceId}`, colorMark: colors.get(b.reservationId) || '' })))
  } } }
}
const bridge = `import { reactive } from '/vue.mjs';
const state=reactive(await (await fetch('/state')).json());
export const useCashierV3State=()=>state;
export const getCashierV3PublicVersion=()=>1;
export const openCashierV3QueryEntitySelector=()=>{};
export const createCashierV3CommandId=(p)=>p+'-'+crypto.randomUUID();
export async function requestCashierV3Action(action,payload){
 const result=await(await fetch('/action',{method:'POST',body:JSON.stringify({action,payload})})).json();
 if(action==='query-reservations')Object.assign(state,await(await fetch('/state')).json());
 return result;
}`
const html = '<!doctype html><meta charset="utf-8"><title>预约颜色标记隔离验证</title><link rel="stylesheet" href="/base.css"><style>#app{padding:16px}.r27-fixture-toolbar{display:flex;gap:12px;padding:12px;background:white}</style><div id="app"></div><script type="module">import {createApp} from "/vue.mjs";import Component from "/component.mjs";createApp(Component).mount("#app")</script>'
const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://localhost').pathname
  const files = { '/vue.mjs': path.join(project, 'node_modules/vue/dist/vue.esm-browser.js'), '/base.css': path.join(project, 'src/styles/base.css') }
  res.setHeader('Content-Type', url.endsWith('.mjs') ? 'text/javascript' : url.endsWith('.css') ? 'text/css' : 'text/html;charset=utf-8')
  if (files[url]) return res.end(fs.readFileSync(files[url]))
  if (url === '/component.mjs') return res.end(component)
  if (url === '/bridge.mjs') return res.end(bridge)
  if (url === '/real-bridge.mjs') return res.end(realBridge)
  if (url === '/transport') return res.end(`<!doctype html><pre id="result"></pre><script type="module">
    import {requestCashierV3Action,cashierV3State,mergeCashierV3PublicVersions,clearCashierV3PublicVersions} from '/real-bridge.mjs';
    const sent=[]; let wrongBinding=false;
    window.__CASHIER_V3_ADAPTER__={request:async(action,payload)=>{
      sent.push({action,payload});
      return {result:{status:'success'},action,boundAction:action,
        boundIdempotencyKey:wrongBinding?'wrong':payload.command.idempotencyKey,
        boundCorrelationId:payload.correlationId,stateContextId:payload.stateContextId||''};
    }};
    const payload={reservationId:27,color:'red',idempotencyKey:'RESERVATION_ACTION-11111111-1111-4111-8111-111111111127',silent:true};
    const saved=await requestCashierV3Action('mark-reservation-color',payload);
    wrongBinding=true;
    const rejected=await requestCashierV3Action('mark-reservation-color',payload);
    wrongBinding=false;
    cashierV3State.stateContextId='r27-test-context';
    mergeCashierV3PublicVersions([{kind:'reservation',id:'27',version:7}],'r27-test-context',{requestStateContextId:'r27-test-context'});
    const ended=await requestCashierV3Action('end-reservation-service',{reservationId:27,serviceOrderId:9027,serviceOrderVersion:null,idempotencyKey:'RESERVATION_ACTION-22222222-2222-4222-8222-222222222227',silent:true});
    clearCashierV3PublicVersions('r27-test-context');
    const missingReservation=await requestCashierV3Action('end-reservation-service',{reservationId:27,serviceOrderId:9027,idempotencyKey:'RESERVATION_ACTION-33333333-3333-4333-8333-333333333327',silent:true,__contextRecoveryAttempted:true});
    document.querySelector('#result').textContent=JSON.stringify({saved:saved.result.status,rejected:rejected.result.status,ended:ended.result.status,missingReservation:missingReservation.result.code,sent:sent.map(r=>({action:r.action,contexts:r.payload.command.contexts,key:r.payload.command.idempotencyKey}))});
  </script>`)
  if (url === '/state') return res.end(JSON.stringify(fixture()))
  if (url === '/action') {
    let body = ''; for await (const part of req) body += part
    const command = JSON.parse(body); requests.push(command)
    if (command.action === 'mark-reservation-color') {
      if (failNext) { failNext = false; return res.end(JSON.stringify({ result: { status: 'result_unknown', message: '保存结果尚未确认，请重试。' } })) }
      colors.set(command.payload.reservationId, command.payload.color)
    }
    if (['start-reservation-service', 'end-reservation-service'].includes(command.action)) {
      if (holdService) await new Promise(resolve => { releaseService = resolve })
      const record = blocks.find(b => b.reservationId === command.payload.reservationId)
      if (!record) throw new Error('Fixture command targeted another booking')
      record.status = command.action === 'start-reservation-service' ? '服务中' : '已结束'
      if (command.action === 'start-reservation-service') record.serviceOrderId = 9027
    }
    return res.end(JSON.stringify({ result: { status: 'success' }, data: { reservationColorMark: {} } }))
  }
  res.end(html)
})
;(async () => {
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve))
  const browser = await chromium.launch({ channel: 'chrome', headless: true })
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } })
  const errors = []; page.on('pageerror', e => errors.push(e.message))
  const card = () => page.locator('.reservation-calendar-block').filter({ hasText: '吕后芹99' }).first()
  const menu = () => page.getByRole('group', { name: '预约颜色标记' })
  try {
    await page.goto(`http://127.0.0.1:${server.address().port}`)
    await card().waitFor()
    assert.equal(await card().locator('.reservation-calendar-status').innerText(), '待服务')
    for (const [value, label] of [['red', '红色'], ['yellow', '黄色'], ['blue', '蓝色'], ['green', '绿色'], ['purple', '紫色']]) {
      await card().click({ button: 'right' })
      assert.equal(await menu().locator('.reservation-color-menu__swatch').count(), 5)
      const geometry = await menu().getByRole('button', { name: label, exact: true }).evaluate(el => ({ width: el.offsetWidth, height: el.offsetHeight, radius: getComputedStyle(el).borderRadius }))
      assert(geometry.width === geometry.height && geometry.radius === '50%')
      await menu().getByRole('button', { name: label, exact: true }).click()
      await page.waitForFunction(c => document.querySelector('.reservation-calendar-block--mark-' + c), value)
      await page.reload(); await card().waitFor()
      assert((await card().getAttribute('class')).includes('--mark-' + value))
      assert.equal(await card().locator('.reservation-calendar-status').innerText(), '待服务')
    }
    await card().click({ button: 'right' })
    await page.screenshot({ path: path.join(root, 'tests/cashier-v3/evidence/R27-reservation-color-2026-10-23/menu-browser.png') })
    await page.keyboard.press('Escape'); assert.equal(await menu().count(), 0)
    await card().click({ button: 'right' }); await page.locator('.reservation-page__toolbar').click(); assert.equal(await menu().count(), 0)
    await card().click({ button: 'right' }); await menu().getByRole('button', { name: '清除标记' }).click()
    await page.waitForFunction(() => !document.querySelector('.reservation-color-menu'))
    await page.reload(); await card().waitFor(); assert(!(await card().getAttribute('class')).includes('--mark-'))
    failNext = true
    await card().click({ button: 'right' }); await menu().getByRole('button', { name: '红色', exact: true }).click()
    await menu().getByRole('alert').waitFor()
    assert(!(await card().getAttribute('class')).includes('--mark-'))
    await menu().getByRole('button', { name: '红色', exact: true }).click()
    await page.waitForFunction(() => document.querySelector('.reservation-calendar-block--mark-red'))
    const marks = requests.filter(r => r.action === 'mark-reservation-color')
    assert.equal(marks.at(-1).payload.idempotencyKey, marks.at(-2).payload.idempotencyKey)
    assert(!requests.some(r => r.action === 'open-reservation-detail'), 'right click must not open detail')
    await page.getByRole('button', { name: '按房间', exact: true }).click()
    assert((await card().getAttribute('class')).includes('--mark-red'))
    await card().click({ button: 'right' }); const bounds = await menu().boundingBox(); assert(bounds.x >= 0 && bounds.x + bounds.width <= 1280 && bounds.y + bounds.height <= 900)
    // Service shortcuts must follow the same state machine and prerequisite flow.
    await menu().getByRole('button', { name: '开始服务', exact: true }).click()
    await menu().getByRole('alert').waitFor()
    assert(requests.some(r => r.action === 'open-reservation-editor'), 'missing project opens original preparation path')
    assert(!requests.some(r => r.action === 'start-reservation-service'), 'missing project cannot start service')
    await page.keyboard.press('Escape')
    await page.locator('.reservation-calendar-block').filter({ hasText: '许菊红' }).first().click({ button: 'right' })
    assert.equal(await menu().locator('.reservation-color-menu__service').count(), 0, 'pending confirmation cannot start/end')
    await page.keyboard.press('Escape')
    blocks[0].projectCount = 1
    await page.reload(); await card().waitFor()
    holdService = true
    await card().click({ button: 'right' }); await menu().getByRole('button', { name: '开始服务', exact: true }).click()
    await menu().getByRole('button', { name: '处理中…', exact: true }).waitFor()
    assert(await menu().getByRole('button', { name: '处理中…', exact: true }).isDisabled())
    assert(await menu().getByRole('button', { name: '红色', exact: true }).isDisabled())
    await page.waitForFunction(() => document.querySelector('.reservation-color-menu')?.getAttribute('aria-busy') === 'true')
    while (!releaseService) await new Promise(resolve => setTimeout(resolve, 5))
    releaseService(); holdService = false
    await page.waitForFunction(() => [...document.querySelectorAll('.reservation-calendar-block')].some(el => el.textContent.includes('吕后芹99') && el.textContent.includes('服务中')))
    assert.equal(requests.filter(r => r.action === 'start-reservation-service').length, 1)
    await card().click({ button: 'right' }); assert.equal(await menu().getByRole('button', { name: '开始服务', exact: true }).count(), 0)
    await page.screenshot({ path: path.join(root, 'tests/cashier-v3/evidence/R27-reservation-color-2026-10-23/service-menu-browser.png') })
    await menu().getByRole('button', { name: '结束服务', exact: true }).click()
    await page.waitForFunction(() => [...document.querySelectorAll('.reservation-calendar-block')].some(el => el.textContent.includes('吕后芹99') && el.textContent.includes('已结束')))
    assert.equal(requests.filter(r => r.action === 'end-reservation-service').length, 1)
    assert.equal(colors.get(27), 'red', 'service changes preserve manual color')
    await card().click({ button: 'right' }); assert.equal(await menu().locator('.reservation-color-menu__service').count(), 0, 'completed booking has no service shortcuts')
    await page.keyboard.press('Escape'); await card().click()
    await page.waitForFunction(() => true)
    assert(requests.some(r => r.action === 'open-reservation-detail'), 'left click still opens detail')
    assert.deepEqual(errors, [])
    await page.goto(`http://127.0.0.1:${server.address().port}/transport`)
    await page.waitForFunction(() => document.querySelector('#result')?.textContent)
    const transport = JSON.parse(await page.locator('#result').innerText())
    assert.equal(transport.saved, 'success')
    assert.equal(transport.rejected, 'result_unknown')
    assert.equal(transport.sent.length, 3)
    assert(transport.sent.slice(0, 2).every(r => r.action === 'mark-reservation-color' && r.contexts.length === 0))
    assert.equal(transport.ended, 'success', 'linked service without a public service version must still allow reservation end')
    assert.deepEqual(transport.sent[2].contexts, [{kind:'reservation',id:27,expectedVersion:7}])
    assert.equal(transport.missingReservation, 'INVALID_COMMAND_CONTEXT', 'reservation concurrency guard remains required')
    console.log('R27 browser PASS: five circles, save/reload/clear, status, independent bookings, retry key, dismiss, room view and left click')
    console.log('R27 real bridge PASS: registered command sends empty contexts; rejects mismatched receipt binding')
    console.log('R27 service menu PASS: original start/end actions, prerequisite protection, pending/completed hiding, busy lock, refresh and color preservation')
    console.log('R27 linked service regression PASS: real bridge sends only reservation context; missing reservation version still blocks')
  } finally { await browser.close(); server.close() }
})().catch(error => { console.error(error); server.close(); process.exitCode = 1 })

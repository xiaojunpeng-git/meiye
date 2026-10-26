// Exercise the actual page, shared query toolbar, pagination and CSS in Chrome.
// Only transport and unrelated overlays are fixtures; no customer endpoint is used.
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict')
const { pathToFileURL } = require('node:url')
const root = path.resolve(__dirname, '../../..'), project = path.join(root, '前端代码/cashier-v3')
const output = path.join(root, 'tests/cashier-v3/evidence/m012-reservation-shortcuts-2026-10-26')
const runtime = process.env.CODEX_NODE_MODULES || '/Users/xiaojunpeng/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules'
const { chromium } = require(path.join(runtime, 'playwright'))
const fixtures = Array.from({ length: 23 }, (_, i) => ({ id: i + 1, reservationId: i + 1, reservationNo: `TEST-${i + 1}`, memberName: `跨日会员${i + 1}`, appointmentTime: `2030-01-0${1 + i % 3} 12:00`, status: '待确认', projectSummary: '测试项目' }))
const calendar = { date: '2030-01-01', timeSlots: ['12:00', '12:30', '13:00'], resources: [{ id: 'staff:1', type: 'staff', name: '测试手艺人' }, { id: 'room:1', type: 'room', name: 'VIP1' }], blocks: [true, false].flatMap((custom, i) => ['staff:1', 'room:1'].map(resourceId => ({ id: `${i}:${resourceId}`, reservationId: i + 1, resourceId, memberName: custom ? '含定制服务会员' : '普通预约会员', start: i ? '13:00' : '12:00', end: i ? '14:00' : '13:00', projectCount: 1, roomName: 'VIP1', status: '已结束', hasCustomServices: custom, colorMark: 'purple' }))) }
const bridge = `import {reactive} from 'vue';
const initial=${JSON.stringify({ records: fixtures.slice(0, 2), total: 2, page: 1, pageSize: 20, calendar, quickCounts: { all: 2, pendingConfirmation: 0, unstarted: 0, serving: 0, allPendingConfirmation: 23 } })};
const rows=${JSON.stringify(fixtures)};
export const state=reactive({operator:{account:'m012-test'},currentStore:{id:1,name:'本地隔离测试'},reservation:structuredClone(initial)});
window.__m012Requests=[];
export const useCashierV3State=()=>state;
export const getCashierV3PublicVersion=()=>1;
export const openCashierV3QueryEntitySelector=()=>{};
export const createCashierV3CommandId=()=>crypto.randomUUID();
export async function requestCashierV3Action(action,payload){
 window.__m012Requests.push({action,payload:structuredClone(payload)});
 if(action==='query-reservations'){
   if(payload.quickFilter==='all_pending_confirmation'){
     let found=rows;
     for(const f of payload.topFilters||[])if(f.field==='member_name')found=found.filter(r=>r.memberName.includes(f.value));
     const page=payload.page||1,size=payload.pageSize||20;
     state.reservation={...structuredClone(initial),quickCounts:{...initial.quickCounts,all:24,pendingConfirmation:23},records:found.slice((page-1)*size,page*size),total:found.length,page,pageSize:size};
   }else state.reservation=structuredClone(initial);
 }
 return {result:{status:'success'}};
}`
;(async () => {
  const { createServer } = await import(pathToFileURL(path.join(project, 'node_modules/vite/dist/node/index.js')))
  const vue = (await import(pathToFileURL(path.join(project, 'node_modules/@vitejs/plugin-vue/dist/index.mjs')))).default
  const server = await createServer({ root: project, configFile: false, logLevel: 'error',
    resolve: { dedupe: ['vue'], alias: { '@': path.join(project, 'src'), '@lucide/vue': path.join(project, 'node_modules/@lucide/vue') } },
    optimizeDeps: { entries: [], include: ['vue', 'vue-router'], exclude: ['@mohe/unified-query-vue3'] },
    server: { host: '127.0.0.1', port: 0, fs: { allow: [root] } },
    plugins: [{ name: 'm012-fixture', enforce: 'pre',
      resolveId(id) {
        if (id.endsWith('/cashierV3Bridge') || id.endsWith('/cashierV3Bridge.js')) return '\0m012-bridge'
        if (/\/Reservation(Editor|Detail)Overlay.vue$/.test(id)) return '\0m012-overlay'
      },
      load(id) { if (id === '\0m012-bridge') return bridge; if (id === '\0m012-overlay') return 'export default {template:"<div/>"}' },
      configureServer(s) { s.middlewares.use('/__m012', async (_, res) => {
        res.setHeader('Content-Type', 'text/html'); res.end(await s.transformIndexHtml('/__m012', '<!doctype html><meta charset="utf-8"><div id="app" style="height:100vh;padding:12px;box-sizing:border-box"></div><script type="module">import {createApp} from "vue";import {createRouter,createMemoryHistory} from "vue-router";import Page from "/src/views/ReservationListView.vue";import "/src/styles/base.css";createApp(Page).use(createRouter({history:createMemoryHistory(),routes:[]})).mount("#app")</script>'))
      }) }
    }, vue()] })
  let browser
  try {
    await server.listen()
    browser = await chromium.launch({ channel: 'chrome', headless: true })
    const page = await browser.newPage({ viewport: { width: 1280, height: 877 } })
    const errors = []; page.on('pageerror', e => errors.push(e.message))
    await page.goto(`http://127.0.0.1:${server.httpServer.address().port}/__m012`)
    const shortcut = () => page.getByRole('button', { name: '全部待确认（23）', exact: true })
    await shortcut().waitFor()
    const buttons = await page.locator('.reservation-calendar-toolbar__quick button').allTextContents()
    assert.match(buttons.at(-2), /服务中/); assert.match(buttons.at(-1), /全部待确认/)
    const customCard = () => page.locator('.reservation-calendar-block').filter({ hasText: '含定制服务会员' })
    assert.equal(await customCard().locator('[aria-label="含定制服务"]').count(), 1)
    assert.equal(await page.locator('.reservation-calendar-block').filter({ hasText: '普通预约会员' }).locator('[aria-label="含定制服务"]').count(), 0)
    const bounds = await customCard().evaluate(el => { const a=el.getBoundingClientRect(),b=el.querySelector('[aria-label="含定制服务"]').getBoundingClientRect();return {right:a.right-b.right,bottom:a.bottom-b.bottom} })
    assert(bounds.right >= 6 && bounds.right <= 10 && bounds.bottom >= 5 && bounds.bottom <= 9)
    assert.equal(await customCard().locator('.reservation-calendar-status').innerText(), '已结束')
    await page.screenshot({ path: path.join(output, 'calendar-desktop.png') })
    await page.getByRole('button', { name: '按房间', exact: true }).click()
    assert.equal(await customCard().locator('[aria-label="含定制服务"]').count(), 1)
    const member = page.getByPlaceholder('请输入会员姓名')
    await member.fill('旧会员条件')
    await shortcut().click()
    await page.locator('.reservation-page--list').waitFor()
    assert.equal(await member.inputValue(), '')
    const latest = await page.evaluate(() => window.__m012Requests.at(-1))
    assert.equal(latest.action, 'query-reservations'); assert.equal(latest.payload.quickFilter, 'all_pending_confirmation')
    assert.deepEqual(latest.payload.topFilters, []); assert.equal(latest.payload.page, 1); assert.equal(latest.payload.pageSize, 20)
    assert.equal(await page.locator('.reservation-list-table tbody tr').count(), 20)
    await page.getByRole('button', { name: '下一页', exact: true }).click()
    await page.waitForFunction(() => window.__m012Requests.at(-1).payload.page === 2)
    assert.equal(await page.locator('.reservation-list-table tbody tr').count(), 3)
    await member.fill('跨日会员23'); await page.getByRole('button', { name: '查询', exact: true }).click()
    await page.waitForFunction(() => window.__m012Requests.at(-1).payload.page === 1)
    assert.equal(await page.locator('.reservation-list-table tbody tr').count(), 1)
    await shortcut().click()
    assert.equal(await member.inputValue(), '')
    await page.screenshot({ path: path.join(output, 'all-pending-list.png') })
    for (const width of [1280, 768, 528]) {
      await page.setViewportSize({ width, height: 877 })
      // The existing desktop shell keeps a 1120px minimum width. Narrow-window
      // checks must actually reach controls via its existing horizontal scroll.
      const next = page.getByRole('button', { name: '下一页', exact: true })
      await next.scrollIntoViewIfNeeded()
      const control = await next.boundingBox()
      assert(control && control.x >= 0 && control.x + control.width <= width + 1, `next page reachable at ${width}`)
      const footer = await page.getByLabel('列表分页').boundingBox()
      assert(footer && footer.y >= 0 && footer.y + footer.height <= 878, `pagination visible at ${width}`)
    }
    await page.screenshot({ path: path.join(output, 'all-pending-528.png') })
    assert.deepEqual(errors, [])
    fs.writeFileSync(path.join(output, 'browser-result.json'), JSON.stringify({ status: 'PASS', widths: [1280, 768, 528], actualPageAndToolbar: true, customerRequests: 0, errors }, null, 2) + '\n')
    console.log('M012_BROWSER=PASS: shortcut, reset, paging, filtering, two calendar modes, bottom-right indicator, three viewport widths')
  } finally { if (browser) await browser.close(); await server.close() }
})().catch(e => { console.error(e); process.exitCode = 1 })

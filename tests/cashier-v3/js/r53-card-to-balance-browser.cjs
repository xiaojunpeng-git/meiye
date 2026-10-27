// Compile the real selector SFC and execute the real workbench submit functions
// through the real bridge; only backend transport and unrelated workspace state are fixtures.
const fs = require('node:fs'), path = require('node:path'), http = require('node:http'), assert = require('node:assert/strict')
const { createHash } = require('node:crypto')
const { execFileSync } = require('node:child_process')
const root = path.resolve(__dirname, '../../..'), project = path.join(root, '前端代码/cashier-v3'), src = path.join(project, 'src')
const { parse, compileScript, compileStyle } = require(path.join(project, 'node_modules/@vue/compiler-sfc'))
const babel = require(path.join(project, 'node_modules/@babel/parser'))
const esbuild = require(path.join(project, 'node_modules/esbuild'))
const { chromium } = require(path.join(process.env.CODEX_NODE_MODULES || '/Users/xiaojunpeng/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules', 'playwright'))
const read = file => fs.readFileSync(path.join(src, file), 'utf8')
const bundle = options => esbuild.buildSync({ bundle: true, write: false, platform: 'browser', format: 'esm', external: ['vue', '/bridge.mjs'], define: { 'import.meta.env.DEV': 'false' }, ...options }).outputFiles[0].text.replace(/from ["']vue["']/g, "from '/vue.mjs'")
// Expose the real private normalizer only inside this test bundle; do not bypass
// login/root allowlists by assigning an invented operation permission directly.
const bridge = bundle({ stdin: { contents: read('services/cashierV3Bridge.js') + '\nexport { normalizeBootstrap as r53NormalizeBootstrap }', resolveDir: path.join(src, 'services') } })
const selectorDescriptor = parse(read('components/cashier/EntitlementSelectorOverlay.vue')).descriptor
const selector = compileScript(selectorDescriptor, { id: 'r53', inlineTemplate: true }).content.replace(/from ['"]@\/services\/cashierV3Bridge['"]/, "from '/bridge.mjs'")
const selectorCss = selectorDescriptor.styles.map(style => {
  const compiled = compileStyle({ source: style.content, id: 'data-v-r53', scoped: style.scoped })
  assert.deepEqual(compiled.errors, [])
  return compiled.code
}).join('\n')
const component = bundle({ stdin: { contents: selector, resolveDir: src }, alias: { '@': src } })
const helper = bundle({ entryPoints: [path.join(src, 'services/cardToBalance.js')] })
const script = parse(read('views/CashierWorkbenchView.vue')).descriptor.scriptSetup.content
// Compile all edited SFCs without building or overwriting shared dist artifacts.
for (const file of ['views/CashierWorkbenchView.vue', 'layouts/CashierShell.vue']) {
  compileScript(parse(read(file)).descriptor, { id: 'r53-compile', inlineTemplate: true })
}
const names = new Set(['cardOperationTypeByMode', 'cardOperationUpgradeTypes', 'directCardOperationTypes', 'cardOperationProjectTypes', 'cardOperationTargetCatalogTypes', 'cardOperationReasonModes', 'sourceCardOperationContext', 'refreshCardToBalanceMemberSummary', 'submitDirectCardOperation', 'handleOperationConfirm'])
const workbench = babel.parse(script, { sourceType: 'module' }).program.body.filter(node => names.has(node.id?.name || node.declarations?.[0]?.id?.name)).map(node => script.slice(node.start, node.end)).join('\n')
assert.equal(names.size, 10)
assert.match(read('layouts/CashierShell.vue'), /key: 'card-to-balance', label: '卡转余额', featureCode: 'cashier.v3.cashier.card.to_balance'/)
const source = (id, options = {}) => ({ id: String(id), cardHolderId: String(id), name: '卡' + id, remainingTimes: 0, projects: [], remainingAmount: 999999, balanceTransfer: { eligible: true, amountCents: 123400, unavailableReason: '' }, ...options })
const sources = [source(1), source(2, { disabled: true, statusCode: 'disabled' }), source(3, { disabled: true, statusCode: 'expired', expiryDate: '2020-01-01' }), source(4, { outstandingDebtAmount: 50, balanceTransfer: { eligible: false, amountCents: 10000, unavailableReason: '本卡关联欠款须先补交或取消。' } }), source(5, { statusCode: 'voided', balanceTransfer: { eligible: false, amountCents: 0, unavailableReason: '卡已作废。' } }), source(6, { remainingTimes: 10, balanceTransfer: { eligible: false, amountCents: 0, unavailableReason: '没有实际剩余金额。' } }), source(7, { balanceTransfer: { eligible: true, amountCents: 101, unavailableReason: '' } })]
const logic = `import {ref,nextTick} from '/vue.mjs';
import {cardToBalancePayload} from '/helper.mjs';
import {requestCashierV3Action as requestAction,createCashierV3CommandId,cashierV3State as state} from '/bridge.mjs';
const previewCardOperation=ref({mode:'card-to-balance',label:'卡转余额'}),isSubmittingCardOperation=ref(false),cardOperationNoticeType=ref(''),cardOperationNotice=ref(''),currentMemberId=ref('53'),member=ref({id:'53'}),entitlementSelector=ref({commandContexts:[{kind:'card_holder',id:'1',expectedVersion:999}]});
let cardOperationNoticeTimer=null;
const clonePlain=x=>JSON.parse(JSON.stringify(x)),entitlementCardHolderId=x=>x.cardHolderId||x.id,moneyToCents=x=>Math.round(x*100),resultStatus=x=>x?.result?.status,resultMessage=(x,d)=>x?.result?.message||d;
const responseDataBlock=x=>x.data||{},isRecord=x=>!!x&&typeof x==='object'&&!Array.isArray(x);
const reportEntitlementContractError=x=>window.errors.push(x),finalizeEntitlementSelector=()=>{window.finalized=true};
${workbench}
export async function confirm(event){window.lastResult=await handleOperationConfirm(event);}
window.resetOperation=()=>{previewCardOperation.value={mode:'card-to-balance',label:'卡转余额'};};
window.submitDirect=submitDirectCardOperation;
window.submitting=isSubmittingCardOperation;`
const shellScript = parse(read('layouts/CashierShell.vue')).descriptor.scriptSetup.content
const menuNames = new Set(['cardOperationItems', 'canUseOperation', 'visibleCardOperationItems'])
const menuLogic = babel.parse(shellScript, { sourceType: 'module' }).program.body.filter(node => menuNames.has(node.id?.name || node.declarations?.[0]?.id?.name)).map(node => shellScript.slice(node.start, node.end)).join('\n')
const app = `import {createApp,ref,computed} from '/vue.mjs';import Component from '/component.mjs';import {confirm} from '/logic.mjs';import {cashierV3State,requestCashierV3Action,cashierV3ActionPermission,applyCashierV3LoginFeatures,r53NormalizeBootstrap,canUseCashierV3Operation} from '/bridge.mjs';
window.errors=[];window.sent=[];window.failNext=false;window.finalized=false;window.pending=null;window.summaryQueries=0;
const state=cashierV3State;
${menuLogic}
window.checkEntry=(grant,readOnly=false)=>{const code='cashier.v3.cashier.card.to_balance',common=['cashier.v3.cashier','cashier.v3.cashier.checkout'];applyCashierV3LoginFeatures([...common,...(grant?[code]:[])],{readOnly});const login=visibleCardOperationItems.value.some(x=>x.key==='card-to-balance');const normalized=r53NormalizeBootstrap({featurePermissions:{'cashier.v3.cashier':true,'cashier.v3.cashier.checkout':true,[code]:grant},operationPermissions:{[code]:grant&&!readOnly}});cashierV3State.operationPermissions=normalized.operationPermissions;cashierV3State.featurePermissions=normalized.featurePermissions;return{login,root:visibleCardOperationItems.value.some(x=>x.key==='card-to-balance')};};
window.checkEntry(true);
cashierV3State.featurePermissions={'cashier.v3.cashier':true,'cashier.v3.cashier.checkout':true};
window.permission=(enabled)=>{cashierV3State.operationPermissions['cashier.v3.cashier.card.to_balance']=enabled;return cashierV3ActionPermission('submit-card-operation',{operationType:'card_to_balance'});};
window.__CASHIER_V3_ADAPTER__={request:async(action,payload)=>{if(action==='query-cashier-member-summary'){window.summaryQueries++;return {action,boundAction:action,boundCorrelationId:payload.correlationId,result:{status:'success'},data:{memberSummary:{id:'53',balance:1234}}};}window.sent.push({action,payload});await new Promise(r=>setTimeout(r,100));return {action,boundAction:action,boundIdempotencyKey:payload.command.idempotencyKey,boundCorrelationId:payload.correlationId,result:window.failNext?{status:'failed',code:'CARD_TO_BALANCE_AMOUNT_CHANGED',message:'金额已变化，请重新选择。'}:{status:'success'}};}};
window.currentBalance=()=>cashierV3State.cashier.member.balance;
const mode=ref('card-to-balance');window.setMode=x=>mode.value=x;
Component.__scopeId='data-v-r53';
createApp({components:{Component},setup(){return{mode,selector:{ready:true,member:{id:'53'},selectorToken:'local-only',sources:${JSON.stringify(sources)}},confirm:e=>{window.pending=confirm(e)},error:e=>window.errors.push(e)}},template:'<Component :selector="selector" workspace-id="53" current-member-id="53" :operation-mode="mode" operation-label="卡转余额" @operation-confirm="confirm" @contract-error="error" />'}).mount('#app');
window.ready=true;`
const html = '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/base.css"><link rel="stylesheet" href="/component.css"><div id="app"></div><script type="module" src="/app.mjs"></script>'
const modules = { '/bridge.mjs': bridge, '/component.mjs': component, '/helper.mjs': helper, '/logic.mjs': logic, '/app.mjs': app }
const server = http.createServer((req, res) => {
  const url = new URL(req.url, 'http://localhost').pathname
  res.setHeader('Content-Type', url.endsWith('.mjs') ? 'text/javascript' : url.endsWith('.css') ? 'text/css' : 'text/html;charset=utf-8')
  if (url === '/vue.mjs') return res.end(fs.readFileSync(path.join(project, 'node_modules/vue/dist/vue.esm-browser.js')))
  if (url === '/base.css') return res.end(read('styles/base.css'))
  if (url === '/component.css') return res.end(selectorCss)
  res.end(modules[url] || html)
})
;(async () => {
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve))
  const browser = await chromium.launch({ headless: true, executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' })
  const page = await browser.newPage({ viewport: { width: 1480, height: 1009 } })
  const errors = []; page.on('pageerror', error => errors.push(error.message))
  try {
    await page.goto('http://127.0.0.1:' + server.address().port)
    await page.waitForFunction(() => window.ready)
    assert.deepEqual(await page.evaluate(() => window.checkEntry(true)), { login: true, root: true }, '真实登录/根投影规范化后菜单仍保留已授权入口')
    assert.deepEqual(await page.evaluate(() => window.checkEntry(false)), { login: false, root: false }, '无授权不能显示入口')
    assert.deepEqual(await page.evaluate(() => window.checkEntry(true, true)), { login: false, root: false }, '只读会话不能显示操作入口')
    await page.evaluate(() => window.checkEntry(true))
    const rows = page.locator('.cashier-entitlement-table__row--source')
    assert.equal(await rows.count(), 7, '默认全部卡含无余次/停用/到期')
    for (let i = 0; i < 3; i++) assert.equal(await rows.nth(i).getByRole('button').isEnabled(), true)
    for (let i = 3; i < 7; i++) assert.equal(await rows.nth(i).getByRole('button').isDisabled(), true)
    assert.match(await rows.nth(3).innerText(), /欠款须先补交或取消/)
    assert.match(await rows.nth(0).innerText(), /1,234/)
    assert.equal(await page.evaluate(() => window.permission(false).allowed), false, '停用权限不授予新权限')
    assert.equal(await page.evaluate(() => window.permission(true).allowed), true)
    const evidence = path.join(root, 'tests/cashier-v3/evidence/R53-card-to-balance-frontend')
    fs.mkdirSync(evidence, { recursive: true })
    await page.screenshot({ path: path.join(evidence, 'selection.png'), fullPage: true })
    await rows.nth(1).getByRole('button').click()
    const dialog = page.getByRole('dialog', { name: '卡转余额', exact: true })
    assert.equal(await dialog.locator('output').count(), 2)
    assert.deepEqual(await dialog.locator('output').allTextContents(), ['¥1,234', '¥1,234'])
    assert.equal(await dialog.locator('input').count(), 0, '金额无可编辑输入')
    assert.equal(await dialog.getByRole('button', { name: '确认', exact: true }).isDisabled(), true)
    await dialog.locator('textarea').fill('  会员申请转赠金  ')
    await page.screenshot({ path: path.join(evidence, 'confirmation.png'), fullPage: true })
    await dialog.getByRole('button', { name: '确认', exact: true }).click()
    await page.evaluate(() => window.pending)
    const first = await page.evaluate(() => window.sent[0])
    assert.equal(first.action, 'submit-card-operation')
    for (const [key, value] of Object.entries({ operationType: 'card_to_balance', memberId: '53', sourceCardHolderId: '2', confirmedAmountCents: 123400, reason: '会员申请转赠金' })) assert.equal(first.payload[key], value)
    assert.deepEqual(first.payload.command.contexts, [])
    assert.equal(/expectedVersion|targetSnapshot|selectorToken|catalogVersion|revision/.test(JSON.stringify(first)), false)
    assert.equal(await page.evaluate(() => window.finalized), true)
    assert.equal(await page.evaluate(() => window.currentBalance()), 1234, '成功后读取后端会员摘要')
    // Failed authority comparison must retain member context and expose the backend reason.
    await page.evaluate(() => { window.resetOperation(); window.failNext = true; window.finalized = false })
    await rows.nth(2).getByRole('button').click()
    await dialog.locator('textarea').fill('金额变化测试')
    await dialog.getByRole('button', { name: '确认', exact: true }).click()
    await page.evaluate(() => window.pending)
    assert.equal(await page.evaluate(() => window.lastResult.result.message), '金额已变化，请重新选择。')
    assert.equal(await page.evaluate(() => window.finalized), false)
    // Real submit function rejects tampered amounts and blocks duplicate in-flight submits.
    const safeguards = await page.evaluate(async source => {
      window.resetOperation(); window.failNext = false
      const before = window.sent.length
      await window.submitDirect({ source: { ...source, balanceTransfer: { eligible: true, amountCents: 101 } }, reason: '非法小数元' })
      const afterInvalid = window.sent.length
      const first = window.submitDirect({ source, reason: '重复保护' })
      const second = window.submitDirect({ source, reason: '重复保护' })
      await Promise.all([first, second])
      return { before, afterInvalid, after: window.sent.length }
    }, sources[0])
    assert.equal(safeguards.afterInvalid, safeguards.before)
    assert.equal(safeguards.after, safeguards.before + 1)
    await page.setViewportSize({ width: 960, height: 800 })
    await page.evaluate(() => window.resetOperation())
    await rows.nth(0).getByRole('button').click()
    assert.equal(await dialog.isVisible(), true)
    await page.screenshot({ path: path.join(evidence, 'confirmation-960.png'), fullPage: true })
    await dialog.getByRole('button', { name: '取消', exact: true }).click()
    // Check actual bounds: isVisible alone can pass for a dialog outside the screen.
    await page.setViewportSize({ width: 375, height: 667 })
    await rows.nth(0).getByRole('button').click()
    const mobileGeometry = await dialog.evaluate(element => {
      const targets = element.querySelectorAll('.cashier-card-operation-editor__panel, output, textarea, footer button')
      return Array.from(targets, target => {
        const rect = target.getBoundingClientRect()
        return { tag: target.tagName, left: rect.left, right: rect.right, top: rect.top, bottom: rect.bottom, inViewport: rect.left >= 0 && rect.right <= innerWidth && rect.top >= 0 && rect.bottom <= innerHeight }
      })
    })
    await page.screenshot({ path: path.join(evidence, 'confirmation-375.png'), fullPage: false })
    fs.writeFileSync(path.join(evidence, 'geometry-375.json'), JSON.stringify(mobileGeometry, null, 2))
    assert.ok(mobileGeometry.every(target => target.inViewport), JSON.stringify(mobileGeometry))
    await dialog.locator('textarea').fill('375小屏取消确认检查')
    assert.equal(await dialog.getByRole('button', { name: '确认', exact: true }).isEnabled(), true)
    await dialog.getByRole('button', { name: '取消', exact: true }).click()
    await page.setViewportSize({ width: 960, height: 800 })
    // Regression: existing disable/enable/extension keep their original selector and editor.
    await page.evaluate(() => window.setMode('card-disable'))
    await page.getByRole('button', { name: '全部', exact: true }).click()
    assert.equal(await rows.nth(0).getByRole('button').isEnabled(), true)
    assert.equal(await rows.nth(1).getByRole('button').isDisabled(), true)
    await rows.nth(0).getByRole('button').click()
    assert.equal(await dialog.locator('output').count(), 0)
    assert.equal(await dialog.evaluate(element => element.classList.contains('cashier-card-operation-editor--balance-transfer')), false)
    await page.setViewportSize({ width: 375, height: 667 })
    assert.equal(await dialog.evaluate(element => getComputedStyle(element).position), 'absolute', '停用弹窗不继承本轮 fixed 样式')
    await page.setViewportSize({ width: 960, height: 800 })
    await dialog.getByRole('button', { name: '取消', exact: true }).click()
    await page.evaluate(() => window.setMode('card-enable'))
    await page.waitForFunction(() => document.querySelectorAll('.cashier-entitlement-table__row--source').length === 1)
    await rows.nth(0).getByRole('button', { name: '启用', exact: true }).click()
    assert.equal(await dialog.locator('output').count(), 0)
    await dialog.getByRole('button', { name: '取消', exact: true }).click()
    await page.evaluate(() => window.setMode('card-extension'))
    await page.getByRole('button', { name: '全部', exact: true }).click()
    await rows.nth(0).getByRole('button', { name: '延期', exact: true }).click()
    assert.equal(await dialog.locator('input[type=date]').count(), 1)
    await dialog.locator('textarea').fill('延期原因')
    assert.equal(await dialog.getByRole('button', { name: '确认', exact: true }).isDisabled(), true)
    await dialog.locator('input[type=date]').fill('2027-10-24')
    assert.equal(await dialog.getByRole('button', { name: '确认', exact: true }).isEnabled(), true)
    assert.deepEqual(errors, [])
    const files = ['前端代码/cashier-v3/src/components/cashier/EntitlementSelectorOverlay.vue', '前端代码/cashier-v3/src/layouts/CashierShell.vue', '前端代码/cashier-v3/src/views/CashierWorkbenchView.vue', '前端代码/cashier-v3/src/services/cashierV3Bridge.js', '前端代码/cashier-v3/src/services/cardToBalance.js', 'tests/cashier-v3/js/r53-card-to-balance-browser.cjs', 'tests/cashier-v3/js/r15-card-operation-frontend-contract.mjs']
    const sha = value => createHash('sha256').update(value).digest('hex')
    const fileHashes = Object.fromEntries(files.map(file => [file, sha(fs.readFileSync(path.join(root, file)))]))
    // Tracked-file diff includes pre-existing work from other tasks; this is a
    // fingerprint of the tested shared workspace, not an R53-only commit boundary.
    const workspacePatchSha256 = sha(execFileSync('git', ['diff', 'HEAD', '--', ...files], { cwd: root }))
    const result = { status: 'PASS', baseline: 'df65e60a4d916f0f021ad00576314061eaeab1bf', testedHead: execFileSync('git', ['rev-parse', 'HEAD'], { cwd: root, encoding: 'utf8' }).trim(), fileHashes, workspacePatchSha256, actualVueSfc: true, actualWorkbenchSubmit: true, actualBridge: true, existingSelectorRegression: ['card-disable', 'card-enable', 'card-extension'], isolatedBackend: true, remote: '不适用', safeguards, submitted: await page.evaluate(() => window.sent) }
    result.mobileViewport = { width: 375, height: 667 }
    result.mobileGeometry = mobileGeometry
    fs.writeFileSync(path.join(evidence, 'result.json'), JSON.stringify(result, null, 2))
    console.log('R53_CARD_TO_BALANCE_BROWSER_PASS', JSON.stringify(safeguards))
  } finally { await browser.close(); await new Promise(resolve => server.close(resolve)) }
})().catch(error => { console.error(error); process.exitCode = 1 })

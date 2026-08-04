#!/usr/bin/env node
/**
 * C1-A 第四轮：esbuild 打包真实 production cashierV3Bridge.js 并执行契约。
 */
import os from 'os'
import fs from 'fs'
import path from 'path'
import { fileURLToPath, pathToFileURL } from 'url'
import esbuild from 'esbuild'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const TESTS = path.resolve(__dirname, '..')
const REPO = path.resolve(TESTS, '../..')
const CASHIER_V3 = path.join(REPO, '前端代码/cashier-v3')
const BRIDGE_SRC = path.join(CASHIER_V3, 'src/services/cashierV3Bridge.js')
const LIFECYCLE = path.join(CASHIER_V3, 'src/services/cashierV3SessionLifecycle.js')
const OUT_DIR = process.env.C1A_TMP_DIR || fs.mkdtempSync(path.join(os.tmpdir(), 'c1a-bridge-'))
const BUNDLE = path.join(OUT_DIR, 'bridge.bundle.mjs')
const LIFECYCLE_BUNDLE = path.join(OUT_DIR, 'lifecycle.bundle.mjs')

let passed = 0
let failed = 0
function ok(name, cond, detail = '', gateId = '') {
  if (cond) {
    passed++
    console.log(`  PASS  ${name}${gateId ? ` [${gateId}]` : ''}`)
    if (gateId) console.log(`GATE_PASS=${gateId}`)
  } else {
    failed++
    console.log(`  FAIL  ${name}${detail ? ' -> ' + detail : ''}${gateId ? ` [${gateId}]` : ''}`)
  }
}

console.log('== bridge esbuild bundle ==')
await esbuild.build({
  entryPoints: [BRIDGE_SRC],
  bundle: true,
  format: 'esm',
  platform: 'browser',
  outfile: BUNDLE,
  logLevel: 'silent',
  define: {
    'import.meta.env.DEV': 'false',
    'import.meta.env.PROD': 'true',
    'import.meta.env.MODE': '"test"'
  },
  plugins: [{
    name: 'vue-shim',
    setup(build) {
      build.onResolve({ filter: /^vue$/ }, () => ({ path: 'vue-shim', namespace: 'vue-shim-ns' }))
      build.onLoad({ filter: /.*/, namespace: 'vue-shim-ns' }, () => ({
        contents: 'export function reactive(o){return o} export default { reactive }',
        loader: 'js'
      }))
    }
  }]
})
ok('esbuild 产出 bundle', fs.existsSync(BUNDLE), BUNDLE, 'FE-11-01')
ok('session lifecycle 生产入口存在', fs.existsSync(LIFECYCLE), LIFECYCLE, 'CS-9-01')

await esbuild.build({
  entryPoints: [LIFECYCLE],
  bundle: true,
  format: 'esm',
  platform: 'browser',
  outfile: LIFECYCLE_BUNDLE,
  logLevel: 'silent',
  define: {
    'import.meta.env.DEV': 'false',
    'import.meta.env.PROD': 'true',
    'import.meta.env.MODE': '"test"'
  },
  plugins: [{
    name: 'vue-shim',
    setup(build) {
      build.onResolve({ filter: /^vue$/ }, () => ({ path: 'vue-shim', namespace: 'vue-shim-ns' }))
      build.onLoad({ filter: /.*/, namespace: 'vue-shim-ns' }, () => ({
        contents: 'export function reactive(o){return o} export default { reactive }',
        loader: 'js'
      }))
    }
  }]
})
ok('esbuild 产出 lifecycle bundle', fs.existsSync(LIFECYCLE_BUNDLE), LIFECYCLE_BUNDLE, 'CS-9-01')

globalThis.window = globalThis
globalThis.window.location = { search: '', href: 'http://127.0.0.1/', pathname: '/' }
globalThis.document = { addEventListener() {}, removeEventListener() {} }
const memorySession = new Map()
globalThis.window.sessionStorage = {
  getItem(k) { return memorySession.has(k) ? memorySession.get(k) : null },
  setItem(k, v) { memorySession.set(k, String(v)) },
  removeItem(k) { memorySession.delete(k) }
}
const listeners = {}
globalThis.window.addEventListener = (t, fn) => { (listeners[t] ||= []).push(fn) }
globalThis.window.removeEventListener = (t, fn) => {
  listeners[t] = (listeners[t] || []).filter((x) => x !== fn)
}
globalThis.window.dispatchEvent = (ev) => {
  for (const fn of listeners[ev.type] || []) fn(ev)
  return true
}
globalThis.CustomEvent = class CustomEvent {
  constructor(type, init) { this.type = type; this.detail = init?.detail }
}
globalThis.crypto = {
  getRandomValues(arr) {
    for (let i = 0; i < arr.length; i++) arr[i] = Math.floor(Math.random() * 256)
    return arr
  },
  randomUUID() {
    return '11111111-2222-4333-8444-555555555555'
  }
}

const bridge = await import(pathToFileURL(BUNDLE).href)
if (typeof bridge.resetCashierV3BridgeForTests === 'function') {
  bridge.resetCashierV3BridgeForTests()
}

ok('导出 requestCashierV3ContextSwitch', typeof bridge.requestCashierV3ContextSwitch === 'function', '', 'CS-9-01')
ok('导出 requestCashierV3Action', typeof bridge.requestCashierV3Action === 'function', '', 'FE-11-01')
ok(
  'clientSessionId is restored from tab-scoped sessionStorage after a browser reload',
  /const CASHIER_V3_CLIENT_SESSION_STORAGE_KEY = 'cashier-v3\.client-session-id'/.test(fs.readFileSync(BRIDGE_SRC, 'utf8'))
    && /function getClientSessionId\(\)\s*\{[\s\S]*?window\.sessionStorage\?\.getItem\(CASHIER_V3_CLIENT_SESSION_STORAGE_KEY\)[\s\S]*?window\.sessionStorage\?\.setItem\(CASHIER_V3_CLIENT_SESSION_STORAGE_KEY, clientSessionId\)/.test(fs.readFileSync(BRIDGE_SRC, 'utf8')),
  '',
  'CS-9-01'
)

function minimalRoot(ctx, rev, extras = {}) {
  return {
    stateContextId: ctx,
    stateRevision: String(rev),
    storeName: '测店',
    currentStore: { id: 1, name: '测店' },
    featurePermissions: { 'cashier.v3.cashier': true },
    workspace: { id: `ws:1:1:${ctx}`, revision: 1, status: 'editing', serverTime: '2026-07-27T00:00:00+08:00' },
    operator: { name: '测', roleName: '' },
    pendingHangCount: 0,
    cashier: {},
    serviceCompletion: { serviceOrder: null, lines: [], commandContexts: [] },
    writeoff: {},
    room: {},
    reservation: {},
    memberCenter: { canBatchOperate: false, records: [], detail: null },
    memberSelector: { records: [], total: 0, page: 1, pageSize: 20, isLoading: false },
    queryEntitySelector: {
      person: { records: [], total: 0, page: 1, pageSize: 20, isLoading: false },
      store: { records: [], total: 0, page: 1, pageSize: 20, isLoading: false },
      organization: { records: [], total: 0, page: 1, pageSize: 20, isLoading: false }
    },
    managementCenter: { entries: [] },
    businessDashboard: {},
    hangOrders: { statusOptions: [], records: [] },
    orderCenter: { businessTypes: [], salesOrders: [], salesOrderDetail: null },
    ...extras
  }
}

console.log('== versions 单调 / 上下文绑定 ==')
if (typeof bridge.resetCashierV3BridgeForTests === 'function') bridge.resetCashierV3BridgeForTests()
bridge.replaceCashierV3State(minimalRoot('ctx-A', 1))
bridge.clearCashierV3PublicVersions()
const m1 = bridge.mergeCashierV3PublicVersions(
  [{ kind: 'room', id: 'R1', version: 2 }],
  'ctx-A',
  { requestStateContextId: 'ctx-A' }
)
ok('merge v2', m1.merged >= 1, JSON.stringify(m1), 'CS-9-02')
bridge.mergeCashierV3PublicVersions([{ kind: 'room', id: 'R1', version: 3 }], 'ctx-A', { requestStateContextId: 'ctx-A' })
ok('get version 3', bridge.getCashierV3PublicVersion('room', 'R1') === 3, String(bridge.getCashierV3PublicVersion('room', 'R1')), 'CS-9-02')
const refuse = bridge.mergeCashierV3PublicVersions([{ kind: 'room', id: 'R1', version: 1 }], 'ctx-A', { requestStateContextId: 'ctx-A' })
ok('拒绝降级', refuse.refused >= 1 && bridge.getCashierV3PublicVersion('room', 'R1') === 3, JSON.stringify(refuse), 'CS-9-02')
bridge.replaceCashierV3StateForContextSwitch(minimalRoot('ctx-B', 1))
ok('换店清空旧仓', bridge.getCashierV3PublicVersion('room', 'R1') === null, String(bridge.getCashierV3PublicVersion('room', 'R1')), 'CS-9-03')
bridge.mergeCashierV3PublicVersions([{ kind: 'room', id: 'R1', version: 1 }], 'ctx-B', { requestStateContextId: 'ctx-B' })
ok('新店可登记独立版本', bridge.getCashierV3PublicVersion('room', 'R1') === 1, '', 'CS-9-03')

console.log('== suppliedVersion 不得信任 ==')
if (typeof bridge.resetCashierV3BridgeForTests === 'function') bridge.resetCashierV3BridgeForTests()
bridge.replaceCashierV3State(minimalRoot('ctx-S', 1))
bridge.clearCashierV3PublicVersions()
window.__CASHIER_V3_ADAPTER__ = {
  async request() {
    return { result: { status: 'success', code: '', message: 'ok' }, data: {} }
  }
}
const noStore = await bridge.requestCashierV3Action('submit-checkout', {
  silent: true,
  commandContexts: [{ kind: 'cashier_workspace', id: 'ws:1:1:ctx-S', expectedVersion: 99 }]
})
ok('仓内无版本时拒绝发送', (noStore?.result?.code || '') === 'INVALID_COMMAND_CONTEXT', JSON.stringify(noStore?.result), 'CS-9-02')

console.log('== 定制卡工作台上下文 ==')
if (typeof bridge.resetCashierV3BridgeForTests === 'function') bridge.resetCashierV3BridgeForTests()
bridge.replaceCashierV3StateForContextSwitch(minimalRoot('ctx-custom-card', 1))
bridge.mergeCashierV3PublicVersions(
  [{ kind: 'cashier_workspace', id: 'ws:1:1:ctx-custom-card', version: 1 }],
  'ctx-custom-card',
  { requestStateContextId: 'ctx-custom-card' }
)
let customCardRequest = null
window.__CASHIER_V3_ADAPTER__ = {
  async request(action, payload) {
    customCardRequest = { action, payload }
    return { result: { status: 'success', code: '', message: 'ok' }, data: {} }
  }
}
const customCardWrite = await bridge.requestCashierV3Action('create-custom-card-configuration', {
  silent: true,
  cardName: '配置卡合同测试',
  validityEnd: '2027-12-31',
  activateOnPurchase: true,
  components: [{ skuId: 1, times: 1, amount: '1' }]
})
ok(
  '定制卡配置携带工作台版本',
  customCardWrite?.result?.status !== 'failed'
    && customCardRequest?.action === 'create-custom-card-configuration'
    && customCardRequest?.payload?.command?.contexts?.length === 1
    && customCardRequest.payload.command.contexts[0]?.kind === 'cashier_workspace'
    && customCardRequest.payload.command.contexts[0]?.id === 'ws:1:1:ctx-custom-card'
    && customCardRequest.payload.command.contexts[0]?.expectedVersion === 1,
  JSON.stringify(customCardRequest?.payload?.command?.contexts),
  'C2-CC-01'
)

console.log('== 根状态 schema 严格嵌套类型 ==')
if (typeof bridge.resetCashierV3BridgeForTests === 'function') bridge.resetCashierV3BridgeForTests()
bridge.replaceCashierV3StateForContextSwitch(minimalRoot('ctx-schema', 1))
let schemaCase = 0
function schemaNegative(label, mutate) {
  schemaCase += 1
  const context = `ctx-schema-${schemaCase}`
  bridge.replaceCashierV3StateForContextSwitch(minimalRoot(context, 1))
  const candidate = minimalRoot(context, 2)
  mutate(candidate)
  const outcome = bridge.replaceCashierV3State(candidate)
  ok(label,
    outcome?.accepted === false
      && outcome?.code === 'STATE_SCHEMA_INVALID'
      && bridge.cashierV3State?.stateContextId === context
      && bridge.cashierV3State?.stateRevision === '1',
    JSON.stringify({ outcome, currentRevision: bridge.cashierV3State?.stateRevision }),
    'BR-10-01')
}
schemaNegative('根分区数组不得伪装对象', (root) => { root.featurePermissions = [] })
schemaNegative('会员选择器 records 必须是数组', (root) => { root.memberSelector.records = {} })
schemaNegative('统一选择器分页字段必须为正整数', (root) => { root.queryEntitySelector.person.page = 0 })
schemaNegative('workspace revision 不接受字符串', (root) => { root.workspace.revision = '2' })
schemaNegative('pendingHangCount 不接受数字字符串', (root) => { root.pendingHangCount = '1' })
schemaNegative('cashier cart lines 必须是数组', (root) => { root.cashier = { cart: { lines: {} } } })
schemaNegative('serviceCompletion lines 必须是数组', (root) => { root.serviceCompletion.lines = {} })
schemaNegative('room pendingAssignments 必须是数组', (root) => { root.room = { pendingAssignments: {} } })
schemaNegative('reservation records 必须是数组', (root) => { root.reservation = { records: {} } })
schemaNegative('memberCenter records 必须是数组', (root) => { root.memberCenter.records = {} })
schemaNegative('orderCenter salesOrders 必须是数组', (root) => { root.orderCenter = { salesOrders: {} } })
schemaNegative('featurePermissions 值必须是布尔值', (root) => { root.featurePermissions['cashier.v3.cashier'] = 'true' })
schemaNegative('workspace serverTime 必须是字符串或 null', (root) => { root.workspace.serverTime = 1700000000 })
schemaNegative('room pendingAssignmentCount 必须是非负整数', (root) => { root.room.pendingAssignmentCount = '1' })
schemaNegative('游客／会员模式不接受未知值', (root) => {
  root.cashier = { customerMode: 'unknown', member: null }
})

const explicitGuestRoot = minimalRoot('ctx-explicit-guest', 1, {
  cashier: { customerMode: 'guest', member: null }
})
const explicitGuestOutcome = bridge.replaceCashierV3StateForContextSwitch(explicitGuestRoot)
ok('只有显式 guest 根状态才开启游客模式',
  explicitGuestOutcome?.accepted === true
    && bridge.cashierV3State?.cashier?.customerMode === 'guest'
    && bridge.cashierV3State?.cashier?.member === null,
  JSON.stringify({ outcome: explicitGuestOutcome, cashier: bridge.cashierV3State?.cashier }),
  'BR-10-01')

console.log('== context switch 乱序 Promise ==')
if (typeof bridge.resetCashierV3BridgeForTests === 'function') bridge.resetCashierV3BridgeForTests()
bridge.replaceCashierV3State(minimalRoot('ctx-old', 1))
bridge.mergeCashierV3PublicVersions(
  [{ kind: 'cashier_workspace', id: 'ws:1:1:ctx-old', version: 1 }],
  'ctx-old',
  { requestStateContextId: 'ctx-old' }
)

let resolveOld
let resolveNew
const oldPromise = new Promise((r) => { resolveOld = r })
const newPromise = new Promise((r) => { resolveNew = r })
let bootstrapCalls = 0
window.__CASHIER_V3_ADAPTER__ = {
  async bootstrap(payload) {
    bootstrapCalls += 1
    const n = bootstrapCalls
    const corr = String(payload.correlationId || '')
    const body = {
      result: { status: 'success', code: '', message: 'ok' },
      data: { ok: true },
      contextChanged: true,
      contextSwitchEpoch: payload.contextSwitchEpoch,
      // 生产协议：服务端 token 与客户端 nonce 分离；客户端 nonce 只用于
      // 绑定本次意图，不能把自报 token 当作服务端已校验凭证。
      contextSwitchToken: n === 1 ? 'server-token-first' : 'server-token-second',
      contextSwitchClientToken: payload.contextSwitchToken,
      contextSwitchServerBound: true,
      stateContextId: n === 1 ? 'ctx-first' : 'ctx-second',
      stateRevision: '1',
      state: minimalRoot(n === 1 ? 'ctx-first' : 'ctx-second', 1),
      versions: [{ kind: 'cashier_workspace', id: `ws:1:1:${n === 1 ? 'ctx-first' : 'ctx-second'}`, version: 1 }],
      boundAction: 'open-cashier-workbench',
      boundCanonical: 'open-cashier-workbench',
      correlationId: corr,
      boundCorrelationId: corr
    }
    if (n === 1) return oldPromise.then(() => body)
    return newPromise.then(() => body)
  }
}

const p1 = bridge.requestCashierV3ContextSwitch({ silent: true })
const p2 = bridge.requestCashierV3ContextSwitch({ silent: true })
// 反序返回：先放行第二次（新），再放行第一次（旧）
resolveNew()
await new Promise((r) => setTimeout(r, 10))
resolveOld()
const [r1, r2] = await Promise.all([p1, p2])
const finalCtx = bridge.cashierV3State?.stateContextId || bridge.stateContextIdOf?.(bridge.cashierV3State)
// 旧响应不得覆盖新根：最终应为第二次 intent 对应的根，或至少不是被旧响应错误切换
ok('乱序后最终根为新 context', (bridge.cashierV3State?.stateContextId === 'ctx-second'), `final=${bridge.cashierV3State?.stateContextId} r1=${JSON.stringify(r1?.stateContextId)} r2=${JSON.stringify(r2?.stateContextId)}`, 'CS-9-01')
ok('乱序后版本仓只绑定新 context', bridge.getCashierV3PublicVersion('cashier_workspace', 'ws:1:1:ctx-second') === 1 && bridge.getCashierV3PublicVersion('cashier_workspace', 'ws:1:1:ctx-first') === null, '', 'CS-9-01')
ok('context switch 入口可调用', bootstrapCalls >= 2, String(bootstrapCalls), 'CS-9-01')

// requestMeta 兜底禁止：仅有 meta、响应无 epoch/token 时不得切换
if (typeof bridge.resetCashierV3BridgeForTests === 'function') bridge.resetCashierV3BridgeForTests()
bridge.replaceCashierV3State(minimalRoot('ctx-hold', 3))
const intent = bridge.beginCashierV3ContextSwitch('tok-hold')
const metaOnly = {
  result: { status: 'success', code: '', message: 'ok' },
  contextChanged: true,
  stateContextId: 'ctx-switched',
  stateRevision: '1',
  state: minimalRoot('ctx-switched', 1)
  // 故意不回传 contextSwitchEpoch/Token
}
window.__CASHIER_V3_ADAPTER__ = {
  async bootstrap() { return metaOnly }
}
await bridge.requestCashierV3ContextSwitch({ silent: true })
const still = bridge.cashierV3State?.stateContextId
const intentLeft = typeof bridge.currentCashierV3ContextSwitchIntent === 'function'
  ? bridge.currentCashierV3ContextSwitchIntent()
  : null
const holdOk = still !== 'ctx-switched' && (still === '' || still === 'ctx-hold' || !!intentLeft)
ok('无服务端 epoch/token 不得靠 requestMeta 切换', holdOk && still !== 'ctx-switched', `ctx=${still} intent=${JSON.stringify(intentLeft)}`, 'CS-9-01')
ok('无服务端 token 时不切换根', holdOk, `ctx=${still} intent=${JSON.stringify(intentLeft)}`, 'CS-9-01')

console.log('== 业务成功 + STATE_REVISION_MISMATCH 保留 ==')
if (typeof bridge.resetCashierV3BridgeForTests === 'function') bridge.resetCashierV3BridgeForTests()
// 上一节可能停在其它 stateContextId：必须用 context-switch 入口装载，再种子版本仓
if (typeof bridge.replaceCashierV3StateForContextSwitch === 'function') {
  const switched = bridge.replaceCashierV3StateForContextSwitch(minimalRoot('ctx-cur', 5))
  ok('BR-10 装载根', switched?.accepted !== false && switched?.applied !== false, JSON.stringify(switched), 'BR-10-01')
} else {
  bridge.replaceCashierV3State(minimalRoot('ctx-cur', 5), { allowContextSwitch: true })
}
const seed = bridge.mergeCashierV3PublicVersions(
  [{ kind: 'cashier_workspace', id: 'ws:1:1:ctx-cur', version: 1 }],
  'ctx-cur',
  { requestStateContextId: 'ctx-cur' }
)
ok('BR-10 种子版本仓', seed.merged >= 1 && bridge.getCashierV3PublicVersion('cashier_workspace', 'ws:1:1:ctx-cur') === 1, JSON.stringify(seed), 'BR-10-01')
window.__CASHIER_V3_ADAPTER__ = {
  async request(action, payload) {
    const key = payload?.command?.idempotencyKey || 'CMD-aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'
    const corr = payload?.correlationId || ''
    return {
      result: { status: 'success', code: '', message: '已收款' },
      businessNo: 'SO1',
      idempotencyKey: key,
      boundIdempotencyKey: key,
      boundAction: 'submit-checkout',
      boundCanonical: 'submit-checkout',
      correlationId: corr,
      boundCorrelationId: corr,
      data: { paid: true },
      navigation: { routeName: 'cashier-v3-cashier' },
      overlay: { name: 'member-selector' },
      stateContextId: 'ctx-cur',
      stateRevision: '9',
      state: minimalRoot('ctx-cur', 1) // 顶层 revision 与 state 内不一致 → STATE_REVISION_MISMATCH
    }
  }
}
const biz = await bridge.requestCashierV3Action('submit-checkout', {
  commandContexts: [{ kind: 'cashier_workspace', id: 'ws:1:1:ctx-cur', expectedVersion: 1 }]
})
const bizStatus = biz?.result?.status || biz?.status
ok('业务成功不被改写为 failed', bizStatus === 'success' || biz?.stateIgnored === true, JSON.stringify(biz?.result), 'BR-10-01')
ok('STATE_REVISION_MISMATCH 可 stateIgnored', biz?.stateIgnored === true || biz?.ignoredRootStateCode === 'STATE_REVISION_MISMATCH' || bizStatus === 'success', JSON.stringify(biz), 'BR-10-01')
ok('stateIgnored 响应剥离导航与浮层', biz?.stateIgnored === true && biz?.requiresRefresh === true && !biz?.navigation && !biz?.overlay, JSON.stringify(biz), 'BR-10-01')

console.log('== 写命令结果未知 ==')
window.__CASHIER_V3_ADAPTER__ = {
  async request() { throw new Error('network down') }
}
const unk = await bridge.requestCashierV3Action('submit-checkout', {})
ok('result_unknown', (unk?.result?.status || unk?.status) === 'result_unknown', JSON.stringify(unk?.result), 'FE-11-01')
ok('COMMAND_RESULT_UNKNOWN', (unk?.result?.code || unk?.code) === 'COMMAND_RESULT_UNKNOWN', '', 'FE-11-01')

console.log('== 损坏响应 ==')
window.__CASHIER_V3_ADAPTER__ = {
  async request() { return { foo: 'bar' } }
}
const bad = await bridge.requestCashierV3Action('submit-checkout', {})
ok('损坏写响应 result_unknown', (bad?.result?.status || bad?.status) === 'result_unknown', JSON.stringify(bad), 'FE-11-01')

console.log('== projection 确定失败 ==')
window.__CASHIER_V3_ADAPTER__ = {
  async request() { throw new Error('query failed') }
}
const qfail = await bridge.requestCashierV3Action('query-members', {})
ok('projection 可确定 failed', (qfail?.result?.status || qfail?.status) === 'failed', '', 'FE-11-01')

console.log('== 严格信封逐字段负例 ==')
if (typeof bridge.resetCashierV3BridgeForTests === 'function') bridge.resetCashierV3BridgeForTests()
if (typeof bridge.replaceCashierV3StateForContextSwitch === 'function') {
  bridge.replaceCashierV3StateForContextSwitch(minimalRoot('ctx-env', 1))
} else {
  bridge.replaceCashierV3State(minimalRoot('ctx-env', 1), { allowContextSwitch: true })
}
bridge.mergeCashierV3PublicVersions(
  [{ kind: 'cashier_workspace', id: 'ws:1:1:ctx-env', version: 1 }],
  'ctx-env',
  { requestStateContextId: 'ctx-env' }
)
const beforeEnvCtx = bridge.cashierV3State?.stateContextId
const beforeEnvVer = bridge.getCashierV3PublicVersion('cashier_workspace', 'ws:1:1:ctx-env')
async function envelopeNeg(mutate, label) {
  window.__CASHIER_V3_ADAPTER__ = {
    async request(action, payload) {
      const key = payload?.command?.idempotencyKey || ''
      const corr = payload?.correlationId || ''
      const body = {
        result: { status: 'success', code: '', message: 'ok' },
        boundAction: 'submit-checkout',
        boundCanonical: 'submit-checkout',
        boundIdempotencyKey: key,
        correlationId: corr,
        boundCorrelationId: corr,
        stateContextId: 'ctx-env',
        stateRevision: '1',
        state: minimalRoot('ctx-env', 1),
        versions: [{ kind: 'cashier_workspace', id: 'ws:1:1:ctx-env', version: 2 }],
        data: { poisoned: true }
      }
      mutate(body, payload)
      return body
    }
  }
  const res = await bridge.requestCashierV3Action('submit-checkout', {
    silent: true,
    commandContexts: [{ kind: 'cashier_workspace', id: 'ws:1:1:ctx-env', expectedVersion: 1 }]
  })
  const status = res?.result?.status || res?.status || ''
  const rejected = status === 'failed'
    || status === 'result_unknown'
    || res?.envelopeRejected === true
    || res?.ignoredRootState === true
  const rootIntact = bridge.cashierV3State?.stateContextId === beforeEnvCtx
  const verIntact = bridge.getCashierV3PublicVersion('cashier_workspace', 'ws:1:1:ctx-env') === beforeEnvVer
  ok(label, rejected && rootIntact && verIntact, JSON.stringify({
    status: res?.result?.status,
    code: res?.result?.code,
    ctx: bridge.cashierV3State?.stateContextId,
    ver: bridge.getCashierV3PublicVersion('cashier_workspace', 'ws:1:1:ctx-env')
  }), 'BR-10-02')
}
await envelopeNeg((b) => { delete b.boundAction; delete b.boundCanonical }, '缺 boundAction 整包拒绝')
await envelopeNeg((b) => { b.boundAction = 'other-action'; b.boundCanonical = 'other-action' }, '篡改 boundAction 拒绝')
await envelopeNeg((b) => { delete b.boundIdempotencyKey }, '缺 idempotency 绑定拒绝')
await envelopeNeg((b) => { b.boundIdempotencyKey = 'CMD-forged' }, '篡改 idempotency 拒绝')
await envelopeNeg((b) => { delete b.boundCorrelationId; delete b.correlationId }, '缺 correlation 拒绝')
await envelopeNeg((b) => { b.boundCorrelationId = 'CORR-forged'; b.correlationId = 'CORR-forged' }, '篡改 correlation 拒绝')
await envelopeNeg((b) => { delete b.stateContextId }, '缺 stateContextId 拒绝')
await envelopeNeg((b) => { b.stateContextId = 'ctx-other' }, '篡改 stateContextId 拒绝')

console.log('== 无 state 且无绑定字段的写响应 → result_unknown ==')
window.__CASHIER_V3_ADAPTER__ = {
  async request() {
    return { result: { status: 'success', code: '', message: 'ok' }, data: { leaked: true } }
  }
}
const unbound = await bridge.requestCashierV3Action('submit-checkout', {
  silent: true,
  commandContexts: [{ kind: 'cashier_workspace', id: 'ws:1:1:ctx-env', expectedVersion: 1 }]
})
ok('无绑定写响应 result_unknown', (unbound?.result?.status || unbound?.status) === 'result_unknown', JSON.stringify(unbound?.result), 'BR-10-02')
ok('无绑定不应用业务 data', bridge.cashierV3State?.stateContextId === beforeEnvCtx, `ctx=${bridge.cashierV3State?.stateContextId}`, 'BR-10-02')

console.log('== 切换开始清空完整旧根 ==')
if (typeof bridge.resetCashierV3BridgeForTests === 'function') bridge.resetCashierV3BridgeForTests()
if (typeof bridge.replaceCashierV3StateForContextSwitch === 'function') {
  bridge.replaceCashierV3StateForContextSwitch(minimalRoot('ctx-full', 2, {
    cashier: {
      member: { id: 'M1', name: '旧会员' },
      cart: { lines: [{ id: 'SALE-1', name: '旧销售行', lineRole: 'sale', quantity: 1 }] },
      checkout: { id: 'CK1' }
    },
    reservation: { records: [{ id: 'R1' }] }
  }))
}
const beforeClearMember = bridge.cashierV3State?.cashier?.member?.id
const intentClear = bridge.beginCashierV3ContextSwitch('tok-clear')
ok('切换开始清空会员', !bridge.cashierV3State?.cashier?.member?.id && beforeClearMember === 'M1', JSON.stringify(bridge.cashierV3State?.cashier), 'CS-9-03')
ok('切换开始清空 stateContext', bridge.cashierV3State?.stateContextId === '', String(bridge.cashierV3State?.stateContextId), 'CS-9-03')
ok('切换意图已登记', intentClear?.epoch >= 1 && intentClear?.token === 'tok-clear', JSON.stringify(intentClear), 'CS-9-01')

console.log('== epoch/token 失败整包拒绝，禁止回退 replaceCashierV3State ==')
if (typeof bridge.resetCashierV3BridgeForTests === 'function') bridge.resetCashierV3BridgeForTests()
bridge.replaceCashierV3StateForContextSwitch(minimalRoot('ctx-hold2', 4, {
  cashier: { member: { id: 'KEEP' } }
}))
const holdMember = bridge.cashierV3State?.cashier?.member?.id
window.__CASHIER_V3_ADAPTER__ = {
  async bootstrap(payload) {
    return {
      result: { status: 'success', code: '', message: 'ok' },
      contextChanged: true,
      contextSwitchServerBound: true,
      contextSwitchEpoch: 999,
      contextSwitchToken: 'wrong-token',
      stateContextId: 'ctx-poison',
      stateRevision: '1',
      state: minimalRoot('ctx-poison', 1, { cashier: { member: { id: 'POISON' } } }),
      boundAction: 'open-cashier-workbench',
      boundCanonical: 'open-cashier-workbench',
      correlationId: payload.correlationId,
      boundCorrelationId: payload.correlationId,
      overlay: { name: 'should-not-apply' },
      navigation: { to: '/poison' }
    }
  }
}
const badTok = await bridge.requestCashierV3ContextSwitch({ silent: true })
ok('错误 token 整包拒绝', (badTok?.result?.status === 'failed') || badTok?.ignoredRootState === true || (badTok?.result?.code || '').includes('CONTEXT_SWITCH'), JSON.stringify(badTok?.result), 'CS-9-01')
ok('错误 token 不应用中间状态', bridge.cashierV3State?.stateContextId !== 'ctx-poison' && bridge.cashierV3State?.cashier?.member?.id !== 'POISON', `ctx=${bridge.cashierV3State?.stateContextId} member=${bridge.cashierV3State?.cashier?.member?.id} hold=${holdMember}`, 'CS-9-01')

console.log('== context-switch 携带稳定 clientSessionId ==')
const sessions = []
window.__CASHIER_V3_ADAPTER__ = {
  async bootstrap(payload) {
    sessions.push(String(payload.clientSessionId || ''))
    const n = sessions.length
    return {
      result: { status: 'success', code: '', message: 'ok' },
      contextChanged: true,
      contextSwitchServerBound: true,
      contextSwitchEpoch: payload.contextSwitchEpoch,
      contextSwitchToken: `server-token-session-${n}`,
      contextSwitchClientToken: payload.contextSwitchToken,
      stateContextId: 'ctx-sess',
      stateRevision: '1',
      state: minimalRoot('ctx-sess', 1),
      versions: [{ kind: 'cashier_workspace', id: 'ws:1:1:ctx-sess', version: 1 }],
      boundAction: 'open-cashier-workbench',
      boundCanonical: 'open-cashier-workbench',
      correlationId: payload.correlationId,
      boundCorrelationId: payload.correlationId
    }
  }
}
await bridge.requestCashierV3ContextSwitch({ silent: true })
await bridge.requestCashierV3ContextSwitch({ silent: true })
ok('clientSessionId 稳定非空', sessions.length === 2 && sessions[0] && sessions[0] === sessions[1], JSON.stringify(sessions), 'CS-9-01')

console.log('== 真实 lifecycle 生产入口 ==')
const lifecycle = await import(pathToFileURL(LIFECYCLE_BUNDLE).href)
ok('lifecycle 导出 bootstrapCashierV3Workbench', typeof lifecycle.bootstrapCashierV3Workbench === 'function', '', 'CS-9-01')
ok('lifecycle 导出 onCashierStoreOrAccountChanged', typeof lifecycle.onCashierStoreOrAccountChanged === 'function', '', 'CS-9-01')

const lifecycleCalls = []
window.__CASHIER_V3_ADAPTER__ = {
  async bootstrap(payload) {
    lifecycleCalls.push({ ...payload })
    const n = lifecycleCalls.length
    const context = `ctx-lifecycle-${n}`
    return {
      result: { status: 'success', code: '', message: 'ok' },
      contextChanged: true,
      contextSwitchServerBound: true,
      contextSwitchEpoch: payload.contextSwitchEpoch,
      contextSwitchToken: `server-token-lifecycle-${n}`,
      contextSwitchClientToken: payload.contextSwitchToken,
      stateContextId: context,
      stateRevision: '1',
      state: minimalRoot(context, 1),
      versions: [{ kind: 'cashier_workspace', id: `ws:1:1:${context}`, version: 1 }],
      boundAction: 'open-cashier-workbench',
      boundCanonical: 'open-cashier-workbench',
      correlationId: payload.correlationId,
      boundCorrelationId: payload.correlationId
    }
  }
}
const lifecycleBoot = await lifecycle.bootstrapCashierV3Workbench({ reason: 'app-boot', silent: true })
const lifecycleStore = await lifecycle.onCashierStoreOrAccountChanged({ reason: 'store', storeId: 7, silent: true })
ok(
  'lifecycle bootstrap 执行真实 context switch',
  lifecycleCalls.length === 2
    && lifecycleCalls[0]?.action === 'open-cashier-workbench'
    && lifecycleCalls[0]?.reason === 'app-boot'
    && lifecycleBoot?.result?.status === 'success',
  JSON.stringify({ calls: lifecycleCalls, result: lifecycleBoot }),
  'CS-9-01'
)
ok(
  'lifecycle 调店入口保留后端绑定参数',
  lifecycleCalls[1]?.action === 'open-cashier-workbench'
    && lifecycleCalls[1]?.reason === 'store'
    && lifecycleCalls[1]?.storeId === 7
    && lifecycleStore?.result?.status === 'success',
  JSON.stringify({ call: lifecycleCalls[1], result: lifecycleStore }),
  'CS-9-01'
)
ok(
  'lifecycle 使用稳定非空 clientSessionId',
  Boolean(lifecycleCalls[0]?.clientSessionId)
    && lifecycleCalls[0]?.clientSessionId === lifecycleCalls[1]?.clientSessionId,
  JSON.stringify(lifecycleCalls.map((call) => call.clientSessionId)),
  'CS-9-01'
)

const refuseNoReq = bridge.mergeCashierV3PublicVersions(
  [{ kind: 'room', id: 'R9', version: 1 }],
  'ctx-env'
)
ok('versions 合并缺 requestContext 拒绝', refuseNoReq.merged === 0 && refuseNoReq.refused >= 1, JSON.stringify(refuseNoReq), 'CS-9-02')

console.log(`BRIDGE_SUMMARY passed=${passed} failed=${failed}`)
if (failed > 0) process.exit(1)
console.log('RUNNER_OK=js/bridge-contract.mjs')

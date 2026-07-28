#!/usr/bin/env node

import fs from 'node:fs'
import { createRequire } from 'node:module'
import os from 'node:os'
import path from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const require = createRequire(path.resolve(__dirname, '../../cashier-v3/package.json'))
const esbuild = require('esbuild')
const repo = path.resolve(__dirname, '../../..')
const cashier = path.join(repo, '前端代码/cashier-v3')
const bridgeSource = path.join(cashier, 'src/services/cashierV3Bridge.js')
const contract = await import(pathToFileURL(path.join(cashier, 'src/services/unifiedQueryContract.js')).href)
const evidence = process.env.C1A_EVIDENCE_DIR || ''
const fixturePath = process.argv[2] || (evidence ? path.join(evidence, 'unified-query-gateway-samples.json') : '')
const outDir = process.env.C1A_TMP_DIR || fs.mkdtempSync(path.join(os.tmpdir(), 'uq-gateway-bridge-'))
const bundle = path.join(outDir, 'unified-query-bridge.bundle.mjs')

let passed = 0
let failed = 0
const emittedGates = new Set()

function ok(name, condition, detail = '', gateId = '') {
  if (condition) {
    passed += 1
    console.log(`  PASS  ${name}${gateId ? ` [${gateId}]` : ''}`)
    if (gateId && !emittedGates.has(gateId)) {
      emittedGates.add(gateId)
      console.log(`GATE_PASS=${gateId}`)
    }
    return
  }
  failed += 1
  console.log(`  FAIL  ${name}${detail ? ` -> ${detail}` : ''}${gateId ? ` [${gateId}]` : ''}`)
}

function record(value) {
  return value && typeof value === 'object' && !Array.isArray(value) ? value : {}
}

function clone(value) {
  return JSON.parse(JSON.stringify(value))
}

function fixtureEntry(samples, name) {
  return record(record(samples.entries)[name])
}

function projectionData(envelope, ...keys) {
  const data = record(envelope?.data)
  for (const key of keys) {
    const candidate = record(data[key])
    if (Object.keys(candidate).length) return candidate
  }
  return data
}

if (!fixturePath || !fs.existsSync(fixturePath)) {
  console.error(`UNIFIED_QUERY_GATEWAY_FIXTURE_MISSING=${fixturePath || '<empty>'}`)
  process.exit(1)
}

const samples = JSON.parse(fs.readFileSync(fixturePath, 'utf8'))
const requiredEntries = [
  'capability_before',
  'save_settings',
  'capability_after',
  'query_members',
  'create_export',
  'poll_export'
]
const missingEntries = requiredEntries.filter((name) => {
  const entry = fixtureEntry(samples, name)
  return !Object.keys(record(entry.request)).length || !Object.keys(record(entry.envelope)).length
})
ok(
  'PHP 固定样本包含六步真实 Gateway 请求与信封',
  samples.producer === 'php-gateway'
    && samples.pageCode === 'member_list'
    && missingEntries.length === 0,
  JSON.stringify({ producer: samples.producer, pageCode: samples.pageCode, missingEntries }),
  'UQ-XEND-01'
)

const badGatewayBindings = requiredEntries.filter((name) => {
  const entry = fixtureEntry(samples, name)
  const request = record(entry.request)
  const envelope = record(entry.envelope)
  const action = String(request.action || '')
  if (!action || envelope?.result?.status !== 'success') return true
  if (String(envelope.boundAction || '') !== action || String(envelope.boundCanonical || '') !== action) return true
  if (!String(envelope.boundCorrelationId || '') || envelope.boundCorrelationId !== request.correlationId) return true
  if (request.command) {
    return !String(envelope.boundIdempotencyKey || '')
      || envelope.boundIdempotencyKey !== request.command.idempotencyKey
  }
  return false
})
ok(
  'PHP 样本保留 Gateway action/correlation/idempotency 绑定',
  badGatewayBindings.length === 0,
  JSON.stringify(badGatewayBindings),
  'UQ-XEND-02'
)

await esbuild.build({
  entryPoints: [bridgeSource],
  bundle: true,
  format: 'esm',
  platform: 'browser',
  outfile: bundle,
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
ok('生产 bridge 可打包执行', fs.existsSync(bundle) && fs.statSync(bundle).size > 0, bundle, 'UQ-FE-01')

globalThis.window = globalThis
globalThis.window.location = { search: '', href: 'http://127.0.0.1/', pathname: '/', origin: 'http://127.0.0.1' }
globalThis.document = { addEventListener() {}, removeEventListener() {} }
const session = new Map()
window.sessionStorage = {
  getItem(key) { return session.has(key) ? session.get(key) : null },
  setItem(key, value) { session.set(key, String(value)) },
  removeItem(key) { session.delete(key) }
}
window.addEventListener = () => {}
window.removeEventListener = () => {}
window.dispatchEvent = () => true
globalThis.CustomEvent = class CustomEvent {
  constructor(type, init) { this.type = type; this.detail = init?.detail }
}
globalThis.crypto = {
  getRandomValues(array) {
    for (let index = 0; index < array.length; index += 1) array[index] = (index * 17 + 11) % 256
    return array
  },
  randomUUID() { return '11111111-2222-4333-8444-555555555555' }
}

const bridge = await import(pathToFileURL(bundle).href)
bridge.resetCashierV3BridgeForTests()

function minimalRoot(contextId) {
  return {
    stateContextId: contextId,
    stateRevision: '1',
    storeName: '统一查询测试店',
    currentStore: { id: 8, name: '统一查询测试店' },
    featurePermissions: { 'cashier.v3.member': true },
    workspace: { id: `ws:8:1:${contextId}`, revision: 1, status: 'editing', serverTime: '2026-07-28T00:00:00+08:00' },
    operator: { name: '统一查询测试员', roleName: '' },
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
    orderCenter: { businessTypes: [], salesOrders: [], salesOrderDetail: null }
  }
}

const fixtureContextId = String(fixtureEntry(samples, 'capability_before').envelope?.stateContextId || '')
ok('PHP Gateway 样本携带 stateContextId', fixtureContextId.length > 0, fixtureContextId, 'UQ-FE-01')
bridge.replaceCashierV3StateForContextSwitch(minimalRoot(fixtureContextId))

const queues = new Map([
  ['query-unified-query-capabilities', [
    fixtureEntry(samples, 'capability_before'),
    fixtureEntry(samples, 'capability_after')
  ]],
  ['save-member-query-settings', [fixtureEntry(samples, 'save_settings')]],
  ['query-members', [fixtureEntry(samples, 'query_members')]],
  ['create-unified-query-export', [fixtureEntry(samples, 'create_export')]],
  ['query-unified-query-export-task', [fixtureEntry(samples, 'poll_export')]]
])
const bridgeRequests = []

function bindForCurrentRequest(entry, action, payload) {
  const envelope = clone(entry.envelope)
  envelope.boundAction = action
  envelope.boundCanonical = action
  envelope.correlationId = payload.correlationId
  envelope.boundCorrelationId = payload.correlationId
  envelope.stateContextId = fixtureContextId
  if (payload.command) envelope.boundIdempotencyKey = payload.command.idempotencyKey
  if (payload.originalIdempotencyKey) envelope.boundOriginalIdempotencyKey = payload.originalIdempotencyKey
  return envelope
}

window.__CASHIER_V3_ADAPTER__ = {
  async request(action, payload) {
    bridgeRequests.push({ action, payload: clone(payload) })
    const queue = queues.get(action) || []
    const entry = queue.shift()
    if (!entry) throw new Error(`UNEXPECTED_GATEWAY_ACTION:${action}`)
    return bindForCurrentRequest(entry, action, payload)
  }
}

const capabilityBeforeRaw = await bridge.requestCashierV3Action('query-unified-query-capabilities', {
  pageCode: 'member_list',
  silent: true
})
const capabilityBefore = contract.normalizeUnifiedQueryCapability(capabilityBeforeRaw, 'member_list')
const capabilityVersionBefore = bridge.getCashierV3PublicVersion('query_preference', 'member_list')
ok(
  '真实 capability 信封经 bridge 后开放白名单并登记服务端版本',
  capabilityBefore.enabled === true
    && capabilityBefore.commandContext?.kind === 'query_preference'
    && capabilityBefore.commandContext?.id === 'member_list'
    && capabilityVersionBefore > 0
    && capabilityBefore.fields.some((field) => field.key === 'member_name')
    && capabilityBefore.fields.some((field) => field.custom === true),
  JSON.stringify({ capabilityBefore, capabilityVersionBefore }),
  'UQ-FE-02'
)

const savedSettingsFixture = record(fixtureEntry(samples, 'save_settings').input)
const saveRaw = await bridge.requestCashierV3Action('save-member-query-settings', {
  pageCode: 'member_list',
  settings: record(savedSettingsFixture.settings),
  commandContexts: [capabilityBefore.commandContext],
  silent: true
})
const saveRequest = bridgeRequests.find((item) => item.action === 'save-member-query-settings')
ok(
  '保存查询设置只使用 bridge 仓内版本并接受真实 Gateway 回执',
  contract.unifiedQueryActionSucceeded(saveRaw)
    && saveRequest?.payload?.command?.contexts?.length === 1
    && saveRequest.payload.command.contexts[0].kind === 'query_preference'
    && saveRequest.payload.command.contexts[0].id === 'member_list'
    && saveRequest.payload.command.contexts[0].expectedVersion === capabilityVersionBefore,
  JSON.stringify({ saveRaw, saveRequest }),
  'UQ-FE-03'
)

const capabilityAfterRaw = await bridge.requestCashierV3Action('query-unified-query-capabilities', {
  pageCode: 'member_list',
  silent: true
})
const capabilityAfter = contract.normalizeUnifiedQueryCapability(capabilityAfterRaw, 'member_list')
const capabilityAfterData = projectionData(
  capabilityAfterRaw,
  'unifiedQueryCapability',
  'unified_query_capability',
  'capability'
)
const savedRoundTrip = record(record(capabilityAfterData.querySettings).settings)
const capabilityVersionAfter = bridge.getCashierV3PublicVersion('query_preference', 'member_list')
ok(
  '保存后 capability 回读同一设置且服务端版本单调递增',
  capabilityAfter.enabled === true
    && capabilityVersionAfter > capabilityVersionBefore
    && JSON.stringify(savedRoundTrip.visibleFields || []) === JSON.stringify(savedSettingsFixture.settings?.visibleFields || [])
    && JSON.stringify(savedRoundTrip.filters || []) === JSON.stringify(savedSettingsFixture.expectedNormalizedFilters || savedSettingsFixture.settings?.filters || []),
  JSON.stringify({ savedRoundTrip, capabilityVersionBefore, capabilityVersionAfter }),
  'UQ-FE-04'
)

const queryInput = record(fixtureEntry(samples, 'query_members').input)
const queryRaw = await bridge.requestCashierV3Action('query-members', { ...queryInput, silent: true })
const memberData = projectionData(queryRaw, 'memberCenter', 'member_center', 'members')
const memberRecords = Array.isArray(memberData.records) ? memberData.records : []
ok(
  '真实 query-members 信封保留分页、列表与自定义字段值',
  contract.unifiedQueryActionSucceeded(queryRaw)
    && memberRecords.length > 0
    && Number(memberData.total) >= memberRecords.length
    && memberRecords.every((row) => row.id != null && typeof record(row.queryFieldValues) === 'object')
    && memberRecords.some((row) => Object.keys(record(row.queryFieldValues)).some((key) => key.startsWith('cf_'))),
  JSON.stringify(memberData),
  'UQ-FE-05'
)

const exportInput = record(fixtureEntry(samples, 'create_export').input)
const exportPayload = contract.buildUnifiedQueryExportPayload(
  record(exportInput.query),
  record(exportInput.configuration),
  capabilityAfter
)
const createExportRaw = await bridge.requestCashierV3Action('create-unified-query-export', {
  pageCode: 'member_list',
  ...exportPayload,
  commandContexts: [capabilityAfter.commandContext],
  silent: true
})
const createdTask = contract.extractUnifiedQueryExportTask(createExportRaw)
ok(
  '真实 create-export 信封经 bridge 保留任务标识与冻结请求',
  contract.unifiedQueryActionSucceeded(createExportRaw)
    && String(createdTask.id || '').startsWith('uqe_')
    && exportPayload.query.page === exportInput.query?.page
    && exportPayload.query.pageSize === exportInput.query?.pageSize
    && exportPayload.query.keyword === exportInput.query?.keyword,
  JSON.stringify({ createdTask, exportPayload }),
  'UQ-FE-06'
)

const pollRaw = await bridge.requestCashierV3Action('query-unified-query-export-task', {
  pageCode: 'member_list',
  taskId: createdTask.id,
  silent: true
})
const polledTask = contract.extractUnifiedQueryExportTask(pollRaw)
ok(
  '真实 task poll 信封只接受站内下载地址并保留行数',
  contract.unifiedQueryActionSucceeded(pollRaw)
    && polledTask.id === createdTask.id
    && ['succeeded', 'ready', 'completed'].includes(String(polledTask.status).toLowerCase())
    && Number(polledTask.rowCount) > 0
    && String(polledTask.downloadUrl).startsWith('/')
    && !String(polledTask.downloadUrl).startsWith('//'),
  JSON.stringify(polledTask),
  'UQ-FE-07'
)

const pendingQueues = [...queues.entries()].filter(([, queue]) => queue.length > 0).map(([action]) => action)
ok(
  '跨端样本全部被真实 bridge 消费且未遗漏动作',
  pendingQueues.length === 0
    && bridgeRequests.map((item) => item.action).join(',') === [
      'query-unified-query-capabilities',
      'save-member-query-settings',
      'query-unified-query-capabilities',
      'query-members',
      'create-unified-query-export',
      'query-unified-query-export-task'
    ].join(','),
  JSON.stringify({ pendingQueues, actions: bridgeRequests.map((item) => item.action) }),
  'UQ-XEND-03'
)

console.log(`ASSERT_PASSED=${passed}`)
console.log(`ASSERT_FAILED=${failed}`)
if (failed > 0) process.exit(1)
console.log('RUNNER_OK=unified-query/js/gateway-envelope-contract.mjs')

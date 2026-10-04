#!/usr/bin/env node
/**
 * 第22轮前端桥接合同：订单归属更正由后端锁定原单并校验旧值，
 * 浏览器不得因为没有虚构的单据版本而把写请求挡在适配器之前。
 */
import assert from 'node:assert/strict'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import esbuild from 'esbuild'

const testDir = path.dirname(fileURLToPath(import.meta.url))
const bridgeSource = path.resolve(testDir, '../../../前端代码/cashier-v3/src/services/cashierV3Bridge.js')
const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'r22-attribution-context-'))
const bundle = path.join(tempDir, 'bridge.mjs')

try {
  await esbuild.build({
    entryPoints: [bridgeSource], bundle: true, format: 'esm', platform: 'browser',
    outfile: bundle, logLevel: 'silent',
    define: { 'import.meta.env.DEV': 'false', 'import.meta.env.PROD': 'true', 'import.meta.env.MODE': '"test"' },
    plugins: [{
      name: 'vue-shim',
      setup(build) {
        build.onResolve({ filter: /^vue$/ }, () => ({ path: 'vue-shim', namespace: 'vue-shim' }))
        build.onLoad({ filter: /.*/, namespace: 'vue-shim' }, () => ({
          contents: 'export function reactive(value){return value}', loader: 'js'
        }))
      }
    }]
  })

  const session = new Map()
  globalThis.window = globalThis
  window.location = { search: '', href: 'http://127.0.0.1/', pathname: '/' }
  window.sessionStorage = {
    getItem(key) { return session.get(key) ?? null },
    setItem(key, value) { session.set(key, String(value)) },
    removeItem(key) { session.delete(key) }
  }
  window.addEventListener = () => {}
  window.removeEventListener = () => {}
  window.dispatchEvent = () => true
  globalThis.document = { addEventListener() {}, removeEventListener() {} }
  globalThis.CustomEvent = class CustomEvent {
    constructor(type, init) { this.type = type; this.detail = init?.detail }
  }
  globalThis.crypto = {
    getRandomValues(bytes) { bytes.fill(1); return bytes },
    randomUUID() { return '11111111-2222-4333-8444-555555555555' }
  }

  const bridge = await import(pathToFileURL(bundle).href)
  bridge.resetCashierV3BridgeForTests()
  // 从真实桥接默认根结构复制完整合同，避免旧测试夹具缺字段而误报权限失败。
  const root = JSON.parse(JSON.stringify(bridge.cashierV3State))
  root.stateContextId = 'r22-attribution-test'
  root.stateRevision = '1'
  root.currentStore = { id: 16, name: '本地测试门店' }
  root.storeName = '本地测试门店'
  root.featurePermissions['cashier.v3.order_center'] = true
  root.workspace = { id: 'ws:16:r22-attribution-test', revision: 1, status: 'editing', serverTime: null }
  const loaded = bridge.replaceCashierV3StateForContextSwitch(root)
  assert.notEqual(loaded?.accepted, false, JSON.stringify(loaded))

  const requests = []
  window.__CASHIER_V3_ADAPTER__ = {
    async request(action, payload) {
      requests.push({ action, payload })
      return { result: { status: 'failed', code: 'MOCK_BACKEND', message: '测试未写库' }, data: {} }
    }
  }
  const actions = [
    'correct-sales-order-attribution',
    'correct-service-order-date',
    'correct-recharge-order-attribution',
    'correct-debt-repayment-date',
    'correct-debt-repayment-source'
  ]
  for (const action of actions) {
    await bridge.requestCashierV3Action(action, {
      silent: true, recordId: 'test-record', field: 'business_date',
      expectedValue: '2026-10-04', newValue: '2026-10-03',
      // 即使调用方误传展示版本，也不能成为历史更正的并发控制来源。
      ...(action === actions[0] ? { commandContexts: [{ kind: 'sales_order', id: 'test-record', expectedVersion: 99 }] } : {})
    })
  }
  assert.deepEqual(requests.map(({ action }) => action), actions)
  for (const { payload } of requests) {
    assert.deepEqual(payload.command.contexts, [])
    assert.ok(payload.command.idempotencyKey)
  }
  console.log('R22 order attribution: five commands reached adapter with empty contexts; no database write')
} finally {
  fs.rmSync(tempDir, { recursive: true, force: true })
}

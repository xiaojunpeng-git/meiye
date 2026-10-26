#!/usr/bin/env node

import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { pathToFileURL, fileURLToPath } from 'node:url'

const testDir = path.dirname(path.dirname(fileURLToPath(import.meta.url)))
const root = path.resolve(testDir, '../..')
const frontendRoot = path.join(root, '前端代码/cashier-v3')
const adapterUrl = pathToFileURL(path.join(frontendRoot, 'src/services/cashierV3HttpAdapter.js')).href
const resultContractUrl = pathToFileURL(path.join(frontendRoot, 'src/services/cashierV3CheckoutResultContract.js')).href
const workbenchSource = fs.readFileSync(path.join(frontendRoot, 'src/views/CashierWorkbenchView.vue'), 'utf8')
const mainSource = fs.readFileSync(path.join(frontendRoot, 'src/main.js'), 'utf8')

let passed = 0
function test(name, callback) {
  return Promise.resolve().then(callback).then(() => {
    passed += 1
    console.log(`PASS ${name}`)
  })
}

const adapterModule = await import(`${adapterUrl}?v=${Date.now()}`)
const resultModule = await import(`${resultContractUrl}?v=${Date.now()}`)

await test('production adapter uses only the current tab session token and never sends shared cookies', async () => {
  const calls = []
  const tokenStorage = { getItem: (key) => key === 'cashier-v3:session-token' ? 'tab-a-token' : null }
  const adapter = adapterModule.createCashierV3HttpAdapter({
    origin: 'https://cashier.example.test',
    endpoint: '/cashierapi/v3/workbenches/actions',
    cookie: 'token=legacy-token; cashier_token=cookie%20token',
    storage: tokenStorage,
    timeoutMs: 0,
    fetchImpl: async (url, options) => {
      calls.push({ url, options })
      return {
        ok: true,
        status: 200,
        text: async () => JSON.stringify({ status: 200, data: { result: { status: 'success' } } })
      }
    }
  })
  const response = await adapter.request('query-members', { page: 1 })
  assert.equal(response.status, 200)
  assert.equal(calls.length, 1)
  assert.equal(calls[0].url, 'https://cashier.example.test/cashierapi/v3/workbenches/actions')
  assert.equal(calls[0].options.credentials, 'omit')
  assert.equal(calls[0].options.headers['X-Source'], 'f76d38d0ee4f854f')
  assert.equal(calls[0].options.headers['Authori-zation'], 'Bearer tab-a-token')
  assert.deepEqual(JSON.parse(calls[0].options.body), { page: 1, action: 'query-members' })
})

await test('two tabs retain independent V3 authorization headers', async () => {
  const calls = []
  const makeAdapter = (token) => adapterModule.createCashierV3HttpAdapter({
    origin: 'https://cashier.example.test',
    timeoutMs: 0,
    storage: { getItem: (key) => key === 'cashier-v3:session-token' ? token : null },
    fetchImpl: async (_url, options) => {
      calls.push(options.headers['Authori-zation'])
      return { ok: true, status: 200, text: async () => JSON.stringify({ status: 200, data: { result: { status: 'success' } } }) }
    }
  })
  await makeAdapter('store-a-token').request('query-members', { page: 1 })
  await makeAdapter('store-b-token').request('query-members', { page: 1 })
  assert.deepEqual(calls, ['Bearer store-a-token', 'Bearer store-b-token'])
})

await test('adapter preserves a structured submit failure even when HTTP status is non-2xx', async () => {
  const failure = {
    result: {
      status: 'failed',
      code: 'ACTION_DEPENDENCY_NOT_READY',
      message: '收银服务依赖尚未完成升级，当前结账未提交。'
    },
    boundAction: 'submit-checkout',
    boundCanonical: 'submit-checkout',
    boundIdempotencyKey: 'CHECKOUT-12345678-1234-4abc-8abc-1234567890ab',
    correlationId: 'CORR-12345678-1234-4abc-8abc-1234567890ab',
    boundCorrelationId: 'CORR-12345678-1234-4abc-8abc-1234567890ab',
    stateContextId: 'ctx-1'
  }
  const adapter = adapterModule.createCashierV3HttpAdapter({
    origin: 'https://cashier.example.test',
    timeoutMs: 0,
    storage: { getItem: () => 'tab-a-token' },
    fetchImpl: async () => ({
      ok: false,
      status: 400,
      text: async () => JSON.stringify({ status: 400, data: failure })
    })
  })
  const response = await adapter.request('submit-checkout', {
    stateContextId: 'ctx-1',
    correlationId: failure.correlationId,
    command: {
      action: 'submit-checkout',
      idempotencyKey: failure.boundIdempotencyKey,
      contexts: []
    }
  })
  assert.equal(response.status, 400)
  assert.equal(response.data.result.status, 'failed')
  assert.equal(response.data.result.code, 'ACTION_DEPENDENCY_NOT_READY')
  assert.equal(response.data.boundIdempotencyKey, failure.boundIdempotencyKey)
})

await test('adapter rejects cross-origin endpoints and action mismatches before sending credentials', async () => {
  assert.throws(() => adapterModule.createCashierV3HttpAdapter({
    origin: 'https://cashier.example.test',
    endpoint: 'https://other.example.test/cashierapi/v3/workbenches/actions',
    fetchImpl: async () => ({ ok: true, status: 200, text: async () => '{}' })
  }), /同源/)
  let calls = 0
  const adapter = adapterModule.createCashierV3HttpAdapter({
    origin: 'https://cashier.example.test',
    timeoutMs: 0,
    fetchImpl: async () => {
      calls += 1
      return { ok: true, status: 200, text: async () => '{}' }
    }
  })
  await assert.rejects(adapter.request('query-members', { action: 'submit-checkout' }), /不一致/)
  await assert.rejects(adapter.bootstrap({ action: 'submit-checkout' }), /初始化操作无效/)
  assert.equal(calls, 0)
})

function resultEnvelope(overrides = {}) {
  const originalIdempotencyKey = 'CHECKOUT-12345678-1234-4abc-8abc-1234567890ab'
  return {
    result: { status: 'success', code: '', message: 'ok' },
    stateContextId: 'ctx-1',
    boundAction: 'query-checkout-result',
    boundCanonical: 'query-checkout-result',
    boundOriginalIdempotencyKey: originalIdempotencyKey,
    correlationId: 'CORR-12345678-1234-4abc-8abc-1234567890ab',
    boundCorrelationId: 'CORR-12345678-1234-4abc-8abc-1234567890ab',
    data: {
      checkoutResult: {
        contractVersion: 'cashier-v3-checkout-result-v1',
        originalIdempotencyKey,
        status: 'success',
        phase: 'succeeded',
        code: '',
        message: '结账已完成。',
        checkoutRequest: {
          requestId: 'CKR-123',
          requestVersion: 5,
          requestStatus: 'succeeded'
        },
        salesOrder: {
          orderId: 'CSO-123',
          orderNo: 'SO-20260729-ABC',
          orderStatus: 'settled',
          orderVersion: 1
        }
      }
    },
    ...overrides
  }
}

await test('checkout success requires the original key, context, binding and authoritative committed DTO', () => {
  const expected = {
    originalIdempotencyKey: 'CHECKOUT-12345678-1234-4abc-8abc-1234567890ab',
    checkoutRequestId: 'CKR-123',
    stateContextId: 'ctx-1'
  }
  const accepted = resultModule.authoritativeCheckoutResult(resultEnvelope(), expected)
  assert.equal(accepted?.status, 'succeeded')
  assert.equal(accepted?.salesOrder?.orderId, 'CSO-123')
  assert.equal(resultModule.authoritativeCheckoutResult(resultEnvelope({ boundOriginalIdempotencyKey: 'CHECKOUT-other' }), expected), null)
  assert.equal(resultModule.authoritativeCheckoutResult(resultEnvelope({ stateContextId: 'ctx-other' }), expected), null)
  const invalidDto = resultEnvelope()
  invalidDto.data.checkoutResult.salesOrder.orderStatus = 'pending'
  assert.equal(resultModule.authoritativeCheckoutResult(invalidDto, expected), null)
})

await test('production entry installs adapter before boot and checkout automatically queries the original result', () => {
  assert.ok(mainSource.indexOf('installCashierV3HttpAdapter()') < mainSource.indexOf('ensureCashierV3RouterBootstrap('))
  assert.ok(mainSource.indexOf('ensureCashierV3RouterBootstrap(') < mainSource.indexOf('createApp(App)'))
  assert.match(workbenchSource, /cashierV3ResponseRequiresRefresh\(result\)/)
  assert.match(workbenchSource, /await requestAction\('query-checkout-result'/)
  assert.match(workbenchSource, /authoritativeCheckoutResult\(result,/)
  assert.match(workbenchSource, /originalIdempotencyKey\.startsWith\('CHECKOUT-'\)/)
  assert.match(workbenchSource, /checkoutRequiresRootReload\.value = true/)
  assert.match(workbenchSource, /window\.location\.reload\(\)/)
  assert.match(workbenchSource, /结账结果暂时无法完整核验/)
})

console.log(`\ncheckout-production-http-contract: ${passed} passed, 0 failed`)

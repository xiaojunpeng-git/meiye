import assert from 'node:assert/strict'
import { readCashierV3TransportToken, orderCenterExportDownloadRequest, createCashierV3HttpAdapter } from '../../前端代码/cashier-v3/src/services/cashierV3HttpAdapter.js'

const origin = 'https://example.test'
const windowRef = { location: { origin }, document: { cookie: 'admin-token=ADMIN; admin-agent_token=AGENT' }, sessionStorage: { getItem: () => 'STORE' } }
const platform = { windowRef, mode: 'platform-order-center' }
const store = { windowRef }
const task = 'uqe_' + 'a'.repeat(32)
const platformPath = `/adminapi/store/order-center/exports/${task}/download`
const storePath = `/cashierapi/v3/unified-query/exports/${task}/download`
assert.equal(readCashierV3TransportToken(platform), 'ADMIN')
assert.equal(readCashierV3TransportToken(store), 'STORE')
assert.equal(orderCenterExportDownloadRequest(platformPath, platform).init.headers['Authori-zation'], 'Bearer ADMIN')
assert.equal(orderCenterExportDownloadRequest(storePath, store).init.headers.Authorization, 'Bearer STORE')
assert.equal(orderCenterExportDownloadRequest(platformPath, platform).init.redirect, 'error')
for (const url of [storePath, `https://other.test${platformPath}`, '/adminapi/other', `${platformPath}/extra`, `https://user:password@example.test${platformPath}`]) {
  assert.throws(() => orderCenterExportDownloadRequest(url, platform))
}
assert.throws(() => orderCenterExportDownloadRequest(platformPath, store))
const noAdmin = { ...platform, windowRef: { ...windowRef, document: { cookie: '' } } }
assert.equal(readCashierV3TransportToken(noAdmin), '')
assert.throws(() => orderCenterExportDownloadRequest(platformPath, noAdmin))
assert.equal(readCashierV3TransportToken({ ...platform, windowRef: { ...windowRef, document: { cookie: 'admin-agent_token=AGENT' } } }), 'AGENT')
for (const [options, expected] of [[platform, 'ADMIN'], [store, 'STORE']]) {
  let sent
  const adapter = createCashierV3HttpAdapter({ ...options, fetchImpl: async (url, init) => {
    sent = init.headers['Authori-zation']
    return { ok: true, text: async () => JSON.stringify({ status: 200 }) }
  } })
  await adapter.request('query-sales-orders')
  assert.equal(sent, `Bearer ${expected}`)
}
console.log('R64_EXPORT_TRANSPORT=PASS (identity isolation, URL boundary, redirect rejection, query/download consistency)')

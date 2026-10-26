/** Execute the production UTS client with an in-memory transport to verify cache scope and rejection handling. */
import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import test from 'node:test'
import { stripTypeScriptTypes } from 'node:module'

const root = path.resolve(import.meta.dirname, '../../..')
const clientSource = fs.readFileSync(path.join(root, '前端代码/mobile-vue3/src/shared/api/mobile-home-client.uts'), 'utf8')
const formatSource = fs.readFileSync(path.join(root, '前端代码/mobile-vue3/src/shared/platform/merchant-dashboard-presentation.uts'), 'utf8')
let moduleId = 0
async function clientHarness() {
  const storage = new Map()
  let session = { activeContextId: 'account-a', stateContextId: 'scope-a', clientSessionId: 'browser-a' }
  let reply = { ok: true, body: { marker: 'fresh-a' } }
  const harness = {
    uni: { getStorageSync: key => storage.get(key), setStorageSync: (key, value) => storage.set(key, value) },
    currentMerchantRoot: () => ({}), merchantRequestSession: () => session,
    buildMobileRequestPlan: () => ({}), mobileRequestPlanMatchesContract: () => true,
    sendMobileRequest: (_plan, _payload, done) => done(reply)
  }
  globalThis.__r13Harness = harness
  const imports = 'const { uni, currentMerchantRoot, merchantRequestSession, buildMobileRequestPlan, mobileRequestPlanMatchesContract, sendMobileRequest } = globalThis.__r13Harness;\n'
  const js = stripTypeScriptTypes(clientSource.replace(/^import .*\n/gm, ''))
  const client = await import('data:text/javascript;base64,' + Buffer.from(imports + js + '\n// ' + moduleId++).toString('base64'))
  delete globalThis.__r13Harness
  const query = payload => { const responses = []; client.queryMobileMerchantHome(payload, value => responses.push(value)); return responses }
  return { query, setSession: value => { session = value }, setReply: value => { reply = value } }
}

test('cache is isolated by authenticated context and exact dates', async () => {
  const h = await clientHarness()
  const first = { periodMode: 'custom', startDate: '2026-10-21', endDate: '2026-10-21' }
  assert.equal(h.query(first).length, 1)
  assert.equal(h.query(first)[0].cached, true)
  assert.equal(h.query({ ...first, startDate: '2026-10-22', endDate: '2026-10-22' }).length, 1)
  h.setSession({ activeContextId: 'account-b', stateContextId: 'scope-b', clientSessionId: 'browser-a' })
  assert.equal(h.query(first).length, 1, 'another account cannot display previous account cache')
})

test('server permission rejection reaches caller even after a cached response', async () => {
  const h = await clientHarness()
  h.query({ periodMode: 'today' })
  h.setReply({ ok: false, message: '权限已变更', body: { errorCode: 'STORE_NOT_ALLOWED' } })
  const results = h.query({ periodMode: 'today' })
  assert.equal(results.length, 2)
  assert.equal(results.at(-1).ok, false)
})

test('new amounts use the platform integer-yuan rounding for positive and negative values', async () => {
  const js = stripTypeScriptTypes(formatSource)
  const { formatDashboardMoney } = await import('data:text/javascript;base64,' + Buffer.from(js).toString('base64'))
  assert.equal(formatDashboardMoney(150), '¥2')
  assert.equal(formatDashboardMoney(-150), '¥-2')
  assert.equal(formatDashboardMoney(0), '¥0')
  assert.equal(formatDashboardMoney(null), '—')
})

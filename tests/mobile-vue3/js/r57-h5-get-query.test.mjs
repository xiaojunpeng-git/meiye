import assert from 'node:assert/strict'
import fs from 'node:fs'
import vm from 'node:vm'
import { createRequire } from 'node:module'

// Execute the actual WEB transport, not a second implementation of URL encoding.
const root = new URL('../../../', import.meta.url)
const require = createRequire(new URL('前端代码/cashier-v3/package.json', root))
const { transformSync } = require('esbuild')
const source = fs.readFileSync(new URL('前端代码/mobile-vue3/src/shared/api/mobile-http.uts', root), 'utf8')
const web = source.replace(/^import .*$/gm, '').replace(/\s*\/\/ #ifndef WEB[\s\S]*?\/\/ #endif/g, '').replace(/^export /gm, '')
const calls = []
const context = vm.createContext({ URL, AbortController, setTimeout, clearTimeout,
  mobileApiOrigin: () => 'http://127.0.0.1:18092',
  fetch: async (url, options) => {
    calls.push({ url: new URL(url), options })
    return { status: 200, json: async () => ({ records: [] }) }
  }
})
vm.runInContext(transformSync(web, { loader: 'ts' }).code + '\nthis.send = sendMobileRequest;', context)
const headers = { Authorization: 'Bearer test-only', 'X-Mobile-Active-Context-Id': 'store-135' }
const send = (method, path, payload) => new Promise(resolve => context.send({ method, path, headers }, payload, resolve))
await send('GET', '/mobile/merchant/reservations', { workflow: 'confirmation', quickFilter: 'pending_confirmation', page: 2, pageSize: 20 })
assert.equal(calls[0].url.searchParams.get('workflow'), 'confirmation')
assert.equal(calls[0].url.searchParams.get('quickFilter'), 'pending_confirmation')
assert.equal(calls[0].url.searchParams.get('page'), '2')
assert.equal(calls[0].url.searchParams.get('pageSize'), '20')
assert.equal(calls[0].options.body, undefined)
assert.equal(calls[0].options.credentials, 'omit')
assert.equal(calls[0].options.headers.Authorization, headers.Authorization)
assert.equal(calls[0].options.headers['X-Mobile-Active-Context-Id'], 'store-135')
await send('GET', '/mobile/merchant/reservations?existing=kept', { keyword: '姓名 & +', zero: 0, flag: false, missing: null })
assert.equal(calls[1].url.searchParams.get('existing'), 'kept')
assert.equal(calls[1].url.searchParams.get('keyword'), '姓名 & +')
assert.equal(calls[1].url.searchParams.get('zero'), '0')
assert.equal(calls[1].url.searchParams.get('flag'), 'false')
assert.equal(calls[1].url.searchParams.has('missing'), false)
await send('GET', '/mobile/merchant/reservations?page=3', null)
assert.equal(calls[2].url.searchParams.getAll('page').length, 1)
assert.equal(calls[2].url.searchParams.get('page'), '3')
for (const method of ['POST', 'PUT', 'DELETE']) {
  await send(method, '/mobile/merchant/reservations/8203', { reason: 'test only' })
  const call = calls.at(-1)
  assert.equal(call.url.search, '')
  assert.deepEqual(JSON.parse(call.options.body), { reason: 'test only' })
}
console.log('R57_H5_GET_QUERY=PASS: confirmation filters, pagination, context headers, encoding, preencoded URLs and write bodies')

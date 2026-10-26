/** 真实门店登录权限初始化回归：单表岗位能力须保留基础报表入口且不开放旧数据报表。 */
import assert from 'node:assert/strict'
import path from 'node:path'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const bridgeFile = path.join(root, '前端代码/cashier-v3/src/services/cashierV3Bridge.js')
const require = createRequire(import.meta.url)
const esbuild = require(path.join(root, '前端代码/cashier-v3/node_modules/esbuild'))
const output = await esbuild.build({
  entryPoints: [bridgeFile], bundle: true, write: false, format: 'esm', platform: 'browser',
  define: { 'import.meta.env.DEV': 'false', 'import.meta.env.PROD': 'true', 'import.meta.env.MODE': '"test"' },
  plugins: [{ name: 'vue-test-shim', setup(build) {
    build.onResolve({ filter: /^vue$/ }, () => ({ path: 'vue-test-shim', namespace: 'vue-test-shim' }))
    build.onLoad({ filter: /.*/, namespace: 'vue-test-shim' }, () => ({
      contents: 'export function reactive(value){return value} export default { reactive }', loader: 'js'
    }))
  } }]
})

globalThis.window = globalThis
globalThis.window.location = { search: '', href: 'http://127.0.0.1/', pathname: '/' }
globalThis.document = { addEventListener() {}, removeEventListener() {} }
const session = new Map()
globalThis.window.sessionStorage = {
  getItem: key => session.get(key) ?? null,
  setItem: (key, value) => session.set(key, String(value)),
  removeItem: key => session.delete(key)
}
globalThis.window.addEventListener = () => {}
globalThis.window.removeEventListener = () => {}
globalThis.crypto = globalThis.crypto || { getRandomValues: values => values, randomUUID: () => '11111111-2222-4333-8444-555555555555' }
const bundle = Buffer.from(output.outputFiles[0].text).toString('base64')
const bridge = await import(`data:text/javascript;base64,${bundle}`)

bridge.applyCashierV3LoginFeatures(['cashier.v3.basic_reports'])
assert.equal(bridge.canUseCashierV3Feature('cashier.v3.basic_reports'), true)
assert.equal(bridge.canUseCashierV3Feature('cashier.v3.data'), false)
bridge.applyCashierV3LoginFeatures([])
assert.equal(bridge.canUseCashierV3Feature('cashier.v3.basic_reports'), false)
console.log('PASS basic report login permission survives bootstrap and revocation')

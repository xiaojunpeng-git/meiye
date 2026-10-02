/** 编译真实 Vue SFC 并运行 setup：不代替浏览器验收，验证重复提交和切店响应保护。 */
const fs = require('fs')
const path = require('path')
const assert = require('assert')
const root = process.env.CASHIER_SOURCE_ROOT || '/app'
const vue = require(path.join(root, 'node_modules/vue'))
const { parse, compileScript, compileTemplate } = require(path.join(root, 'node_modules/@vue/compiler-sfc'))
const source = fs.readFileSync(path.join(root, 'src/components/StaffPhoneTransferDialog.vue'), 'utf8')
const descriptor = parse(source).descriptor
const template = compileTemplate({ source: descriptor.template.content, filename: 'StaffPhoneTransferDialog.vue', id: 'r51' })
assert.deepEqual(template.errors, [])
assert.equal((descriptor.template.content.match(/<input\b/g) || []).length, 1)
let searchReply = { data: { phone: '19912345678', employee_id: 123, source_staff_id: 456, expected_version: 1, snapshot_hash: 'a'.repeat(64), staff_name: '测试员工', from_store_name: '原门店' } }
let fail = null, tokens = [], lookups = 0
const mocks = {
  findStoreTransferStaff: async () => { lookups++; return typeof searchReply === 'function' ? searchReply() : searchReply },
  createRequestToken: () => 'r51-stable-token',
  confirmStoreTransferStaff: async (candidate, token) => { tokens.push(token); if (fail) throw fail; return { data: {} } }
}
let compiled = compileScript(descriptor, { id: 'r51' }).content
compiled = compiled.replace(/import\s+\{([^}]+)\}\s+from\s+['"]vue['"]/g, (_, names) => `const {${names.replace(/\bas\b/g, ':')}} = vue`)
compiled = compiled.replace(/import\s+\{([^}]+)\}\s+from\s+['"]@\/services\/staffManagementApi['"]/g, (_, names) => `const {${names}} = mocks`)
compiled = compiled.replace('export default', 'module.exports =')
const holder = { exports: {} }
new Function('vue', 'mocks', 'module', compiled)(vue, mocks, holder)
const props = vue.reactive({ open: true, contextKey: 'store-a', storeName: '本门店' })
const events = []
const dialog = holder.exports.setup(props, { expose() {}, emit: (name) => events.push(name) })
async function run() {
  dialog.phone.value = '199'; await vue.nextTick(); await dialog.search()
  assert.equal(lookups, 0); assert.ok(dialog.error.value.includes('11位'))
  dialog.phone.value = '19912345678'; await vue.nextTick(); await dialog.search()
  assert.equal(dialog.candidate.value.employee_id, 123)
  fail = new Error('network lost'); await dialog.confirm()
  assert.equal(dialog.uncertain.value, true)
  const before = events.length; dialog.close(); await dialog.search()
  assert.equal(events.length, before); assert.equal(lookups, 1)
  fail = null; await dialog.confirm()
  assert.deepEqual(tokens, ['r51-stable-token', 'r51-stable-token'])
  assert.deepEqual(events.slice(-2), ['transferred', 'close'])
  await dialog.search(); fail = Object.assign(new Error('资料已变化'), { definitive: true }); await dialog.confirm()
  assert.equal(dialog.candidate.value, null); assert.equal(dialog.uncertain.value, false)
  let resolveLookup
  searchReply = () => new Promise(resolve => { resolveLookup = resolve })
  const pending = dialog.search(); props.contextKey = 'store-b'; await vue.nextTick()
  resolveLookup({ data: { employee_id: 999 } }); await pending
  assert.equal(dialog.candidate.value, null); assert.equal(dialog.phone.value, '')
  console.log('PASS compiled SFC, phone-only input, validation, original-token replay, definitive failure, store-context race (6 groups)')
}
run().catch(error => { console.error(error); process.exitCode = 1 })

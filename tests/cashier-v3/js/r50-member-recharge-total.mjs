import assert from 'node:assert/strict'
import fs from 'node:fs'
import vm from 'node:vm'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'

// Execute the real summary ordering without loading or summing history rows.
const require = createRequire(import.meta.url)
const root = new URL('../../../', import.meta.url)
const path = new URL('前端代码/cashier-v3/src/components/member/MemberDetailOverlay.vue', root)
const source = fs.readFileSync(path, 'utf8')
const start = source.indexOf('const headerStats = computed(')
const end = source.indexOf('\nconst basicRows', start)
assert.ok(start >= 0 && end > start)
const context = vm.createContext({
  computed: fn => ({ value: fn() }), metric: (label, value, display) => ({ label, value, display }),
  numberText: value => String(value), summaryValue: keys => keys[0] === 'totalRechargeAmount' ? '2980.00' : 0,
})
vm.runInContext(source.slice(start, end) + '\nthis.stats = headerStats.value', context)
const index = context.stats.findIndex(item => item.label === '充值总额')
assert.ok(index > 0)
assert.equal(context.stats[index + 1].label, '总消费金额')
assert.equal(context.stats[index].value, '2980.00')
assert.equal(context.stats[index].display, 'money')
assert.equal(context.stats.length, 11)
assert.doesNotMatch(source, /metric\('充值金额'/)
assert.match(source, /repeat\(11, minmax\(104px, 1fr\)\)/)
assert.match(source, /repeat\(11, minmax\(128px, 1fr\)\)/)
const compiler = require(fileURLToPath(new URL('前端代码/cashier-v3/node_modules/@vue/compiler-sfc', root)))
const { descriptor, errors } = compiler.parse(source, { filename: fileURLToPath(path) })
assert.equal(errors.length, 0)
const compiled = compiler.compileScript(descriptor, { id: 'r50-member' })
const template = compiler.compileTemplate({ source: descriptor.template.content, filename: fileURLToPath(path),
  id: 'r50-member', compilerOptions: { bindingMetadata: compiled.bindings } })
assert.equal(template.errors.length, 0)
console.log('R50_MEMBER_RECHARGE_TOTAL_UI=PASS (position, backend value, money display, 11 columns, Vue compilation)')

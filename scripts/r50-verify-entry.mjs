import { execFileSync } from 'node:child_process'
import { readFileSync, writeFileSync } from 'node:fs'
import { createHash } from 'node:crypto'
import { createRequire } from 'node:module'
import vm from 'node:vm'
import assert from 'node:assert/strict'

// Exercise real entry handlers and compile the actual Vue SFC. No session,
// authentication data, browser cart or checkout command is changed by this test.
const require = createRequire(import.meta.url)
const sfc = require('../前端代码/cashier-v3/node_modules/@vue/compiler-sfc')
const path = '前端代码/cashier-v3/src/views/CashierWorkbenchView.vue'
const source = readFileSync(path, 'utf8')
const results = []
for (const file of ['r30-custom-card-entry-contract.mjs', 'r50-custom-card-mixed-entry.mjs']) {
  const output = execFileSync(process.execPath, ['tests/cashier-v3/js/' + file], { encoding: 'utf8' })
  results.push({ file, status: 'PASS', output })
}
const { descriptor, errors } = sfc.parse(source, { filename: path })
assert.equal(errors.length, 0)
const compiled = sfc.compileScript(descriptor, { id: 'r50' })
const template = sfc.compileTemplate({ source: descriptor.template.content, filename: path,
  id: 'r50', compilerOptions: { bindingMetadata: compiled.bindings } })
assert.equal(template.errors.length, 0)
results.push({ file: path, status: 'PASS', check: 'Vue script and template compilation' })
// This broader legacy contract already fails on an untouched coupon CSS rule.
// Re-run against the captured pre-R50 Vue blob to prove it is not introduced
// here; do not change unrelated approved styles merely to make the test green.
const legacyFile = 'tests/cashier-v3/js/catalog-custom-card-ui-contract.mjs'
const before = JSON.parse(readFileSync('tests/cashier-v3/evidence/R50-before.json'))
const oldVue = execFileSync('git', ['cat-file', 'blob', before.files.find(f => f.path === path).blob], { encoding: 'utf8' })
const legacy = readFileSync(legacyFile, 'utf8').replace(/^import .*$/gm, '')
  .replaceAll('import.meta.url', JSON.stringify(new URL('../' + legacyFile, import.meta.url).href))
const legacyResult = input => {
  try {
    vm.runInNewContext(legacy, { URL, assert, console: { log() {} }, fs: {
      readFileSync(file, ...args) { return String(file).endsWith('/CashierWorkbenchView.vue') ? input : readFileSync(file, ...args) },
    } })
    return 'PASS'
  } catch (error) { return error.message }
}
const baselineFailure = legacyResult(oldVue), currentFailure = legacyResult(source)
assert.equal(currentFailure, baselineFailure)
assert.equal(currentFailure, '优惠券须在原位置基础上左移30px')
results.push({ file: legacyFile, status: 'EXISTING_BASELINE_FAILURE', reason: currentFailure,
  baselineReproduced: true, unrelatedCssChanged: false })
writeFileSync('tests/cashier-v3/evidence/R50-entry-tests.json', JSON.stringify({
  path, sha256: createHash('sha256').update(source).digest('hex'), testedAt: new Date().toISOString(), results,
  browserVisualQA: 'Not performed: no callable browser interaction tool in this turn',
}, null, 2) + '\n')
console.log('R50_ENTRY_AND_VUE_CHECKS=PASS; one unrelated baseline CSS assertion remains unchanged')

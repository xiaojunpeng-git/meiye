/** R98：真实分配组件在开关两态及简易/完整模式下保留相同售前事实。 */
import assert from 'node:assert/strict'
import { readFileSync, writeFileSync, mkdtempSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { pathToFileURL, fileURLToPath } from 'node:url'
import { createRequire } from 'node:module'

const root = fileURLToPath(new URL('../../../', import.meta.url))
const frontend = join(root, '前端代码/cashier-v3')
const require = createRequire(join(frontend, 'package.json'))
const { parse, compileScript, compileTemplate } = require('@vue/compiler-sfc')
const { createSSRApp, h } = require('vue')
const { renderToString } = require('@vue/server-renderer')
const directory = mkdtempSync(join(tmpdir(), 'r98-presale-'))

try {
  const filename = join(frontend, 'src/components/cashier/PersonnelPerformanceOverlay.vue')
  const { descriptor } = parse(readFileSync(filename, 'utf8').replace('</script>', '\ndefineExpose({ confirm })\n</script>'), { filename })
  let code = compileScript(descriptor, { id: 'r98', inlineTemplate: true }).content
  code = code.replaceAll(/from ['"]vue['"]/g, `from ${JSON.stringify(pathToFileURL(require.resolve('vue')).href)}`)
  code = code.replaceAll("from '@/services/personnelDefaultAllocation'", `from ${JSON.stringify(pathToFileURL(join(frontend, 'src/services/personnelDefaultAllocation.js')).href)}`)
  const generated = join(directory, 'overlay.mjs')
  writeFileSync(generated, code)
  const Component = (await import(pathToFileURL(generated))).default
  for (const initialMode of ['simple', 'full']) {
    const snapshots = []
    for (const showPreSale of [true, false]) {
      let exposed, confirmed
      const Wrapped = { ...Component, setup(props, ctx) {
        return Component.setup(props, { ...ctx, expose(value) { exposed = value } })
      } }
      const salesperson = { id: 98, employeeId: 98, name: '售前验证员工', selected: true, marked: true, performance: 100 }
      const html = await renderToString(createSSRApp({ render: () => h(Wrapped, {
        initialMode, initialTab: 'salespeople', showCraftsmen: false, showSalespeople: true,
        showPreSale, selectedSalespeople: [salesperson], salespersonCandidates: [salesperson],
        performanceBaseAmountCents: 10000, onConfirm: value => { confirmed = value }
      }) }))
      assert.equal(html.includes('>是否售前</span>') || html.includes('>售前</label>'), showPreSale)
      if (initialMode === 'full') {
        assert.equal(html.includes('personnel-full-table--without-presale'), !showPreSale)
        assert.ok(html.includes('>业绩比例</span>'))
      }
      exposed.confirm()
      assert.ok(confirmed, '两态均可提交原分配')
      assert.equal(confirmed.salespeople[0].marked, true)
      assert.equal(confirmed.salespeople[0].isPreSale, true)
      snapshots.push(confirmed)
    }
    assert.deepEqual(snapshots[0], snapshots[1], '隐藏售前不得清空原售前快照')
  }
  for (const name of ['CashierWorkbenchView.vue', 'OrderCenterView.vue']) {
    const view = join(frontend, 'src/views', name)
    const { descriptor: d } = parse(readFileSync(view, 'utf8'), { filename: view })
    const script = compileScript(d, { id: name })
    assert.deepEqual(compileTemplate({ source: d.template.content, filename: view, id: name, compilerOptions: { bindingMetadata: script.bindings } }).errors, [])
  }
  console.log('R98 presale UI and snapshot parity: PASS')
} finally {
  rmSync(directory, { recursive: true, force: true })
}

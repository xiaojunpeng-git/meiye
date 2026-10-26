/** R37 验证真实人员弹窗两种模式的显隐与相同提交快照；不写业务事实。 */
import assert from 'node:assert/strict'
import { readFileSync, writeFileSync, mkdtempSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join, resolve } from 'node:path'
import { pathToFileURL, fileURLToPath } from 'node:url'
import { createRequire } from 'node:module'
import { serviceCraftsmenPerformanceText } from '../../../前端代码/cashier-v3/src/services/orderCenterPersonnelDisplay.js'
const root = fileURLToPath(new URL('../../../', import.meta.url))
const frontend = process.env.R37_FRONTEND_ROOT || join(root, '前端代码/cashier-v3')
const require = createRequire(join(frontend, 'package.json'))
const { parse, compileScript, compileTemplate } = require('@vue/compiler-sfc')
const { createSSRApp, h } = require('vue')
const { renderToString } = require('@vue/server-renderer')
const directory = mkdtempSync(join(tmpdir(), 'r37-sfc-'))
const results = []
try {
  const file = join(frontend, 'src/components/cashier/PersonnelPerformanceOverlay.vue')
  const { descriptor } = parse(readFileSync(file, 'utf8').replace('</script>', '\ndefineExpose({ confirm, applySelectionToAll })\n</script>'), { filename: file })
  let code = compileScript(descriptor, { id: 'r37', inlineTemplate: true }).content
  // 仅测试编译结果暴露原确认函数，实际源码不增加测试入口或另一套金额处理。
  code = code.replaceAll(/from ['"]vue['"]/g, `from ${JSON.stringify(pathToFileURL(require.resolve('vue')).href)}`)
  code = code.replaceAll("from '@/services/personnelDefaultAllocation'", `from ${JSON.stringify(pathToFileURL(join(frontend, 'src/services/personnelDefaultAllocation.js')).href)}`)
  const generated = join(directory, 'overlay.mjs');writeFileSync(generated, code)
  const Component = (await import(pathToFileURL(generated))).default
  for (const historyAdjustment of [false, true]) {
    const payloads = []
    for (const showLaborFee of [true, false]) {
      let exposed, confirmed, applied
      const Wrapped = { ...Component, setup(props, ctx) {
        return Component.setup(props, { ...ctx, expose(value) { exposed = value } })
      } }
      const person = { id: 37, employeeId: 37, name: '验证人员', selected: true, performance: 100,
        laborWeight: 100, craftsmanPerformanceType: 'commission_labor', laborFeeCents: 2500,
        performanceAmountCents: 35300, allocationAmountCents: 35300, projectCount: '1', projectCountText: '1' }
      const html = await renderToString(createSSRApp({ render: () => h(Wrapped, {
        initialMode: 'full', initialTab: 'craftsmen', showSalespeople: false, showLaborFee,
        historyAdjustment, selectedCraftsmen: [person], craftsmenCandidates: [person],
        performanceBaseAmountCents: 35300, projectCountTotal: 1,
        onConfirm: value => { confirmed = value }, 'onApply-all': value => { applied = value }
      }) }))
      assert.equal(html.includes('aria-label="每人手工费"'), showLaborFee)
      assert.equal(html.includes('>手工费</span>'), showLaborFee)
      assert.ok(html.includes('项目数'))
      exposed.confirm();assert.ok(confirmed, '原确认函数应生成分配快照')
      assert.equal(confirmed.craftsmen[0].laborFeeCents, 2500)
      exposed.applySelectionToAll();assert.equal(applied.craftsmen[0].laborFeeCents, 2500)
      payloads.push({ confirmed, applied })
      results.push({ historyAdjustment, showLaborFee, laborFeeCents: confirmed.craftsmen[0].laborFeeCents, result: 'PASS' })
    }
    assert.deepEqual(payloads[0], payloads[1], '显隐选项不得改变确认及应用全部人的业务快照')
  }
  const record = { craftsmenListAllocations: [{ name: '验证人员', amount: 353, laborFeeAmount: 25, projectCount: '1' }] }
  const before = JSON.stringify(record)
  assert.equal(serviceCraftsmenPerformanceText(record), '验证人员（轮、353、25、1）')
  assert.equal(serviceCraftsmenPerformanceText(record, false), '验证人员（轮、353、1）')
  assert.equal(JSON.stringify(record), before)
  for (const filename of ['src/views/OrderCenterView.vue', 'src/views/CashierWorkbenchView.vue']) {
    const source = readFileSync(join(frontend, filename), 'utf8')
    const { descriptor: d } = parse(source)
    const script = compileScript(d, { id: 'r37-compile' })
    const template = compileTemplate({ source: d.template.content, filename, id: 'r37-compile', compilerOptions: { bindingMetadata: script.bindings } })
    assert.deepEqual(template.errors, [])
  }
  writeFileSync(process.env.R37_EVIDENCE_OUTPUT || join(root, 'tests/cashier-v3/evidence/R37-labor-fee-display/verification.json'), JSON.stringify({ result: 'PASS', cases: results, payloadParity: 'PASS', summaryPurity: 'PASS', sfcCompilation: 'PASS' }, null, 2))
  console.log('R37 true SFC render, confirm/apply payload parity, personnel summary and view compilation: PASS')
} finally { rmSync(directory, { recursive: true, force: true }) }

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { runInNewContext } from 'node:vm'

const require = createRequire(new URL('../../../前端代码/cashier-v3/package.json', import.meta.url))
const Vue = require('vue')
const { parse } = require('@vue/compiler-sfc')
const { compile } = require('@vue/compiler-dom')
const { renderToString } = require('@vue/server-renderer')
const { descriptor } = parse(readFileSync(new URL('../../../前端代码/cashier-v3/src/components/cashier/CheckoutBusinessSourceOverlay.vue', import.meta.url), 'utf8'))
const script = descriptor.scriptSetup.content.replace("import { computed, ref, watch } from 'vue'", '')
const render = runInNewContext(`(function () { ${compile(descriptor.template.content, { mode: 'function', prefixIdentifiers: true }).code} })()`, { Vue })

// 执行实际组件脚本和模板，验证旧目录缓存、已选 K 和确认事件，而不是只搜文案。
async function selector(sources, primarySourceId) {
  const props = { sources, primarySourceId, secondarySourceId: 0, rewardAmountCents: 0, saving: false, loadError: '' }
  const events = []
  let state
  const component = { setup() {
    state = runInNewContext(`(function () { ${script}; return { roots, primaryId, secondaryId, selectedPrimary, children, requiresSecondary, isCrossIndustry, rewardAmount, validationMessage, choosePrimary, confirm, emit } })()`, {
      ...Vue, defineProps: () => props, defineEmits: () => (...event) => events.push(event)
    })
    return { ...props, ...state }
  }, render }
  const context = {}
  await renderToString(Vue.createSSRApp(component), context)
  return { state, events, html: context.teleports.body }
}
const sources = [{ id: 17, name: 'A老客首护' }, { id: 9, name: 'K退款顾客' }, { id: 18, name: 'B导购引流' }]
const old = await selector(sources, 9)
assert.doesNotMatch(old.html, />K退款顾客<\/button>/)
assert.match(old.html, /disabled[^>]*>确认<\/button>/)
assert.equal(old.state.primaryId.value, 0)
old.state.confirm()
assert.equal(old.events.length, 0)
assert.equal(old.state.validationMessage.value, '请选择一级来源。')
// 即使程序传入隐藏的 ID，confirm 仍只接受实际可选目录中的来源。
old.state.choosePrimary(9)
old.state.confirm()
assert.equal(old.events.length, 0)
const ordinary = await selector(sources, 17)
assert.match(ordinary.html, />A老客首护<\/button>/)
assert.match(ordinary.html, />B导购引流<\/button>/)
ordinary.state.confirm()
assert.equal(ordinary.events[0][0], 'confirm')
assert.equal(ordinary.events[0][1].primarySourceId, 17)
assert.equal(ordinary.events[0][1].secondarySourceId, 0)
assert.equal(ordinary.events[0][1].rewardAmountCents, 0)
const filtered = await selector(sources.filter(item => item.id !== 9), 9)
assert.equal(filtered.state.primaryId.value, 0)
const empty = await selector([sources[1]], 9)
assert.equal(empty.state.roots.value.length, 0)
assert.match(empty.html, /disabled[^>]*>确认<\/button>/)
console.log('checkout refund source real setup/template: PASS')

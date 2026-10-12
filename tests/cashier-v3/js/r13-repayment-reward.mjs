import assert from 'node:assert/strict'
import fs from 'node:fs'
import vm from 'node:vm'
import { createRequire } from 'node:module'

// 执行真实组件逻辑：奖励归属本次补交，不从原销售继承，不以模板字符串替代提交值校验。
const root = new URL('../../../', import.meta.url)
const require = createRequire(new URL('前端代码/cashier-v3/package.json', root))
const { computed, ref, watch, nextTick, reactive } = require('vue')
const sources = [{ id: 1, name: 'A老客首护' }, { id: 7, name: 'G异业收客', children: [] }]
const record = { id: 12, remainingDebtAmount: '1180.00', originalBusinessSource: { primarySourceId: 7, primarySourceNameSnapshot: 'G异业收客', secondarySourceId: 0, rewardAmountCents: 99999 } }
const props = reactive({ member: { id: 5 }, snapshot: { records: [record] }, initialDebtId: '', businessDate: '2026-10-12', isPreparing: false })
const emitted = []
function component(file, props, names, extra = {}) {
  const source = fs.readFileSync(new URL(`前端代码/cashier-v3/src/components/${file}`, root), 'utf8')
  const script = source.match(/<script setup>([\s\S]*?)<\/script>/)[1].replace(/^import .*\n/gm, '')
  const context = vm.createContext({ computed, ref, watch, defineProps: () => props, defineEmits: () => (...args) => emitted.push(args), ...extra })
  vm.runInContext(`${script}\nthis.test = { ${names} };`, context)
  return context.test
}
const d = component('member/MemberDebtOverlay.vue', props, 'selectRepayment,loadRepaymentSources,repaymentSourcePrimaryId,changeRepaymentSource,isRepaymentRewardOpen,confirmRepaymentReward,repaymentRewardAmountCents,selectedRepaymentSource,submitRepayment,isCrossIndustryRepayment', { loadCheckoutBusinessCatalog: async () => ({ sources }) })
d.selectRepayment(record)
await d.loadRepaymentSources()
await nextTick()
assert.equal(d.selectedRepaymentSource().rewardAmountCents, 0)
d.repaymentSourcePrimaryId.value = 1; d.changeRepaymentSource()
assert.equal(d.isRepaymentRewardOpen.value, false)
d.repaymentSourcePrimaryId.value = 7; d.changeRepaymentSource()
assert.equal(d.isRepaymentRewardOpen.value, true)
d.confirmRepaymentReward({ rewardAmountCents: 1234 })
assert.equal(d.isRepaymentRewardOpen.value, false)
assert.equal(d.selectedRepaymentSource().rewardAmountCents, 1234)
d.submitRepayment()
assert.equal(emitted.at(-1)[1].businessSource.rewardAmountCents, 1234)
assert.equal(emitted.at(-1)[1].amount, '1180')
// 正式壳层交接也必须完整保留分值，不得只把来源ID传往最终结账。
const shell = fs.readFileSync(new URL('前端代码/cashier-v3/src/layouts/CashierShell.vue', root), 'utf8')
const preparation = shell.slice(shell.indexOf('async function prepareDebtRepayment(payload = {})'), shell.indexOf('\nasync function loadMemberDetailTab'))
let handoff
const handoffContext = vm.createContext({
  isDebtRepaymentPreparing: ref(false), isMemberDebtOpen: ref(true), activeDebtMember: ref(props.member), initialDebtRecordId: ref(''),
  route: { name: 'not-cashier' }, window: { sessionStorage: { setItem: (key, value) => { handoff = JSON.parse(value) } }, location: {} }
})
vm.runInContext(preparation + '\nthis.prepare = prepareDebtRepayment;', handoffContext)
await handoffContext.prepare(emitted.at(-1)[1])
assert.equal(handoff.localRepayment.businessSource.rewardAmountCents, 1234)
d.repaymentSourcePrimaryId.value = 1; d.changeRepaymentSource()
assert.equal(d.selectedRepaymentSource().rewardAmountCents, 0)
d.selectRepayment(record); await nextTick()
assert.equal(d.repaymentRewardAmountCents.value, 0)
d.repaymentSourcePrimaryId.value = 0; d.changeRepaymentSource()
assert.equal(d.isCrossIndustryRepayment.value, false)

// 同一个奖励控件覆盖小数精度、负数/超界、零值及非G清空；取消仅由父层关闭，不发保存事件。
const p = reactive({ sources, primarySourceId: 7, secondarySourceId: 0, rewardAmountCents: 0 })
const modal = component('cashier/CheckoutBusinessSourceOverlay.vue', p, 'rewardAmount,confirm,validationMessage,choosePrimary')
for (const bad of ['-1', '1.234', 'abc', '1000000001']) {
  modal.rewardAmount.value = bad
  const n = emitted.length
  modal.confirm()
  assert.equal(emitted.length, n)
  assert.notEqual(modal.validationMessage.value, '')
}
for (const [value, cents] of [['0', 0], ['12.34', 1234], ['1000000000', 100000000000]]) {
  modal.rewardAmount.value = value; modal.confirm()
  assert.equal(emitted.at(-1)[1].rewardAmountCents, cents)
}
modal.choosePrimary(1); modal.confirm()
assert.equal(emitted.at(-1)[1].rewardAmountCents, 0)
console.log('PASS R13 repayment reward: popup, precise cents, submission, source/debt reset, zero and invalid amounts')

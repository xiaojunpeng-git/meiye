import assert from 'node:assert/strict'
import fs from 'node:fs'
import vm from 'node:vm'
import { createRequire } from 'node:module'

// Execute actual setup handlers with isolated transport, not duplicated rules.
const root = new URL('../../../', import.meta.url)
const require = createRequire(new URL('前端代码/cashier-v3/package.json', root))
const { ref, computed, watch, reactive, nextTick } = require('vue')
const { parse, compileTemplate, compileScript } = require('@vue/compiler-sfc')
const read = path => fs.readFileSync(new URL(path, root), 'utf8')
let checks = 0
const equal = (actual, expected, label) => { assert.equal(actual, expected, label); ++checks }
const debtPath = '前端代码/cashier-v3/src/components/member/MemberDebtOverlay.vue'
const props = reactive({ member: { id: 12 }, snapshot: { records: [{ id: 2, remainingDebtAmount: 100, originalBusinessSource: { primarySourceId: 99 } }] },
  businessDate: '2026-10-25', initialDebtId: '', isPreparing: false, businessSourcePolicy: { enabled: true, mode: 'manual' }, sourceDefaults: { repayment: {} } })
const debt = vm.createContext({ ref, computed, watch, defineProps: () => props, defineEmits: () => () => {},
  loadCheckoutBusinessCatalog: async () => ({ sources: [{ id: 3, name: 'current-source', children: [] }] }) })
vm.runInContext(read(debtPath).match(/<script setup>([\s\S]*?)<\/script>/)[1].replace(/^import .*$/gm, '')
  + '\nthis.t = { selectRepayment, selectedRepaymentSource, repaymentSourcePrimaryId, repaymentSourceSecondaryId, submitRepayment, validationMessage, businessSourceEnabled, loadRepaymentSources };', debt)
const t = debt.t
t.selectRepayment(props.snapshot.records[0]); await nextTick()
equal(t.repaymentSourcePrimaryId.value, 0, 'manual does not inherit original sale')
t.repaymentSourcePrimaryId.value = 3
await t.loadRepaymentSources()
equal(t.repaymentSourcePrimaryId.value, 3, 'catalog reload preserves draft')
equal(t.selectedRepaymentSource().primarySourceId, 3, 'current selection materialized')
props.sourceDefaults = { repayment: { primarySourceId: 3, secondarySourceId: 0, displayNameSnapshot: 'prior-repayment' } }
props.businessSourcePolicy.mode = 'previous'
t.selectRepayment(props.snapshot.records[0])
equal(t.repaymentSourcePrimaryId.value, 3, 'previous uses repayment kind, not original sale')
props.businessSourcePolicy.enabled = false
equal(t.businessSourceEnabled.value, false, 'fixed hides source controls')
equal(t.selectedRepaymentSource().displayNameSnapshot, 'prior-repayment', 'fixed source needs no catalog')
props.sourceDefaults = { repayment: { primarySourceId: 0, configurationError: 'invalid fixed config' } }
equal(t.selectedRepaymentSource().primarySourceId, 3, 'live default cannot replace active repayment')
t.selectRepayment(props.snapshot.records[0])
t.submitRepayment()
equal(t.validationMessage.value, 'invalid fixed config', 'fixed config failure blocks with cause')

const workbench = read('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue')
const sliceFunction = (name, end) => workbench.slice(workbench.indexOf(`function ${name}(`), workbench.indexOf(end, workbench.indexOf(`function ${name}(`)))
const initial = sliceFunction('initialBusinessSource', '// 实例开关')
const state = reactive({ businessSourcePolicy: { enabled: true }, businessSourceDefaultsMemberId: 12,
  businessSourceDefaults: { purchase: { primarySourceId: 4 }, recharge: { primarySourceId: 5 } } })
let prepared = null
const ctx = vm.createContext({ ref, computed, state, member: ref({ id: 12 }), clonePlain: value => JSON.parse(JSON.stringify(value)),
  rechargeSession: ref(null), rechargePreparationError: ref(''), isRechargeSubmitting: ref(false), currentMemberId: ref(12),
  rechargeAwaitingSource: ref(false), businessSourceEnabled: computed(() => state.businessSourcePolicy.enabled !== false),
  openCheckoutBusinessSourceSelector: () => {},
  localCheckoutBusinessSource: ref({ primarySourceId: 4 }),
  window: { dispatchEvent: () => {} }, CustomEvent: class {},
  acceptLocalRechargeCheckout: payload => { prepared = payload; return true } })
vm.runInContext(initial + '\n' + sliceFunction('openRecharge', 'function customCard')
  .slice(0, sliceFunction('openRecharge', 'function customCard').indexOf('\nfunction ', sliceFunction('openRecharge', 'function customCard').indexOf('async function submitRecharge') + 35)), ctx)
// Expose only the handlers under test, keeping all browser state local.
vm.runInContext('this.t = { initialBusinessSource, openRecharge, submitRecharge, rechargeSession, localCheckoutBusinessSource, member, rechargePreparationError };', ctx)
const w = ctx.t
equal(w.initialBusinessSource('purchase').primarySourceId, 4)
equal(w.initialBusinessSource('recharge').primarySourceId, 5)
w.openRecharge({ detail: { member: { id: 12 } } })
equal(w.rechargeSession.value.businessSourceSnapshot.primarySourceId, 5, 'purchase default cannot leak into recharge')
w.localCheckoutBusinessSource.value = { primarySourceId: 8, explicitChoice: true }
await w.submitRecharge({})
equal(prepared.businessSource.primarySourceId, 5, 'captured recharge source survives toolbar change')
state.businessSourceDefaultsMemberId = 11
equal(w.initialBusinessSource('purchase').primarySourceId, undefined, 'wrong member default rejected')
state.businessSourcePolicy.enabled = false
equal(w.initialBusinessSource('purchase').primarySourceId, 4, 'fixed choice is not bound to previous root member')
equal(Boolean(w.initialBusinessSource('purchase').configurationError), false, 'member mismatch does not block fixed checkout')
w.member.value = { id: 12, sourceDefaults: { recharge: { primarySourceId: 6 } } }
equal(w.initialBusinessSource('recharge').primarySourceId, 5, 'fixed instance config wins over stale member history')
state.businessSourcePolicy.enabled = true
equal(w.initialBusinessSource('recharge').primarySourceId, 6, 'enabled member-specific projection supported')
state.businessSourcePolicy.enabled = false
for (const memberId of [0, 12, 23]) {
  w.member.value = { id: memberId }
  equal(w.initialBusinessSource('purchase').primarySourceId, 4, 'guest/member switch retains fixed purchase config')
  equal(w.initialBusinessSource('recharge').primarySourceId, 5, 'guest/member switch retains fixed recharge config')
}
w.member.value = { id: 12 }
state.cashier = { serviceOrder: { memberId: 12, reservationId: 81, businessSource: { primarySourceId: 8, displayNameSnapshot: 'this-appointment' } } }
equal(w.initialBusinessSource('purchase').primarySourceId, 8, 'linked appointment keeps this-business source even in fixed mode')
state.cashier.serviceOrder.memberId = 13
equal(w.initialBusinessSource('purchase').primarySourceId, 4, 'another members appointment rejected, fixed config remains')
const fixedPurchase = state.businessSourceDefaults.purchase
state.businessSourceDefaults.purchase = { primarySourceId: 0, configurationError: 'invalid configured source' }
equal(w.initialBusinessSource('purchase').configurationError, 'invalid configured source', 'invalid fixed config still blocks')
state.businessSourceDefaults.purchase = undefined
equal(Boolean(w.initialBusinessSource('purchase').configurationError), true, 'missing fixed config still blocks')
state.businessSourceDefaults.purchase = fixedPurchase
// Run the real checkout entry's source gate with the same stale error seen in
// the browser. Stop before unrelated payment/staff work; no transaction occurs.
const entryStart = workbench.indexOf('async function openCheckout()')
const entryGateEnd = workbench.indexOf('  const craftsmenRequiredLine', entryStart)
ctx.isRefreshingLineCoupon = ref(false)
ctx.reportCheckoutEntryFailure = () => { throw new Error('fixed source unexpectedly blocked checkout') }
vm.runInContext(workbench.slice(entryStart, entryGateEnd) + '\nreturn localCheckoutBusinessSource.value; }\nthis.sourceGate = openCheckout;', ctx)
w.localCheckoutBusinessSource.value = { configurationError: 'old member-bound initialization error' }
equal((await ctx.sourceGate()).primarySourceId, 4, 'real checkout entry recovers stale error after member change')
const shell = read('前端代码/cashier-v3/src/layouts/CashierShell.vue')
const debtDefaultsExpression = shell.match(/:source-defaults="([^"]+)"/)[1]
const debtDefaults = new Function('state', 'activeDebtMember', 'businessSourceEnabled', `return (${debtDefaultsExpression})`)
equal(debtDefaults(state, { id: 23 }, false).recharge.primarySourceId, 5, 'debt fixed fallback is instance-scoped')
equal(Object.keys(debtDefaults(state, { id: 23 }, true)).length, 0, 'debt previous history remains member-scoped')
const shellContext = vm.createContext({ ref, state, businessSourceEnabled: computed(() => state.businessSourcePolicy.enabled !== false),
  rechargeSession: ref(null), rechargeSourceSelector: ref(null), loadCheckoutBusinessCatalog: async () => ({ sources: [{ id: 3, name: 'selected', children: [] }] }) })
vm.runInContext(shell.slice(shell.indexOf('async function beginRechargeSession('), shell.indexOf('async function submitDirectGift('))
  + '\nthis.t = { beginRechargeSession, confirmRechargeSource, rechargeSession, rechargeSourceSelector };', shellContext)
const s = shellContext.t
state.businessSourcePolicy.enabled = true
await s.beginRechargeSession({ member: { id: 12 } })
equal(s.rechargeSourceSelector.value.primarySourceId, undefined, 'noncashier entry rejects another member root default')
s.confirmRechargeSource({ primarySourceId: 3 })
equal(s.rechargeSession.value.businessSourceSnapshot.primarySourceId, 3, 'noncashier source confirmed before recharge')
equal(s.rechargeSourceSelector.value, null, 'source closes before recharge form opens')
await s.beginRechargeSession(s.rechargeSession.value)
equal(s.rechargeSession.value.businessSourceSnapshot.primarySourceId, 3, 'catalog retry retains the confirmed current choice')
state.businessSourcePolicy.enabled = false
await s.beginRechargeSession({ member: { id: 23 } })
equal(s.rechargeSession.value.businessSourceSnapshot.primarySourceId, 5, 'noncashier fixed config works for another member')
await s.beginRechargeSession({ member: { id: 12 }, sourceDefaults: { recharge: { primarySourceId: 7 } } })
equal(s.rechargeSourceSelector.value, null, 'noncashier fixed source has no selector')
equal(s.rechargeSession.value.businessSourceSnapshot.primarySourceId, 7, 'session-specific fixed source retained')
for (const relative of ['cashier-v3/src/views/CashierWorkbenchView.vue', 'cashier-v3/src/layouts/CashierShell.vue', 'cashier-v3/src/components/cashier/CashierCheckoutOverlay.vue', 'cashier-v3/src/components/member/MemberDebtOverlay.vue']) {
  const { descriptor, errors } = parse(read('前端代码/' + relative))
  assert.deepEqual(errors, []); ++checks
  assert.deepEqual(compileTemplate({ source: descriptor.template.content, filename: relative, id: 'r57-source' }).errors, []); ++checks
  compileScript(descriptor, { id: 'r57-source' }); ++checks
}
equal(workbench.includes('checkoutSourceProjection.primarySourceId'), false, 'stale checkout fallback removed')
console.log(`R57_CHECKOUT_SOURCE_FRONTEND=PASS (${checks} checks; setup execution/4 templates; transport isolated)`)

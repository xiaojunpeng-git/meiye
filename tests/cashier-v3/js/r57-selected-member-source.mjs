import assert from 'node:assert/strict'
import fs from 'node:fs'
import vm from 'node:vm'
import { createRequire } from 'node:module'

// Execute the real selection path, initializer and source overlay setup with
// delayed read-only transports. No API call, member mutation or payment occurs.
const root = new URL('../../../', import.meta.url)
const require = createRequire(new URL('前端代码/cashier-v3/package.json', root))
const { ref, computed, reactive, watch, nextTick } = require('vue')
const read = file => fs.readFileSync(new URL(file, root), 'utf8')
const shell = read('前端代码/cashier-v3/src/layouts/CashierShell.vue')
const workbench = read('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue')
let checks = 0
const equal = (a, b, message) => { assert.equal(a, b, message); checks++ }
const pending = new Map()
const completed = []
const state = reactive({ currentStore: { id: 23 }, cashier: { member: null, cart: {} },
  businessSourcePolicy: { enabled: true, mode: 'previous' }, businessSourceDefaultsMemberId: 0, businessSourceDefaults: {} })
const memberId = member => String(member?.memberId || member?.id || '')
const context = vm.createContext({ ref, computed, reactive, watch, nextTick, state,
  memberSelectorContext: ref('cashier'), isMemberSelectorOpen: ref(true), cashierCustomerSelectionEpoch: ref(0),
  cashierMember: computed(() => state.cashier.member), memberDetailId: memberId,
  toolbarBusinessSource: ref({}), window: { dispatchEvent() {} }, CustomEvent: class {},
  isSucceededResult: result => result.result.status === 'success', responseDataBlock: result => result.data,
  requestCashierV3Action: (_, payload) => new Promise(resolve => pending.set(String(payload.memberId), resolve)),
  localCashierCustomerSelectionDetail: record => ({ record }), memberDebtAmount: () => 0,
  completeMemberSelection: async detail => completed.push(detail.record) })
vm.runInContext(shell.slice(shell.indexOf('function memberDetailId('), shell.indexOf('async function openCashierForMember('))
  + '\n' + shell.slice(shell.indexOf('async function selectMemberFromSelector('), shell.indexOf('function localCashierCustomerSelectionDetail('))
  + '\nthis.select = selectMemberFromSelector;', context)
const response = id => ({ result: { status: 'success' }, data: { memberSummary: { id, memberId: id,
  sourceDefaults: { purchase: { primarySourceId: 41, secondarySourceId: 51, rewardAmountCents: 0 }, recharge: { primarySourceId: 42 } } } } })
const awaitRead = async id => {
  for (let i = 0; i < 10 && !pending.has(String(id)); i++) await Promise.resolve()
  assert.ok(pending.has(String(id)), 'actual selection must request the selected member summary')
}
const selection = context.select({ id: 101 })
await awaitRead(101)
state.cashier.member = { id: '101' }
pending.get('101')(response(101)); await selection
equal(state.cashier.member.sourceDefaults.purchase.primarySourceId, 41, 'selected member receives last purchase source')
equal(completed[0].sourceDefaults.purchase.secondarySourceId, 51, 'source dialog opens after summary enrichment')

// A late response may not restore another member, even after A -> B -> A.
const oldA = context.select({ id: 201 }); await awaitRead(201)
const newB = context.select({ id: 202 }); await awaitRead(202)
pending.get('202')(response(202)); await newB
pending.get('201')(response(201)); await oldA
equal(memberId(state.cashier.member), '202', 'late source summary cannot restore previous member')
equal(completed.length, 2, 'late summary cannot reopen previous source dialog')
const switchedStore = context.select({ id: 203 }); await awaitRead(203)
state.currentStore.id = 24
pending.get('203')(response(203)); await switchedStore
equal(completed.length, 2, 'old store source cannot open after store switch')
state.currentStore.id = 23

const member = ref(completed[0])
const sourceContext = vm.createContext({ state, member, ref, computed,
  clonePlain: value => JSON.parse(JSON.stringify(value)) })
vm.runInContext(workbench.slice(workbench.indexOf('function initialBusinessSource('), workbench.indexOf('// 实例开关'))
  + '\nthis.initial = initialBusinessSource;', sourceContext)
equal(sourceContext.initial('purchase').primarySourceId, 41, 'local selected member beats guest root history')
equal(sourceContext.initial('recharge').primarySourceId, 42, 'purchase/recharge suggestions stay separate')
member.value = { id: 204, sourceDefaults: { purchase: { primarySourceId: 0 } } }
equal(sourceContext.initial('purchase').primarySourceId, 0, 'manual/empty suggestion does not borrow previous member')

// The actual overlay must select the primary and child after its directory
// arrives, rather than clearing a valid incoming suggestion during loading.
const props = reactive({ sources: [], primarySourceId: 41, secondarySourceId: 51, rewardAmountCents: 0 })
const overlay = read('前端代码/cashier-v3/src/components/cashier/CheckoutBusinessSourceOverlay.vue')
const overlayContext = vm.createContext({ ref, computed, watch, defineProps: () => props, defineEmits: () => () => {} })
vm.runInContext(overlay.match(/<script setup>([\s\S]*?)<\/script>/)[1].replace(/^import .*$/gm, '')
  + '\nthis.t = { primaryId, secondaryId, selectedPrimary };', overlayContext)
props.sources = [{ id: 41, name: 'D线上引流', children: [{ id: 51, name: '渠道' }] }]
await nextTick()
equal(overlayContext.t.primaryId.value, 41, 'actual overlay selects suggested primary')
equal(overlayContext.t.secondaryId.value, 51, 'actual overlay selects suggested child')
equal(overlayContext.t.selectedPrimary.value.id, 41, 'actual overlay selected button has a directory entry')
console.log(`R57_SELECTED_MEMBER_SOURCE=PASS (${checks} checks; actual selection/overlay setup; isolated transport)`)

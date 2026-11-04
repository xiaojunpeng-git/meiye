import assert from 'node:assert/strict'
import fs from 'node:fs'
import vm from 'node:vm'
import { createRequire } from 'node:module'
const root = new URL('../../../', import.meta.url)
const require = createRequire(new URL('前端代码/cashier-v3/package.json', root))
const { ref, computed, watch, reactive, nextTick } = require('vue')
const { parse, compileTemplate } = require('@vue/compiler-sfc')
const { transformSync } = require('esbuild')
const source = fs.readFileSync(new URL('前端代码/cashier-v3/src/components/reservation/ReservationEditorOverlay.vue', root), 'utf8')
const props = reactive({ modelValue: { memberId: 1, businessSourcePolicy: { enabled: false } }, businessSources: [{ id: 2, name: 'manual' }], catalogOptions: [], craftsmenOptions: [], craftsmen: [], rooms: [], submission: {} })
const context = vm.createContext({ ref, computed, watch, nextTick, setTimeout, clearTimeout, onBeforeUnmount: () => {}, useModalFocusTrap: () => {}, defineProps: () => props, defineEmits: () => () => {} })
vm.runInContext(source.match(/<script setup>([\s\S]*?)<\/script>/)[1].replace(/^import .*$/gm, '') + '\nthis.t = { draft, sourceEnabled, summaryRows, collectMissingFields, chooseMember, chooseBusinessPrimary, submitDraft };', context)
const t = context.t
assert.equal(t.sourceEnabled.value, false)
assert.equal(t.collectMissingFields().includes('客户来源'), false)
assert.equal(t.summaryRows.value.some(row => row.label === '客户来源'), false)
props.modelValue = { memberId: 1, businessSourcePolicy: { enabled: true, mode: 'manual' } }
await nextTick()
assert.equal(t.collectMissingFields().includes('客户来源'), true)
t.chooseBusinessPrimary(2)
assert.equal(t.submitDraft().businessSourcePrimaryId, 2)
// Drive the real selection handler with delayed transport, including A -> B -> A races.
const pending = []
props.onRefreshCatalog = () => new Promise(resolve => pending.push(resolve))
let member = { id: 2 }
props.onSelectMember = async () => member
await t.chooseMember()
assert.equal(t.draft.value.businessSourcePrimaryId, 0)
member = { id: 3 }; await t.chooseMember()
pending[0]({ catalogOptions: [], initialBusinessSource: { primarySourceId: 99 } })
await nextTick(); await Promise.resolve()
assert.equal(t.draft.value.businessSourcePrimaryId, 0)
pending[1]({ catalogOptions: [], initialBusinessSource: { primarySourceId: 8 }, businessSourcePolicy: { enabled: true, mode: 'previous' } })
await nextTick(); await Promise.resolve()
assert.equal(t.draft.value.businessSourcePrimaryId, 8)
member = { id: 4 }; await t.chooseMember(); t.chooseBusinessPrimary(2)
pending[2]({ catalogOptions: [], initialBusinessSource: { primarySourceId: 99 } })
await nextTick(); await Promise.resolve()
assert.equal(t.draft.value.businessSourcePrimaryId, 2, 'late initial suggestion cannot override explicit choice')
// Compile actual templates to ensure the conditional source group is balanced.
for (const relative of ['cashier-v3/src/components/reservation/ReservationEditorOverlay.vue', 'cashier-v3/src/components/reservation/ReservationDetailOverlay.vue', 'mobile-vue3/src/merchant/pages/reservations/index.uvue', 'mobile-vue3/src/merchant/pages/reservation-confirmations/index.uvue']) {
  const text = fs.readFileSync(new URL('前端代码/' + relative, root), 'utf8')
  const { descriptor, errors } = parse(text)
  assert.deepEqual(errors, [])
  assert.deepEqual(compileTemplate({ source: descriptor.template.content, filename: relative, id: 'r57' }).errors, [])
  if (relative.endsWith('.uvue')) transformSync(descriptor.scriptSetup.content, { loader: 'ts' })
}
// Execute both actual UTS setup bodies after syntax lowering; transport alone is stubbed.
for (const page of ['reservations', 'reservation-confirmations']) {
  const text = fs.readFileSync(new URL(`前端代码/mobile-vue3/src/merchant/pages/${page}/index.uvue`, root), 'utf8')
  const script = text.match(/<script setup lang="uts">([\s\S]*?)<\/script>/)[1].replace(/^\s*import .*$/gm, '')
  const requests = []
  const mobile = vm.createContext({ ref, computed, onShow: () => {}, onHide: () => {}, onUnmounted: () => {}, currentMobilePlatform: () => 'H5',
    createMobileReservation: (payload, done) => requests.push({ payload, done }),
    confirmMobileReservation: (id, payload, done) => requests.push({ payload, done }),
    mobileHttpResultRequiresMerchantLogin: () => false })
  const submitName = page === 'reservations' ? 'submitReservation' : 'submitConfirmation'
  vm.runInContext(transformSync(script, { loader: 'ts' }).code + `\nthis.t = { businessSourceEnabled, selectedBusinessPrimaryId, selectedBusinessSecondaryId, selectedMember, appointmentDate, appointmentStartClock, appointmentEndClock, submitting, submit: ${submitName}, reservationDraft };`, mobile)
  const m = mobile.t
  m.selectedMember.value = { id: 5 }; m.appointmentDate.value = '2026-10-25'; m.appointmentStartClock.value = '10:00'; m.appointmentEndClock.value = '11:00'
  m.submit()
  assert.equal(requests.length, 0, page + ': enabled requires source')
  m.businessSourceEnabled.value = false
  m.submit()
  assert.equal(requests.length, 1, page + ': fixed mode skips client required-source validation')
  requests[0].done({ ok: false, message: 'retry' })
  assert.equal(m.selectedMember.value.id, 5, page + ': failure retains draft')
  m.businessSourceEnabled.value = true; m.selectedBusinessPrimaryId.value = 2
  m.submit()
  assert.equal(requests.length, 2)
  assert.equal(requests[1].payload.reservation.businessSourcePrimaryId, 2)
}
console.log('R57_RESERVATION_SOURCE_FRONTEND=PASS: disabled display/validation, manual choice, previous suggestion, stale/member/explicit-choice guards, four templates and UTS syntax')

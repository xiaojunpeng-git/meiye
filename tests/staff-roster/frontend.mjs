import assert from 'node:assert/strict'
import fs from 'node:fs'
import vm from 'node:vm'
import { createRequire } from 'node:module'
const root = new URL('../../', import.meta.url)
const require = createRequire(new URL('前端代码/cashier-v3/package.json', root))
const { computed, ref, reactive, watch, nextTick } = require('vue')
const source = fs.readFileSync(new URL('前端代码/cashier-v3/src/views/StaffRosterView.vue', root), 'utf8').match(/<script setup>([\s\S]*?)<\/script>/)[1].replace(/^import .*$/gm, '')
const calls = [], guideCalls = [], reads = []
let fail = false, token = 0
const fixture = { staff: [1, 2].map(id => ({ id, staff_name: 'Test ' + id, days: Object.fromEntries(['2030-06-01','2030-06-02'].map(date => [date, { rows: [], fingerprint: 'original-' + id + date }])) })), shifts: [], dates: ['2030-06-01','2030-06-02'], total: 2, page: 1 }
const context = vm.createContext({ computed, ref, reactive, watch, onMounted: () => {}, useRoute: () => ({ meta: {} }), useCashierV3State: () => reactive({}),
  useFieldGuidance: () => ({ guidance: reactive({}), clear: () => {}, guide: (...args) => guideCalls.push(args) }),
  createRequestToken: () => 'local-test-request-' + (++token),
  createStaffRosterApi: () => async (path, params, body) => { if (!body) { reads.push(JSON.parse(JSON.stringify(params))); return fixture; } calls.push(JSON.parse(JSON.stringify({ path, params, body }))); if (fail) throw new Error('offline'); return { message: '已保存' } }
})
vm.runInContext(source + '\nthis.t = { data, form, selectedStaff, selectedDates, dialog, pending, keyword, openSchedule, save, load };', context)
const t = context.t; t.data.value = fixture
// 批量草稿必须保存逐员工、逐日期原指纹，避免把未加载的日期或人员写入请求。
t.openSchedule([1,2], ['2030-06-01','2030-06-02'])
t.form.kind = 'REST'; t.form.partial = false; fail = true
await t.save()
assert.equal(calls.length, 1); assert.equal(calls[0].body.staff_ids.length, 2)
assert.equal(Object.keys(calls[0].body.expected[2]).length, 2)
assert.ok(t.pending.value)
const first = calls[0]
fail = false
await t.save()
assert.deepEqual(calls[1], first, '网络失败必须重试同一令牌和内容')
assert.equal(token, 1); assert.equal(t.pending.value, null)
assert.equal(t.dialog.value, '')
const submittedKeyword = reads.at(-1).keyword
t.keyword.value = '尚未查询的草稿'
await t.load(2)
assert.equal(reads.at(-1).keyword, submittedKeyword, '翻页必须保留已提交条件')
// 首缺项就地引导，不产生保存请求。
t.openSchedule([], ['2030-06-01'])
assert.equal(guideCalls.at(-1)[0], 'roster-staff')
t.openSchedule([1], [])
assert.equal(guideCalls.at(-1)[0], 'roster-date')
console.log('ROSTER_FRONTEND_PASS: bulk scope, original fingerprints, uncertain retry identity, success close, first required field')

// 驱动真正预约编辑器：可用性响应不能把手工结束时间改回建议时间。
const editorSource = fs.readFileSync(new URL('前端代码/cashier-v3/src/components/reservation/ReservationEditorOverlay.vue', root), 'utf8').match(/<script setup>([\s\S]*?)<\/script>/)[1].replace(/^import .*$/gm, '')
const timers = new Map(), responses = []; let serial = 0
const props = reactive({ modelValue: { appointmentTime: '2030-06-01T10:00', appointmentEndAt: '2030-06-01T11:00' }, catalogOptions: [], businessSources: [], craftsmenOptions: [], craftsmen: [], rooms: [], submission: {},
  onRecalculate: () => new Promise(resolve => responses.push(resolve)) })
const editor = vm.createContext({ computed, ref, reactive, watch, nextTick, onBeforeUnmount: () => {}, useModalFocusTrap: () => {}, defineProps: () => props, defineEmits: () => () => {},
  setTimeout: fn => { timers.set(++serial, fn); return serial }, clearTimeout: id => timers.delete(id) })
vm.runInContext(editorSource + '\nthis.t = { draft, updateAppointmentEnd, submitDraft, availabilityMessage };', editor)
const fire = () => { const pending = [...timers.values()]; timers.clear(); return pending.map(fn => fn()) }
fire(); editor.t.updateAppointmentEnd('12:00'); await nextTick(); fire()
responses[1]({ success: true, draft: { appointmentEndAt: '2030-06-01T11:00' }, conflicts: [] }); await Promise.resolve()
responses[0]({ success: true, conflicts: [{}] }); await Promise.resolve()
assert.equal(editor.t.submitDraft().appointmentEndAt, '2030-06-01T12:00')
assert.equal(editor.t.availabilityMessage.value, '', '旧响应不能污染较新时段')
console.log('RESERVATION_AVAILABILITY_PASS: manual end preserved, old response discarded')

// 实际父页使用只读汇总配置；重算必须写入可写的准备结果，防止后端可约而界面仍禁用。
const listSource=fs.readFileSync(new URL('前端代码/cashier-v3/src/views/ReservationListView.vue',root),'utf8')
const recalc=listSource.slice(listSource.indexOf('async function recalculateReservationPlan('),listSource.indexOf('async function refreshReservationProjectCatalog('))
const prepared=ref({craftsmenOptions:[{id:1200,selectable:false}]})
const config=computed(()=>prepared.value)
const session=ref({stateContextId:'test',preparationRequestId:'prep',reservationId:null})
const result={result:{status:'success'},data:{reservationPlan:{recalculationRequestId:'r1',draft:{scheduleCalculationReady:true},craftsmenOptions:[{id:1200,selectable:true}],conflicts:[]}}}
const parent=vm.createContext({preparedEditorConfig:prepared,editorConfig:config,editorSession:session,currentStateContextId:()=> 'test',requestAction:async()=>result,actionStatus:r=>r.result.status,actionMessage:()=>'',normalizeEditorDraft:v=>v,reservation:ref({})})
vm.runInContext('let staffAvailabilityCalculationSerial=0;\n'+recalc+'\nthis.run=recalculateReservationPlan;',parent)
assert.equal((await parent.run({draft:{},recalculationRequestId:'r1'})).success,true)
assert.equal(config.value.craftsmenOptions[0].selectable,true)
console.log('RESERVATION_PARENT_REFRESH_PASS: writable prepared configuration refreshes actual readonly projection')

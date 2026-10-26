import assert from 'node:assert/strict'
import fs from 'node:fs'
import vm from 'node:vm'
import { createRequire } from 'node:module'
import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'

// 用后端真实时钟方法生成的草稿驱动真实 Vue setup，验证展示、联动与提交同一值。
const root = new URL('../../../', import.meta.url)
const require = createRequire(new URL('前端代码/cashier-v3/package.json', root))
const { computed, ref, watch, reactive, nextTick } = require('vue')
const source = fs.readFileSync(new URL('前端代码/cashier-v3/src/components/reservation/ReservationEditorOverlay.vue', root), 'utf8')
const script = source.match(/<script setup>([\s\S]*?)<\/script>/)[1].replace(/^import .*$/gm, '')
const fixtures = JSON.parse(execFileSync('php', [fileURLToPath(new URL('../php/r11-reservation-default-schedule.php', import.meta.url)), '--fixtures'], { encoding: 'utf8' }))
const props = reactive({
  modelValue: {}, catalogOptions: [], businessSources: [], craftsmenOptions: [],
  craftsmen: [], rooms: [], submission: {}, isSubmitting: false
})
const emitted = []
const context = vm.createContext({ computed, ref, watch, nextTick, useModalFocusTrap: () => {},
  defineProps: () => props, defineEmits: () => (...args) => emitted.push(args) })
vm.runInContext(script + '\nthis.test = { draft, appointmentDateValue, appointmentStartTimeValue, appointmentEndTimeValue, submitDraft, updateAppointmentDate, updateAppointmentStartTime, updateAppointmentEnd, updateRemark, withCalculatedEnd, applyScheduleChange, collectMissingFields };', context)
const t = context.test
for (const fixture of fixtures) {
  props.modelValue = fixture
  await nextTick()
  assert.equal(t.appointmentDateValue.value, fixture.appointmentDate)
  assert.equal(t.appointmentStartTimeValue.value, fixture.appointmentStartAt.slice(11))
  assert.equal(t.appointmentEndTimeValue.value, fixture.appointmentEndAt.slice(11))
  const payload = t.submitDraft()
  assert.equal(payload.appointmentTime, fixture.appointmentStartAt)
  assert.equal(payload.appointmentEndAt, fixture.appointmentEndAt)
  if (!fixture.appointmentStartAt) assert.ok(t.collectMissingFields().includes('预约日期和开始时间'))
  if (!fixture.appointmentEndAt) assert.ok(t.collectMissingFields().includes('结束时间（须晚于开始时间）'))
}

props.modelValue = fixtures[6] // 23 点后只有今天：可手工填写，不会被自动改到明天。
await nextTick()
t.updateAppointmentStartTime('23:15')
assert.equal(t.submitDraft().appointmentTime, '2026-10-11T23:15')
assert.equal(t.appointmentEndTimeValue.value, '')
t.updateAppointmentEnd('23:45')
assert.equal(t.submitDraft().appointmentEndAt, '2026-10-11T23:45')
t.updateRemark('manual range')
assert.equal(t.appointmentStartTimeValue.value, '23:15')
assert.equal(t.appointmentEndTimeValue.value, '23:45')
props.modelValue = t.submitDraft() // 父组件回传不能重置手填时间。
await nextTick()
assert.equal(t.appointmentEndTimeValue.value, '23:45')
t.updateAppointmentDate('')
assert.equal(t.appointmentDateValue.value, '')
assert.equal(t.appointmentStartTimeValue.value, '')
t.updateAppointmentDate('2026-11-01')
t.updateAppointmentStartTime('10:15')
assert.equal(t.appointmentEndTimeValue.value, '11:15')
t.applyScheduleChange({ ...t.draft.value, projects: [{ projectId: 1, appliedDurationMinutes: 90, quantity: 1 }] })
assert.equal(t.appointmentEndTimeValue.value, '11:45')
t.updateAppointmentEnd('12:00')
assert.equal(t.submitDraft().appointmentEndAt, '2026-11-01T12:00')
assert.equal(emitted.at(-1)[0], 'update:modelValue')
assert.equal(emitted.at(-1)[1].appointmentEndAt, '2026-11-01T12:00')

// 编辑已有预约用原值（包括手工调整的结束时间），不应用新增默认或项目重算。
props.modelValue = { id: 7, appointmentTime: '2026-11-03 09:15', appointmentEndAt: '2026-11-03 10:45', projects: [] }
await nextTick()
assert.equal(t.appointmentDateValue.value, '2026-11-03')
assert.equal(t.appointmentStartTimeValue.value, '09:15')
assert.equal(t.appointmentEndTimeValue.value, '10:45')
t.updateRemark('edit unchanged')
assert.equal(t.submitDraft().appointmentTime, '2026-11-03T09:15')
assert.equal(t.submitDraft().appointmentEndAt, '2026-11-03T10:45')
console.log('R11_RESERVATION_DEFAULT_FRONTEND=PASS: real backend fixtures, display/payload, midnight, clear, manual edit, duration linkage')

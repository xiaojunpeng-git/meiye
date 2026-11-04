<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import StaffRosterDialog from '@/components/StaffRosterDialog.vue'
import TablePagination from '@/components/common/TablePagination.vue'
import RequiredFieldGuidance from '@/components/common/RequiredFieldGuidance.vue'
import OrganizationStoreScopePicker from '@/components/OrganizationStoreScopePicker.vue'
import { useFieldGuidance } from '@/composables/useFieldGuidance'
import { createStaffRosterApi, rosterCsv } from '@/services/staffRosterApi'
import { createRequestToken } from '@/services/staffManagementApi'
import { useCashierV3State } from '@/services/cashierV3Bridge'

const route = useRoute(), state = useCashierV3State()
const platform = route.meta.platformReport === true
const api = createStaffRosterApi(platform), root = ref(null)
const { guidance, clear: clearGuide, guide } = useFieldGuidance(root)
const today = new Date(), month = ref(`${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}`)
const keyword = ref(''), tab = ref('schedule'), data = ref({ staff: [], shifts: [], dates: [], total: 0, page: 1 })
const lastQuery = ref(null)
const loading = ref(false), saving = ref(false), error = ref(''), message = ref(''), conflicts = ref([])
const tree = ref([]), allowedStores = ref([]), selectedStores = ref([]), scopeLabel = ref('选择门店')
const selectedStaff = ref([]), selectedDates = ref([]), dialog = ref(''), form = reactive({})
const readOnly = computed(() => !platform && !!state.readOnly)
// 全选只使用当前已加载月份的日期；不依赖横向滚动范围，也不改变员工选择。
const allDatesSelected = computed(() => data.value.dates.length > 0 && data.value.dates.every(date => selectedDates.value.includes(date)))
const someDatesSelected = computed(() => !allDatesSelected.value && data.value.dates.some(date => selectedDates.value.includes(date)))
function selectAllDates(checked) { selectedDates.value = checked ? [...data.value.dates] : [] }
// 网络重试必须使用第一次业务内容与令牌；没有明确失败时禁止换内容生成第二次写入。
const pending = ref(null)
let sequence = 0
const query = () => ({ month: month.value, keyword: keyword.value.trim(), ...(platform ? { store_id: selectedStores.value[0] } : {}) })
async function load(page = 1) {
  const current = ++sequence
  loading.value = true; error.value = ''; selectedStaff.value = []; selectedDates.value = []
  try {
    if (platform && selectedStores.value.length !== 1) { data.value = { staff: [], shifts: [], dates: [], total: 0, page: 1 }; return }
    const submitted = { ...(page > 1 && lastQuery.value ? lastQuery.value : query()), page }
    const result = await api('', submitted)
    if (current === sequence) { data.value = result; lastQuery.value = submitted }
  } catch (e) { if (current === sequence) { lastQuery.value = null; error.value = e.message; data.value = { staff: [], shifts: [], dates: [], total: 0, page: 1 } } }
  finally { if (current === sequence) loading.value = false }
}
function changeScope({ storeIds, label }) {
  if (pending.value) return
  selectedStores.value = [...storeIds]; scopeLabel.value = label; dialog.value = ''
  // 切换范围即清掉旧门店结果，不能把上一门店人员短暂展示在新组织下。
  lastQuery.value = null; data.value = { staff: [], shifts: [], dates: [], total: 0, page: 1 }; void load()
}
function toggle(list, value) { return list.includes(value) ? list.filter(item => item !== value) : [...list, value] }
function label(row) {
  if (Number(row.schedule_type) === 0) return row.start_time ? `休 ${row.start_time.slice(0, 5)}–${row.end_time.slice(0, 5)}` : '休息'
  const shift = data.value.shifts.find(item => Number(item.id) === Number(row.shift_id))
  return `${row.display_name || row.shift_name_snapshot || shift?.name || '上班'} ${String(row.display_start || row.start_time || shift?.start_time || '').slice(0, 5)}–${String(row.display_end || row.end_time || shift?.end_time || '').slice(0, 5)}`
}
function openSchedule(staffIds = selectedStaff.value, dates = selectedDates.value) {
  clearGuide(); conflicts.value = []; error.value = ''
  if (!staffIds.length) return guide('roster-staff', '请在这里勾选员工')
  if (!dates.length) return guide('roster-date', '请在这里勾选日期')
  const expected = {}
  staffIds.forEach(id => { expected[id] = {}; dates.forEach(date => { expected[id][date] = data.value.staff.find(s => Number(s.id) === Number(id)).days[date].fingerprint }) })
  Object.assign(form, { staff_ids: [...staffIds].map(Number), dates: [...dates], kind: 'WORK', shift_id: '', partial: false, start_time: '12:00', end_time: '13:00', expected })
  // 单格编辑回填已保存安排；批量操作保持明确的统一选择，不猜测混合值。
  if (staffIds.length === 1 && dates.length === 1) {
    const rows = data.value.staff.find(s => Number(s.id) === Number(staffIds[0])).days[dates[0]].rows
    const rest = rows.find(row => Number(row.schedule_type) === 0), work = rows.find(row => Number(row.schedule_type) === 1)
    if (rest) Object.assign(form, { kind: 'REST', partial: !!rest.start_time, start_time: rest.start_time || '12:00', end_time: rest.end_time || '13:00' })
    else if (work) form.shift_id = Number(work.shift_id) || ''
  }
  dialog.value = 'schedule'
}
function openShift(shift = null) {
  clearGuide(); error.value = ''; conflicts.value = []
  Object.assign(form, { id: Number(shift?.id) || 0, name: shift?.name || '', start_time: String(shift?.start_time || '09:00').slice(0, 5), end_time: String(shift?.end_time || '18:00').slice(0, 5), expected: shift?.fingerprint || '' })
  dialog.value = 'shift'
}
async function execute(path, body) {
  if (saving.value) return
  const request = pending.value || { path, body: { ...body, request_token: createRequestToken() }, params: query() }
  pending.value = request; saving.value = true; error.value = ''; conflicts.value = []; message.value = ''
  try {
    const result = await api(request.path, request.params, request.body)
    pending.value = null; dialog.value = ''; message.value = result.message; await load(data.value.page)
  } catch (e) {
    error.value = e.message; conflicts.value = e.conflicts || []
    if (e.definitive) pending.value = null
  } finally { saving.value = false }
}
function save() {
  clearGuide()
  if (pending.value) return execute(pending.value.path, pending.value.body)
  if (dialog.value === 'shift') {
    if (!form.name.trim()) return guide('roster-shift-name', '请在这里填写班次名称')
    if (!form.start_time) return guide('roster-start', '请在这里选择上班时间')
    if (!form.end_time || form.end_time <= form.start_time) return guide('roster-end', '请选择晚于上班的时间')
    return execute('/shift', { id: form.id, name: form.name, start_time: form.start_time, end_time: form.end_time, expected: form.expected })
  }
  if (form.kind === 'WORK' && !form.shift_id) return guide('roster-shift', '请在这里选择班次')
  if (form.kind === 'REST' && form.partial && (!form.end_time || form.end_time <= form.start_time)) return guide('roster-rest-end', '请选择晚于开始的时间')
  return execute('', { ...form })
}
async function exportList(range) {
  try {
    // 导出沿用已经查询成功的条件；输入框尚未提交的搜索草稿不能悄悄改变导出范围。
    if (!lastQuery.value || loading.value || (platform && selectedStores.value.length !== 1)) { error.value = '请先完成查询后再导出。'; return }
    const result = await api('/export', { ...lastQuery.value, page: data.value.page, range })
    const url = URL.createObjectURL(new Blob([rosterCsv(result)], { type: 'text/csv;charset=utf-8' }))
    const a = document.createElement('a'); a.href = url; a.download = `员工排班-${month.value}.csv`; a.click(); URL.revokeObjectURL(url)
  } catch (e) { error.value = e.message }
}
watch(() => state.stateContextId, () => { if (!platform) { dialog.value = ''; pending.value = null; void load() } })
onMounted(async () => {
  if (platform) {
    try { const scope = await api('/scope'); tree.value = scope.tree; allowedStores.value = scope.allowed_store_ids; if (allowedStores.value.length === 1) selectedStores.value = [...allowedStores.value] }
    catch (e) { error.value = e.message; return }
  }
  await load()
})
</script>

<template>
  <section ref="root" class="roster" @input="clearGuide" @change="clearGuide" @pointerdown="clearGuide">
    <header class="roster-head"><h1>员工排班</h1><nav aria-label="排班页面"><button :class="{ active: tab === 'schedule' }" @click="tab = 'schedule'">排班管理</button><button :class="{ active: tab === 'shifts' }" @click="tab = 'shifts'">班次管理</button></nav></header>
    <div class="roster-toolbar" :inert="pending ? true : undefined">
      <OrganizationStoreScopePicker v-if="platform" v-model="selectedStores" :tree="tree" :allowed-store-ids="allowedStores" :label="scopeLabel" @change="changeScope" />
      <template v-if="tab === 'schedule'"><label>月份 <input v-model="month" type="month" @change="load()"></label><input v-model="keyword" aria-label="员工姓名或手机" placeholder="员工姓名 / 手机" @keyup.enter="load()"><button :disabled="loading" @click="load()">查询</button><label class="roster-select-dates"><input type="checkbox" aria-label="日期全选" :checked="allDatesSelected" :indeterminate="someDatesSelected" :disabled="loading || !data.dates.length || !!pending" @change="selectAllDates($event.target.checked)">日期全选</label></template>
      <div class="roster-toolbar-actions"><template v-if="tab === 'schedule'"><button :disabled="readOnly || loading || !data.staff.length" @click="openSchedule()">批量设置</button><details><summary>导出</summary><button @click="exportList('page')">当前页</button><button @click="exportList('all')">全部查询结果</button></details></template><button v-else :disabled="readOnly || (platform && selectedStores.length !== 1)" @click="openShift()">新增班次</button></div>
    </div>
    <p v-if="platform && selectedStores.length !== 1" class="roster-note">请选择一个门店查看和安排员工排班。</p>
    <p v-else class="roster-note">{{ data.enabled ? '预约日历只显示当天上班人员；未排班或全天休息不可预约，部分休息时段不可预约。' : '排班未启用，预约沿用原规则；可先安排上班和休息，再到系统设置开启。' }}</p>
    <p v-if="message" role="status" class="roster-success">{{ message }}</p>
    <p v-if="error && !dialog" role="alert" class="roster-error">{{ error }}</p>
    <div v-if="tab === 'schedule'" class="roster-results" :aria-busy="loading">
      <div class="roster-scroll">
        <table class="roster-calendar"><thead><tr><th class="roster-person"><label><input data-field="roster-staff" type="checkbox" :checked="!!data.staff.length && selectedStaff.length === data.staff.length" :disabled="loading" @change="selectedStaff = $event.target.checked ? data.staff.map(s => Number(s.id)) : []"> 员工</label><RequiredFieldGuidance v-if="guidance.target === 'roster-staff'" :message="guidance.message" /></th><th v-for="(date, index) in data.dates" :key="date" :class="{ weekend: [0,6].includes(new Date(date + 'T12:00:00').getDay()) }"><label><input :data-field="index === 0 ? 'roster-date' : undefined" type="checkbox" :checked="selectedDates.includes(date)" @change="selectedDates = toggle(selectedDates, date)">{{ date.slice(-2) }}</label><small>{{ ['日','一','二','三','四','五','六'][new Date(date + 'T12:00:00').getDay()] }}</small><RequiredFieldGuidance v-if="index === 0 && guidance.target === 'roster-date'" :message="guidance.message" /></th></tr></thead>
          <tbody><tr v-for="person in data.staff" :key="person.id"><th class="roster-person"><label><input type="checkbox" :checked="selectedStaff.includes(Number(person.id))" @change="selectedStaff = toggle(selectedStaff, Number(person.id))"> {{ person.staff_name }}</label><small>{{ person.phone }}</small></th><td v-for="date in data.dates" :key="date"><button :disabled="readOnly || loading || !!pending" :aria-label="`${person.staff_name} ${date} 排班`" @click="openSchedule([person.id], [date])"><span v-if="!person.days[date].rows.length" class="roster-unset">—</span><span v-for="row in person.days[date].rows" :key="row.id" :class="Number(row.schedule_type) === 0 ? 'rest' : 'work'">{{ label(row) }}</span></button></td></tr><tr v-if="!data.staff.length"><td :colspan="data.dates.length + 1" class="roster-empty">{{ loading ? '正在加载排班…' : '暂无员工' }}</td></tr></tbody></table>
      </div>
      <TablePagination :total="data.total" :page="data.page" :page-size="20" :page-size-options="[20]" show-page-count @change="load($event.page)" />
    </div>
    <div v-else class="roster-results roster-scroll"><table class="roster-shifts"><thead><tr><th>班次名称</th><th>上班时间</th><th>下班时间</th><th>操作</th></tr></thead><tbody><tr v-for="shift in data.shifts" :key="shift.id"><td>{{ shift.name }}</td><td>{{ shift.start_time }}</td><td>{{ shift.end_time }}</td><td><template v-if="shift.editable"><button :disabled="readOnly || !!pending" @click="openShift(shift)">编辑</button><button :disabled="readOnly || !!pending" @click="execute('/shift/delete', { id: shift.id, expected: shift.fingerprint })">删除</button></template><span v-else>共用班次</span></td></tr><tr v-if="!data.shifts.length"><td colspan="4" class="roster-empty">暂无班次，请先新增班次。</td></tr></tbody></table><p class="roster-note">修改或删除班次后，已经安排的上下班时间保持不变。</p></div>
    <StaffRosterDialog v-if="dialog" :can-close="!pending && !saving" :label="dialog === 'shift' ? '班次设置' : '排班设置'" @close="dialog = ''">
      <header><h2>{{ dialog === 'shift' ? (form.id ? '编辑班次' : '新增班次') : '设置排班' }}</h2><button aria-label="关闭" :disabled="saving || !!pending" @click="dialog = ''">×</button></header>
      <div class="roster-dialog-body">
        <template v-if="dialog === 'schedule'"><p>{{ form.staff_ids.length }} 名员工 · {{ form.dates.length }} 天 <small v-if="form.dates.length === 1">{{ form.dates[0] }}</small></p><fieldset :disabled="saving || !!pending"><legend>安排</legend><label v-for="option in [['WORK','上班'],['REST','休息'],['CLEAR','清除排班']]" :key="option[0]"><input v-model="form.kind" type="radio" name="roster-kind" :value="option[0]">{{ option[1] }}</label></fieldset>
          <label v-if="form.kind === 'WORK'" class="roster-field">班次<select v-model="form.shift_id" data-field="roster-shift" :disabled="saving || !!pending"><option value="">选择班次</option><option v-for="shift in data.shifts" :key="shift.id" :value="shift.id">{{ shift.name }} {{ shift.start_time }}–{{ shift.end_time }}</option></select><RequiredFieldGuidance v-if="guidance.target === 'roster-shift'" :message="guidance.message" /><small v-if="!data.shifts.length">请先在班次管理中新增班次。</small></label>
          <template v-if="form.kind === 'REST'"><label><input v-model="form.partial" type="checkbox" :disabled="saving || !!pending">仅部分时段休息</label><div v-if="form.partial" class="roster-times"><label class="roster-field">开始<input v-model="form.start_time" type="time" :disabled="saving || !!pending"></label><label class="roster-field">结束<input v-model="form.end_time" data-field="roster-rest-end" type="time" :disabled="saving || !!pending"><RequiredFieldGuidance v-if="guidance.target === 'roster-rest-end'" :message="guidance.message" /></label></div><p v-else class="roster-note">当天全天休息。</p></template><p v-if="form.kind === 'CLEAR'" class="roster-note">清除所选员工这些日期的安排，恢复原预约规则。</p></template>
        <template v-else><label class="roster-field">班次名称<input v-model="form.name" data-field="roster-shift-name" maxlength="64" :disabled="saving || !!pending"><RequiredFieldGuidance v-if="guidance.target === 'roster-shift-name'" :message="guidance.message" /></label><div class="roster-times"><label class="roster-field">上班<input v-model="form.start_time" type="time" data-field="roster-start" :disabled="saving || !!pending"><RequiredFieldGuidance v-if="guidance.target === 'roster-start'" :message="guidance.message" /></label><label class="roster-field">下班<input v-model="form.end_time" type="time" data-field="roster-end" :disabled="saving || !!pending"><RequiredFieldGuidance v-if="guidance.target === 'roster-end'" :message="guidance.message" /></label></div></template>
        <p v-if="error" class="roster-error" role="alert">{{ error }}</p><ul v-if="conflicts.length" class="roster-conflicts"><li v-for="(conflict, i) in conflicts" :key="i">{{ conflict.staff_name }} · {{ conflict.time }} · {{ conflict.reservation_no }}</li></ul><p v-if="pending && !saving" class="roster-note">结果尚未确认，请点击重试查询本次保存结果。</p>
      </div><footer><button :disabled="saving || !!pending" @click="dialog = ''">取消</button><button class="roster-primary" :disabled="saving" @click="save()">{{ saving ? '正在保存…' : pending ? '重试本次保存' : '保存' }}</button></footer>
    </StaffRosterDialog>
    <button v-if="pending && !dialog && !saving" @click="execute(pending.path, pending.body)">重试本次操作</button>
  </section>
</template>

<style scoped>
.roster{height:100%;min-height:0;display:flex;flex-direction:column;padding:16px;background:#f5f7fa;color:#24354d;gap:12px;font-size:13px}.roster-head{display:flex;align-items:center;gap:32px}.roster h1{font-size:18px;margin:0}.roster h2{font-size:16px;margin:0}.roster-head nav{display:flex;gap:20px}.roster button,.roster input,.roster select,.roster summary{font:inherit}.roster button,.roster summary{border:1px solid #dfe5ed;border-radius:6px;background:#fff;padding:7px 13px;cursor:pointer;color:inherit}.roster button:disabled{opacity:.45;cursor:default}.roster-head nav button{border:0;border-radius:0;background:none;padding:10px 0;border-bottom:2px solid transparent}.roster-head nav button.active{color:#066ce4;border-bottom-color:#066ce4}.roster-toolbar{display:flex;align-items:center;gap:10px;background:#fff;padding:12px;border:1px solid #e1e7f0;border-radius:8px;flex-wrap:wrap}.roster input:not([type=checkbox]):not([type=radio]),.roster select{border:1px solid #dce3ec;background:#fff;border-radius:5px;padding:7px 10px;min-width:0;color:inherit}.roster input[type=checkbox],.roster input[type=radio]{accent-color:#076be2;margin-right:5px}.roster-toolbar-actions{margin-left:auto;display:flex;gap:8px}.roster details{position:relative}.roster details[open]{z-index:4}.roster details[open] button{display:block;width:140px}.roster summary{list-style:none}.roster-note{margin:0;color:#738196;line-height:1.6}.roster-error,.roster-success{margin:0;line-height:1.5;color:#c33131}.roster-success{color:#218252}.roster :deep(.table-pagination__summary){display:inline!important}.roster-results{flex:1;min-height:0;display:flex;flex-direction:column;background:#fff;border:1px solid #e1e7ef;border-radius:8px;overflow:hidden}.roster-scroll{flex:1;min-height:0;overflow:auto}.roster table{border-collapse:separate;border-spacing:0;text-align:left;width:100%}.roster th{background:#f0f5fb;font-weight:600;color:#425b79}.roster th,.roster td{padding:12px;border-bottom:1px solid #edf0f4}.roster thead th{position:sticky;top:0;z-index:2}.roster-calendar th:not(.roster-person){min-width:110px;text-align:center;position:sticky}.roster-calendar td{padding:0;border-right:1px solid #f0f2f6;min-width:110px}.roster-calendar td>button{display:flex;flex-direction:column;gap:5px;width:100%;min-height:64px;border:0;border-radius:0;text-align:center;align-items:center;font-size:12px;padding:8px}.roster-calendar .roster-person{position:sticky;left:0;z-index:1;min-width:155px;background:#fff}.roster-calendar thead .roster-person{z-index:3;background:#f0f5fb}.roster small{display:block;font-size:11px;font-weight:400;color:#8090a3;margin-top:5px}.roster th.weekend{color:#cf7043}.roster .work{color:#2467ae}.roster .rest{color:#bb6b31}.roster-unset{color:#b5becc}.roster-empty{text-align:center;padding:64px!important;color:#8b96a5}.roster-shifts td button{border:0;color:#086fe7;background:none}.roster-shifts{max-width:100%}.roster-backdrop{position:fixed;inset:0;background:#17284166;display:flex;align-items:center;justify-content:center;z-index:1000}.roster-dialog{width:min(520px,calc(100vw - 32px));max-height:90vh;display:flex;flex-direction:column;background:#fff;border-radius:10px;box-shadow:0 12px 60px #142d4944}.roster-dialog header,.roster-dialog footer{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 20px;border-bottom:1px solid #edf0f5}.roster-dialog footer{justify-content:flex-end;border:0;border-top:1px solid #edf0f5}.roster-dialog-body{overflow:auto;padding:20px;display:flex;flex-direction:column;gap:18px}.roster-dialog fieldset{border:0;padding:0;display:flex;gap:18px}.roster-dialog legend{margin-bottom:10px}.roster-field{display:flex;flex-direction:column;gap:8px;position:relative}.roster-times{display:flex;gap:20px}.roster-times label{flex:1}.roster button.roster-primary{background:#086de2;color:#fff;border-color:#086de2}.roster-conflicts{margin:0;padding-left:18px;color:#b93c35;line-height:1.9;max-height:160px;overflow:auto}.roster [data-field]:focus{outline:2px solid #86b8f9;outline-offset:2px}.roster :has(>.required-field-guidance)>input,.roster :has(>.required-field-guidance)>select{outline:2px solid #dc8c2a;outline-offset:2px}@media(max-width:800px){.roster{padding:10px}.roster-head{gap:20px}.roster-toolbar-actions{margin-left:0}.roster-toolbar input{max-width:180px}}
</style>

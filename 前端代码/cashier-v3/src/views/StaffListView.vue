<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import TablePagination from '@/components/common/TablePagination.vue'
import UnifiedQueryToolbar from '@/components/query/UnifiedQueryToolbar.vue'
import { useUnifiedQueryPage } from '@/composables/useUnifiedQueryPage'
import { requestCashierV3Action, useCashierV3State } from '@/services/cashierV3Bridge'
import {
  readStoreStaffComplete,
  readStoreStaffPositions,
  readStoreStaffWorkMembers,
  saveStoreStaff,
  uploadStoreStaffAvatar
} from '@/services/staffManagementApi'
import {
  cloneUnifiedQuerySnapshot,
  extractUnifiedQueryProjection,
  unifiedQueryActionMessage,
  unifiedQueryActionSucceeded
} from '@/services/unifiedQueryContract'

const state = useCashierV3State()
const projection = ref(null)
const lastSuccessfulQuery = ref(null)
const isQueryLoading = ref(false)
const queryError = ref('')
const editorOpen = ref(false)
const editorLoading = ref(false)
const editorSaving = ref(false)
const editorError = ref('')
const editorTab = ref('basic')
const editorStaffId = ref(0)
const positionOptions = ref([])
const workMemberOptions = ref([])
const avatarUploading = ref(false)

const PAGE_CODE = 'staff_list'
const DATE_FIELDS = ['joinDate', 'birthdayDate', 'contractBegin', 'contractEnd']
const baseQueryFields = [
  { key: 'staff_id', label: 'ID', type: 'number', defaultVisible: false },
  { key: 'store_name', label: '所属门店', defaultVisible: true },
  { key: 'staff_name', label: '店员名称', defaultVisible: true, defaultQuick: true },
  { key: 'nickname', label: '昵称', defaultVisible: true, defaultQuick: true },
  { key: 'phone', label: '手机号', defaultVisible: true, defaultQuick: true },
  { key: 'roles', label: '店员身份', defaultVisible: true },
  { key: 'position_label', label: '职位', defaultVisible: true },
  { key: 'position_level_label', label: '职级', defaultVisible: true },
  { key: 'is_manager', label: '店长', defaultVisible: true },
  { key: 'cashier_salesperson_enabled', label: '可作为销售人', defaultVisible: true },
  { key: 'cashier_craftsman_enabled', label: '可作为手艺人', defaultVisible: true },
  { key: 'status', label: '在职状态', defaultVisible: true, type: 'enum' },
  { key: 'is_fencheng', label: '参与分成' },
  { key: 'employee_number', label: '工号' },
  { key: 'join_date', label: '入职日期', type: 'date' },
  { key: 'id_card', label: '身份证号码' },
  { key: 'birthday_date', label: '生日日期', type: 'date' },
  { key: 'age', label: '年龄', type: 'number' },
  { key: 'join_area', label: '劳动关系所在地' },
  { key: 'birthday_area', label: '籍贯' },
  { key: 'now_area', label: '现居地' },
  { key: 'contract_begin', label: '合同起始日', type: 'date' },
  { key: 'contract_end', label: '合同终止日', type: 'date' },
  { key: 'uid', label: '商城用户ID', type: 'number' },
  { key: 'account', label: '账号' },
  { key: 'has_pwd', label: '密码' },
  { key: 'is_customer', label: '客服' },
  { key: 'is_reservable', label: '可被预约' },
  { key: 'customer_num', label: '专属客户数', type: 'number' },
  { key: 'department', label: '部门' },
  { key: 'salary_status', label: '工资状态' },
  { key: 'birthday_type', label: '生日类型' }
]

function defaultEditorValues() {
  return {
    staffName: '', phone: '', avatar: '/static/images/staff/avatar_male.png', account: '', password: '',
    positionIds: [], scopeMode: 'personal', workMemberId: 0, notify: false, status: true,
    salespersonEnabled: true, craftsmanEnabled: true, isCustomer: false, customerUrl: '',
    isReservable: true, employeeNumber: '', idCard: '', age: '', joinArea: '', joinDate: '',
    birthdayDate: '', birthdayType: 1, birthdayArea: '', nowArea: '', contractBegin: '',
    contractEnd: '', salaryStatus: true, department: '', employmentTypeCode: 'internal', employmentTypeVersion: 1
  }
}

const editorValues = reactive(defaultEditorValues())

function replaceEditorValues(values = {}) {
  Object.assign(editorValues, defaultEditorValues(), values)
  DATE_FIELDS.forEach((field) => {
    const value = String(editorValues[field] || '')
    editorValues[field] = value && !value.startsWith('0000-00-00') ? value.slice(0, 10) : ''
  })
}

async function requestAction(action, payload = {}) {
  return requestCashierV3Action(action, payload)
}

const unifiedQuery = useUnifiedQueryPage({ pageCode: PAGE_CODE, pageName: '员工列表', baseFields: baseQueryFields, requestAction })
const queryCapability = unifiedQuery.capability
const queryFields = unifiedQuery.fields
const records = computed(() => Array.isArray(projection.value?.records) ? projection.value.records : [])
const total = computed(() => Math.max(Number(projection.value?.total) || 0, records.value.length))
const page = computed(() => Math.max(1, Number(projection.value?.page) || 1))
const pageSize = computed(() => Math.max(1, Number(projection.value?.pageSize) || 20))
const statusOptions = computed(() => Array.isArray(projection.value?.statusOptions) ? projection.value.statusOptions : [])
const effectiveQuerySettings = computed(() => queryCapability.value?.querySettings || projection.value?.querySettings || {})
const queryDataAsOf = computed(() => projection.value?.dataAsOf || queryCapability.value?.dataAsOf || '')
const fieldMap = computed(() => new Map(queryFields.value.map((field) => [field.key, field])))
const defaultVisibleKeys = computed(() => queryFields.value.filter((field) => field.defaultVisible !== false).map((field) => field.key))
const visibleKeys = ref([])
const visibleFields = computed(() => visibleKeys.value.map((key) => fieldMap.value.get(key)).filter(Boolean))

function applyQuerySettings(settings) {
  const configured = Array.isArray(settings?.visibleFields) ? settings.visibleFields : defaultVisibleKeys.value
  visibleKeys.value = [...new Set(configured)].filter((key) => fieldMap.value.has(key))
}

watch(() => [effectiveQuerySettings.value, queryFields.value.map((field) => field.key).join('|')], ([settings]) => applyQuerySettings(settings), { deep: true, immediate: true })

function displayValue(record, field) {
  const displayValues = record.queryDisplayValues || record.query_display_values
  if (displayValues && Object.prototype.hasOwnProperty.call(displayValues, field.key)) return displayValues[field.key]
  const values = record.queryFieldValues || record.query_field_values
  const value = values && Object.prototype.hasOwnProperty.call(values, field.key) ? values[field.key] : record[field.key]
  return value === undefined || value === null || value === '' ? '—' : value
}

function clearResult(message = '') {
  projection.value = null
  lastSuccessfulQuery.value = null
  queryError.value = message
}

let querySequence = 0
async function queryStaff(query = {}, resetPage = true) {
  const sequence = ++querySequence
  const contextId = state.stateContextId
  const nextQuery = {
    ...cloneUnifiedQuerySnapshot(lastSuccessfulQuery.value), ...cloneUnifiedQuerySnapshot(query), pageCode: PAGE_CODE,
    page: resetPage ? 1 : Math.max(1, Number(query.page) || page.value),
    limit: Math.max(1, Number(query.limit || query.pageSize) || pageSize.value)
  }
  delete nextQuery.pageSize
  isQueryLoading.value = true
  queryError.value = ''
  try {
    const result = await requestAction('query-staff', nextQuery)
    if (sequence !== querySequence || contextId !== state.stateContextId) return result
    const nextProjection = extractUnifiedQueryProjection(result, ['staffCenter', 'staff_center'])
    if (!nextProjection) {
      clearResult(unifiedQueryActionMessage(result, '员工查询失败，请稍后重试。'))
      return result
    }
    projection.value = nextProjection
    lastSuccessfulQuery.value = cloneUnifiedQuerySnapshot(nextQuery)
    return result
  } catch (error) {
    if (sequence === querySequence && contextId === state.stateContextId) clearResult(error?.message || '员工查询失败，请稍后重试。')
    return null
  } finally {
    if (sequence === querySequence && contextId === state.stateContextId) isQueryLoading.value = false
  }
}

function initialQuery(capability) {
  const settings = capability?.querySettings || {}
  return {
    pageCode: PAGE_CODE, page: 1, limit: 20, keyword: '', dataScope: 'normal', businessStatus: '', topFilters: [],
    sorts: Array.isArray(settings.sorts) ? settings.sorts : [], filters: Array.isArray(settings.filters) ? settings.filters : [],
    filterRelation: settings.filterRelation === 'any' ? 'any' : 'all', groupBy: Array.isArray(settings.groupBy) ? settings.groupBy : [],
    summaries: Array.isArray(settings.summaries) ? settings.summaries : [], visibleFields: Array.isArray(settings.visibleFields) ? settings.visibleFields : [],
    fieldVersions: settings.customFieldVersions || {}, ...(capability?.queryCutoffDate ? { queryCutoffDate: capability.queryCutoffDate } : {})
  }
}

let bootstrapSequence = 0
watch(() => state.stateContextId, async (contextId) => {
  const sequence = ++bootstrapSequence
  querySequence += 1
  clearResult()
  isQueryLoading.value = false
  unifiedQuery.reset()
  if (!contextId) return
  const capability = await unifiedQuery.load({ silent: true })
  if (sequence !== bootstrapSequence || contextId !== state.stateContextId) return
  await queryStaff(initialQuery(capability))
}, { immediate: true })

async function saveQuerySettings(settings, options = {}) {
  const commandContext = queryCapability.value?.commandContext
  if (!commandContext) return null
  const result = await requestAction('save-unified-query-settings', { pageCode: PAGE_CODE, settings, ...(options.idempotencyKey ? { idempotencyKey: options.idempotencyKey } : {}), commandContexts: [commandContext] })
  if (unifiedQueryActionSucceeded(result)) await unifiedQuery.load({ silent: true })
  return result
}

function changePage(pagination) {
  if (lastSuccessfulQuery.value && !isQueryLoading.value) queryStaff(pagination, false)
}

function normalizeOptionList(value) {
  return Array.isArray(value) ? value.map((item) => ({ value: Number(item.value ?? item.id), label: item.label ?? item.name ?? '' })).filter((item) => item.value > 0 && item.label) : []
}

async function loadEditorOptions() {
  const [positions, workMembers] = await Promise.all([readStoreStaffPositions(), readStoreStaffWorkMembers()])
  positionOptions.value = normalizeOptionList(positions?.data)
  workMemberOptions.value = normalizeOptionList(workMembers?.data)
}

function mapDetail(detail) {
  const scopeMode = detail?.scope?.scope_mode === 'store_self' || detail?.scope?.scope_mode === 'store' ? 'store_self' : 'personal'
  return {
    staffName: String(detail?.staff_name || ''), phone: String(detail?.phone || ''), avatar: String(detail?.avatar || '/static/images/staff/avatar_male.png'),
    account: String(detail?.account || ''), positionIds: Array.isArray(detail?.position_ids) ? detail.position_ids.map(Number).filter(Boolean) : [],
    scopeMode, workMemberId: Number(detail?.work_member_id || 0), notify: Number(detail?.notify || 0) === 1,
    status: Number(detail?.status ?? 1) === 1, salespersonEnabled: Number(detail?.cashier_salesperson_enabled ?? 1) === 1,
    craftsmanEnabled: Number(detail?.cashier_craftsman_enabled ?? 1) === 1, isCustomer: Number(detail?.is_customer || 0) === 1,
    customerUrl: String(detail?.customer_url || ''), isReservable: Number(detail?.is_reservable ?? 1) === 1,
    employeeNumber: String(detail?.employee_number || ''), idCard: String(detail?.id_card || ''), age: detail?.age ?? '',
    joinArea: String(detail?.join_area || ''), joinDate: detail?.join_date || '', birthdayDate: detail?.birthday_date || '',
    birthdayType: Number(detail?.birthday_type) === 2 ? 2 : 1, birthdayArea: String(detail?.birthday_area || ''), nowArea: String(detail?.now_area || ''),
    contractBegin: detail?.contract_begin || '', contractEnd: detail?.contract_end || '', salaryStatus: Number(detail?.salary_status ?? 1) === 1,
    department: String(detail?.department || ''), employmentTypeCode: String(detail?.employment_type_code || ''),
    employmentTypeVersion: Number(detail?.employment_type_version)
  }
}

async function openEditor(record = null) {
  editorOpen.value = true
  editorLoading.value = true
  editorError.value = ''
  editorTab.value = 'basic'
  editorStaffId.value = Number(record?.staffId || record?.staff_id || record?.id || 0)
  replaceEditorValues()
  try {
    const tasks = [loadEditorOptions()]
    if (editorStaffId.value > 0) tasks.push(readStoreStaffComplete(editorStaffId.value))
    const output = await Promise.all(tasks)
    if (editorStaffId.value > 0) replaceEditorValues(mapDetail(output[1]?.data || {}))
  } catch (error) {
    editorError.value = error?.message || '员工资料加载失败。'
  } finally {
    editorLoading.value = false
  }
}

onMounted(() => {
  window.addEventListener('cashier-v3:open-staff-creator', openEditor)
})

onBeforeUnmount(() => {
  window.removeEventListener('cashier-v3:open-staff-creator', openEditor)
})

function validateEditor() {
  if (!editorValues.staffName.trim()) return '请填写员工姓名。'
  if (!/^1[3-9]\d{9}$/.test(editorValues.phone.trim())) return '手机号码格式不正确。'
  if (!editorValues.avatar.trim()) return '请设置员工头像。'
  if (!editorValues.positionIds.length) return '请选择岗位。'
  if (!['internal', 'partner', 'outsourced'].includes(editorValues.employmentTypeCode)) return '请选择人员类型。'
  if (!Number.isInteger(editorValues.employmentTypeVersion) || editorValues.employmentTypeVersion < 0) return '人员类型版本无效，请刷新后重试。'
  if (!editorStaffId.value && !editorValues.account.trim()) return '请填写登录账号。'
  if (!editorStaffId.value && !editorValues.password) return '请设置登录密码。'
  if (editorValues.isCustomer && !editorValues.customerUrl.trim()) return '请上传客服二维码。'
  if (editorValues.age !== '' && (!Number.isInteger(Number(editorValues.age)) || Number(editorValues.age) < 0 || Number(editorValues.age) > 150)) return '年龄必须是 0 到 150 的整数。'
  return ''
}

async function saveEditor() {
  if (editorSaving.value || avatarUploading.value) return
  const invalid = validateEditor()
  if (invalid) {
    editorError.value = invalid
    return
  }
  editorSaving.value = true
  editorError.value = ''
  try {
    await saveStoreStaff(editorStaffId.value, editorValues)
    editorOpen.value = false
    await queryStaff(lastSuccessfulQuery.value || initialQuery(queryCapability.value), false)
  } catch (error) {
    editorError.value = error?.message || '员工保存失败。'
  } finally {
    editorSaving.value = false
  }
}

function avatarSource(value) {
  const raw = String(value || '')
  return raw.startsWith('http://') || raw.startsWith('https://') ? raw : `${window.location.origin}${raw.startsWith('/') ? raw : `/${raw}`}`
}

async function onAvatarFileChange(event) {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file) return
  if (!String(file.type || '').startsWith('image/')) {
    editorError.value = '请选择图片文件。'
    return
  }
  avatarUploading.value = true
  editorError.value = ''
  try {
    editorValues.avatar = await uploadStoreStaffAvatar(file)
  } catch (error) {
    editorError.value = error?.message || '头像上传失败。'
  } finally {
    avatarUploading.value = false
  }
}
</script>

<template>
  <section class="staff-list-page" aria-label="员工列表">
    <header class="staff-list-page__head">
      <div>
        <h3>人员管理</h3>
        <p>查询当前门店员工并设置销售人、手艺人资格。</p>
      </div>
      <button type="button" class="button button--primary" @click="openEditor()">新增员工</button>
    </header>
    <UnifiedQueryToolbar
      search-placeholder="搜索员工姓名、昵称或手机号" settings-button-label="查询设置" :status-options="statusOptions"
      :fields="queryFields" :page-code="PAGE_CODE" page-name="员工列表" :result-count="total" :page-size="pageSize"
      :current-page="page" :current-page-count="records.length" :data-as-of="queryDataAsOf" :query-capability="queryCapability"
      :executed-query="lastSuccessfulQuery" :is-query-loading="isQueryLoading" :state-context-key="state.stateContextId || ''"
      default-sort-field="ID" lock-store-selector :current-store="state.currentStore" :settings="effectiveQuerySettings"
      @query="queryStaff" @settings-applied="applyQuerySettings" :on-save-settings="saveQuerySettings"
      :on-save-field-aliases="unifiedQuery.saveFieldAliases" :on-save-custom-field="unifiedQuery.saveCustomField"
      :on-change-custom-field-status="unifiedQuery.changeCustomFieldStatus" :on-archive-custom-field="unifiedQuery.archiveCustomField"
      :on-upgrade-saved-query-field-reference="unifiedQuery.upgradeSavedQueryFieldReference" :on-create-export="unifiedQuery.createExport"
      :on-query-export-task="unifiedQuery.queryExportTask"
    />
    <p v-if="isQueryLoading" class="staff-list-message">正在查询员工数据…</p>
    <p v-else-if="queryError" class="staff-list-message staff-list-message--error">{{ queryError }}</p>
    <main class="staff-list-wrap">
      <table class="staff-list-table"><thead><tr><th v-for="field in visibleFields" :key="field.key">{{ field.label }}</th><th class="staff-list-table__action">操作</th></tr></thead>
        <tbody><tr v-for="record in records" :key="record.staffId || record.id"><td v-for="field in visibleFields" :key="field.key">{{ displayValue(record, field) }}</td><td class="staff-list-table__action"><button type="button" class="button button--text" @click="openEditor(record)">编辑</button></td></tr></tbody>
      </table>
      <div v-if="!records.length && !isQueryLoading" class="staff-list-empty">暂无符合条件的员工。</div>
    </main>
    <TablePagination :total="total" :page="page" :page-size="pageSize" @change="changePage" />

    <div v-if="editorOpen" class="staff-editor-backdrop" @click.self="!editorSaving && (editorOpen = false)">
      <form class="staff-editor" aria-label="员工资料" @submit.prevent="saveEditor">
        <header><div><h2>{{ editorStaffId ? '编辑员工' : '新增员工' }}</h2></div><button type="button" class="staff-editor__close" aria-label="关闭" :disabled="editorSaving" @click="editorOpen = false">×</button></header>
        <div v-if="editorLoading" class="staff-editor__loading">正在加载员工资料…</div>
        <template v-else>
          <nav class="staff-editor__tabs" aria-label="员工资料设置"><button v-for="tab in [{ id: 'basic', label: '基本信息' }, { id: 'scope', label: '数据权限' }, { id: 'login', label: '登录设置' }, { id: 'other', label: '其他信息' }]" :key="tab.id" type="button" :class="{ active: editorTab === tab.id }" @click="editorTab = tab.id">{{ tab.label }}</button></nav>
          <div class="staff-editor__body">
            <div v-show="editorTab === 'basic'" class="staff-editor__grid">
              <label>员工姓名<input v-model.trim="editorValues.staffName" maxlength="64" required></label>
              <label>手机号码<input v-model.trim="editorValues.phone" inputmode="numeric" maxlength="11" required></label>
              <div class="staff-editor__avatar-field"><span>员工头像</span><div><img :src="avatarSource(editorValues.avatar)" alt="员工头像"><label class="button button--secondary">{{ avatarUploading ? '上传中…' : '上传头像' }}<input type="file" accept="image/*" :disabled="avatarUploading || editorSaving" @change="onAvatarFileChange"></label></div></div>
              <label>岗位<select v-model="editorValues.positionIds" multiple required><option v-for="option in positionOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select></label>
              <label class="staff-editor__toggle"><input v-model="editorValues.salespersonEnabled" type="checkbox"><span>可作为销售人</span></label>
              <label class="staff-editor__toggle"><input v-model="editorValues.craftsmanEnabled" type="checkbox"><span>可作为手艺人</span></label>
              <label>人员类型<select v-model="editorValues.employmentTypeCode"><option value="internal">内部员工</option><option value="partner">合作方</option><option value="outsourced">外包</option></select></label>
              <label class="staff-editor__toggle"><input v-model="editorValues.status" type="checkbox"><span>在职状态</span></label>
            </div>
            <div v-show="editorTab === 'scope'" class="staff-editor__section">
              <fieldset><legend>数据范围</legend><label><input v-model="editorValues.scopeMode" type="radio" value="personal">个人</label><label><input v-model="editorValues.scopeMode" type="radio" value="store_self">门店</label></fieldset>
            </div>
            <div v-show="editorTab === 'login'" class="staff-editor__grid">
              <label>登录账号<input v-model.trim="editorValues.account" maxlength="35" autocomplete="username" :required="!editorStaffId"></label>
              <label>登录密码<input v-model="editorValues.password" type="password" autocomplete="new-password" :required="!editorStaffId" :placeholder="editorStaffId ? '不修改请留空' : '请输入登录密码'"></label>
            </div>
            <div v-show="editorTab === 'other'" class="staff-editor__grid">
              <label>关联企微<select v-model="editorValues.workMemberId"><option :value="0">请选择</option><option v-for="option in workMemberOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select></label>
              <label class="staff-editor__toggle"><input v-model="editorValues.isCustomer" type="checkbox"><span>客服开关</span></label>
              <label v-if="editorValues.isCustomer" class="staff-editor__wide">客服二维码地址<input v-model.trim="editorValues.customerUrl" maxlength="255" required></label>
              <label class="staff-editor__toggle"><input v-model="editorValues.isReservable" type="checkbox"><span>可被预约</span></label>
              <label>工号<input v-model.trim="editorValues.employeeNumber" maxlength="255"></label><label>身份证号<input v-model.trim="editorValues.idCard" maxlength="255"></label>
              <label>年龄<input v-model="editorValues.age" type="number" min="0" max="150" step="1"></label><label>劳动关系所在地<input v-model.trim="editorValues.joinArea" maxlength="255"></label>
              <label>入职日期<input v-model="editorValues.joinDate" type="date"></label><label>生日日期<input v-model="editorValues.birthdayDate" type="date"></label>
              <label>生日类型<select v-model.number="editorValues.birthdayType"><option :value="1">农历</option><option :value="2">新历</option></select></label><label>籍贯<input v-model.trim="editorValues.birthdayArea" maxlength="255"></label>
              <label>现居地<input v-model.trim="editorValues.nowArea" maxlength="255"></label><label>合同起始日<input v-model="editorValues.contractBegin" type="date"></label>
              <label>合同终止日<input v-model="editorValues.contractEnd" type="date"></label><label class="staff-editor__toggle"><input v-model="editorValues.salaryStatus" type="checkbox"><span>工资状态</span></label>
              <label>部门<input v-model.trim="editorValues.department" maxlength="255"></label><label class="staff-editor__toggle"><input v-model="editorValues.notify" type="checkbox"><span>通知开关</span></label>
            </div>
            <p v-if="editorError" class="staff-editor__error">{{ editorError }}</p>
          </div>
        </template>
        <footer><button type="button" class="button button--secondary" :disabled="editorSaving" @click="editorOpen = false">取消</button><button type="submit" class="button button--primary" :disabled="editorLoading || editorSaving || avatarUploading">{{ editorSaving ? '保存中…' : '保存' }}</button></footer>
      </form>
    </div>
  </section>
</template>

<style scoped>
.staff-list-page { display:grid; min-width:0; min-height:0; gap:12px; padding:18px; overflow:auto; background:#f5f7fa; }
.staff-list-page__head { display:flex; align-items:center; justify-content:space-between; gap:16px; padding:16px 18px; border:1px solid #e4ebf3; border-radius:8px; background:#fff; }
.staff-list-page__head > div { display:grid; gap:4px; min-width:0; }
.staff-list-page__head h3 { margin:0; color:#1f2329; font-size:18px; line-height:1.3; }
.staff-list-page__head p { margin:0; color:#697586; font-size:13px; line-height:1.4; }
.staff-list-message { margin:0; padding:9px 12px; border:1px solid #b9d4ff; border-radius:7px; background:#f7fbff; color:#35658f; font-size:13px; }.staff-list-message--error { border-color:#ffccc7; background:#fff2f0; color:#cf1322; }
.staff-list-wrap { min-width:0; overflow:auto; border:1px solid #dde4ed; border-radius:8px; background:#fff; }.staff-list-table { width:100%; min-width:1080px; border-collapse:collapse; color:#303133; font-size:13px; white-space:nowrap; }.staff-list-table th,.staff-list-table td { padding:11px 12px; border-bottom:1px solid #edf1f5; text-align:left; }.staff-list-table th { position:sticky; top:0; z-index:1; background:#f8fafc; color:#697586; font-size:12px; font-weight:600; }.staff-list-table__action { position:sticky; right:0; width:72px; background:#fff; }.staff-list-empty { display:grid; min-height:220px; place-items:center; color:#8a94a3; font-size:13px; }
.staff-editor-backdrop { position:fixed; inset:0; z-index:1200; display:grid; place-items:center; padding:20px; background:rgba(20,29,40,.42); }.staff-editor { width:min(960px,100%); max-height:calc(100vh - 40px); overflow:hidden; border-radius:8px; background:#fff; box-shadow:0 22px 60px rgba(20,29,40,.24); }.staff-editor header,.staff-editor footer { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:16px 18px; border-bottom:1px solid #edf1f5; }.staff-editor footer { justify-content:flex-end; border-top:1px solid #edf1f5; border-bottom:0; }.staff-editor h2 { margin:0; font-size:17px; }.staff-editor__close { width:32px; height:32px; border:0; background:transparent; color:#697586; font-size:24px; cursor:pointer; }.staff-editor__loading { display:grid; min-height:280px; place-items:center; color:#7a8696; }.staff-editor__tabs { display:flex; gap:4px; padding:0 18px; border-bottom:1px solid #edf1f5; }.staff-editor__tabs button { padding:12px 16px; border:0; border-bottom:2px solid transparent; background:transparent; color:#697586; cursor:pointer; }.staff-editor__tabs button.active { border-color:#2f80ed; color:#2f80ed; }.staff-editor__body { min-height:300px; max-height:calc(100vh - 250px); overflow:auto; padding:18px; }.staff-editor__grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; }.staff-editor__grid>label,.staff-editor__avatar-field { display:grid; gap:6px; color:#697586; font-size:13px; }.staff-editor input:not([type=checkbox]):not([type=file]),.staff-editor select { box-sizing:border-box; width:100%; min-height:36px; padding:7px 9px; border:1px solid #d9e1eb; border-radius:6px; background:#fff; color:#303133; font:inherit; }.staff-editor select[multiple] { min-height:112px; }.staff-editor__wide { grid-column:1 / -1; }.staff-editor__toggle { display:flex !important; align-items:center; gap:9px; padding:10px; border:1px solid #dbe4ef; border-radius:7px; color:#303133 !important; cursor:pointer; }.staff-editor__toggle input { width:18px; height:18px; }.staff-editor__avatar-field>div { display:flex; align-items:center; gap:12px; }.staff-editor__avatar-field img { width:56px; height:56px; border-radius:50%; object-fit:cover; border:1px solid #dbe4ef; }.staff-editor__avatar-field .button { position:relative; display:inline-grid; place-items:center; min-height:34px; overflow:hidden; cursor:pointer; }.staff-editor__avatar-field input[type=file] { position:absolute; inset:0; opacity:0; cursor:pointer; }.staff-editor__section fieldset { display:flex; gap:20px; margin:0; padding:16px; border:1px solid #dbe4ef; border-radius:7px; }.staff-editor__section label { display:flex; align-items:center; gap:6px; cursor:pointer; }.staff-editor__error { margin:16px 0 0; color:#cf1322; font-size:13px; }
@media (max-width:760px) {
  .staff-list-page { padding:12px; }
  .staff-list-page__head { flex-direction:column; align-items:stretch; padding:14px 16px; }
  .staff-list-page__head button { width:100%; }
  .staff-editor-backdrop { padding:12px; }
  .staff-editor__grid { grid-template-columns:1fr; }
  .staff-editor__wide { grid-column:auto; }
  .staff-editor__tabs { overflow:auto; padding:0 8px; }
  .staff-editor__tabs button { flex:0 0 auto; padding:12px 10px; }
}
</style>

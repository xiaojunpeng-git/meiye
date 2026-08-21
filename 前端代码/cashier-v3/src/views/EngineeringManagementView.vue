<script setup>
import { computed, onMounted, ref } from 'vue'
import RefreshCw from '@lucide/vue/dist/esm/icons/refresh-cw.mjs'
import Download from '@lucide/vue/dist/esm/icons/download.mjs'
import Plus from '@lucide/vue/dist/esm/icons/plus.mjs'
import Trash2 from '@lucide/vue/dist/esm/icons/trash-2.mjs'
import OrganizationStoreScopePicker from '@/components/OrganizationStoreScopePicker.vue'
import { useCashierV3State } from '@/services/cashierV3Bridge'
import { queryEngineeringLedger, queryEngineeringScope, saveEngineeringLedger, voidEngineeringLedger, exportEngineeringLedger } from '@/services/engineeringLedgerApi'

const props = defineProps({ platform: { type: Boolean, default: false } })
const state = useCashierV3State()
const mode = computed(() => props.platform ? 'platform' : 'store')
const tabs = [
  { key: 'store_building', label: '建店明细' },
  { key: 'engineering_quality', label: '工程质量' },
  { key: 'engineering_repair', label: '工程维修' },
  { key: 'rent_renewal', label: '降租续签' }
]
function initialLedgerType() {
  try {
    const query = window.location.hash.includes('?') ? window.location.hash.split('?')[1] : ''
    const value = new URLSearchParams(query).get('type')
    return tabs.some((tab) => tab.key === value) ? value : 'store_building'
  } catch (_) {
    return 'store_building'
  }
}
const active = ref(initialLedgerType())
const rows = ref([])
const schema = ref({})
const filters = ref({ keyword: '', start_date: '', end_date: '', store_ids: [] })
const pagination = ref({ page: 1, limit: 20, total: 0 })
const scopePicker = ref({ loading: false, tree: [], allowedStoreIds: [], selectedStoreIds: [], label: '当前权限范围' })
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const notice = ref('')
const editing = ref(null)

const fieldDefaults = {
  store_building: ['省', '分公司', '门店', '建店类型', '签约日期', '办证日期', '图纸设计起止', '工程比价起止', '工程施工起止', '验收日期', '拓客', '招聘', '开业日期', '建店时长', '首月业绩', '次月业绩', '第三个月业绩'],
  engineering_quality: ['分公司', '门店', '面积', '施工开始时间', '施工结束时间', '施工时长', '开业日期', '建店时长', '硬装造价', '软装造价', '平米工程造价', '总投资金额'],
  engineering_repair: ['提报日期', '提报人', '维修需求', '维修开始日期', '维修完成日期', '实际完成时长', '维修费用'],
  rent_renewal: ['门店', '最新合同起始时间', '最新合同到期时间', '原月租管费', '原年租管费', '降租完成时间', '降租后月租管费', '降租后年租管费', '月降租金额', '年降租金额']
}
const activeSchema = computed(() => Array.isArray(schema.value[active.value]) && schema.value[active.value].length ? schema.value[active.value] : fieldDefaults[active.value])
const activeRows = computed(() => rows.value.filter((row) => String(row.type || row.record_type || active.value) === active.value))
const formEntries = computed(() => activeSchema.value
  .filter((field) => typeof field === 'string' || field.editable !== false)
  .map((field) => ({ key: field.key || field.name || field, label: field.label || field.name || field })))

function scopeChanged(value) { filters.value.store_ids = value.storeIds || []; load(1) }
function selectedCreateStoreId() {
  const selected = (filters.value.store_ids || []).map(Number).filter(Boolean)
  if (selected.length === 1) return selected[0]
  const allowed = (scopePicker.value.allowedStoreIds || []).map(Number).filter(Boolean)
  return props.platform ? 0 : (allowed[0] || 0)
}
function beginCreate() {
  const storeId = selectedCreateStoreId()
  if (props.platform && !storeId) {
    error.value = '新增台账前请先在当前权限范围中选择一个具体门店。'
    return
  }
  editing.value = { id: '', version: null, type: active.value, values: storeId ? { store_id: storeId } : {} }
  notice.value = ''
  error.value = ''
}
function beginEdit(row) { editing.value = { id: row.id || row.record_id, version: row.version, type: active.value, values: { ...(row.values || row) } }; notice.value = '' }
function closeEditor() { editing.value = null }
async function load(page = pagination.value.page) {
  loading.value = true; error.value = ''
  try {
    const result = await queryEngineeringLedger(mode.value, { type: active.value, keyword: filters.value.keyword, start_date: filters.value.start_date, end_date: filters.value.end_date, store_ids: filters.value.store_ids, page, limit: pagination.value.limit })
    rows.value = Array.isArray(result?.records) ? result.records : Array.isArray(result) ? result : []
    schema.value = result?.schema || { [active.value]: Array.isArray(result?.columns) ? result.columns : [] }
    pagination.value = { ...pagination.value, page: Number(result?.page || page), limit: Number(result?.limit || pagination.value.limit), total: Number(result?.total || 0) }
    if (result?.scope) scopePicker.value = { ...scopePicker.value, ...result.scope, selectedStoreIds: filters.value.store_ids }
  } catch (err) { error.value = err.message || '工程管理数据加载失败。'; rows.value = [] } finally { loading.value = false }
}
async function loadScope() {
  if (!(props.platform || active.value === 'rent_renewal')) return
  scopePicker.value.loading = true
  try {
    const result = await queryEngineeringScope(mode.value)
    scopePicker.value = {
      ...scopePicker.value,
      tree: result?.tree || [],
      allowedStoreIds: Array.isArray(result?.allowed_store_ids) ? result.allowed_store_ids.map(Number).filter(Boolean) : [],
      loading: false,
      selectedStoreIds: filters.value.store_ids
    }
  } catch (_) {
    scopePicker.value.loading = false
  }
}
async function save() {
  if (!editing.value) return
  saving.value = true; error.value = ''
  try { await saveEngineeringLedger(mode.value, active.value, editing.value.values, { expectedVersion: editing.value.version }); notice.value = '已保存，列表已刷新。'; editing.value = null; await load() }
  catch (err) { error.value = err.message || '保存失败，请刷新后重试。' } finally { saving.value = false }
}
async function voidRecord(row) {
  if (!props.platform || !row?.id || !window.confirm('确认作废这条台账记录？作废后不会出现在列表和导出中。')) return
  error.value = ''; notice.value = ''
  try {
    await voidEngineeringLedger(mode.value, active.value, row.id, row.version)
    notice.value = '记录已作废，列表已刷新。'
    await load()
  } catch (err) { error.value = err.message || '作废失败，请刷新后重试。' }
}
async function download() {
  try { const blob = await exportEngineeringLedger(mode.value, { type: active.value, keyword: filters.value.keyword, start_date: filters.value.start_date, end_date: filters.value.end_date, store_ids: filters.value.store_ids }); const url = URL.createObjectURL(blob); const anchor = document.createElement('a'); anchor.href = url; anchor.download = `${tabs.find((tab) => tab.key === active.value)?.label || '工程管理'}.csv`; anchor.click(); URL.revokeObjectURL(url) } catch (err) { error.value = err.message || '导出失败。' }
}
function switchTab(key) { active.value = key; editing.value = null; notice.value = ''; pagination.value.page = 1; loadScope(); load(1) }
function pageBack() { if (pagination.value.page > 1) load(pagination.value.page - 1) }
function pageForward() { if (pagination.value.page * pagination.value.limit < pagination.value.total) load(pagination.value.page + 1) }
onMounted(() => { loadScope(); load() })
</script>

<template>
  <main class="engineering-ledger" aria-label="工程管理">
    <header class="engineering-ledger__header"><div><span class="eyebrow">业务台账</span><h1>工程管理</h1><p>按统一报表权限查看和维护建店、工程、维修与降租续签记录。</p></div><div class="engineering-ledger__actions"><button type="button" @click="download"><Download :size="15" />导出</button><button type="button" @click="load"><RefreshCw :size="15" />刷新</button></div></header>
    <nav class="engineering-ledger__tabs" aria-label="工程管理台账"><button v-for="tab in tabs" :key="tab.key" type="button" :class="{ active: active === tab.key }" @click="switchTab(tab.key)">{{ tab.label }}</button></nav>
    <section class="engineering-ledger__filters"><OrganizationStoreScopePicker v-if="platform || active === 'rent_renewal'" v-model="scopePicker.selectedStoreIds" :tree="scopePicker.tree || []" :allowed-store-ids="scopePicker.allowedStoreIds || []" :label="scopePicker.label" :loading="scopePicker.loading" @change="scopeChanged" /><label>开始日期<input v-model="filters.start_date" type="date" /></label><label>结束日期<input v-model="filters.end_date" type="date" /></label><label>关键词<input v-model.trim="filters.keyword" placeholder="门店、提报人或合同" @keyup.enter="load(1)" /></label><button type="button" @click="load(1)">查询</button><button type="button" class="primary" @click="beginCreate"><Plus :size="15" />新增记录</button></section>
    <p v-if="notice" class="engineering-ledger__notice">{{ notice }}</p><p v-if="error" class="engineering-ledger__error">{{ error }}</p>
    <section class="engineering-ledger__table-wrap"><div v-if="loading" class="state">正在读取台账...</div><div v-else-if="!activeRows.length" class="state">当前权限范围暂无{{ tabs.find((tab) => tab.key === active)?.label }}记录。</div><table v-else><thead><tr><th v-for="field in activeSchema" :key="field.key || field.name || field">{{ field.label || field.name || field }}</th><th>操作</th></tr></thead><tbody><tr v-for="row in activeRows" :key="row.id || row.record_id"><td v-for="field in activeSchema" :key="field.key || field.name || field">{{ row.values?.[field.key || field.name] ?? row[field.key || field.name] ?? '—' }}</td><td><button type="button" class="link" @click="beginEdit(row)">编辑</button><button v-if="platform" type="button" class="link link--danger" @click="voidRecord(row)"><Trash2 :size="14" />作废</button></td></tr></tbody></table><footer class="engineering-ledger__pagination"><span>共 {{ pagination.total }} 条</span><button type="button" :disabled="pagination.page <= 1" @click="pageBack">上一页</button><span>第 {{ pagination.page }} 页</span><button type="button" :disabled="pagination.page * pagination.limit >= pagination.total" @click="pageForward">下一页</button></footer></section>
    <div v-if="editing" class="engineering-ledger__editor" role="dialog" aria-modal="true"><div class="engineering-ledger__editor-card"><header><h2>{{ editing.id ? '编辑' : '新增' }}{{ tabs.find((tab) => tab.key === active)?.label }}</h2><button type="button" class="link" @click="closeEditor">关闭</button></header><div class="engineering-ledger__form"><label v-for="field in formEntries" :key="field.key">{{ field.label }}<input v-model="editing.values[field.key]" :placeholder="`${field.label}（人工填写）`" /></label></div><footer><button type="button" @click="closeEditor">取消</button><button type="button" class="primary" :disabled="saving" @click="save">{{ saving ? '保存中...' : '保存' }}</button></footer></div></div>
  </main>
</template>

<style scoped>
.engineering-ledger{min-height:100%;overflow:auto;padding:24px;background:#f4f6f8;color:#26364a}.engineering-ledger *{box-sizing:border-box}.engineering-ledger__header,.engineering-ledger__tabs,.engineering-ledger__filters,.engineering-ledger__table-wrap{max-width:1440px;margin:0 auto}.engineering-ledger__header{display:flex;justify-content:space-between;gap:18px;align-items:flex-start}.eyebrow{color:#75869a;font-size:12px}.engineering-ledger h1{margin:4px 0;color:#1f2d3d;font-size:24px}.engineering-ledger p{margin:0;color:#718096;font-size:13px}.engineering-ledger__actions,.engineering-ledger__filters{display:flex;gap:8px;align-items:center}.engineering-ledger button{display:inline-flex;align-items:center;gap:5px;border:1px solid #d5dfe8;border-radius:5px;padding:7px 11px;background:#fff;color:#52697d;font:inherit;cursor:pointer}.engineering-ledger button:disabled{cursor:not-allowed;opacity:.45}.engineering-ledger button.primary{border-color:#1769aa;background:#1769aa;color:#fff}.engineering-ledger__tabs{display:flex;gap:20px;margin-top:22px;border-bottom:1px solid #dfe6ed}.engineering-ledger__tabs button{border:0;border-bottom:2px solid transparent;border-radius:0;padding:10px 4px;background:transparent}.engineering-ledger__tabs button.active{border-bottom-color:#1769aa;color:#1769aa;font-weight:700}.engineering-ledger__filters{justify-content:flex-start;flex-wrap:wrap;margin-top:14px;padding:12px;border:1px solid #dfe6ed;border-radius:6px;background:#fff}.engineering-ledger__filters label{display:flex;align-items:center;gap:7px;color:#607386;font-size:12px}.engineering-ledger input{min-height:32px;border:1px solid #d7e0e8;border-radius:4px;padding:5px 8px;font:inherit}.engineering-ledger__notice,.engineering-ledger__error{max-width:1440px;margin:10px auto}.engineering-ledger__notice{color:#2f7a68!important}.engineering-ledger__error{color:#b34d40!important}.engineering-ledger__table-wrap{overflow:auto;margin-top:12px;border:1px solid #dfe6ed;border-radius:6px;background:#fff}.engineering-ledger table{width:100%;min-width:900px;border-collapse:collapse}.engineering-ledger th,.engineering-ledger td{padding:11px 12px;border-bottom:1px solid #edf1f4;text-align:left;font-size:12px;white-space:nowrap}.engineering-ledger th{background:#f8fafc;color:#74869a}.engineering-ledger td{color:#40566b}.engineering-ledger .link{border:0;padding:0;background:transparent;color:#1769aa}.engineering-ledger .link--danger{margin-left:10px;color:#c65a4d}.engineering-ledger__pagination{display:flex;align-items:center;justify-content:flex-end;gap:9px;padding:10px 12px;background:#fbfcfd;color:#6e8092;font-size:12px}.state{padding:56px;text-align:center;color:#8392a1;font-size:13px}.engineering-ledger__editor{position:fixed;z-index:1200;inset:0;display:grid;place-items:center;padding:20px;background:rgba(25,40,56,.32)}.engineering-ledger__editor-card{width:min(760px,100%);max-height:90vh;overflow:auto;border-radius:7px;background:#fff;box-shadow:0 16px 50px rgba(26,42,60,.22)}.engineering-ledger__editor-card header,.engineering-ledger__editor-card footer{display:flex;justify-content:space-between;align-items:center;padding:15px 18px;border-bottom:1px solid #edf1f4}.engineering-ledger__editor-card footer{justify-content:flex-end;gap:8px;border-top:1px solid #edf1f4;border-bottom:0}.engineering-ledger__editor-card h2{margin:0;color:#26394d;font-size:17px}.engineering-ledger__form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;padding:18px}.engineering-ledger__form label{display:grid;gap:5px;color:#62768b;font-size:12px}.engineering-ledger__form input{width:100%}@media(max-width:700px){.engineering-ledger{padding:14px}.engineering-ledger__header{display:block}.engineering-ledger__actions{margin-top:12px}.engineering-ledger__form{grid-template-columns:1fr}}
</style>

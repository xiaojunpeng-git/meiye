<script setup>
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import MemberListView from './MemberListView.vue'
import MemberDetailOverlay from '@/components/member/MemberDetailOverlay.vue'
import QueryEntitySelectorOverlay from '@/components/query/QueryEntitySelectorOverlay.vue'
import MemberSelectorOverlay from '@/components/common/MemberSelectorOverlay.vue'
import OrganizationStoreScopePicker from '@/components/OrganizationStoreScopePicker.vue'

const ready = ref(false), error = ref(''), boot = ref(null), selectedStores = ref([]), scopeLabel = ref('当前权限范围'), targetStore = ref('')
const state = reactive({ stateContextId: '', memberCenter: {}, storeName: '' })
const permissions = reactive({ create: false, edit: false, deactivate: false })
const revisions = new Map()
const detail = ref(null), detailLoading = ref(false), detailError = ref('')
const person = ref(null), personPage = reactive({ records: [], total: 0, page: 1, pageSize: 20, error: '', loading: false })
const referrer = ref(null), referrerPage = reactive({ records: [], total: 0, page: 1, pageSize: 20, loading: false })
let editingStore = 0, preferenceVersion = 0
function token() { const match = document.cookie.match(/(?:^|;\s*)admin-token=([^;]*)/); return match ? decodeURIComponent(match[1]) : '' }
async function api(path, body = null) {
  const response = await fetch('/adminapi/user/member-center/' + path, { method: body ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store', headers: { 'Content-Type': 'application/json', 'Authori-zation': `Bearer ${token()}` }, ...(body ? { body: JSON.stringify(body) } : {}) })
  const json = await response.json()
  if (!response.ok || Number(json.status) !== 200) throw new Error(json.msg || '会员操作失败')
  return json.data
}
// 独立平台上下文不写入门店桥接全局状态，不接收门店员工 token。
async function request(action, input = {}) {
  const payload = { ...input, pageCode: 'platform_member_list', selectedStoreIds: [...selectedStores.value] }
  if (action === 'create-member') payload.targetStoreId = Number(targetStore.value)
  if (action === 'query-query-entities') payload.targetStoreId = editingStore || Number(targetStore.value)
  if (input.memberId && revisions.has(Number(input.memberId))) payload.memberRevision = revisions.get(Number(input.memberId))
  // 查询设置使用独立平台页面版本；幂等键转成固定格式，重试仍映射同一键。
  if (Array.isArray(payload.commandContexts)) payload.commandContexts = payload.commandContexts.map(context => context.kind === 'query_preference' ? { ...context, expectedVersion: preferenceVersion } : context)
  if (!action.startsWith('query-') && !action.startsWith('open-') && action !== 'load-member-detail-tab') {
    const sourceKey = input.idempotencyKey || crypto.randomUUID()
    payload.requestId = Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(sourceKey)))).map(b => b.toString(16).padStart(2, '0')).join('')
  }
  const result = await api('action', { action, payload })
  const version = result?.versions?.find(row => row.kind === 'query_preference' && row.id === 'platform_member_list')
  if (version) preferenceVersion = Number(version.version)
  const member = result?.data?.member
  if (member?.memberRevision) { revisions.set(Number(member.memberId || member.id), member.memberRevision); editingStore = Number(member.storeId) || 0 }
  return result
}
async function init() {
  error.value = ''; ready.value = false
  try {
    const data = await api('bootstrap'); boot.value = data; Object.assign(permissions, data.permissions)
    adapter.creatorSchema = data.creatorSchema || {}
    targetStore.value = data.stores.length === 1 ? String(data.stores[0].id) : ''
    state.stateContextId = data.stateContextId
    ready.value = true
  } catch (e) { error.value = e.message }
}
function changeScope(ids, label) {
  selectedStores.value = [...ids]; scopeLabel.value = label || '当前权限范围'
  state.stateContextId = `${boot.value.stateContextId}:${ids.join(',')}`
  if (ids.length === 1) targetStore.value = String(ids[0])
}
async function openDetail(record) {
  detailLoading.value = true; detailError.value = ''
  try { const result = await request('open-member-detail', { memberId: Number(record.memberId || record.id) }); detail.value = result.data.detail }
  catch (e) { detailError.value = e.message }
  finally { detailLoading.value = false }
}
async function loadDetailTab(query) {
  if (!detail.value) return
  detailLoading.value = true; detailError.value = ''
  try { const r = await request('load-member-detail-tab', { memberId: Number(detail.value.member?.memberId || detail.value.member?.id), ...query, tab: query.tab || query.activeTab }); detail.value = r.data.detail }
  catch (e) { detailError.value = e.message }
  finally { detailLoading.value = false }
}
// 热更新会把 document.referrer 改为子页自身；宿主显式传递回传地址并限制同源/固定本地开发配对。
function platformHostOrigin() {
  const own = window.location.origin
  const candidate = new URL(window.location.href).searchParams.get('platformHostOrigin') || (document.referrer ? new URL(document.referrer).origin : own)
  return candidate === own || (own === 'http://127.0.0.1:18091' && candidate === 'http://127.0.0.1:18081') ? candidate : ''
}
function transfer(record) {
  const parentOrigin = platformHostOrigin()
  if (window.parent === window || !parentOrigin) { error.value = '请从平台用户列表打开调店功能'; return }
  window.parent.postMessage({ type: 'platform-member:transfer', member: { uid: Number(record.memberId || record.id), nickname: record.name, belong_store_id: Number(record.storeId || 0), belong_store_name: record.storeName || '' } }, parentOrigin)
}
function selectPeople(selected) {
  return new Promise(resolve => { person.value = { selected, resolve }; queryPeople({ page: 1 }).catch(() => {}) })
}
async function queryPeople(query = {}) {
  personPage.loading = true; personPage.error = ''
  try { const r = await request('query-query-entities', { entityType: 'person', selectorEntry: 'member_exclusive_service_staff', selectorContext: { storeScope: 'current_store', memberCreate: true }, ...query }); Object.assign(personPage, r.data); return r.data }
  catch (e) { personPage.error = e.message; throw e }
  finally { personPage.loading = false }
}
function choosePeople(records) { person.value.resolve(records); person.value = null; return { success: true } }
function closePeople() { person.value?.resolve(null); person.value = null }
function selectReferrer(selected, exclude) { return new Promise(resolve => { referrer.value = { selected, exclude, resolve }; queryReferrers({ page: 1 }).catch(e => { error.value = e.message }) }) }
async function queryReferrers(query = {}) {
  referrerPage.loading = true
  try {
    const r = await request('query-members', { ...query, page: query.page || 1, limit: query.pageSize || 20 })
    const page = r.data.memberCenter || r.data
    Object.assign(referrerPage, { ...page, records: (page.records || []).map(row => ({ ...row, selectable: Number(row.memberId || row.id) !== Number(referrer.value?.exclude) })) })
    return referrerPage
  } finally { referrerPage.loading = false }
}
function chooseReferrer(record) { referrer.value.resolve(record); referrer.value = null; return { success: true } }
function closeReferrer() { referrer.value?.resolve(null); referrer.value = null }
const adapter = reactive({ state, permissions, creatorSchema: {}, request, token, openDetail, transfer, selectPeople, selectReferrer })
onMounted(init)
let refreshSequence = 0
function refreshFromParent(event) {
  const origin = platformHostOrigin()
  if (origin && event.source === window.parent && event.origin === origin && event.data?.type === 'platform-member:refresh') state.stateContextId = `${boot.value.stateContextId}:refresh:${++refreshSequence}`
}
onMounted(() => window.addEventListener('message', refreshFromParent))
onBeforeUnmount(() => { window.removeEventListener('message', refreshFromParent); closePeople(); closeReferrer() })
</script>

<template>
  <section class="platform-member-list">
    <p v-if="error" role="alert">{{ error }} <button type="button" @click="init">重新读取</button></p>
    <template v-if="ready">
      <div class="platform-member-list__scope">
        <OrganizationStoreScopePicker v-model="selectedStores" :tree="boot.tree" :allowed-store-ids="boot.stores.map(s => Number(s.id))" :label="scopeLabel" @change="changeScope" />
        <label v-if="permissions.create">新增归属门店 <select v-model="targetStore" @change="state.storeName = boot.stores.find(s => Number(s.id) === Number(targetStore))?.name || ''"><option value="">请选择门店</option><option v-for="store in boot.stores" :key="store.id" :value="String(store.id)">{{ store.name }}</option></select></label>
      </div>
      <MemberListView :platform-adapter="adapter" />
    </template>
    <p v-else-if="!error" role="status">正在读取平台会员管理…</p>
    <MemberDetailOverlay v-if="detail" :detail="detail" :is-loading="detailLoading" :load-error="detailError" @tab-change="loadDetailTab" @close="detail = null" />
    <QueryEntitySelectorOverlay v-if="person" entity-type="person" title="选择专属服务人" multiple :selected-records="person.selected" :records="personPage.records" :total="personPage.total" :page="personPage.page" :page-size="personPage.pageSize" :load-error="personPage.error" :is-loading="personPage.loading" :on-query="queryPeople" :on-select="choosePeople" @close="closePeople" />
    <MemberSelectorOverlay v-if="referrer" title="选择推荐人" :records="referrerPage.records" :total="referrerPage.total" :page="referrerPage.page" :page-size="referrerPage.pageSize" :is-loading="referrerPage.loading" :on-query="queryReferrers" :on-select="chooseReferrer" @close="closeReferrer" />
  </section>
</template>

<style scoped>
.platform-member-list{height:100%;min-height:0;display:flex;flex-direction:column;padding:12px 16px;box-sizing:border-box;background:#f5f7fa;color:#334155}.platform-member-list__scope{display:flex;align-items:center;flex-wrap:wrap;gap:20px;padding:12px;background:#fff;border:1px solid #e1e6ed;border-bottom:0;border-radius:6px 6px 0 0}.platform-member-list__scope label{display:flex;align-items:center;gap:10px;font-size:13px}.platform-member-list__scope select{height:34px;min-width:170px;border:1px solid #dce3ed;border-radius:5px;padding:0 8px;color:#334155;background:#fff}.platform-member-list :deep(.member-list-page){flex:1;min-height:0}.platform-member-list>p{padding:16px}
</style>

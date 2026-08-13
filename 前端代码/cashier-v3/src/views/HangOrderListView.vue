<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import TablePagination from '@/components/common/TablePagination.vue'
import UnifiedQueryToolbar from '@/components/query/UnifiedQueryToolbar.vue'
import { formatMoney, requestCashierV3Action, useCashierV3State } from '@/services/cashierV3Bridge'
import { deleteHangDraft, resumeHangDraft } from '@/services/hangDraftApi.js'

const state = useCashierV3State()
const router = useRouter()
const queryResult = ref(null)
const hangOrders = computed(() => queryResult.value || state.hangOrders || {})
const records = computed(() => Array.isArray(hangOrders.value.records) ? hangOrders.value.records : [])
const statusOptions = computed(() => Array.isArray(hangOrders.value.statusOptions) ? hangOrders.value.statusOptions : [])
const querySettings = ref({})
const queryModel = ref({})
const isVoiding = ref(false)
const total = computed(() => Math.max(Number(hangOrders.value.total) || 0, records.value.length))
const page = computed(() => Math.max(1, Number(hangOrders.value.page) || 1))
const pageSize = computed(() => Math.max(1, Number(hangOrders.value.pageSize) || 20))

const queryFields = [
  { key: 'hang_at', label: '挂单时间', defaultVisible: true, defaultQuick: true, type: 'date' },
  { key: 'room_name', label: '房间', defaultVisible: true, defaultQuick: true },
  { key: 'member_name', label: '会员', defaultVisible: true, defaultQuick: true },
  { key: 'phone', label: '手机号', defaultVisible: true },
  { key: 'item_count', label: '商品数量', defaultVisible: true, type: 'number' },
  { key: 'receivable_amount', label: '应收金额', defaultVisible: true, type: 'number' },
  { key: 'operator', label: '操作人', defaultVisible: true, type: 'person' },
  { key: 'order_note', label: '订单备注', defaultVisible: true },
  { key: 'business_status', label: '业务状态', defaultVisible: false }
]

const visibleHangFields = computed(() => {
  const availableFields = new Map(queryFields.map((field) => [field.key, field]))
  const configuredFields = querySettings.value?.visibleFields
  const source = Array.isArray(configuredFields)
    ? configuredFields
    : queryFields.filter((field) => field.defaultVisible !== false).map((field) => field.key)
  const seen = new Set()

  return source.reduce((fields, key) => {
    if (seen.has(key) || !availableFields.has(key)) return fields
    seen.add(key)
    fields.push(availableFields.get(key))
    return fields
  }, [])
})

const showsBusinessStatusColumn = computed(() => visibleHangFields.value.some((field) => field.key === 'business_status'))

watch(
  () => hangOrders.value.querySettings,
  (settings) => {
    querySettings.value = settings && typeof settings === 'object' ? { ...settings } : {}
  },
  { immediate: true, deep: true }
)

function statusClass(status) {
  if (status === '待结账') return 'hang-status--checkout'
  if (status === '服务中') return 'hang-status--serving'
  return 'hang-status--normal'
}

function applyQuerySettings(settings = {}) {
  querySettings.value = settings && typeof settings === 'object' ? { ...settings } : {}
}

function hangFieldValue(record, key) {
  const fieldValues = {
    hang_at: record.hangAt,
    room_name: record.roomName,
    member_name: record.memberName,
    phone: record.phone,
    item_count: record.itemCount,
    receivable_amount: record.receivableAmount,
    operator: record.operator,
    order_note: record.orderNote,
    business_status: record.status
  }
  return fieldValues[key]
}

function displayHangField(record, key) {
  const value = hangFieldValue(record, key)
  if (key === 'member_name') return value || '游客'
  if (key === 'item_count') return value ?? 0
  return value || '—'
}

function hangCellClass(key) {
  return {
    'hang-order-list-table__money': key === 'receivable_amount',
    'hang-order-list-table__note': key === 'order_note'
  }
}

function commandPayload(record) {
  return {
    hangOrderId: record.id
  }
}

async function requestAction(action, payload = {}) {
  return requestCashierV3Action(action, payload)
}

function resultStatus(result) {
  const response = result?.result && typeof result.result === 'object'
    ? result
    : result?.data?.result && typeof result.data.result === 'object'
      ? result.data
      : result
  return String(response?.result?.status || response?.status || '').toLowerCase()
}

async function resumeHangOrder(record) {
  if (record?.canResume !== true) return null
  try {
    const saved = await resumeHangDraft({
      hangOrderId: record.id,
      stateContextId: String(state.stateContextId || '')
    })
    if (saved?.cashierDraft) {
      // 路由切换会卸载本页，先把一次性恢复结果交给收银页，再跳转。
      window.__cashierV3PendingHangDraft = saved
    }
    await router.push({ name: 'cashier-v3-cashier' })
    if (saved?.cashierDraft) {
      window.dispatchEvent(new CustomEvent('cashier-v3:hang-draft-restored', { detail: saved }))
    }
    return { result: { status: 'succeeded' }, data: saved }
  } catch (error) {
    throw error
  }
}

async function deleteHangOrder(record) {
  if (!record?.id || isVoiding.value) return null
  isVoiding.value = true
  const current = hangOrders.value
  if (current && Array.isArray(current.records)) {
    queryResult.value = {
      ...current,
      records: current.records.filter((item) => item?.id !== record.id),
      total: Math.max(0, (Number(current.total) || current.records.length) - 1)
    }
  }
  try {
    return await deleteHangDraft(record.id)
  } catch (error) {
    await queryHangOrders({}, false)
    throw error
  } finally {
    isVoiding.value = false
  }
}

function responseData(result) {
  const response = result?.result && typeof result.result === 'object'
    ? result
    : result?.data?.result && typeof result.data.result === 'object'
      ? result.data
      : result
  return response?.data && typeof response.data === 'object' ? response.data : {}
}

async function queryHangOrders(query = {}, resetPage = true) {
  const nextQuery = {
    ...queryModel.value,
    ...query,
    page: resetPage ? 1 : Math.max(1, Number(query.page) || page.value),
    pageSize: Math.max(1, Number(query.pageSize) || pageSize.value)
  }
  queryModel.value = nextQuery
  const result = await requestAction('query-hang-orders', nextQuery)
  const payload = responseData(result).hangOrders
  if (payload && typeof payload === 'object' && Array.isArray(payload.records)) {
    queryResult.value = payload
  }
  return result
}

onMounted(() => {
  queryHangOrders({}, false)
})
</script>

<template>
  <section class="hang-order-page" aria-label="挂单列表">
    <UnifiedQueryToolbar
      search-placeholder="搜索会员姓名、手机号、挂单备注或商品"
      :status-options="statusOptions"
      :fields="queryFields"
      :settings="querySettings"
      default-sort-field="挂单时间"
      lock-store-selector
      :current-store="state.currentStore"
      @query="queryHangOrders"
      :on-save-settings="(settings) => requestAction('save-hang-order-query-settings', { settings })"
      @settings-applied="applyQuerySettings"
    />

    <main class="hang-order-list-wrap">
      <table class="hang-order-list-table" :style="{ '--hang-column-count': visibleHangFields.length }">
        <thead>
          <tr>
            <th v-for="field in visibleHangFields" :key="field.key">{{ field.label }}</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="record in records" :key="record.id">
            <td v-for="field in visibleHangFields" :key="field.key" :class="hangCellClass(field.key)">
              <template v-if="field.key === 'hang_at'">
                <strong>{{ displayHangField(record, field.key) }}</strong>
                <span v-if="!showsBusinessStatusColumn" class="hang-status" :class="statusClass(record.status)">{{ displayHangField(record, 'business_status') }}</span>
              </template>
              <span v-else-if="field.key === 'receivable_amount'">{{ formatMoney(hangFieldValue(record, field.key)) }}</span>
              <span v-else-if="field.key === 'business_status'" class="hang-status" :class="statusClass(record.status)">{{ displayHangField(record, field.key) }}</span>
              <span v-else>{{ displayHangField(record, field.key) }}</span>
            </td>
            <td>
              <div class="hang-order-row-actions">
                <button
                  v-if="record.canResume === true"
                  type="button"
                  class="button button--primary"
                  title="恢复挂单并返回收银台"
                  @click="resumeHangOrder(record)"
                >提单</button>
                <button type="button" class="button button--text hang-order-row-actions__danger" :disabled="isVoiding" @click="deleteHangOrder(record)">删除</button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
      <div v-if="!records.length" class="hang-order-list-empty">暂无有效挂单。请在收银台先选择商品后点击“挂单”。</div>
    </main>
    <TablePagination :total="total" :page="page" :page-size="pageSize" @change="(pagination) => queryHangOrders(pagination, false)" />
  </section>
</template>

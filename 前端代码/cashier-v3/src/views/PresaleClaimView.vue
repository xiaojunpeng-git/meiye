<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import FileClock from '@lucide/vue/dist/esm/icons/file-clock.mjs'
import PackageCheck from '@lucide/vue/dist/esm/icons/package-check.mjs'
import X from '@lucide/vue/dist/esm/icons/x.mjs'
import { UnifiedQueryDateRange } from '@mohe/unified-query-vue3'
import '@mohe/unified-query-vue3/styles.css'
import {
  createStorePresaleClaim,
  listStorePresaleClaimRecords,
  listStorePresaleClaims,
  readStorePresaleClaim,
  updateStorePresaleClaimRemark,
  voidStorePresaleClaim,
  voidStorePresaleClaimable
} from '@/services/presaleClaimApi'
import { canUseCashierV3Operation } from '@/services/cashierV3Bridge'

const rows = ref([])
const route = useRoute()
const activeTab = computed(() => route.query.tab === 'records' ? 'records' : 'claimable')
const total = ref(0)
const page = ref(1)
const loading = ref(false)
const keyword = ref('')
const status = ref('AVAILABLE')
const sourceKind = ref('PRESALE')
// 可领用余额必须跨销售日期完整展示；仅查询非可领用记录时按销售日筛选，默认当天。
const salesDateRange = ref({ min: today(), max: today() })
const feedback = ref('')
const selectedLine = ref(null)
const detail = ref({ claimable: null, claims: [] })
const detailOpen = ref(false)
const detailLoading = ref(false)
const claimOpen = ref(false)
const claimQuantity = ref(1)
const claimDate = ref(today())
const claimRemark = ref('')
const claimSubmitting = ref(false)
const remarkOpen = ref(false)
const remarkTarget = ref(null)
const editedRemark = ref('')
const remarkSubmitting = ref(false)
const remarkError = ref('')
const claimFailure = ref('')
const voidOpen = ref(false)
const voidTarget = ref(null)
const voidReason = ref('')
const voidSubmitting = ref(false)
const sourceVoidOpen = ref(false)
const sourceVoidTarget = ref(null)
const sourceVoidReason = ref('')
const sourceVoidSubmitting = ref(false)
const sourceVoidError = ref('')
const recordRows = ref([])
const recordTotal = ref(0)
const recordPage = ref(1)
const recordLoading = ref(false)
const recordKeyword = ref('')
const recordSourceKind = ref('')
const recordStatus = ref('')
const recordDateRange = ref({ min: '', max: '' })
const selectedRecord = ref(null)
const recordDetailOpen = ref(false)
const recordDetailLoading = ref(false)
const recordDetail = ref(null)

const pageSize = 20
const statusOptions = Object.freeze([
  { value: '', label: '全部状态' },
  { value: 'AVAILABLE', label: '可领用' },
  { value: 'FULLY_CLAIMED', label: '已全部领用' },
  { value: 'CLOSED_AFTER_SALE_REVERSAL', label: '已关闭' },
  { value: 'VOIDED', label: '已作废' }
])
const shouldShowSalesDateFilter = computed(() => status.value !== 'AVAILABLE')
const canPreviousPage = computed(() => page.value > 1)
const canNextPage = computed(() => page.value * pageSize < total.value)
const totalPages = computed(() => Math.max(1, Math.ceil(total.value / pageSize)))
const recordTotalPages = computed(() => Math.max(1, Math.ceil(recordTotal.value / pageSize)))
const sourceLabel = computed(() => sourceKind.value === 'GIFT' ? '赠送产品' : '预售商品')
const sourceDocumentLabel = computed(() => sourceKind.value === 'GIFT' ? '赠送单号' : '预售订单')
const sourceQuantityLabel = computed(() => sourceKind.value === 'GIFT' ? '赠送' : '预售')
const outboundDocumentLabel = computed(() => sourceKind.value === 'GIFT' ? '赠送产品出库单' : '预售领用出库单')
const canClaim = computed(() => canUseCashierV3Operation('cashier.v3.inventory.presale_claim.create'))
const canViewClaimDetail = computed(() => canUseCashierV3Operation('cashier.v3.inventory.presale_claim.detail'))
const canVoidClaim = computed(() => canUseCashierV3Operation('cashier.v3.inventory.presale_claim.void'))

function today() {
  const date = new Date()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${date.getFullYear()}-${month}-${day}`
}

function statusLabel(value) {
  return ({ AVAILABLE: '可领用', FULLY_CLAIMED: '已全部领用', CLOSED: '已关闭', CLOSED_AFTER_SALE_REVERSAL: '已关闭', SETTLED: '已领用', VOIDED: '已作废' })[value] || '-'
}

function timestamp(value) {
  const seconds = Number(value || 0)
  if (!seconds) return '-'
  const date = new Date(seconds * 1000)
  const pad = (item) => String(item).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`
}

function showFeedback(message) {
  feedback.value = String(message || '')
  window.setTimeout(() => { feedback.value = '' }, 3500)
}

async function load(nextPage = page.value) {
  loading.value = true
  try {
    const result = await listStorePresaleClaims({
      page: Math.max(1, Number(nextPage) || 1), limit: pageSize,
      keyword: keyword.value.trim(), status: status.value, source_kind: sourceKind.value,
      start_date: shouldShowSalesDateFilter.value ? salesDateRange.value.min : '',
      end_date: shouldShowSalesDateFilter.value ? salesDateRange.value.max : ''
    })
    rows.value = Array.isArray(result?.list) ? result.list : []
    total.value = Math.max(0, Number(result?.count || 0))
    page.value = Math.max(1, Number(result?.page || nextPage) || 1)
  } catch (error) {
    showFeedback(error?.message || `${sourceLabel.value}加载失败。`)
  } finally {
    loading.value = false
  }
}

function query() { load(1) }
/** Claim records use their own business date and filters; the source balance view stays intact. */
async function loadRecords(nextPage = recordPage.value) {
  if (!canViewClaimDetail.value) return
  recordLoading.value = true
  try {
    const result = await listStorePresaleClaimRecords({
      page: Math.max(1, Number(nextPage) || 1), limit: pageSize,
      keyword: recordKeyword.value.trim(), source_kind: recordSourceKind.value,
      status: recordStatus.value, start_date: recordDateRange.value.min,
      end_date: recordDateRange.value.max
    })
    recordRows.value = Array.isArray(result?.list) ? result.list : []
    recordTotal.value = Math.max(0, Number(result?.count || 0))
    recordPage.value = Math.max(1, Number(result?.page || 1))
  } catch (error) {
    showFeedback(error?.message || '领用记录加载失败。')
  } finally {
    recordLoading.value = false
  }
}

function queryRecords() { loadRecords(1) }
function resetRecords() {
  recordKeyword.value = ''
  recordSourceKind.value = ''
  recordStatus.value = ''
  recordDateRange.value = { min: '', max: '' }
  loadRecords(1)
}
function applyRecordDateRange(value) {
  recordDateRange.value = { min: String(value?.min || ''), max: String(value?.max || '') }
  loadRecords(1)
}

/** Re-read the selected outbound through the existing source-scoped detail permission. */
async function openRecordDetail(record) {
  if (!canViewClaimDetail.value) return
  selectedRecord.value = record
  recordDetail.value = null
  recordDetailOpen.value = true
  recordDetailLoading.value = true
  try {
    const result = await readStorePresaleClaim(record.claimable_line_id)
    const claim = (result?.claims || []).find((item) => item.claim_id === record.claim_id)
    if (!claim) throw new Error('该领用记录不存在或当前无权查看。')
    recordDetail.value = { ...record, ...claim, source: result.claimable }
  } catch (error) {
    showFeedback(error?.message || '领用记录详情加载失败。')
  } finally {
    recordDetailLoading.value = false
  }
}
function closeRecordDetail() { recordDetailOpen.value = false }
/** 确认后的周期用于服务端销售日筛选，清空周期则恢复不限日期。 */
function applySalesDateRange(value) {
  salesDateRange.value = { min: String(value?.min || ''), max: String(value?.max || '') }
  load(1)
}
function switchSource(nextSource) {
  if (!['PRESALE', 'GIFT'].includes(nextSource) || sourceKind.value === nextSource) return
  sourceKind.value = nextSource
  status.value = 'AVAILABLE'
  salesDateRange.value = { min: today(), max: today() }
  load(1)
}
function reset() {
  keyword.value = ''
  status.value = 'AVAILABLE'
  sourceKind.value = 'PRESALE'
  salesDateRange.value = { min: today(), max: today() }
  load(1)
}

function openClaim(line) {
  if (!canClaim.value) return
  selectedLine.value = line
  claimQuantity.value = 1
  claimDate.value = today()
  claimRemark.value = ''
  claimOpen.value = true
}

function closeClaim() {
  if (!claimSubmitting.value) claimOpen.value = false
}

function closeClaimFailure() {
  claimFailure.value = ''
}

async function submitClaim() {
  const line = selectedLine.value
  const quantity = Number(claimQuantity.value || 0)
  if (!line || !Number.isInteger(quantity) || quantity < 1 || quantity > Number(line.unclaimed_quantity || 0)) {
    showFeedback('领用数量必须在未领用数量范围内。')
    return
  }
  if (!/^\d{4}-\d{2}-\d{2}$/.test(claimDate.value)) {
    showFeedback('请选择正确的领用日期。')
    return
  }
  claimSubmitting.value = true
  try {
    await createStorePresaleClaim({
      claimable_line_id: line.claimable_line_id,
      quantity,
      business_date: claimDate.value,
      remark: claimRemark.value.trim()
    })
    claimOpen.value = false
    showFeedback(`领用成功，已生成${outboundDocumentLabel.value}。`)
    await load()
  } catch (error) {
    if (String(error?.code || '') === 'presale_claim_stock_insufficient') {
      claimFailure.value = '默认门店仓可用库存不足，请先完成入库后再领用。'
    } else {
      showFeedback(error?.message || '领用失败。')
    }
  } finally {
    claimSubmitting.value = false
  }
}

async function openDetail(line) {
  if (!canViewClaimDetail.value) return
  selectedLine.value = line
  detailOpen.value = true
  detailLoading.value = true
  detail.value = { claimable: null, claims: [] }
  try {
    detail.value = await readStorePresaleClaim(line.claimable_line_id)
  } catch (error) {
    showFeedback(error?.message || '领用明细加载失败。')
  } finally {
    detailLoading.value = false
  }
}

function closeDetail() { detailOpen.value = false }

/** Keep note edits attached to the selected outbound record, not to the source order. */
function openRemark(row) {
  if (!canClaim.value) return
  remarkTarget.value = row
  editedRemark.value = String(row.remark || '')
  remarkError.value = ''
  remarkOpen.value = true
}
function closeRemark() {
  if (!remarkSubmitting.value) remarkOpen.value = false
}
async function submitRemark() {
  if (!remarkTarget.value || editedRemark.value.trim().length > 500) {
    remarkError.value = '备注不能超过500字。'
    return
  }
  remarkError.value = ''
  remarkSubmitting.value = true
  try {
    await updateStorePresaleClaimRemark(remarkTarget.value.claim_id, {
      remark: editedRemark.value.trim(), expected_version: remarkTarget.value.remark_version
    })
    remarkOpen.value = false
    showFeedback('领用备注已保存。')
    if (selectedLine.value) await openDetail(selectedLine.value)
  } catch (error) {
    remarkError.value = error?.message || '备注保存失败，请刷新后重试。'
  } finally {
    remarkSubmitting.value = false
  }
}
function openVoid(row) {
  if (!canVoidClaim.value) return
  voidTarget.value = row
  voidReason.value = ''
  voidOpen.value = true
}
function closeVoid() {
  if (!voidSubmitting.value) voidOpen.value = false
}

/** Main-row void closes only future eligibility; settled outbound claims remain auditable. */
function openSourceVoid(row) {
  if (!canVoidClaim.value || row.claim_status === 'VOIDED') return
  sourceVoidTarget.value = row
  sourceVoidReason.value = ''
  sourceVoidError.value = ''
  sourceVoidOpen.value = true
}
function closeSourceVoid() {
  if (!sourceVoidSubmitting.value) sourceVoidOpen.value = false
}
async function submitSourceVoid() {
  if (!sourceVoidTarget.value || sourceVoidSubmitting.value) return
  sourceVoidSubmitting.value = true
  sourceVoidError.value = ''
  try {
    await voidStorePresaleClaimable(sourceVoidTarget.value.claimable_line_id, { reason: sourceVoidReason.value.trim() })
    sourceVoidOpen.value = false
    showFeedback('记录已作废；此前领用与出库保留，剩余数量不可再领用。')
    await load()
  } catch (error) {
    // Keep a failed confirmation visible in this modal; page-level feedback is
    // hidden behind its backdrop and would make the button look unresponsive.
    sourceVoidError.value = error?.message || '作废失败，请稍后重试。'
  } finally {
    sourceVoidSubmitting.value = false
  }
}

async function submitVoid() {
  const reason = voidReason.value.trim()
  if (!voidTarget.value || reason.length < 2) {
    showFeedback('请填写至少两个字的作废原因。')
    return
  }
  voidSubmitting.value = true
  try {
    await voidStorePresaleClaim(voidTarget.value.claim_id, { reason })
    voidOpen.value = false
    showFeedback('领用已作废，库存已按原批次退回。')
    if (detailOpen.value && selectedLine.value) await openDetail(selectedLine.value)
    if (recordDetailOpen.value && selectedRecord.value) await openRecordDetail(selectedRecord.value)
    await load()
    if (activeTab.value === 'records') await loadRecords()
  } catch (error) {
    showFeedback(error?.message || '作废失败。')
  } finally {
    voidSubmitting.value = false
  }
}

onMounted(() => activeTab.value === 'records' ? loadRecords(1) : load(1))
watch(activeTab, (nextTab) => { if (nextTab === 'records') loadRecords(1); else load(1) })
</script>

<template>
  <section class="presale-claim-view" aria-label="预售及赠送领用">
    <form v-if="activeTab === 'claimable'" class="presale-claim-filter" @submit.prevent="query">
      <div class="presale-claim-source-switch" role="group" aria-label="领用来源">
        <span class="presale-claim-source-switch__thumb" :class="{ 'is-gift': sourceKind === 'GIFT' }" aria-hidden="true" />
        <button type="button" :class="{ 'is-active': sourceKind === 'PRESALE' }" @click="switchSource('PRESALE')">预售</button>
        <button type="button" :class="{ 'is-active': sourceKind === 'GIFT' }" @click="switchSource('GIFT')">赠送</button>
      </div>
      <label>
        <span class="sr-only">{{ sourceLabel }}查询</span>
        <input v-model="keyword" type="search" placeholder="订单、会员、手机或商品" />
      </label>
      <label>
        <span class="sr-only">领用状态</span>
        <select v-model="status" @change="query">
          <option v-for="option in statusOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
        </select>
      </label>
      <div v-if="shouldShowSalesDateFilter" class="presale-claim-filter__period">
        <UnifiedQueryDateRange :model-value="salesDateRange" label="销售日期周期" @change="applySalesDateRange" />
      </div>
      <button class="button button--primary" type="submit">查询</button>
      <button class="button button--secondary" type="button" @click="reset">重置</button>
    </form>
    <form v-else class="presale-claim-filter presale-claim-filter--records" @submit.prevent="queryRecords">
      <label><span class="sr-only">领用记录查询</span><input v-model="recordKeyword" type="search" placeholder="出库单号、原订单、会员、手机或商品" /></label>
      <label><span class="sr-only">领用来源</span><select v-model="recordSourceKind" @change="queryRecords"><option value="">全部来源</option><option value="PRESALE">预售</option><option value="GIFT">赠送</option></select></label>
      <label><span class="sr-only">领用状态</span><select v-model="recordStatus" @change="queryRecords"><option value="">全部状态</option><option value="SETTLED">已领用</option><option value="VOIDED">已作废</option></select></label>
      <div class="presale-claim-filter__period"><UnifiedQueryDateRange :model-value="recordDateRange" label="领用日期周期" @change="applyRecordDateRange" /></div>
      <button class="button button--primary" type="submit">查询</button>
      <button class="button button--secondary" type="button" @click="resetRecords">重置</button>
    </form>

    <p v-if="feedback" class="presale-feedback" role="status">{{ feedback }}</p>

    <main v-if="activeTab === 'claimable'" class="presale-claim-table-wrap" :aria-busy="loading">
      <div class="presale-claim-table-scroll">
        <table class="presale-claim-table">
        <thead>
          <tr>
            <th>{{ sourceDocumentLabel }}</th><th>发生日期</th><th>会员</th><th>商品</th><th class="align-right">{{ sourceQuantityLabel }}</th><th class="align-right">已领用</th><th class="align-right">未领用</th><th>状态</th><th>操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in rows" :key="row.claimable_line_id">
            <td><strong>{{ row.presale_order_no }}</strong></td>
            <td>{{ row.sales_date }}</td>
            <td><strong>{{ row.member_name || '游客' }}</strong><small>{{ row.member_phone || '-' }}</small></td>
            <td>{{ row.product_name }}</td>
            <td class="align-right">{{ row.quantity }}</td>
            <td class="align-right">{{ row.claimed_quantity }}</td>
            <td class="align-right">{{ row.unclaimed_quantity }}</td>
            <td><span class="presale-status" :class="`presale-status--${String(row.claim_status || '').toLowerCase()}`">{{ statusLabel(row.claim_status) }}</span></td>
            <td class="presale-claim-table__actions">
              <button v-if="row.can_claim && canClaim" class="button button--text" type="button" @click="openClaim(row)">领用</button>
              <span v-else-if="row.claim_status !== 'VOIDED'" class="muted">不可领用</span>
              <button v-if="canViewClaimDetail" class="button button--text" type="button" @click="openDetail(row)">明细</button>
              <button v-if="row.claim_status !== 'VOIDED' && canVoidClaim" class="button button--text button--danger" type="button" @click="openSourceVoid(row)">作废</button>
            </td>
          </tr>
          <tr v-if="!loading && !rows.length"><td colspan="9" class="presale-empty">暂无{{ sourceLabel }}</td></tr>
        </tbody>
        </table>
      </div>
      <div class="presale-pagination">
        <span>共 {{ total }} 条 · 每页 {{ pageSize }} 条 · 第 {{ page }} / {{ totalPages }} 页</span>
        <button class="button button--secondary" type="button" :disabled="!canPreviousPage || loading" @click="load(page - 1)">上一页</button>
        <span>{{ page }}</span>
        <button class="button button--secondary" type="button" :disabled="!canNextPage || loading" @click="load(page + 1)">下一页</button>
      </div>
    </main>
    <main v-else class="presale-claim-table-wrap" :aria-busy="recordLoading">
      <div class="presale-claim-table-scroll">
        <table class="presale-claim-table presale-claim-record-table">
          <thead><tr><th>出库单号</th><th>来源</th><th>原订单号</th><th>领用日期</th><th>操作时间</th><th>会员</th><th>商品</th><th class="align-right">数量</th><th>操作人</th><th>状态</th><th>备注</th><th>操作</th></tr></thead>
          <tbody>
            <tr v-for="row in recordRows" :key="row.claim_id">
              <td><strong>{{ row.outbound_document_no }}</strong></td><td>{{ row.source_kind === 'GIFT' ? '赠送' : '预售' }}</td><td>{{ row.source_order_no || '-' }}</td>
              <td>{{ row.business_date }}</td><td>{{ timestamp(row.occurred_at) }}</td>
              <td><strong>{{ row.member_name || '游客' }}</strong><small>{{ row.member_phone || '-' }}</small></td>
              <td>{{ row.product_name }}</td><td class="align-right">{{ row.quantity }}</td><td>{{ row.operator_name || '-' }}</td>
              <td><span class="presale-status" :class="`presale-status--${String(row.status || '').toLowerCase()}`">{{ statusLabel(row.status) }}</span></td>
              <td class="presale-record-remark">{{ row.remark || '-' }}</td>
              <td class="presale-claim-table__actions"><button class="button button--text" type="button" @click="openRecordDetail(row)">详情</button><button v-if="row.status === 'SETTLED' && row.source_status !== 'VOIDED' && canVoidClaim" class="button button--text button--danger" type="button" @click="openVoid(row)">作废</button></td>
            </tr>
            <tr v-if="!recordLoading && !recordRows.length"><td colspan="12" class="presale-empty">暂无领用记录</td></tr>
          </tbody>
        </table>
      </div>
      <div class="presale-pagination">
        <span>共 {{ recordTotal }} 条 · 每页 {{ pageSize }} 条 · 第 {{ recordPage }} / {{ recordTotalPages }} 页</span>
        <button class="button button--secondary" type="button" :disabled="recordPage <= 1 || recordLoading" @click="loadRecords(recordPage - 1)">上一页</button>
        <span>{{ recordPage }}</span>
        <button class="button button--secondary" type="button" :disabled="recordPage >= recordTotalPages || recordLoading" @click="loadRecords(recordPage + 1)">下一页</button>
      </div>
    </main>

    <section v-if="claimOpen" class="presale-modal-backdrop" role="presentation" @click.self="closeClaim">
      <div class="presale-modal" role="dialog" aria-modal="true" aria-labelledby="presale-claim-title">
        <header><div><p>{{ sourceLabel }}</p><h2 id="presale-claim-title">领用</h2></div><button class="icon-button" title="关闭" type="button" @click="closeClaim"><X :size="18" /></button></header>
        <dl v-if="selectedLine" class="presale-modal__summary"><dt>{{ sourceDocumentLabel }}</dt><dd>{{ selectedLine.presale_order_no }}</dd><dt>商品</dt><dd>{{ selectedLine.product_name }}</dd><dt>未领用</dt><dd>{{ selectedLine.unclaimed_quantity }}</dd></dl>
        <label class="presale-modal__field">本次领用<input v-model.number="claimQuantity" type="number" min="1" :max="Number(selectedLine?.unclaimed_quantity || 1)" step="1" /></label>
        <label class="presale-modal__field">领用日期<input v-model="claimDate" type="date" /></label>
        <label class="presale-modal__field">备注<textarea v-model="claimRemark" rows="3" maxlength="500" placeholder="选填" /></label>
        <footer><button class="button button--secondary" type="button" @click="closeClaim">取消</button><button class="button button--primary" type="button" :disabled="claimSubmitting" @click="submitClaim">确认领用</button></footer>
      </div>
    </section>

    <section v-if="claimFailure" class="presale-modal-backdrop presale-modal-backdrop--alert" role="presentation" @click.self="closeClaimFailure">
      <div class="presale-modal presale-modal--alert" role="alertdialog" aria-modal="true" aria-labelledby="presale-claim-failure-title">
        <header><div><p>领用未提交</p><h2 id="presale-claim-failure-title">库存不足</h2></div></header>
        <p class="presale-alert-message">{{ claimFailure }}</p>
        <footer><button class="button button--primary" type="button" @click="closeClaimFailure">知道了</button></footer>
      </div>
    </section>

    <section v-if="detailOpen" class="presale-modal-backdrop" role="presentation" @click.self="closeDetail">
      <div class="presale-modal presale-modal--wide" role="dialog" aria-modal="true" aria-labelledby="presale-detail-title">
        <header><div><p>{{ sourceLabel }}</p><h2 id="presale-detail-title">领用明细</h2></div><button class="icon-button" title="关闭" type="button" @click="closeDetail"><X :size="18" /></button></header>
        <div v-if="detailLoading" class="presale-loading"><FileClock :size="22" />加载中...</div>
        <template v-else>
          <p v-if="detail.claimable" class="presale-detail-summary">{{ detail.claimable.product_name }}：{{ sourceQuantityLabel }} {{ detail.claimable.quantity }}，已领用 {{ detail.claimable.claimed_quantity }}，未领用 {{ detail.claimable.unclaimed_quantity }}</p>
          <p v-if="detail.claimable?.claim_status === 'VOIDED'" class="presale-void-note">该记录已作废，剩余未领用数量不可再操作；已领用出库保留。</p>
          <table class="presale-detail-table"><thead><tr><th>{{ outboundDocumentLabel }}</th><th class="align-right">数量</th><th>状态</th><th>操作人</th><th>领用时间</th><th>备注</th><th>作废时间</th><th>作废原因</th><th>操作</th></tr></thead><tbody>
            <tr v-for="row in detail.claims || []" :key="row.claim_id"><td>{{ row.claim_no }}</td><td class="align-right">{{ row.quantity }}</td><td>{{ statusLabel(row.status) }}</td><td>{{ row.operator_name }}</td><td>{{ timestamp(row.occurred_at) }}</td><td class="presale-detail-table__remark">{{ row.remark || '-' }}</td><td>{{ timestamp(row.voided_at) }}</td><td>{{ row.void_reason || '-' }}</td><td class="presale-detail-table__actions"><button v-if="detail.claimable?.claim_status !== 'VOIDED' && canClaim" class="button button--text" type="button" @click="openRemark(row)">修改备注</button><button v-if="detail.claimable?.claim_status !== 'VOIDED' && row.status === 'SETTLED' && canVoidClaim" class="button button--text button--danger" type="button" @click="openVoid(row)">作废领用</button><span v-if="detail.claimable?.claim_status === 'VOIDED' || row.status !== 'SETTLED'" class="muted">{{ detail.claimable?.claim_status === 'VOIDED' ? '记录已作废' : '已作废' }}</span></td></tr>
            <tr v-if="!(detail.claims || []).length"><td colspan="9" class="presale-empty">暂无领用记录</td></tr>
          </tbody></table>
        </template>
        <footer><button class="button button--secondary" type="button" @click="closeDetail">关闭</button></footer>
      </div>
    </section>

    <section v-if="recordDetailOpen" class="presale-modal-backdrop" role="presentation" @click.self="closeRecordDetail">
      <div class="presale-modal presale-modal--record" role="dialog" aria-modal="true" aria-labelledby="presale-record-detail-title">
        <header><div><p>领用记录</p><h2 id="presale-record-detail-title">出库详情</h2></div><button class="icon-button" title="关闭" type="button" @click="closeRecordDetail"><X :size="18" /></button></header>
        <div v-if="recordDetailLoading" class="presale-loading"><FileClock :size="22" />加载中...</div>
        <dl v-else-if="recordDetail" class="presale-modal__summary presale-record-summary">
          <dt>出库单号</dt><dd><strong>{{ recordDetail.outbound_document_no }}</strong></dd>
          <dt>来源</dt><dd>{{ recordDetail.source_kind === 'GIFT' ? '赠送' : '预售' }}</dd>
          <dt>原订单号</dt><dd>{{ recordDetail.source_order_no || '-' }}</dd>
          <dt>会员</dt><dd>{{ recordDetail.member_name || '游客' }} · {{ recordDetail.member_phone || '-' }}</dd>
          <dt>商品</dt><dd>{{ recordDetail.product_name }}</dd>
          <dt>领用数量</dt><dd>{{ recordDetail.quantity }}</dd>
          <dt>领用日期</dt><dd>{{ recordDetail.business_date }}</dd>
          <dt>操作时间</dt><dd>{{ timestamp(recordDetail.occurred_at) }}</dd>
          <dt>操作人</dt><dd>{{ recordDetail.operator_name || '-' }}</dd>
          <dt>状态</dt><dd>{{ statusLabel(recordDetail.status) }}</dd>
          <dt>备注</dt><dd>{{ recordDetail.remark || '-' }}</dd>
          <template v-if="recordDetail.status === 'VOIDED'"><dt>作废时间</dt><dd>{{ timestamp(recordDetail.voided_at) }}</dd><dt>作废原因</dt><dd>{{ recordDetail.void_reason || '-' }}</dd></template>
        </dl>
        <p v-else class="presale-empty">暂无可查看的记录</p>
        <footer><button class="button button--secondary" type="button" @click="closeRecordDetail">关闭</button></footer>
      </div>
    </section>

    <section v-if="remarkOpen" class="presale-modal-backdrop" role="presentation" @click.self="closeRemark">
      <div class="presale-modal" role="dialog" aria-modal="true" aria-labelledby="presale-remark-title">
        <header><div><p>{{ outboundDocumentLabel }}</p><h2 id="presale-remark-title">修改备注</h2></div><button class="icon-button" title="关闭" type="button" @click="closeRemark"><X :size="18" /></button></header>
        <p class="presale-void-note">{{ remarkTarget?.claim_no }}</p>
        <label class="presale-modal__field">备注<textarea v-model="editedRemark" rows="4" maxlength="500" placeholder="选填" /></label>
        <p v-if="remarkError" class="presale-alert-message" role="alert">{{ remarkError }}</p>
        <footer><button class="button button--secondary" type="button" @click="closeRemark">取消</button><button class="button button--primary" type="button" :disabled="remarkSubmitting" @click="submitRemark">保存</button></footer>
      </div>
    </section>

    <section v-if="voidOpen" class="presale-modal-backdrop" role="presentation" @click.self="closeVoid">
      <div class="presale-modal" role="dialog" aria-modal="true" aria-labelledby="presale-void-title">
        <header><div><p>领用出库单</p><h2 id="presale-void-title">作废领用</h2></div><button class="icon-button" title="关闭" type="button" @click="closeVoid"><X :size="18" /></button></header>
        <p class="presale-void-note">作废后会按本次领用时的原库存批次退回。</p>
        <label class="presale-modal__field">作废原因<textarea v-model.trim="voidReason" rows="3" maxlength="500" placeholder="请填写作废原因" /></label>
        <footer><button class="button button--secondary" type="button" @click="closeVoid">取消</button><button class="button button--danger" type="button" :disabled="voidSubmitting" @click="submitVoid">确认作废</button></footer>
      </div>
    </section>

    <section v-if="sourceVoidOpen" class="presale-modal-backdrop" role="presentation" @click.self="closeSourceVoid">
      <div class="presale-modal" role="dialog" aria-modal="true" aria-labelledby="presale-source-void-title">
        <header><div><p>{{ sourceLabel }}</p><h2 id="presale-source-void-title">作废记录</h2></div><button class="icon-button" title="关闭" type="button" @click="closeSourceVoid"><X :size="18" /></button></header>
        <p class="presale-void-note">{{ sourceVoidTarget?.presale_order_no }} · {{ sourceVoidTarget?.product_name }}。作废后不再领用，已领用出库保持原状。</p>
        <label class="presale-modal__field">作废说明（选填）<textarea v-model="sourceVoidReason" rows="3" maxlength="500" placeholder="选填" /></label>
        <p v-if="sourceVoidError" class="presale-alert-message" role="alert">{{ sourceVoidError }}</p>
        <footer><button class="button button--secondary" type="button" @click="closeSourceVoid">取消</button><button class="button button--danger" type="button" :disabled="sourceVoidSubmitting" @click="submitSourceVoid">确认作废</button></footer>
      </div>
    </section>
  </section>
</template>

<style scoped>
/* The cashier shell clips route overflow; keep pagination visible while the 20-row table scrolls. */
.presale-claim-view { display: flex; height: 100%; min-height: 0; flex-direction: column; padding: 24px; overflow: hidden; background: #f6f8fa; color: #17212b; }
.presale-detail-table__remark { min-width: 150px; max-width: 260px; white-space: pre-wrap; overflow-wrap: anywhere; }
.presale-detail-table__actions { white-space: nowrap; }
/* 已领用/已关闭记录按销售周期查询；可领用记录不按销售日缩小范围。 */
.presale-claim-filter__period { width: min(290px, 100%); }
.presale-modal header p { margin: 0 0 4px; color: #6b7785; font-size: 12px; }.presale-modal h2 { margin: 0; font-size: 22px; font-weight: 650; }.presale-claim-filter { display: flex; gap: 10px; align-items: center; margin-bottom: 14px; }.presale-claim-source-switch { position: relative; display: grid; grid-template-columns: repeat(2, 72px); isolation: isolate; min-height: 36px; padding: 2px; border: 1px solid #d7dee5; border-radius: 4px; background: #fff; }.presale-claim-source-switch__thumb { position: absolute; z-index: -1; inset: 2px auto 2px 2px; width: 72px; border-radius: 3px; background: #e8f3ee; transition: transform .18s ease; }.presale-claim-source-switch__thumb.is-gift { transform: translateX(72px); }.presale-claim-source-switch button { min-height: 30px; border: 0; border-radius: 3px; background: transparent; color: #687481; cursor: pointer; }.presale-claim-source-switch button.is-active { color: #155d50; font-weight: 600; }.presale-claim-filter input, .presale-claim-filter select, .presale-modal__field input, .presale-modal__field textarea { box-sizing: border-box; width: 100%; min-height: 36px; border: 1px solid #d7dee5; border-radius: 4px; padding: 7px 10px; background: #fff; color: inherit; }.presale-claim-filter input { width: 260px; }.presale-claim-filter select { width: 130px; }.presale-claim-filter__date { display: flex; align-items: center; gap: 6px; color: #53606d; font-size: 12px; white-space: nowrap; }.presale-claim-filter__date input { width: 142px; }.presale-feedback { margin: 0 0 12px; padding: 10px 12px; border-left: 3px solid #167d68; background: #edf8f4; color: #155d50; }.presale-claim-table-wrap { overflow: auto; background: #fff; border: 1px solid #e1e7ec; }.presale-claim-table, .presale-detail-table { width: 100%; min-width: 1000px; border-collapse: collapse; font-size: 13px; }.presale-claim-table th, .presale-claim-table td, .presale-detail-table th, .presale-detail-table td { padding: 12px; border-bottom: 1px solid #edf0f2; text-align: left; vertical-align: middle; }.presale-claim-table th, .presale-detail-table th { background: #f7f9fa; color: #53606d; font-weight: 600; white-space: nowrap; }.presale-claim-table small { display: block; margin-top: 3px; color: #768391; }.align-right { text-align: right !important; }.presale-claim-table__actions { white-space: nowrap; }.button--text { padding: 0; min-height: auto; border: 0; background: transparent; color: #167d68; cursor: pointer; }.button--text + .button--text { margin-left: 12px; }.button--danger { border-color: #c83c3c; background: #c83c3c; color: #fff; }.button--text.button--danger { color: #c83c3c; background: transparent; }.muted { color: #9aa5af; }.presale-status { display: inline-block; padding: 3px 7px; border-radius: 3px; background: #eff3f5; color: #687481; white-space: nowrap; }.presale-status--available { background: #e9f7f0; color: #167d68; }.presale-status--fully_claimed { background: #eaf2fb; color: #276ba7; }.presale-status--closed_after_sale_reversal { background: #f8eded; color: #9d3a3a; }.presale-pagination { display: flex; justify-content: flex-end; align-items: center; gap: 10px; padding: 12px; color: #65727e; }.presale-empty { padding: 32px !important; text-align: center !important; color: #7d8994; }.presale-modal-backdrop { position: fixed; z-index: 2000; inset: 0; display: grid; place-items: center; padding: 24px; background: rgba(23, 33, 43, .46); }.presale-modal-backdrop--alert { z-index: 2100; }.presale-modal { width: min(440px, 100%); max-height: calc(100vh - 48px); overflow: auto; padding: 20px; border-radius: 6px; background: #fff; box-shadow: 0 18px 56px rgba(10, 20, 30, .28); }.presale-modal--wide { width: min(1040px, 100%); }.presale-modal--alert { width: min(380px, 100%); }.presale-modal header { display: flex; justify-content: space-between; align-items: start; margin-bottom: 20px; }.icon-button { display: grid; width: 32px; height: 32px; place-items: center; border: 0; border-radius: 4px; background: transparent; color: #5a6875; cursor: pointer; }.icon-button:hover { background: #eef2f4; }.presale-modal__summary { display: grid; grid-template-columns: 84px 1fr; gap: 8px 12px; margin: 0 0 18px; }.presale-modal__summary dt { color: #71808d; }.presale-modal__summary dd { margin: 0; }.presale-modal__field { display: grid; gap: 7px; margin-bottom: 16px; color: #46535f; font-size: 13px; }.presale-modal footer { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; }.presale-detail-summary, .presale-void-note, .presale-alert-message { margin: 0 0 14px; padding: 10px; background: #f4f7f8; color: #475561; font-size: 13px; }.presale-alert-message { border-left: 3px solid #c83c3c; background: #fff4f4; color: #8a3030; }.presale-loading { display: grid; min-height: 160px; place-content: center; gap: 10px; color: #71808d; text-align: center; }@media (max-width: 760px) { .presale-claim-view { padding: 16px; }.presale-claim-filter { align-items: stretch; flex-wrap: wrap; }.presale-claim-source-switch { width: 148px; }.presale-claim-filter input { width: 100%; }.presale-claim-filter label:first-child { width: 100%; }.presale-claim-filter__date { flex: 1 1 100%; }.presale-claim-filter__date input { width: auto; flex: 1; }.presale-modal-backdrop { padding: 12px; }.presale-modal { padding: 16px; } }
.presale-claim-filter, .presale-feedback { flex: 0 0 auto; }
.presale-status--voided { background: #f8eded; color: #9d3a3a; }
.presale-claim-table-wrap { display: flex; min-height: 0; flex: 1; flex-direction: column; overflow: hidden; }
.presale-claim-table-scroll { min-height: 0; flex: 1; overflow: auto; }
.presale-pagination { flex: 0 0 auto; border-top: 1px solid #e1e7ec; background: #fff; }
.presale-modal.presale-modal--wide { width: min(1160px, 100%); }
/* The route owns its two tabs, while CashierShell retains the existing page header. */
:global(.cashier-page-identity > div.presale-header-identity) { display: flex; align-items: center; gap: 22px; }
:global(.presale-header-tabs) { display: flex; align-items: center; gap: 6px; }
:global(.presale-header-tabs button) { border: 0; border-bottom: 2px solid transparent; padding: 8px 12px; background: transparent; color: #667586; font: inherit; cursor: pointer; }
:global(.presale-header-tabs button.is-active) { border-bottom-color: #167d68; color: #155d50; font-weight: 650; }
.presale-claim-filter--records { flex-wrap: wrap; }
.presale-claim-filter--records input { width: min(320px, 100%); }
.presale-claim-record-table { min-width: 1450px; }
.presale-record-remark { max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.presale-modal--record { width: min(560px, 100%); }
.presale-record-summary { grid-template-columns: 100px minmax(0, 1fr); }
.presale-record-summary dd { overflow-wrap: anywhere; }
</style>

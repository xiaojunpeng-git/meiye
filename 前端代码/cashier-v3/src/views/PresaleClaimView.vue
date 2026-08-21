<script setup>
import { computed, onMounted, ref } from 'vue'
import FileClock from '@lucide/vue/dist/esm/icons/file-clock.mjs'
import PackageCheck from '@lucide/vue/dist/esm/icons/package-check.mjs'
import X from '@lucide/vue/dist/esm/icons/x.mjs'
import {
  createStorePresaleClaim,
  listStorePresaleClaims,
  readStorePresaleClaim,
  voidStorePresaleClaim
} from '@/services/presaleClaimApi'
import { canUseCashierV3Operation } from '@/services/cashierV3Bridge'

const rows = ref([])
const total = ref(0)
const page = ref(1)
const loading = ref(false)
const keyword = ref('')
const status = ref('AVAILABLE')
const sourceKind = ref('PRESALE')
const salesStartDate = ref('')
const salesEndDate = ref('')
const feedback = ref('')
const selectedLine = ref(null)
const detail = ref({ claimable: null, claims: [] })
const detailOpen = ref(false)
const detailLoading = ref(false)
const claimOpen = ref(false)
const claimQuantity = ref(1)
const claimDate = ref(today())
const claimSubmitting = ref(false)
const claimFailure = ref('')
const voidOpen = ref(false)
const voidTarget = ref(null)
const voidReason = ref('')
const voidSubmitting = ref(false)

const pageSize = 20
const statusOptions = Object.freeze([
  { value: '', label: '全部状态' },
  { value: 'AVAILABLE', label: '可领用' },
  { value: 'FULLY_CLAIMED', label: '已全部领用' },
  { value: 'CLOSED_AFTER_SALE_REVERSAL', label: '已关闭' }
])
const shouldShowSalesDateFilter = computed(() => status.value !== 'AVAILABLE')
const canPreviousPage = computed(() => page.value > 1)
const canNextPage = computed(() => page.value * pageSize < total.value)
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
      start_date: salesStartDate.value, end_date: salesEndDate.value
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
function switchSource(nextSource) {
  if (!['PRESALE', 'GIFT'].includes(nextSource) || sourceKind.value === nextSource) return
  sourceKind.value = nextSource
  status.value = 'AVAILABLE'
  salesStartDate.value = ''
  salesEndDate.value = ''
  load(1)
}
function reset() {
  keyword.value = ''
  status.value = 'AVAILABLE'
  sourceKind.value = 'PRESALE'
  salesStartDate.value = ''
  salesEndDate.value = ''
  load(1)
}

function openClaim(line) {
  if (!canClaim.value) return
  selectedLine.value = line
  claimQuantity.value = 1
  claimDate.value = today()
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
      business_date: claimDate.value
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
function openVoid(row) {
  if (!canVoidClaim.value) return
  voidTarget.value = row
  voidReason.value = ''
  voidOpen.value = true
}
function closeVoid() {
  if (!voidSubmitting.value) voidOpen.value = false
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
    if (selectedLine.value) await openDetail(selectedLine.value)
    await load()
  } catch (error) {
    showFeedback(error?.message || '作废失败。')
  } finally {
    voidSubmitting.value = false
  }
}

onMounted(() => load(1))
</script>

<template>
  <section class="presale-claim-view" aria-label="预售及赠送领用">
    <header class="presale-claim-view__header">
      <div>
        <p class="presale-claim-view__eyebrow">库存出库</p>
        <h1>客户领用</h1>
      </div>
    </header>

    <form class="presale-claim-filter" @submit.prevent="query">
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
      <template v-if="shouldShowSalesDateFilter">
        <label class="presale-claim-filter__date">
          <span>销售日期 从</span>
          <input v-model="salesStartDate" type="date" />
        </label>
        <label class="presale-claim-filter__date">
          <span>至</span>
          <input v-model="salesEndDate" type="date" />
        </label>
      </template>
      <button class="button button--primary" type="submit">查询</button>
      <button class="button button--secondary" type="button" @click="reset">重置</button>
    </form>

    <p v-if="feedback" class="presale-feedback" role="status">{{ feedback }}</p>

    <main class="presale-claim-table-wrap" :aria-busy="loading">
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
              <span v-else class="muted">不可领用</span>
              <button v-if="canViewClaimDetail" class="button button--text" type="button" @click="openDetail(row)">明细</button>
            </td>
          </tr>
          <tr v-if="!loading && !rows.length"><td colspan="9" class="presale-empty">暂无{{ sourceLabel }}</td></tr>
        </tbody>
      </table>
      <div class="presale-pagination">
        <span>共 {{ total }} 条</span>
        <button class="button button--secondary" type="button" :disabled="!canPreviousPage || loading" @click="load(page - 1)">上一页</button>
        <span>{{ page }}</span>
        <button class="button button--secondary" type="button" :disabled="!canNextPage || loading" @click="load(page + 1)">下一页</button>
      </div>
    </main>

    <section v-if="claimOpen" class="presale-modal-backdrop" role="presentation" @click.self="closeClaim">
      <div class="presale-modal" role="dialog" aria-modal="true" aria-labelledby="presale-claim-title">
        <header><div><p>{{ sourceLabel }}</p><h2 id="presale-claim-title">领用</h2></div><button class="icon-button" title="关闭" type="button" @click="closeClaim"><X :size="18" /></button></header>
        <dl v-if="selectedLine" class="presale-modal__summary"><dt>{{ sourceDocumentLabel }}</dt><dd>{{ selectedLine.presale_order_no }}</dd><dt>商品</dt><dd>{{ selectedLine.product_name }}</dd><dt>未领用</dt><dd>{{ selectedLine.unclaimed_quantity }}</dd></dl>
        <label class="presale-modal__field">本次领用<input v-model.number="claimQuantity" type="number" min="1" :max="Number(selectedLine?.unclaimed_quantity || 1)" step="1" /></label>
        <label class="presale-modal__field">领用日期<input v-model="claimDate" type="date" /></label>
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
          <table class="presale-detail-table"><thead><tr><th>{{ outboundDocumentLabel }}</th><th class="align-right">数量</th><th>状态</th><th>操作人</th><th>领用时间</th><th>作废时间</th><th>作废原因</th><th>操作</th></tr></thead><tbody>
            <tr v-for="row in detail.claims || []" :key="row.claim_id"><td>{{ row.claim_no }}</td><td class="align-right">{{ row.quantity }}</td><td>{{ statusLabel(row.status) }}</td><td>{{ row.operator_name }}</td><td>{{ timestamp(row.occurred_at) }}</td><td>{{ timestamp(row.voided_at) }}</td><td>{{ row.void_reason || '-' }}</td><td><button v-if="row.status === 'SETTLED' && canVoidClaim" class="button button--text button--danger" type="button" @click="openVoid(row)">作废领用</button><span v-else class="muted">已作废</span></td></tr>
            <tr v-if="!(detail.claims || []).length"><td colspan="8" class="presale-empty">暂无领用记录</td></tr>
          </tbody></table>
        </template>
        <footer><button class="button button--secondary" type="button" @click="closeDetail">关闭</button></footer>
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
  </section>
</template>

<style scoped>
.presale-claim-view { min-height: 100%; padding: 24px; background: #f6f8fa; color: #17212b; }
.presale-claim-view__header { display: flex; justify-content: space-between; align-items: end; margin-bottom: 18px; }.presale-claim-view__eyebrow, .presale-modal header p { margin: 0 0 4px; color: #6b7785; font-size: 12px; }.presale-claim-view h1, .presale-modal h2 { margin: 0; font-size: 22px; font-weight: 650; }.presale-claim-filter { display: flex; gap: 10px; align-items: center; margin-bottom: 14px; }.presale-claim-source-switch { position: relative; display: grid; grid-template-columns: repeat(2, 72px); isolation: isolate; min-height: 36px; padding: 2px; border: 1px solid #d7dee5; border-radius: 4px; background: #fff; }.presale-claim-source-switch__thumb { position: absolute; z-index: -1; inset: 2px auto 2px 2px; width: 72px; border-radius: 3px; background: #e8f3ee; transition: transform .18s ease; }.presale-claim-source-switch__thumb.is-gift { transform: translateX(72px); }.presale-claim-source-switch button { min-height: 30px; border: 0; border-radius: 3px; background: transparent; color: #687481; cursor: pointer; }.presale-claim-source-switch button.is-active { color: #155d50; font-weight: 600; }.presale-claim-filter input, .presale-claim-filter select, .presale-modal__field input, .presale-modal__field textarea { box-sizing: border-box; width: 100%; min-height: 36px; border: 1px solid #d7dee5; border-radius: 4px; padding: 7px 10px; background: #fff; color: inherit; }.presale-claim-filter input { width: 260px; }.presale-claim-filter select { width: 130px; }.presale-claim-filter__date { display: flex; align-items: center; gap: 6px; color: #53606d; font-size: 12px; white-space: nowrap; }.presale-claim-filter__date input { width: 142px; }.presale-feedback { margin: 0 0 12px; padding: 10px 12px; border-left: 3px solid #167d68; background: #edf8f4; color: #155d50; }.presale-claim-table-wrap { overflow: auto; background: #fff; border: 1px solid #e1e7ec; }.presale-claim-table, .presale-detail-table { width: 100%; min-width: 1000px; border-collapse: collapse; font-size: 13px; }.presale-claim-table th, .presale-claim-table td, .presale-detail-table th, .presale-detail-table td { padding: 12px; border-bottom: 1px solid #edf0f2; text-align: left; vertical-align: middle; }.presale-claim-table th, .presale-detail-table th { background: #f7f9fa; color: #53606d; font-weight: 600; white-space: nowrap; }.presale-claim-table small { display: block; margin-top: 3px; color: #768391; }.align-right { text-align: right !important; }.presale-claim-table__actions { white-space: nowrap; }.button--text { padding: 0; min-height: auto; border: 0; background: transparent; color: #167d68; cursor: pointer; }.button--text + .button--text { margin-left: 12px; }.button--danger { border-color: #c83c3c; background: #c83c3c; color: #fff; }.button--text.button--danger { color: #c83c3c; background: transparent; }.muted { color: #9aa5af; }.presale-status { display: inline-block; padding: 3px 7px; border-radius: 3px; background: #eff3f5; color: #687481; white-space: nowrap; }.presale-status--available { background: #e9f7f0; color: #167d68; }.presale-status--fully_claimed { background: #eaf2fb; color: #276ba7; }.presale-status--closed_after_sale_reversal { background: #f8eded; color: #9d3a3a; }.presale-pagination { display: flex; justify-content: flex-end; align-items: center; gap: 10px; padding: 12px; color: #65727e; }.presale-empty { padding: 32px !important; text-align: center !important; color: #7d8994; }.presale-modal-backdrop { position: fixed; z-index: 2000; inset: 0; display: grid; place-items: center; padding: 24px; background: rgba(23, 33, 43, .46); }.presale-modal-backdrop--alert { z-index: 2100; }.presale-modal { width: min(440px, 100%); max-height: calc(100vh - 48px); overflow: auto; padding: 20px; border-radius: 6px; background: #fff; box-shadow: 0 18px 56px rgba(10, 20, 30, .28); }.presale-modal--wide { width: min(1040px, 100%); }.presale-modal--alert { width: min(380px, 100%); }.presale-modal header { display: flex; justify-content: space-between; align-items: start; margin-bottom: 20px; }.icon-button { display: grid; width: 32px; height: 32px; place-items: center; border: 0; border-radius: 4px; background: transparent; color: #5a6875; cursor: pointer; }.icon-button:hover { background: #eef2f4; }.presale-modal__summary { display: grid; grid-template-columns: 84px 1fr; gap: 8px 12px; margin: 0 0 18px; }.presale-modal__summary dt { color: #71808d; }.presale-modal__summary dd { margin: 0; }.presale-modal__field { display: grid; gap: 7px; margin-bottom: 16px; color: #46535f; font-size: 13px; }.presale-modal footer { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; }.presale-detail-summary, .presale-void-note, .presale-alert-message { margin: 0 0 14px; padding: 10px; background: #f4f7f8; color: #475561; font-size: 13px; }.presale-alert-message { border-left: 3px solid #c83c3c; background: #fff4f4; color: #8a3030; }.presale-loading { display: grid; min-height: 160px; place-content: center; gap: 10px; color: #71808d; text-align: center; }@media (max-width: 760px) { .presale-claim-view { padding: 16px; }.presale-claim-filter { align-items: stretch; flex-wrap: wrap; }.presale-claim-source-switch { width: 148px; }.presale-claim-filter input { width: 100%; }.presale-claim-filter label:first-child { width: 100%; }.presale-claim-filter__date { flex: 1 1 100%; }.presale-claim-filter__date input { width: auto; flex: 1; }.presale-modal-backdrop { padding: 12px; }.presale-modal { padding: 16px; } }
</style>

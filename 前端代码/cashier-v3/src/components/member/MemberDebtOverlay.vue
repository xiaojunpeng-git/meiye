<script setup>
import { computed, ref, watch } from 'vue'
import { formatMoney, requestCashierV3Action } from '@/services/cashierV3Bridge'
import PersonnelPerformanceOverlay from '@/components/cashier/PersonnelPerformanceOverlay.vue'

const props = defineProps({
  member: {
    type: Object,
    default: () => ({})
  },
  snapshot: {
    type: Object,
    default: () => ({})
  },
  isLoading: {
    type: Boolean,
    default: false
  },
  isPreparing: {
    type: Boolean,
    default: false
  },
  initialDebtId: {
    type: [String, Number],
    default: ''
  },
  businessDate: {
    type: String,
    default: ''
  }
})

const emit = defineEmits(['close', 'repay'])

const selectedDebtId = ref('')
const repayAmount = ref('')
// 补交日期可以独立于收银工具栏临时调整；真正落账时仍由该值写入
// 服务端准备快照，不能在最后一步改回服务器当天。
const repaymentBusinessDate = ref('')
const validationMessage = ref('')
const paymentLines = ref([])
const salespersonAllocations = ref([])
const salespersonCandidates = ref([])
const isSalespersonSelectorOpen = ref(false)
const isSalespersonLoading = ref(false)
const salespersonLoadError = ref('')
const paymentMethods = [
  { code: 'unionpay', label: '银联' },
  { code: 'wechat', label: '微信' },
  { code: 'alipay', label: '支付宝' },
  { code: 'dianping_voucher', label: '大众验券' },
  { code: 'douyin_voucher', label: '抖音验券' },
  { code: 'partner_collection', label: '合作方收款' },
  { code: 'other_collection', label: '其他收款' }
]
let paymentLineSequence = 0

const records = computed(() => {
  if (Array.isArray(props.snapshot.records)) return props.snapshot.records
  if (Array.isArray(props.snapshot.debtRecords)) return props.snapshot.debtRecords
  if (Array.isArray(props.member.debtRecords)) return props.member.debtRecords
  return []
})
const totalDebt = computed(() => (
  props.snapshot.outstandingDebtAmount
  ?? props.snapshot.totalDebtAmount
  ?? props.member.outstandingDebtAmount
  ?? props.member.totalDebtAmount
  ?? props.member.debtAmount
  ?? 0
))
const debtCount = computed(() => (
  props.snapshot.outstandingDebtCount
  ?? props.snapshot.total
  ?? props.member.outstandingDebtCount
  ?? records.value.length
))
const dataAsOf = computed(() => props.snapshot.debtDataAsOf || props.snapshot.dataAsOf || props.member.debtDataAsOf || '')
const selectedDebt = computed(() => records.value.find((record) => String(recordId(record)) === String(selectedDebtId.value)) || null)
const selectedDebtIsRecharge = computed(() => String(value(selectedDebt.value, ['sourceType', 'source_type', 'sourceLabel', 'source'], '')).includes('充值'))
const paymentTotal = computed(() => paymentLines.value.reduce((sum, item) => sum + moneyToCents(item.amount), 0))
const selectedPaymentMethods = computed(() => new Set(paymentLines.value.map((item) => String(item.paymentMethod || ''))))
const paymentMethodLabel = (code) => paymentMethods.find((method) => method.code === String(code))?.label || '未命名收款方式'
const salespersonSummary = computed(() => salespersonAllocations.value.length
  ? salespersonAllocations.value.map(salespersonLabel).join('、')
  : '暂未选择')

watch(records, (nextRecords) => {
  if (selectedDebtId.value && nextRecords.some((record) => String(recordId(record)) === String(selectedDebtId.value))) return
  selectedDebtId.value = ''
  repayAmount.value = ''
  paymentLines.value = []
  salespersonAllocations.value = []
  validationMessage.value = ''
}, { immediate: true })

watch(() => props.businessDate, (value) => {
  // 工具栏日期变化时同步默认值；已打开的补交单可再由操作员单独选择。
  repaymentBusinessDate.value = String(value || '')
}, { immediate: true })

// 从订单中心“去还款”进入时，只预选对应欠款；实际准备收款必须由用户
// 点击“下一步”触发，不能在打开明细时隐式跳转或覆盖当前购物车。
watch([records, () => props.initialDebtId], ([nextRecords, initialDebtId]) => {
  const requested = String(initialDebtId || '')
  if (!requested || selectedDebtId.value || props.isPreparing) return
  const record = nextRecords.find((item) => String(recordId(item)) === requested)
  if (record) selectRepayment(record)
}, { immediate: true })

function value(record, keys, fallback = '—') {
  for (const key of keys) {
    const current = record?.[key]
    if (current !== undefined && current !== null && current !== '') return current
  }
  return fallback
}

function recordId(record) {
  return value(record, ['id', 'debtItemId', 'debt_item_id', 'recordId', 'debtNo'], '')
}

function remainingAmount(record) {
  return value(record, ['remainingDebtAmount', 'pendingDebtAmount', 'pendingDebt', 'remainingAmount'], 0)
}

function selectRepayment(record) {
  if (Number(remainingAmount(record)) <= 0 || props.isPreparing) return
  selectedDebtId.value = String(recordId(record))
  repayAmount.value = wholeYuanString(remainingAmount(record))
  repaymentBusinessDate.value = String(props.businessDate || '')
  paymentLines.value = [{ id: nextPaymentLineId(), paymentMethod: 'unionpay', amount: wholeYuanString(remainingAmount(record)), collectionReference: '', remark: '' }]
  salespersonAllocations.value = []
  validationMessage.value = ''
}

function submitRepayment() {
  const record = selectedDebt.value
  const amount = Number(repayAmount.value)
  const remaining = Number(remainingAmount(record))
  if (!record) {
    validationMessage.value = '请先选择一条欠款。'
    return
  }
  if (!Number.isInteger(amount) || amount <= 0) {
    validationMessage.value = '还款金额必须是大于 0 的整数。'
    return
  }
  if (amount > remaining) {
    validationMessage.value = '还款金额不能超过当前剩余欠款。'
    return
  }
  if (!/^\d{4}-\d{2}-\d{2}$/.test(repaymentBusinessDate.value)) {
    validationMessage.value = '请选择补交业务日期。'
    return
  }
  validationMessage.value = ''
  emit('repay', {
    memberId: props.member.id || props.member.memberId,
    debtRecordId: recordId(record),
    debtItemId: value(record, ['debtItemId', 'debt_item_id', 'id'], ''),
    recordVersion: value(record, ['revision', 'recordVersion', 'version'], null),
    amount: String(amount),
    businessDate: repaymentBusinessDate.value,
    record,
    rechargeDebt: selectedDebtIsRecharge.value,
    balanceVersion: Number(props.snapshot.balanceVersion || props.snapshot.accountVersion || props.member.balanceVersion || props.member.accountVersion || 0),
    paymentLines: selectedDebtIsRecharge.value ? paymentLines.value.map((item) => ({ paymentMethod: item.paymentMethod, amount: String(item.amount), collectionReference: String(item.collectionReference || '').trim(), remark: String(item.remark || '').trim() })) : undefined,
    salespersonAllocations: salespersonAllocations.value.map((item) => ({
      staffId: item.staffId || item.id,
      allocationWeight: Number(item.allocationWeight),
      isPreSale: Boolean(item.isPreSale ?? item.marked),
      // 手填业绩金额是本次补交的业务输入，必须随提交快照进入服务端；
      // 不能只保留在人员选择弹窗里再由比例覆盖。
      performanceAmountCents: Math.max(0, Math.trunc(Number(item.performanceAmountCents || 0))),
      performanceAmountManual: Boolean(item.performanceAmountManual),
      // 独立核算由服务端按员工当前职位权威判定；保留前端元数据只用于
      // 同一选择器在本次会话中的展示，不能用浏览器标志突破职位配置。
      performanceIndependent: Boolean(item.performanceIndependent),
      allocationGroupKey: String(item.allocationGroupKey || '')
    }))
  })
}

function salespersonLabel(item = {}) {
  const role = item.isPreSale || item.marked ? '售前' : '售后'
  return `${item.name || '未命名'}（${role}${item.performanceIndependent ? '，独立核算' : ''}）`
}

function moneyToCents(value) { const raw = String(value ?? '').trim(); return /^(0|[1-9]\d*)$/.test(raw) ? Number(raw) * 100 : 0 }
function isPositiveMoney(value) { return moneyToCents(value) > 0 }
function wholeYuanString(value) {
  const raw = String(value ?? '').trim()
  // 欠款金额来自金额字段时可能带有固定的 .00 展示小数；
  // 收款命令的金额契约使用“元”的整数文本，选择欠款后统一归一化。
  return /^\d+\.00$/.test(raw) ? raw.slice(0, -3) : raw
}
function nextPaymentLineId() { paymentLineSequence += 1; return `recharge-repayment-${Date.now()}-${paymentLineSequence}` }
function normalizeRepayAmount(event) { repayAmount.value = String(event.target.value ?? '').trim() }
function normalizePaymentAmount(line, event) { line.amount = String(event.target.value ?? '').trim() }
function addPaymentLine(method) {
  const code = String(method || '')
  if (!code || selectedPaymentMethods.value.has(code)) return
  paymentLines.value.push({ id: nextPaymentLineId(), paymentMethod: code, amount: '', collectionReference: '', remark: '' })
}
function removePaymentLine(id) { if (paymentLines.value.length <= 1) return; paymentLines.value = paymentLines.value.filter((item) => item.id !== id) }
async function openSalespersonSelector() {
  isSalespersonSelectorOpen.value = true
  isSalespersonLoading.value = true
  salespersonLoadError.value = ''
  const records = []
  let page = 1
  let total = 0
  try {
    do {
      const response = await requestCashierV3Action('query-query-entities', {
        entityType: 'person', selectorEntry: 'cashier', selectorContext: { scope: 'sales_performance_assignees' },
        keyword: '', page, pageSize: 100, silent: true
      })
      const envelope = response?.data?.result ? response.data : response
      const data = envelope?.data || {}
      const status = String(envelope?.result?.status || '')
      if (!['success', 'succeeded'].includes(status) || !Array.isArray(data.records)) {
        throw new Error(envelope?.result?.message || '当前门店销售人加载失败，请重试。')
      }
      records.push(...data.records)
      total = Math.max(0, Number(data.total) || records.length)
      page += 1
    } while (records.length < total && page <= 20)
    salespersonCandidates.value = records
  } catch (error) {
    salespersonLoadError.value = error instanceof Error ? error.message : '当前门店销售人加载失败，请重试。'
  } finally {
    isSalespersonLoading.value = false
  }
}
function confirmSalespeople(result = {}) {
  salespersonAllocations.value = Array.isArray(result.salespeople) ? result.salespeople.map((item) => ({ ...item })) : []
  isSalespersonSelectorOpen.value = false
}
</script>

<template>
  <div class="member-debt-overlay" role="presentation" @click.self="$emit('close')">
    <section class="member-debt-overlay__dialog" role="dialog" aria-modal="true" :aria-label="`${member.name || '会员'}欠款明细`">
      <header class="member-debt-overlay__header">
        <div>
          <span>会员欠款</span>
          <h2>{{ member.name || '会员' }}的欠款明细</h2>
          <p>{{ member.phone || member.memberNo || '' }}</p>
        </div>
        <div class="member-debt-overlay__header-summary">
          <span>总欠款</span>
          <strong>{{ formatMoney(totalDebt) }}</strong>
          <small>共 {{ debtCount }} 条<span v-if="dataAsOf"> · 更新于 {{ dataAsOf }}</span></small>
        </div>
        <button type="button" class="member-debt-overlay__close" :disabled="isPreparing" aria-label="关闭欠款明细" @click="$emit('close')">×</button>
      </header>

      <main class="member-debt-overlay__body" :aria-busy="isLoading">
        <div v-if="isLoading" class="member-debt-overlay__state">正在加载欠款明细…</div>
        <div v-else-if="!records.length" class="member-debt-overlay__state">
          <strong>当前没有待还欠款</strong>
          <span>欠款明细以服务端最新结果为准。</span>
        </div>
        <div v-else class="member-debt-overlay__table-wrap">
          <table class="member-debt-overlay__table">
            <thead>
              <tr>
                <th>欠款编号</th><th>业务日期</th><th>欠款来源</th><th>来源订单号</th><th>商品或充值摘要</th>
                <th>原始欠款</th><th>已补交</th><th>剩余欠款</th><th>状态</th><th>操作</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="record in records" :key="recordId(record)" :class="{ 'member-debt-overlay__row--selected': String(recordId(record)) === selectedDebtId }">
                <td>{{ value(record, ['debtNo', 'debt_no']) }}</td>
                <td>{{ value(record, ['businessDate', 'business_date']) }}</td>
                <td>{{ value(record, ['sourceLabel', 'sourceTypeLabel', 'source']) }}</td>
                <td>{{ value(record, ['sourceOrderNo', 'source_order_no', 'orderNo']) }}</td>
                <td class="member-debt-overlay__summary">{{ value(record, ['summary', 'itemSummary', 'productName']) }}</td>
                <td>{{ formatMoney(value(record, ['originalDebtAmount', 'debtAmount'], 0)) }}</td>
                <td>{{ formatMoney(value(record, ['repaidAmount', 'settledAmount'], 0)) }}</td>
                <td class="member-debt-overlay__remaining">{{ formatMoney(remainingAmount(record)) }}</td>
                <td><span class="member-debt-overlay__status">{{ value(record, ['statusLabel', 'status']) }}</span></td>
                <td>
                  <button
                    type="button"
                    class="member-debt-overlay__repay-link"
                    :disabled="Number(remainingAmount(record)) <= 0 || isPreparing"
                    @click="selectRepayment(record)"
                  >
                    去还款
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <section v-if="selectedDebt" class="member-debt-overlay__repayment" aria-label="本次还款金额">
          <div>
            <span>本次针对</span>
            <strong>{{ value(selectedDebt, ['summary', 'itemSummary', 'productName']) }}</strong>
            <small>每次只能还一条欠款，剩余 {{ formatMoney(remainingAmount(selectedDebt)) }}</small>
          </div>
          <label>
            <span>还款金额</span>
            <input v-model="repayAmount" type="text" inputmode="numeric" pattern="[0-9]*" :disabled="isPreparing" @input="normalizeRepayAmount">
          </label>
          <button type="button" class="button button--primary" :disabled="isPreparing" @click="submitRepayment">
            {{ isPreparing ? '正在准备收款…' : selectedDebtIsRecharge ? '确认补交' : '下一步：确认收款' }}
          </button>
          <p v-if="validationMessage" role="alert">{{ validationMessage }}</p>
        </section>
        <!-- 充值欠款进入统一结账收款页；这里不再维护第二套收款控件。 -->
        <section v-if="false && selectedDebt && selectedDebtIsRecharge" class="member-debt-overlay__recharge-payment" aria-label="充值欠款收款信息">
          <header>
            <strong>收款信息</strong>
            <span>可先选择全部收款方式，再调整金额；合计必须等于本次补交。</span>
          </header>
          <div class="member-debt-overlay__payment-methods-header">
            <strong>记账收款</strong>
            <span v-if="paymentLines.length > 1">组合收款</span>
          </div>
          <div class="member-debt-overlay__payment-methods-grid">
            <button
              v-for="method in paymentMethods"
              :key="method.code"
              type="button"
              class="member-debt-overlay__payment-method-card"
              :disabled="selectedPaymentMethods.has(method.code) || isPreparing"
              @click="addPaymentLine(method.code)"
            >
              {{ method.label }}
            </button>
          </div>
          <div v-for="line in paymentLines" :key="line.id" class="member-debt-overlay__recharge-payment-line">
            <div>
              <strong>{{ paymentMethodLabel(line.paymentMethod) }}</strong>
              <span>待收</span>
            </div>
            <input v-model="line.amount" inputmode="numeric" autocomplete="off" placeholder="整数收款金额" @input="normalizePaymentAmount(line, $event)">
            <input v-model="line.collectionReference" autocomplete="off" placeholder="流水号（选填）">
            <button type="button" class="member-debt-overlay__repay-link" :disabled="paymentLines.length <= 1 || isPreparing" @click="removePaymentLine(line.id)">删除</button>
          </div>
          <dl class="member-debt-overlay__payment-summary">
            <div><dt>本次补交</dt><dd>{{ formatMoney(repayAmount) }}</dd></div>
            <div><dt>已选收款</dt><dd>{{ formatMoney(paymentTotal / 100) }}</dd></div>
            <div><dt>待收</dt><dd>{{ formatMoney(Math.max(0, Number(repayAmount || 0) - paymentTotal / 100)) }}</dd></div>
          </dl>
        </section>
        <section v-if="selectedDebt" class="member-debt-overlay__repayment-context" aria-label="补交业务信息">
          <div class="member-debt-overlay__business-date">
            <label for="member-debt-repayment-business-date">业务日期</label>
            <input id="member-debt-repayment-business-date" v-model="repaymentBusinessDate" type="date" :disabled="isPreparing" aria-label="补交业务日期">
          </div>
          <div class="member-debt-overlay__recharge-salespeople" aria-label="销售人分配">
            <header><strong>销售人分配</strong><span>{{ salespersonSummary }}</span><button type="button" class="button button--secondary" @click="openSalespersonSelector">选择销售人</button></header>
            <span v-for="person in salespersonAllocations" :key="person.staffId || person.id">{{ salespersonLabel(person) }}</span>
          </div>
        </section>
      </main>

      <footer class="member-debt-overlay__footer">
        <span>还款将进入统一收款流程，成功后由系统重新读取欠款余额。</span>
        <button type="button" class="button button--secondary" :disabled="isPreparing" @click="$emit('close')">关闭</button>
      </footer>
    </section>
    <PersonnelPerformanceOverlay
      v-if="isSalespersonSelectorOpen"
      initial-tab="salespeople"
      :show-craftsmen="false"
      :show-salespeople="true"
      :craftsmen-candidates="[]"
      :salesperson-candidates="salespersonCandidates"
      :selected-craftsmen="[]"
      :selected-salespeople="salespersonAllocations"
      :performance-base-amount-cents="Number(repayAmount || 0) * 100"
      :loading="isSalespersonLoading"
      :load-error="salespersonLoadError"
      @close="isSalespersonSelectorOpen = false"
      @confirm="confirmSalespeople"
      @retry="openSalespersonSelector"
    />
  </div>
</template>

<style scoped>
.member-debt-overlay {
  position: fixed;
  z-index: 1480;
  inset: 0;
  display: grid;
  place-items: center;
  padding: 22px;
  background: rgba(20, 28, 40, .42);
}

.member-debt-overlay__dialog {
  display: grid;
  grid-template-rows: auto minmax(0, 1fr) auto;
  width: min(1180px, 100%);
  max-height: min(820px, calc(100vh - 44px));
  overflow: hidden;
  border: 1px solid #d9e0e8;
  border-radius: 8px;
  background: #fff;
  box-shadow: 0 24px 70px rgba(28, 38, 52, .24);
}

.member-debt-overlay__header {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto auto;
  align-items: center;
  gap: 24px;
  padding: 18px 22px;
  border-bottom: 1px solid #e7ebf0;
}

.member-debt-overlay__header > div:first-child,
.member-debt-overlay__header-summary {
  display: grid;
  gap: 2px;
}

.member-debt-overlay__header span,
.member-debt-overlay__header p,
.member-debt-overlay__header small {
  margin: 0;
  color: #7f8a99;
  font-size: 12px;
}

.member-debt-overlay__header h2 {
  margin: 0;
  color: #20252b;
  font-size: 20px;
  letter-spacing: 0;
}

.member-debt-overlay__header-summary {
  min-width: 180px;
  padding-left: 20px;
  border-left: 1px solid #e8edf2;
}

.member-debt-overlay__header-summary strong {
  color: #9f1239;
  font-size: 24px;
  font-variant-numeric: tabular-nums;
}

.member-debt-overlay__close {
  display: grid;
  width: 34px;
  height: 34px;
  place-items: center;
  padding: 0;
  border: 0;
  border-radius: 50%;
  background: #f0f2f5;
  color: #526071;
  font-size: 22px;
}

.member-debt-overlay__body {
  display: grid;
  align-content: start;
  gap: 14px;
  min-height: 0;
  overflow: auto;
  padding: 18px 20px;
  background: #f7f9fb;
}

.member-debt-overlay__table-wrap {
  min-width: 0;
  overflow: auto;
  border: 1px solid #dde4ec;
  border-radius: 6px;
  background: #fff;
}

.member-debt-overlay__table {
  width: 100%;
  min-width: 1160px;
  border-collapse: collapse;
  font-size: 13px;
}

.member-debt-overlay__table th,
.member-debt-overlay__table td {
  padding: 12px 13px;
  border-bottom: 1px solid #edf0f4;
  text-align: left;
  white-space: nowrap;
}

.member-debt-overlay__table th {
  position: sticky;
  top: 0;
  background: #f4f6f9;
  color: #667281;
  font-size: 12px;
}

.member-debt-overlay__row--selected {
  background: #eef6ff;
}

.member-debt-overlay__summary {
  max-width: 230px;
  overflow: hidden;
  text-overflow: ellipsis;
}

.member-debt-overlay__remaining {
  color: #9f1239;
  font-variant-numeric: tabular-nums;
  font-weight: 800;
}

.member-debt-overlay__status {
  display: inline-flex;
  min-height: 24px;
  align-items: center;
  padding: 0 9px;
  border-radius: 999px;
  background: #fff1f2;
  color: #9f1239;
  font-size: 12px;
  font-weight: 700;
}

.member-debt-overlay__repay-link {
  padding: 0;
  border: 0;
  background: transparent;
  color: #1769aa;
  font-weight: 700;
}

.member-debt-overlay__repayment {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 220px auto;
  align-items: end;
  gap: 16px;
  padding: 16px 18px;
  border: 1px solid #cbdff5;
  border-radius: 6px;
  background: #fff;
}

.member-debt-overlay__repayment > div,
.member-debt-overlay__repayment label {
  display: grid;
  gap: 5px;
}

.member-debt-overlay__repayment span,
.member-debt-overlay__repayment small {
  color: #7d8897;
  font-size: 12px;
}

.member-debt-overlay__repayment strong {
  color: #303640;
  font-size: 15px;
}

.member-debt-overlay__repayment input {
  width: 100%;
  height: 40px;
  padding: 0 12px;
  border: 1px solid #cfd8e3;
  border-radius: 5px;
  color: #9f1239;
  font-size: 17px;
  font-variant-numeric: tabular-nums;
  font-weight: 700;
}

.member-debt-overlay__repayment > p {
  grid-column: 2 / -1;
  margin: -7px 0 0;
  color: #b42318;
  font-size: 12px;
}

.member-debt-overlay__recharge-payment,
.member-debt-overlay__recharge-salespeople { display: grid; gap: 10px; padding: 16px 18px; border: 1px solid #dde4ec; border-radius: 6px; background: #fff; }
.member-debt-overlay__repayment-context { display: grid; grid-template-columns: minmax(180px, .42fr) minmax(0, 1fr); gap: 14px; }
.member-debt-overlay__business-date { display: grid; align-content: center; gap: 7px; padding: 16px 18px; border: 1px solid #dde4ec; border-radius: 6px; background: #fff; }
.member-debt-overlay__business-date label { color: #6b7787; font-size: 12px; }
.member-debt-overlay__business-date input { width: 100%; box-sizing: border-box; min-height: 34px; padding: 6px 8px; border: 1px solid #cfd7e3; border-radius: 5px; color: #303640; font: inherit; font-size: 16px; font-variant-numeric: tabular-nums; }
.member-debt-overlay__business-date input:disabled { background: #f4f6f8; color: #98a2b3; }
.member-debt-overlay__recharge-payment header,
.member-debt-overlay__recharge-salespeople header { display: flex; align-items: center; gap: 10px; color: #6b7787; font-size: 12px; }
.member-debt-overlay__recharge-payment header strong,
.member-debt-overlay__recharge-salespeople header strong { color: #303640; font-size: 14px; }
.member-debt-overlay__recharge-payment header span,
.member-debt-overlay__recharge-salespeople header span { flex: 1; text-align: right; }
.member-debt-overlay__payment-methods-header { display: flex; align-items: center; justify-content: space-between; color: #6b7787; font-size: 12px; }
.member-debt-overlay__payment-methods-header strong { color: #303640; font-size: 14px; }
.member-debt-overlay__payment-methods-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; }
.member-debt-overlay__payment-method-card { min-height: 38px; padding: 0 10px; border: 1px solid #dbe3ec; border-radius: 6px; background: #fff; color: #303640; font-weight: 700; }
.member-debt-overlay__payment-method-card:not(:disabled):hover { border-color: #1769aa; color: #1769aa; }
.member-debt-overlay__payment-method-card:disabled { cursor: not-allowed; opacity: .45; }
.member-debt-overlay__recharge-payment-line { display: grid; grid-template-columns: minmax(130px, .7fr) 120px minmax(160px, 1fr) auto; align-items: center; gap: 8px; padding: 10px 0; border-top: 1px solid #edf0f4; }
.member-debt-overlay__recharge-payment-line > div { display: grid; gap: 3px; }
.member-debt-overlay__recharge-payment-line > div span { color: #7d8897; font-size: 12px; }
.member-debt-overlay__recharge-payment-line input,
.member-debt-overlay__recharge-payment input,
.member-debt-overlay__recharge-salespeople input { min-width: 0; height: 34px; padding: 0 9px; border: 1px solid #cfd8e3; border-radius: 5px; background: #fff; }
.member-debt-overlay__payment-summary { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin: 0; padding-top: 10px; border-top: 1px solid #edf0f4; }
.member-debt-overlay__payment-summary div { display: flex; justify-content: space-between; gap: 8px; color: #6b7787; font-size: 12px; }
.member-debt-overlay__payment-summary dt,
.member-debt-overlay__payment-summary dd { margin: 0; }
.member-debt-overlay__payment-summary dd { color: #9f1239; font-weight: 800; }
.member-debt-overlay__recharge-salespeople > label { display: grid; grid-template-columns: minmax(0, 1fr) 150px auto; align-items: center; gap: 10px; }

.member-debt-overlay__state {
  display: grid;
  min-height: 260px;
  place-content: center;
  gap: 7px;
  color: #7d8897;
  text-align: center;
}

.member-debt-overlay__state strong {
  color: #303640;
  font-size: 17px;
}

.member-debt-overlay__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 14px 20px;
  border-top: 1px solid #e7ebf0;
  color: #7d8897;
  font-size: 12px;
}

@media (max-width: 780px) {
  .member-debt-overlay {
    padding: 10px;
  }

  .member-debt-overlay__dialog {
    max-height: calc(100vh - 20px);
  }

  .member-debt-overlay__header {
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 12px;
  }

  .member-debt-overlay__header-summary {
    grid-column: 1 / -1;
    grid-row: 2;
    padding: 10px 0 0;
    border-top: 1px solid #e8edf2;
    border-left: 0;
  }

  .member-debt-overlay__close {
    grid-column: 2;
    grid-row: 1;
  }

  .member-debt-overlay__repayment {
    grid-template-columns: 1fr;
  }

  .member-debt-overlay__repayment-context {
    grid-template-columns: 1fr;
  }

  .member-debt-overlay__repayment > p {
    grid-column: 1;
  }

  .member-debt-overlay__payment-methods-grid,
  .member-debt-overlay__payment-summary {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .member-debt-overlay__recharge-payment-line {
    grid-template-columns: minmax(110px, 1fr) 100px auto;
  }

  .member-debt-overlay__recharge-payment-line input:nth-of-type(2) {
    grid-column: 1 / -1;
  }
}
</style>

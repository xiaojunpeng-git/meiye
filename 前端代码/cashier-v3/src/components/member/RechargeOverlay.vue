<script setup>
import { computed, ref, watch } from 'vue'
import { formatMoney, requestCashierV3Action } from '@/services/cashierV3Bridge'
import PersonnelPerformanceOverlay from '@/components/cashier/PersonnelPerformanceOverlay.vue'

const props = defineProps({
  session: { type: Object, default: () => ({}) },
  // 充值日期是本次充值的业务快照。打开弹窗时从收银工具栏带入，
  // 点击下一步后与人员分配一同冻结；不能在正式收款阶段回读工具栏。
  businessDate: { type: String, default: '' },
  submitting: { type: Boolean, default: false },
  submitError: { type: String, default: '' }
})
const emit = defineEmits(['close', 'submit'])

const principalAmount = ref('')
const bonusAmount = ref('0')
const debtAmount = ref('0')
const rechargeMode = ref('package')
const rechargePackageId = ref('')
const paymentLines = ref([])
const newPaymentMethod = ref('unionpay')
const salespersonAllocations = ref([])
const salespersonCandidates = ref([])
const isSalespersonSelectorOpen = ref(false)
const isSalespersonLoading = ref(false)
const salespersonLoadError = ref('')
const validationMessage = ref('')
const businessDate = ref('')
let paymentLineSequence = 1

const cashierToday = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Shanghai' }).format(new Date())

const member = computed(() => props.session.member || {})
const balance = computed(() => props.session.balance || {})
const memberId = computed(() => member.value.id || member.value.memberId || balance.value.memberId || '')
const memberName = computed(() => member.value.name || member.value.realName || member.value.nickname || '会员')
const currentBalance = computed(() => {
  const cents = Number(balance.value.totalCents)
  return Number.isSafeInteger(cents) && cents >= 0 ? cents / 100 : Number(member.value.accountBalance || 0)
})
const rechargeOptions = computed(() => props.session.rechargeOptions || {})
const rechargePackages = computed(() => Array.isArray(rechargeOptions.value.packages) ? rechargeOptions.value.packages : [])
const selectedPackage = computed(() => rechargePackages.value.find((item) => String(item.id) === String(rechargePackageId.value)) || null)
const hasRechargePackages = computed(() => rechargePackages.value.length > 0)
const displayedPrincipal = computed(() => rechargeMode.value === 'package'
  ? String(selectedPackage.value?.price ?? '')
  : principalAmount.value)
const displayedBonus = computed(() => rechargeMode.value === 'package'
  ? String(selectedPackage.value?.bonus ?? '0')
  : bonusAmount.value)
const cashDueCents = computed(() => Math.max(0, moneyToCents(displayedPrincipal.value) - moneyToCents(debtAmount.value)))
const cashDue = computed(() => cashDueCents.value / 100)
const paymentLineTotalCents = computed(() => paymentLines.value.reduce((total, item) => total + moneyToCents(item.amount), 0))
const paymentLineTotal = computed(() => paymentLineTotalCents.value / 100)
const paymentMethods = [
  ['unionpay', '银联'], ['wechat', '微信'], ['alipay', '支付宝'], ['dianping_voucher', '大众验券'],
  ['douyin_voucher', '抖音验券'], ['partner_collection', '合作方收款'], ['other_collection', '其他收款']
]

watch(() => props.session, () => {
  principalAmount.value = ''
  bonusAmount.value = '0'
  debtAmount.value = '0'
  rechargeMode.value = hasRechargePackages.value ? 'package' : 'custom'
  rechargePackageId.value = hasRechargePackages.value ? String(rechargePackages.value[0].id) : ''
  paymentLines.value = [createPaymentLine('unionpay')]
  newPaymentMethod.value = 'unionpay'
  salespersonAllocations.value = []
  businessDate.value = String(props.businessDate || cashierToday)
  validationMessage.value = ''
}, { immediate: true, deep: true })

watch([displayedPrincipal, debtAmount], () => {
  if (paymentLines.value.length === 1 && cashDueCents.value > 0) {
    paymentLines.value[0].amount = String(cashDue.value)
  }
})

function isMoney(value, allowZero = false) {
  const raw = String(value ?? '').trim()
  if (!/^(0|[1-9]\d*)$/.test(raw)) return false
  return allowZero ? Number(raw) >= 0 : Number(raw) > 0
}

function moneyToCents(value) {
  const raw = String(value ?? '').trim()
  const match = /^(0|[1-9]\d*)$/.exec(raw)
  if (!match) return 0
  return Number(match[1]) * 100
}

function normalizePrincipalAmount(event) {
  principalAmount.value = String(event.target.value ?? '').trim()
}

function normalizeBonusAmount(event) {
  bonusAmount.value = String(event.target.value ?? '').trim()
}

function normalizeDebtAmount(event) {
  debtAmount.value = String(event.target.value ?? '').trim()
}

function normalizePaymentAmount(line, event) {
  line.amount = String(event.target.value ?? '').trim()
}

function formatPackageBonus(value) {
  return String(formatMoney(value)).replace(/[¥￥\s]/g, '')
}

function submit() {
  const principal = displayedPrincipal.value.trim()
  const bonus = displayedBonus.value.trim()
  const debt = debtAmount.value.trim()
  const selectedBusinessDate = businessDate.value.trim()
  if (!memberId.value) validationMessage.value = '会员信息已失效，请重新选择会员。'
  else if (!isMoney(principal)) validationMessage.value = '请输入大于 0 的充值金额。'
  else if (!isMoney(bonus, true)) validationMessage.value = '赠送金额只能填写不小于 0 的金额。'
  else if (!isMoney(debt, true) || moneyToCents(debt) > moneyToCents(principal)) validationMessage.value = '欠款金额必须在 0 到充值本金之间。'
  else if (!isValidBusinessDate(selectedBusinessDate)) validationMessage.value = '请选择不晚于今天的业务日期。'
  else validationMessage.value = ''
  if (validationMessage.value) return
  emit('submit', {
    memberId: memberId.value,
    rechargeMode: rechargeMode.value,
    rechargePackageId: rechargeMode.value === 'package' ? rechargePackageId.value : undefined,
    principalAmount: principal,
    bonusAmount: bonus,
    debtAmount: debt,
    businessDate: selectedBusinessDate,
    salespersonAllocations: salespersonAllocations.value.map((item) => ({
      staffId: item.staffId || item.id,
      allocationWeight: Number(item.allocationWeight),
      isPreSale: Boolean(item.isPreSale ?? item.marked),
      performanceAmountCents: Math.max(0, Number(item.performanceAmountCents || 0)),
      performanceAmountManual: Boolean(item.performanceAmountManual),
      positionId: Number(item.positionId || 0),
      positionName: String(item.positionName || ''),
      performanceIndependent: Boolean(item.performanceIndependent),
      allocationGroupKey: String(item.allocationGroupKey || '')
    }))
  })
}

function isValidBusinessDate(value) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value) || value > cashierToday) return false
  const date = new Date(`${value}T00:00:00+08:00`)
  return Number.isFinite(date.getTime()) && date.toLocaleDateString('en-CA', { timeZone: 'Asia/Shanghai' }) === value
}

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
        entityType: 'person',
        selectorEntry: 'cashier',
        selectorContext: { scope: 'sales_performance_assignees' },
        keyword: '',
        page,
        pageSize: 100,
        silent: true
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

function addPaymentLine() {
  const method = newPaymentMethod.value
  if (!method) return
  paymentLines.value.push(createPaymentLine(method))
}

function removePaymentLine(id) {
  if (paymentLines.value.length <= 1) return
  paymentLines.value = paymentLines.value.filter((item) => item.id !== id)
}

function createPaymentLine(paymentMethod) {
  return {
    id: `recharge-payment-${paymentLineSequence++}`,
    paymentMethod,
    amount: '',
    collectionReference: ''
  }
}
</script>

<template>
  <Teleport to="body">
    <section class="recharge-overlay" role="presentation" @click.self="!submitting && emit('close')">
      <form class="recharge-overlay__dialog" role="dialog" aria-modal="true" aria-label="会员充值" @submit.prevent="submit">
        <header class="recharge-overlay__header">
          <div>
            <span>会员充值</span>
            <h2>{{ memberName }}</h2>
            <p>{{ member.phone || member.memberNo || '' }}</p>
          </div>
          <button type="button" class="button button--secondary" :disabled="submitting" @click="emit('close')">关闭</button>
        </header>

        <div class="recharge-overlay__balance">
          <span>当前储值余额</span>
          <strong>{{ formatMoney(currentBalance) }}</strong>
        </div>

        <div class="recharge-overlay__mode" role="tablist" aria-label="充值方式">
          <button type="button" :class="{ 'recharge-overlay__mode--active': rechargeMode === 'package' }" :disabled="!hasRechargePackages" @click="rechargeMode = 'package'">充值套餐</button>
          <button type="button" :class="{ 'recharge-overlay__mode--active': rechargeMode === 'custom' }" @click="rechargeMode = 'custom'">自定义充值</button>
        </div>
        <div v-if="rechargeMode === 'package'" class="recharge-overlay__packages" aria-label="充值套餐">
          <button
            v-for="item in rechargePackages"
            :key="item.id"
            type="button"
            class="recharge-overlay__package"
            :class="{ 'recharge-overlay__package--active': String(item.id) === String(rechargePackageId) }"
            @click="rechargePackageId = String(item.id)"
          >
            <div class="recharge-overlay__package-main">
              <strong>本金 {{ formatMoney(item.price) }}</strong>
              <span>赠送{{ formatPackageBonus(item.bonus) }}</span>
            </div>
            <small v-if="item.giftProductCount || item.giftCouponCount">含 {{ item.giftProductCount || 0 }} 项赠品、{{ item.giftCouponCount || 0 }} 张券</small>
          </button>
          <p v-if="!hasRechargePackages" class="recharge-overlay__hint">当前没有可用的充值套餐，请使用自定义充值。</p>
        </div>
        <div class="recharge-overlay__fields" :class="{ 'recharge-overlay__fields--custom': rechargeMode === 'custom' }">
          <template v-if="rechargeMode === 'custom'">
            <label>充值金额<input v-model="principalAmount" inputmode="numeric" autocomplete="off" placeholder="请输入整数充值金额" @input="normalizePrincipalAmount"></label>
            <label>赠送金额<input v-model="bonusAmount" inputmode="numeric" autocomplete="off" @input="normalizeBonusAmount"></label>
          </template>
          <label>本次欠款<input v-model="debtAmount" inputmode="numeric" autocomplete="off" @input="normalizeDebtAmount"></label>
        </div>
        <section class="recharge-overlay__context" aria-label="充值日期与销售人分配">
          <label class="recharge-overlay__business-date">业务日期<input v-model="businessDate" type="date" :max="cashierToday"></label>
          <section class="recharge-overlay__salespeople" aria-label="销售人分配">
            <header><strong>销售人分配</strong><span>{{ salespersonAllocations.length ? salespersonAllocations.map((item) => `${item.name} ${item.allocationWeight}%`).join('、') : '暂未选择' }}</span><button type="button" class="button button--secondary" @click="openSalespersonSelector">选择销售人</button></header>
            <div v-if="salespersonAllocations.length" class="recharge-overlay__salesperson-list">
              <span v-for="item in salespersonAllocations" :key="item.staffId || item.id">{{ item.name }}（{{ item.isPreSale || item.marked ? '售前' : '售后' }}，{{ item.allocationWeight }}%）</span>
            </div>
            <p v-else class="recharge-overlay__hint">未分配时，本笔现金业绩不归属具体销售人。</p>
          </section>
        </section>
        <p v-if="validationMessage || submitError" class="recharge-overlay__error" role="alert">{{ validationMessage || submitError }}</p>
        <footer class="recharge-overlay__footer">
          <button type="button" class="button button--secondary" :disabled="submitting" @click="emit('close')">取消</button>
          <button type="button" class="button button--primary" :disabled="submitting" @click="submit">{{ submitting ? '正在准备' : '下一步：收款信息' }}</button>
        </footer>
      </form>
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
      :performance-base-amount-cents="cashDueCents"
      :loading="isSalespersonLoading"
      :load-error="salespersonLoadError"
      @close="isSalespersonSelectorOpen = false"
      @confirm="confirmSalespeople"
      @retry="openSalespersonSelector"
    />
  </Teleport>
</template>

<style scoped>
.recharge-overlay { position: fixed; inset: 0; z-index: 1200; display: grid; place-items: center; padding: 24px; background: rgba(21, 31, 47, .46); }
.recharge-overlay__dialog { width: min(820px, 100%); max-height: calc(100vh - 48px); overflow-y: auto; border: 1px solid #dfe5ec; border-radius: 8px; background: #fff; box-shadow: 0 20px 56px rgba(18, 34, 54, .24); }
.recharge-overlay__header, .recharge-overlay__footer { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 20px 24px; }
.recharge-overlay__header { border-bottom: 1px solid #edf0f4; }
.recharge-overlay__header span { color: #7b8798; font-size: 13px; }
.recharge-overlay__header h2 { margin: 3px 0; font-size: 20px; line-height: 28px; }
.recharge-overlay__header p { margin: 0; color: #7b8798; font-size: 13px; }
.recharge-overlay__balance { display: flex; justify-content: space-between; align-items: baseline; margin: 20px 24px 0; padding: 14px 16px; border: 1px solid #d7e8ff; border-radius: 6px; background: #f3f8ff; }
.recharge-overlay__balance span { color: #5c6a7e; font-size: 14px; }
.recharge-overlay__balance strong { color: #175fb3; font-size: 24px; }
.recharge-overlay__mode { display: flex; gap: 8px; margin: 22px 24px 0; border-bottom: 1px solid #edf0f4; }
.recharge-overlay__mode button { padding: 8px 12px; border: 0; border-bottom: 2px solid transparent; background: transparent; color: #5c6a7e; cursor: pointer; }
.recharge-overlay__mode button:disabled { cursor: not-allowed; opacity: .45; }
.recharge-overlay__mode button.recharge-overlay__mode--active { border-bottom-color: #2981e5; color: #175fb3; font-weight: 600; }
.recharge-overlay__packages { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; padding: 18px 24px 0; }
.recharge-overlay__package { display: grid; gap: 8px; min-height: 92px; padding: 12px; border: 1px solid #cfd7e3; border-radius: 6px; background: #fff; color: #3f4c5c; text-align: left; cursor: pointer; }
.recharge-overlay__package-main { display: grid; grid-template-columns: minmax(0, 1fr) auto; grid-template-rows: auto auto; align-items: end; min-height: 42px; }
.recharge-overlay__package strong { grid-column: 1; grid-row: 1; color: #175fb3; font-size: 18px; }
.recharge-overlay__package span { grid-column: 2; grid-row: 2; color: #5c6a7e; font-size: 12px; white-space: nowrap; }
.recharge-overlay__package small { color: #5c6a7e; font-size: 12px; }
.recharge-overlay__package--active { border-color: #2981e5; box-shadow: 0 0 0 2px rgba(41,129,229,.12); }
.recharge-overlay__hint { grid-column: 1 / -1; margin: 0; color: #7b8798; font-size: 13px; }
.recharge-overlay__fields { display: grid; grid-template-columns: minmax(0, 1fr); gap: 16px; padding: 22px 24px; }
.recharge-overlay__fields--custom { grid-template-columns: repeat(3, minmax(0, 1fr)); }
.recharge-overlay__fields label { display: grid; gap: 7px; color: #3f4c5c; font-size: 14px; }
.recharge-overlay__fields input, .recharge-overlay__fields select { width: 100%; height: 38px; padding: 0 10px; border: 1px solid #cfd7e3; border-radius: 5px; background: #fff; color: #202b3a; }
.recharge-overlay__fields input:focus, .recharge-overlay__fields select:focus { outline: 2px solid rgba(41, 129, 229, .24); border-color: #2981e5; }
.recharge-overlay__context { display: grid; grid-template-columns: minmax(180px, .7fr) minmax(0, 1.3fr); gap: 20px; margin: 0 24px 22px; border-top: 1px solid #edf0f4; padding-top: 16px; }
.recharge-overlay__business-date { display: grid; align-content: start; gap: 7px; color: #3f4c5c; font-size: 14px; }
.recharge-overlay__business-date input { width: 100%; height: 38px; padding: 0 10px; border: 1px solid #cfd7e3; border-radius: 5px; background: #fff; color: #202b3a; }
.recharge-overlay__business-date input:focus { outline: 2px solid rgba(41, 129, 229, .24); border-color: #2981e5; }
.recharge-overlay__salespeople { min-width: 0; }
.recharge-overlay__salespeople header { display: flex; align-items: center; gap: 10px; }
.recharge-overlay__salespeople header strong { color: #263445; }
.recharge-overlay__salespeople header span { flex: 1; color: #5c6a7e; font-size: 13px; text-align: right; }
.recharge-overlay__salesperson-list { display: grid; gap: 8px; margin-top: 12px; }
.recharge-overlay__salesperson-list label { display: grid; grid-template-columns: minmax(0, 1fr) 150px auto; align-items: center; gap: 10px; color: #3f4c5c; }
.recharge-overlay__salesperson-list input { width: 100%; height: 34px; padding: 0 10px; border: 1px solid #cfd7e3; border-radius: 5px; }
.recharge-overlay__payments { margin: 0 24px 22px; border-top: 1px solid #edf0f4; padding-top: 16px; }
.recharge-overlay__payments header { display: flex; justify-content: space-between; gap: 12px; color: #5c6a7e; font-size: 13px; }
.recharge-overlay__payments header strong { color: #263445; font-size: 14px; }
.recharge-overlay__payment-list { display: grid; gap: 8px; margin-top: 12px; }
.recharge-overlay__payment-line { display: grid; grid-template-columns: 120px 120px minmax(0, 1fr) auto; gap: 8px; }
.recharge-overlay__payment-line select, .recharge-overlay__payment-line input, .recharge-overlay__payments footer select { min-width: 0; height: 34px; padding: 0 9px; border: 1px solid #cfd7e3; border-radius: 5px; background: #fff; color: #202b3a; }
.recharge-overlay__payments footer { display: flex; justify-content: flex-end; gap: 8px; margin-top: 10px; }
.recharge-overlay__error { margin: -4px 24px 0; color: #c63434; font-size: 13px; }
.recharge-overlay__footer { justify-content: flex-end; border-top: 1px solid #edf0f4; }
@media (max-width: 720px) {
  .recharge-overlay__packages { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .recharge-overlay__fields--custom { grid-template-columns: minmax(0, 1fr); }
  .recharge-overlay__context { grid-template-columns: minmax(0, 1fr); gap: 16px; }
}
</style>

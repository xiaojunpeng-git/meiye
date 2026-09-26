<script setup>
import { computed, ref, watch } from 'vue'
import { formatMoney } from '@/services/cashierV3Bridge'
import {
  entitlementBenefitPoolId,
  entitlementCardHolderId
} from '@/services/cashierV3EntitlementDraftContract'

const props = defineProps({
  selector: {
    type: Object,
    default: () => ({})
  },
  submitting: {
    type: Boolean,
    default: false
  },
  workspaceId: {
    type: [String, Number],
    default: ''
  },
  currentMemberId: {
    type: [String, Number],
    default: ''
  },
  cartLines: {
    type: Array,
    default: () => []
  },
  pendingProjectKey: {
    type: String,
    default: ''
  },
  addedProjectKey: {
    type: String,
    default: ''
  },
  operationMode: {
    type: String,
    default: ''
  },
  operationLabel: {
    type: String,
    default: ''
  },
  selectedOperationProjectKeys: {
    type: Array,
    default: () => []
  },
  selectedOperationSourceIds: {
    type: Array,
    default: () => []
  },
  loadState: {
    type: String,
    default: 'ready'
  },
  loadErrorMessage: {
    type: String,
    default: ''
  }
})

const emit = defineEmits(['close', 'retry', 'add', 'contract-error', 'operation-source', 'operation-project', 'operation-target', 'operation-confirm'])
const localSources = ref([])
const keyword = ref('')
const appliedKeyword = ref('')
const sourceFilter = ref('valid')
const editingSource = ref(null)
const operationDate = ref('')
const operationReason = ref('')

const member = computed(() => props.selector.member || null)
const selectorIdentity = computed(() => [
  props.selector.selectorToken || props.selector.snapshotToken || '',
  props.selector.selectorRequestId || '',
  member.value?.id || member.value?.memberId || ''
].join(':'))

function sourceContractReady(source = {}) {
  const sourceId = entitlementCardHolderId(source)
  if (!sourceId) return false
  // 延期、转让、停用、启用只操作卡本身；不应因历史卡没有项目明细
  // 或旧的金额/版本坐标而阻止生成本次浏览器快照。
  if (['card-extension', 'card-transfer', 'card-disable', 'card-enable'].includes(props.operationMode)) return true
  if (!Array.isArray(source.projects)) return false
  return source.projects.every((project) => (
    Boolean(project.projectId || project.id)
    && Boolean(entitlementBenefitPoolId(project))
    && Number(project.remainingTimes) >= 0
    && Number(project.occupiedTimes ?? project.reservedTimes) >= 0
    && Number(project.availableTimes) >= 0
  ))
}

const selectorReady = computed(() => Boolean(
  props.selector.ready === true
  && member.value
  && (member.value.id || member.value.memberId)
  && String(member.value.id || member.value.memberId) === String(props.currentMemberId || '')
  && Boolean(props.workspaceId)
  && (props.selector.selectorToken || props.selector.snapshotToken)
  && Array.isArray(props.selector.sources)
  && props.selector.sources.every(sourceContractReady)
))
const isLoading = computed(() => props.loadState === 'loading')
const hasLoadError = computed(() => props.loadState === 'error')

function clonePlain(value) {
  return JSON.parse(JSON.stringify(value))
}

function resetSources() {
  localSources.value = clonePlain(Array.isArray(props.selector.sources) ? props.selector.sources : [])
}

watch(selectorIdentity, resetSources, { immediate: true })
watch(() => props.operationMode, (mode) => {
  sourceFilter.value = mode === 'card-enable' ? 'all' : 'valid'
}, { immediate: true })

function isSourceAvailable(source = {}) {
  const status = String(source.statusCode || source.status || '').trim().toLocaleLowerCase()
  return source.disabled !== true
    && source.selectable !== false
    && (!status || ['可用', 'available', 'valid', 'enabled'].includes(status))
}

// “有效卡”是会员资产视图：有剩余次数就必须让门店看见。
// 能否本次使用仍由 isSourceAvailable/project.disabled 决定，欠款、
// 到期或停用不能因此把卡从默认视图藏起来。
function sourceHasRemainingTimes(source = {}) {
  const sourceTimes = Number(source.remainingTimes)
  if (Number.isFinite(sourceTimes) && sourceTimes > 0) return true
  return (source.projects || []).some((project) => {
    const projectTimes = Number(project?.remainingTimes)
    return Number.isFinite(projectTimes) && projectTimes > 0
  })
}

function sourceIsExpired(source = {}) {
  const expiryDate = String(source.expiryDate || '').trim()
  if (!/^\d{4}-\d{2}-\d{2}$/.test(expiryDate)) return false
  const endOfExpiryDay = Date.parse(`${expiryDate}T23:59:59`)
  return Number.isFinite(endOfExpiryDay) && endOfExpiryDay < Date.now()
}

function sourceMatchesValidCardFilter(source = {}) {
  const status = String(source.statusCode || source.status || '').trim().toLocaleLowerCase()
  return sourceHasRemainingTimes(source)
    && source.disabled !== true
    && status !== 'disabled'
    && !sourceIsExpired(source)
}

function sourceMatchesKeyword(source = {}, normalizedKeyword = '') {
  if (!normalizedKeyword) return true
  const searchable = [
    source.name,
    source.reference,
    source.fullCardNo,
    ...(Array.isArray(source.projects) ? source.projects.map((project) => project.name) : [])
  ].filter(Boolean).join(' ').toLocaleLowerCase()
  return searchable.includes(normalizedKeyword)
}

function projectKey(source = {}, project = {}) {
  return `${entitlementCardHolderId(source)}:${entitlementBenefitPoolId(project)}`
}

function cartQuantity(source = {}, project = {}) {
  const holderId = String(entitlementCardHolderId(source) || '')
  const detailId = String(entitlementBenefitPoolId(project) || '')
  return props.cartLines.reduce((total, item) => {
    const matched = item?.lineRole === 'entitlement_service'
      && String(item.entitlementInstanceId || item.cardHolderId || '') === holderId
      && String(item.entitlementSourceDetailId || item.memberBenefitPoolId || '') === detailId
    if (!matched) return total
    const quantity = Number(item.quantity || 0)
    return total + (Number.isInteger(quantity) && quantity > 0 ? quantity : 0)
  }, 0)
}

function sourceHasAddedProject(source = {}) {
  return (source.projects || []).some((project) => cartQuantity(source, project) > 0)
}

const visibleSources = computed(() => {
  const normalizedKeyword = appliedKeyword.value.trim().toLocaleLowerCase()
  return localSources.value.filter((source) => {
    // Card enable must never offer an already enabled card as an actionable target.
    if (props.operationMode === 'card-enable'
      && String(source.statusCode || '').trim().toLocaleLowerCase() !== 'disabled') {
      return false
    }
    const filterMatched = sourceFilter.value === 'all' || sourceMatchesValidCardFilter(source)
    return filterMatched && sourceMatchesKeyword(source, normalizedKeyword)
  })
})

function applyKeyword() {
  appliedKeyword.value = keyword.value
}

function sourceTypeLabel(source = {}) {
  const explicitKind = String(source.sourceKindLabel || '').trim()
  if (['次卡', '时间卡', '定制卡', '赠送'].includes(explicitKind)) return explicitKind
  // 历史权益没有统一的细分卡类型字段，未知时不根据次数或有效期猜测类型。
  return '—'
}

// 权益金额仅在展示边界按元取整；不得回写到权益快照或用于核销计算。
// 沿用统一金额格式化的千分位，列表已有金额列语义，不重复显示货币符号。
function displayAmount(value) {
  if (value === null || value === undefined || value === '') return '—'
  const amount = Number(value)
  if (!Number.isFinite(amount)) return '—'
  return formatMoney(Math.round(amount)).replace(/^¥/, '')
}

function displayNumber(value) {
  if (value === null || value === undefined || value === '') return '—'
  const number = Number(value)
  if (!Number.isFinite(number)) return '—'
  return new Intl.NumberFormat('zh-CN', { maximumFractionDigits: 2 }).format(number)
}

// 任选次数卡的次数是卡级共享额度，不能同时展示到每个子项目上。
// 父行展示共享剩余次数，项目行用短横线避免误解为每个项目各有同样次数。
function isSharedChoiceCountSource(source = {}) {
  return String(source.cardRuleType || '').trim() === 'choice_count'
}

function isTimeCardSource(source = {}) {
  return String(source.sourceKind || '').trim() === 'time_card'
    || String(source.cardRuleType || '').trim() === 'time'
    || source.unlimited === true
}

function displaySourceRemainingTimes(source = {}) {
  return isTimeCardSource(source) ? '—' : displayNumber(source.remainingTimes)
}

function displayProjectRemainingTimes(source = {}, project = {}) {
  return isTimeCardSource(source) || isSharedChoiceCountSource(source)
    ? '—'
    : displayNumber(project.remainingTimes)
}

function displayProjectRemainingAmount(source = {}, project = {}) {
  return isTimeCardSource(source) ? '—' : displayAmount(project.remainingAmount)
}

function sourceCardNo(source = {}) {
  return String(source.fullCardNo || '—').trim() || '—'
}

function sourceExpiryDate(source = {}) {
  return String(source.expiryDate || '—').trim() || '—'
}

function sourceOrderRemark(source = {}) {
  return String(source.orderRemark || '').trim()
}

function projectDisabled(source = {}, project = {}) {
  if (props.submitting || !selectorReady.value) return true
  // 次数不足只在最终确认服务/收款时校验。这里仍保留卡失效、停用等
  // 非次数原因，避免把不可用权益伪装成可选项目。
  return !isSourceAvailable(source) || project.disabled === true
}

function addProject(source = {}, project = {}) {
  if (projectDisabled(source, project)) return
  if (['project-replacement', 'project-upgrade'].includes(props.operationMode)) {
    emit('operation-project', { source: clonePlain(source), project: clonePlain(project) })
    return
  }
  const line = {
    cardHolderId: entitlementCardHolderId(source),
    memberBenefitPoolId: entitlementBenefitPoolId(project),
    entitlementInstanceId: source.entitlementInstanceId || source.id,
    entitlementInstanceType: source.entitlementInstanceType || source.sourceType,
    entitlementSourceDetailId: project.entitlementSourceDetailId || project.sourceDetailId || project.id,
    projectId: project.projectId || project.id,
    quantity: 1,
    displaySnapshot: {
      name: String(project.name || project.projectName || '项目'),
      kind: '项目',
      entitlementInstanceType: String(source.entitlementInstanceType || source.sourceType || ''),
      entitlementSourceKind: project.isGift ? 'gift' : String(source.sourceKind || ''),
      isGift: Boolean(project.isGift),
      giftSourceType: project.isGift ? 'holder_backed' : 'none',
      sourceType: String(project.sourceType || ''),
      sourceDetailId: Number(project.entitlementSourceDetailId || project.sourceDetailId || project.id || 0),
      entitlementSourceName: String(source.name || ''),
      fullCardNo: String(source.fullCardNo || ''),
      remainingTimes: Number(project.remainingTimes || 0),
      occupiedTimes: Number(project.occupiedTimes || 0),
      availableTimes: Number(project.availableTimes || 0),
      purchaseAmount: String(project.purchaseAmount || ''),
      totalPurchaseTimes: Number(project.totalPurchaseTimes || 0),
      consumedTimesAtSelection: Number(project.consumedTimesAtSelection || 0),
      amountCalculationVersion: String(project.amountCalculationVersion || ''),
      amountRole: 'entitlement_actual',
      validThroughLabel: String(project.validThroughLabel || source.validThroughLabel || ''),
      expiryDate: String(project.expiryDate || source.expiryDate || ''),
      debtRestrictionLabel: String(project.debtRestrictionLabel || ''),
      serviceSource: '卡内项目'
    }
  }
  emit('add', {
    selectorRequestId: props.selector.selectorRequestId,
    selectorToken: props.selector.selectorToken || props.selector.snapshotToken,
    memberId: member.value.id || member.value.memberId,
    mutationMode: 'append',
    projectKey: projectKey(source, project),
    lines: [line]
  })
}

function sourceOperationLabel() {
  return {
    'card-upgrade': '升级',
    'card-transfer': '转让',
    'card-extension': '延期',
    'card-disable': '停用',
    'card-enable': '启用'
  }[props.operationMode] || ''
}

function chooseSourceOperation(source = {}) {
  if (props.operationMode === 'card-enable') {
    if (String(source.statusCode || '').toLowerCase() !== 'disabled') {
      emit('contract-error', { code: 'CARD_NOT_DISABLED', message: '该卡当前不是停用状态，无需启用。' })
      return
    }
  } else if (!isSourceAvailable(source)) {
    emit('contract-error', { code: 'CARD_UNAVAILABLE', message: '该卡当前不可办理此操作。' })
    return
  }
  if (['card-upgrade', 'card-transfer'].includes(props.operationMode)) {
    emit('operation-source', { source: clonePlain(source) })
    return
  }
  editingSource.value = clonePlain(source)
  operationDate.value = String(source.expiryDate || '')
  operationReason.value = ''
}

function operationSourceSelected(source = {}) {
  return props.selectedOperationSourceIds.includes(String(source.id || source.cardHolderId || source.entitlementInstanceId || ''))
}

function confirmSourceOperation() {
  if (!operationReason.value.trim()) return
  if (props.operationMode === 'card-extension' && !operationDate.value) return
  emit('operation-confirm', {
    source: clonePlain(editingSource.value),
    date: operationDate.value,
    reason: operationReason.value.trim()
  })
  editingSource.value = null
}

function addButtonLabel(source = {}, project = {}) {
  const key = projectKey(source, project)
  if (props.pendingProjectKey === key) return '添加中…'
  return '添加'
}

function operationProjectSelected(source = {}, project = {}) {
  return props.selectedOperationProjectKeys.includes(projectKey(source, project))
}
</script>

<template>
  <section class="cashier-entitlement-selector-panel" aria-label="使用权益">
    <header class="cashier-entitlement-selector-panel__header">
      <strong>{{ operationLabel || '使用权益' }}</strong>
      <button type="button" class="button button--primary cashier-entitlement-selector-panel__return-button" :disabled="submitting" @click="emit('close')">返回选购</button>
    </header>

    <section v-if="isLoading" class="cashier-entitlement-selector__blocked" role="status" aria-live="polite">
      <strong>正在加载会员权益</strong>
      <span>正在核对当前会员的卡项、项目和可用次数。</span>
    </section>

    <section v-else-if="hasLoadError" class="cashier-entitlement-selector__blocked" role="alert">
      <strong>会员权益加载失败</strong>
      <span>{{ loadErrorMessage || '未取得完整的权益数据，本次没有加入购物车。' }}</span>
      <button type="button" class="button button--primary" :disabled="submitting" @click="emit('retry')">重新加载</button>
    </section>

    <section v-else-if="!selectorReady" class="cashier-entitlement-selector__blocked" role="alert">
      <strong>权益项目尚未完整加载</strong>
      <span>请返回选购后重新打开，未取得后端权威来源、金额和版本前不能加入购物车。</span>
    </section>

    <template v-else>
      <section class="cashier-entitlement-selector__filters" aria-label="权益项目筛选">
        <label class="search-field cashier-entitlement-selector__search">
          <span class="sr-only">请选择客户需要服务的项目</span>
          <input
            v-model="keyword"
            type="search"
            placeholder="请选择客户需要服务的项目"
            autocomplete="off"
            @keyup.enter="applyKeyword"
          >
        </label>
        <button type="button" class="button button--secondary cashier-entitlement-selector__filter-button" @click="applyKeyword">查询</button>
        <template v-if="operationMode === 'card-enable'">
          <span class="cashier-entitlement-selector__filter-hint">已停用卡</span>
        </template>
        <template v-else>
          <button
            type="button"
            class="button button--secondary cashier-entitlement-selector__filter-button"
            :class="{ 'is-active': sourceFilter === 'valid' }"
            @click="sourceFilter = 'valid'"
          >有效卡</button>
          <button
            type="button"
            class="button button--secondary cashier-entitlement-selector__filter-button"
            :class="{ 'is-active': sourceFilter === 'all' }"
            @click="sourceFilter = 'all'"
          >全部</button>
        </template>
      </section>

      <div v-if="operationMode === 'project-replacement' && selectedOperationProjectKeys.length" class="cashier-entitlement-selector__operation-next">
        <span>已选择 {{ selectedOperationProjectKeys.length }} 个原项目</span>
        <button type="button" class="button button--primary cashier-entitlement-selector__operation-target-button" @click="emit('operation-target')">选择目标项目</button>
      </div>
      <div v-if="operationMode === 'card-upgrade' && selectedOperationSourceIds.length" class="cashier-entitlement-selector__operation-next">
        <span>已选择 {{ selectedOperationSourceIds.length }} 张原卡；结账时将全部结束并抵扣。</span>
        <button type="button" class="button button--primary cashier-entitlement-selector__operation-target-button" @click="emit('operation-target')">选择目标卡</button>
      </div>

      <section class="cashier-entitlement-selector__content" aria-label="可使用权益">
        <div v-if="visibleSources.length" class="cashier-entitlement-table" role="table" aria-label="会员权益项目">
          <div class="cashier-entitlement-table__head" role="row">
            <span>卡项名称</span>
            <span>余次</span>
            <span>余额</span>
            <span>欠款</span>
            <span>有效期</span>
            <span aria-label="操作" />
          </div>

          <template v-for="source in visibleSources" :key="source.id || source.entitlementInstanceId">
            <article
              class="cashier-entitlement-table__row cashier-entitlement-table__row--source"
              :class="{ 'is-unavailable': !isSourceAvailable(source) }"
              role="row"
            >
              <div class="cashier-entitlement-table__source-name" :title="sourceOrderRemark(source) || undefined">
                <div>
                  <strong>{{ source.name || '卡项' }}</strong>
                  <span class="cashier-entitlement-table__type-tag">{{ sourceTypeLabel(source) }}</span>
                </div>
                <small :title="source.disabledReason || sourceCardNo(source)">{{ source.disabledReason || sourceCardNo(source) }}</small>
              </div>
              <span>{{ displaySourceRemainingTimes(source) }}</span>
              <span>{{ displayAmount(source.remainingAmount) }}</span>
              <span>{{ displayAmount(source.outstandingDebtAmount) }}</span>
              <span>{{ sourceExpiryDate(source) }}</span>
              <button
                v-if="sourceOperationLabel()"
                type="button"
                class="cashier-entitlement-table__add"
                :disabled="operationMode !== 'card-enable' && !isSourceAvailable(source)"
                @click="chooseSourceOperation(source)"
              >{{ operationMode === 'card-upgrade' && operationSourceSelected(source)
                ? '已选择'
                : operationMode === 'card-upgrade' && selectedOperationSourceIds.length
                  ? '添加'
                  : sourceOperationLabel() }}</button>
              <span v-else />
            </article>

            <article
              v-for="project in source.projects || []"
              :key="project.id || project.projectId"
              class="cashier-entitlement-table__row cashier-entitlement-table__row--project"
              :class="{
                'is-added': addedProjectKey === projectKey(source, project),
                'is-unavailable': projectDisabled(source, project) && pendingProjectKey !== projectKey(source, project)
              }"
              role="row"
            >
              <div class="cashier-entitlement-table__project-name">
                <strong :title="project.name || '项目'">{{ project.name || '项目' }}</strong>
                <small v-if="project.disabledReason || project.unavailableReason">{{ project.disabledReason || project.unavailableReason }}</small>
              </div>
              <span>{{ displayProjectRemainingTimes(source, project) }}</span>
              <span>{{ displayProjectRemainingAmount(source, project) }}</span>
              <span aria-hidden="true" />
              <span aria-hidden="true" />
              <button
                v-if="!sourceOperationLabel()"
                type="button"
                class="cashier-entitlement-table__add"
                :class="{ 'is-added': addedProjectKey === projectKey(source, project) }"
                :disabled="projectDisabled(source, project)"
                :title="projectDisabled(source, project) ? (project.disabledReason || project.unavailableReason || '当前权益不可使用') : '添加到购物车'"
                @click="addProject(source, project)"
              >{{ ['project-replacement', 'project-upgrade'].includes(operationMode) ? (operationProjectSelected(source, project) ? '已添加' : '添加') : addButtonLabel(source, project) }}</button>
            </article>
          </template>
        </div>
        <div v-else class="cashier-entitlement-selector__empty">
          <strong>{{ localSources.length ? '暂无符合条件的权益项目' : '当前会员暂无可使用权益' }}</strong>
          <span>{{ localSources.length ? '可切换筛选条件或修改查询内容后重试。' : '该会员目前没有可用的卡内项目。' }}</span>
        </div>
      </section>

      <div v-if="editingSource" class="cashier-card-operation-editor" role="dialog" :aria-label="operationLabel">
        <div class="cashier-card-operation-editor__panel">
          <header><strong>{{ operationLabel }} · {{ editingSource.name }}</strong></header>
          <label v-if="operationMode === 'card-extension'">新的有效期<input v-model="operationDate" type="date"></label>
          <label>原因<textarea v-model="operationReason" rows="3" placeholder="请填写原因"></textarea></label>
          <footer>
            <button type="button" class="button button--secondary" @click="editingSource = null">取消</button>
            <button type="button" class="button button--primary" :disabled="!operationReason.trim() || (operationMode === 'card-extension' && !operationDate)" @click="confirmSourceOperation">确认</button>
          </footer>
        </div>
      </div>
    </template>
  </section>
</template>

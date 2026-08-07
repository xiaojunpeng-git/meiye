<script setup>
import { computed, ref, watch } from 'vue'
import {
  canonicalEntitlementCommandContexts,
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
  loadState: {
    type: String,
    default: 'ready'
  },
  loadErrorMessage: {
    type: String,
    default: ''
  }
})

const emit = defineEmits(['close', 'retry', 'add', 'contract-error', 'operation-source', 'operation-project', 'operation-confirm'])
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

function positiveVersion(value) {
  const version = Number(value)
  return Number.isInteger(version) && version > 0 ? version : null
}

function validActualAmountAllocation(project = {}) {
  const purchaseAmount = project.purchaseAmount
  const totalPurchaseTimes = Number(project.totalPurchaseTimes)
  const consumedTimes = Number(project.consumedTimesAtSelection)
  const sourceVersion = Number(project.amountSourceVersion ?? project.version ?? project.revision)
  return typeof purchaseAmount === 'string'
    && /^(?:0|[1-9]\d*)(?:\.0{1,2})?$/.test(purchaseAmount)
    && Number.isInteger(totalPurchaseTimes)
    && totalPurchaseTimes > 0
    && Number.isInteger(consumedTimes)
    && consumedTimes >= 0
    && consumedTimes <= totalPurchaseTimes
    && positiveVersion(sourceVersion) !== null
    && typeof project.amountCalculationVersion === 'string'
    && project.amountCalculationVersion.endsWith('whole-yuan-floor-final-remainder-v1')
}

function sourceContractReady(source = {}) {
  const sourceId = entitlementCardHolderId(source)
  if (!sourceId || positiveVersion(source.version ?? source.revision) === null || !Array.isArray(source.projects)) return false
  return source.projects.every((project) => (
    Boolean(project.projectId || project.id)
    && Boolean(entitlementBenefitPoolId(project))
    && positiveVersion(project.version ?? project.revision) !== null
    && Number(project.remainingTimes) >= 0
    && Number(project.occupiedTimes ?? project.reservedTimes) >= 0
    && Number(project.availableTimes) >= 0
    && (project.selectable === false || validActualAmountAllocation(project))
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
  && Array.isArray(props.selector.commandContexts)
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
    const filterMatched = sourceFilter.value === 'all' || isSourceAvailable(source)
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
  return isTimeCardSource(source) ? '—' : displayNumber(project.remainingAmount)
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

function projectAvailableQuantity(project = {}) {
  const quantity = Number(project.availableTimes)
  return Number.isInteger(quantity) && quantity >= 0 ? quantity : 0
}

function remainingAddableQuantity(source = {}, project = {}) {
  return Math.max(0, projectAvailableQuantity(project) - cartQuantity(source, project))
}

function projectDisabled(source = {}, project = {}) {
  return props.submitting
    || !selectorReady.value
    || !isSourceAvailable(source)
    || project.disabled === true
    || project.selectable === false
    || remainingAddableQuantity(source, project) < 1
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
    entitlementSourceVersion: Number(source.version || source.revision),
    projectId: project.projectId || project.id,
    projectVersion: Number(project.version || project.revision),
    quantity: 1
  }
  const commandContexts = canonicalEntitlementCommandContexts({
    suppliedContexts: props.selector.commandContexts,
    selectedLines: [line],
    workspaceId: props.workspaceId,
    memberId: props.currentMemberId
  })
  if (!commandContexts) {
    emit('contract-error', {
      code: 'ENTITLEMENT_COMMAND_CONTEXT_INCOMPLETE',
      message: '会员权益版本数据不完整，请重新打开。'
    })
    return
  }
  emit('add', {
    selectorRequestId: props.selector.selectorRequestId,
    selectorToken: props.selector.selectorToken || props.selector.snapshotToken,
    memberId: member.value.id || member.value.memberId,
    commandContexts,
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

      <section class="cashier-entitlement-selector__content" aria-label="可使用权益">
        <div v-if="visibleSources.length" class="cashier-entitlement-table" role="table" aria-label="会员权益项目">
          <div class="cashier-entitlement-table__head" role="row">
            <span>卡项名称</span>
            <span>余次</span>
            <span>余额</span>
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
                <small :title="sourceCardNo(source)">{{ sourceCardNo(source) }}</small>
              </div>
              <span>{{ displaySourceRemainingTimes(source) }}</span>
              <span>{{ displayNumber(source.remainingAmount) }}</span>
              <span>{{ sourceExpiryDate(source) }}</span>
              <button
                v-if="sourceOperationLabel()"
                type="button"
                class="cashier-entitlement-table__add"
                :disabled="operationMode !== 'card-enable' && !isSourceAvailable(source)"
                @click="chooseSourceOperation(source)"
              >{{ sourceOperationLabel() }}</button>
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
              <button
                v-if="!sourceOperationLabel()"
                type="button"
                class="cashier-entitlement-table__add"
                :class="{ 'is-added': addedProjectKey === projectKey(source, project) }"
                :disabled="projectDisabled(source, project)"
                :title="remainingAddableQuantity(source, project) < 1 ? '已达到可用次数' : `添加${project.name || '项目'}到购物车`"
                @click="addProject(source, project)"
              >{{ ['project-replacement', 'project-upgrade'].includes(operationMode) ? '添加' : addButtonLabel(source, project) }}</button>
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

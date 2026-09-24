<script setup>
import { computed, ref, watch } from 'vue'

const props = defineProps({
  initialTab: { type: String, default: 'craftsmen' },
  showCraftsmen: { type: Boolean, default: true },
  showSalespeople: { type: Boolean, default: true },
  showGuides: { type: Boolean, default: false },
  showSalesManagers: { type: Boolean, default: false },
  // 游客没有会员导购轮次；仍可记录导购归属，但不能占用会员的第 1～3 轮。
  guestCustomer: { type: Boolean, default: false },
  // 订单中心单行调整复用同一控件，但需要直接进入完整分配模式；收银默认行为不变。
  initialMode: { type: String, default: '' },
  requireCraftsmen: { type: Boolean, default: false },
  craftsmenCandidates: { type: Array, default: () => [] },
  allowOtherCraftsmen: { type: Boolean, default: false },
  otherCraftsmanCandidates: { type: Array, default: () => [] },
  storeId: { type: [Number, String], default: 0 },
  salespersonCandidates: { type: Array, default: () => [] },
  guideCandidates: { type: Array, default: () => [] },
  salesManagerCandidates: { type: Array, default: () => [] },
  attributionSearchLoading: { type: Boolean, default: false },
  attributionSearchError: { type: String, default: '' },
  selectedCraftsmen: { type: Array, default: () => [] },
  selectedSalespeople: { type: Array, default: () => [] },
  selectedGuides: { type: Array, default: () => [] },
  selectedSalesManagers: { type: Array, default: () => [] },
  laborDefaultFee: { type: [Number, String], default: 0 },
  laborManualFee: { type: [Number, String], default: null },
  allowLaborOverride: { type: Boolean, default: false },
  projectCountTotal: { type: [Number, String], default: 1 },
  historyAdjustment: { type: Boolean, default: false },
  allocationTotalAmountCents: { type: [Number, String], default: 0 },
  // 当前收银行的业绩基数只用于“改比例时”的即时展示。最终结账仍以
  // 服务端锁定后的实际业务基数重算自动金额；手工金额则原样进入快照。
  performanceBaseAmountCents: { type: [Number, String], default: 0 },
  loading: { type: Boolean, default: false },
  saving: { type: Boolean, default: false },
  loadError: { type: String, default: '' }
})
const emit = defineEmits(['close', 'confirm', 'apply-all', 'retry', 'search-personnel'])

const mode = ref(props.historyAdjustment || props.initialMode === 'full' ? 'full' : 'simple')
const resolveInitialTab = (tab = '') => {
  return tab
}
const initialTab = resolveInitialTab(props.initialTab)
const firstVisibleTab = props.showCraftsmen
  ? 'craftsmen'
  : props.showSalespeople
    ? 'salespeople'
    : (props.showGuides || props.showSalesManagers)
      ? 'guides'
      : 'salesManagers'
const activeTab = ref(
  [
    props.showCraftsmen && 'craftsmen',
    props.showSalespeople && 'salespeople',
    props.showGuides && 'guides',
    props.showSalesManagers && 'salesManagers'
  ].includes(initialTab)
    ? initialTab
    : firstVisibleTab
)
const keyword = ref('')
const groupKeyword = ref('')
const craftsmen = ref([])
const otherCraftsmanCandidates = ref([])
const salespeople = ref([])
const guides = ref([])
const salesManagers = ref([])
const guideRoundNo = ref(props.guestCustomer ? 'none' : '')
const attributionSearchOpen = ref(false)
const attributionSearchRole = ref('guide')
const otherCraftsmanSearchOpen = ref(false)
const otherCraftsmanKeyword = ref('')
const validationMessage = ref('')
const PERFORMANCE_TYPES = {
  COMMISSION: 'commission',
  LABOR: 'labor',
  COMMISSION_LABOR: 'commission_labor'
}

function craftsmanType(item = {}) {
  const type = String(item.craftsmanPerformanceType || item.craftsman_performance_type || '')
  return Object.values(PERFORMANCE_TYPES).includes(type) ? type : PERFORMANCE_TYPES.COMMISSION
}

function performanceTypeLabel(item = {}) {
  const type = craftsmanType(item)
  return type === PERFORMANCE_TYPES.COMMISSION ? '消耗业绩'
    : type === PERFORMANCE_TYPES.LABOR ? '手工费'
      : '消耗业绩+手工费'
}

function defaultLaborFeeCents() {
  return Math.max(0, Number(props.laborDefaultFee || 0) * 100)
}

function laborFeeCentsFor(item = {}) {
  if (craftsmanType(item) === PERFORMANCE_TYPES.COMMISSION) return 0
  const saved = Number(item.laborFeeCents ?? item.labor_fee_cents ?? 0)
  const candidateDefault = Number(item.laborDefaultFeeCents ?? item.labor_default_fee_cents ?? 0)
  return saved > 0
    ? saved
    : (candidateDefault > 0 ? candidateDefault : defaultLaborFeeCents())
}

function allocationAmountCentsFor(item = {}) {
  return Math.max(0, Math.trunc(Number(item.allocationAmountCents ?? item.amountCents ?? 0)))
}

function allocationAmountYuanText(item = {}) {
  const cents = allocationAmountCentsFor(item)
  // 历史服务调整的消耗业绩按整元分配。若旧数据本身不是整元，
  // 保留原值供校验提示，不能在前端静默截断。
  return String(cents / 100)
}

function performanceBaseCents() {
  return Math.max(0, Math.trunc(Number(props.performanceBaseAmountCents || 0)))
}

function performanceAmountCentsFor(item = {}) {
  return Math.max(0, Math.trunc(Number(
    item.performanceAmountCents ?? item.performance_amount_cents ?? 0
  )))
}

function performanceAmountYuanText(item = {}) {
  return String(performanceAmountCentsFor(item) / 100)
}

function calculatedPerformanceAmountCents(ratio) {
  // 本页金额按整元编辑和展示；分的内部精度仍由最终结账事实保留。
  return Math.max(0, Math.round(performanceBaseCents() * Number(ratio || 0) / 10000) * 100)
}

function syncPerformanceAmountFromRatio(item) {
  // 历史调整的手艺人使用单独的“消耗业绩”输入；销售人的金额栏仍是
  // 可编辑业务字段，不能因共用 historyAdjustment 弹窗而跳过同步。
  if ((props.historyAdjustment && item.role === 'craftsmen') || craftsmanType(item) === PERFORMANCE_TYPES.LABOR) return
  const ratio = Math.max(0, Math.min(100, Math.trunc(Number(item.performance || 0))))
  item.performance = ratio
  item.performanceAmountCents = calculatedPerformanceAmountCents(ratio)
  item.performanceAmountYuan = performanceAmountYuanText(item)
  item.performanceAmountManual = false
  item.performanceAmountLocked = false
}

function syncManualPerformanceAmount(item) {
  if ((props.historyAdjustment && item.role === 'craftsmen') || craftsmanType(item) === PERFORMANCE_TYPES.LABOR) return
  const yuan = String(item.performanceAmountYuan ?? '').trim()
  const cents = /^\d+$/.test(yuan) ? Number(yuan) * 100 : 0
  item.performanceAmountCents = Math.max(0, Math.trunc(cents))
  item.performanceAmountYuan = performanceAmountYuanText(item)
  // 手改金额是独立的最终分配值，绝不反向改比例或平衡其他人员。
  item.performanceAmountManual = true
  item.performanceAmountLocked = false
  validationMessage.value = ''
}

function syncComputedPerformanceAmounts(records) {
  if (props.historyAdjustment || !Array.isArray(records)) return
  records.filter((record) => record.selected
    && craftsmanType(record) !== PERFORMANCE_TYPES.LABOR
    // 订单中心打开历史记录时，业绩事实中已保存的金额才是展示权威。
    // 不能因为当前页面未传入新的收银基数而重算成 0；操作员修改比例或
    // 金额后会解除锁定，改按当前编辑值计算。
    && !record.performanceAmountManual
    && !record.performanceAmountLocked)
    .forEach((record) => syncPerformanceAmountFromRatio(record))
}

const PROJECT_COUNT_SCALE = 1000000

function projectCountTextFor(item = {}) {
  // 输入框绑定的是 projectCountText。完整分配确认前若仍读取旧的
  // projectCount，用户刚输入的 6.6 / 0.3 会被初始值 0 覆盖，最终
  // 结账快照缺失项目数并在服务记录回退显示为服务次数 1。
  const explicit = String(item.projectCountText ?? item.projectCount ?? '').trim()
  if (/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/.test(explicit)) {
    const [whole, fraction = ''] = explicit.split('.')
    const normalizedFraction = fraction.replace(/0+$/, '')
    return normalizedFraction ? `${whole}.${normalizedFraction}` : whole
  }
  // 原生 number 输入框在部分浏览器自动换算小数时会吐出
  // `6.599999904632568` 这一类浮点尾差。界面仍显示 6.6，但旧逻辑会
  // 因超过 6 位小数直接回退为 0，造成“弹窗填了、结账保存为 0”。
  // 将它按后端允许的 6 位小数规范化，避免把用户可见的输入静默丢失。
  const numeric = Number(explicit)
  if (/^(?:0|[1-9]\d*)(?:\.\d+)?$/.test(explicit) && Number.isFinite(numeric) && numeric >= 0) {
    const microUnits = Math.round(numeric * PROJECT_COUNT_SCALE)
    if (Number.isSafeInteger(microUnits)) return projectCountTextFromMicroUnits(microUnits)
  }
  const saved = Number(item.projectCountHalfUnits)
  if (Number.isInteger(saved) && saved >= 0) return String(saved / 2)
  return '0'
}

function projectCountMicroUnitsForText(value) {
  const raw = String(value ?? '').trim()
  if (!/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/.test(raw)) return null
  const [whole, fraction = ''] = raw.split('.')
  const micro = Number(whole) * PROJECT_COUNT_SCALE
    + Number((fraction + '000000').slice(0, 6))
  return Number.isSafeInteger(micro) && micro >= 0 ? micro : null
}

function projectCountTextFromMicroUnits(microUnits) {
  const value = Math.max(0, Math.trunc(Number(microUnits) || 0))
  const whole = Math.floor(value / PROJECT_COUNT_SCALE)
  const fraction = String(value % PROJECT_COUNT_SCALE).padStart(6, '0').replace(/0+$/, '')
  return fraction ? `${whole}.${fraction}` : String(whole)
}

function performanceIndependent(item = {}) {
  return item.performanceIndependent === true
    || Number(item.performanceIndependent ?? item.performance_independent ?? 0) === 1
}

function hasSavedAllocationWeight(item = {}) {
  return Object.prototype.hasOwnProperty.call(item, 'allocationWeight')
    || Object.prototype.hasOwnProperty.call(item, 'laborWeight')
    || Object.prototype.hasOwnProperty.call(item, 'performance')
}

function hasPerformanceIndependentMetadata(item = {}) {
  return Object.prototype.hasOwnProperty.call(item, 'performanceIndependent')
    || Object.prototype.hasOwnProperty.call(item, 'performance_independent')
}

function allocationGroupKey(item = {}) {
  // Keep an explicit independent group key when a local draft/snapshot carries
  // the group metadata but an older projection omitted the boolean flag. The
  // key is emitted by the authoritative candidate query and is also persisted
  // in the personnel snapshot, so dropping it here would silently merge an
  // independent position back into the normal 100% pool.
  const explicitKey = String(item.allocationGroupKey || '').trim()
  if (explicitKey.startsWith('independent:')) return explicitKey
  if (!performanceIndependent(item)) return 'normal'
  const positionId = Number(item.positionId ?? item.position_id ?? 0)
  return `independent:${positionId > 0 ? positionId : recordId(item)}`
}

function selectedByAllocationGroup(records, predicate = () => true) {
  const groups = new Map()
  records.filter((record) => record.selected && predicate(record)).forEach((record) => {
    const key = allocationGroupKey(record)
    if (!groups.has(key)) groups.set(key, [])
    groups.get(key).push(record)
  })
  return groups
}

function distributeProjectCounts(records) {
  if (props.historyAdjustment || !Array.isArray(records)) return
  const selected = records.filter((record) => record.selected && record.role === 'craftsmen')
  if (!selected.length || selected.some((record) => record.projectCountTouched)) return
  const totalMicroUnits = Math.max(0, Math.round(Number(props.projectCountTotal || 0) * PROJECT_COUNT_SCALE))
  selectedByAllocationGroup(selected).forEach((group) => {
    const base = Math.floor(totalMicroUnits / group.length)
    const remainder = totalMicroUnits - base * group.length
    group.forEach((record, index) => {
      record.projectCountText = projectCountTextFromMicroUnits(
        base + (index >= group.length - remainder ? 1 : 0)
      )
    })
  })
}

function recordId(record = {}) {
  return String(record.staffId || record.id || record.systemStoreStaffId || '')
}

function recordName(record = {}) {
  return String(record.name || record.staffName || record.employeeName || record.realName || '')
}

function refreshCraftsmanAllocationMetadata(records) {
  if (!Array.isArray(records)) return
  const candidatesById = new Map(
    [...(Array.isArray(props.craftsmenCandidates) ? props.craftsmenCandidates : []),
      ...(Array.isArray(otherCraftsmanCandidates.value) ? otherCraftsmanCandidates.value : [])]
      .map((candidate) => [recordId(candidate), candidate])
      .filter(([id]) => id)
  )
  records.forEach((record) => {
    const candidate = candidatesById.get(recordId(record))
    if (!candidate) return
    // Re-apply the authoritative selector metadata immediately before a
    // default split. This covers a local selected snapshot that was created by
    // an older response and therefore lost the independent-position fields.
    if (Object.prototype.hasOwnProperty.call(candidate, 'positionId')
      || Object.prototype.hasOwnProperty.call(candidate, 'position_id')) {
      record.positionId = Number(candidate.positionId ?? candidate.position_id ?? 0)
    }
    if (hasPerformanceIndependentMetadata(candidate)) {
      record.performanceIndependent = performanceIndependent(candidate)
    }
    if (Object.prototype.hasOwnProperty.call(candidate, 'allocationGroupKey')) {
      record.allocationGroupKey = String(candidate.allocationGroupKey || '').trim()
    }
    if (candidate.positionName || candidate.position || candidate.jobTitle) {
      record.position = candidate.positionName || candidate.position || candidate.jobTitle || record.position
    }
  })
}

function equalWeights(records) {
  refreshCraftsmanAllocationMetadata(records)
  selectedByAllocationGroup(records, (record) => craftsmanType(record) !== PERFORMANCE_TYPES.LABOR)
    .forEach((group) => {
      const base = Math.floor(100 / group.length)
      let remainder = 100 - base * group.length
      group.forEach((record) => {
        record.performance = base + (remainder > 0 ? 1 : 0)
        remainder = Math.max(0, remainder - 1)
      })
    })
  syncComputedPerformanceAmounts(records)
}

function splitWeight(total, records) {
  const selected = records.filter((record) => record.selected)
  if (!selected.length) return
  const base = Math.floor(total / selected.length)
  let remainder = total - base * selected.length
  selected.forEach((record) => {
    record.performance = base + (remainder > 0 ? 1 : 0)
    remainder = Math.max(0, remainder - 1)
  })
}

function salespersonDefaultWeights(records) {
  selectedByAllocationGroup(records).forEach((selected) => {
    const ratio = Math.max(0, Math.min(100, Math.trunc(Number(selected[0].partnerDefaultRatio || 0))))
    const partners = selected.filter((record) => String(record.employeeTypeCode || '').toLowerCase() === 'partner')
    const others = selected.filter((record) => String(record.employeeTypeCode || '').toLowerCase() !== 'partner')
    if (ratio <= 0 || !partners.length || !others.length) {
      splitWeight(100, selected)
    } else {
      splitWeight(ratio, partners)
      splitWeight(100 - ratio, others)
    }
    selected.forEach((record) => { record.performanceTouched = false })
  })
  syncComputedPerformanceAmounts(records)
}

function mergeCandidates(candidates, selected, role) {
  const selectedById = new Map(
    (Array.isArray(selected) ? selected : [])
      .map((record) => [recordId(record), record])
      .filter(([id]) => id)
  )
  const merged = []
  const seen = new Set()
  for (const candidate of Array.isArray(candidates) ? candidates : []) {
    const id = recordId(candidate)
    if (!id || seen.has(id)) continue
    seen.add(id)
    const saved = selectedById.get(id)
    merged.push({
      ...candidate,
      id,
      staffId: id,
      name: recordName(candidate),
      position: candidate.positionName || candidate.position || candidate.jobTitle || '在职员工',
      // Candidate metadata is authoritative, but retain the selected snapshot
      // metadata when an older selector response omitted the position fields.
      // Without this fallback, switching simple -> full after adding a normal
      // employee can recalculate an independent manager into 34/33/33.
      positionId: Number(candidate.positionId ?? candidate.position_id ?? saved?.positionId ?? saved?.position_id ?? 0),
      performanceIndependent: hasPerformanceIndependentMetadata(candidate)
        ? performanceIndependent(candidate)
        : performanceIndependent(saved || {}),
      allocationGroupKey: String(candidate.allocationGroupKey || saved?.allocationGroupKey || '').trim()
        || allocationGroupKey({ ...saved, ...candidate }),
      level: candidate.levelName || candidate.positionLevelName || candidate.level || '—',
      selected: Boolean(saved),
      marked: Boolean(saved?.marked ?? saved?.isPreSale ?? saved?.isPointCustomer),
      performance: Number(saved?.allocationWeight ?? saved?.laborWeight ?? saved?.performance ?? 0),
      performanceTouched: Boolean(saved && hasSavedAllocationWeight(saved)),
      craftsmanPerformanceType: craftsmanType(saved || candidate),
      partnerDefaultRatio: Number(candidate.partnerDefaultRatio ?? saved?.partnerDefaultRatio ?? 0),
      laborFeeCents: laborFeeCentsFor({ ...candidate, ...(saved || {}) }),
      laborFeeYuan: laborFeeCentsFor({ ...candidate, ...(saved || {}) }) / 100,
      allocationAmountCents: allocationAmountCentsFor(saved || {}),
      allocationAmountYuan: allocationAmountYuanText(saved || {}),
      performanceAmountCents: performanceAmountCentsFor(saved || {}),
      performanceAmountYuan: performanceAmountYuanText(saved || {}),
      performanceAmountManual: Boolean(saved?.performanceAmountManual ?? saved?.performance_amount_manual),
      performanceAmountLocked: Boolean(saved?.performanceAmountLocked ?? saved?.performance_amount_locked),
      projectCountText: projectCountTextFor(saved || {}),
      projectCountTouched: Object.prototype.hasOwnProperty.call(saved || {}, 'projectCount')
        || Object.prototype.hasOwnProperty.call(saved || {}, 'projectCountHalfUnits'),
      role
    })
  }
  for (const saved of selectedById.values()) {
    const id = recordId(saved)
    if (!id || seen.has(id)) continue
    seen.add(id)
    merged.push({
      ...saved,
      id,
      staffId: id,
      name: recordName(saved),
      position: saved.positionName || saved.position || saved.jobTitle || '在职员工',
      positionId: Number(saved.positionId ?? saved.position_id ?? 0),
      performanceIndependent: performanceIndependent(saved),
      allocationGroupKey: String(saved.allocationGroupKey || '').trim() || allocationGroupKey(saved),
      level: saved.levelName || saved.positionLevelName || saved.level || '—',
      selected: true,
      marked: Boolean(saved.marked ?? saved.isPreSale ?? saved.isPointCustomer),
      performance: Number(saved.allocationWeight ?? saved.laborWeight ?? saved.performance ?? 0),
      performanceTouched: hasSavedAllocationWeight(saved),
      craftsmanPerformanceType: craftsmanType(saved),
      partnerDefaultRatio: Number(saved.partnerDefaultRatio ?? 0),
      laborFeeCents: laborFeeCentsFor(saved),
      laborFeeYuan: laborFeeCentsFor(saved) / 100,
      allocationAmountCents: allocationAmountCentsFor(saved),
      allocationAmountYuan: allocationAmountYuanText(saved),
      performanceAmountCents: performanceAmountCentsFor(saved),
      performanceAmountYuan: performanceAmountYuanText(saved),
      performanceAmountManual: Boolean(saved?.performanceAmountManual ?? saved?.performance_amount_manual),
      performanceAmountLocked: Boolean(saved?.performanceAmountLocked ?? saved?.performance_amount_locked),
      projectCountText: projectCountTextFor(saved),
      projectCountTouched: Object.prototype.hasOwnProperty.call(saved || {}, 'projectCount')
        || Object.prototype.hasOwnProperty.call(saved || {}, 'projectCountHalfUnits'),
      role
    })
  }
  const selectedRecords = merged.filter((record) => record.selected)
  if (!props.historyAdjustment && !['guides', 'salesManagers'].includes(role) && selectedRecords.length && selectedRecords.some((record) => !Number.isInteger(record.performance))) {
    if (role === 'salespeople' && !selectedRecords.some((record) => record.performanceTouched)) salespersonDefaultWeights(merged)
    if (role !== 'salespeople') equalWeights(merged)
  }
  if (role === 'craftsmen') distributeProjectCounts(merged)
  syncComputedPerformanceAmounts(merged)
  return merged
}

function mergeWithLocalSelections(candidates, selected, currentRecords, role) {
  const preserved = Array.isArray(currentRecords)
    ? currentRecords.filter((record) => record.selected)
    : []
  const saved = [
    ...(Array.isArray(selected) ? selected : []),
    ...preserved
  ]
  return mergeCandidates(candidates, saved, role)
}

watch(
  () => [props.craftsmenCandidates, props.selectedCraftsmen],
  // 候选列表会在“添加其他手艺人”搜索完成后刷新。不能因此把本次弹窗
  // 已点选、但尚未提交的人员重置为空；历史服务记录调整尤其会让用户回到
  // 完整分配页后误以为刚选择的手艺人丢失了。
  ([candidates, selected]) => { craftsmen.value = mergeWithLocalSelections(candidates, selected, craftsmen.value, 'craftsmen') },
  { immediate: true, deep: true }
)
watch(
  () => props.otherCraftsmanCandidates,
  (candidates) => { otherCraftsmanCandidates.value = Array.isArray(candidates) ? candidates : [] },
  { immediate: true, deep: true }
)
watch(
  () => [props.guideCandidates, props.selectedGuides],
  ([candidates, selected]) => { guides.value = mergeWithLocalSelections(candidates, selected, guides.value, 'guides') },
  { immediate: true, deep: true }
)
watch(
  () => props.selectedGuides,
  (selected) => {
    const rounds = [...new Set((Array.isArray(selected) ? selected : [])
      .map((item) => Number(item?.guideRoundNo ?? item?.guide_round_no ?? 0))
      .filter((round) => Number.isInteger(round) && round >= 0 && round <= 3))]
    // 游客继续默认“无”；会员只有已保存明确选择时才回显，0 对应可选的“无”。
    guideRoundNo.value = props.guestCustomer
      ? 'none'
      : (rounds.length === 1 ? (rounds[0] === 0 ? 'none' : String(rounds[0])) : '')
  },
  { immediate: true, deep: true }
)
watch(() => props.guestCustomer, (guest) => {
  // 客户身份变化时不能沿用另一身份的轮次选择。
  guideRoundNo.value = guest ? 'none' : ''
})
watch(
  () => [props.salesManagerCandidates, props.selectedSalesManagers],
  ([candidates, selected]) => {
    salesManagers.value = mergeWithLocalSelections(candidates, selected, salesManagers.value, 'salesManagers')
  },
  { immediate: true, deep: true }
)
watch(
  () => [props.salespersonCandidates, props.selectedSalespeople],
  ([candidates, selected]) => { salespeople.value = mergeCandidates(candidates, selected, 'salespeople') },
  { immediate: true, deep: true }
)

const normalizedKeyword = computed(() => keyword.value.trim().toLowerCase())
const normalizedGroupKeyword = computed(() => groupKeyword.value.trim().toLowerCase())
const isAttributionTab = computed(() => ['guides', 'salesManagers'].includes(activeTab.value))

function matchesKeyword(item) {
  if (!normalizedKeyword.value) return true
  return [item.name, item.position, item.level, item.staffNo]
    .some((value) => String(value || '').toLowerCase().includes(normalizedKeyword.value))
}
function matchesGroupKeyword(item) {
  if (!normalizedGroupKeyword.value) return true
  return [item.name, item.position, item.level, item.staffNo, item.storeName]
    .some((value) => String(value || '').toLowerCase().includes(normalizedGroupKeyword.value))
}

const filteredCraftsmen = computed(() => craftsmen.value.filter(matchesKeyword))
const filteredOtherCraftsmen = computed(() => otherCraftsmanCandidates.value.filter((item) => {
  const value = otherCraftsmanKeyword.value.trim().toLowerCase()
  if (!value) return true
  return [item.name, item.position, item.level, item.staffNo]
    .some((field) => String(field || '').toLowerCase().includes(value))
}))
const filteredSalespeople = computed(() => salespeople.value.filter(matchesKeyword))
const filteredGuides = computed(() => guides.value.filter(isAttributionTab.value ? matchesGroupKeyword : matchesKeyword))
const filteredSalesManagers = computed(() => salesManagers.value.filter(isAttributionTab.value ? matchesGroupKeyword : matchesKeyword))
const selectedGuides = computed(() => guides.value.filter((item) => item.selected))
const selectedSalesManagers = computed(() => salesManagers.value.filter((item) => item.selected))
const attributionSearchResults = computed(() => {
  const records = attributionSearchRole.value === 'salesManager' ? salesManagers.value : guides.value
  // 同一集团查询承载两个角色；只展示该角色正式结账能保存的员工。
  return records.filter((item) => matchesGroupKeyword(item)
    && attributionRoleAllows(item, attributionSearchRole.value === 'salesManager' ? 'salesManager' : 'guide'))
})
const activeRecords = computed(() => activeTab.value === 'craftsmen'
  ? craftsmen.value
  : activeTab.value === 'salespeople' ? salespeople.value
    : activeTab.value === 'guides' ? guides.value
      : salesManagers.value)
const selectedRecords = computed(() => activeRecords.value.filter((item) => item.selected))
const activeTotal = computed(() => selectedRecords.value.reduce((total, item) => total + Number(item.performance || 0), 0))
const activeIsNonPerformance = computed(() => isAttributionTab.value)
const historyTotalCents = computed(() => Math.max(0, Math.trunc(Number(props.allocationTotalAmountCents || 0))))
const historyAllocatedCents = computed(() => craftsmen.value
  .filter((item) => item.selected)
  .reduce((total, item) => total + allocationAmountCentsFor(item), 0))
const historyOnlyLaborFee = computed(() => {
  const selected = craftsmen.value.filter((item) => item.selected)
  return selected.length > 0
    && selected.every((item) => craftsmanType(item) === PERFORMANCE_TYPES.LABOR)
})
const historyAllocationSummary = computed(() => historyOnlyLaborFee.value
  ? '仅手工费，不记录消耗业绩'
  : `已录入消耗业绩 ¥${(historyAllocatedCents.value / 100).toFixed(2)}`)

function historyAmountInputHint(item) {
  if (craftsmanType(item) === PERFORMANCE_TYPES.LABOR) return '只拿手工费的手艺人不能分配消耗业绩。'
  return '可按整元填写，保存时以最终输入金额为准。'
}
function selectRecord(item) {
  if (item.role === 'salesManagers' && !item.selected) {
    salesManagers.value.forEach((record) => { record.selected = false })
  }
  item.selected = !item.selected
  if (!item.selected) item.marked = false
  if (item.role === 'craftsmen') {
    equalWeights(craftsmen.value)
    distributeProjectCounts(craftsmen.value)
    if (props.historyAdjustment) {
      distributeHistoryAmountsByRatio()
    }
  }
  if (item.role === 'salespeople') {
    const selected = salespeople.value.filter((record) => record.selected)
    if (selected.length && !selected.some((record) => record.performanceTouched)) salespersonDefaultWeights(salespeople.value)
  }
  validationMessage.value = ''
}

function markPerformanceTouched(item) {
  item.performanceTouched = true
  if (props.historyAdjustment && item.role === 'craftsmen') syncHistoryAmountFromRatio(item)
  else syncPerformanceAmountFromRatio(item)
  validationMessage.value = ''
}

function historyPerformanceRows() {
  return craftsmen.value.filter((item) => item.selected && craftsmanType(item) !== PERFORMANCE_TYPES.LABOR)
}

function syncHistoryAmountFromRatio(item) {
  if (!props.historyAdjustment || craftsmanType(item) === PERFORMANCE_TYPES.LABOR) return
  const ratio = Math.max(0, Math.min(100, Number(item.performance || 0)))
  item.allocationAmountCents = Math.floor(historyTotalCents.value * ratio / 10000) * 100
  item.allocationAmountYuan = allocationAmountYuanText(item)
  balanceHistoryTail(item)
}

function syncHistoryRatioFromAmount(item) {
  const yuan = String(item.allocationAmountYuan ?? '').trim()
  const cents = /^\d+$/.test(yuan) ? Number(yuan) * 100 : 0
  item.allocationAmountCents = Math.max(0, Math.trunc(cents))
  item.allocationAmountYuan = allocationAmountYuanText(item)
  item.performance = historyTotalCents.value > 0
    ? Number((item.allocationAmountCents * 100 / historyTotalCents.value).toFixed(2))
    : 0
  validationMessage.value = ''
}

function balanceHistoryTail(changedItem) {
  const rows = historyPerformanceRows()
  if (!rows.length) return
  const tail = rows[rows.length - 1]
  const otherTotal = rows.reduce((total, row) => row === tail ? total : total + allocationAmountCentsFor(row), 0)
  // 尾差固定归最后一人：前面人员都是整元后，剩余金额全部落到最后一位。
  // 若前面已超出项目核销金额，保留 0 并由提交校验提示用户调整。
  tail.allocationAmountCents = Math.max(0, historyTotalCents.value - otherTotal)
  tail.allocationAmountYuan = allocationAmountYuanText(tail)
  tail.performance = historyTotalCents.value > 0
    ? Number((tail.allocationAmountCents * 100 / historyTotalCents.value).toFixed(2))
    : 0
}

function distributeHistoryAmountsByRatio() {
  const rows = historyPerformanceRows()
  if (!rows.length) return
  const tail = rows[rows.length - 1]
  rows.forEach((record) => {
    if (record === tail) return
    const ratio = Math.max(0, Math.min(100, Number(record.performance || 0)))
    record.allocationAmountCents = Math.floor(historyTotalCents.value * ratio / 10000) * 100
    record.allocationAmountYuan = allocationAmountYuanText(record)
  })
  balanceHistoryTail(null)
}

function normalizeProjectCount(item) {
  const microUnits = projectCountMicroUnitsForText(item.projectCountText)
  if (microUnits === null) return
  item.projectCountText = projectCountTextFromMicroUnits(microUnits)
  item.projectCountTouched = true
  validationMessage.value = ''
}

function openAttributionSearch(role = 'guide') {
  attributionSearchRole.value = role === 'salesManager' ? 'salesManager' : 'guide'
  groupKeyword.value = ''
  validationMessage.value = ''
  attributionSearchOpen.value = true
}

function openOtherCraftsmanSearch() {
  otherCraftsmanKeyword.value = ''
  validationMessage.value = ''
  otherCraftsmanSearchOpen.value = true
}

function searchOtherCraftsmen() {
  const keywordValue = otherCraftsmanKeyword.value.trim()
  if (keywordValue.length < 2) {
    validationMessage.value = '请输入至少 2 个字符后搜索其他手艺人。'
    return
  }
  validationMessage.value = ''
  emit('search-personnel', {
    scope: 'cashier_other_craftsmen',
    keyword: keywordValue,
    target: 'otherCraftsmen'
  })
}

function addOtherCraftsman(item) {
  const candidate = mergeCandidates([item], [], 'craftsmen')[0]
  if (!candidate || craftsmen.value.some((record) => record.id === candidate.id)) {
    otherCraftsmanSearchOpen.value = false
    return
  }
  candidate.selected = true
  craftsmen.value.push(candidate)
  equalWeights(craftsmen.value)
  distributeProjectCounts(craftsmen.value)
  otherCraftsmanSearchOpen.value = false
  validationMessage.value = ''
}

function addGuide(item) {
  if (!guides.value.some((record) => record.selected)) guideRoundNo.value = props.guestCustomer ? 'none' : ''
  const guide = guides.value.find((record) => record.id === item.id)
  if (guide) guide.selected = true
  attributionSearchOpen.value = false
}

function setSalesManager(item) {
  salesManagers.value.forEach((record) => { record.selected = record.id === item.id })
  attributionSearchOpen.value = false
}

function removeAttribution(item, role) {
  const records = role === 'guide' ? guides.value : salesManagers.value
  const target = records.find((record) => record.id === item.id)
  if (target) target.selected = false
  if (role === 'guide' && !guides.value.some((record) => record.selected)) guideRoundNo.value = props.guestCustomer ? 'none' : ''
}

function setMarked(item, checked) {
  // 售前复选框本身就是加入销售人的快捷入口；未选中的人员勾选时自动加入，
  // 避免必须先点整行才能勾选，且允许多个销售人分别保留售前标记。
  if (item.role === 'salespeople' && checked && !item.selected) {
    item.selected = true
    salespersonDefaultWeights(salespeople.value)
  }
  if (!item.selected) return
  // 售前标记允许同一明细多选；每名已选销售人的标记都要随快照提交。
  item.marked = checked
  validationMessage.value = ''
}

function allocationIsValid(records, role = '') {
  const selected = records.filter((record) => record.selected)
  return !selected.length || (
    selected.every((record) => Number.isInteger(Number(record.performance))
      && Number(record.performance) >= 0 && Number(record.performance) <= 100)
    // “仅手工费”的 0 比例约束属于手艺人服务业绩口径，不能套用到销售人。
    // 同一员工可同时拥有服务业绩类型；作为销售人时，仍按销售人分配规则计算。
    && (role !== 'craftsmen' || selected.every((record) => (
      craftsmanType(record) !== PERFORMANCE_TYPES.LABOR || Number(record.performance) === 0
    )))
  )
}

function historyAllocationIsValid(records) {
  const selected = records.filter((record) => record.selected)
  if (!selected.length) return false
  if (selected.some((record) => {
    return projectCountMicroUnitsForText(record.projectCountText) === null
  })) return false
  if (selected.some((record) => craftsmanType(record) === PERFORMANCE_TYPES.LABOR && allocationAmountCentsFor(record) !== 0)) return false
  if (selected.some((record) => craftsmanType(record) === PERFORMANCE_TYPES.COMMISSION && Number(record.laborFeeYuan || 0) !== 0)) return false
  if (selected.some((record) => allocationAmountCentsFor(record) % 100 !== 0)) return false
  return true
}

function activateInvalidTab(tab, message) {
  activeTab.value = tab
  mode.value = 'full'
  validationMessage.value = message
}

function selectedSalespersonPayload() {
  return salespeople.value.filter((item) => item.selected).map((item) => ({
    id: item.id,
    staffId: item.id,
    name: item.name,
    marked: Boolean(item.marked),
    isPreSale: Boolean(item.marked),
    allocationWeight: Number(item.performance),
    performanceAmountCents: performanceAmountCentsFor(item),
    performanceAmountManual: Boolean(item.performanceAmountManual),
    positionId: Number(item.positionId || 0),
    positionName: item.position || '在职员工',
    performanceIndependent: performanceIndependent(item),
    allocationGroupKey: allocationGroupKey(item)
  }))
}

function selectedCraftsmenPayload() {
  return craftsmen.value.filter((item) => item.selected).map((item, index) => ({
    id: item.id,
    staffId: item.id,
    employeeId: Number(item.employeeId || item.employee_id || item.id),
    storeId: Number(item.storeId || item.store_id || props.storeId || 0),
    name: item.name,
    marked: Boolean(item.marked),
    isPointCustomer: Boolean(item.marked),
    laborWeight: Number(item.performance),
    performanceAmountCents: craftsmanType(item) === PERFORMANCE_TYPES.LABOR ? 0 : performanceAmountCentsFor(item),
    performanceAmountManual: craftsmanType(item) !== PERFORMANCE_TYPES.LABOR && Boolean(item.performanceAmountManual),
    craftsmanPerformanceType: craftsmanType(item),
    laborFeeCents: craftsmanType(item) === PERFORMANCE_TYPES.COMMISSION ? 0 : Math.max(0, Number(item.laborFeeYuan || 0) * 100),
    positionId: Number(item.positionId || 0),
    positionName: item.position || '在职员工',
    performanceIndependent: performanceIndependent(item),
    allocationGroupKey: allocationGroupKey(item),
    projectCountText: item.projectCountText,
    projectCount: projectCountTextFor(item),
    ...(item.personnelSource === 'other' ? { personnelSource: 'other' } : {}),
    isPrimary: index === 0,
    sequence: index + 1
  }))
}

function selectedAttributionPayload(records) {
  return records.filter((item) => item.selected).map((item) => ({
    id: item.id,
    staffId: item.id,
    employeeId: item.employeeId || item.id,
    name: item.name
  }))
}

function attributionRoleAllows(item = {}, role = '') {
  // `role` is the front-end group key (`guides` / `salesManagers`), not the
  // employee's back-end attribution capability. Falling back to it silently
  // discards selected records when the modal is confirmed.
  const attributionRole = String(item?.attributionRole ?? item?.attribution_role ?? '').trim()
  if (!role) return true
  if (role === 'guide') {
    return attributionRole === ''
      || attributionRole === 'guide'
      || attributionRole === 'guide_and_sales_manager'
      || attributionRole === 'guideAndSalesManager'
      || attributionRole === 'guide/salesManager'
  }
  if (role === 'salesManager') {
    return attributionRole === ''
      || attributionRole === 'sales_manager'
      || attributionRole === 'guide_and_sales_manager'
      || attributionRole === 'guideAndSalesManager'
      || attributionRole === 'guide/salesManager'
  }
  return true
}

function selectedGuidePayload(records = []) {
  // 传输层 0 表示明确选择“无”；正式事实层使用 NULL，不制造第 0 轮，
  // 且“无”不占用会员第 1～3 轮的跨日期名额。
  const roundNo = guideRoundNo.value === 'none' ? 0 : Number(guideRoundNo.value)
  return selectedAttributionPayload(records.filter((record) => attributionRoleAllows(record, 'guide')))
    .map((record) => ({ ...record, guideRoundNo: roundNo }))
}

function selectedSalesManagerPayload(records = []) {
  return selectedAttributionPayload(records.filter((record) => attributionRoleAllows(record, 'salesManager')))
}

function applySelectionToAll() {
  // “应用全部人”是简易选择的批量操作：先按简易模式的既定规则补齐
  // 默认比例，再把当前勾选直接写入所有适用明细。它不能因为内部比例
  // 尚未同步就替用户跳转到“完整分配”。
  if (mode.value === 'simple') {
    equalWeights(craftsmen.value)
    salespersonDefaultWeights(salespeople.value)
  }
  const selectedCraftsmen = props.showCraftsmen ? craftsmen.value.filter((item) => item.selected) : []
  const selectedSalespeople = props.showSalespeople ? salespeople.value.filter((item) => item.selected) : []
  const selectedGuides = props.showGuides ? guides.value.filter((item) => item.selected) : []
  const selectedSalesManagers = props.showSalesManagers ? salesManagers.value.filter((item) => item.selected) : []
  if (selectedGuides.length || selectedSalesManagers.length || (props.showGuides || props.showSalesManagers)) {
    validationMessage.value = '导购和销售经理需在当前商品上逐条保存，不能应用到全部商品。'
    return
  }
  if (!selectedCraftsmen.length && !selectedSalespeople.length && !selectedGuides.length && !selectedSalesManagers.length) {
    validationMessage.value = '请先选择要应用到购物车的人员。'
    return
  }
  if (selectedCraftsmen.length && !allocationIsValid(selectedCraftsmen, 'craftsmen')) {
    validationMessage.value = '手艺人业绩比例仅可填写 0 至 100 的整数。'
    return
  }
  if (selectedSalespeople.length && !allocationIsValid(selectedSalespeople, 'salespeople')) {
    validationMessage.value = '销售人业绩比例仅可填写 0 至 100 的整数。'
    return
  }
  validationMessage.value = ''
  emit('apply-all', {
    role: selectedCraftsmen.length && selectedSalespeople.length
      ? 'personnel'
      : selectedCraftsmen.length ? 'craftsmen' : 'salespeople',
    craftsmen: selectedCraftsmenPayload(),
    // 导购与销售经理只能逐条保存；应用全部人不得携带空数组清除既有归属。
    salespeople: selectedSalespersonPayload()
  })
}

function confirm() {
  // 简易选择只负责选人；确认时先把默认分配写入当前选择，再做完整性校验。
  // 否则默认比例要等切到完整模式后才生成，导致本来可提交的选择被错误切页拦截。
  const simpleMode = mode.value === 'simple' && !props.historyAdjustment
  if (simpleMode) {
    // 简易模式没有手工编辑比例的入口，当前选人必须重新按默认规则生成。
    equalWeights(craftsmen.value)
    salespersonDefaultWeights(salespeople.value)
  }
  const selectedCraftsmen = craftsmen.value.filter((item) => item.selected)
  const selectedSalespeople = salespeople.value.filter((item) => item.selected)
  const selectedGuides = guides.value.filter((item) => item.selected)
  const selectedSalesManagers = salesManagers.value.filter((item) => item.selected)
  if (props.requireCraftsmen && !selectedCraftsmen.length) {
    activateInvalidTab('craftsmen', '当前项目至少需要选择一名手艺人。')
    return
  }
  if (props.historyAdjustment && !historyAllocationIsValid(selectedCraftsmen)) {
    activateInvalidTab('craftsmen', '消耗业绩和手工费按最终输入金额保存；消耗业绩需为整元，项目数必须为非负数字，最多保留六位小数。')
    return
  }
  const selectedGuideRoundNo = guideRoundNo.value === 'none' ? 0 : Number(guideRoundNo.value)
  if (selectedGuides.length && ![0, 1, 2, 3].includes(selectedGuideRoundNo)) {
    activateInvalidTab('guides', '已选择导购，请选择本次导购第几轮。')
    return
  }
  if (!simpleMode && !props.historyAdjustment && !allocationIsValid(selectedCraftsmen, 'craftsmen')) {
    activateInvalidTab('craftsmen', '手艺人业绩比例仅可填写 0 至 100 的整数。')
    return
  }
  if (!simpleMode && props.showSalespeople && !allocationIsValid(selectedSalespeople, 'salespeople')) {
    activateInvalidTab('salespeople', '销售人业绩比例仅可填写 0 至 100 的整数。')
    return
  }
  if (selectedCraftsmen.some((item) => {
    return projectCountMicroUnitsForText(item.projectCountText) === null
  })) {
    activateInvalidTab('craftsmen', '项目数必须是非负数字。')
    return
  }
  validationMessage.value = ''
  const assignment = {
    craftsmen: selectedCraftsmen.map((item, index) => ({
      id: item.id,
      staffId: item.id,
      employeeId: Number(item.employeeId || item.employee_id || 0),
      // 单行“确认”与“应用全部人”必须输出同一门店身份。否则远端根投影
      // 不含 currentStore.id 时，最终结账无法冻结合规的人员快照而被拒绝。
      storeId: Number(item.storeId || item.store_id || props.storeId || 0),
      name: item.name,
      marked: Boolean(item.marked),
      isPointCustomer: Boolean(item.marked),
      laborWeight: Number(item.performance),
      performanceAmountCents: craftsmanType(item) === PERFORMANCE_TYPES.LABOR ? 0 : performanceAmountCentsFor(item),
      performanceAmountManual: craftsmanType(item) !== PERFORMANCE_TYPES.LABOR && Boolean(item.performanceAmountManual),
      craftsmanPerformanceType: craftsmanType(item),
      laborFeeCents: craftsmanType(item) === PERFORMANCE_TYPES.COMMISSION ? 0 : Math.max(0, Number(item.laborFeeYuan || 0) * 100),
      positionId: Number(item.positionId || 0),
      positionName: item.position || '在职员工',
      performanceIndependent: performanceIndependent(item),
      allocationGroupKey: allocationGroupKey(item),
      ...(item.personnelSource === 'other' ? { personnelSource: 'other' } : {}),
      allocationAmountCents: allocationAmountCentsFor(item),
      projectCountText: item.projectCountText,
      projectCount: projectCountTextFor(item),
      isPrimary: index === 0,
      sequence: index + 1
    }))
  }
  if (props.showSalespeople) {
    assignment.salespeople = selectedSalespersonPayload()
  }
  if (props.showGuides) assignment.guideSelections = selectedGuidePayload(guides.value)
  if (props.showSalesManagers) assignment.salesManagerSelections = selectedSalesManagerPayload(salesManagers.value)
  emit('confirm', assignment)
}

function searchGroupPersonnel(scope) {
  const keywordValue = groupKeyword.value.trim()
  if (keywordValue.length < 2) {
    validationMessage.value = '请输入至少 2 个字符后搜索集团人员。'
    return
  }
  validationMessage.value = ''
  emit('search-personnel', { scope, keyword: keywordValue, target: attributionSearchRole.value })
}
</script>

<template>
  <div class="personnel-performance-overlay" role="dialog" aria-modal="true" aria-label="业绩分配">
    <section class="personnel-performance-panel">
      <header>
        <div><strong>{{ historyAdjustment ? '修改服务记录手艺人' : '业绩分配' }}</strong><span>{{ historyAdjustment ? '历史服务调整' : (mode === 'simple' ? '简易选择' : '完整分配') }}</span></div>
        <button type="button" aria-label="关闭" title="关闭" @click="emit('close')">×</button>
      </header>

      <div class="personnel-performance-toolbar">
        <label v-if="!isAttributionTab"><span class="sr-only">搜索员工</span><input v-model="keyword" type="search" placeholder="输入关键词搜索员工"></label>
        <div class="personnel-performance-mode" aria-label="分配模式">
          <button type="button" :class="{ 'is-active': mode === 'simple' }" @click="validationMessage = ''; mode = 'simple'">简易选择</button>
          <button type="button" :class="{ 'is-active': mode === 'full' }" @click="validationMessage = ''; mode = 'full'">完整分配</button>
        </div>
      </div>

      <div v-if="loading" class="personnel-performance-state">正在加载当前门店员工…</div>
      <div v-else-if="loadError" class="personnel-performance-state personnel-performance-state--error">
        <span>{{ loadError }}</span>
        <button type="button" class="button button--secondary" @click="emit('retry')">重新加载</button>
      </div>

      <div v-else-if="mode === 'simple'" class="personnel-simple-grid" :class="{ 'is-single': !showCraftsmen || !showSalespeople }">
        <section v-if="showCraftsmen">
          <div class="personnel-role-heading">
            <button v-if="allowOtherCraftsmen" type="button" class="button button--secondary personnel-role-heading__action" @click="openOtherCraftsmanSearch">添加其他手艺人</button>
            <h3>手艺人</h3>
          </div>
          <button v-for="item in filteredCraftsmen" :key="item.id" type="button" class="personnel-simple-item" :class="{ 'is-selected': item.selected }" @click="selectRecord(item)">
            <span>{{ item.name }}</span>
            <label @click.stop><input :checked="item.marked" type="checkbox" :disabled="!item.selected" @change="setMarked(item, $event.target.checked)">点客</label>
          </button>
          <p v-if="!filteredCraftsmen.length" class="personnel-empty">暂无可选择的手艺人</p>
        </section>
        <section v-if="showSalespeople">
          <div class="personnel-role-heading"><h3>销售人</h3></div>
          <button v-for="item in filteredSalespeople" :key="item.id" type="button" class="personnel-simple-item" :class="{ 'is-selected': item.selected }" @click="selectRecord(item)">
            <span>{{ item.name }}</span>
            <label @click.stop><input :checked="item.marked" type="checkbox" @change="setMarked(item, $event.target.checked)">售前</label>
          </button>
          <p v-if="!filteredSalespeople.length" class="personnel-empty">暂无可选择的销售人</p>
        </section>
        <section v-if="showGuides || showSalesManagers" class="personnel-attribution-manager">
          <div class="personnel-role-heading"><h3>导购 / 销售经理</h3><small>仅记录归属，不参与业绩比例分配</small></div>
          <div v-if="showGuides" class="personnel-attribution-role">
            <!-- 查询入口与角色标题固定同行，人员列表及轮次始终在下方独立展示。 -->
            <div class="personnel-attribution-role-header">
              <div class="personnel-attribution-role-title"><strong>导购</strong><small>可添加多人</small></div>
              <button type="button" class="button button--secondary personnel-attribution-add" @click="openAttributionSearch('guide')">查询导购</button>
            </div>
            <div class="personnel-attribution-members">
              <span v-for="item in selectedGuides" :key="`selected-guide-${item.id}`">{{ item.name }}<button type="button" :aria-label="`移除导购 ${item.name}`" @click="removeAttribution(item, 'guide')">×</button></span>
              <em v-if="!selectedGuides.length">暂未添加</em>
            </div>
            <fieldset v-if="selectedGuides.length" class="personnel-guide-round" aria-label="本次导购轮次">
              <legend>导购第几轮<strong v-if="!guestCustomer">*</strong></legend>
              <label><input v-model="guideRoundNo" type="radio" value="none"><span>无</span></label>
              <label v-for="round in guestCustomer ? [] : [1, 2, 3]" :key="round"><input v-model="guideRoundNo" type="radio" :value="String(round)"><span>第{{ round }}轮</span></label>
            </fieldset>
          </div>
          <div v-if="showSalesManagers" class="personnel-attribution-role">
            <div class="personnel-attribution-role-header">
              <div class="personnel-attribution-role-title"><strong>销售经理</strong><small>仅 1 人</small></div>
              <button type="button" class="button button--secondary personnel-attribution-add" @click="openAttributionSearch('salesManager')">查询销售经理</button>
            </div>
            <div class="personnel-attribution-members">
              <span v-for="item in selectedSalesManagers" :key="`selected-manager-${item.id}`">{{ item.name }}<button type="button" :aria-label="`移除销售经理 ${item.name}`" @click="removeAttribution(item, 'salesManager')">×</button></span>
              <em v-if="!selectedSalesManagers.length">暂未添加</em>
            </div>
          </div>
        </section>
      </div>

      <div v-else class="personnel-full-view">
        <div class="personnel-full-tabs" aria-label="人员类型">
          <button v-if="showCraftsmen" type="button" :class="{ 'is-active': activeTab === 'craftsmen' }" @click="activeTab = 'craftsmen'">手艺人</button>
          <button v-if="showSalespeople" type="button" :class="{ 'is-active': activeTab === 'salespeople' }" @click="activeTab = 'salespeople'">销售人</button>
          <button v-if="showGuides" type="button" :class="{ 'is-active': activeTab === 'guides' }" @click="activeTab = 'guides'">导购</button>
          <button v-if="showSalesManagers" type="button" :class="{ 'is-active': activeTab === 'salesManagers' }" @click="activeTab = 'salesManagers'">销售经理</button>
        </div>
        <div class="personnel-full-summary">
          <button v-if="allowOtherCraftsmen && activeTab === 'craftsmen'" type="button" class="button button--secondary" @click="openOtherCraftsmanSearch">添加其他手艺人</button>
          <button type="button" class="button button--primary" @click="mode = 'simple'">添加人员</button>
          <strong>已选择 {{ selectedRecords.length }} 人<span v-if="historyAdjustment">，{{ historyAllocationSummary }}</span><span v-else-if="!activeIsNonPerformance">，分配合计 {{ activeTotal }}%</span><span v-else>（仅记录归属，不分配比例）</span></strong>
        </div>
        <fieldset v-if="activeTab === 'guides' && selectedGuides.length" class="personnel-guide-round" aria-label="本次导购轮次">
          <legend>导购第几轮<strong v-if="!guestCustomer">*</strong></legend>
          <label><input v-model="guideRoundNo" type="radio" value="none"><span>无</span></label>
          <label v-for="round in guestCustomer ? [] : [1, 2, 3]" :key="round"><input v-model="guideRoundNo" type="radio" :value="String(round)"><span>第{{ round }}轮</span></label>
        </fieldset>
        <div class="personnel-full-table" :class="{ 'personnel-full-table--craftsmen': activeTab === 'craftsmen' && !activeIsNonPerformance, 'personnel-full-table--history': historyAdjustment && activeTab === 'craftsmen' }" role="table" aria-label="完整人员分配">
          <div role="row" class="personnel-full-table__head"><span>员工</span><span>职位</span><span>职级</span><span v-if="!activeIsNonPerformance">服务业绩类型</span><span v-if="!activeIsNonPerformance">{{ activeTab === 'craftsmen' ? '是否点客' : '是否售前' }}</span><span v-if="!activeIsNonPerformance">业绩比例</span><span v-if="!historyAdjustment && !activeIsNonPerformance">业绩金额</span><span v-if="historyAdjustment && activeTab === 'craftsmen'">消耗业绩</span><span v-if="activeTab === 'craftsmen' && !activeIsNonPerformance">手工费</span><span v-if="activeTab === 'craftsmen' && !activeIsNonPerformance">项目数</span><span>操作</span></div>
          <div v-for="item in selectedRecords" :key="item.id" role="row" class="personnel-full-table__row">
            <strong>{{ item.name }}</strong><span>{{ item.position }}</span><span>{{ item.level }}</span>
            <span v-if="!activeIsNonPerformance && activeTab === 'craftsmen'">{{ performanceTypeLabel(item) }}</span>
            <span v-else-if="!activeIsNonPerformance"></span>
            <label v-if="!activeIsNonPerformance" class="personnel-toggle"><input :checked="item.marked" type="checkbox" @change="setMarked(item, $event.target.checked)"><span>{{ item.marked ? '是' : '否' }}</span></label>
            <label v-if="!activeIsNonPerformance" class="personnel-allocation-input"><input v-model.number="item.performance" type="number" min="0" max="100" :step="historyAdjustment ? '0.01' : '1'" inputmode="decimal" aria-label="业绩分配比例" :disabled="activeTab === 'craftsmen' && craftsmanType(item) === PERFORMANCE_TYPES.LABOR" @input="markPerformanceTouched(item)"><b>%</b></label>
            <label v-if="!historyAdjustment && !activeIsNonPerformance" class="personnel-allocation-input personnel-allocation-input--money"><input v-model.trim="item.performanceAmountYuan" type="number" min="0" step="1" inputmode="numeric" aria-label="业绩金额" :disabled="activeTab === 'craftsmen' && craftsmanType(item) === PERFORMANCE_TYPES.LABOR" @input="syncManualPerformanceAmount(item)"><b>元</b></label>
            <label v-if="historyAdjustment && activeTab === 'craftsmen'" class="personnel-allocation-input personnel-allocation-input--money"><input v-model.trim="item.allocationAmountYuan" type="number" min="0" step="1" inputmode="numeric" aria-label="分配消耗业绩" :title="historyAmountInputHint(item)" :disabled="craftsmanType(item) === PERFORMANCE_TYPES.LABOR" @input="syncHistoryRatioFromAmount(item)"><b>元</b></label>
            <label v-if="activeTab === 'craftsmen' && !activeIsNonPerformance" class="personnel-allocation-input personnel-allocation-input--labor"><input v-model.number="item.laborFeeYuan" type="number" min="0" step="1" inputmode="numeric" aria-label="每人手工费" :disabled="craftsmanType(item) === PERFORMANCE_TYPES.COMMISSION" @input="item.laborFeeCents = Math.max(0, Number(item.laborFeeYuan || 0) * 100)"><b>元/次</b></label>
            <label v-if="activeTab === 'craftsmen' && !activeIsNonPerformance" class="personnel-allocation-input personnel-allocation-input--project"><input v-model.trim="item.projectCountText" type="number" min="0" step="any" inputmode="decimal" :aria-label="historyAdjustment ? '工资项目数' : '项目数'" @blur="normalizeProjectCount(item)"><b>个</b></label>
            <button type="button" @click="selectRecord(item)">删除</button>
          </div>
          <p v-if="!selectedRecords.length" class="personnel-empty">请先在简易选择中添加人员</p>
        </div>
      </div>

      <div v-if="otherCraftsmanSearchOpen" class="personnel-attribution-search-overlay" role="dialog" aria-modal="true" aria-label="添加其他手艺人">
        <section class="personnel-attribution-search-panel">
          <header><strong>添加其他手艺人</strong><button type="button" aria-label="关闭添加其他手艺人" @click="otherCraftsmanSearchOpen = false">×</button></header>
          <div class="personnel-attribution-search-toolbar">
            <input v-model="otherCraftsmanKeyword" type="search" placeholder="输入姓名或工号后搜索" @keyup.enter="searchOtherCraftsmen">
            <button type="button" class="button button--primary" @click="searchOtherCraftsmen">搜索</button>
          </div>
          <p v-if="otherCraftsmanKeyword.trim().length < 2" class="personnel-empty">请输入至少 2 个字符后搜索其他手艺人</p>
          <div v-else class="personnel-attribution-search-results">
            <article v-for="item in filteredOtherCraftsmen" :key="`other-craftsman-${item.id}`">
              <div><strong>{{ item.name }}</strong><small>{{ item.storeName || '当前门店人员' }}</small></div>
              <div><button type="button" class="button button--primary" :disabled="craftsmen.some((record) => record.id === item.id)" @click="addOtherCraftsman(item)">{{ craftsmen.some((record) => record.id === item.id) ? '已添加' : '添加' }}</button></div>
            </article>
            <p v-if="!filteredOtherCraftsmen.length" class="personnel-empty">未找到匹配的在职人员</p>
          </div>
        </section>
      </div>

      <div v-if="attributionSearchOpen" class="personnel-attribution-search-overlay" role="dialog" aria-modal="true" :aria-label="attributionSearchRole === 'salesManager' ? '查询销售经理' : '查询导购'">
        <section class="personnel-attribution-search-panel">
          <header><strong>{{ attributionSearchRole === 'salesManager' ? '查询销售经理' : '查询导购' }}</strong><button type="button" aria-label="关闭人员查询" @click="attributionSearchOpen = false">×</button></header>
          <div class="personnel-attribution-search-toolbar">
            <input v-model="groupKeyword" type="search" placeholder="输入姓名或工号后搜索" @keyup.enter="searchGroupPersonnel('group_attributions')">
            <button type="button" class="button button--primary" :disabled="attributionSearchLoading" @click="searchGroupPersonnel('group_attributions')">搜索</button>
          </div>
          <p v-if="groupKeyword.length < 2" class="personnel-empty">请输入至少 2 个字符后搜索集团人员</p>
          <p v-else-if="attributionSearchLoading" class="personnel-empty">正在搜索集团人员…</p>
          <p v-else-if="attributionSearchError" class="personnel-empty" role="alert">{{ attributionSearchError }}</p>
          <div v-else class="personnel-attribution-search-results">
            <article v-for="item in attributionSearchResults" :key="`attribution-search-${item.id}`">
              <div><strong>{{ item.name }}</strong><small>{{ item.storeName || '集团人员' }}</small></div>
              <div>
                <button v-if="attributionSearchRole === 'guide' && showGuides" type="button" class="button button--secondary" @click="addGuide(item)">加入导购</button>
                <button v-if="attributionSearchRole === 'salesManager' && showSalesManagers" type="button" class="button button--primary" @click="setSalesManager(item)">设为销售经理</button>
              </div>
            </article>
            <p v-if="!attributionSearchResults.length" class="personnel-empty">未找到匹配的在职人员</p>
          </div>
        </section>
      </div>

      <footer>
        <p v-if="validationMessage" role="alert">{{ validationMessage }}</p>
        <button type="button" class="button button--secondary" :disabled="saving" @click="emit('close')">取消</button>
        <button v-if="!historyAdjustment && (showCraftsmen || showSalespeople)" type="button" class="button button--secondary" :disabled="loading || saving || Boolean(loadError)" @click="applySelectionToAll">应用全部人</button>
        <button type="button" class="button button--primary" :disabled="loading || saving || Boolean(loadError)" @click="confirm">确认</button>
      </footer>
    </section>
  </div>
</template>

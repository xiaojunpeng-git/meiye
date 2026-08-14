<script setup>
import { computed, ref, watch } from 'vue'

const props = defineProps({
  initialTab: { type: String, default: 'craftsmen' },
  showCraftsmen: { type: Boolean, default: true },
  showSalespeople: { type: Boolean, default: true },
  showGuides: { type: Boolean, default: false },
  showSalesManagers: { type: Boolean, default: false },
  requireCraftsmen: { type: Boolean, default: false },
  craftsmenCandidates: { type: Array, default: () => [] },
  salespersonCandidates: { type: Array, default: () => [] },
  guideCandidates: { type: Array, default: () => [] },
  salesManagerCandidates: { type: Array, default: () => [] },
  selectedCraftsmen: { type: Array, default: () => [] },
  selectedSalespeople: { type: Array, default: () => [] },
  selectedGuides: { type: Array, default: () => [] },
  selectedSalesManagers: { type: Array, default: () => [] },
  laborDefaultFee: { type: [Number, String], default: 0 },
  laborManualFee: { type: [Number, String], default: null },
  allowLaborOverride: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
  saving: { type: Boolean, default: false },
  loadError: { type: String, default: '' }
})
const emit = defineEmits(['close', 'confirm', 'apply-all', 'retry', 'search-personnel'])

const mode = ref('simple')
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
const salespeople = ref([])
const guides = ref([])
const salesManagers = ref([])
const guideRoundNo = ref('')
const attributionSearchOpen = ref(false)
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

function recordId(record = {}) {
  return String(record.staffId || record.id || record.systemStoreStaffId || '')
}

function recordName(record = {}) {
  return String(record.name || record.staffName || record.employeeName || record.realName || '')
}

function equalWeights(records) {
  const selected = records.filter((record) => record.selected && craftsmanType(record) !== PERFORMANCE_TYPES.LABOR)
  if (!selected.length) return
  const base = Math.floor(100 / selected.length)
  let remainder = 100 - base * selected.length
  selected.forEach((record) => {
    record.performance = base + (remainder > 0 ? 1 : 0)
    remainder = Math.max(0, remainder - 1)
  })
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
  const selected = records.filter((record) => record.selected)
  if (!selected.length) return
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
      level: candidate.levelName || candidate.positionLevelName || candidate.level || '—',
      selected: Boolean(saved),
      marked: Boolean(saved?.marked ?? saved?.isPreSale ?? saved?.isPointCustomer),
      performance: Number(saved?.allocationWeight ?? saved?.laborWeight ?? saved?.performance ?? 0),
      performanceTouched: Boolean(saved && Number(saved.allocationWeight ?? saved.performance ?? 0) > 0),
      craftsmanPerformanceType: craftsmanType(saved || candidate),
      partnerDefaultRatio: Number(candidate.partnerDefaultRatio ?? saved?.partnerDefaultRatio ?? 0),
      laborFeeCents: laborFeeCentsFor({ ...candidate, ...(saved || {}) }),
      laborFeeYuan: laborFeeCentsFor({ ...candidate, ...(saved || {}) }) / 100,
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
      level: saved.levelName || saved.positionLevelName || saved.level || '—',
      selected: true,
      marked: Boolean(saved.marked ?? saved.isPreSale ?? saved.isPointCustomer),
      performance: Number(saved.allocationWeight ?? saved.laborWeight ?? saved.performance ?? 0),
      performanceTouched: Boolean(Number(saved.allocationWeight ?? saved.performance ?? 0) > 0),
      craftsmanPerformanceType: craftsmanType(saved),
      partnerDefaultRatio: Number(saved.partnerDefaultRatio ?? 0),
      laborFeeCents: laborFeeCentsFor(saved),
      laborFeeYuan: laborFeeCentsFor(saved) / 100,
      role
    })
  }
  const selectedRecords = merged.filter((record) => record.selected)
  if (!['guides', 'salesManagers'].includes(role) && selectedRecords.length && selectedRecords.some((record) => !Number.isInteger(record.performance) || record.performance <= 0)) {
    if (role === 'salespeople' && !selectedRecords.some((record) => record.performanceTouched)) salespersonDefaultWeights(merged)
    if (role !== 'salespeople') equalWeights(merged)
  }
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
  ([candidates, selected]) => { craftsmen.value = mergeCandidates(candidates, selected, 'craftsmen') },
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
      .filter((round) => Number.isInteger(round) && round >= 1 && round <= 3))]
    guideRoundNo.value = rounds.length === 1 ? String(rounds[0]) : ''
  },
  { immediate: true, deep: true }
)
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
const filteredSalespeople = computed(() => salespeople.value.filter(matchesKeyword))
const filteredGuides = computed(() => guides.value.filter(isAttributionTab.value ? matchesGroupKeyword : matchesKeyword))
const filteredSalesManagers = computed(() => salesManagers.value.filter(isAttributionTab.value ? matchesGroupKeyword : matchesKeyword))
const selectedGuides = computed(() => guides.value.filter((item) => item.selected))
const selectedSalesManagers = computed(() => salesManagers.value.filter((item) => item.selected))
const attributionSearchResults = computed(() => guides.value.filter(matchesGroupKeyword))
const activeRecords = computed(() => activeTab.value === 'craftsmen'
  ? craftsmen.value
  : activeTab.value === 'salespeople' ? salespeople.value
    : activeTab.value === 'guides' ? guides.value
      : salesManagers.value)
const selectedRecords = computed(() => activeRecords.value.filter((item) => item.selected))
const activeTotal = computed(() => selectedRecords.value.reduce((total, item) => total + Number(item.performance || 0), 0))
const activeIsNonPerformance = computed(() => isAttributionTab.value)
function selectRecord(item) {
  if (item.role === 'salesManagers' && !item.selected) {
    salesManagers.value.forEach((record) => { record.selected = false })
  }
  item.selected = !item.selected
  if (!item.selected) item.marked = false
  if (item.role === 'craftsmen') equalWeights(craftsmen.value)
  if (item.role === 'salespeople') {
    const selected = salespeople.value.filter((record) => record.selected)
    if (selected.length && !selected.some((record) => record.performanceTouched)) salespersonDefaultWeights(salespeople.value)
  }
  validationMessage.value = ''
}

function markPerformanceTouched(item) {
  item.performanceTouched = true
  validationMessage.value = ''
}

function openAttributionSearch() {
  groupKeyword.value = ''
  validationMessage.value = ''
  attributionSearchOpen.value = true
}

function addGuide(item) {
  if (!guides.value.some((record) => record.selected)) guideRoundNo.value = ''
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
  if (role === 'guide' && !guides.value.some((record) => record.selected)) guideRoundNo.value = ''
}

function setMarked(item, checked) {
  if (!item.selected) return
  if (item.role === 'salespeople' && checked) {
    salespeople.value.forEach((record) => { record.marked = record.id === item.id })
  } else {
    item.marked = checked
  }
  validationMessage.value = ''
}

function allocationIsValid(records) {
  const selected = records.filter((record) => record.selected)
  const commissionSelected = selected.filter((record) => craftsmanType(record) !== PERFORMANCE_TYPES.LABOR)
  return !selected.length || (
    selected.every((record) => Number.isInteger(Number(record.performance)) && Number(record.performance) >= 0)
    && commissionSelected.every((record) => Number(record.performance) > 0)
    && commissionSelected.reduce((total, record) => total + Number(record.performance), 0) === (commissionSelected.length ? 100 : 0)
    && selected.every((record) => craftsmanType(record) !== PERFORMANCE_TYPES.LABOR || Number(record.performance) === 0)
  )
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
    allocationWeight: Number(item.performance)
  }))
}

function selectedCraftsmenPayload() {
  return craftsmen.value.filter((item) => item.selected).map((item, index) => ({
    id: item.id,
    staffId: item.id,
    name: item.name,
    marked: Boolean(item.marked),
    isPointCustomer: Boolean(item.marked),
    laborWeight: Number(item.performance),
    craftsmanPerformanceType: craftsmanType(item),
    laborFeeCents: craftsmanType(item) === PERFORMANCE_TYPES.COMMISSION ? 0 : Math.max(0, Number(item.laborFeeYuan || 0) * 100),
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
  const attributionRole = item?.attributionRole || item?.role || ''
  if (!role) return true
  if (role === 'guide') {
    return attributionRole === ''
      || attributionRole === 'guide'
      || attributionRole === 'guide_and_sales_manager'
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
  const roundNo = Number(guideRoundNo.value)
  return selectedAttributionPayload(records.filter((record) => attributionRoleAllows(record, 'guide')))
    .map((record) => ({ ...record, guideRoundNo: roundNo }))
}

function selectedSalesManagerPayload(records = []) {
  return selectedAttributionPayload(records.filter((record) => attributionRoleAllows(record, 'salesManager')))
}

function applySelectionToAll() {
  const selectedCraftsmen = props.showCraftsmen ? craftsmen.value.filter((item) => item.selected) : []
  const selectedSalespeople = props.showSalespeople ? salespeople.value.filter((item) => item.selected) : []
  const selectedGuides = props.showGuides ? guides.value.filter((item) => item.selected) : []
  const selectedSalesManagers = props.showSalesManagers ? salesManagers.value.filter((item) => item.selected) : []
  if (selectedGuides.length || selectedSalesManagers.length || (props.showGuides || props.showSalesManagers)) {
    const tabName = isAttributionTab.value
      ? activeTab.value
      : (props.showCraftsmen && !selectedCraftsmen.length ? 'guides' : 'salespeople')
    activateInvalidTab(tabName, '导购和销售经理需在当前商品上逐条保存，不能应用到全部商品。')
    return
  }
  if (!selectedCraftsmen.length && !selectedSalespeople.length && !selectedGuides.length && !selectedSalesManagers.length) {
    activateInvalidTab(props.showCraftsmen ? 'craftsmen' : 'salespeople', '请先选择要应用到购物车的人员。')
    return
  }
  if (selectedCraftsmen.length && !allocationIsValid(selectedCraftsmen)) {
    activateInvalidTab('craftsmen', '手艺人分配比例必须为正整数，合计为 100%。')
    return
  }
  if (selectedSalespeople.length && !allocationIsValid(selectedSalespeople)) {
    activateInvalidTab('salespeople', '销售人分配比例必须为正整数，合计为 100%。')
    return
  }
  validationMessage.value = ''
  emit('apply-all', {
    role: selectedCraftsmen.length && selectedSalespeople.length
      ? 'personnel'
      : selectedCraftsmen.length ? 'craftsmen' : 'salespeople',
    craftsmen: selectedCraftsmenPayload(),
    salespeople: selectedSalespersonPayload(),
    guideSelections: selectedGuidePayload(guides.value),
    salesManagerSelections: selectedSalesManagerPayload(salesManagers.value)
  })
}

function confirm() {
  // 简易选择只负责选人；确认时先把默认分配写入当前选择，再做完整性校验。
  // 否则默认比例要等切到完整模式后才生成，导致本来可提交的选择被错误切页拦截。
  const simpleMode = mode.value === 'simple'
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
  if (selectedGuides.length && ![1, 2, 3].includes(Number(guideRoundNo.value))) {
    activateInvalidTab('guides', '已选择导购，请选择本次导购第几轮。')
    return
  }
  if (!simpleMode && !allocationIsValid(selectedCraftsmen)) {
    activateInvalidTab('craftsmen', '手艺人分配比例必须为正整数，合计为 100%。')
    return
  }
  if (!simpleMode && props.showSalespeople && !allocationIsValid(selectedSalespeople)) {
    activateInvalidTab('salespeople', '销售人分配比例必须为正整数，合计为 100%。')
    return
  }
  validationMessage.value = ''
  const assignment = {
    craftsmen: selectedCraftsmen.map((item, index) => ({
      id: item.id,
      staffId: item.id,
      name: item.name,
      marked: Boolean(item.marked),
      isPointCustomer: Boolean(item.marked),
      laborWeight: Number(item.performance),
      craftsmanPerformanceType: craftsmanType(item),
      laborFeeCents: craftsmanType(item) === PERFORMANCE_TYPES.COMMISSION ? 0 : Math.max(0, Number(item.laborFeeYuan || 0) * 100),
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
  emit('search-personnel', { scope, keyword: keywordValue })
}
</script>

<template>
  <div class="personnel-performance-overlay" role="dialog" aria-modal="true" aria-label="业绩分配">
    <section class="personnel-performance-panel">
      <header>
        <div><strong>业绩分配</strong><span>{{ mode === 'simple' ? '简易选择' : '完整分配' }}</span></div>
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
          <div class="personnel-role-heading"><h3>手艺人</h3></div>
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
            <label @click.stop><input :checked="item.marked" type="checkbox" :disabled="!item.selected" @change="setMarked(item, $event.target.checked)">售前</label>
          </button>
          <p v-if="!filteredSalespeople.length" class="personnel-empty">暂无可选择的销售人</p>
        </section>
        <section v-if="showGuides || showSalesManagers" class="personnel-attribution-manager">
          <div class="personnel-role-heading"><h3>导购 / 销售经理</h3><small>仅记录归属，不参与业绩比例分配</small></div>
          <div v-if="showGuides" class="personnel-attribution-role">
            <strong>导购</strong><small>可添加多人</small>
            <div class="personnel-attribution-members">
              <span v-for="item in selectedGuides" :key="`selected-guide-${item.id}`">{{ item.name }}<button type="button" :aria-label="`移除导购 ${item.name}`" @click="removeAttribution(item, 'guide')">×</button></span>
              <em v-if="!selectedGuides.length">暂未添加</em>
            </div>
            <fieldset v-if="selectedGuides.length" class="personnel-guide-round" aria-label="本次导购轮次">
              <legend>导购第几轮<strong>*</strong></legend>
              <label v-for="round in [1, 2, 3]" :key="round"><input v-model="guideRoundNo" type="radio" :value="String(round)"><span>第{{ round }}轮</span></label>
            </fieldset>
          </div>
          <div v-if="showSalesManagers" class="personnel-attribution-role">
            <strong>销售经理</strong><small>仅 1 人</small>
            <div class="personnel-attribution-members">
              <span v-for="item in selectedSalesManagers" :key="`selected-manager-${item.id}`">{{ item.name }}<button type="button" :aria-label="`移除销售经理 ${item.name}`" @click="removeAttribution(item, 'salesManager')">×</button></span>
              <em v-if="!selectedSalesManagers.length">暂未添加</em>
            </div>
          </div>
          <button type="button" class="button button--secondary personnel-attribution-add" @click="openAttributionSearch">查找人员</button>
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
          <button type="button" class="button button--primary" @click="mode = 'simple'">添加人员</button>
          <strong>已选择 {{ selectedRecords.length }} 人<span v-if="!activeIsNonPerformance">，分配合计 {{ activeTotal }}%</span><span v-else>（仅记录归属，不分配比例）</span></strong>
        </div>
        <fieldset v-if="activeTab === 'guides' && selectedGuides.length" class="personnel-guide-round" aria-label="本次导购轮次">
          <legend>导购第几轮<strong>*</strong></legend>
          <label v-for="round in [1, 2, 3]" :key="round"><input v-model="guideRoundNo" type="radio" :value="String(round)"><span>第{{ round }}轮</span></label>
        </fieldset>
        <div class="personnel-full-table" :class="{ 'personnel-full-table--craftsmen': activeTab === 'craftsmen' && !activeIsNonPerformance }" role="table" aria-label="完整人员分配">
          <div role="row" class="personnel-full-table__head"><span>员工</span><span>职位</span><span>职级</span><span v-if="!activeIsNonPerformance">服务业绩类型</span><span v-if="!activeIsNonPerformance">{{ activeTab === 'craftsmen' ? '是否点客' : '是否售前' }}</span><span v-if="!activeIsNonPerformance">业绩比例</span><span v-if="activeTab === 'craftsmen' && !activeIsNonPerformance">手工费</span><span>操作</span></div>
          <div v-for="item in selectedRecords" :key="item.id" role="row" class="personnel-full-table__row">
            <strong>{{ item.name }}</strong><span>{{ item.position }}</span><span>{{ item.level }}</span>
            <span v-if="!activeIsNonPerformance && activeTab === 'craftsmen'">{{ performanceTypeLabel(item) }}</span>
            <span v-else-if="!activeIsNonPerformance"></span>
            <label v-if="!activeIsNonPerformance" class="personnel-toggle"><input :checked="item.marked" type="checkbox" @change="setMarked(item, $event.target.checked)"><span>{{ item.marked ? '是' : '否' }}</span></label>
            <label v-if="!activeIsNonPerformance" class="personnel-allocation-input"><input v-model.number="item.performance" type="number" min="0" max="100" step="1" inputmode="numeric" aria-label="业绩分配比例" :disabled="activeTab === 'craftsmen' && craftsmanType(item) === PERFORMANCE_TYPES.LABOR" @input="markPerformanceTouched(item)"><b>%</b></label>
            <label v-if="activeTab === 'craftsmen' && !activeIsNonPerformance" class="personnel-allocation-input personnel-allocation-input--labor"><input v-model.number="item.laborFeeYuan" type="number" min="0" step="1" inputmode="numeric" aria-label="每人手工费" :disabled="craftsmanType(item) === PERFORMANCE_TYPES.COMMISSION" @input="item.laborFeeCents = Math.max(0, Number(item.laborFeeYuan || 0) * 100)"><b>元/次</b></label>
            <button type="button" @click="selectRecord(item)">删除</button>
          </div>
          <p v-if="!selectedRecords.length" class="personnel-empty">请先在简易选择中添加人员</p>
        </div>
      </div>

      <div v-if="attributionSearchOpen" class="personnel-attribution-search-overlay" role="dialog" aria-modal="true" aria-label="查找导购或销售经理">
        <section class="personnel-attribution-search-panel">
          <header><strong>查找人员</strong><button type="button" aria-label="关闭查找人员" @click="attributionSearchOpen = false">×</button></header>
          <div class="personnel-attribution-search-toolbar">
            <input v-model="groupKeyword" type="search" placeholder="输入姓名或工号后搜索" @keyup.enter="searchGroupPersonnel('group_attributions')">
            <button type="button" class="button button--primary" @click="searchGroupPersonnel('group_attributions')">搜索</button>
          </div>
          <p v-if="groupKeyword.length < 2" class="personnel-empty">请输入至少 2 个字符后搜索集团人员</p>
          <div v-else class="personnel-attribution-search-results">
            <article v-for="item in attributionSearchResults" :key="`attribution-search-${item.id}`">
              <div><strong>{{ item.name }}</strong><small>{{ item.storeName || '集团人员' }}</small></div>
              <div>
                <button v-if="showGuides" type="button" class="button button--secondary" @click="addGuide(item)">加入导购</button>
                <button v-if="showSalesManagers" type="button" class="button button--primary" @click="setSalesManager(item)">设为销售经理</button>
              </div>
            </article>
            <p v-if="!attributionSearchResults.length" class="personnel-empty">未找到匹配的在职人员</p>
          </div>
        </section>
      </div>

      <footer>
        <p v-if="validationMessage" role="alert">{{ validationMessage }}</p>
        <button type="button" class="button button--secondary" :disabled="saving" @click="emit('close')">取消</button>
        <button v-if="showCraftsmen || showSalespeople" type="button" class="button button--secondary" :disabled="loading || saving || Boolean(loadError)" @click="applySelectionToAll">应用全部人</button>
        <button type="button" class="button button--primary" :disabled="loading || saving || Boolean(loadError)" @click="confirm">确认</button>
      </footer>
    </section>
  </div>
</template>

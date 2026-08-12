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
  loading: { type: Boolean, default: false },
  saving: { type: Boolean, default: false },
  loadError: { type: String, default: '' }
})
const emit = defineEmits(['close', 'confirm', 'apply-all', 'retry', 'search-personnel'])

const mode = ref('simple')
const activeTab = ref(props.showCraftsmen ? props.initialTab : 'salespeople')
const keyword = ref('')
const groupKeyword = ref('')
const craftsmen = ref([])
const salespeople = ref([])
const guides = ref([])
const salesManagers = ref([])
const validationMessage = ref('')

function recordId(record = {}) {
  return String(record.staffId || record.id || record.systemStoreStaffId || '')
}

function recordName(record = {}) {
  return String(record.name || record.staffName || record.employeeName || record.realName || '')
}

function equalWeights(records) {
  const selected = records.filter((record) => record.selected)
  if (!selected.length) return
  const base = Math.floor(100 / selected.length)
  let remainder = 100 - base * selected.length
  selected.forEach((record) => {
    record.performance = base + (remainder > 0 ? 1 : 0)
    remainder = Math.max(0, remainder - 1)
  })
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
      role
    })
  }
  const selectedRecords = merged.filter((record) => record.selected)
  if (!['guides', 'salesManagers'].includes(role) && selectedRecords.length && selectedRecords.some((record) => !Number.isInteger(record.performance) || record.performance <= 0)) {
    equalWeights(merged)
  }
  return merged
}

watch(
  () => [props.craftsmenCandidates, props.selectedCraftsmen],
  ([candidates, selected]) => { craftsmen.value = mergeCandidates(candidates, selected, 'craftsmen') },
  { immediate: true, deep: true }
)
watch(
  () => [props.guideCandidates, props.selectedGuides],
  ([candidates, selected]) => { guides.value = mergeCandidates(candidates, selected, 'guides') },
  { immediate: true, deep: true }
)
watch(
  () => [props.salesManagerCandidates, props.selectedSalesManagers],
  ([candidates, selected]) => { salesManagers.value = mergeCandidates(candidates, selected, 'salesManagers') },
  { immediate: true, deep: true }
)
watch(
  () => [props.salespersonCandidates, props.selectedSalespeople],
  ([candidates, selected]) => { salespeople.value = mergeCandidates(candidates, selected, 'salespeople') },
  { immediate: true, deep: true }
)

const normalizedKeyword = computed(() => keyword.value.trim().toLowerCase())
function matchesKeyword(item) {
  if (!normalizedKeyword.value) return true
  return [item.name, item.position, item.level, item.staffNo]
    .some((value) => String(value || '').toLowerCase().includes(normalizedKeyword.value))
}

const filteredCraftsmen = computed(() => craftsmen.value.filter(matchesKeyword))
const filteredSalespeople = computed(() => salespeople.value.filter(matchesKeyword))
const filteredGuides = computed(() => guides.value.filter(matchesKeyword))
const filteredSalesManagers = computed(() => salesManagers.value.filter(matchesKeyword))
const activeRecords = computed(() => activeTab.value === 'craftsmen'
  ? craftsmen.value
  : activeTab.value === 'salespeople' ? salespeople.value
    : activeTab.value === 'guides' ? guides.value : salesManagers.value)
const selectedRecords = computed(() => activeRecords.value.filter((item) => item.selected))
const activeTotal = computed(() => selectedRecords.value.reduce((total, item) => total + Number(item.performance || 0), 0))
const activeIsNonPerformance = computed(() => ['guides', 'salesManagers'].includes(activeTab.value))
function selectRecord(item) {
  item.selected = !item.selected
  if (!item.selected) item.marked = false
  if (!['guides', 'salesManagers'].includes(item.role)) equalWeights(item.role === 'craftsmen' ? craftsmen.value : salespeople.value)
  validationMessage.value = ''
}

function setMarked(item, checked) {
  if (!item.selected) return
  if (item.role === 'salespeople' && checked) {
    salespeople.value.forEach((record) => { record.marked = record.id === item.id })
  } else {
    item.marked = checked
  }
}

function allocationIsValid(records) {
  const selected = records.filter((record) => record.selected)
  return !selected.length || (
    selected.every((record) => Number.isInteger(Number(record.performance)) && Number(record.performance) > 0)
    && selected.reduce((total, record) => total + Number(record.performance), 0) === 100
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

function applySelectionToAll() {
  const selectedCraftsmen = props.showCraftsmen ? craftsmen.value.filter((item) => item.selected) : []
  const selectedSalespeople = props.showSalespeople ? salespeople.value.filter((item) => item.selected) : []
  const selectedGuides = props.showGuides ? guides.value.filter((item) => item.selected) : []
  const selectedSalesManagers = props.showSalesManagers ? salesManagers.value.filter((item) => item.selected) : []
  if (selectedGuides.length || selectedSalesManagers.length) {
    activateInvalidTab(selectedGuides.length ? 'guides' : 'salesManagers', '导购和销售经理需在当前商品上逐条保存，不能应用到全部商品。')
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
    guideSelections: selectedAttributionPayload(guides.value),
    salesManagerSelections: selectedAttributionPayload(salesManagers.value)
  })
}

function confirm() {
  const selectedCraftsmen = craftsmen.value.filter((item) => item.selected)
  const selectedSalespeople = salespeople.value.filter((item) => item.selected)
  const selectedGuides = guides.value.filter((item) => item.selected)
  const selectedSalesManagers = salesManagers.value.filter((item) => item.selected)
  if (props.requireCraftsmen && !selectedCraftsmen.length) {
    activateInvalidTab('craftsmen', '当前项目至少需要选择一名手艺人。')
    return
  }
  if (!allocationIsValid(selectedCraftsmen)) {
    activateInvalidTab('craftsmen', '手艺人分配比例必须为正整数，合计为 100%。')
    return
  }
  if (props.showSalespeople && !allocationIsValid(selectedSalespeople)) {
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
      isPrimary: index === 0,
      sequence: index + 1
    }))
  }
  if (props.showSalespeople) {
    assignment.salespeople = selectedSalespersonPayload()
  }
  assignment.guideSelections = selectedAttributionPayload(selectedGuides)
  assignment.salesManagerSelections = selectedAttributionPayload(selectedSalesManagers)
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
        <label><span class="sr-only">搜索员工</span><input v-model="keyword" type="search" placeholder="输入关键词搜索员工"></label>
        <label v-if="activeTab === 'guides' || activeTab === 'salesManagers'" class="personnel-group-search"><span class="sr-only">搜索集团人员</span><input v-model="groupKeyword" type="search" placeholder="输入姓名或工号后搜索" @keyup.enter="searchGroupPersonnel(activeTab === 'guides' ? 'group_guides' : 'group_sales_managers')"></label>
        <button v-if="activeTab === 'guides' || activeTab === 'salesManagers'" type="button" class="button button--secondary" @click="searchGroupPersonnel(activeTab === 'guides' ? 'group_guides' : 'group_sales_managers')">搜索集团人员</button>
        <div class="personnel-performance-mode" aria-label="分配模式">
          <button type="button" :class="{ 'is-active': mode === 'simple' }" @click="mode = 'simple'">简易选择</button>
          <button type="button" :class="{ 'is-active': mode === 'full' }" @click="mode = 'full'">完整分配</button>
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
        <section v-if="showGuides">
          <div class="personnel-role-heading"><h3>导购（集团）</h3><small>不参与业绩比例分配</small></div>
          <button v-for="item in filteredGuides" :key="`guide-${item.id}`" type="button" class="personnel-simple-item" :class="{ 'is-selected': item.selected }" @click="selectRecord(item)">
            <span>{{ item.name }}</span><small>{{ item.storeName || '集团人员' }}</small>
          </button>
          <p v-if="!filteredGuides.length" class="personnel-empty">请输入关键词搜索导购</p>
        </section>
        <section v-if="showSalesManagers">
          <div class="personnel-role-heading"><h3>销售经理（集团）</h3><small>不参与业绩比例分配</small></div>
          <button v-for="item in filteredSalesManagers" :key="`sales-manager-${item.id}`" type="button" class="personnel-simple-item" :class="{ 'is-selected': item.selected }" @click="selectRecord(item)">
            <span>{{ item.name }}</span><small>{{ item.storeName || '集团人员' }}</small>
          </button>
          <p v-if="!filteredSalesManagers.length" class="personnel-empty">请输入关键词搜索销售经理</p>
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
        <div class="personnel-full-table" role="table" aria-label="完整人员分配">
          <div role="row" class="personnel-full-table__head"><span>员工</span><span>职位</span><span>职级</span><span v-if="!activeIsNonPerformance">{{ activeTab === 'craftsmen' ? '是否点客' : '是否售前' }}</span><span v-if="!activeIsNonPerformance">分配比例</span><span>操作</span></div>
          <div v-for="item in selectedRecords" :key="item.id" role="row" class="personnel-full-table__row">
            <strong>{{ item.name }}</strong><span>{{ item.position }}</span><span>{{ item.level }}</span>
            <label v-if="!activeIsNonPerformance" class="personnel-toggle"><input :checked="item.marked" type="checkbox" @change="setMarked(item, $event.target.checked)"><span>{{ item.marked ? '是' : '否' }}</span></label>
            <label v-if="!activeIsNonPerformance" class="personnel-allocation-input"><input v-model.number="item.performance" type="number" min="1" max="100" step="1" inputmode="numeric" aria-label="业绩分配比例"><b>%</b></label>
            <button type="button" @click="selectRecord(item)">删除</button>
          </div>
          <p v-if="!selectedRecords.length" class="personnel-empty">请先在简易选择中添加人员</p>
        </div>
      </div>

      <footer>
        <p v-if="validationMessage" role="alert">{{ validationMessage }}</p>
        <button type="button" class="button button--secondary" :disabled="saving" @click="emit('close')">取消</button>
        <button type="button" class="button button--secondary" :disabled="loading || saving || Boolean(loadError)" @click="applySelectionToAll">应用全部人</button>
        <button type="button" class="button button--primary" :disabled="loading || saving || Boolean(loadError)" @click="confirm">确认</button>
      </footer>
    </section>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import ChevronDown from '@lucide/vue/dist/esm/icons/chevron-down.mjs'
import ChevronRight from '@lucide/vue/dist/esm/icons/chevron-right.mjs'
import Network from '@lucide/vue/dist/esm/icons/network.mjs'

const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  tree: { type: Array, default: () => [] },
  allowedStoreIds: { type: Array, default: () => [] },
  label: { type: String, default: '当前权限范围' },
  loading: { type: Boolean, default: false },
  // 嵌入式模式复用同一套组织 / 门店双栏控件，不使用浮层定位。
  embedded: { type: Boolean, default: false },
  showScopeFooter: { type: Boolean, default: true },
  // 登录等一次只能进入一间门店的场景，组织节点仅用于展开下级门店，
  // 不能把整个组织的门店范围当作一次选择结果。
  singleStoreOnly: { type: Boolean, default: false }
})

const emit = defineEmits(['update:modelValue', 'change'])
const open = ref(false)
const triggerRef = ref(null)
const panelRef = ref(null)
const panelStyle = ref({})
const expandedKeys = ref(new Set())
const selectedOrganizationKey = ref('')
const selectedOrganizationName = ref('')
const selectedStores = ref([])
let panelResizeHandler = null
const panelScrollTargets = []

const allowedStoreIdSet = computed(() => new Set((props.allowedStoreIds || []).map(Number).filter(Boolean)))
const selectedStoreIds = computed(() => [...new Set((props.modelValue || []).map(Number).filter(Boolean))])

const visibleNodes = computed(() => {
  const result = []
  const walk = (nodes, depth = 0, parentKey = '') => (Array.isArray(nodes) ? nodes : []).forEach((node, index) => {
    const storeId = Number(node?.store_id || (node?.node_type === 'store' ? node?.id : 0))
    const key = `${parentKey}/${node?.node_type || 'org'}-${node?.id || node?.org_id || index}`
    const hasChildren = Array.isArray(node?.children) && node.children.length > 0
    result.push({
      key,
      node,
      depth,
      storeId,
      hasChildren,
      expanded: expandedKeys.value.has(key),
      name: node?.title || node?.name || node?.label || (storeId > 0 ? `门店${storeId}` : '未命名组织')
    })
    if (hasChildren && expandedKeys.value.has(key)) walk(node.children, depth + 1, key)
  })
  walk(props.tree)
  return result
})

watch(() => props.modelValue, (value) => {
  if (!Array.isArray(value) || !value.length) {
    selectedOrganizationKey.value = ''
    selectedOrganizationName.value = ''
    selectedStores.value = []
  }
}, { deep: true })

watch(() => props.tree, (tree) => {
  if (!props.embedded || expandedKeys.value.size || !Array.isArray(tree)) return
  const initial = tree
    .filter((node) => Array.isArray(node?.children) && node.children.length)
    .map((node, index) => `/${node?.node_type || 'org'}-${node?.id || node?.org_id || index}`)
  if (initial.length) expandedKeys.value = new Set(initial)
}, { immediate: true, deep: true })

function emitChange(storeIds, label, close = false) {
  const ids = [...new Set((storeIds || []).map(Number).filter(Boolean))]
  emit('update:modelValue', ids)
  emit('change', { storeIds: ids, label })
  if (close) open.value = false
}

function updatePanelPosition() {
  if (!open.value || typeof window === 'undefined' || !triggerRef.value) return

  const triggerRect = triggerRef.value.getBoundingClientRect()
  const availableWidth = Math.max(240, window.innerWidth - 24)
  const panelWidth = Math.min(480, availableWidth)
  const targetHeight = Math.min(360, Math.max(280, window.innerHeight - 24))
  const left = Math.min(
    Math.max(12, triggerRect.left),
    Math.max(12, window.innerWidth - panelWidth - 12)
  )
  const spaceBelow = window.innerHeight - triggerRect.bottom - 8
  const spaceAbove = triggerRect.top - 8
  const openAbove = spaceBelow < targetHeight && spaceAbove > spaceBelow
  const top = openAbove
    ? Math.max(8, triggerRect.top - targetHeight - 8)
    : Math.min(window.innerHeight - targetHeight - 8, triggerRect.bottom + 8)

  panelStyle.value = {
    position: 'fixed',
    zIndex: 2000,
    left: `${left}px`,
    top: `${Math.max(8, top)}px`,
    width: `${panelWidth}px`,
    maxHeight: `${targetHeight}px`
  }
}

function bindPanelPositioning() {
  if (typeof window === 'undefined' || panelResizeHandler) return
  unbindPanelPositioning()
  panelResizeHandler = () => updatePanelPosition()
  window.addEventListener('resize', panelResizeHandler, { passive: true })
  const trigger = triggerRef.value
  const candidates = []
  let parent = trigger?.parentElement || null
  while (parent) {
    const style = window.getComputedStyle(parent)
    const overflow = `${style.overflow}${style.overflowX}${style.overflowY}`
    if (/(auto|scroll|overlay)/i.test(overflow)) candidates.push(parent)
    parent = parent.parentElement
  }
  candidates.push(window)
  candidates.forEach((target) => {
    target.addEventListener('scroll', panelResizeHandler, { passive: true })
    panelScrollTargets.push(target)
  })
}

function unbindPanelPositioning() {
  if (typeof window === 'undefined') return
  if (panelResizeHandler) window.removeEventListener('resize', panelResizeHandler)
  panelScrollTargets.splice(0).forEach((target) => {
    target.removeEventListener?.('scroll', panelResizeHandler)
  })
  panelResizeHandler = null
}

function toggleNode(option) {
  const next = new Set(expandedKeys.value)
  if (next.has(option.key)) next.delete(option.key)
  else next.add(option.key)
  expandedKeys.value = next
}

function nodeStoreIds(node) {
  const ids = []
  const walk = (item) => {
    const storeId = Number(item?.store_id || (item?.node_type === 'store' ? item?.id : 0))
    if (storeId > 0 && allowedStoreIdSet.value.has(storeId)) ids.push(storeId)
    ;(Array.isArray(item?.children) ? item.children : []).forEach(walk)
  }
  walk(node)
  return [...new Set(ids)]
}

function nodeStores(node) {
  const stores = []
  const walk = (item) => {
    const storeId = Number(item?.store_id || (item?.node_type === 'store' ? item?.id : 0))
    if (storeId > 0 && allowedStoreIdSet.value.has(storeId)) {
      stores.push({ id: storeId, name: item?.title || item?.name || `门店${storeId}` })
    }
    ;(Array.isArray(item?.children) ? item.children : []).forEach(walk)
  }
  walk(node)
  return stores.filter((store, index, all) => all.findIndex((item) => item.id === store.id) === index)
}

function selectNode(option) {
  if (option.storeId > 0) {
    selectStore({ id: option.storeId, name: option.name })
    return
  }
  const ids = nodeStoreIds(option.node)
  if (!ids.length) return
  selectedOrganizationKey.value = option.key
  selectedOrganizationName.value = option.name
  selectedStores.value = nodeStores(option.node)
  if (props.singleStoreOnly) return
  emitChange(ids, option.name)
}

function selectStore(store) {
  const storeId = Number(store?.id)
  if (!storeId || !allowedStoreIdSet.value.has(storeId)) return
  emitChange([storeId], store?.name || `门店${storeId}`, true)
}

function chooseAll() {
  selectedOrganizationKey.value = ''
  selectedOrganizationName.value = ''
  selectedStores.value = []
  emitChange([], '当前权限范围', true)
}

watch(open, async (value) => {
  if (props.embedded) return
  if (value) {
    bindPanelPositioning()
    await nextTick()
    updatePanelPosition()
    return
  }
  panelStyle.value = {}
  unbindPanelPositioning()
})

onMounted(() => {
  if (open.value) {
    bindPanelPositioning()
    void nextTick().then(updatePanelPosition)
  }
})

onBeforeUnmount(() => {
  unbindPanelPositioning()
})
</script>

<template>
  <div class="organization-store-scope-picker" :class="{ 'organization-store-scope-picker--embedded': embedded }">
    <button v-if="!embedded" ref="triggerRef" type="button" class="organization-store-scope-picker__trigger" :disabled="loading" @click="open = !open">
      <Network :size="16" aria-hidden="true" />
      <span>{{ loading ? '读取权限范围' : label }}</span>
      <ChevronDown :size="15" aria-hidden="true" />
    </button>

    <section ref="panelRef" v-if="embedded || open" class="organization-store-scope-picker__panel" :class="{ 'organization-store-scope-picker__panel--embedded': embedded }" :style="embedded ? {} : panelStyle" aria-label="组织和门店权限范围">
      <header>组织 / 门店</header>
      <div class="organization-store-scope-picker__body">
        <div class="organization-store-scope-picker__tree" aria-label="组织树">
          <p v-if="loading" class="organization-store-scope-picker__empty">正在读取组织范围。</p>
          <p v-else-if="!visibleNodes.length" class="organization-store-scope-picker__empty">当前权限范围内暂无门店。</p>
          <div v-for="node in visibleNodes" :key="node.key" class="organization-store-scope-picker__tree-row" :style="{ paddingLeft: `${node.depth * 18}px` }">
            <button v-if="node.hasChildren" type="button" class="organization-store-scope-picker__toggle" :aria-label="node.expanded ? '收起组织' : '展开组织'" @click.stop="toggleNode(node)">
              <ChevronDown v-if="node.expanded" :size="15" aria-hidden="true" />
              <ChevronRight v-else :size="15" aria-hidden="true" />
            </button>
            <span v-else class="organization-store-scope-picker__toggle-placeholder" aria-hidden="true" />
            <button type="button" class="organization-store-scope-picker__option" :class="{ 'organization-store-scope-picker__option--selected': selectedOrganizationKey === node.key, 'organization-store-scope-picker__option--store': node.storeId > 0 }" @click="selectNode(node)">{{ node.name }}</button>
          </div>
        </div>
        <div class="organization-store-scope-picker__stores" aria-label="可选门店">
          <p class="organization-store-scope-picker__stores-title">{{ selectedOrganizationName || '选择组织后查看组织及下级门店' }}</p>
          <div v-if="selectedStores.length" class="organization-store-scope-picker__store-list">
            <button v-for="store in selectedStores" :key="store.id" type="button" :class="{ 'is-active': selectedStoreIds.length === 1 && selectedStoreIds[0] === store.id }" @click="selectStore(store)">{{ store.name }}</button>
          </div>
          <p v-else class="organization-store-scope-picker__empty">请选择左侧组织。</p>
        </div>
      </div>
      <footer v-if="showScopeFooter">
        <button v-if="!singleStoreOnly" type="button" @click="chooseAll">当前权限范围</button>
        <span v-else class="organization-store-scope-picker__scope-label">当前权限范围</span>
        <span>{{ singleStoreOnly ? '选择组织查看其下级门店；请选择一间门店登录。' : '选择组织查询其全部下级门店；选择门店仅查询该门店。' }}</span>
      </footer>
    </section>
  </div>
</template>

<style scoped>
.organization-store-scope-picker { position: relative; display: inline-block; }
.organization-store-scope-picker--embedded { display: block; width: 100%; }
.organization-store-scope-picker__trigger { display: inline-flex; align-items: center; gap: 6px; min-height: 32px; border: 1px solid #dcdee2; border-radius: 4px; padding: 5px 10px; background: #fff; color: #515a6e; font: inherit; font-size: 13px; cursor: pointer; }
.organization-store-scope-picker__trigger:hover { border-color: #57a3f3; color: #2d8cf0; }
.organization-store-scope-picker__trigger:disabled { cursor: wait; opacity: .65; }
.organization-store-scope-picker__panel { overflow: hidden; border: 1px solid #dcdee2; border-radius: 4px; background: #fff; box-shadow: 0 2px 12px rgba(0, 0, 0, .14); }
.organization-store-scope-picker__panel--embedded { position: static; width: 100%; max-height: none; box-shadow: none; }
.organization-store-scope-picker__panel header { padding: 12px 14px 8px; border-bottom: 1px solid #edf0f5; color: #17233d; font-size: 14px; font-weight: 600; }
.organization-store-scope-picker__body { display: flex; min-height: 230px; }
.organization-store-scope-picker__tree { position: relative; flex: 1; max-height: 260px; overflow: auto; padding: 7px 8px; border-right: 1px solid #edf0f5; }
.organization-store-scope-picker__tree-row { display: flex; align-items: center; min-height: 31px; }
.organization-store-scope-picker__toggle, .organization-store-scope-picker__toggle-placeholder { display: inline-grid; flex: none; width: 22px; height: 31px; place-items: center; }
.organization-store-scope-picker__toggle { border: 0; border-radius: 3px; padding: 0; background: transparent; color: #657386; cursor: pointer; }
.organization-store-scope-picker__toggle:hover { background: #edf5ff; color: #2d8cf0; }
.organization-store-scope-picker__option { display: block; flex: 1; min-width: 0; min-height: 31px; border: 0; border-radius: 3px; padding: 0 8px; background: #fff; color: #515a6e; text-align: left; font: inherit; font-size: 13px; cursor: pointer; }
.organization-store-scope-picker__option:hover, .organization-store-scope-picker__option--selected { background: #edf5ff; color: #2d8cf0; }
.organization-store-scope-picker__option--store { color: #657386; }
.organization-store-scope-picker__stores { width: 205px; max-height: 260px; overflow: auto; padding: 10px 12px; }
.organization-store-scope-picker__stores-title { min-height: 18px; margin: 0 0 8px; color: #666; font-size: 12px; line-height: 18px; }
.organization-store-scope-picker__store-list button { display: block; width: 100%; min-height: 31px; border: 0; border-radius: 3px; padding: 5px 8px; background: transparent; color: #515a6e; text-align: left; font: inherit; font-size: 13px; cursor: pointer; }
.organization-store-scope-picker__store-list button:hover, .organization-store-scope-picker__store-list button.is-active { background: #edf5ff; color: #2d8cf0; }
.organization-store-scope-picker__empty { margin: 0; padding: 8px 2px; color: #bbb; font-size: 12px; line-height: 1.55; }
.organization-store-scope-picker__panel footer { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 10px 14px; border-top: 1px solid #edf0f5; color: #999; font-size: 12px; line-height: 1.45; }
.organization-store-scope-picker__panel footer button { flex: none; border: 0; padding: 0; background: transparent; color: #2d8cf0; font: inherit; font-size: 12px; cursor: pointer; }
.organization-store-scope-picker__scope-label { flex: none; color: #2d8cf0; }
</style>

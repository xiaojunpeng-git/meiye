<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { ChevronDown, ChevronRight, Network } from '@lucide/vue'

const props = defineProps({
  modelValue: { type: String, default: 'HQ:0' },
  tree: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  showScopeDefault: { type: Boolean, default: false },
  showHqDefault: { type: Boolean, default: true }
})
const emit = defineEmits(['update:modelValue'])
const root = ref(null)
const open = ref(false)
const expanded = ref({})
const selectedOrganization = ref(null)
watch(() => props.tree, () => { selectedOrganization.value = null; expanded.value = {} })

// Typed keys keep the current authorized scope, an organization (including legacy ID 0), HQ and stores distinct.
function storesUnder(node) {
  const stores = []
  const visit = item => {
    if (item.node_type === 'store') stores.push(item)
    else (item.children || []).forEach(visit)
  }
  if (node) visit(node)
  return stores
}
const rows = computed(() => {
  const result = []
  const walk = (nodes, depth) => {
    for (const node of nodes || []) {
      if (node.node_type === 'store') continue
      result.push({ node, depth })
      if (expanded.value[node.id]) walk(node.children, depth + 1)
    }
  }
  walk(props.tree, 0)
  return result
})
const visibleStores = computed(() => storesUnder(selectedOrganization.value))
const selectedName = computed(() => {
  if (props.modelValue === 'SCOPE:0') return '当前权限范围'
  if (props.modelValue === 'HQ:0') return '平台端'
  const find = nodes => {
    for (const node of nodes || []) {
      if (`${node.node_type === 'org' ? 'ORG' : 'STORE'}:${node.id}` === props.modelValue) return node.title || node.name
      const nested = find(node.children)
      if (nested) return nested
    }
    return ''
  }
  return find(props.tree) || '所选范围'
})
function expandOrganization(node) {
  selectedOrganization.value = node
  expanded.value = { ...expanded.value, [node.id]: !expanded.value[node.id] }
}
function chooseOrganization(node) {
  selectedOrganization.value = node
  emit('update:modelValue', `ORG:${node.id}`)
  open.value = false
}
function chooseStore(node) {
  emit('update:modelValue', `STORE:${node.id}`)
  open.value = false
}
function clear(value = 'HQ:0') {
  emit('update:modelValue', value)
  open.value = false
}
function closeOutside(event) {
  if (root.value && !root.value.contains(event.target)) open.value = false
}
onMounted(() => document.addEventListener('pointerdown', closeOutside))
onBeforeUnmount(() => document.removeEventListener('pointerdown', closeOutside))
</script>

<template>
  <div ref="root" class="inventory-organization-picker">
    <button type="button" class="inventory-organization-picker__trigger" :aria-expanded="open" @click="open = !open">
      <Network :size="15" /><span>{{ selectedName }}</span><ChevronDown :size="15" />
    </button>
    <section v-if="open" class="inventory-organization-picker__panel" aria-label="组织和门店选择">
      <header>组织 / 门店</header>
      <div class="inventory-organization-picker__body">
        <div class="inventory-organization-picker__organizations">
          <p v-if="loading">正在读取组织...</p>
          <p v-else-if="!rows.length">暂无可选门店</p>
          <div v-for="row in rows" :key="row.node.id" class="inventory-organization-picker__organization" :style="{ paddingLeft: `${8 + row.depth * 18}px` }">
            <button type="button" class="inventory-organization-picker__expand" :aria-label="`展开${row.node.title || row.node.name}`" @click="expandOrganization(row.node)"><ChevronDown v-if="expanded[row.node.id]" :size="13" /><ChevronRight v-else :size="13" /></button>
            <button type="button" class="inventory-organization-picker__organization-name" :class="{ active: modelValue === `ORG:${row.node.id}` }" @click="chooseOrganization(row.node)">{{ row.node.title || row.node.name }}</button>
          </div>
        </div>
        <div class="inventory-organization-picker__stores">
          <p>{{ selectedOrganization ? (selectedOrganization.title || selectedOrganization.name) : '选择组织后查看门店' }}</p>
          <button v-for="store in visibleStores" :key="store.id" type="button" :class="{ active: modelValue === `STORE:${store.id}` }" @click="chooseStore(store)">{{ store.title || store.name }}</button>
        </div>
      </div>
      <footer><button v-if="showScopeDefault" type="button" @click="clear('SCOPE:0')">当前权限范围</button><button v-if="showHqDefault" type="button" @click="clear('HQ:0')">平台端</button></footer>
    </section>
  </div>
</template>

<style scoped>
.inventory-organization-picker { position: relative; min-width: 160px; font-size: 12px; }
.inventory-organization-picker__trigger { display: flex; align-items: center; gap: 6px; width: 100%; height: 34px; padding: 0 9px; border: 1px solid #d8e3ed; border-radius: 6px; background: #fff; color: #40566d; text-align: left; }
.inventory-organization-picker__trigger span { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.inventory-organization-picker__trigger:hover { border-color: #82b8ea; }
.inventory-organization-picker__panel { position: absolute; z-index: 40; top: calc(100% + 5px); left: 0; width: min(480px, calc(100vw - 36px)); overflow: hidden; border: 1px solid #d8e3ed; border-radius: 6px; background: #fff; box-shadow: 0 8px 24px #233e5b26; }
.inventory-organization-picker__panel header { padding: 10px 13px; border-bottom: 1px solid #edf0f5; font-weight: 600; }
.inventory-organization-picker__body { display: flex; min-height: 260px; }
.inventory-organization-picker__organizations, .inventory-organization-picker__stores { max-height: 330px; overflow: auto; padding: 7px; }
.inventory-organization-picker__organizations { width: 56%; border-right: 1px solid #edf0f5; }
.inventory-organization-picker__stores { flex: 1; }
.inventory-organization-picker__body p { margin: 4px 7px 8px; color: #8393a4; }
.inventory-organization-picker__body button { display: flex; align-items: center; gap: 5px; width: 100%; min-height: 29px; border: 0; border-radius: 3px; background: transparent; color: #40566d; text-align: left; }
.inventory-organization-picker__body button:hover, .inventory-organization-picker__body button.active { background: #edf5ff; color: #176fd1; }
.inventory-organization-picker__organization { display: flex; align-items: center; }
.inventory-organization-picker__organization .inventory-organization-picker__expand { width: 20px; min-width: 20px; padding: 0; justify-content: center; }
.inventory-organization-picker__organization .inventory-organization-picker__organization-name { flex: 1; min-width: 0; padding: 0 4px; }
.inventory-organization-picker__panel footer { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 9px 13px; border-top: 1px solid #edf0f5; color: #8393a4; }
.inventory-organization-picker__panel footer button { border: 0; background: transparent; color: #176fd1; cursor: pointer; }
</style>

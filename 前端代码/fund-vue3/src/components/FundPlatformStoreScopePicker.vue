<script setup>
import { computed, ref } from 'vue'
import { ChevronDown, ChevronRight, Network } from '@lucide/vue'

const props = defineProps({ modelValue: { type: Number, default: 0 }, tree: { type: Array, default: () => [] }, loading: Boolean })
const emit = defineEmits(['update:modelValue'])
const open = ref(false)
const expanded = ref(new Set())
const selectedOrganization = ref('')
const stores = ref([])

const options = computed(() => {
  const rows = []
  const walk = (nodes, depth = 0, parentKey = '') => (Array.isArray(nodes) ? nodes : []).forEach((node, index) => {
    const storeId = Number(node?.store_id || (node?.node_type === 'store' ? node?.id : 0))
    const key = `${parentKey}/${node?.node_type || 'org'}-${node?.id || node?.org_id || index}`
    const hasChildren = Array.isArray(node?.children) && node.children.length > 0
    rows.push({ node, depth, key, storeId, hasChildren, isExpanded: expanded.value.has(key) })
    if (hasChildren && expanded.value.has(key)) walk(node.children, depth + 1, key)
  })
  walk(props.tree)
  return rows
})
const selectedStore = computed(() => stores.value.find((store) => Number(store.id) === Number(props.modelValue)))
const selectedTreeStore = computed(() => findStore(props.tree, Number(props.modelValue)))
const label = computed(() => selectedStore.value?.name || selectedTreeStore.value?.name || '请选择门店')

function title(node) { return node?.title || node?.name || node?.label || '-' }
function findStore(nodes, targetId) {
  for (const node of Array.isArray(nodes) ? nodes : []) {
    const id = Number(node?.store_id || (node?.node_type === 'store' ? node?.id : 0))
    if (id === targetId && id > 0) return { id, name: title(node) }
    const found = findStore(node?.children, targetId)
    if (found) return found
  }
  return null
}
function collectStores(node) {
  const output = []
  const walk = (item) => {
    const id = Number(item?.store_id || (item?.node_type === 'store' ? item?.id : 0))
    if (id > 0) output.push({ id, name: title(item) })
    ;(Array.isArray(item?.children) ? item.children : []).forEach(walk)
  }
  walk(node)
  return output.filter((store, index, all) => all.findIndex((item) => item.id === store.id) === index)
}
function toggle(option) {
  const next = new Set(expanded.value)
  if (next.has(option.key)) next.delete(option.key)
  else next.add(option.key)
  expanded.value = next
}
function chooseNode(option) {
  if (option.storeId > 0) return chooseStore({ id: option.storeId, name: title(option.node) })
  selectedOrganization.value = option.key
  stores.value = collectStores(option.node)
}
function chooseStore(store) {
  stores.value = stores.value.some((item) => item.id === Number(store.id)) ? stores.value : [store]
  emit('update:modelValue', Number(store.id))
  open.value = false
}
</script>

<template>
  <div class="fund-scope-picker">
    <button type="button" class="fund-scope-trigger" :class="{ 'is-empty': !modelValue }" :disabled="loading" @click="open = !open">
      <Network :size="16" aria-hidden="true" /> {{ loading ? '读取门店范围' : label }}
    </button>
    <section v-if="open" class="fund-scope-panel" aria-label="选择组织和门店">
      <header>组织 / 门店</header>
      <div class="fund-scope-body">
        <div class="fund-scope-tree" aria-label="组织树">
          <p v-if="loading" class="fund-scope-empty">正在读取可选范围。</p>
          <template v-else>
            <div v-for="option in options" :key="option.key" class="fund-scope-tree-row" :style="{ paddingLeft: `${8 + option.depth * 18}px` }">
              <button v-if="option.hasChildren" type="button" class="fund-scope-toggle" :aria-label="`${option.isExpanded ? '折叠' : '展开'}${title(option.node)}`" @click="toggle(option)">
                <ChevronDown v-if="option.isExpanded" :size="15" aria-hidden="true" />
                <ChevronRight v-else :size="15" aria-hidden="true" />
              </button>
              <span v-else class="fund-scope-toggle-placeholder" aria-hidden="true"></span>
              <button type="button" class="fund-scope-node" :class="{ 'is-selected': selectedOrganization === option.key, 'is-store': option.storeId > 0 }" @click="chooseNode(option)">{{ title(option.node) }}</button>
            </div>
          </template>
        </div>
        <div class="fund-scope-stores" aria-label="门店列表">
          <p>{{ stores.length ? '选择一个门店后查询' : '先选择组织，再选择门店' }}</p>
          <div v-if="stores.length" class="fund-scope-store-list">
            <button v-for="store in stores" :key="store.id" type="button" :class="{ 'is-active': Number(modelValue) === store.id }" @click="chooseStore(store)">{{ store.name }}</button>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

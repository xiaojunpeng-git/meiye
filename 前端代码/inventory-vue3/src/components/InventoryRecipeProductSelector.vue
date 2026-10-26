<script setup>
import { computed, ref, watch } from 'vue'
import { ChevronLeft, ChevronRight, Search, X } from '@lucide/vue'
import { platformInventoryApi } from '../services/inventoryApi'

const props = defineProps({
  visible: { type: Boolean, default: false },
  kind: { type: String, required: true },
  selectedRows: { type: Array, default: () => [] }
})

const emit = defineEmits(['close', 'confirm'])
const keyword = ref('')
const page = ref(1)
const limit = 12
const rows = ref([])
const total = ref(0)
const loading = ref(false)
const loadError = ref('')
const selection = ref(new Map())

const isProject = computed(() => props.kind === 'project')
const title = computed(() => isProject.value ? '选择项目' : '添加耗材')
const totalPages = computed(() => Math.max(1, Math.ceil(total.value / limit)))
const selectedCount = computed(() => selection.value.size)

function rowKey(row) {
  return `${Number(row.product_id)}:${String(row.sku_unique || '')}`
}

function normalizeCatalog(response) {
  const products = Array.isArray(response?.list) ? response.list : []
  return products.flatMap((product) => (Array.isArray(product.attrValue) ? product.attrValue : []).map((sku) => ({
    product_id: Number(sku.product_id || product.id),
    product_name: product.store_name || '',
    product_type: Number(product.product_type),
    sku_id: Number(sku.id),
    sku_unique: String(sku.unique || ''),
    sku_name: sku.suk || sku.unique || '默认规格',
    product_code: product.code || '',
    barcode: sku.bar_code || product.bar_code || '',
    stock_unit: sku.stock_unit || product.unit_name || ''
  })))
}

function resetSelection() {
  selection.value = new Map(props.selectedRows.map((row) => [rowKey(row), row]))
}

async function loadCatalog() {
  loading.value = true
  loadError.value = ''
  try {
    const response = await platformInventoryApi.searchRecipeCatalog({
      kind: props.kind,
      keyword: keyword.value,
      page: page.value,
      limit
    })
    rows.value = normalizeCatalog(response)
    total.value = Number(response?.count ?? response?.total ?? rows.value.length)
  } catch (error) {
    rows.value = []
    total.value = 0
    loadError.value = error instanceof Error ? error.message : `${title.value}目录读取失败。`
  } finally {
    loading.value = false
  }
}

function isSelected(row) {
  return selection.value.has(rowKey(row))
}

function toggleRow(row) {
  const key = rowKey(row)
  if (isProject.value) {
    selection.value = new Map([[key, row]])
    return
  }
  const next = new Map(selection.value)
  if (next.has(key)) next.delete(key)
  else next.set(key, row)
  selection.value = next
}

function search() {
  page.value = 1
  loadCatalog()
}

function changePage(nextPage) {
  page.value = Math.min(Math.max(1, nextPage), totalPages.value)
  loadCatalog()
}

function confirm() {
  emit('confirm', Array.from(selection.value.values()))
}

watch(() => props.visible, (visible) => {
  if (!visible) return
  keyword.value = ''
  page.value = 1
  resetSelection()
  loadCatalog()
})
</script>

<template>
  <Teleport to="body">
    <div v-if="visible" class="recipe-selector-layer" role="presentation">
      <button class="recipe-selector-mask" aria-label="关闭选择窗口" @click="emit('close')" />
      <section class="recipe-selector" role="dialog" aria-modal="true" :aria-label="title">
        <header>
          <div><p>{{ isProject ? '项目目录' : '院装耗材目录' }}</p><h2>{{ title }}</h2></div>
          <button class="icon-button" title="关闭" @click="emit('close')"><X :size="20" /></button>
        </header>
        <div class="selector-filters">
          <label>{{ isProject ? '项目搜索' : '耗材搜索' }}
            <span><Search :size="16" /><input v-model="keyword" :placeholder="isProject ? '项目名称、规格或编码' : '商品名称、规格或编码'" @keyup.enter="search" /></span>
          </label>
          <button class="primary" :disabled="loading" @click="search">{{ loading ? '查询中' : '查询' }}</button>
        </div>
        <main>
          <p v-if="loadError" class="selector-error">{{ loadError }}</p>
          <div class="selector-table-scroll">
            <table>
              <thead><tr><th>选择</th><th>{{ isProject ? '项目名称' : '耗材名称' }}</th><th>规格</th><th>编码</th><th>库存单位</th></tr></thead>
              <tbody>
                <tr v-if="loading"><td colspan="5" class="selector-empty">正在读取目录...</td></tr>
                <tr v-else-if="!rows.length"><td colspan="5" class="selector-empty">暂无符合条件的{{ isProject ? '项目' : '耗材' }}</td></tr>
                <tr v-for="row in rows" v-else :key="rowKey(row)" :class="{ selected: isSelected(row) }" @click="toggleRow(row)">
                  <td><input :type="isProject ? 'radio' : 'checkbox'" :checked="isSelected(row)" @click.stop="toggleRow(row)" /></td>
                  <td><strong>{{ row.product_name }}</strong></td><td>{{ row.sku_name }}</td><td>{{ row.product_code || '-' }}</td><td>{{ row.stock_unit || '-' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </main>
        <footer>
          <span>已选择 {{ selectedCount }} 个规格，共 {{ total }} 条商品</span>
          <div>
            <button class="page-button" :disabled="page <= 1 || loading" title="上一页" @click="changePage(page - 1)"><ChevronLeft :size="17" /></button>
            <span>第 {{ page }} / {{ totalPages }} 页</span>
            <button class="page-button" :disabled="page >= totalPages || loading" title="下一页" @click="changePage(page + 1)"><ChevronRight :size="17" /></button>
            <button class="secondary" @click="emit('close')">取消</button>
            <button class="primary" :disabled="selectedCount === 0" @click="confirm">确认选择</button>
          </div>
        </footer>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.recipe-selector-layer { position: fixed; z-index: 130; inset: 0; display: grid; place-items: center; padding: 28px; }.recipe-selector-mask { position: absolute; inset: 0; width: 100%; border: 0; background: rgba(17, 30, 46, .46); }.recipe-selector { position: relative; display: grid; grid-template-rows: auto auto minmax(0, 1fr) auto; width: min(1040px, calc(100vw - 56px)); max-height: calc(100vh - 56px); overflow: hidden; border: 1px solid #d8e3ed; border-radius: 8px; background: #fff; box-shadow: 0 24px 60px rgba(8, 25, 45, .28); }.recipe-selector > header, .recipe-selector > footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 15px 20px; border-bottom: 1px solid #e0e7ef; }.recipe-selector header p { margin: 0 0 4px; color: #8a97a5; font-size: 11px; }.recipe-selector header h2 { margin: 0; color: #253a50; font-size: 18px; }.icon-button, .page-button { display: inline-grid; width: 34px; height: 34px; place-items: center; padding: 0; border: 1px solid #dce5ee; border-radius: 6px; background: #fff; color: #47627d; }.page-button:disabled { cursor: default; opacity: .45; }.selector-filters { display: flex; align-items: end; gap: 10px; padding: 14px 20px; border-bottom: 1px solid #e0e7ef; background: #fbfdff; }.selector-filters label { display: grid; flex: 1; gap: 5px; color: #60748a; font-size: 12px; }.selector-filters label span { display: flex; align-items: center; gap: 7px; height: 34px; padding: 0 9px; border: 1px solid #d8e3ed; border-radius: 6px; background: #fff; }.selector-filters input { width: 100%; border: 0; outline: 0; background: transparent; }.recipe-selector main { min-height: 280px; overflow: auto; }.selector-table-scroll { overflow: auto; }.selector-table-scroll table { width: 100%; min-width: 720px; border-collapse: collapse; }.selector-table-scroll th, .selector-table-scroll td { height: 45px; padding: 0 14px; border-bottom: 1px solid #e7edf3; color: #40566d; font-size: 12px; text-align: left; white-space: nowrap; }.selector-table-scroll th { height: 39px; background: #f7f9fc; color: #60748a; font-size: 11px; }.selector-table-scroll tbody tr { cursor: pointer; }.selector-table-scroll tbody tr:hover, .selector-table-scroll tbody tr.selected { background: #f1f8ff; }.selector-empty { height: 150px !important; color: #8798a9 !important; text-align: center !important; }.selector-error { margin: 12px 20px 0; color: #bf5050; font-size: 12px; }.recipe-selector > footer { border-top: 1px solid #e0e7ef; border-bottom: 0; color: #71849a; font-size: 12px; }.recipe-selector > footer > div { display: flex; align-items: center; gap: 8px; }.primary, .secondary { min-height: 34px; padding: 0 13px; border-radius: 6px; font-size: 12px; }.primary { border: 1px solid #176fd1; background: #176fd1; color: #fff; }.primary:disabled { cursor: default; opacity: .55; }.secondary { border: 1px solid #d5e1ee; background: #fff; color: #47627d; }
@media (max-width: 760px) { .recipe-selector-layer { padding: 12px; }.recipe-selector { width: 100%; max-height: calc(100vh - 24px); }.recipe-selector > footer { align-items: flex-start; flex-direction: column; }.recipe-selector > footer > div { flex-wrap: wrap; } }
</style>

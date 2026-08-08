<script setup>
import { computed, ref, watch } from 'vue'
import { ChevronLeft, ChevronRight, Search, X } from '@lucide/vue'
import { inventoryApi } from '../services/inventoryApi'

const props = defineProps({
  visible: { type: Boolean, default: false },
  selectedRows: { type: Array, default: () => [] },
  catalogApi: { type: Object, default: () => inventoryApi },
  catalogQuery: { type: Object, default: () => ({}) }
})

const emit = defineEmits(['close', 'confirm'])
const keyword = ref('')
const categoryId = ref(0)
const page = ref(1)
const limit = 12
const rows = ref([])
const categories = ref([])
const total = ref(0)
const loading = ref(false)
const loadError = ref('')
const selection = ref(new Map())

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / limit)))
const selectedCount = computed(() => selection.value.size)

function resetSelection() {
  selection.value = new Map(props.selectedRows.map((row) => [Number(row.sku_id), row]))
}

async function loadCatalog() {
  loading.value = true
  loadError.value = ''
  try {
    if (!props.catalogApi?.searchCatalog) throw new Error('商品目录服务尚未初始化。')
    const response = await props.catalogApi.searchCatalog({
      ...props.catalogQuery,
      keyword: keyword.value.trim(),
      category_id: Number(categoryId.value) || 0,
      page: page.value,
      limit
    })
    rows.value = Array.isArray(response?.list) ? response.list : []
    categories.value = Array.isArray(response?.categories) ? response.categories : []
    total.value = Number(response?.total || 0)
    const serverPage = Number(response?.page || page.value)
    page.value = Math.min(Math.max(1, serverPage), totalPages.value)
  } catch (error) {
    rows.value = []
    total.value = 0
    loadError.value = error instanceof Error ? error.message : '商品目录读取失败。'
  } finally {
    loading.value = false
  }
}

function isSelected(row) {
  return selection.value.has(Number(row.sku_id))
}

function toggleRow(row) {
  const next = new Map(selection.value)
  const key = Number(row.sku_id)
  if (next.has(key)) next.delete(key)
  else next.set(key, row)
  selection.value = next
}

function changeCategory() {
  page.value = 1
  loadCatalog()
}

function search() {
  page.value = 1
  loadCatalog()
}

function changePage(nextPage) {
  page.value = Math.min(Math.max(1, nextPage), totalPages.value)
  loadCatalog()
}

function close() {
  emit('close')
}

function confirm() {
  emit('confirm', Array.from(selection.value.values()))
}

watch(() => props.visible, (visible) => {
  if (!visible) return
  resetSelection()
  page.value = 1
  loadCatalog()
})
</script>

<template>
  <Teleport to="body">
    <div v-if="visible" class="inventory-product-selector-layer" role="presentation">
      <button class="inventory-product-selector-mask" aria-label="关闭商品选择" @click="close" />
      <section class="inventory-product-selector" role="dialog" aria-modal="true" aria-label="选择商品">
        <header>
          <div><p>库存商品目录</p><h2>选择商品</h2></div>
          <button class="selector-icon-button" title="关闭" @click="close"><X :size="20" /></button>
        </header>

        <div class="inventory-product-selector__filters">
          <label>商品分类
            <select v-model.number="categoryId" @change="changeCategory">
              <option :value="0">全部分类</option>
              <option v-for="category in categories" :key="category.id" :value="Number(category.id)">{{ category.name }}</option>
            </select>
          </label>
          <label class="selector-search">商品搜索
            <span><Search :size="16" /><input v-model="keyword" placeholder="商品名称、SKU、编码或条码" @keyup.enter="search" /></span>
          </label>
          <button class="selector-primary" :disabled="loading" @click="search">{{ loading ? '查询中' : '查询' }}</button>
        </div>

        <main>
          <p v-if="loadError" class="selector-error">{{ loadError }}</p>
          <div class="selector-table-scroll">
            <table>
              <thead><tr><th>选择</th><th>商品名称</th><th>规格</th><th>商品编码</th><th>条码</th><th>库存单位</th></tr></thead>
              <tbody>
                <tr v-if="loading"><td colspan="6" class="selector-empty">正在读取商品目录...</td></tr>
                <tr v-else-if="!rows.length"><td colspan="6" class="selector-empty">暂无符合条件的库存商品</td></tr>
                <tr v-else v-for="row in rows" :key="row.sku_id" :class="{ 'selector-row--selected': isSelected(row) }" @click="toggleRow(row)">
                  <td><input type="checkbox" :checked="isSelected(row)" @click.stop="toggleRow(row)" /></td>
                  <td><strong>{{ row.product_name }}</strong></td><td>{{ row.sku_name || '默认规格' }}</td><td>{{ row.product_code || '-' }}</td><td>{{ row.barcode || '-' }}</td><td>{{ row.stock_unit || '-' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </main>

        <footer>
          <span>已选择 {{ selectedCount }} 个 SKU，共 {{ total }} 条商品规格</span>
          <div>
            <button class="selector-pagination" :disabled="page <= 1 || loading" title="上一页" @click="changePage(page - 1)"><ChevronLeft :size="17" /></button>
            <span class="selector-page">第 {{ page }} / {{ totalPages }} 页</span>
            <button class="selector-pagination" :disabled="page >= totalPages || loading" title="下一页" @click="changePage(page + 1)"><ChevronRight :size="17" /></button>
            <button class="selector-secondary" @click="close">取消</button>
            <button class="selector-primary" :disabled="selectedCount === 0" @click="confirm">确认选择</button>
          </div>
        </footer>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.inventory-product-selector-layer { position: fixed; z-index: 120; inset: 0; display: grid; place-items: center; padding: 28px; }.inventory-product-selector-mask { position: absolute; inset: 0; width: 100%; border: 0; background: rgba(17, 30, 46, .46); }.inventory-product-selector { position: relative; display: grid; grid-template-rows: auto auto minmax(0, 1fr) auto; width: min(1080px, calc(100vw - 56px)); max-height: calc(100vh - 56px); overflow: hidden; border: 1px solid #d8e3ed; border-radius: 8px; background: #f5f7fa; box-shadow: 0 24px 60px rgba(8, 25, 45, .28); }.inventory-product-selector > header, .inventory-product-selector > footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 15px 20px; border-bottom: 1px solid #e0e7ef; background: #fff; }.inventory-product-selector header p { margin: 0 0 4px; color: #8a97a5; font-size: 11px; }.inventory-product-selector header h2 { margin: 0; color: #253a50; font-size: 18px; }.selector-icon-button, .selector-pagination { display: inline-grid; width: 34px; height: 34px; place-items: center; padding: 0; border: 1px solid #dce5ee; border-radius: 6px; background: #fff; color: #47627d; }.selector-icon-button:hover, .selector-pagination:hover:not(:disabled) { border-color: #91caff; color: #176fd1; }.selector-pagination:disabled { cursor: default; opacity: .45; }.inventory-product-selector__filters { display: flex; align-items: end; gap: 12px; padding: 14px 20px; border-bottom: 1px solid #e0e7ef; background: #fbfdff; }.inventory-product-selector__filters label { display: grid; gap: 5px; color: #60748a; font-size: 12px; }.inventory-product-selector__filters select, .selector-search span { height: 34px; min-width: 170px; padding: 0 9px; border: 1px solid #d8e3ed; border-radius: 6px; background: #fff; color: #40566d; }.selector-search { min-width: 300px; flex: 1; }.selector-search span { display: flex; align-items: center; gap: 7px; min-width: 0; }.selector-search input { width: 100%; border: 0; outline: 0; background: transparent; color: #40566d; }.inventory-product-selector main { min-height: 280px; overflow: auto; }.selector-table-scroll { overflow: auto; }.selector-table-scroll table { width: 100%; min-width: 820px; border-collapse: collapse; }.selector-table-scroll th { height: 39px; padding: 0 14px; border-bottom: 1px solid #e7edf3; background: #f7f9fc; color: #60748a; font-size: 11px; font-weight: 600; text-align: left; white-space: nowrap; }.selector-table-scroll td { height: 51px; padding: 0 14px; border-bottom: 1px solid #edf1f5; color: #40566d; font-size: 12px; white-space: nowrap; }.selector-table-scroll tbody tr { cursor: pointer; }.selector-table-scroll tbody tr:hover, .selector-row--selected { background: #f1f8ff; }.selector-empty { height: 160px; color: #8798a9 !important; text-align: center; }.selector-error { margin: 12px 20px 0; color: #bf5050; font-size: 12px; }.inventory-product-selector > footer { border-top: 1px solid #e0e7ef; border-bottom: 0; color: #71849a; font-size: 12px; }.inventory-product-selector > footer > div { display: flex; align-items: center; gap: 8px; }.selector-page { min-width: 74px; color: #60748a; text-align: center; }.selector-primary, .selector-secondary { min-height: 34px; padding: 0 13px; border-radius: 6px; font-size: 12px; }.selector-primary { border: 1px solid #176fd1; background: #176fd1; color: #fff; }.selector-primary:disabled { cursor: default; opacity: .55; }.selector-secondary { border: 1px solid #d5e1ee; background: #fff; color: #47627d; }
@media (max-width: 760px) { .inventory-product-selector-layer { padding: 12px; }.inventory-product-selector { width: 100%; max-height: calc(100vh - 24px); }.inventory-product-selector__filters { flex-wrap: wrap; }.selector-search { min-width: 100%; }.inventory-product-selector > footer { align-items: flex-start; flex-direction: column; }.inventory-product-selector > footer > div { flex-wrap: wrap; } }
</style>

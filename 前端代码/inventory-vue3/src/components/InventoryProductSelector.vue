<script setup>
import { computed, ref, watch } from 'vue'
import { ChevronLeft, ChevronRight, Search, X } from '@lucide/vue'
import { inventoryApi } from '../services/inventoryApi'

const props = defineProps({
  visible: { type: Boolean, default: false },
  selectedRows: { type: Array, default: () => [] },
  catalogApi: { type: Object, default: () => inventoryApi },
  catalogQuery: { type: Object, default: () => ({}) },
  // 由库存单据开启数量回填。赠送等共用入口继续只接收选中的商品。
  quantityMode: { type: String, default: '' },
  // 库存查询按商品选择，单据仍按实际可操作的 SKU 选择。
  productOnly: { type: Boolean, default: false },
  // 嵌入赠送弹窗时只提升遮罩层级；业务范围仍由传入的服务端目录确定。
  layerIndex: { type: Number, default: 120 },
  allowEmpty: { type: Boolean, default: false }
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
let requestRevision = 0

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / limit)))
const selectedCount = computed(() => selection.value.size)
const columnCount = computed(() => props.productOnly ? 2 : props.quantityMode ? 6 : 5)

function selectionKey(row) {
  return Number(props.productOnly ? row.product_id : row.sku_id)
}

function resetSelection() {
  selection.value = new Map(props.selectedRows.map((row) => [selectionKey(row), { row, manual: true, quantity: '' }]))
}

async function loadCatalog() {
  // 快速搜索、翻页或关闭后，旧响应不得覆盖新查询或下一次打开的选择。
  const revision = ++requestRevision
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
    if (revision !== requestRevision) return
    rows.value = Array.isArray(response?.list) ? response.list : []
    categories.value = Array.isArray(response?.categories) ? response.categories : []
    total.value = Number(response?.total || 0)
    const serverPage = Number(response?.page || page.value)
    page.value = Math.min(Math.max(1, serverPage), totalPages.value)
  } catch (error) {
    if (revision !== requestRevision) return
    rows.value = []
    total.value = 0
    loadError.value = error instanceof Error ? error.message : '商品目录读取失败。'
  } finally {
    if (revision === requestRevision) loading.value = false
  }
}

function isSelected(row) {
  return selection.value.has(selectionKey(row))
}

function toggleRow(row) {
  const key = selectionKey(row)
  if (selection.value.has(key)) selection.value.delete(key)
  else selection.value.set(key, { row, manual: true, quantity: '' })
}

function needsBatchQuantities(row) {
  return props.quantityMode === 'count' && Number(row.no_expiry_management) !== 1 && row.count_batches?.length > 1
}

// 数量和选中状态按 SKU 保留，翻页或搜索不会清掉本次输入。
function setOperationQuantity(row, quantity) {
  const key = Number(row.sku_id)
  const manual = selection.value.get(key)?.manual ?? false
  if (quantity.trim() || manual) selection.value.set(key, { row, manual, quantity })
  else selection.value.delete(key)
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
  const products = []
  const quantities = new Map()
  for (const [key, { row, quantity }] of selection.value) {
    products.push(row)
    if (quantity.trim()) quantities.set(key, quantity.trim())
  }
  // 数量是本次草稿输入，单独传递，避免混入商品目录或其他调用方的数据。
  if (props.quantityMode) emit('confirm', products, quantities)
  else emit('confirm', products)
}

watch(() => props.visible, (visible) => {
  if (!visible) { requestRevision += 1; return }
  resetSelection()
  keyword.value = ''
  categoryId.value = 0
  page.value = 1
  loadCatalog()
}, { immediate: true })
</script>

<template>
  <Teleport to="body">
    <div v-if="visible" class="inventory-product-selector-layer" :style="{ zIndex: layerIndex }" role="presentation">
      <button type="button" class="inventory-product-selector-mask" aria-label="关闭商品选择" @click="close" />
      <section class="inventory-product-selector" role="dialog" aria-modal="true" aria-label="选择商品">
        <header>
          <div><p>商品目录</p><h2>选择商品</h2></div>
          <button type="button" class="selector-icon-button" title="关闭" @click="close"><X :size="20" /></button>
        </header>

        <div class="inventory-product-selector__filters">
          <label v-if="!productOnly || categories.length">商品分类
            <select v-model.number="categoryId" @change="changeCategory">
              <option :value="0">全部分类</option>
              <option v-for="category in categories" :key="category.id" :value="Number(category.id)">{{ category.name }}</option>
            </select>
          </label>
          <label class="selector-search">商品搜索
            <span><Search :size="16" /><input v-model="keyword" placeholder="商品名称、SKU、编码或条码" @keyup.enter="search" /></span>
          </label>
          <button type="button" class="selector-primary" :disabled="loading" @click="search">{{ loading ? '查询中' : '查询' }}</button>
        </div>

        <main>
          <p v-if="loadError" class="selector-error">{{ loadError }}</p>
          <div class="selector-table-scroll">
            <table>
              <!-- 库存与赠送共用选品列；库存数量直接展示服务端的当前仓 SKU 总余额。 -->
              <thead><tr><th>选择</th><th>商品名称</th><template v-if="!productOnly"><th>规格</th><th>库存单位</th><th>库存数量</th><th v-if="quantityMode">操作数量</th></template></tr></thead>
              <tbody>
                <tr v-if="loading"><td :colspan="columnCount" class="selector-empty">正在读取商品目录...</td></tr>
                <tr v-else-if="!rows.length"><td :colspan="columnCount" class="selector-empty">暂无符合条件的商品</td></tr>
                <tr v-else v-for="row in rows" :key="selectionKey(row)" :class="{ 'selector-row--selected': isSelected(row) }" @click="toggleRow(row)">
                  <td><input type="checkbox" :checked="isSelected(row)" @click.stop="toggleRow(row)" /></td>
                  <td><strong>{{ row.product_name }}</strong></td>
                  <template v-if="!productOnly"><td>{{ row.sku_name || '默认规格' }}</td><td>{{ row.stock_unit || '-' }}</td><td>{{ row.available_quantity ?? '-' }}</td></template>
                  <td v-if="!productOnly && quantityMode" @click.stop>
                    <span v-if="needsBatchQuantities(row)">在明细中按批次填写</span>
                    <input v-else class="selector-operation-quantity" type="text" inputmode="decimal"
                      :aria-label="`${row.product_name} ${row.sku_name || '默认规格'} 操作数量`"
                      :value="selection.get(Number(row.sku_id))?.quantity ?? ''"
                      @input="setOperationQuantity(row, $event.target.value)" />
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </main>

        <footer>
          <span v-if="productOnly">已选择 {{ selectedCount }} 件商品，共 {{ total }} 件商品</span>
          <span v-else>已选择 {{ selectedCount }} 个 SKU，共 {{ total }} 条商品规格</span>
          <div>
            <button type="button" class="selector-pagination" :disabled="page <= 1 || loading" title="上一页" @click="changePage(page - 1)"><ChevronLeft :size="17" /></button>
            <span class="selector-page">第 {{ page }} / {{ totalPages }} 页</span>
            <button type="button" class="selector-pagination" :disabled="page >= totalPages || loading" title="下一页" @click="changePage(page + 1)"><ChevronRight :size="17" /></button>
            <button type="button" class="selector-secondary" @click="close">取消</button>
            <button type="button" class="selector-primary" :disabled="loading || !!loadError || (!allowEmpty && selectedCount === 0)" @click="confirm">确认选择</button>
          </div>
        </footer>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.selector-operation-quantity { width: 96px; height: 32px; padding: 0 8px; border: 1px solid #d8e3ed; border-radius: 6px; background: #fff; color: #40566d; }
.inventory-product-selector-layer { position: fixed; z-index: 120; inset: 0; display: grid; place-items: center; padding: 28px; }.inventory-product-selector-mask { position: absolute; inset: 0; width: 100%; border: 0; background: rgba(17, 30, 46, .46); }.inventory-product-selector { position: relative; display: grid; grid-template-rows: auto auto minmax(0, 1fr) auto; width: min(1080px, calc(100vw - 56px)); max-height: calc(100vh - 56px); overflow: hidden; border: 1px solid #d8e3ed; border-radius: 8px; background: #f5f7fa; box-shadow: 0 24px 60px rgba(8, 25, 45, .28); }.inventory-product-selector > header, .inventory-product-selector > footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 15px 20px; border-bottom: 1px solid #e0e7ef; background: #fff; }.inventory-product-selector header p { margin: 0 0 4px; color: #8a97a5; font-size: 11px; }.inventory-product-selector header h2 { margin: 0; color: #253a50; font-size: 18px; }.selector-icon-button, .selector-pagination { display: inline-grid; width: 34px; height: 34px; place-items: center; padding: 0; border: 1px solid #dce5ee; border-radius: 6px; background: #fff; color: #47627d; }.selector-icon-button:hover, .selector-pagination:hover:not(:disabled) { border-color: #91caff; color: #176fd1; }.selector-pagination:disabled { cursor: default; opacity: .45; }.inventory-product-selector__filters { display: flex; align-items: end; gap: 12px; padding: 14px 20px; border-bottom: 1px solid #e0e7ef; background: #fbfdff; }.inventory-product-selector__filters label { display: grid; gap: 5px; color: #60748a; font-size: 12px; }.inventory-product-selector__filters select, .selector-search span { height: 34px; min-width: 170px; padding: 0 9px; border: 1px solid #d8e3ed; border-radius: 6px; background: #fff; color: #40566d; }.selector-search { min-width: 300px; flex: 1; }.selector-search span { display: flex; align-items: center; gap: 7px; min-width: 0; }.selector-search input { width: 100%; border: 0; outline: 0; background: transparent; color: #40566d; }.inventory-product-selector main { min-height: 280px; overflow: auto; }.selector-table-scroll { overflow: auto; }.selector-table-scroll table { width: 100%; min-width: 820px; border-collapse: collapse; }.selector-table-scroll th { height: 39px; padding: 0 14px; border-bottom: 1px solid #e7edf3; background: #f7f9fc; color: #60748a; font-size: 11px; font-weight: 600; text-align: left; white-space: nowrap; }.selector-table-scroll td { height: 51px; padding: 0 14px; border-bottom: 1px solid #edf1f5; color: #40566d; font-size: 12px; white-space: nowrap; }.selector-table-scroll tbody tr { cursor: pointer; }.selector-table-scroll tbody tr:hover, .selector-row--selected { background: #f1f8ff; }.selector-empty { height: 160px; color: #8798a9 !important; text-align: center; }.selector-error { margin: 12px 20px 0; color: #bf5050; font-size: 12px; }.inventory-product-selector > footer { border-top: 1px solid #e0e7ef; border-bottom: 0; color: #71849a; font-size: 12px; }.inventory-product-selector > footer > div { display: flex; align-items: center; gap: 8px; }.selector-page { min-width: 74px; color: #60748a; text-align: center; }.selector-primary, .selector-secondary { min-height: 34px; padding: 0 13px; border-radius: 6px; font-size: 12px; }.selector-primary { border: 1px solid #176fd1; background: #176fd1; color: #fff; }.selector-primary:disabled { cursor: default; opacity: .55; }.selector-secondary { border: 1px solid #d5e1ee; background: #fff; color: #47627d; }
@media (max-width: 760px) { .inventory-product-selector-layer { padding: 12px; }.inventory-product-selector { width: 100%; max-height: calc(100vh - 24px); }.inventory-product-selector__filters { flex-wrap: wrap; }.selector-search { min-width: 100%; }.inventory-product-selector > footer { align-items: flex-start; flex-direction: column; }.inventory-product-selector > footer > div { flex-wrap: wrap; } }
</style>

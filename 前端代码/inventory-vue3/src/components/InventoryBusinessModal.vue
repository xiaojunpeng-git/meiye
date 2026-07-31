<script setup>
import { computed, ref } from 'vue'
import { AlertTriangle, Barcode, CalendarDays, FileSpreadsheet, Plus, QrCode, Search, Trash2, X } from '@lucide/vue'
import { inventoryApi, platformInventoryApi } from '../services/inventoryApi'

const props = defineProps({
  visible: { type: Boolean, default: false },
  pageKey: { type: String, default: '' },
  modalKind: { type: String, default: '' },
  scopeName: { type: String, default: '' },
  defaultLocationName: { type: String, default: '' },
  warehouseOptions: { type: Array, default: () => [] },
  mode: { type: String, default: 'store' }
})

const emit = defineEmits(['close', 'saved'])

const inboundType = ref('采购入库')
const outboundType = ref('过期退货')
const inboundTypes = ['初始入库', '采购入库', '退货入库', '其他入库']
const outboundTypes = ['过期退货', '试用出库', '报废出库', '其他出库']
const catalogKeyword = ref('')
const catalogRows = ref([])
const selectedRows = ref([])
const catalogLoading = ref(false)
const catalogError = ref('')
const catalogVisible = ref(false)
const inboundDate = ref(new Date().toISOString().slice(0, 10))
const inboundRemark = ref('')
const outboundDate = ref(new Date().toISOString().slice(0, 10))
const outboundRemark = ref('')
const countRemark = ref('')
const requestDate = ref(new Date().toISOString().slice(0, 10))
const requestRemark = ref('')
const transferDate = ref(new Date().toISOString().slice(0, 10))
const transferRemark = ref('')
const transferLocations = ref([])
const targetLocationId = ref(0)
const usageDate = ref(new Date().toISOString().slice(0, 10))
const usageProjectId = ref('')
const usageProjectName = ref('')
const usageRemark = ref('')
const warehouseStoreId = ref(0)
const warehouseName = ref('')
const submitting = ref(false)
const submitError = ref('')

const isDetail = computed(() => props.modalKind.endsWith('-detail'))
const isImport = computed(() => props.modalKind === 'import')
const warehouseStoreOptions = computed(() => {
  const seen = new Set()
  return props.warehouseOptions.filter((location) => {
    const storeId = Number(location?.store_id || 0)
    if (storeId <= 0 || seen.has(storeId)) return false
    seen.add(storeId)
    return true
  })
})
const title = computed(() => {
  if (isImport.value) return '库存导入'
  if (isDetail.value) return `${pageLabel.value}详情`
  return {
    inbound: '添加入库单', outbound: '添加出库单', count: '添加盘点单',
    request: '新建请货单', transfer: '新建自由调拨', usage: '院装耗材领用', warehouse: '新建仓库', recipe: '新建配方'
  }[props.pageKey] || '库存业务详情'
})
const pageLabel = computed(() => ({ inbound: '入库单', outbound: '出库单', count: '盘点单', request: '请货单', transfer: '调拨单', warehouse: '仓库', recipe: '项目配方', stock: '库存明细', usage: '院装管理', import: '导入记录' }[props.pageKey] || '库存业务'))
const selectedType = computed(() => props.pageKey === 'inbound' ? inboundType.value : outboundType.value)

const productRows = computed(() => {
  if (['inbound', 'outbound'].includes(props.pageKey)) {
    return selectedRows.value.map((row) => props.pageKey === 'inbound'
      ? [row.product_id, row.product_name, row.sku_name, row.barcode, row.batch_no, row.manufactured_date, row.expire_date, row.quantity, row.unit_cost, '']
      : [row.product_id, row.product_name, row.sku_name, row.barcode, '服务端按 FEFO 扣减', '提交后返回', row.quantity, '服务端计算', '服务端计算', ''])
  }
  if (props.pageKey === 'count') return selectedRows.value.map((row) => [row.product_id, row.product_name, row.sku_name, row.barcode, row.book_quantity, row.counted_quantity, Number(row.counted_quantity || 0) - Number(row.book_quantity || 0), row.surplus_batch_no, row.surplus_unit_cost, row.surplus_manufactured_date, row.surplus_expire_date, ''])
  if (props.pageKey === 'request') return selectedRows.value.map((row) => [row.product_id, row.product_name, row.sku_name, row.barcode, row.available_quantity || '-', row.quantity, row.reference_unit_cost || '-', '由服务端按仓库成本快照计算'])
  if (props.pageKey === 'transfer') return selectedRows.value.map((row) => [row.product_id, row.product_name, row.sku_name, row.barcode, '服务端按 FEFO 扣减', '提交后返回', '服务端校验', row.quantity, '服务端计算', '服务端计算', ''])
  if (props.pageKey === 'usage') return selectedRows.value.map((row) => [row.product_id, row.product_name, row.sku_name, row.barcode, row.quantity, '服务端按 FEFO 计算', ''])
  return []
})

const columns = computed(() => {
  const product = ['商品ID', '商品名称', '商品规格', '商品条码']
  const batch = ['批次号', '生产日期', '到期日']
  if (props.pageKey === 'count') return [...product, '账面库存', '实盘库存', '库存盈亏', '盘盈批次号', '盘盈单价', '生产日期', '到期日', '操作']
  if (props.pageKey === 'request') return [...product, '当前库存', '申请数量', '参考单价', '预计金额', '操作']
  if (props.pageKey === 'transfer') return [...product, '扣减批次', '到期日', '调出可用库存', '调拨数量', '实际单位成本', '调拨金额', '操作']
  if (props.pageKey === 'usage') return [...product, '领用数量', '实际单位成本', '操作']
  if (props.pageKey === 'outbound') return [...product, '扣减批次', '到期日', '出库数量', '实际单位成本', '成本金额', '操作']
  return [...product, ...batch, '入库数量', '入库单价', '入库金额', '操作']
})

function close() { emit('close') }

async function searchCatalog() {
  if (!['inbound', 'outbound', 'count', 'request', 'transfer', 'usage'].includes(props.pageKey)) return
  const keyword = catalogKeyword.value.trim()
  catalogVisible.value = true
  catalogLoading.value = true
  catalogError.value = ''
  try {
    const response = await inventoryApi.searchCatalog({ keyword })
    catalogRows.value = Array.isArray(response?.list) ? response.list : []
  } catch (error) {
    catalogRows.value = []
    catalogError.value = error instanceof Error ? error.message : '商品搜索失败。'
  } finally {
    catalogLoading.value = false
  }
}

async function openCatalogPicker() {
  catalogVisible.value = true
  await searchCatalog()
}

function inventoryErrorMessage(error, fallback) {
  const message = error instanceof Error ? error.message : ''
  if (message === 'inventory_manual_inbound_dates_required') return '请为每个入库商品填写生产日期和到期日。'
  if (message === 'inventory_manual_inbound_sku_not_found') return '所选商品规格已失效，请重新选择商品。'
  if (message === 'inventory_manual_inbound_operator_scope_denied') return '当前员工无权操作本门店库存。'
  return message || fallback
}

async function addCatalogRow(row) {
  if (selectedRows.value.some((item) => item.sku_id === row.sku_id)) return
  const selected = { ...row, batch_no: '', manufactured_date: '', expire_date: '', quantity: '1', unit_cost: '0.00', book_quantity: '0', counted_quantity: '0', surplus_batch_no: '', surplus_unit_cost: '', surplus_manufactured_date: '', surplus_expire_date: '', available_quantity: '-', reference_unit_cost: '-' }
  if (props.pageKey === 'count') {
    try { const stock = await inventoryApi.list('stock', { keyword: row.sku_unique }); const list = Array.isArray(stock?.list) ? stock.list : []; selected.book_quantity = String(list.filter((item) => Number(item.sku_id) === Number(row.sku_id)).reduce((sum, item) => sum + Number(item.batch_balance_quantity || 0), 0)); selected.counted_quantity = selected.book_quantity } catch (error) { catalogError.value = error instanceof Error ? error.message : '账面库存读取失败。'; return }
  }
  selectedRows.value.push(selected)
  catalogRows.value = []
  catalogKeyword.value = ''
}

async function loadTransferLocations() {
  try {
    const response = await inventoryApi.list('storeLocations')
    transferLocations.value = (Array.isArray(response?.list) ? response.list : []).filter((item) => Number(item.is_default) !== 1)
    if (!transferLocations.value.some((item) => Number(item.id) === Number(targetLocationId.value))) targetLocationId.value = Number(transferLocations.value[0]?.id || 0)
  } catch (error) { catalogError.value = error instanceof Error ? error.message : '调拨仓库读取失败。' }
}

function countIdempotencyKey() { return typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function' ? `count-${crypto.randomUUID()}` : `count-${Date.now()}-${Math.random().toString(36).slice(2)}` }
async function submitCount() { if (props.pageKey !== 'count' || !selectedRows.value.length) return; submitting.value = true; submitError.value = ''; try { await inventoryApi.confirmCount({ idempotency_key: countIdempotencyKey(), business_date: inboundDate.value, remark: countRemark.value, lines: selectedRows.value.map((row) => ({ product_id: Number(row.product_id), sku_id: Number(row.sku_id), sku_unique: row.sku_unique, counted_quantity: row.counted_quantity, surplus_batch_no: row.surplus_batch_no, surplus_unit_cost: row.surplus_unit_cost, surplus_manufactured_date: row.surplus_manufactured_date, surplus_expire_date: row.surplus_expire_date })) }); emit('saved'); close() } catch (error) { submitError.value = error instanceof Error ? error.message : '盘点提交失败。' } finally { submitting.value = false } }

function removeCatalogRow(rowIndex) {
  selectedRows.value.splice(rowIndex, 1)
}

function inboundIdempotencyKey() {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') return `inbound-${crypto.randomUUID()}`
  return `inbound-${Date.now()}-${Math.random().toString(36).slice(2)}`
}

function outboundIdempotencyKey() {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') return `outbound-${crypto.randomUUID()}`
  return `outbound-${Date.now()}-${Math.random().toString(36).slice(2)}`
}

async function submitInbound() {
  if (props.pageKey !== 'inbound' || !selectedRows.value.length) return
  submitting.value = true
  submitError.value = ''
  try {
    await inventoryApi.createInbound({
      idempotency_key: inboundIdempotencyKey(), business_date: inboundDate.value, remark: inboundRemark.value,
      lines: selectedRows.value.map((row) => ({
        product_id: Number(row.product_id), sku_id: Number(row.sku_id), sku_unique: row.sku_unique,
        batch_no: row.batch_no, quantity: row.quantity, unit_cost: row.unit_cost,
        manufactured_date: row.manufactured_date, expire_date: row.expire_date
      }))
    })
    emit('saved')
    close()
  } catch (error) {
    submitError.value = inventoryErrorMessage(error, '入库提交失败。')
  } finally {
    submitting.value = false
  }
}

async function submitOutbound() {
  if (props.pageKey !== 'outbound' || !selectedRows.value.length) return
  submitting.value = true
  submitError.value = ''
  try {
    await inventoryApi.createOutbound({
      idempotency_key: outboundIdempotencyKey(), business_date: outboundDate.value, remark: outboundRemark.value,
      lines: selectedRows.value.map((row) => ({
        product_id: Number(row.product_id), sku_id: Number(row.sku_id), sku_unique: row.sku_unique, quantity: row.quantity
      }))
    })
    emit('saved')
    close()
  } catch (error) {
    submitError.value = error instanceof Error ? error.message : '出库提交失败。'
  } finally {
    submitting.value = false
  }
}

function requestIdempotencyKey() {
  return typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function' ? `request-${crypto.randomUUID()}` : `request-${Date.now()}-${Math.random().toString(36).slice(2)}`
}

async function submitRequest() {
  if (props.pageKey !== 'request' || !selectedRows.value.length) return
  submitting.value = true
  submitError.value = ''
  try {
    await inventoryApi.applyRequest({
      idempotency_key: requestIdempotencyKey(), business_date: requestDate.value, remark: requestRemark.value,
      lines: selectedRows.value.map((row) => ({ product_id: Number(row.product_id), sku_id: Number(row.sku_id), sku_unique: row.sku_unique, quantity: row.quantity }))
    })
    emit('saved')
    close()
  } catch (error) {
    submitError.value = error instanceof Error ? error.message : '请货申请失败。'
  } finally {
    submitting.value = false
  }
}

function transferIdempotencyKey() { return typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function' ? `transfer-${crypto.randomUUID()}` : `transfer-${Date.now()}-${Math.random().toString(36).slice(2)}` }
async function submitTransfer() {
  if (props.pageKey !== 'transfer' || !selectedRows.value.length || Number(targetLocationId.value) <= 0) return
  submitting.value = true; submitError.value = ''
  try {
    await inventoryApi.createTransfer({ idempotency_key: transferIdempotencyKey(), business_date: transferDate.value, remark: transferRemark.value, target_location_id: Number(targetLocationId.value), lines: selectedRows.value.map((row) => ({ product_id: Number(row.product_id), sku_id: Number(row.sku_id), sku_unique: row.sku_unique, quantity: row.quantity })) })
    emit('saved'); close()
  } catch (error) { submitError.value = error instanceof Error ? error.message : '调拨提交失败。' } finally { submitting.value = false }
}

function usageIdempotencyKey() { return typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function' ? `usage-${crypto.randomUUID()}` : `usage-${Date.now()}-${Math.random().toString(36).slice(2)}` }
async function submitUsage() {
  if (props.pageKey !== 'usage' || !selectedRows.value.length || Number(usageProjectId.value) <= 0 || !usageProjectName.value.trim()) return
  submitting.value = true; submitError.value = ''
  try {
    await inventoryApi.issueSalonUsage({ idempotency_key: usageIdempotencyKey(), business_date: usageDate.value, project_id: Number(usageProjectId.value), project_name: usageProjectName.value.trim(), remark: usageRemark.value, lines: selectedRows.value.map((row) => ({ product_id: Number(row.product_id), sku_id: Number(row.sku_id), sku_unique: row.sku_unique, quantity: row.quantity })) })
    emit('saved'); close()
  } catch (error) { submitError.value = error instanceof Error ? error.message : '院装领用提交失败。' } finally { submitting.value = false }
}

function warehouseIdempotencyKey() {
  return typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
    ? `warehouse-${crypto.randomUUID()}`
    : `warehouse-${Date.now()}-${Math.random().toString(36).slice(2)}`
}

async function submitWarehouse() {
  if (props.pageKey !== 'warehouse' || props.mode !== 'platform' || Number(warehouseStoreId.value) <= 0 || !warehouseName.value.trim()) return
  submitting.value = true
  submitError.value = ''
  try {
    await platformInventoryApi.createWarehouse({
      idempotency_key: warehouseIdempotencyKey(),
      store_id: Number(warehouseStoreId.value),
      location_name: warehouseName.value.trim()
    })
    emit('saved')
    close()
  } catch (error) {
    submitError.value = error instanceof Error ? error.message : '仓库创建失败。'
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div v-if="visible" class="inventory-modal-layer" role="presentation">
      <button class="inventory-modal-mask" aria-label="关闭模态弹窗" @click="close" />
      <section class="inventory-modal" role="dialog" aria-modal="true" :aria-label="title">
        <header class="inventory-modal__header">
          <div><p>{{ mode === 'platform' ? '平台端' : '门店端' }} · {{ scopeName }}</p><h2>{{ title }}</h2></div>
          <button class="modal-icon-button" title="关闭" @click="close"><X :size="20" /></button>
        </header>

        <main class="inventory-modal__body">
          <template v-if="isImport">
            <div class="import-modal"><FileSpreadsheet /><div><strong>按旧流程导入库存数据</strong><p>选择初始入库、普通入库或出库模板；批次、单价与金额按模板规则校验。</p></div><button class="modal-secondary">下载模板</button></div>
          </template>

          <template v-else-if="isDetail">
            <div class="detail-meta"><span>此列表页尚未提供详情读取接口。</span><span>为避免展示示例数量、金额或状态，此处不生成模拟详情。</span></div>
          </template>

          <template v-else>
            <section v-if="pageKey === 'inbound' || pageKey === 'outbound'" class="form-section form-section--single">
              <label class="form-label">{{ pageKey === 'inbound' ? '入库类型' : '出库类型' }}<i>*</i></label>
              <div class="type-radio-group">
                <button v-for="item in pageKey === 'inbound' ? inboundTypes : outboundTypes" :key="item" :class="{ active: selectedType === item }" @click="pageKey === 'inbound' ? inboundType = item : outboundType = item">{{ item }}</button>
              </div>
              <p class="type-tip">{{ pageKey === 'inbound' ? '调拨入库由调拨确认自动生成，不在此处手工创建。' : '调拨出库由调拨确认自动生成；出库按所选仓库的实际批次扣减。' }}</p>
            </section>

            <section v-if="pageKey === 'inbound' || pageKey === 'outbound'" class="form-grid">
              <label> {{ pageKey === 'inbound' ? '入库日期' : '出库日期' }}<i>*</i><span class="input-like"><CalendarDays :size="16" /><input v-if="pageKey === 'inbound'" v-model="inboundDate" type="date" /><input v-else v-model="outboundDate" type="date" /></span></label>
              <label>备注<input v-if="pageKey === 'inbound'" v-model="inboundRemark" placeholder="请输入备注" /><input v-else v-model="outboundRemark" placeholder="请输入备注" /></label>
              <label v-if="pageKey === 'inbound' && inboundType === '退货入库'" class="form-grid__full">售后单号<span class="input-like input-like--action">+ 选择售后单据</span></label>
            </section>

            <section v-else-if="pageKey === 'count'" class="form-grid form-grid--single"><label>备注<input v-model="countRemark" placeholder="请输入备注" /></label></section>

            <section v-else-if="pageKey === 'request'" class="form-grid">
              <label>请货门店<input :value="scopeName" disabled /></label><label>供货方<input value="由后续调拨单选择供货仓" disabled /></label>
              <label>请货日期<i>*</i><span class="input-like"><CalendarDays :size="16" /><input v-model="requestDate" type="date" /></span></label><label>请货人<input value="当前登录员工" disabled /></label>
              <label class="form-grid__full">备注<input v-model="requestRemark" placeholder="请输入备注" /></label>
            </section>

            <section v-else-if="pageKey === 'transfer'" class="form-grid">
              <label>调出仓<i>*</i><input :value="defaultLocationName || '当前门店默认仓'" disabled /></label><label>调入仓<i>*</i><select v-model.number="targetLocationId" @focus="loadTransferLocations"><option :value="0" disabled>请选择可调入仓</option><option v-for="location in transferLocations" :key="location.id" :value="Number(location.id)">{{ location.location_name }}</option></select></label>
              <label>调拨日期<i>*</i><span class="input-like"><CalendarDays :size="16" /><input v-model="transferDate" type="date" /></span></label><label>调拨人<input value="当前登录员工" disabled /></label>
              <label class="form-grid__full">备注<input v-model="transferRemark" placeholder="请输入备注" /></label>
            </section>

            <section v-else-if="pageKey === 'warehouse'" class="form-grid">
              <label>所属门店<i>*</i><select v-model.number="warehouseStoreId"><option :value="0" disabled>请选择已有默认仓的门店</option><option v-for="location in warehouseStoreOptions" :key="location.store_id" :value="Number(location.store_id)">{{ location.store_name_snapshot || location.location_name }}</option></select></label>
              <label>仓库名称<i>*</i><input v-model="warehouseName" maxlength="100" placeholder="例如：调拨测试仓" /></label>
              <p class="form-grid__full type-tip">创建的是非默认仓库，不改变现有库存、批次或成本；调拨时只能在同门店仓库之间进行。</p>
            </section>

            <section v-else-if="pageKey === 'usage'" class="form-grid">
              <label>关联项目 ID<i>*</i><input v-model="usageProjectId" inputmode="numeric" placeholder="输入本次核销项目 ID" /></label><label>关联项目名称<i>*</i><input v-model="usageProjectName" placeholder="输入本次核销项目名称" /></label>
              <label>领用日期<i>*</i><span class="input-like"><CalendarDays :size="16" /><input v-model="usageDate" type="date" /></span></label><label>领用人<input value="当前登录员工" disabled /></label>
              <label class="form-grid__full">备注<input v-model="usageRemark" placeholder="请输入备注" /></label>
            </section>

          </template>

          <section v-if="!isImport && pageKey !== 'warehouse'" class="line-section">
            <header>
              <div><h3>{{ pageKey === 'count' ? '盘点商品' : pageKey === 'request' ? '请货商品' : pageKey === 'transfer' ? '调拨商品' : pageKey === 'inbound' ? '入库商品' : pageKey === 'outbound' ? '出库商品' : '业务明细' }}<i v-if="!isDetail">*</i></h3><p v-if="pageKey === 'inbound'">商品条码可扫码；批次、生产日期、到期日和入库单价在商品明细中填写。</p><p v-else-if="pageKey === 'outbound'">按实际批次显示单位成本与金额；成本不允许手工改写。</p></div>
              <div v-if="!isDetail" class="line-actions"><button class="modal-secondary" @click="pageKey === 'transfer' ? loadTransferLocations() : openCatalogPicker()"><Search :size="16" />选择商品</button><button class="modal-secondary" @click="openCatalogPicker"><QrCode :size="16" />扫码添加</button><button class="modal-danger"><Trash2 :size="15" />批量删除</button></div>
            </header>
            <div v-if="catalogVisible && ['inbound', 'outbound', 'count', 'request', 'transfer', 'usage'].includes(pageKey)" class="line-search"><Search :size="16" /><input v-model="catalogKeyword" placeholder="输入商品名称、SKU 或商品条码；留空可展示当前门店库存商品" @keyup.enter="searchCatalog" /><button class="modal-primary" :disabled="catalogLoading" @click="searchCatalog">{{ catalogLoading ? '查询中' : '查询' }}</button></div>
            <p v-if="catalogError" class="catalog-error">{{ catalogError }}</p>
            <div v-if="catalogVisible && catalogRows.length" class="catalog-results"><button v-for="row in catalogRows" :key="row.sku_id" @click="addCatalogRow(row)"><span><strong>{{ row.product_name }}</strong><small>{{ row.sku_name || '默认规格' }} · {{ row.barcode || '无条码' }}</small></span><Plus :size="16" /></button></div>
            <div class="modal-table-scroll"><table><thead><tr><th v-for="column in columns" :key="column">{{ column }}</th></tr></thead><tbody><tr v-if="!productRows.length && ['inbound', 'outbound', 'count', 'request', 'transfer', 'usage'].includes(pageKey)"><td :colspan="columns.length" class="modal-empty">请扫描商品条码或搜索并选择商品</td></tr><tr v-for="(row, rowIndex) in productRows" :key="rowIndex"><td v-for="(cell, cellIndex) in row" :key="cellIndex"><template v-if="pageKey === 'inbound' && cellIndex === 4"><input v-model="selectedRows[rowIndex].batch_no" class="table-input" /></template><template v-else-if="pageKey === 'inbound' && cellIndex === 5"><input v-model="selectedRows[rowIndex].manufactured_date" class="table-input" type="date" /></template><template v-else-if="pageKey === 'inbound' && cellIndex === 6"><input v-model="selectedRows[rowIndex].expire_date" class="table-input" type="date" /></template><template v-else-if="pageKey === 'inbound' && cellIndex === 7"><input v-model="selectedRows[rowIndex].quantity" class="table-input" /></template><template v-else-if="pageKey === 'inbound' && cellIndex === 8"><input v-model="selectedRows[rowIndex].unit_cost" class="table-input" /></template><template v-else-if="(pageKey === 'outbound' && cellIndex === 6) || (pageKey === 'request' && cellIndex === 5) || (pageKey === 'transfer' && cellIndex === 7) || (pageKey === 'usage' && cellIndex === 4)"><input v-model="selectedRows[rowIndex].quantity" class="table-input" /></template><template v-else-if="pageKey === 'count' && cellIndex === 5"><input v-model="selectedRows[rowIndex].counted_quantity" class="table-input" /></template><template v-else-if="pageKey === 'count' && cellIndex === 7"><input v-model="selectedRows[rowIndex].surplus_batch_no" class="table-input" placeholder="盘盈必填" /></template><template v-else-if="pageKey === 'count' && cellIndex === 8"><input v-model="selectedRows[rowIndex].surplus_unit_cost" class="table-input" placeholder="盘盈必填" /></template><template v-else-if="pageKey === 'count' && cellIndex === 9"><input v-model="selectedRows[rowIndex].surplus_manufactured_date" class="table-input" type="date" /></template><template v-else-if="pageKey === 'count' && cellIndex === 10"><input v-model="selectedRows[rowIndex].surplus_expire_date" class="table-input" type="date" /></template><template v-else>{{ cell }}</template></td><td><button class="modal-link" @click="['inbound', 'outbound', 'count', 'request', 'transfer', 'usage'].includes(pageKey) ? removeCatalogRow(rowIndex) : null">删除</button></td></tr></tbody></table></div>
            <footer v-if="['inbound', 'outbound', 'request', 'transfer'].includes(pageKey)" class="line-total"><span>明细数量：{{ selectedRows.length }} 项</span><strong>{{ pageKey === 'inbound' ? '入库金额按填写单价计算' : pageKey === 'outbound' ? '出库成本由服务端按实际批次计算' : pageKey === 'request' ? '预计金额由服务端按仓库成本快照计算' : '调拨金额由服务端按实际批次成本计算' }}</strong></footer>
          </section>

          <aside v-if="!isDetail && ['request', 'transfer'].includes(pageKey)" class="modal-notice"><AlertTriangle :size="17" /><div><strong>操作注意事项</strong><p>{{ pageKey === 'request' ? '确认申请不会变动库存；确认调拨后才会变动双方库存。' : '确认调拨才会改变双方库存；保存草稿不会变动库存。' }}</p></div></aside>
          <p v-if="submitError" class="catalog-error">{{ submitError }}</p>
        </main>

        <footer class="inventory-modal__footer">
          <button class="modal-secondary" @click="close">取消</button>
          <template v-if="!isDetail && !isImport"><button v-if="['count'].includes(pageKey)" class="modal-secondary">保存草稿</button><button class="modal-primary" :disabled="submitting || (['inbound', 'outbound', 'count', 'request', 'transfer', 'usage'].includes(pageKey) && !selectedRows.length) || (pageKey === 'warehouse' && (Number(warehouseStoreId) <= 0 || !warehouseName.trim()))" @click="pageKey === 'inbound' ? submitInbound() : pageKey === 'outbound' ? submitOutbound() : pageKey === 'count' ? submitCount() : pageKey === 'request' ? submitRequest() : pageKey === 'transfer' ? submitTransfer() : pageKey === 'usage' ? submitUsage() : pageKey === 'warehouse' ? submitWarehouse() : null">{{ (['inbound', 'outbound', 'count', 'request', 'transfer', 'usage', 'warehouse'].includes(pageKey) && submitting) ? '提交中' : pageKey === 'count' ? '完成盘点' : pageKey === 'request' ? '确认申请' : pageKey === 'transfer' ? '确认调拨' : pageKey === 'usage' ? '确认领用' : pageKey === 'warehouse' ? '创建仓库' : '保存' }}</button></template>
          <button v-else-if="isImport" class="modal-primary">选择导入文件</button>
          <button v-else class="modal-primary" @click="close">关闭</button>
        </footer>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.inventory-modal-layer { position: fixed; z-index: 100; inset: 0; display: grid; place-items: center; padding: 28px; }
.inventory-modal-mask { position: absolute; inset: 0; width: 100%; border: 0; background: rgba(17, 30, 46, .46); }
.inventory-modal { position: relative; display: grid; grid-template-rows: auto minmax(0, 1fr) auto; width: min(1240px, calc(100vw - 56px)); max-height: calc(100vh - 56px); overflow: hidden; border: 1px solid #d8e3ed; border-radius: 14px; background: #f5f7fa; box-shadow: 0 24px 60px rgba(8, 25, 45, .28); }
.inventory-modal__header, .inventory-modal__footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 15px 20px; border-bottom: 1px solid #e0e7ef; background: #fff; }.inventory-modal__header p { margin: 0 0 4px; color: #8a97a5; font-size: 11px; }.inventory-modal__header h2 { margin: 0; color: #253a50; font-size: 18px; }.inventory-modal__footer { justify-content: flex-end; border-top: 1px solid #e0e7ef; border-bottom: 0; }.modal-icon-button { display: inline-grid; width: 34px; height: 34px; place-items: center; padding: 0; border: 1px solid #dce5ee; border-radius: 8px; background: #fff; color: #47627d; }.modal-icon-button:hover { border-color: #91caff; color: #176fd1; }
.inventory-modal__body { overflow: auto; padding: 18px 20px 22px; }.form-section { margin-bottom: 15px; padding: 14px; border: 1px solid #e0e7ef; border-radius: 10px; background: #fff; }.form-label { display: block; margin-bottom: 10px; color: #5c7187; font-size: 12px; }.form-section i, .form-grid i, .line-section h3 i { margin-left: 3px; color: #e05252; font-style: normal; }.type-radio-group { display: flex; flex-wrap: wrap; gap: 8px; }.type-radio-group button { min-height: 31px; padding: 0 12px; border: 1px solid #dbe5ee; border-radius: 7px; background: #fff; color: #5b7086; font-size: 12px; }.type-radio-group button.active { border-color: #176fd1; background: #eaf3ff; color: #176fd1; font-weight: 700; }.type-tip { margin: 9px 0 0; color: #8491a0; font-size: 11px; }.form-grid { display: grid; grid-template-columns: 1fr 1fr; align-items: start; gap: 13px 18px; margin-bottom: 15px; padding: 15px; border: 1px solid #e0e7ef; border-radius: 10px; background: #fff; }.form-grid--single { grid-template-columns: 1fr; }.form-grid label { display: grid; align-content: start; gap: 6px; color: #60748a; font-size: 12px; }.form-grid input, .form-grid select, .input-like { width: 100%; height: 34px; padding: 0 9px; border: 1px solid #d8e3ed; border-radius: 8px; outline: 0; background: #fff; color: #40566d; }.input-like { display: inline-flex; align-items: center; gap: 6px; }.input-like--action { border-color: #b8d8f7; background: #f6fbff; color: #176fd1; }.form-grid__full { grid-column: 1 / -1; }
.line-section { overflow: hidden; border: 1px solid #dfe8f0; border-radius: 10px; background: #fff; }.line-section > header { display: flex; align-items: start; justify-content: space-between; gap: 12px; padding: 15px; border-bottom: 1px solid #e8eef4; }.line-section h3 { margin: 0; color: #30465d; font-size: 14px; }.line-section p { margin: 4px 0 0; color: #8795a5; font-size: 11px; }.line-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 7px; }.modal-primary, .modal-secondary, .modal-danger { display: inline-flex; align-items: center; justify-content: center; gap: 5px; min-height: 33px; padding: 0 12px; border-radius: 8px; font-size: 12px; }.modal-primary { border: 1px solid #176fd1; background: #176fd1; color: #fff; }.modal-secondary { border: 1px solid #d5e1ee; background: #fff; color: #47627d; }.modal-danger { border: 1px solid #f0d1d1; background: #fff8f8; color: #ca4c4c; }.line-search { display: flex; align-items: center; gap: 8px; margin: 14px; padding: 0 9px; border: 1px solid #dbe5ee; border-radius: 8px; color: #7f8fa0; background: #fbfdff; }.line-search input { width: 240px; height: 34px; border: 0; outline: 0; background: transparent; }.modal-table-scroll { overflow: auto; }.modal-table-scroll table { width: 100%; min-width: 1020px; border-collapse: collapse; }.modal-table-scroll th { height: 39px; padding: 0 11px; border-bottom: 1px solid #e7edf3; background: #f7f9fc; color: #60748a; font-size: 11px; font-weight: 600; text-align: left; white-space: nowrap; }.modal-table-scroll td { height: 51px; padding: 0 11px; border-bottom: 1px solid #edf1f5; color: #40566d; font-size: 12px; white-space: nowrap; }.table-input { width: 76px; height: 29px; padding: 0 6px; border: 1px solid #bcd6ed; border-radius: 6px; color: #344d68; font-size: 12px; }.modal-link { border: 0; background: transparent; color: #d05252; font-size: 12px; }.line-total { display: flex; justify-content: space-between; padding: 12px 15px; background: #fbfdff; color: #7d8c9c; font-size: 12px; }.line-total strong { color: #1f4e80; }.modal-notice { display: flex; gap: 8px; margin-top: 14px; padding: 12px 14px; border: 1px solid #f0ddae; border-radius: 9px; background: #fffbef; color: #a86d12; }.modal-notice div { display: grid; gap: 4px; }.modal-notice strong { font-size: 12px; }.modal-notice p { margin: 0; color: #857040; font-size: 11px; }.detail-meta { display: flex; flex-wrap: wrap; gap: 10px 20px; margin-bottom: 14px; padding: 12px 14px; border: 1px solid #dfe8f0; border-radius: 9px; background: #fff; color: #61758b; font-size: 12px; }.detail-meta b { color: #26835f; }.detail-summary { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 15px; }.detail-summary article { padding: 14px; border: 1px solid #e0e7ef; border-radius: 10px; background: #fff; }.detail-summary span { display: block; color: #8291a2; font-size: 11px; }.detail-summary strong { display: block; margin-top: 7px; color: #284868; font-size: 17px; }.import-modal { display: grid; grid-template-columns: 34px minmax(0, 1fr) auto; align-items: center; gap: 12px; padding: 18px; border: 1px solid #cfe1f5; border-radius: 10px; background: #f2f8ff; color: #176fd1; }.import-modal div { display: grid; gap: 4px; }.import-modal strong { color: #304d69; font-size: 14px; }.import-modal p { margin: 0; color: #71849a; font-size: 12px; }
.catalog-results { display: grid; gap: 6px; margin: 0 14px 12px; }.catalog-results button { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 9px 11px; border: 1px solid #d8e7f5; border-radius: 7px; background: #f9fcff; color: #2165a5; text-align: left; }.catalog-results button:hover { border-color: #79afe1; background: #f0f8ff; }.catalog-results span { display: grid; gap: 3px; }.catalog-results strong { color: #40566d; font-size: 12px; }.catalog-results small { color: #8293a4; font-size: 11px; }.catalog-error { margin: 0 14px 10px !important; color: #bf5050 !important; }.modal-empty { height: 95px !important; color: #8798a9 !important; text-align: center; }
@media (max-width: 760px) { .inventory-modal-layer { padding: 12px; }.inventory-modal { width: 100%; max-height: calc(100vh - 24px); border-radius: 10px; }.form-grid { grid-template-columns: 1fr; }.form-grid__full { grid-column: auto; }.line-section > header { flex-direction: column; }.line-actions { justify-content: flex-start; }.detail-summary { grid-template-columns: 1fr; }.import-modal { grid-template-columns: 28px 1fr; }.import-modal button { grid-column: 1 / -1; } }
</style>

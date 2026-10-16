<script setup>
import { computed, ref, watch } from 'vue'
import { FileSpreadsheet, Plus, Search, Trash2, X } from '@lucide/vue'
import { inventoryApi, platformInventoryApi } from '../services/inventoryApi'
import InventoryProductSelector from './InventoryProductSelector.vue'
import InventoryStoreSelector from './InventoryStoreSelector.vue'
import { exportTransferTemplateXlsx, parseTransferXlsx } from './transferWorksheet'
import { planTransferDraftBatch } from './transferDraftBatch'

const props = defineProps({
  mode: { type: String, default: 'store' },
  modalKind: { type: String, default: 'transfer' },
  scopeName: { type: String, default: '' },
  warehouseOptions: { type: Array, default: () => [] },
  hqLocationId: { type: Number, default: 0 },
  defaultBusinessDate: { type: String, default: '' }
})
const emit = defineEmits(['close', 'saved'])
const platform = computed(() => props.mode === 'platform')
const importMode = computed(() => props.modalKind === 'transfer-import')
const businessDate = ref(props.defaultBusinessDate)
const remark = ref('')
const staffId = ref(0)
const staffOptions = ref([])
const rows = ref([])
const targetLists = ref({})
const targetLoading = ref({})
const productRowId = ref(0)
const destinationRowId = ref(0)
const destinationSearch = ref('')
const fileInput = ref(null)
const busy = ref(false)
const error = ref('')
const fileName = ref('')
const attempt = ref({ content: '', key: '' })
let nextRowId = 1
const catalogApi = computed(() => platform.value ? platformInventoryApi : inventoryApi)
const defaultSourceKey = computed(() => platform.value && Number(props.hqLocationId) > 0 ? `HQ:${Number(props.hqLocationId)}` : '')
const sourceOptions = computed(() => {
  if (!platform.value) return []
  const hq = props.warehouseOptions.filter((item) => item.location_type === 'HQ' && item.location_status !== 'INACTIVE' && Number(item.id) > 0)
    .map((item) => ({ key: `HQ:${Number(item.id)}`, name: String(item.location_name || '总部仓'), type: 'HQ' }))
  const stores = props.warehouseOptions.filter((item) => Number(item.store_id) > 0)
    .map((item) => ({ key: `STORE:${Number(item.store_id)}`, name: String(item.store_name_snapshot || item.store_name || `门店${item.store_id}`), type: 'STORE' }))
  return [...hq, ...stores].filter((item, index, list) => list.findIndex((other) => other.key === item.key) === index)
})
const activeProductRow = computed(() => rows.value.find((row) => row.id === productRowId.value))
const activeDestinationRow = computed(() => rows.value.find((row) => row.id === destinationRowId.value))
const destinationOptions = computed(() => {
  const row = activeDestinationRow.value
  if (!row) return []
  const options = targetLists.value[row.sourceKey] || []
  const keyword = destinationSearch.value.trim().toLowerCase()
  return options.filter((item) => !keyword || item.name.toLowerCase().includes(keyword) || item.key.toLowerCase().includes(keyword))
})
const catalogQuery = computed(() => {
  if (!platform.value) return {}
  const [type, rawId] = String(activeProductRow.value?.sourceKey || '').split(':', 2)
  const sourceStoreId = type === 'STORE' ? Number(rawId) : 0
  const hqLocationId = type === 'HQ' ? Number(rawId) : Number(props.hqLocationId)
  return {
    hq_location_id: hqLocationId, source_party_type: type, source_store_id: sourceStoreId,
    availability_party_type: type, availability_store_id: sourceStoreId, availability_location_id: hqLocationId
  }
})
const plannedCount = computed(() => new Set(rows.value.flatMap((row) => row.destinations.map((item) => `${row.sourceKey}>${item.key}`))).size)
const canSubmit = computed(() => !busy.value && rows.value.length > 0)

function newRow(sourceKey = defaultSourceKey.value) {
  return { id: nextRowId++, sourceKey, product: null, quantity: '1', detailRemark: '', destinations: [] }
}
function addRow() {
  const row = newRow(rows.value[rows.value.length - 1]?.sourceKey || defaultSourceKey.value)
  rows.value.push(row)
  if (row.sourceKey) loadTargets(row.sourceKey)
}
function onSourceChanged(row, key) {
  // A source change invalidates both the selected SKU identity and its eligible recipients.
  row.sourceKey = String(key || '')
  row.product = null
  row.destinations = []
  if (productRowId.value === row.id) productRowId.value = 0
  if (destinationRowId.value === row.id) destinationRowId.value = 0
  error.value = ''
  if (row.sourceKey) loadTargets(row.sourceKey)
}
function sourceQuery(key) {
  const [type, rawId] = String(key).split(':', 2)
  return {
    hq_location_id: type === 'HQ' ? Number(rawId) : Number(props.hqLocationId),
    source_party_type: type, source_store_id: type === 'STORE' ? Number(rawId) : 0
  }
}
const pendingTargets = new Map()
async function loadTargets(sourceKey) {
  if (targetLists.value[sourceKey]) return targetLists.value[sourceKey]
  if (pendingTargets.has(sourceKey)) return pendingTargets.get(sourceKey)
  targetLoading.value = { ...targetLoading.value, [sourceKey]: true }
  const promise = (async () => {
    try {
      const response = platform.value
        ? await platformInventoryApi.hqCrossTransferCounterparties(sourceQuery(sourceKey))
        : await inventoryApi.crossTransferCounterparties()
      const options = (Array.isArray(response?.list) ? response.list : []).map((item) => {
        const type = String(item.party_type || 'STORE').toUpperCase()
        const id = type === 'HQ' ? Number(item.location_id) : Number(item.id || item.store_id)
        return { key: `${type}:${id}`, name: String(item.name || item.store_name || ''), type }
      }).filter((item) => item.name && !item.key.endsWith(':0') && item.key !== sourceKey)
      targetLists.value = { ...targetLists.value, [sourceKey]: options }
      return options
    } catch (cause) {
      error.value = cause instanceof Error ? cause.message : '调入方读取失败。'
      return []
    } finally {
      pendingTargets.delete(sourceKey)
      targetLoading.value = { ...targetLoading.value, [sourceKey]: false }
    }
  })()
  pendingTargets.set(sourceKey, promise)
  return promise
}

async function openProducts(row) {
  if (platform.value && !sourceOptions.value.some((item) => item.key === row.sourceKey)) {
    error.value = '请先选择可操作的调出方。'
    return
  }
  productRowId.value = row.id
}
function selectProducts(products) {
  const row = activeProductRow.value
  if (!row || !products.length) { productRowId.value = 0; return }
  const [first, ...extra] = products
  row.product = first
  for (const product of extra) {
    const added = newRow(row.sourceKey)
    added.product = product
    added.quantity = row.quantity
    added.destinations = row.destinations.map((item) => ({ ...item }))
    rows.value.push(added)
  }
  productRowId.value = 0
}
async function openDestinations(row) {
  if (!row.sourceKey && platform.value) { error.value = '请先选择调出方。'; return }
  const sourceKey = row.sourceKey
  await loadTargets(sourceKey)
  if (row.sourceKey !== sourceKey) return
  destinationSearch.value = ''
  destinationRowId.value = row.id
  if (platform.value && !staffOptions.value.length) loadStaff(row)
}
async function loadStaff(row) {
  const first = row.destinations[0] || (targetLists.value[row.sourceKey] || [])[0]
  if (!first) return
  const targetStoreId = first.key.startsWith('STORE:') ? Number(first.key.split(':')[1]) : 0
  const targetLocationId = first.key.startsWith('HQ:') ? Number(first.key.split(':')[1]) : 0
  try {
    const response = await platformInventoryApi.hqCrossTransferStaffs({
      ...sourceQuery(row.sourceKey), target_store_id: targetStoreId, target_location_id: targetLocationId
    })
    staffOptions.value = Array.isArray(response?.list) ? response.list : []
    staffId.value = Number(response?.current_staff_id || 0)
  } catch (cause) { error.value = cause instanceof Error ? cause.message : '调拨人员读取失败。' }
}
function toggleDestination(row, option) {
  const exists = row.destinations.some((item) => item.key === option.key)
  row.destinations = exists
    ? row.destinations.filter((item) => item.key !== option.key)
    : [...row.destinations, { key: option.key, quantity: row.quantity, custom: false }]
}
function selectVisibleDestinations(row) {
  const keys = new Set(row.destinations.map((item) => item.key))
  row.destinations = [...row.destinations, ...destinationOptions.value.filter((item) => !keys.has(item.key)).map((item) => ({ key: item.key, quantity: row.quantity, custom: false }))]
}
function updateDefaultQuantity(row, value) {
  row.quantity = value
  row.destinations = row.destinations.map((item) => item.custom ? item : { ...item, quantity: value })
}
function saveBlob(bytes) {
  const blob = new Blob([bytes], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' })
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = '调拨导入模板.xlsx'
  anchor.click()
  URL.revokeObjectURL(url)
}
async function downloadTemplate() {
  try {
    if (platform.value && !defaultSourceKey.value) throw new Error('请先选择总部仓。')
    const references = platform.value ? sourceOptions.value : []
    const targets = await loadTargets(defaultSourceKey.value)
    saveBlob(await exportTransferTemplateXlsx([...references, ...targets].filter((item, index, all) => all.findIndex((other) => other.key === item.key) === index)))
  } catch (cause) { error.value = cause instanceof Error ? cause.message : '模板下载失败。' }
}

/** Resolve each source/code once, then atomically create all destination drafts. */
async function importFile(event) {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file || busy.value) return
  error.value = ''
  fileName.value = ''
  if (!/\.xlsx$/i.test(file.name) || file.size > 2 * 1024 * 1024) { error.value = '请选择不超过 2 MB 的 xlsx 文件。'; return }
  busy.value = true
  try {
    const lines = await parseTransferXlsx(await file.arrayBuffer())
    if (!platform.value && lines.some((line) => line.sourceKey)) throw new Error('门店端调出方由当前登录门店确定，模板中请留空。')
    const sources = [...new Set(lines.map((line) => platform.value ? line.sourceKey : defaultSourceKey.value))]
    for (const sourceKey of sources) {
      if (platform.value && !sourceOptions.value.some((item) => item.key === sourceKey)) throw new Error(`调出方 ${sourceKey} 不在当前可操作范围。`)
      await loadTargets(sourceKey)
    }
    const products = new Map()
    const unique = [...new Set(lines.map((line) => `${platform.value ? line.sourceKey : defaultSourceKey.value}\u0000${line.code}`))]
    for (let index = 0; index < unique.length; index += 5) {
      const batch = unique.slice(index, index + 5)
      const resolved = await Promise.all(batch.map(async (identity) => {
        const [sourceKey, code] = identity.split('\u0000')
        const query = platform.value ? {
          ...sourceQuery(sourceKey), availability_party_type: sourceKey.split(':')[0],
          availability_store_id: sourceKey.startsWith('STORE:') ? Number(sourceKey.split(':')[1]) : 0,
          availability_location_id: sourceKey.startsWith('HQ:') ? Number(sourceKey.split(':')[1]) : Number(props.hqLocationId)
        } : {}
        let product
        try { product = await catalogApi.value.findCatalogByBarcode({ ...query, barcode: code }) }
        catch (cause) { throw new Error(`商品编码 ${code}：${cause instanceof Error ? cause.message : '匹配失败。'}`) }
        if (Number(product?.product_id) <= 0 || Number(product?.sku_id) <= 0 || !String(product?.sku_unique || '').trim()) throw new Error(`商品编码 ${code} 没有有效规格。`)
        return [identity, product]
      }))
      for (const [identity, product] of resolved) products.set(identity, product)
    }
    const imported = lines.map((line) => {
      const sourceKey = platform.value ? line.sourceKey : defaultSourceKey.value
      if (!(targetLists.value[sourceKey] || []).some((item) => item.key === line.targetKey)) throw new Error(`第 ${line.rowNumber} 行调入方不属于该调出方的可选对象。`)
      return {
        id: nextRowId++, sourceKey, product: products.get(`${sourceKey}\u0000${line.code}`),
        quantity: line.quantity, detailRemark: line.detailRemark,
        destinations: [{ key: line.targetKey, quantity: line.quantity, custom: false }]
      }
    })
    rows.value = imported
    fileName.value = file.name
    busy.value = false
    await submitDrafts(imported)
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : '调拨文件读取失败。'
  } finally { busy.value = false }
}
async function submitDrafts(items = rows.value) {
  if (busy.value) return
  error.value = ''
  let drafts
  try {
    drafts = planTransferDraftBatch(items, {
      platform: platform.value, storeSourceKey: defaultSourceKey.value,
      businessDate: businessDate.value, remark: remark.value,
      staffId: staffId.value, hqLocationId: props.hqLocationId
    })
  } catch (cause) { error.value = cause instanceof Error ? cause.message : '调拨明细不完整。'; return }
  const content = JSON.stringify(drafts)
  if (attempt.value.content !== content) {
    const nonce = typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`
    attempt.value = { content, key: `transfer-batch-${drafts.length}-${nonce}` }
  }
  busy.value = true
  try {
    const payload = { idempotency_key: attempt.value.key, drafts }
    if (platform.value) await platformInventoryApi.createHqCrossTransferBatch(payload)
    else await inventoryApi.createCrossTransferBatch(payload)
    emit('saved', { businessDate: businessDate.value })
    emit('close')
  } catch (cause) { error.value = cause instanceof Error ? cause.message : '生成调拨草稿失败。' }
  finally { busy.value = false }
}

watch(defaultSourceKey, (sourceKey, previous) => {
  if (!rows.value.length && !importMode.value) addRow()
  // The HQ warehouse arrives asynchronously; update only untouched placeholder rows.
  if (previous !== sourceKey) {
    for (const row of rows.value) if (row.sourceKey === previous && !row.product && !row.destinations.length) row.sourceKey = sourceKey
  }
  if (sourceKey) loadTargets(sourceKey)
}, { immediate: true })
</script>

<template>
  <section class="transfer-composer" :aria-label="importMode ? '导入调拨草稿' : '新建调拨草稿'">
    <header class="composer-header">
      <div><small>{{ mode === 'platform' ? '平台端' : '门店端' }} · {{ scopeName }}</small><h2>{{ importMode ? '导入调拨草稿' : '新建调拨草稿' }}</h2></div>
      <button class="icon-button" title="返回列表" :disabled="busy" @click="emit('close')"><X :size="19" /></button>
    </header>
    <main class="composer-body">
      <section class="composer-meta">
        <label>调拨日期<input v-model="businessDate" type="date" :disabled="busy" /></label>
        <label v-if="platform">调拨人
          <select v-model.number="staffId" :disabled="busy || !staffOptions.length">
            <option v-if="!staffOptions.length" :value="0">当前登录人员</option>
            <option v-for="staff in staffOptions" :key="staff.id" :value="Number(staff.id)">{{ staff.employee_name || staff.staff_name }}（{{ staff.store_name || '平台账号' }}）</option>
          </select>
        </label>
        <label>备注<input v-model="remark" maxlength="500" :disabled="busy" /></label>
      </section>
      <section v-if="importMode" class="composer-upload">
        <span>{{ fileName || 'Excel 调拨明细' }}</span>
        <button type="button" :disabled="busy" @click="downloadTemplate"><FileSpreadsheet :size="16" />下载模板</button>
        <button type="button" :disabled="busy" @click="fileInput?.click()">{{ busy ? '处理中' : '选择文件并导入' }}</button>
        <input ref="fileInput" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" hidden @change="importFile" />
      </section>
      <section class="composer-lines">
        <header><h3>调拨明细</h3><button v-if="!importMode" type="button" :disabled="busy" @click="addRow"><Plus :size="16" />添加明细</button></header>
        <div class="composer-table-scroll">
          <table><thead><tr><th>调出方</th><th>商品</th><th>调入方</th><th>每店数量</th><th>明细备注</th><th>操作</th></tr></thead>
            <tbody>
              <tr v-if="!rows.length"><td colspan="6" class="empty">暂无调拨明细</td></tr>
              <tr v-for="row in rows" :key="row.id">
                <td class="party-cell"><InventoryStoreSelector v-if="platform" :model-value="row.sourceKey" :options="sourceOptions" presentation="modal" :disabled="busy" placeholder="选择调出方" @update:modelValue="onSourceChanged(row, $event)" /><span v-else>{{ scopeName }}</span></td>
                <td><button type="button" class="product-button" :disabled="busy" @click="openProducts(row)">{{ row.product ? `${row.product.product_name} · ${row.product.sku_name || '默认规格'}` : '选择商品' }}</button></td>
                <td><button type="button" class="destination-button" :disabled="busy || !!targetLoading[row.sourceKey]" @click="openDestinations(row)">{{ row.destinations.length ? `已选 ${row.destinations.length} 个调入方` : '选择调入方' }}</button></td>
                <td><input class="quantity-input" :value="row.quantity" inputmode="decimal" :disabled="busy" aria-label="每店默认数量" @input="updateDefaultQuantity(row, $event.target.value)" /></td>
                <td><input v-model="row.detailRemark" maxlength="500" :disabled="busy" aria-label="明细备注" /></td>
                <td><button type="button" class="delete-button" title="删除明细" :disabled="busy" @click="rows = rows.filter((item) => item.id !== row.id)"><Trash2 :size="16" /></button></td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
      <p v-if="error" class="composer-error" role="alert">{{ error }}</p>
    </main>
    <footer class="composer-footer"><span>{{ rows.length }} 项商品 · {{ plannedCount }} 张调拨草稿</span><div><button type="button" :disabled="busy" @click="emit('close')">取消</button><button v-if="!importMode || !!error" type="button" class="primary" :disabled="!canSubmit" @click="submitDrafts()">{{ busy ? '生成中' : importMode ? '重试生成草稿' : '生成调拨草稿' }}</button></div></footer>
  </section>
  <InventoryProductSelector :visible="!!productRowId" :selected-rows="activeProductRow?.product ? [activeProductRow.product] : []" :catalog-api="catalogApi" :catalog-query="catalogQuery" @close="productRowId = 0" @confirm="selectProducts" />
  <Teleport to="body">
    <div v-if="activeDestinationRow" class="destination-layer">
      <button class="destination-mask" aria-label="关闭调入方选择" @click="destinationRowId = 0" />
      <section class="destination-dialog" role="dialog" aria-modal="true" aria-label="选择调入方">
        <header><h3>选择调入方</h3><button class="icon-button" title="关闭" @click="destinationRowId = 0"><X :size="18" /></button></header>
        <div class="destination-toolbar"><label><Search :size="16" /><input v-model="destinationSearch" placeholder="搜索门店或编码" /></label><button type="button" @click="selectVisibleDestinations(activeDestinationRow)">全选当前结果</button><span>已选 {{ activeDestinationRow.destinations.length }}</span></div>
        <div class="destination-list">
          <div v-for="option in destinationOptions" :key="option.key" class="destination-item">
            <label><input type="checkbox" :checked="activeDestinationRow.destinations.some((item) => item.key === option.key)" @change="toggleDestination(activeDestinationRow, option)" />{{ option.name }}</label>
            <input v-if="activeDestinationRow.destinations.some((item) => item.key === option.key)" :value="activeDestinationRow.destinations.find((item) => item.key === option.key)?.quantity" inputmode="decimal" aria-label="该门店调拨数量" @input="activeDestinationRow.destinations.find((item) => item.key === option.key).quantity = $event.target.value; activeDestinationRow.destinations.find((item) => item.key === option.key).custom = true" />
          </div>
          <p v-if="!destinationOptions.length">没有符合条件的调入方</p>
        </div>
        <footer><button type="button" class="primary" @click="destinationRowId = 0">完成</button></footer>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.transfer-composer { display: grid; grid-template-rows: auto minmax(0, 1fr) auto; width: 100%; height: calc(100vh - 112px); min-height: 0; overflow: hidden; border: 1px solid #dce6ef; border-radius: 8px; background: #f5f7fa; color: #30465d; }
.composer-header, .composer-footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 18px; background: #fff; }.composer-header { border-bottom: 1px solid #e1e8ef; }.composer-footer { border-top: 1px solid #e1e8ef; font-size: 12px; }.composer-header h2 { margin: 4px 0 0; font-size: 18px; }.composer-header small { color: #789; }.composer-footer > div { display: flex; gap: 8px; }
.composer-body { min-height: 0; overflow: auto; padding: 15px 18px; }.composer-meta { display: flex; flex-wrap: wrap; gap: 14px; align-items: end; margin-bottom: 14px; }.composer-meta label { display: grid; min-width: 170px; gap: 5px; font-size: 12px; color: #60748a; }.composer-meta label:last-child { flex: 1; }.composer-meta input, .composer-meta select { height: 34px; padding: 0 9px; border: 1px solid #d8e3ed; border-radius: 6px; background: #fff; color: #30465d; }
.composer-upload { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 14px; }.composer-upload span { flex: 1; min-width: 200px; font-size: 12px; color: #60748a; }.composer-upload button, .composer-lines header button, .composer-footer button, .destination-toolbar button, .destination-dialog footer button { display: inline-flex; align-items: center; justify-content: center; gap: 5px; min-height: 33px; padding: 0 11px; border: 1px solid #d5e1ee; border-radius: 6px; background: #fff; color: #47627d; font-size: 12px; }.primary, .composer-footer button.primary, .destination-dialog footer button.primary { border-color: #176fd1; background: #176fd1; color: #fff; }button:disabled { opacity: .5; cursor: default; }.icon-button { display: grid; width: 32px; height: 32px; place-items: center; border: 0; background: transparent; color: #60748a; }
.composer-lines { overflow: hidden; border: 1px solid #dce6ef; border-radius: 8px; background: #fff; }.composer-lines > header { display: flex; align-items: center; justify-content: space-between; padding: 11px 13px; border-bottom: 1px solid #e8eef4; }.composer-lines h3 { margin: 0; font-size: 14px; }.composer-table-scroll { max-height: 480px; overflow: auto; }.composer-table-scroll table { width: 100%; min-width: 1050px; border-collapse: collapse; }.composer-table-scroll th, .composer-table-scroll td { padding: 8px 10px; border-bottom: 1px solid #edf1f5; text-align: left; font-size: 12px; }.composer-table-scroll th { position: sticky; top: 0; z-index: 1; background: #f7f9fc; color: #60748a; }.composer-table-scroll td { height: 52px; }.composer-table-scroll td.party-cell { min-width: 190px; }.composer-table-scroll td input { height: 32px; padding: 0 7px; border: 1px solid #d8e3ed; border-radius: 6px; }.composer-table-scroll td input.quantity-input { width: 82px; }.product-button, .destination-button { max-width: 230px; overflow: hidden; border: 0; background: none; color: #176fd1; text-overflow: ellipsis; white-space: nowrap; }.delete-button { border: 0; background: none; color: #c75353; }.empty { height: 90px; color: #8393a3; text-align: center !important; }.composer-error { color: #bb4646; font-size: 12px; }
.destination-layer { position: fixed; z-index: 130; inset: 0; display: grid; place-items: center; padding: 16px; }.destination-mask { position: absolute; inset: 0; border: 0; background: #12283d66; }.destination-dialog { position: relative; display: grid; grid-template-rows: auto auto minmax(0, 1fr) auto; width: min(620px, 100%); max-height: min(760px, 90vh); overflow: hidden; border-radius: 8px; background: #fff; box-shadow: 0 24px 60px #061e3660; }.destination-dialog > header, .destination-dialog > footer { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border-bottom: 1px solid #e5ecf3; }.destination-dialog > footer { justify-content: flex-end; border-top: 1px solid #e5ecf3; border-bottom: 0; }.destination-dialog h3 { margin: 0; font-size: 15px; }.destination-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; padding: 10px 16px; }.destination-toolbar label { display: flex; flex: 1; align-items: center; gap: 5px; min-width: 180px; border: 1px solid #d8e3ed; border-radius: 6px; padding: 0 8px; }.destination-toolbar input { width: 100%; height: 32px; border: 0; outline: 0; }.destination-toolbar span { font-size: 12px; color: #60748a; }.destination-list { min-height: 120px; overflow: auto; padding: 0 16px 12px; }.destination-item { display: flex; align-items: center; justify-content: space-between; min-height: 43px; gap: 8px; border-bottom: 1px solid #eff3f7; font-size: 12px; }.destination-item label { display: flex; align-items: center; gap: 8px; flex: 1; }.destination-item > input { width: 90px; height: 30px; border: 1px solid #d8e3ed; border-radius: 6px; padding: 0 7px; }
@media (max-width: 650px) { .transfer-composer { height: calc(100vh - 94px); }.composer-meta { display: grid; }.composer-meta label { min-width: 0; }.composer-footer { align-items: flex-start; flex-direction: column; }.composer-footer > div { align-self: flex-end; } }
</style>

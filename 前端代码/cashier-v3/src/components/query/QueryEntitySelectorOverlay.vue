<script setup>
import { computed, ref } from 'vue'

/**
 * 统一查询的人员／门店／组织选择浮层。
 *
 * 可见范围、排序、可选状态均由调用方的后端查询接口决定；组件只展示后端返回的记录，
 * 不在前端推断组织权限或自行扩大查询范围。
 *
 * 建议 records 中提供：
 * - 通用：id、name、code、secondaryText、auxiliary、selectable、selectableReason
 * - 人员：staffId、staffName、staffNo、mobile、positionName、storeName、organizationName
 * - 门店：storeId、storeName、storeCode、organizationName、address
 * - 组织：organizationId、organizationName、organizationCode、organizationPath、parentOrganizationName
 */
const props = defineProps({
  entityType: {
    type: String,
    default: 'person',
    validator: (value) => ['person', 'store', 'organization'].includes(value)
  },
  records: {
    type: Array,
    default: () => []
  },
  total: {
    type: Number,
    default: 0
  },
  page: {
    type: Number,
    default: 1
  },
  pageSize: {
    type: Number,
    default: 20
  },
  isLoading: {
    type: Boolean,
    default: false
  },
  onQuery: {
    type: Function,
    default: null
  },
  onSelect: {
    type: Function,
    default: null
  },
  title: {
    type: String,
    default: ''
  },
  description: {
    type: String,
    default: ''
  },
  multiple: {
    type: Boolean,
    default: false
  },
  selectedRecords: {
    type: Array,
    default: () => []
  }
})

const emit = defineEmits(['close', 'selected'])

const ENTITY_DEFINITIONS = {
  person: {
    label: '人员',
    defaultTitle: '选择人员',
    searchLabel: '查询人员',
    searchPlaceholder: '姓名、手机号、工号',
    description: '可搜索当前账号有权查看的人员；可见范围和可选范围由后端统一控制。',
    codeLabel: '工号',
    contactLabel: '联系方式'
  },
  store: {
    label: '门店',
    defaultTitle: '选择门店',
    searchLabel: '查询门店',
    searchPlaceholder: '门店名称或门店编号',
    description: '可搜索当前账号有权查看的门店；可见范围和可选范围由后端统一控制。',
    codeLabel: '门店编号',
    contactLabel: '门店信息'
  },
  organization: {
    label: '组织',
    defaultTitle: '选择组织',
    searchLabel: '查询组织',
    searchPlaceholder: '组织名称或组织编号',
    description: '可搜索当前账号有权查看的组织；可见范围和可选范围由后端统一控制。',
    codeLabel: '组织编号',
    contactLabel: '组织信息'
  }
}

const keyword = ref('')
const isQuerying = ref(false)
const selectingRecordKey = ref('')
const queryError = ref('')
const selectError = ref('')
const selectedRecordMap = ref(new Map())

const definition = computed(() => ENTITY_DEFINITIONS[props.entityType] || ENTITY_DEFINITIONS.person)
const resolvedTitle = computed(() => props.title?.trim() || definition.value.defaultTitle)
const pageCount = computed(() => Math.max(1, Math.ceil(Math.max(0, props.total) / Math.max(1, props.pageSize))))
const isBusy = computed(() => props.isLoading || isQuerying.value || Boolean(selectingRecordKey.value))
const canGoPrevious = computed(() => !isBusy.value && props.page > 1)
const canGoNext = computed(() => !isBusy.value && props.page < pageCount.value)
const selectedRecords = computed(() => Array.from(selectedRecordMap.value.values()))
const selectedCount = computed(() => selectedRecordMap.value.size)

function firstValue(record, keys) {
  for (const key of keys) {
    const value = record?.[key]
    if (value !== undefined && value !== null && String(value).trim()) return String(value).trim()
  }
  return ''
}

function recordStableIdentity(record) {
  return firstValue(record, [
    'id',
    `${props.entityType}Id`,
    'staffId',
    'storeId',
    'organizationId',
    'code',
    'staffNo',
    'storeCode',
    'organizationCode'
  ])
}

function recordIdentity(record, index = '') {
  return recordStableIdentity(record) || `${recordName(record)}-${index}`
}

function recordName(record) {
  return firstValue(record, [
    'name',
    `${props.entityType}Name`,
    'staffName',
    'storeName',
    'organizationName',
    'title',
    'label'
  ]) || '—'
}

function recordCode(record) {
  return firstValue(record, [
    'code',
    `${props.entityType}Code`,
    'staffNo',
    'storeCode',
    'organizationCode',
    'number'
  ]) || '—'
}

function recordStatus(record) {
  return firstValue(record, ['statusLabel', 'statusName', 'status'])
}

function recordContact(record) {
  if (props.entityType === 'person') return firstValue(record, ['mobile', 'phone', 'telephone', 'contact']) || '—'
  if (props.entityType === 'store') return firstValue(record, ['address', 'storeAddress', 'contact', 'phone']) || '—'
  return firstValue(record, ['organizationPath', 'path', 'parentOrganizationName', 'parentName']) || '—'
}

function recordAuxiliary(record) {
  const supplied = Array.isArray(record?.auxiliary)
    ? record.auxiliary
    : String(record?.auxiliary || '').split(/[\n|]/)
  const fallback = props.entityType === 'person'
    ? [record?.positionName, record?.roleName, record?.storeName, record?.organizationName, record?.secondaryText]
    : props.entityType === 'store'
      ? [record?.organizationName, record?.organizationPath, record?.managerName, record?.secondaryText]
      : [record?.parentOrganizationName, record?.organizationPath, record?.secondaryText]

  return [...supplied, ...fallback]
    .map((item) => String(item ?? '').trim())
    .filter(Boolean)
    .filter((item, index, source) => source.indexOf(item) === index)
    .slice(0, 3)
}

function isSelectable(record) {
  return record?.selectable === true
    && record?.disabled !== true
    && Boolean(recordStableIdentity(record))
}

function initializeSelectedRecords() {
  const next = new Map()
  ;(props.selectedRecords || []).forEach((record, index) => {
    if (!record || typeof record !== 'object' || !isSelectable(record)) return
    next.set(recordIdentity(record, index), record)
  })
  selectedRecordMap.value = next
}

initializeSelectedRecords()

function isRecordSelected(record, index) {
  return selectedRecordMap.value.has(recordIdentity(record, index))
}

function toggleRecord(record, index) {
  if (!isSelectable(record) || isBusy.value) return
  const key = recordIdentity(record, index)
  const next = new Map(selectedRecordMap.value)
  if (next.has(key)) next.delete(key)
  else next.set(key, record)
  selectedRecordMap.value = next
  selectError.value = ''
}

function unavailableReason(record) {
  return firstValue(record, ['selectableReason', 'disabledReason', 'unavailableReason']) || `该${definition.value.label}当前不可选择`
}

function resultError(result, fallback) {
  if (result === false || result?.success === false || result?.ok === false) {
    return firstValue(result, ['message', 'errorMessage', 'error']) || fallback
  }
  return ''
}

async function runQuery(targetPage = 1) {
  if (isBusy.value) return
  queryError.value = ''

  if (typeof props.onQuery !== 'function') {
    queryError.value = `暂未接入${definition.value.label}查询服务。`
    return
  }

  isQuerying.value = true
  try {
    const result = await props.onQuery({
      entityType: props.entityType,
      keyword: keyword.value.trim(),
      page: targetPage,
      pageSize: props.pageSize
    })
    queryError.value = resultError(result, `${definition.value.label}查询失败，请稍后重试。`)
  } catch (error) {
    queryError.value = error?.message || `${definition.value.label}查询失败，请稍后重试。`
  } finally {
    isQuerying.value = false
  }
}

function submitQuery() {
  runQuery(1)
}

function clearQuery() {
  keyword.value = ''
  runQuery(1)
}

function requestClose(reason = 'close') {
  if (isBusy.value) return
  emit('close', { reason, entityType: props.entityType })
}

async function selectRecord(record, index) {
  if (!isSelectable(record) || isBusy.value) return
  if (props.multiple) {
    toggleRecord(record, index)
    return
  }
  selectError.value = ''

  if (typeof props.onSelect !== 'function') {
    selectError.value = `暂未接入${definition.value.label}选择服务。`
    return
  }

  const selectionKey = recordIdentity(record, index)
  selectingRecordKey.value = selectionKey
  try {
    const result = await props.onSelect(record, { entityType: props.entityType })
    const error = resultError(result, `选择${definition.value.label}失败，请稍后重试。`)
    if (error) {
      selectError.value = error
      return
    }

    // 调用方决定如何把筛选值写回组合筛选；组件仅回传原始后端记录。
    emit('selected', { record, entityType: props.entityType })
    emit('close', { reason: 'selected', record, entityType: props.entityType })
  } catch (error) {
    selectError.value = error?.message || `选择${definition.value.label}失败，请稍后重试。`
  } finally {
    selectingRecordKey.value = ''
  }
}

async function confirmMultiple() {
  if (!props.multiple || isBusy.value) return
  selectError.value = ''
  if (!selectedRecords.value.length) {
    selectError.value = `请至少选择一名${definition.value.label}。`
    return
  }
  if (!selectedRecords.value.every(isSelectable)) {
    selectError.value = `有${definition.value.label}未被后端明确标记为可选择，请刷新后重选。`
    return
  }
  if (typeof props.onSelect !== 'function') {
    selectError.value = `暂未接入${definition.value.label}选择服务。`
    return
  }

  selectingRecordKey.value = '__multiple_confirm__'
  try {
    const result = await props.onSelect(selectedRecords.value, { entityType: props.entityType, multiple: true })
    const error = resultError(result, `选择${definition.value.label}失败，请稍后重试。`)
    if (error) {
      selectError.value = error
      return
    }
    emit('selected', { records: selectedRecords.value, entityType: props.entityType })
    emit('close', { reason: 'selected', records: selectedRecords.value, entityType: props.entityType })
  } catch (error) {
    selectError.value = error?.message || `选择${definition.value.label}失败，请稍后重试。`
  } finally {
    selectingRecordKey.value = ''
  }
}
</script>

<template>
  <div class="query-entity-selector" role="presentation" @click.self="requestClose('dismiss')">
    <section class="query-entity-selector__dialog" role="dialog" aria-modal="true" :aria-label="resolvedTitle">
      <header class="query-entity-selector__header">
        <div>
          <h2>{{ resolvedTitle }}</h2>
        </div>
        <button
          type="button"
          class="query-entity-selector__close"
          :disabled="isBusy"
          :aria-label="`关闭${resolvedTitle}`"
          @click="requestClose('close')"
        >×</button>
      </header>

      <form class="query-entity-selector__search" @submit.prevent="submitQuery">
        <label class="query-entity-selector__search-field">
          <span>{{ definition.searchLabel }}</span>
          <input
            v-model="keyword"
            type="search"
            autocomplete="off"
            :placeholder="definition.searchPlaceholder"
            :disabled="isBusy"
          >
        </label>
        <button type="submit" class="query-entity-selector__button query-entity-selector__button--primary" :disabled="isBusy">
          {{ isBusy ? '查询中…' : '查询' }}
        </button>
        <button type="button" class="query-entity-selector__button" :disabled="isBusy" @click="clearQuery">清空</button>
      </form>

      <div class="query-entity-selector__feedback" aria-live="polite">
        <p v-if="queryError" class="query-entity-selector__message query-entity-selector__message--error" role="alert">{{ queryError }}</p>
        <p v-if="selectError" class="query-entity-selector__message query-entity-selector__message--error" role="alert">{{ selectError }}</p>
      </div>

      <div class="query-entity-selector__content" :aria-busy="isBusy">
        <div v-if="isBusy && !records.length" class="query-entity-selector__loading">正在加载{{ definition.label }}…</div>
        <div v-else-if="!records.length" class="query-entity-selector__empty">
          <strong>暂无可显示的{{ definition.label }}</strong>
          <span>可直接查询，或清空条件后分页浏览全部{{ definition.label }}。</span>
        </div>

        <table v-else class="query-entity-selector__table">
          <thead>
            <tr>
              <th>{{ definition.label }}名称</th>
              <th>{{ definition.codeLabel }}</th>
              <th>归属信息</th>
              <th>{{ definition.contactLabel }}</th>
              <th class="query-entity-selector__action-column">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="(record, index) in records"
              :key="recordIdentity(record, index)"
              :class="{ 'query-entity-selector__row--unavailable': !isSelectable(record) }"
            >
              <td>
                <strong>{{ recordName(record) }}</strong>
                <span v-if="recordStatus(record)" class="query-entity-selector__status">{{ recordStatus(record) }}</span>
              </td>
              <td>{{ recordCode(record) }}</td>
              <td>
                <template v-if="recordAuxiliary(record).length">
                  <span v-for="line in recordAuxiliary(record)" :key="line" class="query-entity-selector__auxiliary">{{ line }}</span>
                </template>
                <span v-else>—</span>
              </td>
              <td>{{ recordContact(record) }}</td>
              <td class="query-entity-selector__action-column">
                <button
                  type="button"
                  class="query-entity-selector__button query-entity-selector__button--text"
                  :disabled="!isSelectable(record) || isBusy"
                  :title="isSelectable(record) ? (multiple ? (isRecordSelected(record, index) ? `取消选择该${definition.label}` : `选择该${definition.label}`) : `选择该${definition.label}`) : unavailableReason(record)"
                  @click="selectRecord(record, index)"
                >
                  {{ !isSelectable(record) ? '不可选择' : multiple ? (isRecordSelected(record, index) ? '已选择' : '选择') : selectingRecordKey === recordIdentity(record, index) ? '选择中…' : '选择' }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <footer class="query-entity-selector__footer">
        <span>共 {{ total }} 个{{ definition.label }}，第 {{ page }} / {{ pageCount }} 页</span>
        <div class="query-entity-selector__pagination">
          <button v-if="multiple" type="button" class="query-entity-selector__button query-entity-selector__button--primary" :disabled="isBusy || !selectedCount" @click="confirmMultiple">确认选择（{{ selectedCount }}）</button>
          <button type="button" class="query-entity-selector__button" :disabled="!canGoPrevious" @click="runQuery(page - 1)">上一页</button>
          <button type="button" class="query-entity-selector__button" :disabled="!canGoNext" @click="runQuery(page + 1)">下一页</button>
        </div>
      </footer>
    </section>
  </div>
</template>

<style scoped>
.query-entity-selector {
  position: fixed;
  z-index: 1250;
  inset: 0;
  display: grid;
  place-items: center;
  padding: 32px;
  background: rgb(16 24 40 / 46%);
}

.query-entity-selector__dialog {
  display: flex;
  width: min(1080px, 100%);
  height: min(720px, calc(100vh - 64px));
  min-height: 0;
  flex-direction: column;
  overflow: hidden;
  border: 1px solid #dfe5ef;
  border-radius: 14px;
  background: #fff;
  box-shadow: 0 24px 60px rgb(20 32 54 / 24%);
}

.query-entity-selector__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 24px;
  padding: 24px 28px 18px;
  border-bottom: 1px solid #edf0f5;
}

.query-entity-selector__header h2 {
  margin: 0;
  color: #172033;
  font-size: 20px;
  line-height: 28px;
}

.query-entity-selector__header p {
  max-width: 760px;
  margin: 6px 0 0;
  color: #667085;
  font-size: 13px;
  line-height: 20px;
}

.query-entity-selector__close {
  width: 32px;
  height: 32px;
  flex: 0 0 auto;
  border: 0;
  border-radius: 8px;
  background: transparent;
  color: #667085;
  cursor: pointer;
  font-size: 28px;
  line-height: 28px;
}

.query-entity-selector__close:hover:not(:disabled) {
  background: #f2f4f7;
  color: #344054;
}

.query-entity-selector__search {
  display: flex;
  align-items: flex-end;
  gap: 10px;
  padding: 18px 28px;
  border-bottom: 1px solid #edf0f5;
  background: #fbfcfe;
}

.query-entity-selector__search-field {
  display: grid;
  min-width: 360px;
  flex: 1;
  gap: 6px;
  color: #475467;
  font-size: 13px;
  font-weight: 600;
}

.query-entity-selector__search-field input {
  width: 100%;
  box-sizing: border-box;
  padding: 9px 12px;
  border: 1px solid #d0d5dd;
  border-radius: 8px;
  outline: none;
  color: #172033;
  font: inherit;
  font-weight: 400;
}

.query-entity-selector__search-field input:focus {
  border-color: #4b77ff;
  box-shadow: 0 0 0 3px rgb(75 119 255 / 12%);
}

.query-entity-selector__button {
  min-height: 36px;
  padding: 0 14px;
  border: 1px solid #d0d5dd;
  border-radius: 8px;
  background: #fff;
  color: #344054;
  cursor: pointer;
  font-size: 13px;
  font-weight: 600;
}

.query-entity-selector__button:hover:not(:disabled) {
  border-color: #a7b6df;
  background: #f7f9ff;
}

.query-entity-selector__button--primary {
  border-color: #3b63e6;
  background: #3b63e6;
  color: #fff;
}

.query-entity-selector__button--primary:hover:not(:disabled) {
  border-color: #2b50cf;
  background: #2b50cf;
}

.query-entity-selector__button--text {
  min-height: 32px;
  border-color: transparent;
  color: #315fd6;
}

.query-entity-selector__button:disabled,
.query-entity-selector__close:disabled {
  cursor: not-allowed;
  opacity: .52;
}

.query-entity-selector__feedback {
  box-sizing: border-box;
  min-height: 45px;
  padding: 8px 28px;
  border-bottom: 1px solid #edf0f5;
  background: #fff;
}

.query-entity-selector__message {
  margin: 0;
  padding: 9px 12px;
  border-radius: 8px;
  font-size: 13px;
  line-height: 20px;
}

.query-entity-selector__message + .query-entity-selector__message {
  margin-top: 6px;
}

.query-entity-selector__message--error {
  background: #fff1f1;
  color: #b42318;
}

.query-entity-selector__content {
  min-height: 220px;
  flex: 1;
  overflow: auto;
  scrollbar-gutter: stable;
}

.query-entity-selector__table {
  width: 100%;
  min-width: 900px;
  border-collapse: collapse;
  color: #344054;
  font-size: 13px;
}

.query-entity-selector__table th,
.query-entity-selector__table td {
  padding: 13px 16px;
  border-bottom: 1px solid #edf0f5;
  text-align: left;
  vertical-align: middle;
  white-space: nowrap;
}

.query-entity-selector__table th {
  position: sticky;
  z-index: 1;
  top: 0;
  background: #f8fafc;
  color: #667085;
  font-size: 12px;
  font-weight: 600;
}

.query-entity-selector__table td:first-child {
  color: #172033;
}

.query-entity-selector__table td:nth-child(3) {
  min-width: 220px;
  white-space: normal;
}

.query-entity-selector__row--unavailable {
  background: #fafafa;
  color: #98a2b3;
}

.query-entity-selector__row--unavailable td:first-child {
  color: #667085;
}

.query-entity-selector__status {
  display: inline-flex;
  min-height: 22px;
  align-items: center;
  margin-left: 8px;
  padding: 0 7px;
  border-radius: 999px;
  background: #f2f4f7;
  color: #667085;
  font-size: 11px;
  font-weight: 600;
}

.query-entity-selector__auxiliary {
  display: inline-block;
  margin: 2px 8px 2px 0;
  color: #667085;
  font-size: 12px;
  line-height: 18px;
}

.query-entity-selector__action-column {
  position: sticky;
  right: 0;
  z-index: 2;
  width: 100px;
  min-width: 100px;
  background: #fff;
  box-shadow: -10px 0 16px -16px rgb(16 24 40 / 60%);
  text-align: right !important;
}

.query-entity-selector__table th.query-entity-selector__action-column {
  z-index: 3;
  background: #f8fafc;
}

.query-entity-selector__row--unavailable .query-entity-selector__action-column {
  background: #fafafa;
}

.query-entity-selector__loading,
.query-entity-selector__empty {
  display: grid;
  min-height: 220px;
  place-content: center;
  gap: 8px;
  padding: 28px;
  color: #667085;
  text-align: center;
}

.query-entity-selector__empty strong {
  color: #344054;
  font-size: 15px;
}

.query-entity-selector__empty span {
  font-size: 13px;
}

.query-entity-selector__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 15px 28px;
  border-top: 1px solid #edf0f5;
  color: #667085;
  font-size: 13px;
}

.query-entity-selector__pagination {
  display: flex;
  gap: 8px;
}

@media (max-width: 760px) {
  .query-entity-selector {
    padding: 12px;
  }

  .query-entity-selector__dialog {
    height: calc(100vh - 24px);
  }

  .query-entity-selector__header,
  .query-entity-selector__search,
  .query-entity-selector__feedback,
  .query-entity-selector__footer {
    padding-right: 16px;
    padding-left: 16px;
  }

  .query-entity-selector__header {
    gap: 12px;
  }

  .query-entity-selector__search {
    align-items: stretch;
    flex-wrap: wrap;
  }

  .query-entity-selector__search-field {
    min-width: 100%;
  }

  .query-entity-selector__footer {
    align-items: flex-start;
    flex-direction: column;
  }
}
</style>

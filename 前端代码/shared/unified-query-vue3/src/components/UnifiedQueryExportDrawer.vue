<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import CircleCheck from '@lucide/vue/dist/esm/icons/circle-check.mjs'
import Download from '@lucide/vue/dist/esm/icons/download.mjs'
import FileSpreadsheet from '@lucide/vue/dist/esm/icons/file-spreadsheet.mjs'
import GripVertical from '@lucide/vue/dist/esm/icons/grip-vertical.mjs'
import X from '@lucide/vue/dist/esm/icons/x.mjs'
import {
  extractUnifiedQueryExportTask,
  unifiedQueryActionIdempotencyKey,
  unifiedQueryActionMessage,
  unifiedQueryActionStatus
} from '../contracts/unifiedQueryContract.js'

const props = defineProps({
  fields: { type: Array, default: () => [] },
  visibleFieldKeys: { type: Array, default: () => [] },
  pageName: { type: String, default: '查询结果' },
  resultCount: { type: Number, default: 0 },
  pageSize: { type: Number, default: 20 },
  currentPageCount: { type: Number, default: -1 },
  dataAsOf: { type: String, default: '' },
  previewMode: { type: Boolean, default: false },
  exportCapability: { type: Object, default: () => ({}) },
  initialTask: { type: Object, default: null },
  onCreate: { type: Function, default: null },
  onQueryTask: { type: Function, default: null },
  // Export download often needs an application token held outside cookies.
  // Let the host fetch the binary with its authenticated transport while the
  // shared drawer remains reusable by cookie-authenticated hosts.
  onDownload: { type: Function, default: null }
})

const emit = defineEmits(['close', 'export', 'task-change'])
const exportScope = ref('query')
const selectedFields = ref([])
const includeSummary = ref(true)
const fileName = ref('')
const error = ref('')
const isCreating = ref(false)
const isPolling = ref(false)
const isDownloading = ref(false)
const task = ref(props.initialTask ? { ...props.initialTask } : null)
let pollTimer = null
let pollAttempts = 0

const fieldOptions = computed(() => props.fields.filter((field) => !field.hidden && field.key !== 'id'))
const selectedFieldRecords = computed(() => selectedFields.value.map((key) => fieldOptions.value.find((field) => field.key === key)).filter(Boolean))
// 任务已经创建后，下载文件的列必须只以服务端返回的冻结表头为准。不能再从
// 当前页面字段回填，否则字段改名、停用或版本升级会让界面和 Excel 实际内容漂移。
const frozenTaskFields = computed(() => Array.isArray(task.value?.frozenFields) ? task.value.frozenFields : [])
const frozenTaskFieldCount = computed(() => frozenTaskFields.value.length)
const currentPageCount = computed(() => props.currentPageCount >= 0
  ? props.currentPageCount
  : Math.min(props.pageSize, props.resultCount))
const exportCount = computed(() => exportScope.value === 'page' ? currentPageCount.value : props.resultCount)
const isAllSelected = computed(() => fieldOptions.value.length > 0 && selectedFields.value.length === fieldOptions.value.length)
const allowCurrentQuery = computed(() => props.previewMode || props.exportCapability.allowCurrentQuery === true)
const allowCurrentPage = computed(() => props.previewMode || props.exportCapability.allowCurrentPage === true)
const allowSummary = computed(() => props.previewMode || props.exportCapability.allowSummary === true)
function localDateStamp(separator = '') {
  const now = new Date()
  const parts = [now.getFullYear(), String(now.getMonth() + 1).padStart(2, '0'), String(now.getDate()).padStart(2, '0')]
  return parts.join(separator)
}
const generatedFileName = computed(() => {
  const base = (fileName.value.trim() || `${props.pageName}_${localDateStamp()}`)
    .replace(/\.xlsx$/i, '')
  return `${base}.xlsx`
})
const taskStatus = computed(() => String(task.value?.status || '').toLowerCase())
const taskReady = computed(() => ['success', 'succeeded', 'completed', 'ready'].includes(taskStatus.value))
const taskFailed = computed(() => ['failed', 'expired', 'cancelled'].includes(taskStatus.value))
const canClose = computed(() => !isCreating.value)
const safeDownloadUrl = computed(() => {
  const raw = String(task.value?.downloadUrl || '')
  if (!raw || typeof window === 'undefined') return ''
  try {
    const url = new URL(raw, window.location.origin)
    if (!['http:', 'https:'].includes(url.protocol) || url.origin !== window.location.origin) return ''
    return url.href
  } catch {
    return ''
  }
})

watch(() => props.visibleFieldKeys, (keys) => {
  const available = new Set(fieldOptions.value.map((field) => field.key))
  selectedFields.value = (Array.isArray(keys) ? keys : []).filter((key) => available.has(key))
  if (!selectedFields.value.length) selectedFields.value = fieldOptions.value.map((field) => field.key)
}, { immediate: true })

watch(() => props.pageName, (name) => {
  if (!fileName.value) fileName.value = `${name || '查询结果'}_${localDateStamp('-')}`
}, { immediate: true })

watch([allowCurrentQuery, allowCurrentPage], ([queryAllowed, pageAllowed]) => {
  if (exportScope.value === 'query' && !queryAllowed && pageAllowed) exportScope.value = 'page'
  if (exportScope.value === 'page' && !pageAllowed && queryAllowed) exportScope.value = 'query'
}, { immediate: true })

watch(task, (value) => emit('task-change', value ? { ...value } : null), { deep: true })

function toggleField(key) {
  const index = selectedFields.value.indexOf(key)
  if (index === -1) selectedFields.value.push(key)
  else if (selectedFields.value.length > 1) selectedFields.value.splice(index, 1)
  error.value = ''
}

function toggleAll() {
  selectedFields.value = isAllSelected.value
    ? fieldOptions.value.slice(0, 1).map((field) => field.key)
    : fieldOptions.value.map((field) => field.key)
}

function moveField(index, direction) {
  const next = index + direction
  if (next < 0 || next >= selectedFields.value.length) return
  const copy = [...selectedFields.value]
  const [key] = copy.splice(index, 1)
  copy.splice(next, 0, key)
  selectedFields.value = copy
}

function normalizedTaskResult(result) {
  const raw = result?.raw || result
  return {
    status: result?.status || unifiedQueryActionStatus(raw),
    message: result?.message || unifiedQueryActionMessage(raw),
    idempotencyKey: result?.idempotencyKey || unifiedQueryActionIdempotencyKey(raw),
    task: result?.task?.id ? result.task : extractUnifiedQueryExportTask(raw)
  }
}

function clearPollTimer() {
  if (pollTimer) window.clearTimeout(pollTimer)
  pollTimer = null
}

function schedulePoll() {
  clearPollTimer()
  if (!props.onQueryTask
    || (!task.value?.id && !task.value?.originalIdempotencyKey)
    || taskReady.value
    || taskFailed.value) return
  if (pollAttempts >= 60) {
    error.value = '导出任务处理时间较长，请点击“刷新状态”继续查询。'
    return
  }
  pollTimer = window.setTimeout(() => refreshTask(true), 2000)
}

async function refreshTask(fromPoll = false) {
  if (!props.onQueryTask || (!task.value?.id && !task.value?.originalIdempotencyKey) || isPolling.value) return
  isPolling.value = true
  if (fromPoll) pollAttempts += 1
  try {
    const result = normalizedTaskResult(await props.onQueryTask({
      taskId: task.value.id,
      originalIdempotencyKey: task.value.originalIdempotencyKey
    }))
    if (result.status !== 'success') {
      error.value = result.message || '导出任务状态查询失败。'
      if (result.status === 'result_unknown') schedulePoll()
      return
    }
    task.value = {
      ...task.value,
      ...result.task,
      originalIdempotencyKey: result.idempotencyKey || task.value.originalIdempotencyKey
    }
    error.value = taskFailed.value ? (task.value.failureReason || '导出任务未完成。') : ''
    schedulePoll()
  } catch (requestError) {
    if (!fromPoll) error.value = requestError?.message || '导出任务状态查询失败。'
    schedulePoll()
  } finally {
    isPolling.value = false
  }
}

function requestClose() {
  if (!canClose.value) {
    error.value = '正在创建导出任务，请稍后再关闭。'
    return
  }
  emit('close')
}

async function createExport() {
  if (isCreating.value) return
  if (!selectedFields.value.length) {
    error.value = '至少选择一个导出字段。'
    return
  }
  if ((exportScope.value === 'query' && !allowCurrentQuery.value) || (exportScope.value === 'page' && !allowCurrentPage.value)) {
    error.value = '当前导出范围未开放。'
    return
  }
  const configuration = {
    scope: exportScope.value,
    fields: [...selectedFields.value],
    includeSummary: allowSummary.value && includeSummary.value,
    fileName: generatedFileName.value
  }
  error.value = ''
  if (props.previewMode && !props.onCreate) {
    task.value = { id: 'preview-task', status: 'ready', fileName: generatedFileName.value, rowCount: exportCount.value }
    emit('export', configuration)
    return
  }
  if (!props.onCreate) {
    error.value = '导出服务尚未接入。'
    return
  }
  isCreating.value = true
  try {
    const result = normalizedTaskResult(await props.onCreate(configuration))
    if (result.status === 'result_unknown' && result.idempotencyKey) {
      task.value = {
        id: null,
        status: 'result_unknown',
        fileName: generatedFileName.value,
        rowCount: exportCount.value,
        originalIdempotencyKey: result.idempotencyKey
      }
      error.value = result.message || '导出任务创建结果未知，正在查询原请求结果。'
      pollAttempts = 0
      schedulePoll()
      return
    }
    if (result.status !== 'success' || !result.task?.id) {
      error.value = result.message || '导出任务创建失败。'
      return
    }
    task.value = { ...result.task, originalIdempotencyKey: result.idempotencyKey }
    emit('export', configuration)
    pollAttempts = 0
    schedulePoll()
  } catch (requestError) {
    error.value = requestError?.message || '导出任务创建失败。'
  } finally {
    isCreating.value = false
  }
}

function returnToForm() {
  clearPollTimer()
  task.value = null
  error.value = ''
}

function fallbackDownload(url, fileName) {
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = fileName || '查询结果.xlsx'
  anchor.rel = 'noopener'
  document.body.appendChild(anchor)
  anchor.click()
  anchor.remove()
}

async function downloadFile() {
  if (!taskReady.value || !safeDownloadUrl.value || isDownloading.value) return
  isDownloading.value = true
  error.value = ''
  try {
    if (props.onDownload) {
      await props.onDownload({
        url: safeDownloadUrl.value,
        fileName: String(task.value?.fileName || generatedFileName.value),
        task: task.value ? { ...task.value } : null
      })
    } else {
      fallbackDownload(safeDownloadUrl.value, String(task.value?.fileName || generatedFileName.value))
    }
  } catch (downloadError) {
    error.value = downloadError?.message || '导出文件下载失败，请稍后重试。'
  } finally {
    isDownloading.value = false
  }
}

onMounted(() => {
  if (task.value && !taskReady.value && !taskFailed.value) schedulePoll()
})
onBeforeUnmount(clearPollTimer)
</script>

<template>
  <Teleport to="body">
    <div class="query-export-layer" role="dialog" aria-modal="true" aria-label="导出查询结果">
      <div class="query-export-layer__backdrop" @click="requestClose" />
      <section class="query-export-panel">
        <header class="query-export-header">
          <div class="query-export-header__title"><span><FileSpreadsheet :size="20" /></span><div><h2>导出查询结果</h2><p>{{ pageName }}</p></div></div>
          <button type="button" class="query-export-icon-button" title="关闭" aria-label="关闭" :disabled="!canClose" @click="requestClose"><X :size="19" /></button>
        </header>

        <template v-if="!task">
          <main class="query-export-body">
            <section class="query-export-section">
              <h3>导出范围</h3>
              <div class="query-export-scope">
                <button v-if="allowCurrentQuery" type="button" :class="{ active: exportScope === 'query' }" @click="exportScope = 'query'"><strong>当前查询结果</strong><span>{{ resultCount }} 条</span></button>
                <button v-if="allowCurrentPage" type="button" :class="{ active: exportScope === 'page' }" @click="exportScope = 'page'"><strong>当前页</strong><span>{{ currentPageCount }} 条</span></button>
              </div>
            </section>

            <section class="query-export-section query-export-fields">
              <div class="query-export-section__header"><div><h3>导出字段</h3><span>已选 {{ selectedFields.length }} 项</span></div><button type="button" class="button button--text" @click="toggleAll">{{ isAllSelected ? '仅保留一项' : '选择全部' }}</button></div>
              <div class="query-export-field-grid">
                <label v-for="field in fieldOptions" :key="field.key" :class="{ selected: selectedFields.includes(field.key) }"><input type="checkbox" :checked="selectedFields.includes(field.key)" @change="toggleField(field.key)"><span>{{ field.label }}</span></label>
              </div>
            </section>

            <section class="query-export-section">
              <h3>表头顺序</h3>
              <div class="query-export-order">
                <div v-for="(field, index) in selectedFieldRecords" :key="field.key"><GripVertical :size="15" /><span>{{ index + 1 }}. {{ field.label }}</span><button type="button" :disabled="index === 0" title="上移" @click="moveField(index, -1)">↑</button><button type="button" :disabled="index === selectedFieldRecords.length - 1" title="下移" @click="moveField(index, 1)">↓</button></div>
              </div>
            </section>

            <section class="query-export-section query-export-options">
              <label><span>文件名称</span><div><input v-model="fileName" maxlength="60"><strong>.xlsx</strong></div></label>
              <label v-if="allowSummary" class="query-export-summary"><input v-model="includeSummary" type="checkbox"><span>包含合计行</span></label>
            </section>

            <div class="query-export-meta"><span>预计导出 <strong>{{ exportCount }}</strong> 条</span><span>数据更新至 <strong>{{ dataAsOf || '暂未返回' }}</strong></span><span>格式 <strong>Excel</strong></span></div>
          </main>
          <footer class="query-export-footer"><span v-if="error" class="query-export-error">{{ error }}</span><button type="button" class="button button--secondary" :disabled="!canClose" @click="requestClose">取消</button><button type="button" class="button button--primary" :disabled="isCreating || (!allowCurrentQuery && !allowCurrentPage)" @click="createExport"><Download :size="16" />{{ isCreating ? '正在创建…' : '创建导出任务' }}</button></footer>
        </template>

        <main v-else class="query-export-result">
          <span class="query-export-result__icon" :class="{ 'query-export-result__icon--pending': !taskReady }"><CircleCheck v-if="taskReady" :size="34" /><Download v-else :size="30" /></span>
          <h3>{{ taskReady ? '导出文件已生成' : (taskFailed ? '导出任务未完成' : '导出任务处理中') }}</h3>
          <div class="query-export-result__file"><FileSpreadsheet :size="22" /><div><strong>{{ task.fileName || '导出文件' }}</strong><span>{{ task.rowCount ?? '—' }} 条 · {{ frozenTaskFieldCount }} 个字段</span></div></div>
          <div v-if="frozenTaskFields.length" class="query-export-result__headers"><span>Excel 表头</span><strong v-for="field in frozenTaskFields" :key="field.key">{{ field.label }}</strong></div>
          <p v-else class="query-export-result__frozen-warning">该导出任务未返回冻结表头，无法确认文件列，请刷新任务状态或重新创建导出。</p>
          <span v-if="error" class="query-export-error">{{ error }}</span>
          <div class="query-export-result__actions"><button type="button" class="button button--secondary" @click="returnToForm">返回修改</button><button v-if="!taskReady && !taskFailed" type="button" class="button button--primary" :disabled="isPolling" @click="refreshTask(false)">{{ isPolling ? '正在刷新…' : '刷新状态' }}</button><button v-else-if="taskReady && safeDownloadUrl" type="button" class="button button--primary" :disabled="isDownloading" @click="downloadFile"><Download :size="16" />{{ isDownloading ? '正在下载…' : '下载文件' }}</button><button v-else type="button" class="button button--primary" disabled><Download :size="16" />下载文件</button></div>
        </main>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.query-export-layer{position:fixed;z-index:120;inset:0}.query-export-layer__backdrop{position:absolute;inset:0;background:rgba(21,32,50,.48)}
.query-export-panel{position:relative;display:grid;grid-template-rows:auto minmax(0,1fr) auto;width:min(920px,calc(100vw - 56px));height:min(800px,calc(100vh - 40px));margin:20px auto;overflow:hidden;border:1px solid #dce3eb;border-radius:12px;background:#fff;box-shadow:0 24px 70px rgba(15,23,42,.28)}
.query-export-header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #e2e7ee}.query-export-header__title{display:flex;align-items:center;gap:11px}.query-export-header__title>span{display:grid;width:38px;height:38px;place-items:center;border-radius:8px;background:#eaf8f0;color:#18864b}.query-export-header h2{margin:0;color:#1f2937;font-size:18px}.query-export-header p{margin:3px 0 0;color:#8a94a3;font-size:12px}.query-export-icon-button{display:grid;width:36px;height:36px;place-items:center;border:1px solid #dbe2ea;border-radius:8px;background:#fff;color:#596579}
.query-export-body{display:grid;align-content:start;gap:14px;min-height:0;padding:18px 20px;overflow:auto;background:#f8fafc}.query-export-section{padding:15px;border:1px solid #dfe5ec;border-radius:8px;background:#fff}.query-export-section h3{margin:0;color:#2d3948;font-size:14px}.query-export-scope{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:12px}.query-export-scope button{display:flex;min-height:54px;align-items:center;justify-content:space-between;padding:0 14px;border:1px solid #d9e1ea;border-radius:8px;background:#fff;color:#596579}.query-export-scope button.active{border-color:#69b1ff;background:#f0f7ff;box-shadow:inset 3px 0 #1677cc}.query-export-scope strong{color:#2d3948;font-size:13px}.query-export-scope span{font-size:12px}.query-export-section__header{display:flex;align-items:center;justify-content:space-between}.query-export-section__header>div{display:flex;align-items:baseline;gap:9px}.query-export-section__header span{color:#87919f;font-size:12px}.query-export-field-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-top:12px}.query-export-field-grid label{display:flex;min-width:0;height:36px;align-items:center;gap:7px;padding:0 9px;border:1px solid #e0e5eb;border-radius:7px;background:#fff;color:#596579;font-size:12px}.query-export-field-grid label.selected{border-color:#b8d8f7;background:#f4f9ff;color:#2168a7}.query-export-field-grid input{accent-color:#1677cc}.query-export-field-grid span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.query-export-order{display:flex;gap:7px;margin-top:12px;overflow:auto;padding-bottom:2px}.query-export-order>div{display:flex;flex:none;height:34px;align-items:center;gap:5px;padding:0 7px;border:1px solid #dce3eb;border-radius:7px;background:#fafbfd;color:#607084;font-size:12px}.query-export-order button{width:24px;height:24px;padding:0;border:0;border-radius:5px;background:#edf1f5;color:#596579}.query-export-order button:disabled{opacity:.38}.query-export-options{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:end;gap:22px}.query-export-options>label:first-child{display:grid;gap:7px;color:#4d5969;font-size:13px}.query-export-options>label:first-child>div{display:flex;height:38px;align-items:center;border:1px solid #d5dde7;border-radius:8px;background:#fff;overflow:hidden}.query-export-options input:not([type]){min-width:0;height:100%;flex:1;padding:0 11px;border:0;outline:0}.query-export-options strong{padding:0 10px;color:#87919f;font-size:12px}.query-export-summary{display:flex;height:38px;align-items:center;gap:7px;color:#4d5969;font-size:13px}.query-export-summary input{accent-color:#1677cc}.query-export-meta{display:flex;flex-wrap:wrap;gap:18px;padding:0 3px;color:#7a8696;font-size:12px}.query-export-meta strong{color:#425066}.query-export-footer{display:flex;align-items:center;justify-content:flex-end;gap:9px;padding:13px 20px;border-top:1px solid #e2e7ee}.query-export-footer .button,.query-export-result__actions .button{display:inline-flex;align-items:center;gap:6px}.query-export-error{margin-right:auto;color:#cf1322;font-size:12px}
.query-export-result{display:grid;align-content:center;justify-items:center;gap:13px;min-height:0;padding:24px}.query-export-result__icon{display:grid;width:64px;height:64px;place-items:center;border-radius:50%;background:#eaf8f0;color:#18864b}.query-export-result h3{margin:0;color:#263445;font-size:19px}.query-export-result__file{display:flex;width:min(560px,100%);align-items:center;gap:11px;padding:13px 15px;border:1px solid #dce3eb;border-radius:8px;background:#fafbfd;color:#238456}.query-export-result__file div{display:grid;min-width:0;gap:4px}.query-export-result__file strong{overflow:hidden;color:#2d3948;font-size:13px;text-overflow:ellipsis;white-space:nowrap}.query-export-result__file span{color:#83909f;font-size:12px}.query-export-result__headers{display:flex;width:min(680px,100%);flex-wrap:wrap;align-items:center;gap:7px;padding:12px;border:1px solid #dce3eb;border-radius:8px}.query-export-result__headers span{color:#7d8897;font-size:12px}.query-export-result__headers strong{padding:5px 7px;border-radius:5px;background:#eef5fc;color:#38668f;font-size:11px}.query-export-result__frozen-warning{width:min(680px,100%);margin:0;padding:10px 12px;border:1px solid #ffe58f;border-radius:8px;background:#fffbe6;color:#ad6800;font-size:12px;line-height:1.55}.query-export-result__actions{display:flex;gap:9px;margin-top:5px}
.query-export-result__icon--pending{background:#eef5fc;color:#236aa8}.query-export-result__actions a{text-decoration:none}
@media(max-width:760px){.query-export-panel{width:calc(100vw - 20px);height:calc(100vh - 20px);margin:10px auto}.query-export-field-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.query-export-options{grid-template-columns:1fr}.query-export-scope{grid-template-columns:1fr}}
</style>

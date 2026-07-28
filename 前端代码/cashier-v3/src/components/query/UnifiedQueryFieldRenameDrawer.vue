<script setup>
import { computed, reactive, ref, watch } from 'vue'
import PencilLine from '@lucide/vue/dist/esm/icons/pencil-line.mjs'
import RotateCcw from '@lucide/vue/dist/esm/icons/rotate-ccw.mjs'
import Save from '@lucide/vue/dist/esm/icons/save.mjs'
import Search from '@lucide/vue/dist/esm/icons/search.mjs'
import X from '@lucide/vue/dist/esm/icons/x.mjs'
import {
  unifiedQueryActionIdempotencyKey,
  unifiedQueryActionMessage,
  unifiedQueryActionStatus
} from '@/services/unifiedQueryContract'

const props = defineProps({
  fields: { type: Array, default: () => [] },
  aliases: { type: Object, default: () => ({}) },
  pageName: { type: String, default: '当前查询页面' },
  previewMode: { type: Boolean, default: false },
  onSave: { type: Function, default: null }
})

const emit = defineEmits(['close', 'save'])
const activeView = ref('all')
const keyword = ref('')
const draft = reactive({})
const errors = reactive({})
const toast = ref('')
const isSaving = ref(false)
const pendingIdempotencyKey = ref('')
const hasPendingSave = computed(() => isSaving.value || Boolean(pendingIdempotencyKey.value))

const fieldOptions = computed(() => props.fields.filter((field) => !field.hidden && field.key !== 'id'))
const changedCount = computed(() => fieldOptions.value.filter((field) => normalizeAlias(draft[field.key])).length)
const filteredFields = computed(() => {
  const query = keyword.value.trim().toLowerCase()
  return fieldOptions.value.filter((field) => {
    const alias = normalizeAlias(draft[field.key])
    if (activeView.value === 'changed' && !alias) return false
    return !query || String(field.label || '').toLowerCase().includes(query) || alias.toLowerCase().includes(query)
  })
})
const previewFields = computed(() => fieldOptions.value.filter((field) => normalizeAlias(draft[field.key])).slice(0, 3))

watch(() => props.aliases, loadAliases, { deep: true, immediate: true })

function normalizeAlias(value) {
  return String(value || '').trim()
}

function loadAliases(source = {}) {
  fieldOptions.value.forEach((field) => {
    draft[field.key] = normalizeAlias(source[field.key])
    errors[field.key] = ''
  })
  toast.value = ''
  pendingIdempotencyKey.value = ''
}

function resetField(fieldKey) {
  draft[fieldKey] = ''
  errors[fieldKey] = ''
  toast.value = ''
}

function resetAll() {
  fieldOptions.value.forEach((field) => resetField(field.key))
}

function validate() {
  const names = new Map()
  let valid = true
  fieldOptions.value.forEach((field) => {
    const displayName = normalizeAlias(draft[field.key]) || String(field.label || '').trim()
    const normalized = displayName.toLocaleLowerCase('zh-CN')
    errors[field.key] = ''
    if (displayName.length > 20) {
      errors[field.key] = '显示名称最多 20 个字。'
      valid = false
      return
    }
    if (names.has(normalized)) {
      errors[field.key] = `与“${names.get(normalized)}”重名。`
      valid = false
      return
    }
    names.set(normalized, displayName)
  })
  return valid
}

async function saveAliases() {
  if (isSaving.value) return
  toast.value = ''
  if (!validate()) {
    toast.value = '请先处理重名或过长的显示名称。'
    return
  }
  const result = {}
  fieldOptions.value.forEach((field) => {
    const alias = normalizeAlias(draft[field.key])
    if (alias && alias !== field.label) result[field.key] = alias
  })
  if (props.previewMode && !props.onSave) {
    toast.value = '确认稿已在本页应用显示名称。'
    emit('save', result)
    return
  }
  isSaving.value = true
  try {
    const response = props.onSave ? await props.onSave(result, { idempotencyKey: pendingIdempotencyKey.value }) : null
    if (!props.onSave) emit('save', result)
    const status = unifiedQueryActionStatus(response)
    if (props.onSave && status === 'result_unknown') {
      pendingIdempotencyKey.value = unifiedQueryActionIdempotencyKey(response)
      toast.value = unifiedQueryActionMessage(response, '保存结果未知，请按原请求重试。')
      return
    }
    if (props.onSave && status !== 'success') {
      pendingIdempotencyKey.value = ''
      toast.value = unifiedQueryActionMessage(response, '字段名称保存失败，请检查后重试。')
      return
    }
    pendingIdempotencyKey.value = ''
    emit('save', result)
    emit('close')
  } catch (error) {
    toast.value = error?.message || '字段名称保存失败，请检查网络后重试。'
  } finally {
    isSaving.value = false
  }
}

function requestClose() {
  if (hasPendingSave.value) {
    toast.value = '保存结果尚未确认，请先按原请求重试。'
    return
  }
  emit('close')
}
</script>

<template>
  <Teleport to="body">
    <div class="field-rename-layer" role="dialog" aria-modal="true" aria-label="字段改名">
      <div class="field-rename-layer__backdrop" @click="requestClose" />
      <section class="field-rename-panel">
        <header class="field-rename-header">
          <div class="field-rename-header__title">
            <span><PencilLine :size="20" /></span>
            <div><h2>字段改名</h2><p>{{ pageName }}</p></div>
          </div>
          <button type="button" class="field-rename-icon-button" title="关闭" aria-label="关闭" :disabled="hasPendingSave" @click="requestClose"><X :size="19" /></button>
        </header>

        <div class="field-rename-toolbar">
          <div class="field-rename-segmented" role="tablist" aria-label="字段范围">
            <button type="button" :class="{ active: activeView === 'all' }" @click="activeView = 'all'">全部字段</button>
            <button type="button" :class="{ active: activeView === 'changed' }" @click="activeView = 'changed'">已改名 {{ changedCount }}</button>
          </div>
          <label class="field-rename-search"><Search :size="16" /><input v-model="keyword" type="search" placeholder="搜索原名称或显示名称"></label>
        </div>

        <main class="field-rename-body">
          <div class="field-rename-table__header"><span>原名称</span><span>显示名称</span><span>操作</span></div>
          <div class="field-rename-table">
            <div v-for="field in filteredFields" :key="field.key" class="field-rename-row" :class="{ changed: normalizeAlias(draft[field.key]) }">
              <div class="field-rename-original"><strong>{{ field.label }}</strong><small>{{ field.custom ? '自定义字段' : '系统字段' }}</small></div>
              <label>
                <input v-model="draft[field.key]" maxlength="20" :placeholder="field.label" :disabled="isSaving || Boolean(pendingIdempotencyKey)" @input="errors[field.key] = ''; toast = ''">
                <span v-if="errors[field.key]" class="field-rename-error">{{ errors[field.key] }}</span>
              </label>
              <button type="button" class="field-rename-reset" :disabled="!normalizeAlias(draft[field.key]) || isSaving || Boolean(pendingIdempotencyKey)" :title="`恢复${field.label}`" @click="resetField(field.key)"><RotateCcw :size="16" /><span>恢复原名</span></button>
            </div>
            <div v-if="!filteredFields.length" class="field-rename-empty">没有符合条件的字段</div>
          </div>

          <section v-if="previewFields.length" class="field-rename-preview">
            <h3>显示预览</h3>
            <div class="field-rename-preview__groups">
              <div><span>列表列名</span><strong v-for="field in previewFields" :key="`list-${field.key}`">{{ normalizeAlias(draft[field.key]) }}</strong></div>
              <div><span>筛选字段</span><strong v-for="field in previewFields" :key="`filter-${field.key}`">{{ normalizeAlias(draft[field.key]) }}</strong></div>
            </div>
          </section>
        </main>

        <footer class="field-rename-footer">
          <button type="button" class="button button--text" :disabled="changedCount === 0 || isSaving || Boolean(pendingIdempotencyKey)" @click="resetAll">恢复全部原名</button>
          <span v-if="toast" class="field-rename-toast">{{ toast }}</span>
          <button type="button" class="button button--secondary" :disabled="hasPendingSave" @click="requestClose">取消</button>
          <button type="button" class="button button--primary" :disabled="isSaving" @click="saveAliases"><Save :size="16" />{{ isSaving ? '正在保存…' : (pendingIdempotencyKey ? '按原请求重试' : '保存名称') }}</button>
        </footer>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.field-rename-layer{position:fixed;z-index:120;inset:0}.field-rename-layer__backdrop{position:absolute;inset:0;background:rgba(21,32,50,.48)}
.field-rename-panel{position:relative;display:grid;grid-template-rows:auto auto minmax(0,1fr) auto;width:min(900px,calc(100vw - 56px));height:min(760px,calc(100vh - 40px));margin:20px auto;overflow:hidden;border:1px solid #dce3eb;border-radius:12px;background:#fff;box-shadow:0 24px 70px rgba(15,23,42,.28)}
.field-rename-header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #e2e7ee}.field-rename-header__title{display:flex;align-items:center;gap:11px}.field-rename-header__title>span{display:grid;width:38px;height:38px;place-items:center;border-radius:8px;background:#eaf4ff;color:#1677cc}.field-rename-header h2{margin:0;color:#1f2937;font-size:18px}.field-rename-header p{margin:3px 0 0;color:#8a94a3;font-size:12px}.field-rename-icon-button{display:grid;width:36px;height:36px;place-items:center;border:1px solid #dbe2ea;border-radius:8px;background:#fff;color:#596579}
.field-rename-toolbar{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:13px 20px;border-bottom:1px solid #e7ebf0;background:#fafbfd}.field-rename-segmented{display:grid;grid-template-columns:repeat(2,1fr);padding:3px;border:1px solid #dce3eb;border-radius:8px;background:#fff}.field-rename-segmented button{height:30px;padding:0 14px;border:0;border-radius:6px;background:transparent;color:#667085}.field-rename-segmented button.active{background:#eaf4ff;color:#1677cc;font-weight:600}.field-rename-search{display:flex;width:min(310px,42vw);height:36px;align-items:center;gap:8px;padding:0 10px;border:1px solid #d7dfe8;border-radius:8px;background:#fff;color:#8993a2}.field-rename-search input{min-width:0;flex:1;border:0;outline:0;background:transparent;color:#273444;font-size:13px}
.field-rename-body{min-height:0;padding:0 20px 18px;overflow:auto}.field-rename-table__header,.field-rename-row{display:grid;grid-template-columns:minmax(150px,.75fr) minmax(240px,1.25fr) 104px;gap:18px;align-items:start}.field-rename-table__header{position:sticky;z-index:1;top:0;padding:12px 12px 9px;border-bottom:1px solid #dfe5ec;background:#fff;color:#7b8796;font-size:12px}.field-rename-table{display:grid}.field-rename-row{min-height:66px;padding:11px 12px;border-bottom:1px solid #edf0f4}.field-rename-row.changed{background:#f7fbff}.field-rename-original{display:grid;gap:3px;min-width:0;padding-top:2px}.field-rename-original strong{overflow:hidden;color:#2d3948;font-size:13px;text-overflow:ellipsis;white-space:nowrap}.field-rename-original small{overflow:hidden;color:#98a1ad;font-size:10px;text-overflow:ellipsis;white-space:nowrap}.field-rename-row label{display:grid;gap:3px}.field-rename-row input{width:100%;height:36px;padding:0 10px;border:1px solid #d5dde7;border-radius:8px;background:#fff;color:#273444;font-size:13px}.field-rename-row input:focus{border-color:#69b1ff;outline:2px solid #e6f4ff}.field-rename-error{color:#cf1322;font-size:11px}.field-rename-reset{display:flex;height:34px;align-items:center;justify-content:center;gap:5px;border:0;background:transparent;color:#58708b;font-size:12px}.field-rename-reset:disabled{opacity:.38}.field-rename-empty{padding:50px 0;text-align:center;color:#9aa3af;font-size:13px}
.field-rename-preview{margin:18px 0 0;padding:14px;border:1px solid #dce3eb;border-radius:8px;background:#fafbfd}.field-rename-preview h3{margin:0 0 11px;color:#2e3a49;font-size:13px}.field-rename-preview__groups{display:grid;grid-template-columns:1fr 1fr;gap:12px}.field-rename-preview__groups>div{display:flex;min-width:0;align-items:center;gap:7px;overflow:hidden}.field-rename-preview__groups span{flex:none;color:#7c8796;font-size:12px}.field-rename-preview__groups strong{min-width:0;padding:5px 8px;overflow:hidden;border:1px solid #cbe1f8;border-radius:5px;background:#f0f7ff;color:#2168a7;font-size:12px;text-overflow:ellipsis;white-space:nowrap}
.field-rename-footer{display:flex;align-items:center;justify-content:flex-end;gap:9px;padding:13px 20px;border-top:1px solid #e2e7ee}.field-rename-footer .button{display:inline-flex;align-items:center;gap:6px}.field-rename-footer>.button:first-child{margin-right:auto}.field-rename-toast{color:#a35c00;font-size:12px}
@media(max-width:720px){.field-rename-panel{width:calc(100vw - 20px);height:calc(100vh - 20px);margin:10px auto}.field-rename-toolbar{align-items:stretch;flex-direction:column}.field-rename-search{width:100%}.field-rename-table__header,.field-rename-row{grid-template-columns:minmax(120px,.8fr) minmax(180px,1.2fr) 38px;gap:9px}.field-rename-reset span{display:none}.field-rename-preview__groups{grid-template-columns:1fr}.field-rename-footer>.button:first-child{display:none}}
</style>

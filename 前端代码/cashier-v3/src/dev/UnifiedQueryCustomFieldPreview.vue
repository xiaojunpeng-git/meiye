<script setup>
import { computed, ref } from 'vue'
import UnifiedQueryCustomFieldDrawer from '@/components/query/UnifiedQueryCustomFieldDrawer.vue'
import UnifiedQueryExportDrawer from '@/components/query/UnifiedQueryExportDrawer.vue'
import UnifiedQueryFieldRenameDrawer from '@/components/query/UnifiedQueryFieldRenameDrawer.vue'
import UnifiedQuerySettingsDrawer from '@/components/query/UnifiedQuerySettingsDrawer.vue'
import Download from '@lucide/vue/dist/esm/icons/download.mjs'

const isSettingsOpen = ref(true)
const isCustomFieldOpen = ref(false)
const isFieldRenameOpen = ref(false)
const isExportOpen = ref(false)
const previewDataScope = ref('normal')
const previewBusinessStatus = ref('')
const previewCustomFieldVersions = ref({ cf_8a7f42c1d9e5b0367fa2: 2 })
const previewInvalidReferences = ref([{
  fieldKey: 'cf_8a7f42c1d9e5b0367fa2',
  version: 2,
  status: 'upgrade_available',
  reason: '“会员价值”已有版本 3，可确认后升级到最新版本。'
}])

const fields = [
  { key: 'member_name', label: '会员姓名', type: 'text', defaultVisible: true, defaultQuick: true },
  { key: 'phone', label: '完整手机号', type: 'text', defaultVisible: true, defaultQuick: true },
  { key: 'member_level', label: '会员等级', type: 'text', defaultVisible: true },
  { key: 'account_balance', label: '账户余额', type: 'amount', defaultVisible: true },
  { key: 'total_consumption_amount', label: '总消费金额', type: 'amount', defaultVisible: true },
  { key: 'visit_count', label: '到店次数', type: 'integer', defaultVisible: true },
  { key: 'latest_visit_date', label: '最近到店日期', type: 'date', defaultVisible: true },
  { key: 'created_at', label: '建档时间', type: 'date', defaultVisible: true }
]

const fieldAliases = ref({
  phone: '联系电话',
  account_balance: '储值余额'
})
const displayFields = computed(() => fields.map((field) => ({
  ...field,
  label: fieldAliases.value[field.key] || field.label
})))
const settingsFields = computed(() => ([
  ...displayFields.value,
  {
    key: 'cf_8a7f42c1d9e5b0367fa2',
    label: '会员价值',
    type: 'amount',
    custom: true,
    version: 3,
    defaultVisible: false
  }
]))
const previewColumns = [
  { key: 'member_name', fallback: '会员姓名' },
  { key: 'phone', fallback: '完整手机号' },
  { key: 'member_level', fallback: '会员等级' },
  { key: 'account_balance', fallback: '账户余额' },
  { key: 'total_consumption_amount', fallback: '总消费金额' },
  { key: 'visit_count', fallback: '到店次数' },
  { key: 'latest_visit_date', fallback: '最近到店日期' }
]
const previewColumnLabels = computed(() => previewColumns.map((column) => fieldAliases.value[column.key] || column.fallback))

function applyAliases(aliases) {
  fieldAliases.value = { ...aliases }
  isFieldRenameOpen.value = false
}

async function upgradePreviewReference(reference) {
  const fieldKey = String(reference?.fieldKey || reference?.field_key || '')
  if (fieldKey !== 'cf_8a7f42c1d9e5b0367fa2') {
    return { result: { status: 'failed', code: 'PREVIEW_REFERENCE_NOT_FOUND', message: '确认稿没有该字段引用。' } }
  }
  // 仅确认稿的内存态演示：不模拟生产保存、不写接口或数据库。生产由后端在事务中
  // 解析真实当前版本，浏览器不提交任何版本号。
  previewCustomFieldVersions.value = { ...previewCustomFieldVersions.value, [fieldKey]: 3 }
  previewInvalidReferences.value = previewInvalidReferences.value.filter((item) => item.fieldKey !== fieldKey)
  return { result: { status: 'success', code: '', message: '已升级到字段最新版本。' } }
}

const customFields = [
  { id: 'cf-member-value', key: 'cf_8a7f42c1d9e5b0367fa2', code: 'CF-8A7F', name: '会员价值', returnType: 'amount', returnTypeLabel: '金额', visibility: 'shared', shareScope: 'store', version: 3, status: 'active', rule: { leftField: 'total_consumption_amount', operator: 'add', rightMode: 'field', rightField: 'account_balance', nullMode: 'empty' }, history: [{ version: 3, date: '2026-07-28 16:20', author: '门店管理员', note: '金额精度调整为统一金额口径' }, { version: 2, date: '2026-07-28 15:42', author: '门店管理员', note: '共享至当前门店' }, { version: 1, date: '2026-07-28 15:30', author: '门店管理员', note: '创建字段' }] },
  { id: 'cf-visit-gap', key: 'cf_5c2184d0af3e7b91c2d4', code: 'CF-5C21', name: '距最近到店天数', returnType: 'integer', returnTypeLabel: '整数', visibility: 'personal', version: 1, status: 'active', rule: { leftField: 'latest_visit_date', operator: 'date_diff', rightMode: 'query_cutoff', nullMode: 'empty' } },
  { id: 'cf-service-gap', key: 'cf_19d2c71ef40a6b8d35c9', code: 'CF-19D2', name: '服务间隔提示', returnType: 'text', returnTypeLabel: '文本', visibility: 'shared', version: 2, status: 'invalid', statusLabel: '引用失效', invalidReason: '引用字段“旧服务日期”已停用，请编辑规则并选择新的日期字段。', rule: { leftField: 'latest_visit_date', operator: 'date_diff', rightMode: 'query_cutoff', nullMode: 'empty' } }
]

const rows = [
  ['林女士', '138 0721 6688', '金卡', '¥2,860.00', '¥18,420.00', '16', '2026-07-26'],
  ['周女士', '186 1120 3921', '银卡', '¥680.00', '¥6,980.00', '7', '2026-07-22'],
  ['陈先生', '139 6630 8127', '普通会员', '¥0.00', '¥2,360.00', '3', '2026-07-19']
]
</script>

<template>
  <div class="custom-field-preview-page">
    <header><div><strong>会员列表</strong><span>当前门店：魔核美业·滨江店</span></div></header>
    <main>
      <div class="preview-query">
        <input placeholder="搜索会员姓名、完整手机号或会员编号">
        <button class="button button--primary">查询</button>
        <div class="unified-query-scope" role="group" aria-label="数据范围">
          <span class="unified-query-scope__thumb" :class="{ 'unified-query-scope__thumb--all': previewDataScope === 'all' }" aria-hidden="true" />
          <button type="button" :aria-pressed="previewDataScope === 'normal'" :class="{ 'unified-query-scope__active': previewDataScope === 'normal' }" @click="previewDataScope = 'normal'; previewBusinessStatus = ''">正常数据</button>
          <button type="button" :aria-pressed="previewDataScope === 'all'" :class="{ 'unified-query-scope__active': previewDataScope === 'all' }" @click="previewDataScope = 'all'">全部数据</button>
        </div>
        <select v-if="previewDataScope === 'all'" v-model="previewBusinessStatus" class="unified-query-status" aria-label="其他状态">
          <option value="">其他状态</option>
          <option value="active">正常</option>
          <option v-if="previewDataScope === 'all'" value="disabled">已停用</option>
          <option v-if="previewDataScope === 'all'" value="cancelled">已注销</option>
        </select>
        <button class="button button--secondary preview-export-button" @click="isExportOpen = true"><Download :size="16" />导出</button>
        <button class="button button--secondary" @click="isSettingsOpen = true">查询设置</button>
      </div>
      <div class="preview-table"><table><thead><tr><th v-for="label in previewColumnLabels" :key="label">{{ label }}</th><th>操作</th></tr></thead><tbody><tr v-for="row in rows" :key="row[1]"><td v-for="cell in row" :key="cell">{{ cell }}</td><td><button class="button button--text">查看</button></td></tr></tbody></table></div>
    </main>

    <UnifiedQuerySettingsDrawer
      v-if="isSettingsOpen"
      :fields="settingsFields"
      default-sort-field="建档时间"
      show-custom-fields
      show-field-rename
      :invalid-references="previewInvalidReferences"
      :on-upgrade-reference="upgradePreviewReference"
      :initial-settings="{ visibleFields: fields.slice(0, 8).map((field) => field.key).concat(['cf_8a7f42c1d9e5b0367fa2']), quickFields: ['member_name', 'phone'], sorts: [{ field: 'created_at', direction: 'desc' }], customFieldVersions: previewCustomFieldVersions }"
      @close="isSettingsOpen = false"
      @custom-fields="isCustomFieldOpen = true"
      @field-rename="isFieldRenameOpen = true"
    />
    <UnifiedQueryCustomFieldDrawer v-if="isCustomFieldOpen" :fields="displayFields" :custom-fields="customFields" initial-field-id="cf-member-value" preview-mode @close="isCustomFieldOpen = false" />
    <UnifiedQueryFieldRenameDrawer v-if="isFieldRenameOpen" :fields="fields" :aliases="fieldAliases" page-name="会员列表" preview-mode @close="isFieldRenameOpen = false" @save="applyAliases" />
    <UnifiedQueryExportDrawer v-if="isExportOpen" :fields="displayFields" :visible-field-keys="previewColumns.map((column) => column.key)" page-name="会员列表" :result-count="238" :page-size="20" data-as-of="2026-07-28 17:30" preview-mode @close="isExportOpen = false" />
  </div>
</template>

<style scoped>
.custom-field-preview-page{min-height:100vh;background:#f3f5f8;color:#273444}.custom-field-preview-page>header{display:flex;height:64px;align-items:center;justify-content:space-between;padding:0 24px;border-bottom:1px solid #dde3eb;background:#fff}.custom-field-preview-page>header div{display:grid;gap:4px}.custom-field-preview-page>header strong{font-size:18px}.custom-field-preview-page>header span{color:#8a94a3;font-size:12px}.custom-field-preview-page>main{display:grid;gap:14px;padding:18px}.preview-query{display:flex;align-items:center;gap:8px;padding:12px;border:1px solid #e1e6ed;border-radius:8px;background:#fff}.preview-query>input{width:330px;height:38px;padding:0 12px;border:1px solid #d4dce6;border-radius:7px}.preview-query .preview-export-button{display:inline-flex;align-items:center;gap:6px}.preview-table{overflow:auto;border:1px solid #e1e6ed;border-radius:8px;background:#fff}.preview-table table{width:100%;border-collapse:collapse}.preview-table th,.preview-table td{padding:13px 14px;border-bottom:1px solid #edf0f4;text-align:left;white-space:nowrap;font-size:13px}.preview-table th{background:#f7f9fc;color:#667085}
</style>

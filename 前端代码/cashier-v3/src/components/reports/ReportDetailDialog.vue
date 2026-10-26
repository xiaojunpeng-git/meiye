<script setup>
import { computed, ref } from 'vue'
import X from '@lucide/vue/dist/esm/icons/x.mjs'
import Search from '@lucide/vue/dist/esm/icons/search.mjs'
import Download from '@lucide/vue/dist/esm/icons/download.mjs'

// The parent owns the authorized query and metric-specific formatting; this
// dialog only renders the backend-declared columns and paged result.
const props = defineProps({
  title: { type: String, required: true },
  columns: { type: Array, required: true },
  records: { type: Array, required: true },
  footer: { type: String, required: true },
  emptyText: { type: String, default: '暂无明细' },
  loading: { type: Boolean, default: false },
  error: { type: String, default: '' },
  filterActive: { type: Boolean, default: false },
  exporting: { type: Boolean, default: false },
  exportError: { type: String, default: '' },
  appliedMember: { type: String, default: '' },
  appliedOrderNo: { type: String, default: '' },
  page: { type: Number, default: 1 },
  pageCount: { type: Number, default: 1 },
  formatCell: { type: Function, required: true }
})

const emit = defineEmits(['close', 'page', 'filter', 'export'])
const member = ref('')
const orderNo = ref('')
const hasPendingFilter = computed(() => member.value.trim() !== props.appliedMember || orderNo.value.trim() !== props.appliedOrderNo)

// These fields submit to the report's authorized backend query, not the current page of rows.
function submitFilter() {
  emit('filter', { member: member.value.trim(), orderNo: orderNo.value.trim() })
}

function clearFilter() {
  member.value = ''
  orderNo.value = ''
  submitFilter()
}

// Use the report's column widths so adding a dimension does not break document identities.
function columnWidth(column) {
  const width = Number(column?.width || column?.fixed_width || 140)
  return Number.isFinite(width) ? Math.min(240, Math.max(100, width)) : 140
}
const tableWidth = computed(() => Math.max(600, props.columns.reduce((total, column) => total + columnWidth(column), 0)))
</script>

<template>
  <div class="report-detail-dialog" role="dialog" aria-modal="true" :aria-label="title">
    <section class="report-detail-dialog__card" :style="{ width: `min(${Math.min(1180, tableWidth + 48)}px, 100%)` }">
      <header>
        <strong>{{ title }}</strong>
        <button type="button" class="report-detail-dialog__close" aria-label="关闭明细" title="关闭明细" @click="$emit('close')"><X :size="20" aria-hidden="true" /></button>
      </header>
      <form class="report-detail-dialog__filter" role="search" @submit.prevent="submitFilter">
        <label>会员<input v-model="member" type="search" maxlength="40" placeholder="请输入会员名称" /></label>
        <label>单号<input v-model="orderNo" type="search" maxlength="80" placeholder="请输入单据号" /></label>
        <button type="submit" class="button button--primary" :disabled="loading || exporting"><Search :size="16" aria-hidden="true" />筛选</button>
        <button type="button" class="button button--secondary" title="清空筛选" aria-label="清空筛选" :disabled="loading || exporting || (!filterActive && !member && !orderNo)" @click="clearFilter"><X :size="16" aria-hidden="true" /></button>
        <button type="button" class="button button--secondary" :title="hasPendingFilter ? '请先筛选后导出' : '导出全部匹配明细为 XLSX'" :disabled="loading || exporting || hasPendingFilter" @click="$emit('export')"><Download :size="16" aria-hidden="true" />{{ exporting ? '导出中' : '导出 XLSX' }}</button>
      </form>
      <p v-if="error" class="report-detail-dialog__error" role="alert">{{ error }}</p>
      <p v-if="exportError" class="report-detail-dialog__error" role="alert">{{ exportError }}</p>
      <div class="report-detail-dialog__scroll">
        <table :style="{ minWidth: `${tableWidth}px` }">
          <colgroup><col v-for="column in columns" :key="column.key" :style="{ width: `${columnWidth(column)}px` }" /></colgroup>
          <thead><tr><th v-for="column in columns" :key="column.key">{{ column.label }}</th></tr></thead>
          <tbody>
            <tr v-for="(record, index) in records" :key="`${record.order_no || record.visit_date || index}-${index}`">
              <td v-for="column in columns" :key="column.key">{{ formatCell(record, column) }}</td>
            </tr>
            <tr v-if="!loading && !records.length"><td :colspan="Math.max(1, columns.length)">{{ emptyText }}</td></tr>
          </tbody>
        </table>
      </div>
      <footer>
        <span>{{ loading ? '正在读取' : footer }}</span>
        <div>
          <button type="button" class="button button--secondary" :disabled="loading || page <= 1" @click="$emit('page', page - 1)">上一页</button>
          <span>{{ page }} / {{ pageCount }}</span>
          <button type="button" class="button button--secondary" :disabled="loading || page >= pageCount" @click="$emit('page', page + 1)">下一页</button>
        </div>
      </footer>
    </section>
  </div>
</template>

<style scoped>
.report-detail-dialog { position: fixed; z-index: 100; inset: 0; display: grid; place-items: center; padding: 20px; background: rgba(24, 39, 58, .38); }
.report-detail-dialog__card { display: flex; width: min(980px, 100%); max-height: min(680px, 90vh); flex-direction: column; overflow: hidden; border-radius: 8px; padding: 18px; background: #fff; box-shadow: 0 16px 50px rgba(24, 39, 58, .2); }
.report-detail-dialog__card header { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 14px; }
.report-detail-dialog__card strong { overflow-wrap: anywhere; }
.report-detail-dialog__close { border: 0; background: transparent; color: #657386; cursor: pointer; }
.report-detail-dialog__filter { display: flex; flex-wrap: wrap; align-items: end; gap: 8px; margin-bottom: 12px; }
.report-detail-dialog__filter label { display: flex; flex: 1 1 180px; min-width: 0; flex-direction: column; gap: 4px; color: #526174; font-size: 12px; }
.report-detail-dialog__filter input { width: 100%; min-width: 0; height: 34px; border: 1px solid #d8e1ec; border-radius: 4px; padding: 0 9px; color: #25364b; font-size: 13px; }
.report-detail-dialog__filter .button { display: inline-flex; height: 34px; align-items: center; justify-content: center; gap: 5px; white-space: nowrap; }
.report-detail-dialog__error { color: #b42318; }
.report-detail-dialog__scroll { min-height: 0; flex: 1; overflow: auto; }
.report-detail-dialog__scroll table { width: 100%; border-collapse: collapse; table-layout: fixed; }
.report-detail-dialog__scroll th, .report-detail-dialog__scroll td { border-bottom: 1px solid #e5eaf0; padding: 10px 12px; text-align: left; overflow-wrap: anywhere; }
.report-detail-dialog__scroll th { position: sticky; top: 0; background: #f5f8fc; color: #526174; font-size: 12px; }
.report-detail-dialog__card footer { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; border-top: 1px solid #e5eaf0; padding-top: 12px; font-size: 12px; color: #526174; }
.report-detail-dialog__card footer div { display: flex; align-items: center; gap: 8px; }
@media (max-width: 640px) { .report-detail-dialog { padding: 8px; } .report-detail-dialog__card { max-height: 94vh; padding: 12px; } }
</style>

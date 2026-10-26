<script setup>
import { ref } from 'vue'
import { X } from '@lucide/vue'

const props = defineProps({
  page: { type: String, required: true },
  detail: { type: Object, required: true },
  saving: { type: Boolean, default: false },
  error: { type: String, default: '' }
})
const emit = defineEmits(['close', 'save'])
const labels = { inbound: '入库单', outbound: '出库单', request: '请货单', transfer: '调拨单', usage: '院装单' }
const remark = ref(String(props.detail.document?.remark || ''))
// The source row ID, never a product ID or array offset, is the edit target.
const lines = ref((props.detail.lines || []).map((line) => ({
  line_id: Number(line.line_id || line.id),
  title: String(line.product_name || line.product_name_snapshot || '商品'),
  spec: String(line.sku_name || line.sku_name_snapshot || ''),
  detail_remark: String(line.detail_remark || '')
})))

function changedParts(history) {
  let oldLines = {}
  let newLines = {}
  try {
    oldLines = JSON.parse(history.previous_line_remarks_json || '{}')
    newLines = JSON.parse(history.new_line_remarks_json || '{}')
  } catch (_) { return '备注' }
  const count = Object.keys(newLines).filter((key) => String(newLines[key] || '') !== String(oldLines[key] || '')).length
  return [history.previous_remark !== history.new_remark ? '主项备注' : '', count ? `${count} 条明细备注` : ''].filter(Boolean).join('、') || '备注'
}

function save() {
  emit('save', {
    type: props.page,
    version: Number(props.detail.remark_version || 0),
    remark: remark.value,
    lines: lines.value.map((line) => ({ line_id: line.line_id, detail_remark: line.detail_remark }))
  })
}
</script>

<template>
  <Teleport to="body">
    <div class="remark-layer">
      <button class="remark-mask" aria-label="关闭备注窗口" :disabled="saving" @click="emit('close')" />
      <section class="remark-dialog" role="dialog" aria-modal="true" aria-label="编辑库存单据备注">
        <header class="remark-header">
          <div><small>{{ labels[page] }} · {{ detail.document?.order_sn || detail.document?.request_no || detail.document?.transfer_no || detail.document?.usage_no || '' }}</small><h2>编辑备注</h2></div>
          <button class="remark-icon" title="关闭" :disabled="saving" @click="emit('close')"><X :size="18" /></button>
        </header>
        <main class="remark-content">
          <label class="remark-field"><span>主项备注</span><textarea v-model="remark" maxlength="500" rows="3" placeholder="填写单据备注" /></label>
          <section class="remark-lines">
            <h3>明细备注</h3>
            <div v-for="line in lines" :key="line.line_id" class="remark-line">
              <label><span class="remark-product">{{ line.title }} <small>{{ line.spec }}</small></span><textarea v-model="line.detail_remark" maxlength="500" rows="2" placeholder="填写明细备注" /></label>
            </div>
          </section>
          <section v-if="detail.remark_history?.length" class="remark-history">
            <h3>修改记录</h3>
            <p v-for="item in detail.remark_history" :key="item.previous_version">{{ new Date(Number(item.changed_at) * 1000).toLocaleString() }} · {{ item.operator_type === 'PLATFORM_ADMIN' ? '平台管理员' : '门店员工' }} #{{ item.operator_id }} · {{ changedParts(item) }}</p>
          </section>
          <p v-if="error" class="remark-error" role="alert">{{ error }}</p>
        </main>
        <footer class="remark-footer"><button class="remark-secondary" :disabled="saving" @click="emit('close')">取消</button><button class="remark-primary" :disabled="saving" @click="save">{{ saving ? '保存中...' : '保存备注' }}</button></footer>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.remark-layer { position: fixed; z-index: 140; inset: 0; display: grid; place-items: center; padding: 16px; }
.remark-mask { position: absolute; inset: 0; border: 0; background: rgba(17,30,46,.46); }
.remark-dialog { position: relative; display: grid; grid-template-rows: auto minmax(0,1fr) auto; width: min(720px,100%); max-height: min(760px,calc(100vh - 32px)); overflow: hidden; border: 1px solid #d8e3ed; border-radius: 8px; background: #fff; box-shadow: 0 24px 60px rgba(8,25,45,.28); color: #30465d; }
.remark-header, .remark-footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 18px; border-bottom: 1px solid #e0e7ef; }
.remark-header small { color: #71869b; font-size: 11px; }
.remark-header h2 { margin: 4px 0 0; font-size: 18px; }
.remark-icon { display: grid; place-items: center; width: 32px; height: 32px; border: 1px solid #dce5ee; border-radius: 6px; background: #fff; color: #47627d; }
.remark-content { min-height: 0; overflow: auto; padding: 18px; }
.remark-field, .remark-line label { display: grid; gap: 7px; font-size: 12px; font-weight: 600; }
.remark-field textarea, .remark-line textarea { box-sizing: border-box; width: 100%; min-height: 64px; padding: 9px 10px; resize: vertical; border: 1px solid #ccd9e7; border-radius: 6px; color: #30465d; font: inherit; font-weight: 400; line-height: 1.5; }
.remark-field textarea:focus, .remark-line textarea:focus { outline: 2px solid #b8d7fa; border-color: #367fda; }
.remark-lines, .remark-history { margin-top: 19px; border-top: 1px solid #e6edf4; padding-top: 15px; }
.remark-lines h3, .remark-history h3 { margin: 0 0 12px; font-size: 13px; }
.remark-line { padding: 11px 0; border-top: 1px solid #edf1f5; }
.remark-line:first-of-type { border-top: 0; }
.remark-product small { margin-left: 8px; font-weight: 400; color: #8090a0; }
.remark-history p { margin: 6px 0; color: #60748a; font-size: 11px; }
.remark-error { margin: 13px 0 0; color: #bc3f48; font-size: 12px; }
.remark-footer { justify-content: flex-end; border-top: 1px solid #e0e7ef; border-bottom: 0; }
.remark-primary, .remark-secondary { min-height: 34px; padding: 0 13px; border-radius: 6px; font-size: 12px; }
.remark-primary { border: 1px solid #176fd1; background: #176fd1; color: #fff; }
.remark-secondary { border: 1px solid #d5e1ee; background: #fff; color: #47627d; }
.remark-primary:disabled, .remark-secondary:disabled { opacity: .6; }
@media (max-width: 640px) { .remark-dialog { max-height: calc(100vh - 32px); } .remark-content { padding: 12px; } }
</style>

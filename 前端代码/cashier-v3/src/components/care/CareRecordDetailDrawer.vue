<script setup>
import { computed } from 'vue'
import ClipboardList from '@lucide/vue/dist/esm/icons/clipboard-list.mjs'
import Link2 from '@lucide/vue/dist/esm/icons/link-2.mjs'
import MapPin from '@lucide/vue/dist/esm/icons/map-pin.mjs'
import UserRound from '@lucide/vue/dist/esm/icons/user-round.mjs'
import X from '@lucide/vue/dist/esm/icons/x.mjs'

const props = defineProps({
  record: { type: Object, default: () => ({}) },
  pendingAction: { type: String, default: '' }
})

const emit = defineEmits(['close', 'action'])
const actions = computed(() => Array.isArray(props.record.availableActions)
  ? props.record.availableActions.filter((action) => action && action.code && action.label)
  : [])
const relatedBusiness = computed(() => props.record.relatedBusiness || {})

function actionClass(action) {
  return { 'care-record-detail__action--danger': action.tone === 'danger' }
}
</script>

<template>
  <Teleport to="body">
    <div class="care-record-detail" role="dialog" aria-modal="true" aria-label="客情记录详情">
      <button type="button" class="care-record-detail__backdrop" aria-label="关闭客情记录详情" @click="emit('close')" />
      <aside class="care-record-detail__drawer">
        <header class="care-record-detail__header">
          <div><span>{{ record.typeLabel || '客情记录' }}</span><h2>{{ record.memberName || '未命名会员' }}</h2><p>{{ record.recordNo || '正式单号待加载' }}</p></div>
          <button type="button" class="care-record-detail__close" aria-label="关闭" title="关闭" @click="emit('close')"><X :size="18" aria-hidden="true" /></button>
        </header>

        <main class="care-record-detail__body">
          <section class="care-record-detail__status"><strong>{{ record.statusLabel || record.status || '—' }}</strong><span>记录版本 {{ record.recordVersion || '—' }}</span></section>
          <section class="care-record-detail__section"><h3><ClipboardList :size="16" aria-hidden="true" />跟进内容</h3><p>{{ record.content || '—' }}</p></section>
          <section class="care-record-detail__section"><h3>客情记录号</h3><strong>{{ record.recordNo || '—' }}</strong></section>
          <section class="care-record-detail__section"><h3>跟进结果</h3><dl class="care-record-detail__facts"><div><dt>跟进时间</dt><dd>{{ record.followedAt || '—' }}</dd></div><div><dt>跟进方式</dt><dd>{{ record.methodLabel || '—' }}</dd></div><div><dt>跟进结果</dt><dd>{{ record.resultLabel || '—' }}</dd></div><div><dt>实际跟进人</dt><dd>{{ record.actualFollowerName || '—' }}</dd></div><div><dt><UserRound :size="15" aria-hidden="true" />创建人</dt><dd>{{ record.creatorName || '—' }}</dd></div><div><dt><MapPin :size="15" aria-hidden="true" />门店</dt><dd>{{ record.storeName || '—' }}</dd></div></dl></section>
          <section v-if="relatedBusiness.label || relatedBusiness.id" class="care-record-detail__section"><h3><Link2 :size="16" aria-hidden="true" />关联业务</h3><strong>{{ relatedBusiness.label || relatedBusiness.id }}</strong></section>
          <section class="care-record-detail__section"><h3>操作时间</h3><dl class="care-record-detail__facts"><div><dt>创建时间</dt><dd>{{ record.createdAt || record.recordedAt || '—' }}</dd></div><div><dt>系统入账时间</dt><dd>{{ record.recordedAt || '—' }}</dd></div><div v-if="record.voidedAt"><dt>作废时间</dt><dd>{{ record.voidedAt }}</dd></div></dl></section>
          <section v-if="record.voidReason" class="care-record-detail__section care-record-detail__void"><h3>作废原因</h3><p>{{ record.voidReason }}</p></section>
        </main>

        <footer class="care-record-detail__footer"><span v-if="!actions.length">该记录当前没有可执行操作</span><button v-for="action in actions" :key="action.code" type="button" class="care-record-detail__action" :class="actionClass(action)" :disabled="action.enabled === false || pendingAction === action.code" :title="action.disabledReason || ''" @click="emit('action', action)">{{ pendingAction === action.code ? '正在处理…' : action.label }}</button></footer>
      </aside>
    </div>
  </Teleport>
</template>

<style scoped>
.care-record-detail { position:fixed; inset:0; z-index:80; display:grid; grid-template-columns:minmax(0, 1fr) minmax(420px, 520px); }
.care-record-detail__backdrop { grid-column:1; width:100%; height:100%; padding:0; border:0; background:rgba(20, 31, 48, .34); }
.care-record-detail__drawer { grid-column:2; display:grid; grid-template-rows:auto minmax(0, 1fr) auto; min-width:0; min-height:0; border-left:1px solid #dce4ee; background:#fff; box-shadow:-12px 0 34px rgba(20, 31, 48, .13); }
.care-record-detail__header { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; padding:21px 22px 18px; border-bottom:1px solid #e7ebf0; }
.care-record-detail__header span { color:#176fd1; font-size:12px; font-weight:700; }
.care-record-detail__header h2 { margin:3px 0 2px; color:#1f2937; font-size:20px; line-height:28px; }
.care-record-detail__header p { margin:0; color:#7b8794; font-size:12px; }
.care-record-detail__close { display:grid; width:34px; height:34px; place-items:center; padding:0; border:1px solid #dce4ee; border-radius:6px; background:#fff; color:#5f6b78; }
.care-record-detail__body { min-height:0; overflow:auto; padding:0 22px 24px; }
.care-record-detail__status { display:flex; min-height:50px; align-items:center; gap:10px; border-bottom:1px solid #edf0f4; color:#8a94a3; font-size:12px; }
.care-record-detail__status strong { padding:3px 8px; border-radius:4px; background:#eaf8ef; color:#1d7a45; font-size:12px; }
.care-record-detail__section { padding:18px 0; border-bottom:1px solid #edf0f4; }
.care-record-detail__section h3 { display:flex; align-items:center; gap:6px; margin:0 0 12px; color:#394555; font-size:14px; }
.care-record-detail__section > strong { display:block; color:#263241; font-size:13px; }
.care-record-detail__section > p { margin:0; color:#4c5969; font-size:13px; line-height:1.7; white-space:pre-wrap; }
.care-record-detail__facts { display:grid; grid-template-columns:1fr 1fr; gap:13px 20px; margin:0; }
.care-record-detail__facts div { min-width:0; }
.care-record-detail__facts dt { display:flex; align-items:center; gap:5px; margin-bottom:3px; color:#8a94a3; font-size:12px; }
.care-record-detail__facts dd { margin:0; overflow-wrap:anywhere; color:#303b49; font-size:13px; font-weight:600; }
.care-record-detail__void h3, .care-record-detail__void p { color:#a61b29; }
.care-record-detail__footer { display:flex; min-height:68px; align-items:center; justify-content:flex-end; gap:8px; padding:13px 22px; border-top:1px solid #e7ebf0; background:#fbfcfd; }
.care-record-detail__footer > span { margin-right:auto; color:#8a94a3; font-size:12px; }
.care-record-detail__action { min-height:36px; padding:0 14px; border:1px solid #cfd8e3; border-radius:6px; background:#fff; color:#465364; font-size:13px; font-weight:600; }
.care-record-detail__action--danger { border-color:#ffb8b3; color:#b42318; }
.care-record-detail__action:disabled { opacity:.52; }
@media (max-width: 760px) { .care-record-detail { grid-template-columns:1fr; } .care-record-detail__backdrop { display:none; } .care-record-detail__drawer { grid-column:1; } .care-record-detail__facts { grid-template-columns:1fr; } }
</style>

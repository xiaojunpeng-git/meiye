<script setup>
const props = defineProps({
  detail: { type: Object, required: true },
  loading: { type: Boolean, default: false },
  error: { type: String, default: '' }
})

const emit = defineEmits(['close', 'load-more', 'open-task', 'open-record'])
</script>

<template>
  <section class="care-metric-detail" role="dialog" aria-modal="true" :aria-label="`${detail.title}明细`">
    <button class="care-metric-detail__backdrop" type="button" aria-label="关闭明细" @click="emit('close')" />
    <aside class="care-metric-detail__drawer">
      <header class="care-metric-detail__header">
        <div>
          <h2>{{ detail.title }}明细</h2>
          <p v-if="detail.staffName">员工：{{ detail.staffName }}</p>
          <p v-else>当前授权范围</p>
        </div>
        <button type="button" class="care-metric-detail__close" aria-label="关闭明细" @click="emit('close')">关闭</button>
      </header>

      <div class="care-metric-detail__summary">
        <strong>{{ detail.total }}{{ detail.entityType === 'record' ? ' 条' : ' 项' }}</strong>
        <span>数据时间 {{ detail.dataAsOf || '—' }}</span>
      </div>

      <main class="care-metric-detail__content">
        <template v-if="detail.entityType === 'task'">
          <button v-for="task in detail.records || []" :key="task.taskId" type="button" class="care-metric-detail__row" @click="emit('open-task', task)">
            <div><strong>{{ task.member?.name || '未命名会员' }}</strong><span>{{ task.typeLabel }} · {{ task.statusLabel }}</span></div>
            <p>{{ task.plannedAt }} · 负责人：{{ task.owner?.name || '—' }}<small>{{ task.taskNo || '' }}</small></p>
          </button>
        </template>
        <template v-else>
          <button v-for="record in detail.records || []" :key="record.recordId" type="button" class="care-metric-detail__row" @click="emit('open-record', record)">
            <div><strong>{{ record.memberName || '未命名会员' }}</strong><span>{{ record.typeLabel }} · {{ record.resultLabel }}</span></div>
            <p>{{ record.followedAt }} · 实际跟进人：{{ record.actualFollowerName || '—' }}<small>{{ record.recordNo || '' }}</small></p>
          </button>
        </template>
        <p v-if="!(detail.records || []).length && !loading" class="care-metric-detail__empty">当前范围没有可展示的明细。</p>
        <p v-if="error" class="care-metric-detail__error">{{ error }}</p>
      </main>

      <footer class="care-metric-detail__footer">
        <span v-if="detail.hasMore">还有更多明细</span>
        <span v-else>已展示全部明细</span>
        <button v-if="detail.hasMore" type="button" :disabled="loading" @click="emit('load-more')">{{ loading ? '加载中…' : '加载更多' }}</button>
      </footer>
    </aside>
  </section>
</template>

<style scoped>
.care-metric-detail { position:fixed; z-index:80; inset:0; display:grid; grid-template-columns:minmax(0, 1fr) minmax(360px, 520px); }
.care-metric-detail__backdrop { grid-column:1; border:0; background:rgba(25, 35, 48, .34); }
.care-metric-detail__drawer { grid-column:2; display:grid; grid-template-rows:auto auto minmax(0, 1fr) auto; min-width:0; min-height:0; background:#fff; box-shadow:-8px 0 24px rgba(25, 35, 48, .18); }
.care-metric-detail__header { display:flex; align-items:flex-start; justify-content:space-between; gap:14px; padding:20px 22px 16px; border-bottom:1px solid #e7ebf0; }
.care-metric-detail__header h2 { margin:0; color:#273444; font-size:18px; }
.care-metric-detail__header p { margin:5px 0 0; color:#7b8794; font-size:12px; }
.care-metric-detail__close, .care-metric-detail__footer button { min-height:34px; padding:0 12px; border:1px solid #cfd8e3; border-radius:6px; background:#fff; color:#465364; font-size:12px; font-weight:600; }
.care-metric-detail__summary { display:flex; align-items:baseline; justify-content:space-between; gap:12px; padding:13px 22px; border-bottom:1px solid #edf0f4; background:#fbfcfd; }
.care-metric-detail__summary strong { color:#176fd1; font-size:20px; }
.care-metric-detail__summary span, .care-metric-detail__footer span { color:#7b8794; font-size:12px; }
.care-metric-detail__content { min-height:0; overflow:auto; padding:0 22px; }
.care-metric-detail__row { display:block; width:100%; padding:15px 0; border:0; border-bottom:1px solid #edf0f4; background:transparent; text-align:left; }
.care-metric-detail__row:hover, .care-metric-detail__row:focus-visible { background:#f5f9ff; outline:none; }
.care-metric-detail__row div { display:flex; justify-content:space-between; gap:12px; color:#7b8794; font-size:12px; }
.care-metric-detail__row strong { color:#303b49; font-size:14px; }
.care-metric-detail__row p { margin:7px 0 0; color:#667384; font-size:12px; }
.care-metric-detail__row small { display:block; margin-top:3px; color:#8b96a3; font-size:11px; }
.care-metric-detail__empty { margin:34px 0; color:#8b96a3; font-size:13px; text-align:center; }
.care-metric-detail__error { margin:15px 0; color:#b42318; font-size:12px; }
.care-metric-detail__footer { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:14px 22px; border-top:1px solid #e7ebf0; background:#fbfcfd; }
.care-metric-detail__footer button { border-color:#176fd1; background:#176fd1; color:#fff; }
.care-metric-detail__footer button:disabled { opacity:.55; }
@media (max-width: 760px) { .care-metric-detail { grid-template-columns:1fr; } .care-metric-detail__backdrop { display:none; } .care-metric-detail__drawer { grid-column:1; } }
</style>

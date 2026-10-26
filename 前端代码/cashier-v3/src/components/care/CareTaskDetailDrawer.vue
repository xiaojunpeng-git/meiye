<script setup>
import { computed } from 'vue'
import CalendarClock from '@lucide/vue/dist/esm/icons/calendar-clock.mjs'
import Link2 from '@lucide/vue/dist/esm/icons/link-2.mjs'
import MapPin from '@lucide/vue/dist/esm/icons/map-pin.mjs'
import UserRound from '@lucide/vue/dist/esm/icons/user-round.mjs'
import X from '@lucide/vue/dist/esm/icons/x.mjs'

const props = defineProps({
  task: { type: Object, default: () => ({}) },
  pendingAction: { type: String, default: '' }
})

const emit = defineEmits(['close', 'action'])

const actions = computed(() => Array.isArray(props.task.availableActions)
  ? props.task.availableActions.filter((action) => action && action.code && action.label)
  : [])
const member = computed(() => props.task.member || {})
const owner = computed(() => props.task.owner || {})
const latestCare = computed(() => props.task.latestCare || null)
const relatedBusiness = computed(() => props.task.relatedBusiness || null)

function actionClass(action) {
  return {
    'care-task-detail__action--primary': action.tone === 'primary',
    'care-task-detail__action--danger': action.tone === 'danger'
  }
}
</script>

<template>
  <Teleport to="body">
    <div class="care-task-detail" role="dialog" aria-modal="true" aria-label="客情任务详情">
      <button type="button" class="care-task-detail__backdrop" aria-label="关闭任务详情" @click="emit('close')" />
      <aside class="care-task-detail__drawer">
        <header class="care-task-detail__header">
          <div>
            <span class="care-task-detail__eyebrow">{{ task.typeLabel || '跟进任务' }}</span>
            <h2>{{ member.name || '未命名会员' }}</h2>
            <p>{{ task.taskNo || '正式单号待加载' }}<template v-if="member.phone"> · {{ member.phone }}</template><template v-if="member.level"> · {{ member.level }}</template></p>
          </div>
          <button type="button" class="care-task-detail__close" aria-label="关闭" title="关闭" @click="emit('close')">
            <X :size="18" aria-hidden="true" />
          </button>
        </header>

        <div class="care-task-detail__body">
          <section class="care-task-detail__status-band">
            <span class="care-task-detail__status" :data-status="task.status">{{ task.statusLabel || task.status || '—' }}</span>
            <strong v-if="task.isOverdue" class="care-task-detail__overdue">已逾期</strong>
            <span>版本 {{ task.taskVersion || '—' }}</span>
          </section>

          <section class="care-task-detail__section" aria-label="任务信息">
            <h3>任务信息</h3>
            <dl class="care-task-detail__facts">
              <div><dt><CalendarClock :size="15" aria-hidden="true" />计划时间</dt><dd>{{ task.plannedAt || '—' }}</dd></div>
              <div><dt>跟进任务单号</dt><dd>{{ task.taskNo || '—' }}</dd></div>
              <div><dt><UserRound :size="15" aria-hidden="true" />当前负责人</dt><dd>{{ owner.name || '—' }}</dd></div>
              <div><dt><MapPin :size="15" aria-hidden="true" />归属门店</dt><dd>{{ task.store?.name || '—' }}</dd></div>
              <div><dt>任务来源</dt><dd>{{ task.sourceLabel || '—' }}</dd></div>
              <div v-if="task.completedAt"><dt>完成时间</dt><dd>{{ task.completedAt }}</dd></div>
              <div v-if="task.actualFollower?.name"><dt>实际跟进人</dt><dd>{{ task.actualFollower.name }}</dd></div>
            </dl>
          </section>

          <section v-if="relatedBusiness" class="care-task-detail__section" aria-label="关联业务">
            <h3><Link2 :size="16" aria-hidden="true" />关联业务</h3>
            <strong>{{ relatedBusiness.label }}</strong>
            <p>{{ relatedBusiness.summary || '后端未返回关联业务摘要。' }}</p>
          </section>

          <section v-if="latestCare" class="care-task-detail__section" aria-label="最近客情">
            <h3>最近客情</h3>
            <div class="care-task-detail__record-head">
              <strong>{{ latestCare.methodLabel || '跟进记录' }} · {{ latestCare.resultLabel || '—' }}</strong>
              <span>{{ latestCare.followedAt || '—' }}</span>
            </div>
            <p>{{ latestCare.content || '后端未返回最近跟进内容。' }}</p>
          </section>

          <section v-if="member.tags?.length" class="care-task-detail__section" aria-label="会员标签">
            <h3>会员标签</h3>
            <div class="care-task-detail__tags">
              <span v-for="tag in member.tags" :key="tag">{{ tag }}</span>
            </div>
          </section>
        </div>

        <footer class="care-task-detail__footer">
          <span v-if="!actions.length">当前任务没有可执行动作</span>
          <button
            v-for="action in actions"
            :key="action.code"
            type="button"
            class="care-task-detail__action"
            :class="actionClass(action)"
            :disabled="action.enabled === false || pendingAction === action.code"
            :title="action.disabledReason || ''"
            @click="emit('action', action)"
          >
            {{ pendingAction === action.code ? '正在处理…' : action.label }}
          </button>
        </footer>
      </aside>
    </div>
  </Teleport>
</template>

<style scoped>
.care-task-detail { position:fixed; inset:0; z-index:80; display:grid; grid-template-columns:minmax(0, 1fr) minmax(420px, 520px); }
.care-task-detail__backdrop { grid-column:1; width:100%; height:100%; padding:0; border:0; background:rgba(20, 31, 48, .34); }
.care-task-detail__drawer { grid-column:2; display:grid; grid-template-rows:auto minmax(0, 1fr) auto; min-width:0; min-height:0; border-left:1px solid #dce4ee; background:#fff; box-shadow:-12px 0 34px rgba(20, 31, 48, .13); }
.care-task-detail__header { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; padding:21px 22px 18px; border-bottom:1px solid #e7ebf0; }
.care-task-detail__header h2 { margin:3px 0 2px; color:#1f2937; font-size:20px; line-height:28px; }
.care-task-detail__header p { margin:0; color:#7b8794; font-size:13px; }
.care-task-detail__eyebrow { color:#176fd1; font-size:12px; font-weight:700; }
.care-task-detail__close { display:grid; width:34px; height:34px; place-items:center; padding:0; border:1px solid #dce4ee; border-radius:6px; background:#fff; color:#5f6b78; }
.care-task-detail__close:hover { border-color:#91caff; color:#176fd1; }
.care-task-detail__body { min-height:0; overflow:auto; padding:0 22px 24px; }
.care-task-detail__status-band { display:flex; align-items:center; gap:8px; min-height:50px; border-bottom:1px solid #edf0f4; color:#8a94a3; font-size:12px; }
.care-task-detail__status { padding:3px 8px; border-radius:4px; background:#edf5ff; color:#176fd1; font-size:12px; font-weight:700; }
.care-task-detail__status[data-status="COMPLETED"] { background:#eaf8ef; color:#1d7a45; }
.care-task-detail__status[data-status="VOIDED"] { background:#f1f2f4; color:#6b7280; }
.care-task-detail__overdue { color:#a61b29; }
.care-task-detail__section { padding:18px 0; border-bottom:1px solid #edf0f4; }
.care-task-detail__section h3 { display:flex; align-items:center; gap:6px; margin:0 0 12px; color:#394555; font-size:14px; }
.care-task-detail__section > strong { display:block; color:#263241; font-size:14px; }
.care-task-detail__section > p { margin:6px 0 0; color:#657180; font-size:13px; line-height:1.7; }
.care-task-detail__facts { display:grid; grid-template-columns:1fr 1fr; gap:13px 20px; margin:0; }
.care-task-detail__facts div { min-width:0; }
.care-task-detail__facts dt { display:flex; align-items:center; gap:5px; margin-bottom:3px; color:#8a94a3; font-size:12px; }
.care-task-detail__facts dd { margin:0; overflow-wrap:anywhere; color:#303b49; font-size:13px; font-weight:600; }
.care-task-detail__record-head { display:flex; align-items:center; justify-content:space-between; gap:12px; }
.care-task-detail__record-head strong { color:#303b49; font-size:13px; }
.care-task-detail__record-head span { flex:none; color:#8a94a3; font-size:12px; }
.care-task-detail__tags { display:flex; flex-wrap:wrap; gap:6px; }
.care-task-detail__tags span { padding:3px 8px; border-radius:4px; background:#f2f5f8; color:#596575; font-size:12px; }
.care-task-detail__footer { display:flex; min-height:68px; align-items:center; justify-content:flex-end; gap:8px; padding:13px 22px; border-top:1px solid #e7ebf0; background:#fbfcfd; }
.care-task-detail__footer > span { margin-right:auto; color:#8a94a3; font-size:12px; }
.care-task-detail__action { min-height:36px; padding:0 14px; border:1px solid #cfd8e3; border-radius:6px; background:#fff; color:#465364; font-size:13px; font-weight:600; }
.care-task-detail__action--primary { border-color:#176fd1; background:#176fd1; color:#fff; }
.care-task-detail__action--danger { border-color:#ffb8b3; color:#b42318; }
.care-task-detail__action:disabled { opacity:.52; }
@media (max-width: 760px) {
  .care-task-detail { grid-template-columns:1fr; }
  .care-task-detail__backdrop { display:none; }
  .care-task-detail__drawer { grid-column:1; }
}
</style>

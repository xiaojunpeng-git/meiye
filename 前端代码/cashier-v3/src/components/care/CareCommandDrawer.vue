<script setup>
import { computed, reactive, watch } from 'vue'
import AlertCircle from '@lucide/vue/dist/esm/icons/circle-alert.mjs'
import X from '@lucide/vue/dist/esm/icons/x.mjs'

const props = defineProps({
  mode: { type: String, default: '' },
  task: { type: Object, default: () => ({}) },
  subject: { type: Object, default: () => ({}) },
  selectedMember: { type: Object, default: () => ({}) },
  preparation: { type: Object, default: () => ({}) },
  isSubmitting: { type: Boolean, default: false },
  error: { type: String, default: '' }
})

const emit = defineEmits(['close', 'submit', 'request-member-selector'])

const draft = reactive({})

const modeDefinition = computed(() => ({
  'complete-care-task': { title: '完成跟进', description: '提交真实跟进结果后，任务和正式客情记录一并生效。', submitLabel: '确认完成' },
  'reassign-care-task': { title: '转派任务', description: '只变更当前负责人，不代替新负责人执行任务。', submitLabel: '确认转派' },
  'void-care-task': { title: '作废任务', description: '进行中的任务作废后不可重新打开。', submitLabel: '确认作废' },
  'delete-care-task': { title: '删除未开始任务', description: '页面不再展示该任务，但后端仍保留完整操作审计。', submitLabel: '确认删除' },
  'create-care-task': { title: '新增跟进任务', description: '任务由后端校验会员、门店、负责人和计划时间。', submitLabel: '创建任务' },
  'create-care-record': { title: '新增客情记录', description: '正式记录提交后不可修改，只能按权限作废再重新创建。', submitLabel: '提交记录' },
  'void-care-record': { title: '作废客情记录', description: '原内容和完整作废审计都会保留。', submitLabel: '确认作废' },
  'save-care-followup-rule': { title: '回访规则', description: '规则只影响后续真实服务完成事件，不补造历史任务。', submitLabel: '保存规则' }
})[props.mode] || { title: '客情操作', description: '', submitLabel: '确认' })

const methodOptions = computed(() => Array.isArray(props.preparation.methodOptions) ? props.preparation.methodOptions : [])
const resultOptions = computed(() => Array.isArray(props.preparation.resultOptions) ? props.preparation.resultOptions : [])
const assigneeOptions = computed(() => Array.isArray(props.preparation.assigneeOptions) ? props.preparation.assigneeOptions : [])
watch(
  () => [props.mode, props.task.taskId, props.subject.recordId, props.subject.ruleId],
  () => resetDraft(),
  { immediate: true }
)

watch(
  () => [props.selectedMember.memberId, props.selectedMember.memberName],
  ([memberId, memberName]) => {
    if (props.mode !== 'create-care-record') return
    draft.memberId = String(memberId || '')
    draft.memberName = String(memberName || '')
  }
)

function resetDraft() {
  Object.keys(draft).forEach((key) => delete draft[key])
  Object.assign(draft, {
    methodCode: '',
    content: '',
    resultCode: '',
    createNextTask: false,
    nextPlannedAt: '',
    nextOwnerId: '',
    assigneeId: '',
    reason: '',
    memberId: String(props.selectedMember.memberId || ''),
    memberName: String(props.selectedMember.memberName || ''),
    taskTypeCode: 'DAILY_FOLLOWUP',
    plannedAt: '',
    ownerId: '',
    recordTypeCode: 'DAILY_FOLLOWUP',
    followedAt: '',
    projectName: props.subject.projectName || '',
    delayDays: 1,
    ownerRuleCode: 'PRIMARY_CRAFTSMAN',
    enabled: props.subject.enabled !== false
  })
}

const canSubmit = computed(() => {
  if (props.isSubmitting) return false
  if (props.mode === 'complete-care-task') {
    if (!draft.methodCode || !String(draft.content || '').trim() || !draft.resultCode) return false
    if (draft.createNextTask && (!draft.nextPlannedAt || !draft.nextOwnerId)) return false
    return true
  }
  if (props.mode === 'reassign-care-task') return Boolean(draft.assigneeId && String(draft.reason || '').trim())
  if (['void-care-task', 'void-care-record'].includes(props.mode)) return Boolean(String(draft.reason || '').trim())
  if (props.mode === 'delete-care-task') return true
  if (props.mode === 'create-care-task') return Boolean(draft.memberId && draft.taskTypeCode && draft.plannedAt && draft.ownerId)
  if (props.mode === 'create-care-record') return Boolean(draft.memberId && draft.recordTypeCode && draft.followedAt && draft.methodCode && String(draft.content || '').trim() && draft.resultCode)
  if (props.mode === 'save-care-followup-rule') return Boolean(String(draft.projectName || '').trim() && Number(draft.delayDays) >= 0 && draft.ownerRuleCode)
  return false
})

function submit() {
  if (!canSubmit.value) return
  emit('submit', {
    ...draft,
    taskId: props.task.taskId || undefined,
    expectedVersion: props.task.taskVersion || props.subject.recordVersion || props.subject.ruleVersion || undefined,
    recordId: props.subject.recordId || undefined,
    ruleId: props.subject.ruleId || undefined
  })
}
</script>

<template>
  <Teleport to="body">
    <div class="care-command" role="dialog" aria-modal="true" :aria-label="modeDefinition.title">
      <button type="button" class="care-command__backdrop" aria-label="关闭客情操作" @click="emit('close')" />
      <section class="care-command__drawer">
        <header class="care-command__header">
          <div><h2>{{ modeDefinition.title }}</h2><p>{{ modeDefinition.description }}</p></div>
          <button type="button" class="care-command__close" aria-label="关闭" title="关闭" @click="emit('close')"><X :size="18" aria-hidden="true" /></button>
        </header>

        <form class="care-command__body" @submit.prevent="submit">
          <template v-if="mode === 'complete-care-task'">
            <label>跟进方式<strong>*</strong><select v-model="draft.methodCode" required><option value="">请选择</option><option v-for="option in methodOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select></label>
            <label>跟进内容<strong>*</strong><textarea v-model="draft.content" rows="5" maxlength="1000" placeholder="记录本次真实沟通内容" required /></label>
            <label>跟进结果<strong>*</strong><select v-model="draft.resultCode" required><option value="">请选择</option><option v-for="option in resultOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select></label>
            <p class="care-command__notice"><AlertCircle :size="16" aria-hidden="true" />“预约成功”仅记录本次沟通结果，不会创建或关联预约；需要预约请到预约功能单独办理。</p>
            <label class="care-command__check"><input v-model="draft.createNextTask" type="checkbox"><span>同时创建下一次跟进任务</span></label>
            <div v-if="draft.createNextTask" class="care-command__two-columns">
              <label>下次计划时间<strong>*</strong><input v-model="draft.nextPlannedAt" type="datetime-local" required></label>
              <label>下次负责人<strong>*</strong><select v-model="draft.nextOwnerId" required><option value="">请选择</option><option v-for="option in assigneeOptions" :key="option.value" :value="option.value" :disabled="option.enabled === false">{{ option.label }}</option></select></label>
            </div>
          </template>

          <template v-else-if="mode === 'reassign-care-task'">
            <label>新负责人<strong>*</strong><select v-model="draft.assigneeId" required><option value="">请选择当前门店员工</option><option v-for="option in assigneeOptions" :key="option.value" :value="option.value" :disabled="option.enabled === false">{{ option.label }}</option></select></label>
            <label>转派原因<strong>*</strong><textarea v-model="draft.reason" rows="4" maxlength="300" placeholder="说明本次转派原因" required /></label>
            <p class="care-command__notice"><AlertCircle :size="16" aria-hidden="true" />跨门店转派不开放；最终仍由后端校验任职与数据范围。</p>
          </template>

          <template v-else-if="mode === 'void-care-task' || mode === 'void-care-record'">
            <label>作废原因<strong>*</strong><textarea v-model="draft.reason" rows="5" maxlength="300" placeholder="请填写可追溯的作废原因" required /></label>
          </template>

          <template v-else-if="mode === 'delete-care-task'">
            <div class="care-command__confirmation"><AlertCircle :size="20" aria-hidden="true" /><div><strong>确认删除这条未开始任务？</strong><p>删除后不会物理抹除任务和操作记录。</p></div></div>
          </template>

          <template v-else-if="mode === 'create-care-task'">
            <label>会员编号<strong>*</strong><input v-model.trim="draft.memberId" placeholder="由会员选择器返回稳定编号" required></label>
            <div class="care-command__two-columns">
              <label>任务类型<strong>*</strong><select v-model="draft.taskTypeCode"><option value="DAILY_FOLLOWUP">日常跟进</option><option value="FOLLOWUP">回访</option><option value="INVITATION">邀约</option><option value="SERVICE_FEEDBACK">服务后反馈</option></select></label>
              <label>计划时间<strong>*</strong><input v-model="draft.plannedAt" type="datetime-local" required></label>
            </div>
            <label>负责人<strong>*</strong><select v-model="draft.ownerId" required><option value="">请选择</option><option v-for="option in assigneeOptions" :key="option.value" :value="option.value" :disabled="option.enabled === false">{{ option.label }}</option></select></label>
            <label>任务说明<textarea v-model="draft.content" rows="4" maxlength="500" placeholder="选填，记录本次跟进重点" /></label>
          </template>

          <template v-else-if="mode === 'create-care-record'">
            <label>会员姓名<strong>*</strong><span class="care-command__member"><input :value="draft.memberName" readonly placeholder="请选择会员"><button type="button" class="care-command__member-button" @click="emit('request-member-selector')">选择会员</button></span></label>
            <div class="care-command__two-columns">
              <label>记录类型<strong>*</strong><select v-model="draft.recordTypeCode"><option value="FOLLOWUP">回访</option><option value="DAILY_FOLLOWUP">日常跟进</option><option value="INVITATION">邀约</option><option value="SERVICE_FEEDBACK">服务后反馈</option></select></label>
              <label>实际跟进时间<strong>*</strong><input v-model="draft.followedAt" type="datetime-local" required></label>
            </div>
            <label>跟进方式<strong>*</strong><select v-model="draft.methodCode" required><option value="">请选择</option><option v-for="option in methodOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select></label>
            <label>跟进内容<strong>*</strong><textarea v-model="draft.content" rows="5" maxlength="1000" required /></label>
            <label>跟进结果<strong>*</strong><select v-model="draft.resultCode" required><option value="">请选择</option><option v-for="option in resultOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select></label>
            <p class="care-command__notice"><AlertCircle :size="16" aria-hidden="true" />“预约成功”仅记录本次沟通结果，不会创建或关联预约；需要预约请到预约功能单独办理。</p>
          </template>

          <template v-else-if="mode === 'save-care-followup-rule'">
            <label>项目名称<strong>*</strong><input v-model.trim="draft.projectName" required></label>
            <div class="care-command__two-columns">
              <label>服务完成后<strong>*</strong><span class="care-command__unit-input"><input v-model.number="draft.delayDays" type="number" min="0" max="365" required><span>天跟进</span></span></label>
              <label>负责人规则<strong>*</strong><select v-model="draft.ownerRuleCode"><option value="PRIMARY_CRAFTSMAN">本次服务主要手艺人</option><option value="EXCLUSIVE_SERVICE">会员专属服务人</option><option value="CONFIGURED_STAFF">指定人员</option></select></label>
            </div>
            <label class="care-command__check"><input v-model="draft.enabled" type="checkbox"><span>启用该回访规则</span></label>
          </template>

          <p v-if="error" class="care-command__error" role="alert"><AlertCircle :size="16" aria-hidden="true" />{{ error }}</p>
        </form>

        <footer class="care-command__footer">
          <button type="button" class="care-command__button" @click="emit('close')">取消</button>
          <button type="button" class="care-command__button care-command__button--primary" :disabled="!canSubmit" @click="submit">{{ isSubmitting ? '正在提交…' : modeDefinition.submitLabel }}</button>
        </footer>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.care-command { position:fixed; inset:0; z-index:90; display:grid; grid-template-columns:minmax(0, 1fr) minmax(440px, 560px); }
.care-command__backdrop { grid-column:1; width:100%; height:100%; padding:0; border:0; background:rgba(20, 31, 48, .38); }
.care-command__drawer { grid-column:2; display:grid; grid-template-rows:auto minmax(0, 1fr) auto; min-width:0; min-height:0; border-left:1px solid #dce4ee; background:#fff; box-shadow:-12px 0 34px rgba(20, 31, 48, .15); }
.care-command__header { display:flex; align-items:flex-start; justify-content:space-between; gap:18px; padding:21px 22px 18px; border-bottom:1px solid #e7ebf0; }
.care-command__header h2 { margin:0; color:#202b38; font-size:20px; line-height:28px; }
.care-command__header p { margin:5px 0 0; color:#788493; font-size:13px; line-height:1.5; }
.care-command__close { display:grid; flex:none; width:34px; height:34px; place-items:center; padding:0; border:1px solid #dce4ee; border-radius:6px; background:#fff; color:#5f6b78; }
.care-command__body { display:grid; align-content:start; gap:16px; min-height:0; padding:21px 22px; overflow:auto; }
.care-command__body label { display:grid; gap:6px; color:#445061; font-size:13px; font-weight:600; }
.care-command__body label > strong { display:inline; color:#d92d20; }
.care-command__body input:not([type="checkbox"]), .care-command__body select, .care-command__body textarea { width:100%; min-height:38px; padding:8px 10px; border:1px solid #cfd8e3; border-radius:6px; outline:none; background:#fff; color:#263241; font-size:13px; font-weight:400; }
.care-command__body textarea { resize:vertical; line-height:1.65; }
.care-command__body input:focus, .care-command__body select:focus, .care-command__body textarea:focus { border-color:#4096ff; box-shadow:0 0 0 3px rgba(64, 150, 255, .12); }
.care-command__two-columns { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.care-command__member { display:grid; grid-template-columns:minmax(0, 1fr) auto; gap:8px; }
.care-command__member-button { min-height:38px; padding:0 12px; border:1px solid #cfd8e3; border-radius:6px; background:#fff; color:#176fd1; font-size:13px; font-weight:600; }
.care-command__check { display:flex !important; align-items:center; gap:8px !important; min-height:38px; }
.care-command__check input { width:16px; height:16px; margin:0; }
.care-command__notice, .care-command__error { display:flex; align-items:flex-start; gap:7px; margin:0; padding:10px 11px; border:1px solid #b9d4ff; border-radius:6px; background:#f5f9ff; color:#35658f; font-size:12px; font-weight:400; line-height:1.55; }
.care-command__error { border-color:#ffccc7; background:#fff2f0; color:#b42318; }
.care-command__notice svg, .care-command__error svg { flex:none; margin-top:1px; }
.care-command__confirmation { display:flex; gap:10px; padding:14px; border:1px solid #ffd2cc; border-radius:6px; background:#fff7f6; color:#9f251b; }
.care-command__confirmation svg { flex:none; }
.care-command__confirmation strong { color:#7f1d17; font-size:14px; }
.care-command__confirmation p { margin:5px 0 0; font-size:12px; line-height:1.55; }
.care-command__unit-input { display:grid; grid-template-columns:minmax(0, 1fr) auto; align-items:center; gap:8px; color:#677384; font-weight:400; }
.care-command__footer { display:flex; min-height:68px; align-items:center; justify-content:flex-end; gap:8px; padding:13px 22px; border-top:1px solid #e7ebf0; background:#fbfcfd; }
.care-command__button { min-height:37px; padding:0 16px; border:1px solid #cfd8e3; border-radius:6px; background:#fff; color:#465364; font-size:13px; font-weight:600; }
.care-command__button--primary { border-color:#176fd1; background:#176fd1; color:#fff; }
.care-command__button:disabled { opacity:.5; }
@media (max-width: 760px) {
  .care-command { grid-template-columns:1fr; }
  .care-command__backdrop { display:none; }
  .care-command__drawer { grid-column:1; }
  .care-command__two-columns { grid-template-columns:1fr; }
}
</style>

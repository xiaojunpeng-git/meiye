<script setup>
import { computed, ref, watch } from 'vue'
import { createCashierV3CommandId } from '@/services/cashierV3Bridge'
import { useModalFocusTrap } from '@/composables/useModalFocusTrap'

/**
 * 预约新增／编辑前端壳。
 *
 * modelValue 建议由页面持有，至少包含：
 * {
 *   id, revision, memberId, member, appointmentTime, roomId, room,
 *   craftsmen: [], remark, projects: [
 *     {
 *       projectId, name, source: 'card' | 'unpaid', role: 'main' | 'detail',
 *       // 以下时长均由后端／商品设置返回，前端不推算：
 *       projectServiceDuration, addonServiceDuration,
 *       appliedDurationMinutes, appliedDurationLabel, durationDescription
 *     }
 *   ]
 * }
 *
 * craftsmen 是已经由后端选择、可用于当前预约的手艺人列表；不是本地候选名单。
 * catalogOptions、rooms 也应由后端按当前会员、门店和预约上下文筛选后提供。
 * onSelectMember / onSelectProject / onSelectCraftsmen / onSelectRoom 可以打开共用选择器，
 * 并返回选中实体（或 { member / projects / craftsmen / room }）。
 * onSubmit 只接收当前草稿快照；后端负责最终合法性、冲突、时长和保存结果。
 */
const props = defineProps({
  modelValue: {
    type: Object,
    default: () => ({})
  },
  catalogOptions: {
    type: Array,
    default: () => []
  },
  member: {
    type: Object,
    default: null
  },
  craftsmen: {
    type: Array,
    default: () => []
  },
  craftsmenOptions: {
    type: Array,
    default: () => []
  },
  rooms: {
    type: Array,
    default: () => []
  },
  isSubmitting: {
    type: Boolean,
    default: false
  },
  submission: {
    type: Object,
    default: () => ({})
  },
  onSelectMember: {
    type: Function,
    default: null
  },
  onSelectProject: {
    type: Function,
    default: null
  },
  onSelectCraftsmen: {
    type: Function,
    default: null
  },
  onSelectRoom: {
    type: Function,
    default: null
  },
  onSubmit: {
    type: Function,
    default: null
  },
  onQuery: {
    type: Function,
    default: null
  },
  onRecalculate: {
    type: Function,
    default: null
  }
})

const emit = defineEmits(['close', 'update:modelValue', 'submitted'])
const draft = ref(cloneDraft(props.modelValue))
const missingFields = ref([])
const submitMessage = ref('')
const isSelecting = ref(false)
const isCatalogOpen = ref(false)
const isCraftsmanPickerOpen = ref(false)
const isRecalculating = ref(false)
const scheduleIsDirty = ref(false)
const recalculationMessage = ref('')
const latestRecalculationRequestId = ref('')
const editorDialogRef = ref(null)

const selectedMember = computed(() => draft.value.member || props.member || null)
const hasSelectedMember = computed(() => Boolean(
  draft.value.memberId
  || selectedMember.value?.id
  || selectedMember.value?.memberId
  || selectedMember.value?.name
  || selectedMember.value?.memberName
))
const projectStorageKey = computed(() => (Array.isArray(draft.value.projectLines) ? 'projectLines' : 'projects'))
const projectLines = computed(() => normalizeProjects(readProjects(draft.value)))
const selectedCraftsmen = computed(() => {
  if (Array.isArray(draft.value.craftsmen)) return draft.value.craftsmen
  return Array.isArray(props.craftsmen) ? props.craftsmen : []
})
const availableCraftsmen = computed(() => Array.isArray(props.craftsmenOptions) ? props.craftsmenOptions : [])
const selectedRoom = computed(() => draft.value.room || findRoom(draft.value.roomId) || null)
const visibleCatalogOptions = computed(() => Array.isArray(props.catalogOptions) ? props.catalogOptions : [])
const memberLabel = computed(() => {
  const member = selectedMember.value
  if (!hasSelectedMember.value) return '请选择会员'
  return [member?.name || member?.memberName || draft.value.memberName, member?.phone || member?.mobile || draft.value.memberPhone]
    .filter(Boolean)
    .join(' · ') || '已选择会员'
})
const roomLabel = computed(() => selectedRoom.value?.name || selectedRoom.value?.roomName || draft.value.roomName || '待分配房间')
const craftsmanLabel = computed(() => selectedCraftsmen.value.length
  ? selectedCraftsmen.value.map((item) => item.name || item.staffName).filter(Boolean).join('、')
  : '待分配手艺人')
const summaryRows = computed(() => [
  { label: '会员', value: memberLabel.value },
  { label: '项目', value: projectLines.value.length ? projectLines.value.map((item) => `${roleText(item)}：${projectName(item)}`).join('；') : '未选择项目' },
  { label: '预约时间', value: draft.value.appointmentTime || draft.value.appointmentStartAt || '未选择时间' },
  { label: '手艺人', value: craftsmanLabel.value },
  { label: '房间', value: roomLabel.value },
  { label: '备注', value: draft.value.remark || '未填写' }
])
const scheduleCheck = computed(() => {
  const availability = draft.value.availabilityCheck && typeof draft.value.availabilityCheck === 'object'
    ? draft.value.availabilityCheck
    : {}
  const conflicts = Array.isArray(draft.value.conflicts) ? draft.value.conflicts : []
  const message = draft.value.conflictSummary
    || availability.message
    || availability.summary
    || ''
  const blocking = draft.value.hasBlockingConflict === true
    || availability.blocking === true
    || availability.status === 'conflict'
    || conflicts.some((item) => item?.blocking === true)
  return { message, conflicts, blocking }
})
const submissionStatus = computed(() => normalizeSubmissionStatus(props.submission.status))
const isProcessing = computed(() => submissionStatus.value === 'processing')
const isPendingConfirmation = computed(() => submissionStatus.value === 'pending_confirmation')
const isResultUnknown = computed(() => submissionStatus.value === 'result_unknown')
const isUncertain = computed(() => isPendingConfirmation.value || isResultUnknown.value)
const isFailed = computed(() => submissionStatus.value === 'failed')
const editorFieldsLocked = computed(() => (
  props.isSubmitting
  || isProcessing.value
  || isUncertain.value
  || isFailed.value
))
const canClose = computed(() => (
  (!props.isSubmitting && submissionStatus.value === 'editing')
  || (isFailed.value && props.submission.canClose === true)
))
const canRetry = computed(() => (
  isFailed.value
  && props.submission.canRetry === true
  && !props.isSubmitting
  && typeof props.onSubmit === 'function'
))
const canSubmitReservation = computed(() => (
  ((submissionStatus.value === 'editing' && !props.isSubmitting)
  || canRetry.value
  )
  && !isRecalculating.value
  && !scheduleIsDirty.value
))
const submissionRequestNo = computed(() => (
  props.submission.requestNo
  || props.submission.reservationRequestNo
  || props.submission.requestId
  || ''
))
const submissionQueryAction = 'query-reservation-result'
const canQueryOriginalResult = computed(() => (
  isUncertain.value
  && Boolean(submissionRequestNo.value)
  && Boolean(props.submission.idempotencyKey)
  && typeof props.onQuery === 'function'
))
const submissionTitle = computed(() => {
  if (isResultUnknown.value) return '预约结果暂时未知'
  if (isPendingConfirmation.value) return '正在确认原预约结果'
  if (isProcessing.value) return '正在保存预约'
  if (isFailed.value) return '预约未保存'
  return ''
})
const submissionDescription = computed(() => {
  if (isResultUnknown.value) return '这次预约可能已经创建或更新。只能查询原请求，禁止重新提交。'
  if (isPendingConfirmation.value) return '正在确认这一次原预约请求，请勿关闭或重复操作。'
  if (isProcessing.value) return '请勿关闭或重复操作。'
  if (isFailed.value) return props.submission.message || props.submission.failureReason || '本次保存明确失败，请按系统提示处理。'
  return ''
})

watch(
  () => props.modelValue,
  (value) => {
    draft.value = cloneDraft(value)
    missingFields.value = []
    submitMessage.value = ''
    const responseRequestId = value?.scheduleRecalculationRequestId || value?.recalculationRequestId
    if (
      latestRecalculationRequestId.value
      && String(responseRequestId || '') === String(latestRecalculationRequestId.value)
      && value?.scheduleCalculationReady === true
    ) {
      scheduleIsDirty.value = false
      recalculationMessage.value = ''
    }
  },
  { deep: true }
)

function cloneDraft(value) {
  const source = value && typeof value === 'object' ? value : {}
  const cloned = { ...source }
  if (Array.isArray(source.projects)) cloned.projects = source.projects.map((item) => ({ ...item }))
  if (Array.isArray(source.projectLines)) cloned.projectLines = source.projectLines.map((item) => ({ ...item }))
  if (Array.isArray(source.craftsmen)) cloned.craftsmen = source.craftsmen.map((item) => ({ ...item }))
  if (source.member && typeof source.member === 'object') cloned.member = { ...source.member }
  if (source.room && typeof source.room === 'object') cloned.room = { ...source.room }
  return cloned
}

function normalizeSubmissionStatus(value) {
  const status = String(value || '').trim().toLowerCase()
  if (['processing', 'submitting', '处理中', '提交中'].includes(status)) return 'processing'
  if (['pending', 'result_pending', 'pending_confirmation', 'confirming', '结果确认中', '待确认'].includes(status)) {
    return 'pending_confirmation'
  }
  if (['result_unknown', 'unknown', '结果未知', '未知'].includes(status)) return 'result_unknown'
  if (['failed', 'failure', 'error', 'conflict', '失败', '冲突'].includes(status)) return 'failed'
  if (['succeeded', 'success', 'completed', '已完成', '成功'].includes(status)) return 'succeeded'
  if (!status || status === 'editing') return 'editing'
  return 'result_unknown'
}

function readProjects(source) {
  if (Array.isArray(source?.projectLines)) return source.projectLines
  return Array.isArray(source?.projects) ? source.projects : []
}

function normalizedRole(line) {
  if (line?.role === 'main' || line?.projectRole === 'main' || line?.projectRole === '主项目' || line?.isMain) return 'main'
  return 'detail'
}

function normalizeProjects(lines) {
  const copied = Array.isArray(lines) ? lines.map((line) => ({ ...line })) : []
  if (!copied.length) return copied
  const mainIndex = Math.max(0, copied.findIndex((line) => normalizedRole(line) === 'main'))
  return copied.map((line, index) => ({
    ...line,
    role: index === mainIndex ? 'main' : 'detail'
  }))
}

function commit(next) {
  draft.value = cloneDraft(next)
  emit('update:modelValue', cloneDraft(next))
}

function updateDraft(partial) {
  commit({ ...draft.value, ...partial })
}

function replaceProjects(lines) {
  updateDraft({ [projectStorageKey.value]: normalizeProjects(lines) })
}

function withoutStaleSchedule(value, requestId) {
  const next = cloneDraft(value)
  const key = Array.isArray(next.projectLines) ? 'projectLines' : 'projects'
  if (Array.isArray(next[key])) {
    next[key] = next[key].map((line) => {
      const copied = { ...line }
      delete copied.appliedDurationMinutes
      delete copied.appliedDurationLabel
      delete copied.durationDescription
      if (copied.duration && typeof copied.duration === 'object') {
        copied.duration = { ...copied.duration }
        delete copied.duration.appliedMinutes
        delete copied.duration.appliedLabel
        delete copied.duration.description
      }
      return copied
    })
  }
  for (const field of [
    'expectedEndAt',
    'expectedEndLabel',
    'durationSummary',
    'backendSummaryHint',
    'conflicts',
    'conflictSummary',
    'availabilityCheck',
    'scheduleCalculationToken'
  ]) {
    delete next[field]
  }
  next.scheduleCalculationReady = false
  next.scheduleRecalculationRequestId = requestId
  return next
}

async function applyScheduleChange(nextDraft, changeReason) {
  const requestId = createCashierV3CommandId('RESERVATION_PLAN')
  latestRecalculationRequestId.value = requestId
  scheduleIsDirty.value = true
  recalculationMessage.value = '正在重新计算预计时长并检查人员、房间冲突…'
  const invalidatedDraft = withoutStaleSchedule(nextDraft, requestId)
  commit(invalidatedDraft)

  // 会员、项目、手艺人或房间可先于预约时间选择。时间尚未填写时没有
  // 合法的排期时点，不能把一个必然失败的后端计算请求发出去；草稿保留，
  // 等操作人员填写预约时间后由同一流程统一计算时长与冲突。
  const appointmentTime = String(invalidatedDraft.appointmentTime || invalidatedDraft.appointmentStartAt || '').trim()
  if (!appointmentTime) {
    scheduleIsDirty.value = false
    recalculationMessage.value = ''
    return
  }

  if (typeof props.onRecalculate !== 'function') {
    recalculationMessage.value = '预约时长与冲突检查尚未接入，当前不能保存。'
    return
  }

  isRecalculating.value = true
  try {
    const result = await props.onRecalculate({
      draft: cloneDraft(invalidatedDraft),
      changeReason,
      recalculationRequestId: requestId
    })
    if (requestId !== latestRecalculationRequestId.value) return
    const calculatedDraft = result?.draft || result?.reservationDraft
    if (
      result?.success !== true
      || !calculatedDraft
      || String(calculatedDraft.scheduleRecalculationRequestId || calculatedDraft.recalculationRequestId || '') !== String(requestId)
      || calculatedDraft.scheduleCalculationReady !== true
    ) {
      recalculationMessage.value = result?.message || '系统尚未返回完整的时长和冲突检查结果，当前不能保存。'
      return
    }
    scheduleIsDirty.value = false
    recalculationMessage.value = ''
    commit(calculatedDraft)
  } catch (error) {
    if (requestId === latestRecalculationRequestId.value) {
      recalculationMessage.value = error?.message || '重新计算失败，请修改内容后重试。'
    }
  } finally {
    if (requestId === latestRecalculationRequestId.value) isRecalculating.value = false
  }
}

function updateAppointmentTime(value) {
  if (editorFieldsLocked.value) return
  applyScheduleChange({ ...draft.value, appointmentTime: value }, 'appointment_time_changed')
}

function updateRemark(value) {
  if (editorFieldsLocked.value) return
  updateDraft({ remark: value })
}

function roleText(line) {
  return normalizedRole(line) === 'main' ? '主项目' : '明细项目'
}

function sourceText(line) {
  const source = line?.source || line?.projectSource || line?.sourceLabel
  if (source === 'card' || source === '卡内' || source === '卡内项目') return '卡内'
  if (source === 'unpaid' || source === '未购' || source === '未购项目') return '未购'
  return source || '待后端确认'
}

function normalizedProjectSource(line) {
  const source = line?.source || line?.projectSource || line?.sourceLabel
  if (source === 'card' || source === '卡内' || source === '卡内项目') return 'card'
  if (source === 'unpaid' || source === '未购' || source === '未购项目') return 'unpaid'
  return ''
}

function projectName(line) {
  return line?.name || line?.projectName || '未命名项目'
}

function projectKey(line, index) {
  return line?.id || line?.projectLineId || line?.projectId || `${projectName(line)}-${index}`
}

function projectQuantity(line) {
  const quantity = Number(line?.quantity ?? line?.projectQuantity ?? 1)
  return Number.isInteger(quantity) && quantity > 0 ? quantity : 1
}

function durationInfo(line) {
  const isMain = normalizedRole(line) === 'main'
  const appliedMinutes = Number(line?.appliedDurationMinutes ?? line?.duration?.appliedMinutes)
  const appliedLabel = line?.appliedDurationLabel || line?.duration?.appliedLabel
  const configuredMinutes = Number(isMain
    ? (line?.projectServiceDuration ?? line?.duration?.projectServiceDuration)
    : (line?.addonServiceDuration ?? line?.duration?.addonServiceDuration))
  const description = line?.durationDescription || line?.duration?.description

  if (appliedLabel) return { value: appliedLabel, description: description || '实际采用时长由后端确认。' }
  if (Number.isFinite(appliedMinutes) && appliedMinutes > 0) {
    return { value: `${appliedMinutes}分钟`, description: description || '实际采用时长由后端确认。' }
  }
  if (Number.isFinite(configuredMinutes) && configuredMinutes > 0) {
    return { value: `${configuredMinutes}分钟`, description: description || '商品已配置此类项目时长。' }
  }
  return {
    value: '按继承／默认时长',
    description: description || '时长未单独配置，后端会继承平台配置；仍未配置时使用系统默认时长。'
  }
}

function scheduleConflictText(conflict) {
  if (typeof conflict === 'string') return conflict
  if (!conflict || typeof conflict !== 'object') return ''
  return conflict.message || conflict.description || conflict.summary || conflict.disabledReason || ''
}

function findRoom(roomId) {
  return (props.rooms || []).find((room) => String(room.id) === String(roomId)) || null
}

function clearValidation(field) {
  missingFields.value = missingFields.value.filter((item) => item !== field)
}

function selectMainProject(index) {
  if (editorFieldsLocked.value) return
  const projects = normalizeProjects(projectLines.value.map((line, lineIndex) => ({
    ...line,
    role: lineIndex === index ? 'main' : 'detail'
  })))
  applyScheduleChange({ ...draft.value, [projectStorageKey.value]: projects }, 'main_project_changed')
}

function removeProject(index) {
  if (editorFieldsLocked.value) return
  const projects = normalizeProjects(projectLines.value.filter((_, lineIndex) => lineIndex !== index))
  applyScheduleChange({ ...draft.value, [projectStorageKey.value]: projects }, 'project_removed')
  clearValidation('项目')
}

function changeProjectQuantity(index, delta) {
  if (editorFieldsLocked.value || !Number.isInteger(delta) || !delta) return
  const projects = projectLines.value.map((line, lineIndex) => {
    if (lineIndex !== index) return { ...line }
    return { ...line, quantity: Math.max(1, projectQuantity(line) + delta) }
  })
  applyScheduleChange(
    { ...draft.value, [projectStorageKey.value]: normalizeProjects(projects) },
    'project_quantity_changed'
  )
}

function toProjectLine(option) {
  const source = normalizedProjectSource(option)
  return {
    ...option,
    projectId: option.projectId || option.id,
    name: option.name || option.projectName,
    source: source || null,
    role: projectLines.value.length ? 'detail' : 'main'
  }
}

function appendProjects(selection) {
  if (editorFieldsLocked.value) return
  const options = Array.isArray(selection) ? selection : [selection]
  const validOptions = options.filter((item) => (
    item
    && (item.projectId || item.id || item.name || item.projectName)
    && Boolean(normalizedProjectSource(item))
  ))
  if (!validOptions.length) return
  const projects = normalizeProjects([...projectLines.value, ...validOptions.map(toProjectLine)])
  applyScheduleChange({ ...draft.value, [projectStorageKey.value]: projects }, 'project_added')
  isCatalogOpen.value = false
  clearValidation('项目')
}

async function chooseMember() {
  if (!props.onSelectMember || isSelecting.value || editorFieldsLocked.value) return
  isSelecting.value = true
  try {
    const result = await props.onSelectMember({ draft: submitDraft() })
    const member = result?.member || result
    if (!member || typeof member !== 'object') return
    applyScheduleChange({
      ...draft.value,
      member,
      memberId: member.memberId || member.id || draft.value.memberId,
      memberName: member.memberName || member.name || draft.value.memberName
    }, 'member_changed')
    clearValidation('会员')
  } finally {
    isSelecting.value = false
  }
}

async function chooseProject() {
  if (editorFieldsLocked.value) return
  if (!props.onSelectProject || isSelecting.value) {
    isCatalogOpen.value = !isCatalogOpen.value
    return
  }
  isSelecting.value = true
  try {
    const result = await props.onSelectProject({ draft: submitDraft(), currentProjects: projectLines.value })
    appendProjects(result?.projects || result?.project || result)
  } finally {
    isSelecting.value = false
  }
}

async function chooseCraftsmen() {
  if (isSelecting.value || editorFieldsLocked.value) return
  if (!props.onSelectCraftsmen) {
    isCraftsmanPickerOpen.value = !isCraftsmanPickerOpen.value
    return
  }
  isSelecting.value = true
  try {
    const result = await props.onSelectCraftsmen({ draft: submitDraft(), craftsmen: selectedCraftsmen.value })
    const craftsmen = result?.craftsmen || result
    if (!Array.isArray(craftsmen)) return
    applyScheduleChange({ ...draft.value, craftsmen: craftsmen.map((item) => ({ ...item })) }, 'craftsmen_changed')
    clearValidation('手艺人')
  } finally {
    isSelecting.value = false
  }
}

function isCraftsmanSelected(craftsman) {
  const craftsmanId = craftsman?.id || craftsman?.staffId
  return selectedCraftsmen.value.some((item) => String(item.id || item.staffId) === String(craftsmanId))
}

function toggleCraftsman(craftsman) {
  const craftsmanId = craftsman?.id || craftsman?.staffId
  if (editorFieldsLocked.value || !craftsmanId || craftsman?.selectable !== true) return
  const next = selectedCraftsmen.value.map((item) => ({ ...item }))
  const index = next.findIndex((item) => String(item.id || item.staffId) === String(craftsmanId))
  if (index === -1) next.push({ ...craftsman })
  else next.splice(index, 1)
  applyScheduleChange({ ...draft.value, craftsmen: next }, 'craftsmen_changed')
  clearValidation('手艺人')
}

function clearCraftsmen() {
  if (editorFieldsLocked.value) return
  applyScheduleChange({ ...draft.value, craftsmen: [] }, 'craftsmen_cleared')
  isCraftsmanPickerOpen.value = false
  clearValidation('手艺人')
}

async function chooseRoom() {
  if (!props.onSelectRoom || isSelecting.value || editorFieldsLocked.value) return
  isSelecting.value = true
  try {
    const result = await props.onSelectRoom({ draft: submitDraft(), room: selectedRoom.value })
    const room = result?.room || result
    if (!room || typeof room !== 'object') return
    applyScheduleChange({
      ...draft.value,
      room,
      roomId: room.roomId || room.id || draft.value.roomId,
      roomName: room.roomName || room.name || draft.value.roomName
    }, 'room_changed')
  } finally {
    isSelecting.value = false
  }
}

function chooseRoomFromList(room) {
  if (editorFieldsLocked.value || room?.selectable !== true) return
  applyScheduleChange({
    ...draft.value,
    room: { ...room },
    roomId: room.roomId || room.id,
    roomName: room.roomName || room.name
  }, 'room_changed')
  isCatalogOpen.value = false
}

function clearRoom() {
  if (editorFieldsLocked.value) return
  applyScheduleChange({ ...draft.value, room: null, roomId: null, roomName: '' }, 'room_cleared')
}

function submitDraft() {
  const payload = cloneDraft(draft.value)
  if (!payload.member && selectedMember.value) {
    payload.member = { ...selectedMember.value }
    payload.memberId = payload.memberId || selectedMember.value.memberId || selectedMember.value.id
    payload.memberName = payload.memberName || selectedMember.value.memberName || selectedMember.value.name
  }
  payload[projectStorageKey.value] = normalizeProjects(readProjects(payload))
  if (!Array.isArray(payload.craftsmen) && selectedCraftsmen.value.length) {
    payload.craftsmen = selectedCraftsmen.value.map((item) => ({ ...item }))
  }
  return payload
}

function collectMissingFields() {
  const missing = []
  if (!hasSelectedMember.value) missing.push('会员')
  if (!projectLines.value.length) missing.push('项目')
  if (projectLines.value.some((line) => !normalizedProjectSource(line))) missing.push('项目来源')
  if (!draft.value.appointmentTime && !draft.value.appointmentStartAt) missing.push('预约时间')
  return missing
}

async function submit() {
  if (!canSubmitReservation.value || !props.onSubmit) return
  missingFields.value = collectMissingFields()
  submitMessage.value = ''
  if (missingFields.value.length) return

  try {
    const result = await props.onSubmit(submitDraft())
    emit('submitted', result)
    if (result?.message) submitMessage.value = result.message
  } catch (error) {
    submitMessage.value = error?.message || '暂时无法保存，请稍后再试。'
  }
}

async function queryOriginalResult() {
  if (!canQueryOriginalResult.value || props.isSubmitting) return
  submitMessage.value = ''
  try {
    const result = await props.onQuery({
      queryResultAction: submissionQueryAction,
      requestNo: submissionRequestNo.value,
      originalIdempotencyKey: props.submission.originalIdempotencyKey || props.submission.idempotencyKey,
      reservationId: draft.value.id || draft.value.reservationId,
      recordVersion: draft.value.revision ?? draft.value.recordVersion
    })
    if (result?.message) submitMessage.value = result.message
  } catch (error) {
    submitMessage.value = error?.message || '查询原预约请求失败，请稍后继续查询。'
  }
}

function closeEditor() {
  if (!canClose.value) return
  emit('close')
}

useModalFocusTrap({
  containerRef: editorDialogRef,
  canClose: () => canClose.value,
  onClose: closeEditor
})
</script>

<template>
  <Teleport to="body">
    <section
      ref="editorDialogRef"
      class="reservation-editor-overlay"
      role="dialog"
      aria-modal="true"
      aria-labelledby="reservation-editor-title"
      tabindex="-1"
    >
    <header class="reservation-editor-overlay__header">
      <div>
        <p>预约</p>
        <h2 id="reservation-editor-title">{{ draft.id ? '编辑预约' : '新增预约' }}</h2>
        <span>按顺序填写即可；最终可约性、时长和冲突由系统保存时确认。</span>
      </div>
      <button type="button" class="reservation-editor-button reservation-editor-button--secondary" :disabled="!canClose" @click="closeEditor">关闭</button>
    </header>

    <section v-if="isProcessing || isUncertain || isFailed" class="reservation-editor-submission" :class="`reservation-editor-submission--${submissionStatus}`" aria-live="polite">
      <strong>{{ submissionTitle }}</strong>
      <span>{{ submissionDescription }}</span>
      <small v-if="submissionRequestNo">请求号：{{ submissionRequestNo }}</small>
    </section>

    <form class="reservation-editor-overlay__body" @submit.prevent="submit">
      <section class="reservation-editor-step">
        <header class="reservation-editor-step__header">
          <span class="reservation-editor-step__number">1</span>
          <div><h3>选择会员</h3><p>预约需要先确定服务对象。</p></div>
        </header>
        <div class="reservation-editor-selection-card">
          <div><strong>{{ memberLabel }}</strong><span v-if="selectedMember?.memberNo">会员编号：{{ selectedMember.memberNo }}</span></div>
          <button type="button" class="reservation-editor-button reservation-editor-button--secondary" :disabled="editorFieldsLocked || isSelecting" @click="chooseMember">选择会员</button>
        </div>
      </section>

      <section class="reservation-editor-step">
        <header class="reservation-editor-step__header">
          <span class="reservation-editor-step__number">2</span>
          <div><h3>选择项目</h3><p>每笔预约必须有一个主项目；其余项目作为明细项目。</p></div>
        </header>
        <div class="reservation-editor-project-actions">
          <button type="button" class="reservation-editor-button reservation-editor-button--secondary" :disabled="editorFieldsLocked || isSelecting" @click="chooseProject">选择项目</button>
          <span>第一个项目会自动成为主项目；需要时可点击“设为主项目”切换。</span>
        </div>
        <div v-if="isCatalogOpen && visibleCatalogOptions.length" class="reservation-editor-catalog" aria-label="可选项目">
          <button
            v-for="option in visibleCatalogOptions"
            :key="option.id || option.projectId || option.name"
            type="button"
            :disabled="editorFieldsLocked || option.selectable !== true || !normalizedProjectSource(option)"
            :title="!normalizedProjectSource(option) ? '项目来源尚未由后端确认，不能加入预约。' : ''"
            @click="appendProjects(option)"
          >
            <strong>{{ option.name || option.projectName }}</strong>
            <span>{{ sourceText(option) }}</span>
          </button>
        </div>
        <div v-if="projectLines.length" class="reservation-editor-project-list">
          <article v-for="(line, index) in projectLines" :key="projectKey(line, index)" class="reservation-editor-project-line">
            <div class="reservation-editor-project-line__title">
              <strong>{{ projectName(line) }}</strong>
              <span class="reservation-editor-tag">来源：{{ sourceText(line) }}</span>
              <span class="reservation-editor-tag reservation-editor-tag--role">{{ roleText(line) }}</span>
            </div>
            <div class="reservation-editor-project-line__duration">
              <strong>{{ normalizedRole(line) === 'main' ? '项目服务时长' : '增项服务时长' }}：{{ durationInfo(line).value }}</strong>
              <span>{{ durationInfo(line).description }}</span>
            </div>
            <div class="reservation-editor-project-line__actions">
              <div class="quantity-stepper" aria-label="预约项目数量">
                <button type="button" :disabled="editorFieldsLocked || projectQuantity(line) <= 1" aria-label="减少项目数量" @click="changeProjectQuantity(index, -1)">−</button>
                <span>×{{ projectQuantity(line) }}</span>
                <button type="button" :disabled="editorFieldsLocked" aria-label="增加项目数量" @click="changeProjectQuantity(index, 1)">＋</button>
              </div>
              <button v-if="normalizedRole(line) !== 'main'" type="button" class="reservation-editor-link" :disabled="editorFieldsLocked" @click="selectMainProject(index)">设为主项目</button>
              <button type="button" class="reservation-editor-link reservation-editor-link--danger" :disabled="editorFieldsLocked" @click="removeProject(index)">移除</button>
            </div>
          </article>
        </div>
        <p v-else class="reservation-editor-empty">还没有选择项目。</p>
      </section>

      <section class="reservation-editor-step reservation-editor-step--compact">
        <header class="reservation-editor-step__header">
          <span class="reservation-editor-step__number">3</span>
          <div><h3>预约时间</h3><p>选择顾客到店开始服务的时间；预计结束时间以系统确认结果为准。</p></div>
        </header>
        <label class="reservation-editor-field">
          <span>开始时间</span>
          <input :value="draft.appointmentTime || draft.appointmentStartAt || ''" type="datetime-local" step="900" :disabled="editorFieldsLocked" @input="updateAppointmentTime($event.target.value)">
        </label>
        <p v-if="scheduleIsDirty || isRecalculating" class="reservation-editor-system-note">
          {{ recalculationMessage || '正在重新计算预计时长并检查冲突…' }}
        </p>
        <p v-else-if="draft.expectedEndAt || draft.expectedEndLabel" class="reservation-editor-system-note">系统预计结束：{{ draft.expectedEndLabel || draft.expectedEndAt }}</p>
        <div
          v-if="scheduleCheck.message || scheduleCheck.conflicts.length"
          class="reservation-editor-schedule-check"
          :class="{ 'reservation-editor-schedule-check--blocking': scheduleCheck.blocking }"
          :role="scheduleCheck.blocking ? 'alert' : 'status'"
        >
          <strong>{{ scheduleCheck.blocking ? '当前安排存在冲突' : '排期检查结果' }}</strong>
          <span v-if="scheduleCheck.message">{{ scheduleCheck.message }}</span>
          <ul v-if="scheduleCheck.conflicts.length">
            <li v-for="(conflict, index) in scheduleCheck.conflicts" :key="conflict.id || `${scheduleConflictText(conflict)}-${index}`">
              {{ scheduleConflictText(conflict) || '存在一项由系统返回的排期冲突。' }}
            </li>
          </ul>
        </div>
      </section>

      <section class="reservation-editor-step">
        <header class="reservation-editor-step__header">
          <span class="reservation-editor-step__number">4</span>
          <div><h3>选择手艺人 <em>（可不选）</em></h3><p>未选择时显示“待分配手艺人”，不会阻止预约提交。</p></div>
        </header>
        <div class="reservation-editor-selection-card">
          <div><strong>{{ craftsmanLabel }}</strong><span>选择后只能使用后端按当前预约返回的可选人员；最终可约性由系统保存时再次确认。</span></div>
          <div class="reservation-editor-selection-card__actions">
            <button type="button" class="reservation-editor-button reservation-editor-button--secondary" :disabled="editorFieldsLocked || isSelecting" @click="chooseCraftsmen">选择手艺人</button>
            <button type="button" class="reservation-editor-link" :disabled="editorFieldsLocked || isSelecting" @click="clearCraftsmen">暂不分配</button>
          </div>
        </div>
        <div v-if="isCraftsmanPickerOpen && availableCraftsmen.length" class="reservation-editor-craftsmen-list" aria-label="后端返回的可选手艺人">
          <button
            v-for="craftsman in availableCraftsmen"
            :key="craftsman.id || craftsman.staffId"
            type="button"
            :disabled="editorFieldsLocked || craftsman.selectable !== true"
            :class="{ 'reservation-editor-craftsmen-list__item--active': isCraftsmanSelected(craftsman) }"
            @click="toggleCraftsman(craftsman)"
          >
            <strong>{{ craftsman.name || craftsman.staffName }}</strong>
            <span>{{ craftsman.storeName || craftsman.organizationName || '当前门店可约' }}</span>
          </button>
        </div>
      </section>

      <section class="reservation-editor-step">
        <header class="reservation-editor-step__header">
          <span class="reservation-editor-step__number">5</span>
          <div><h3>选择房间 <em>（可不选）</em></h3><p>不选时显示“待分配房间”，不会占用房间。</p></div>
        </header>
        <div class="reservation-editor-selection-card">
          <div><strong>{{ roomLabel }}</strong><span>{{ selectedRoom?.categoryName || selectedRoom?.category || '未指定房间' }}</span></div>
          <div class="reservation-editor-selection-card__actions">
            <button type="button" class="reservation-editor-button reservation-editor-button--secondary" :disabled="editorFieldsLocked || isSelecting" @click="chooseRoom">选择房间</button>
            <button type="button" class="reservation-editor-link" :disabled="editorFieldsLocked" @click="clearRoom">暂不分配</button>
          </div>
        </div>
        <div v-if="!onSelectRoom && rooms.length" class="reservation-editor-room-list" aria-label="后端返回的可选房间">
          <button v-for="room in rooms" :key="room.id || room.roomId" type="button" :disabled="editorFieldsLocked || room.selectable !== true" :class="{ 'reservation-editor-room-list__item--active': String(room.id || room.roomId) === String(draft.roomId) }" @click="chooseRoomFromList(room)">
            {{ room.name || room.roomName }}{{ room.selectable === true ? '' : ` · ${room.disabledReason || '不可选择'}` }}
          </button>
        </div>
      </section>

      <section class="reservation-editor-step reservation-editor-step--compact">
        <header class="reservation-editor-step__header">
          <span class="reservation-editor-step__number">6</span>
          <div><h3>填写备注 <em>（可不填）</em></h3><p>写下本次预约需要注意的事项。</p></div>
        </header>
        <label class="reservation-editor-field">
          <span>预约备注</span>
          <textarea :value="draft.remark || ''" rows="3" :disabled="editorFieldsLocked" placeholder="例如：顾客希望安静房间" @input="updateRemark($event.target.value)"></textarea>
        </label>
      </section>

      <section class="reservation-editor-step reservation-editor-step--summary">
        <header class="reservation-editor-step__header">
          <span class="reservation-editor-step__number">7</span>
          <div><h3>确认预约内容</h3><p>确认后由系统做最终校验并返回保存结果。</p></div>
        </header>
        <dl class="reservation-editor-summary">
          <div v-for="row in summaryRows" :key="row.label"><dt>{{ row.label }}</dt><dd>{{ row.value }}</dd></div>
        </dl>
        <p v-if="draft.durationSummary || draft.backendSummaryHint" class="reservation-editor-system-note">{{ draft.durationSummary || draft.backendSummaryHint }}</p>
      </section>

      <p v-if="missingFields.length" class="reservation-editor-validation">请先补齐：{{ missingFields.join('、') }}</p>
      <p v-else-if="scheduleIsDirty" class="reservation-editor-validation">{{ recalculationMessage || '请等待系统完成时长与冲突检查。' }}</p>
      <p v-else-if="submitMessage" class="reservation-editor-message">{{ submitMessage }}</p>

      <footer class="reservation-editor-overlay__footer">
        <template v-if="isUncertain">
          <span v-if="!canQueryOriginalResult" class="reservation-editor-locked-tip">原请求信息尚未完整加载，请联系管理员核对；禁止重新提交。</span>
          <button v-else type="button" class="reservation-editor-button reservation-editor-button--primary" @click="queryOriginalResult">查询原预约结果</button>
        </template>
        <template v-else-if="isProcessing">
          <span class="reservation-editor-locked-tip">正在保存，请勿关闭或重复操作。</span>
        </template>
        <template v-else>
          <button type="button" class="reservation-editor-button reservation-editor-button--secondary" :disabled="!canClose" @click="closeEditor">取消</button>
          <button type="submit" class="reservation-editor-button reservation-editor-button--primary" :disabled="!canSubmitReservation || isSelecting">
            {{ isSubmitting ? '正在保存…' : isFailed ? '按原请求重试' : '确认预约' }}
          </button>
        </template>
      </footer>
    </form>
    </section>
  </Teleport>
</template>

<style scoped>
.reservation-editor-overlay {
  position: fixed;
  inset: 0;
  z-index: 50;
  display: flex;
  flex-direction: column;
  background: #f6f8fb;
  color: #1f2937;
}

.reservation-editor-overlay__header,
.reservation-editor-overlay__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 24px;
  padding: 20px 32px;
  background: #fff;
  border-bottom: 1px solid #e5e7eb;
}

.reservation-editor-overlay__header p,
.reservation-editor-overlay__header h2,
.reservation-editor-overlay__header span,
.reservation-editor-step h3,
.reservation-editor-step p {
  margin: 0;
}

.reservation-editor-overlay__header p {
  margin-bottom: 4px;
  color: #1677cc;
  font-size: 13px;
  font-weight: 700;
}

.reservation-editor-overlay__header h2 {
  font-size: 23px;
  line-height: 1.3;
}

.reservation-editor-overlay__header span {
  display: block;
  margin-top: 6px;
  color: #6b7280;
  font-size: 13px;
}

.reservation-editor-submission {
  display: grid;
  gap: 4px;
  margin: 14px 32px 0;
  padding: 12px 14px;
  border: 1px solid #f0d39d;
  border-radius: 9px;
  background: #fffaf0;
  color: #805b1f;
  font-size: 13px;
  line-height: 1.55;
}

.reservation-editor-submission--failed {
  border-color: #f0cdcd;
  background: #fff8f8;
  color: #a84b4b;
}

.reservation-editor-submission small {
  color: #7a8795;
}

.reservation-editor-schedule-check {
  display: grid;
  gap: 4px;
  margin-top: 10px;
  padding: 10px 12px;
  border: 1px solid #b7ebc6;
  border-radius: 8px;
  background: #f2fff6;
  color: #237a45;
  font-size: 13px;
  line-height: 1.5;
}

.reservation-editor-schedule-check--blocking {
  border-color: #ffccc7;
  background: #fff7f6;
  color: #b42318;
}

.reservation-editor-schedule-check span,
.reservation-editor-schedule-check ul {
  margin: 0;
}

.reservation-editor-schedule-check ul {
  padding-left: 18px;
}

.reservation-editor-overlay__body {
  flex: 1;
  overflow: auto;
  padding: 24px 32px 112px;
}

.reservation-editor-step {
  max-width: 980px;
  margin: 0 auto 16px;
  padding: 20px 22px;
  border: 1px solid #e5e7eb;
  border-radius: 14px;
  background: #fff;
}

.reservation-editor-step__header {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  margin-bottom: 16px;
}

.reservation-editor-step__number {
  display: inline-grid;
  width: 26px;
  height: 26px;
  flex: 0 0 auto;
  place-items: center;
  border-radius: 50%;
  background: #e6f4ff;
  color: #1677cc;
  font-size: 13px;
  font-weight: 800;
}

.reservation-editor-step h3 {
  font-size: 16px;
}

.reservation-editor-step h3 em {
  color: #9ca3af;
  font-size: 13px;
  font-style: normal;
  font-weight: 400;
}

.reservation-editor-step p {
  margin-top: 4px;
  color: #6b7280;
  font-size: 13px;
}

.reservation-editor-selection-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  min-height: 70px;
  padding: 14px 16px;
  border-radius: 10px;
  background: #f9fafb;
}

.reservation-editor-selection-card > div:first-child {
  min-width: 0;
}

.reservation-editor-selection-card strong,
.reservation-editor-selection-card span {
  display: block;
}

.reservation-editor-selection-card strong {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.reservation-editor-selection-card span {
  margin-top: 5px;
  color: #6b7280;
  font-size: 13px;
}

.reservation-editor-selection-card__actions {
  display: flex;
  flex: 0 0 auto;
  align-items: center;
  gap: 10px;
}

.reservation-editor-button {
  min-height: 36px;
  padding: 0 14px;
  border: 1px solid transparent;
  border-radius: 8px;
  cursor: pointer;
  font: inherit;
  font-size: 14px;
  font-weight: 600;
}

.reservation-editor-button:disabled,
.reservation-editor-link:disabled {
  cursor: not-allowed;
  opacity: .5;
}

.reservation-editor-button--primary {
  background: #1677cc;
  color: #fff;
}

.reservation-editor-button--secondary {
  border-color: #d1d5db;
  background: #fff;
  color: #374151;
}

.reservation-editor-project-actions {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 12px;
}

.reservation-editor-project-actions span {
  color: #6b7280;
  font-size: 13px;
}

.reservation-editor-catalog,
.reservation-editor-room-list,
.reservation-editor-craftsmen-list {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 12px;
}

.reservation-editor-catalog button,
.reservation-editor-room-list button,
.reservation-editor-craftsmen-list button {
  display: flex;
  align-items: baseline;
  gap: 6px;
  padding: 9px 12px;
  border: 1px solid #bae0ff;
  border-radius: 8px;
  background: #f0f7ff;
  color: #0958d9;
  cursor: pointer;
  font: inherit;
  font-size: 13px;
}

.reservation-editor-catalog button:disabled,
.reservation-editor-room-list button:disabled {
  cursor: not-allowed;
  opacity: .5;
}

.reservation-editor-catalog span {
  color: #1677cc;
}

.reservation-editor-room-list button {
  border-color: #d1d5db;
  background: #fff;
  color: #374151;
}

.reservation-editor-craftsmen-list button {
  display: grid;
  gap: 3px;
  border-color: #d1d5db;
  background: #fff;
  color: #374151;
  text-align: left;
}

.reservation-editor-craftsmen-list button:disabled {
  cursor: not-allowed;
  opacity: .5;
}

.reservation-editor-craftsmen-list span {
  color: #6b7280;
  font-size: 12px;
}

.reservation-editor-room-list__item--active {
  border-color: #1677cc !important;
  background: #e6f4ff !important;
  color: #0958d9 !important;
}

.reservation-editor-craftsmen-list__item--active {
  border-color: #1677cc !important;
  background: #e6f4ff !important;
  color: #0958d9 !important;
}

.reservation-editor-project-list {
  display: grid;
  gap: 10px;
}

.reservation-editor-project-line {
  display: grid;
  grid-template-columns: minmax(180px, 1fr) minmax(260px, 1.4fr) auto;
  align-items: center;
  gap: 16px;
  padding: 14px 16px;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
}

.reservation-editor-project-line__title,
.reservation-editor-project-line__duration {
  min-width: 0;
}

.reservation-editor-project-line__title strong,
.reservation-editor-project-line__duration strong,
.reservation-editor-project-line__duration span {
  display: block;
}

.reservation-editor-project-line__title strong {
  margin-bottom: 7px;
}

.reservation-editor-tag {
  display: inline-block;
  margin-right: 6px;
  padding: 3px 7px;
  border-radius: 5px;
  background: #f3f4f6;
  color: #4b5563;
  font-size: 12px;
}

.reservation-editor-tag--role {
  background: #e6f4ff;
  color: #1677cc;
}

.reservation-editor-project-line__duration strong {
  font-size: 13px;
}

.reservation-editor-project-line__duration span {
  margin-top: 4px;
  color: #6b7280;
  font-size: 12px;
  line-height: 1.45;
}

.reservation-editor-project-line__actions {
  display: flex;
  align-items: center;
  gap: 10px;
  white-space: nowrap;
}

.reservation-editor-link {
  padding: 0;
  border: 0;
  background: transparent;
  color: #1677cc;
  cursor: pointer;
  font: inherit;
  font-size: 13px;
}

.reservation-editor-link--danger {
  color: #dc2626;
}

.reservation-editor-empty {
  padding: 20px;
  border: 1px dashed #d1d5db;
  border-radius: 10px;
  text-align: center;
}

.reservation-editor-field {
  display: block;
  max-width: 430px;
}

.reservation-editor-field > span {
  display: block;
  margin-bottom: 7px;
  color: #374151;
  font-size: 13px;
  font-weight: 600;
}

.reservation-editor-field input,
.reservation-editor-field textarea {
  box-sizing: border-box;
  width: 100%;
  padding: 10px 11px;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  outline: none;
  color: #1f2937;
  font: inherit;
}

.reservation-editor-field input:focus,
.reservation-editor-field textarea:focus {
  border-color: #1677cc;
  box-shadow: 0 0 0 3px rgba(124, 58, 237, .12);
}

.reservation-editor-system-note {
  margin-top: 10px !important;
  color: #0958d9 !important;
}

.reservation-editor-summary {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  overflow: hidden;
  margin: 0;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
}

.reservation-editor-summary div {
  display: grid;
  grid-template-columns: 96px minmax(0, 1fr);
  min-height: 46px;
  border-bottom: 1px solid #e5e7eb;
}

.reservation-editor-summary div:nth-last-child(-n + 2) {
  border-bottom: 0;
}

.reservation-editor-summary dt,
.reservation-editor-summary dd {
  display: flex;
  align-items: center;
  margin: 0;
  padding: 0 12px;
  font-size: 13px;
}

.reservation-editor-summary dt {
  background: #f9fafb;
  color: #6b7280;
}

.reservation-editor-summary dd {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.reservation-editor-validation,
.reservation-editor-message {
  max-width: 980px;
  margin: 0 auto 16px;
  padding: 11px 14px;
  border-radius: 8px;
  background: #fff7ed;
  color: #c2410c;
  font-size: 14px;
}

.reservation-editor-message {
  background: #eff6ff;
  color: #1d4ed8;
}

.reservation-editor-locked-tip {
  color: #6b7280;
  font-size: 13px;
}

.reservation-editor-overlay__footer {
  position: fixed;
  right: 0;
  bottom: 0;
  left: 0;
  z-index: 1;
  justify-content: flex-end;
  border-top: 1px solid #e5e7eb;
  border-bottom: 0;
  box-shadow: 0 -8px 22px rgba(15, 23, 42, .06);
}

@media (max-width: 760px) {
  .reservation-editor-overlay__header,
  .reservation-editor-overlay__footer,
  .reservation-editor-overlay__body {
    padding-right: 16px;
    padding-left: 16px;
  }

  .reservation-editor-overlay__header {
    align-items: flex-start;
  }

  .reservation-editor-project-line {
    grid-template-columns: 1fr;
    gap: 10px;
  }

  .reservation-editor-summary {
    grid-template-columns: 1fr;
  }

  .reservation-editor-summary div:nth-last-child(-n + 2) {
    border-bottom: 1px solid #e5e7eb;
  }

  .reservation-editor-summary div:last-child {
    border-bottom: 0;
  }

  .reservation-editor-selection-card {
    align-items: flex-start;
    flex-direction: column;
  }
}
</style>

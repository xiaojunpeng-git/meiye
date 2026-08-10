<script setup>
import { computed, ref, watch } from 'vue'
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
 *       // 项目时长来自项目设置；未设置时按 60 分钟：
 *       projectServiceDuration, addonServiceDuration,
 *       appliedDurationMinutes, appliedDurationLabel, durationDescription
 *     }
 *   ]
 * }
 *
 * craftsmen 是已经由后端选择、可用于当前预约的手艺人列表；不是本地候选名单。
 * catalogOptions、rooms 也应由后端按当前会员、门店和预约上下文筛选后提供。
 * onSelectMember / onSelectCraftsmen / onSelectRoom 可以打开共用选择器，
 * 并返回选中实体（或 { member / projects / craftsmen / room }）。
 * onSubmit 只接收当前草稿快照；预约保存不依赖收银、房间或服务单上下文。
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
  onSelectCraftsmen: {
    type: Function,
    default: null
  },
  onSelectRoom: {
    type: Function,
    default: null
  },
  onRefreshCatalog: {
    type: Function,
    default: null
  },
  onSubmit: {
    type: Function,
    default: null
  },
  onRecalculate: {
    type: Function,
    default: null
  }
})

const emit = defineEmits(['close', 'update:modelValue', 'submitted', 'resume-editing'])
const draft = ref(cloneDraft(props.modelValue))
const missingFields = ref([])
const submitMessage = ref('')
const isSelecting = ref(false)
const isCatalogOpen = ref(false)
const catalogKeyword = ref('')
const catalogCategory = ref('')
const catalogSource = ref('purchased')
const selectedCatalogProjectKeys = ref([])
const refreshedCatalogOptions = ref(null)
const isCraftsmanPickerOpen = ref(false)
const editorDialogRef = ref(null)

const selectedMember = computed(() => draft.value.member || props.member || null)
const hasSelectedMember = computed(() => Boolean(
  draft.value.memberId
  || selectedMember.value?.id
  || selectedMember.value?.memberId
  || selectedMember.value?.name
  || selectedMember.value?.memberName
))
const isEditingReservation = computed(() => Boolean(
  draft.value.id
  || draft.value.reservationId
  || draft.value.appointmentId
))
const projectStorageKey = computed(() => (Array.isArray(draft.value.projectLines) ? 'projectLines' : 'projects'))
const projectLines = computed(() => normalizeProjects(readProjects(draft.value)))
const selectedCraftsmen = computed(() => {
  if (Array.isArray(draft.value.craftsmen)) return draft.value.craftsmen
  return Array.isArray(props.craftsmen) ? props.craftsmen : []
})
const availableCraftsmen = computed(() => Array.isArray(props.craftsmenOptions) ? props.craftsmenOptions : [])
const selectedRoom = computed(() => draft.value.room || findRoom(draft.value.roomId) || null)
const projectCatalogOptions = computed(() => (Array.isArray(refreshedCatalogOptions.value)
  ? refreshedCatalogOptions.value
  : (Array.isArray(props.catalogOptions) ? props.catalogOptions : []))
  .filter(isReservationProjectOption))
const catalogCategories = computed(() => {
  const categories = new Set()
  projectCatalogOptions.value.forEach((option) => categories.add(projectCategory(option)))
  return [...categories].sort((left, right) => left.localeCompare(right, 'zh-CN'))
})
const visibleCatalogOptions = computed(() => {
  const keyword = catalogKeyword.value.trim().toLocaleLowerCase()
  return projectCatalogOptions.value.filter((option) => {
    if (catalogSource.value === 'purchased' && normalizedProjectSource(option) !== 'card') return false
    if (catalogCategory.value && projectCategory(option) !== catalogCategory.value) return false
    if (!keyword) return true
    return projectName(option).toLocaleLowerCase().includes(keyword)
  })
})
const selectedCatalogOptions = computed(() => {
  const selected = new Set(selectedCatalogProjectKeys.value)
  return projectCatalogOptions.value.filter((option) => selected.has(catalogProjectKey(option)))
})
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
const submissionStatus = computed(() => normalizeSubmissionStatus(props.submission.status))
const isProcessing = computed(() => submissionStatus.value === 'processing')
const isFailed = computed(() => submissionStatus.value === 'failed')
const editorFieldsLocked = computed(() => (
  props.isSubmitting
  || isProcessing.value
))
const canClose = computed(() => !props.isSubmitting && !isProcessing.value)
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
))
const submissionTitle = computed(() => {
  if (isProcessing.value) return '正在保存预约'
  if (isFailed.value) return '预约保存未完成'
  return ''
})
const submissionDescription = computed(() => {
  if (isProcessing.value) return '请勿关闭或重复操作。'
  if (isFailed.value) return props.submission.message || props.submission.failureReason || '保存未完成，请重试。'
  return ''
})

watch(
  () => props.modelValue,
  (value) => {
    draft.value = cloneDraft(value)
    missingFields.value = []
    submitMessage.value = ''
    resetProjectCatalog()
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
  if (['pending', 'result_pending', 'pending_confirmation', 'confirming', '结果确认中', '待确认', 'result_unknown', 'unknown', '结果未知', '未知'].includes(status)) return 'failed'
  if (['failed', 'failure', 'error', 'conflict', '失败', '冲突'].includes(status)) return 'failed'
  if (['succeeded', 'success', 'completed', '已完成', '成功'].includes(status)) return 'succeeded'
  if (!status || status === 'editing') return 'editing'
  return 'failed'
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
  if (isFailed.value) emit('resume-editing')
  draft.value = cloneDraft(next)
  emit('update:modelValue', cloneDraft(next))
}

function updateDraft(partial) {
  commit({ ...draft.value, ...partial })
}

function replaceProjects(lines) {
  updateDraft({ [projectStorageKey.value]: normalizeProjects(lines) })
}

function configuredDurationMinutes(line) {
  const candidates = [
    line?.appliedDurationMinutes,
    line?.duration?.appliedMinutes,
    line?.projectServiceDuration,
    line?.addonServiceDuration,
    line?.serviceDurationMinutes,
    line?.duration?.projectServiceDuration,
    line?.duration?.addonServiceDuration,
    typeof line?.duration === 'number' ? line.duration : 0
  ]
  const duration = candidates.map(Number).find((value) => Number.isFinite(value) && value > 0)
  return duration || 60
}

function formatLocalDateTime(value) {
  const year = value.getFullYear()
  const month = String(value.getMonth() + 1).padStart(2, '0')
  const day = String(value.getDate()).padStart(2, '0')
  const hour = String(value.getHours()).padStart(2, '0')
  const minute = String(value.getMinutes()).padStart(2, '0')
  return `${year}-${month}-${day}T${hour}:${minute}`
}

function withExpectedEnd(value) {
  const next = cloneDraft(value)
  const appointmentTime = String(next.appointmentTime || next.appointmentStartAt || '').trim()
  const start = appointmentTime ? new Date(appointmentTime) : null
  const duration = readProjects(next).reduce((total, line) => total + configuredDurationMinutes(line) * projectQuantity(line), 0)
  if (!start || Number.isNaN(start.getTime()) || !duration) {
    delete next.expectedEndAt
    delete next.expectedEndLabel
    delete next.durationSummary
    return next
  }
  const end = new Date(start.getTime() + duration * 60 * 1000)
  next.expectedEndAt = formatLocalDateTime(end)
  next.expectedEndLabel = formatLocalDateTime(end).replace('T', ' ')
  next.durationSummary = `预计服务时长 ${duration} 分钟`
  return next
}

function applyScheduleChange(nextDraft) {
  commit(withExpectedEnd(nextDraft))
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
  return normalizedProjectSource(line) === 'card' ? '已购买' : '未购买'
}

function normalizedProjectSource(line) {
  const source = String(line?.source || line?.projectSource || line?.sourceLabel || '').trim().toLowerCase()
  if (['card', 'entitlement', '已购买', '卡内', '卡内项目'].includes(source)) return 'card'
  return 'unpaid'
}

function projectName(line) {
  return line?.name || line?.projectName || '未命名项目'
}

function projectKey(line, index) {
  return line?.id || line?.projectLineId || line?.projectId || `${projectName(line)}-${index}`
}

function catalogProjectKey(option) {
  return [
    normalizedProjectSource(option),
    option?.projectId || option?.id || '',
    option?.skuId || option?.sku_id || '',
    option?.entitlementSourceDetailId || option?.sourceDetailId || ''
  ].join(':')
}

function projectCategory(option) {
  const categories = Array.isArray(option?.categoryNames) ? option.categoryNames : []
  return String(
    option?.categoryName
    || option?.category
    || categories.find((item) => String(item || '').trim())
    || '未分类'
  ).trim() || '未分类'
}

function isReservationProjectOption(option) {
  if (!option || typeof option !== 'object') return false
  const type = String(option.productType ?? option.itemType ?? option.type ?? option.kind ?? '').trim().toLowerCase()
  if (type && !['6', '项目', 'project'].includes(type)) return false
  return Boolean(option.projectId || option.id)
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
    value: '60分钟',
    description: description || '项目未设置服务时长，按系统默认60分钟。'
  }
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
    source,
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
  closeProjectCatalog()
  clearValidation('项目')
}

async function chooseMember() {
  // 预约编辑仅允许调整项目、时间、手艺人、房间和备注；会员归属创建后保持不变。
  if (isEditingReservation.value || !props.onSelectMember || isSelecting.value || editorFieldsLocked.value) return
  isSelecting.value = true
  try {
    const result = await props.onSelectMember({ draft: submitDraft() })
    const member = result?.member || result
    if (!member || typeof member !== 'object') return
    const memberId = member.memberId || member.id || draft.value.memberId
    applyScheduleChange({
      ...draft.value,
      member,
      memberId,
      memberName: member.memberName || member.name || draft.value.memberName
    })
    clearValidation('会员')
    isSelecting.value = false
    if (typeof props.onRefreshCatalog === 'function' && memberId) {
      Promise.resolve(props.onRefreshCatalog({ memberId }))
        .then((catalog) => {
          if (Array.isArray(catalog?.catalogOptions) && String(draft.value.memberId || '') === String(memberId)) {
            refreshedCatalogOptions.value = catalog.catalogOptions
          }
        })
        .catch(() => {})
    }
  } finally {
    isSelecting.value = false
  }
}

function resetProjectCatalog() {
  catalogKeyword.value = ''
  catalogCategory.value = ''
  catalogSource.value = 'purchased'
  selectedCatalogProjectKeys.value = []
}

function openProjectCatalog() {
  if (editorFieldsLocked.value) return
  resetProjectCatalog()
  isCatalogOpen.value = true
}

function closeProjectCatalog() {
  isCatalogOpen.value = false
  resetProjectCatalog()
}

function isCatalogProjectSelected(option) {
  return selectedCatalogProjectKeys.value.includes(catalogProjectKey(option))
}

function toggleCatalogProject(option) {
  if (editorFieldsLocked.value || option?.selectable !== true || !normalizedProjectSource(option)) return
  const key = catalogProjectKey(option)
  if (!key || key === ':') return
  selectedCatalogProjectKeys.value = isCatalogProjectSelected(option)
    ? selectedCatalogProjectKeys.value.filter((item) => item !== key)
    : [...selectedCatalogProjectKeys.value, key]
}

function confirmCatalogProjects() {
  if (editorFieldsLocked.value || !selectedCatalogOptions.value.length) return
  appendProjects(selectedCatalogOptions.value)
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
  if (projectLines.value.some((line) => normalizedProjectSource(line) === 'card' && !Number(line?.entitlementSourceDetailId || line?.sourceDetailId))) {
    missing.push('已购项目来源')
  }
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
        <span>按顺序填写即可；确认后直接保存预约单据。</span>
      </div>
      <button type="button" class="reservation-editor-button reservation-editor-button--secondary" :disabled="!canClose" @click="closeEditor">关闭</button>
    </header>

    <section v-if="isProcessing || isFailed" class="reservation-editor-submission" :class="`reservation-editor-submission--${submissionStatus}`" aria-live="polite">
      <strong>{{ submissionTitle }}</strong>
      <span>{{ submissionDescription }}</span>
    </section>

    <form class="reservation-editor-overlay__body" @submit.prevent="submit">
      <section class="reservation-editor-step">
        <header class="reservation-editor-step__header">
          <span class="reservation-editor-step__number">1</span>
          <div><h3>选择会员</h3><p>预约需要先确定服务对象。</p></div>
        </header>
        <div class="reservation-editor-selection-card">
          <div><strong>{{ memberLabel }}</strong><span v-if="selectedMember?.memberNo">会员编号：{{ selectedMember.memberNo }}</span></div>
          <button
            v-if="!isEditingReservation"
            type="button"
            class="reservation-editor-button reservation-editor-button--secondary"
            :disabled="editorFieldsLocked || isSelecting"
            @click="chooseMember"
          >选择会员</button>
          <span v-else class="reservation-editor-selection-card__readonly">会员创建后不可修改</span>
        </div>
      </section>

      <section class="reservation-editor-step">
        <header class="reservation-editor-step__header">
          <span class="reservation-editor-step__number">2</span>
          <div><h3>选择项目</h3><p>每笔预约必须有一个主项目；其余项目作为明细项目。</p></div>
        </header>
        <div class="reservation-editor-project-actions">
          <button type="button" class="reservation-editor-button reservation-editor-button--secondary" :disabled="editorFieldsLocked" @click="openProjectCatalog">选择项目</button>
          <span>第一个项目会自动成为主项目；需要时可点击“设为主项目”切换。</span>
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
          <div><h3>预约时间</h3><p>选择顾客到店开始服务的时间；预计结束时间按项目时长计算。</p></div>
        </header>
        <label class="reservation-editor-field">
          <span>开始时间</span>
          <input :value="draft.appointmentTime || draft.appointmentStartAt || ''" type="datetime-local" step="900" :disabled="editorFieldsLocked" @input="updateAppointmentTime($event.target.value)">
        </label>
        <p v-if="draft.expectedEndAt || draft.expectedEndLabel" class="reservation-editor-system-note">预计结束：{{ draft.expectedEndLabel || draft.expectedEndAt }}</p>
      </section>

      <section class="reservation-editor-step">
        <header class="reservation-editor-step__header">
          <span class="reservation-editor-step__number">4</span>
          <div><h3>选择手艺人 <em>（可不选）</em></h3><p>未选择时显示“待分配手艺人”，不会阻止预约提交。</p></div>
        </header>
        <div class="reservation-editor-selection-card">
          <div><strong>{{ craftsmanLabel }}</strong><span>可按实际安排选择手艺人。</span></div>
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
          <div><h3>确认预约内容</h3><p>确认后保存预约单据。</p></div>
        </header>
        <dl class="reservation-editor-summary">
          <div v-for="row in summaryRows" :key="row.label"><dt>{{ row.label }}</dt><dd>{{ row.value }}</dd></div>
        </dl>
        <p v-if="draft.durationSummary || draft.backendSummaryHint" class="reservation-editor-system-note">{{ draft.durationSummary || draft.backendSummaryHint }}</p>
      </section>

      <p v-if="missingFields.length" class="reservation-editor-validation">请先补齐：{{ missingFields.join('、') }}</p>
      <p v-else-if="submitMessage" class="reservation-editor-message">{{ submitMessage }}</p>

      <footer class="reservation-editor-overlay__footer">
        <template v-if="isProcessing">
          <span class="reservation-editor-locked-tip">正在保存，请勿关闭或重复操作。</span>
        </template>
        <template v-else>
          <button type="button" class="reservation-editor-button reservation-editor-button--secondary" :disabled="!canClose" @click="closeEditor">取消</button>
          <button type="submit" class="reservation-editor-button reservation-editor-button--primary" :disabled="!canSubmitReservation || isSelecting">
            {{ isSubmitting ? '正在保存…' : '确认预约' }}
          </button>
        </template>
      </footer>
    </form>

    <section
      v-if="isCatalogOpen"
      class="reservation-project-picker"
      role="dialog"
      aria-modal="true"
      aria-labelledby="reservation-project-picker-title"
    >
      <div class="reservation-project-picker__backdrop" @click="closeProjectCatalog" />
      <section class="reservation-project-picker__panel">
        <header class="reservation-project-picker__header">
          <div>
            <h3 id="reservation-project-picker-title">选择项目</h3>
            <p>仅展示项目，可筛选后多选并一次加入预约。</p>
          </div>
          <button type="button" class="reservation-editor-button reservation-editor-button--secondary" @click="closeProjectCatalog">关闭</button>
        </header>

        <div class="reservation-project-picker__filters">
          <label>
            <span>项目来源</span>
            <select v-model="catalogSource">
              <option value="purchased">已购买</option>
              <option value="all">全部</option>
            </select>
          </label>
          <label>
            <span>项目分类</span>
            <select v-model="catalogCategory">
              <option value="">全部分类</option>
              <option v-for="category in catalogCategories" :key="category" :value="category">{{ category }}</option>
            </select>
          </label>
          <label>
            <span>项目名称</span>
            <input v-model="catalogKeyword" type="search" placeholder="搜索项目名称">
          </label>
        </div>

        <main v-if="visibleCatalogOptions.length" class="reservation-project-picker__list" aria-label="可选项目">
          <label
            v-for="option in visibleCatalogOptions"
            :key="catalogProjectKey(option)"
            class="reservation-project-picker__item"
            :class="{ 'reservation-project-picker__item--selected': isCatalogProjectSelected(option), 'reservation-project-picker__item--disabled': option.selectable !== true || !normalizedProjectSource(option) }"
          >
            <input
              type="checkbox"
              :checked="isCatalogProjectSelected(option)"
              :disabled="editorFieldsLocked || option.selectable !== true || !normalizedProjectSource(option)"
              @change="toggleCatalogProject(option)"
            >
            <span class="reservation-project-picker__item-main">
              <strong>{{ projectName(option) }}</strong>
              <small>{{ projectCategory(option) }} · {{ sourceText(option) }}</small>
            </span>
            <small v-if="option.selectable !== true || !normalizedProjectSource(option)" class="reservation-project-picker__unavailable">
              {{ option.disabledReason || '当前不可选择' }}
            </small>
          </label>
        </main>
        <p v-else class="reservation-project-picker__empty">没有符合条件的项目。</p>

        <footer class="reservation-project-picker__footer">
          <span>已选择 {{ selectedCatalogOptions.length }} 个项目</span>
          <div>
            <button type="button" class="reservation-editor-button reservation-editor-button--secondary" @click="closeProjectCatalog">取消</button>
            <button type="button" class="reservation-editor-button reservation-editor-button--primary" :disabled="!selectedCatalogOptions.length || editorFieldsLocked" @click="confirmCatalogProjects">加入预约</button>
          </div>
        </footer>
      </section>
    </section>
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

.reservation-editor-selection-card__readonly {
  flex: 0 0 auto;
  color: #6b7280;
  white-space: nowrap;
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

.reservation-project-picker {
  position: fixed;
  inset: 0;
  z-index: 2;
  display: grid;
  place-items: center;
  padding: 24px;
}

.reservation-project-picker__backdrop {
  position: absolute;
  inset: 0;
  background: rgba(15, 23, 42, .42);
}

.reservation-project-picker__panel {
  position: relative;
  display: flex;
  width: min(760px, 100%);
  max-height: min(720px, calc(100vh - 48px));
  flex-direction: column;
  overflow: hidden;
  border: 1px solid #d8e0ea;
  border-radius: 8px;
  background: #fff;
  box-shadow: 0 18px 46px rgba(15, 23, 42, .24);
}

.reservation-project-picker__header,
.reservation-project-picker__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 16px 20px;
}

.reservation-project-picker__header {
  border-bottom: 1px solid #e5e7eb;
}

.reservation-project-picker__header h3,
.reservation-project-picker__header p {
  margin: 0;
}

.reservation-project-picker__header h3 {
  color: #1f2937;
  font-size: 17px;
}

.reservation-project-picker__header p {
  margin-top: 4px;
  color: #6b7280;
  font-size: 13px;
}

.reservation-project-picker__filters {
  display: grid;
  grid-template-columns: minmax(180px, .72fr) minmax(240px, 1fr);
  gap: 12px;
  padding: 16px 20px;
  border-bottom: 1px solid #eef1f5;
  background: #fafbfd;
}

.reservation-project-picker__filters label {
  display: grid;
  gap: 6px;
  color: #4b5563;
  font-size: 13px;
  font-weight: 600;
}

.reservation-project-picker__filters select,
.reservation-project-picker__filters input {
  box-sizing: border-box;
  width: 100%;
  height: 36px;
  padding: 0 10px;
  border: 1px solid #d1d5db;
  border-radius: 6px;
  background: #fff;
  color: #1f2937;
  font: inherit;
  font-weight: 400;
}

.reservation-project-picker__filters select:focus,
.reservation-project-picker__filters input:focus {
  border-color: #1677cc;
  outline: 0;
  box-shadow: 0 0 0 3px rgba(22, 119, 204, .12);
}

.reservation-project-picker__list {
  display: grid;
  min-height: 180px;
  overflow: auto;
  padding: 8px 20px;
}

.reservation-project-picker__item {
  display: flex;
  min-height: 64px;
  align-items: center;
  gap: 12px;
  padding: 10px 4px;
  border-bottom: 1px solid #edf0f4;
  color: #1f2937;
  cursor: pointer;
}

.reservation-project-picker__item:last-child {
  border-bottom: 0;
}

.reservation-project-picker__item input {
  width: 16px;
  height: 16px;
  margin: 0;
  accent-color: #1677cc;
}

.reservation-project-picker__item-main {
  display: grid;
  min-width: 0;
  gap: 4px;
}

.reservation-project-picker__item-main strong {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 14px;
}

.reservation-project-picker__item-main small {
  color: #6b7280;
  font-size: 12px;
}

.reservation-project-picker__item--selected {
  margin: 0 -8px;
  padding-right: 12px;
  padding-left: 12px;
  border-radius: 6px;
  background: #f0f7ff;
}

.reservation-project-picker__item--disabled {
  cursor: not-allowed;
  opacity: .5;
}

.reservation-project-picker__unavailable {
  margin-left: auto;
  color: #b45309;
  font-size: 12px;
}

.reservation-project-picker__empty {
  margin: 0;
  padding: 48px 20px;
  color: #6b7280;
  text-align: center;
}

.reservation-project-picker__footer {
  border-top: 1px solid #e5e7eb;
  background: #fff;
  color: #4b5563;
  font-size: 13px;
}

.reservation-project-picker__footer > div {
  display: flex;
  gap: 10px;
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

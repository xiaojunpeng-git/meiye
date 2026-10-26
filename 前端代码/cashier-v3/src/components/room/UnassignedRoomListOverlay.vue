<script setup>
import { computed, ref } from 'vue'
import { useModalFocusTrap } from '@/composables/useModalFocusTrap'

/**
 * 待分配房间列表。
 *
 * list 建议由后端按当前账号的数据权限返回：
 * {
 *   records: [{
 *     id, revision, sourceLabel, statusLabel,
 *     member: { name, memberNo, phone },
 *     timeRangeLabel, mainProjectName, detailProjectCount,
 *     craftsmenLabel, reservationNo, serviceOrderNo,
 *     actions: {
 *       view: { visible, enabled, disabledReason },
 *       assign: { visible, enabled, disabledReason }
 *     }
 *   }],
 *   page, pageSize: 20, total
 * }
 *
 * 本组件只展示后端快照。业务状态、动作是否显示、是否可用以及禁用原因均以
 * actions 为准；不在前端推断服务、预约、房态或权限，也不提供其他业务动作。
 */
const props = defineProps({
  list: {
    type: [Object, Array],
    default: () => ({})
  },
  isLoading: {
    type: Boolean,
    default: false
  },
  activeRecordKey: {
    type: [String, Number],
    default: ''
  },
  errorMessage: {
    type: String,
    default: ''
  }
})

const emit = defineEmits(['close', 'query', 'view', 'assign'])
const unassignedDialogRef = ref(null)

const PAGE_SIZE = 20

const records = computed(() => {
  const source = Array.isArray(props.list)
    ? props.list
    : firstArray(props.list, ['records', 'items', 'list', 'data'])
  return source.slice(0, PAGE_SIZE)
})
const currentPage = computed(() => positiveInteger(firstValue(props.list, ['page', 'currentPage', 'pageNo'])) || 1)
const total = computed(() => {
  const value = nonnegativeInteger(firstValue(props.list, ['total', 'totalCount', 'count']))
  return value === null ? records.value.length : value
})
const totalPages = computed(() => Math.max(1, currentPage.value, Math.ceil(total.value / PAGE_SIZE)))
const canGoPrevious = computed(() => !props.isLoading && currentPage.value > 1)
const canGoNext = computed(() => !props.isLoading && currentPage.value < totalPages.value)

function firstValue(source, keys) {
  if (!source || typeof source !== 'object') return ''
  for (const key of keys) {
    const value = source[key]
    if (value !== undefined && value !== null && value !== '') return value
  }
  return ''
}

function firstObject(source, keys) {
  if (!source || typeof source !== 'object') return {}
  for (const key of keys) {
    const value = source[key]
    if (value && typeof value === 'object' && !Array.isArray(value)) return value
  }
  return {}
}

function firstArray(source, keys) {
  if (!source || typeof source !== 'object') return []
  for (const key of keys) {
    if (Array.isArray(source[key])) return source[key]
  }
  return []
}

function positiveInteger(value) {
  const number = Number(value)
  return Number.isInteger(number) && number > 0 ? number : null
}

function nonnegativeInteger(value) {
  const number = Number(value)
  return Number.isInteger(number) && number >= 0 ? number : null
}

function text(value, fallback = '—') {
  return value === undefined || value === null || value === '' ? fallback : String(value)
}

function recordKey(record, index) {
  return String(firstValue(record, [
    'recordKey',
    'id',
    'serviceOrderId',
    'serviceSessionId',
    'reservationId',
    'serviceOrderNo',
    'reservationNo'
  ]) || `unassigned-${currentPage.value}-${index}`)
}

function memberOf(record) {
  return firstObject(record, ['member', 'memberInfo', 'customer'])
}

function memberName(record) {
  const member = memberOf(record)
  return text(
    firstValue(member, ['name', 'memberName', 'customerName'])
      || firstValue(record, ['memberName', 'customerName'])
  )
}

function memberMeta(record) {
  const member = memberOf(record)
  return [
    firstValue(member, ['memberNo', 'memberCode', 'code'])
      || firstValue(record, ['memberNo', 'memberCode']),
    firstValue(member, ['phone', 'mobile', 'memberPhone'])
      || firstValue(record, ['memberPhone', 'phone', 'mobile'])
  ].filter(Boolean).join(' · ')
}

function sourceLabel(record) {
  return text(firstValue(record, ['sourceLabel', 'sourceName', 'source']))
}

function statusLabel(record) {
  return text(firstValue(record, ['statusLabel', 'statusName', 'backendStatusLabel', 'status']))
}

function timeRange(record) {
  const label = firstValue(record, ['timeRangeLabel', 'serviceTimeRangeLabel', 'reservationTimeRangeLabel'])
    || firstValue(firstObject(record, ['time']), ['displayText', 'label'])
  if (label) return text(label)

  const time = firstObject(record, ['time'])
  const start = firstValue(time, ['startAt'])
    || firstValue(record, [
    'startAt',
    'serviceStartAt',
    'serviceStartedAt',
    'appointmentStartAt',
    'reservationStartAt'
  ])
  const end = firstValue(time, ['endAt'])
    || firstValue(record, [
    'endAt',
    'expectedEndAt',
    'expectedEndedAt',
    'appointmentEndAt',
    'reservationEndAt'
  ])
  if (start && end) return `${start} 至 ${end}`
  return text(start || end)
}

function projectSummary(record) {
  const summary = firstValue(record, ['projectSummary', 'projectSummaryLabel'])
  if (summary && typeof summary === 'object') {
    const displayText = firstValue(summary, ['displayText', 'label'])
    if (displayText) return text(displayText)
    const primaryProjectName = firstValue(summary, ['primaryProjectName', 'mainProjectName'])
    const totalCount = positiveInteger(firstValue(summary, ['totalCount', 'projectCount']))
    if (primaryProjectName) return totalCount && totalCount > 1
      ? `${primaryProjectName} + ${totalCount - 1} 项`
      : text(primaryProjectName)
  }
  if (summary) return text(summary)

  const mainProject = firstValue(record, ['mainProjectName', 'primaryProjectName'])
    || firstValue(firstObject(record, ['mainProject', 'primaryProject']), ['name', 'projectName', 'label'])
  const detailCount = nonnegativeInteger(firstValue(record, ['detailProjectCount', 'additionalProjectCount', 'addonProjectCount']))
  if (!mainProject) return '—'
  return detailCount && detailCount > 0 ? `${mainProject} +${detailCount}项` : text(mainProject)
}

function craftsmenLabel(record) {
  const label = firstValue(record, ['craftsmenLabel', 'craftsmanSummary', 'craftsmanNames', 'plannedCraftsmenLabel'])
  if (label) return Array.isArray(label) ? label.filter(Boolean).join('、') || '—' : text(label)

  const craftsmen = firstArray(record, ['craftsmen', 'plannedCraftsmen', 'serviceCraftsmen'])
  const names = craftsmen
    .map((item) => typeof item === 'string' ? item : firstValue(item, ['name', 'staffName', 'employeeName', 'label']))
    .filter(Boolean)
  return names.length ? names.join('、') : '—'
}

function reservationNo(record) {
  return text(
    firstValue(record, ['reservationNo', 'appointmentNo'])
    || firstValue(firstObject(record, ['reservation']), ['no', 'reservationNo', 'appointmentNo'])
  )
}

function serviceOrderNo(record) {
  return text(
    firstValue(record, ['serviceOrderNo', 'serviceNo', 'serviceSessionNo'])
    || firstValue(firstObject(record, ['serviceOrder', 'serviceSession']), ['no', 'serviceOrderNo', 'serviceNo'])
  )
}

function actionsOf(record) {
  const actions = record?.actions
  if (actions && typeof actions === 'object' && !Array.isArray(actions)) return actions
  return {}
}

function assignmentScopeOf(record) {
  const scope = firstValue(record, ['assignmentScope', 'assignment_scope'])
  return ['reservation_plan', 'active_service'].includes(scope) ? scope : ''
}

function arrayAction(record, kind) {
  const actions = Array.isArray(record?.actions)
    ? record.actions
    : firstArray(record, ['availableActions', 'actionList'])
  const acceptedCodes = kind === 'view'
    ? ['view', 'view-detail', 'view-service-order', 'open-reservation-detail', 'open-room-service-session']
    : ['assign', 'assign-room', 'prepare-room-assignment']

  return actions.find((action) => {
    const code = String(firstValue(action, ['code', 'actionCode', 'action', 'key'])).trim().toLowerCase().replace(/_/g, '-')
    return acceptedCodes.includes(code)
  }) || null
}

function actionOf(record, kind) {
  const keyedActions = actionsOf(record)
  const source = keyedActions[kind]
    || keyedActions[kind === 'view' ? 'viewDetail' : 'assignRoom']
    || arrayAction(record, kind)
  if (!source || typeof source !== 'object') return null
  const assignmentScope = assignmentScopeOf(record)
  const canonicalAction = kind === 'assign'
    ? 'prepare-room-assignment'
    : assignmentScope === 'reservation_plan'
      ? 'open-reservation-detail'
      : assignmentScope === 'active_service'
        ? 'open-room-service-session'
        : ''
  if (!canonicalAction) return null

  return {
    raw: {
      ...source,
      code: canonicalAction,
      assignmentScope,
      ...(kind === 'assign' ? { assignmentMode: 'assign' } : {})
    },
    visible: source.visible === true,
    enabled: source.enabled === true,
    disabledReason: text(source.disabledReason, '')
  }
}

function isActive(record, index) {
  if (props.activeRecordKey === '' || props.activeRecordKey === null || props.activeRecordKey === undefined) return false
  return String(props.activeRecordKey) === recordKey(record, index)
}

function disabledReasonText(record) {
  return [
    ['查看', actionOf(record, 'view')],
    ['分配房间', actionOf(record, 'assign')]
  ]
    .filter(([, action]) => action?.visible && !action.enabled && action.disabledReason)
    .map(([label, action]) => `${label}：${action.disabledReason}`)
    .join('；')
}

function query(page, reason) {
  if (props.isLoading || page < 1 || page > totalPages.value) return
  emit('query', {
    page,
    pageSize: PAGE_SIZE,
    reason
  })
}

function trigger(kind, record, index) {
  const action = actionOf(record, kind)
  if (!action?.visible || !action.enabled || isActive(record, index)) return
  emit(kind, {
    record,
    recordKey: recordKey(record, index),
    action: action.raw
  })
}

function requestClose() {
  emit('close')
}

useModalFocusTrap({
  containerRef: unassignedDialogRef,
  canClose: () => true,
  onClose: requestClose
})
</script>

<template>
  <Teleport to="body">
    <div class="unassigned-room-list" role="presentation" @click.self="requestClose">
      <section ref="unassignedDialogRef" class="unassigned-room-list__dialog" role="dialog" aria-modal="true" aria-labelledby="unassigned-room-list-title" tabindex="-1">
      <header class="unassigned-room-list__header">
        <div>
          <span class="unassigned-room-list__eyebrow">房间安排</span>
          <h2 id="unassigned-room-list-title">待分配房间</h2>
          <p>共 {{ total }} 条待处理记录</p>
        </div>
        <div class="unassigned-room-list__header-actions">
          <button
            type="button"
            class="unassigned-room-list__button unassigned-room-list__button--secondary"
            :disabled="isLoading"
            @click="query(currentPage, 'refresh')"
          >
            {{ isLoading ? '刷新中…' : '刷新' }}
          </button>
          <button type="button" class="unassigned-room-list__close" aria-label="关闭待分配房间" @click="requestClose">×</button>
        </div>
      </header>

      <main class="unassigned-room-list__body">
        <p v-if="errorMessage" class="unassigned-room-list__error" role="alert">{{ errorMessage }}</p>

        <div v-if="isLoading && !records.length" class="unassigned-room-list__state" role="status">
          正在加载待分配记录…
        </div>
        <div v-else-if="!records.length" class="unassigned-room-list__state">
          当前没有待分配房间的记录
        </div>
        <div v-else class="unassigned-room-list__records">
          <article
            v-for="(record, index) in records"
            :key="recordKey(record, index)"
            class="unassigned-room-list__record"
            :class="{ 'unassigned-room-list__record--active': isActive(record, index) }"
          >
            <div class="unassigned-room-list__badges">
              <span>{{ sourceLabel(record) }}</span>
              <strong>{{ statusLabel(record) }}</strong>
            </div>

            <div class="unassigned-room-list__member">
              <span class="unassigned-room-list__label">会员</span>
              <strong>{{ memberName(record) }}</strong>
              <small v-if="memberMeta(record)">{{ memberMeta(record) }}</small>
            </div>

            <div class="unassigned-room-list__project">
              <span class="unassigned-room-list__label">服务项目</span>
              <strong>{{ projectSummary(record) }}</strong>
              <small>手艺人：{{ craftsmenLabel(record) }}</small>
            </div>

            <div class="unassigned-room-list__time">
              <span class="unassigned-room-list__label">时间段</span>
              <strong>{{ timeRange(record) }}</strong>
            </div>

            <dl class="unassigned-room-list__numbers">
              <div><dt>预约号</dt><dd>{{ reservationNo(record) }}</dd></div>
              <div><dt>服务单号</dt><dd>{{ serviceOrderNo(record) }}</dd></div>
            </dl>

            <div class="unassigned-room-list__record-actions">
              <button
                v-if="actionOf(record, 'view')?.visible"
                type="button"
                class="unassigned-room-list__button unassigned-room-list__button--secondary"
                :disabled="!actionOf(record, 'view').enabled || isActive(record, index)"
                :title="actionOf(record, 'view').disabledReason"
                @click="trigger('view', record, index)"
              >
                {{ isActive(record, index) ? '处理中…' : '查看' }}
              </button>
              <button
                v-if="actionOf(record, 'assign')?.visible"
                type="button"
                class="unassigned-room-list__button unassigned-room-list__button--primary"
                :disabled="!actionOf(record, 'assign').enabled || isActive(record, index)"
                :title="actionOf(record, 'assign').disabledReason"
                @click="trigger('assign', record, index)"
              >
                {{ isActive(record, index) ? '处理中…' : '分配房间' }}
              </button>
              <span v-if="disabledReasonText(record)" class="unassigned-room-list__disabled-reason">
                {{ disabledReasonText(record) }}
              </span>
            </div>
          </article>
        </div>
      </main>

      <footer class="unassigned-room-list__footer">
        <span>每页 {{ PAGE_SIZE }} 条</span>
        <nav class="unassigned-room-list__pagination" aria-label="待分配房间分页">
          <button
            type="button"
            class="unassigned-room-list__button unassigned-room-list__button--secondary"
            :disabled="!canGoPrevious"
            @click="query(currentPage - 1, 'previous-page')"
          >
            上一页
          </button>
          <strong>第 {{ currentPage }} / {{ totalPages }} 页</strong>
          <button
            type="button"
            class="unassigned-room-list__button unassigned-room-list__button--secondary"
            :disabled="!canGoNext"
            @click="query(currentPage + 1, 'next-page')"
          >
            下一页
          </button>
        </nav>
      </footer>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.unassigned-room-list {
  position: fixed;
  z-index: 1260;
  inset: 0;
  display: grid;
  place-items: center;
  padding: 24px;
  background: rgb(15 23 42 / 48%);
}

.unassigned-room-list__dialog {
  display: flex;
  flex-direction: column;
  width: min(1180px, 100%);
  max-height: min(860px, calc(100vh - 48px));
  overflow: hidden;
  border: 1px solid #dfe6ef;
  border-radius: 18px;
  background: #fff;
  box-shadow: 0 24px 70px rgb(15 23 42 / 24%);
}

.unassigned-room-list__header,
.unassigned-room-list__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  padding: 20px 24px;
}

.unassigned-room-list__header {
  border-bottom: 1px solid #edf1f5;
}

.unassigned-room-list__eyebrow {
  display: block;
  margin-bottom: 3px;
  color: #7f8da1;
  font-size: 12px;
  font-weight: 700;
  letter-spacing: .06em;
}

.unassigned-room-list h2,
.unassigned-room-list p {
  margin: 0;
}

.unassigned-room-list h2 {
  color: #1d2a3d;
  font-size: 23px;
}

.unassigned-room-list__header p {
  margin-top: 5px;
  color: #748298;
  font-size: 13px;
}

.unassigned-room-list__header-actions,
.unassigned-room-list__pagination,
.unassigned-room-list__record-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}

.unassigned-room-list__close {
  width: 36px;
  height: 36px;
  border: 0;
  border-radius: 9px;
  color: #68778c;
  background: #f3f6f9;
  font-size: 25px;
  line-height: 1;
  cursor: pointer;
}

.unassigned-room-list__body {
  flex: 1;
  min-height: 240px;
  overflow: auto;
  padding: 18px 24px 22px;
  background: #f7f9fc;
}

.unassigned-room-list__records {
  display: grid;
  gap: 10px;
}

.unassigned-room-list__record {
  display: grid;
  grid-template-columns: 92px minmax(130px, .8fr) minmax(180px, 1.25fr) minmax(180px, 1.25fr) minmax(190px, 1fr) 164px;
  gap: 15px;
  align-items: center;
  padding: 15px 16px;
  border: 1px solid #e0e7ef;
  border-radius: 12px;
  background: #fff;
}

.unassigned-room-list__record--active {
  border-color: #90b6df;
  box-shadow: 0 0 0 2px rgb(55 124 199 / 9%);
}

.unassigned-room-list__badges {
  display: grid;
  justify-items: start;
  gap: 6px;
}

.unassigned-room-list__badges span,
.unassigned-room-list__badges strong {
  max-width: 100%;
  overflow: hidden;
  padding: 4px 8px;
  border-radius: 999px;
  font-size: 12px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.unassigned-room-list__badges span {
  color: #65768c;
  background: #eef2f6;
}

.unassigned-room-list__badges strong {
  color: #9a5a21;
  background: #fff1e3;
}

.unassigned-room-list__member,
.unassigned-room-list__project,
.unassigned-room-list__time {
  min-width: 0;
}

.unassigned-room-list__label {
  display: block;
  margin-bottom: 4px;
  color: #8a96a7;
  font-size: 11px;
}

.unassigned-room-list__member strong,
.unassigned-room-list__project strong,
.unassigned-room-list__time strong {
  display: block;
  overflow: hidden;
  color: #29364a;
  font-size: 14px;
  line-height: 1.45;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.unassigned-room-list__member small,
.unassigned-room-list__project small {
  display: block;
  margin-top: 4px;
  overflow: hidden;
  color: #758399;
  font-size: 12px;
  line-height: 1.4;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.unassigned-room-list__numbers {
  display: grid;
  gap: 6px;
  min-width: 0;
  margin: 0;
}

.unassigned-room-list__numbers div {
  display: grid;
  grid-template-columns: 48px minmax(0, 1fr);
  gap: 6px;
}

.unassigned-room-list__numbers dt {
  color: #8a96a7;
  font-size: 11px;
}

.unassigned-room-list__numbers dd {
  min-width: 0;
  margin: 0;
  overflow: hidden;
  color: #4b5a70;
  font-size: 12px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.unassigned-room-list__record-actions {
  flex-wrap: wrap;
  justify-content: flex-end;
}

.unassigned-room-list__disabled-reason {
  flex-basis: 100%;
  color: #a65c36;
  font-size: 11px;
  line-height: 1.35;
  text-align: right;
}

.unassigned-room-list__button {
  min-height: 36px;
  padding: 0 15px;
  border: 1px solid transparent;
  border-radius: 8px;
  font: inherit;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
}

.unassigned-room-list__button--primary {
  border-color: #2f74be;
  color: #fff;
  background: #377cc7;
}

.unassigned-room-list__button--secondary {
  border-color: #d8e1eb;
  color: #47576d;
  background: #fff;
}

.unassigned-room-list__button:disabled,
.unassigned-room-list__close:disabled {
  cursor: not-allowed;
  opacity: .56;
}

.unassigned-room-list__error,
.unassigned-room-list__state {
  padding: 16px;
  border-radius: 10px;
  font-size: 13px;
  line-height: 1.55;
  text-align: center;
}

.unassigned-room-list__error {
  margin-bottom: 12px !important;
  color: #a43f3f;
  background: #fff0f0;
}

.unassigned-room-list__state {
  display: grid;
  min-height: 190px;
  place-items: center;
  color: #78869a;
  border: 1px dashed #d8e0ea;
  background: #fff;
}

.unassigned-room-list__footer {
  border-top: 1px solid #edf1f5;
  color: #7a889c;
  font-size: 12px;
}

.unassigned-room-list__pagination strong {
  min-width: 96px;
  color: #47576d;
  font-size: 12px;
  text-align: center;
}

@media (max-width: 1040px) {
  .unassigned-room-list__record {
    grid-template-columns: 82px 1fr 1.2fr;
  }

  .unassigned-room-list__numbers,
  .unassigned-room-list__record-actions {
    grid-column: auto;
  }
}

@media (max-width: 720px) {
  .unassigned-room-list {
    padding: 10px;
  }

  .unassigned-room-list__dialog {
    max-height: calc(100vh - 20px);
  }

  .unassigned-room-list__header,
  .unassigned-room-list__footer {
    align-items: flex-start;
    padding: 16px;
  }

  .unassigned-room-list__body {
    padding: 12px;
  }

  .unassigned-room-list__record {
    grid-template-columns: 1fr;
    gap: 11px;
  }

  .unassigned-room-list__badges {
    display: flex;
  }

  .unassigned-room-list__record-actions {
    justify-content: flex-start;
  }

  .unassigned-room-list__disabled-reason {
    text-align: left;
  }

  .unassigned-room-list__footer {
    flex-direction: column;
  }
}
</style>

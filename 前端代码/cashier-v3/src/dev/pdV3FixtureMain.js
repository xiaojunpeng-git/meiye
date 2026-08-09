import { createApp, nextTick } from 'vue'
import App from '@/App.vue'
import router from '@/router'
import {
  cashierV3State,
  mergeCashierV3PublicVersions,
  replaceCashierV3State
} from '@/services/cashierV3Bridge'
import { CASHIER_ROOM_OPEN_INTENT_SOURCE } from '@/services/cashierV3RoomOpenIntent'
import '@/styles/base.css'

const PREVIEW_MARKER = 'PD-V3-FIXTURE'
const allowedHosts = new Set(['127.0.0.1', 'localhost', '::1'])
const checkoutScenarios = new Set([
  'checkout-order',
  'checkout-payment',
  'checkout-final',
  'checkout-entitlement-only',
  'payment-processing',
  'payment-pending-confirmation',
  'payment-result-unknown',
  'payment-failed',
  'payment-failed-locked',
  'payment-partial',
  'payment-success',
  'payment-service-pending',
  'stress-long-content'
])
const reservationScenarios = new Set([
  'reservation-list',
  'reservation-calendar',
  'reservation-editor',
  'reservation-detail'
])
const roomScenarios = new Set([
  'room-status',
  'room-stale',
  'room-detail',
  'room-unassigned',
  'room-assignment'
])
const allowedScenarios = new Set([
  'workbench',
  'workbench-no-member',
  'service-completion',
  ...checkoutScenarios,
  ...reservationScenarios,
  ...roomScenarios,
  'project-replacement',
  'sales-order-detail'
])
const params = new URLSearchParams(window.location.search)
const scenario = params.get('scenario') || 'workbench'
const fixtureMetrics = {
  actions: {}
}

function stopFixture(message) {
  const root = document.querySelector('#app')
  if (root) {
    root.innerHTML = `<main style="padding:24px;font-family:sans-serif"><h1>页面联调入口已停止</h1><p>${message}</p></main>`
  }
  throw new Error(`${PREVIEW_MARKER}: ${message}`)
}

if (
  !import.meta.env.DEV
  || !allowedHosts.has(window.location.hostname)
  || params.get('preview') !== '1'
) {
  stopFixture('该入口只允许在本机开发环境并携带 preview=1 时使用。')
}

if (!allowedScenarios.has(scenario)) {
  stopFixture(`未知场景：${scenario}`)
}

function clone(value) {
  return JSON.parse(JSON.stringify(value))
}

const fixtureDebtLedger = new Map()
let fixtureEntitlementSelector = null
let fixtureCashierDraft = null

function nextRootState(mutator) {
  const state = clone(cashierV3State)
  const currentRevision = Number(state.stateRevision)
  state.stateRevision = String(Number.isSafeInteger(currentRevision) && currentRevision > 0
    ? currentRevision + 1
    : 1)
  mutator(state)
  return state
}

function debtRecordsForMember(memberId) {
  if (String(memberId) !== 'member-10087') return []
  const ledgerKey = String(memberId)
  if (!fixtureDebtLedger.has(ledgerKey)) fixtureDebtLedger.set(ledgerKey, [
    {
      id: 'debt-item-10087-1',
      debtItemId: 'debt-item-10087-1',
      revision: 3,
      debtNo: 'QK202607180009-01',
      businessDate: '2026-07-18',
      sourceLabel: '销售订单',
      sourceOrderNo: 'XS202607180021',
      summary: '年度护理卡',
      payableAmount: '1800',
      originallyReceivedAmount: '1300',
      originalDebtAmount: '500',
      repaidAmount: '0',
      remainingDebtAmount: '500',
      cardWriteoffLimit: '3 次',
      statusLabel: '未补交',
      storeName: '瑞昊一店',
      operatorName: '肖君鹏',
      latestRepaymentAt: ''
    },
    {
      id: 'debt-item-10087-2',
      debtItemId: 'debt-item-10087-2',
      revision: 2,
      debtNo: 'QK202607200014-01',
      businessDate: '2026-07-20',
      sourceLabel: '充值订单',
      sourceOrderNo: 'CZ202607200004',
      summary: '储值充值',
      payableAmount: '1000',
      originallyReceivedAmount: '600',
      originalDebtAmount: '400',
      repaidAmount: '0',
      remainingDebtAmount: '400',
      cardWriteoffLimit: '—',
      statusLabel: '未补交',
      storeName: '瑞昊一店',
      operatorName: '李美容师',
      latestRepaymentAt: ''
    }
  ])
  return clone(fixtureDebtLedger.get(ledgerKey))
}

function buildDebtSnapshot(member = {}) {
  const memberId = member.id || member.memberId
  const records = debtRecordsForMember(memberId)
  const outstandingDebtAmount = records.reduce(
    (total, record) => total + Number(record.remainingDebtAmount || 0),
    0
  )
  return {
    member: clone(member),
    memberId,
    outstandingDebtAmount: String(outstandingDebtAmount),
    outstandingDebtCount: records.filter((record) => Number(record.remainingDebtAmount || 0) > 0).length,
    debtDataAsOf: member.debtDataAsOf || '2026-07-27 10:35:00',
    records
  }
}

function positiveFixtureVersion(value) {
  const version = Number(value)
  return Number.isSafeInteger(version) && version > 0 ? version : null
}

/**
 * 从联调根投影里明确存在的业务对象提取公开版本。
 *
 * 同一对象可能同时出现在列表、详情和当前操作区；优先使用前面登记的完整对象，
 * 后面的嵌套引用只在该对象尚未登记时补充，避免把只读摘要里的旧版本反向覆盖
 * 当前权威对象。这里绝不为缺失版本的对象伪造版本。
 */
function fixtureVersionsFromState(state) {
  const versionByResource = new Map()
  const add = (kind, id, version) => {
    const normalizedId = String(id ?? '').trim()
    const normalizedVersion = positiveFixtureVersion(version)
    if (!kind || !normalizedId || normalizedVersion === null) return
    const key = `${kind}:${normalizedId}`
    if (!versionByResource.has(key)) {
      versionByResource.set(key, { kind, id: normalizedId, version: normalizedVersion })
    }
  }
  const addEntity = (kind, entity, idKeys = ['id'], versionKeys = ['revision', 'recordVersion']) => {
    if (!entity || typeof entity !== 'object') return
    const id = idKeys.map((key) => entity[key]).find((value) => value !== undefined && value !== null && String(value).trim())
    const version = versionKeys.map((key) => entity[key]).find((value) => positiveFixtureVersion(value) !== null)
    add(kind, id, version)
  }

  addEntity('cashier_workspace', state?.workspace)
  addEntity('member', state?.cashier?.member, ['id', 'memberId'], ['recordVersion', 'revision', 'memberVersion'])
  addEntity('service_order', state?.cashier?.serviceOrder)
  addEntity('checkout_request', state?.cashier?.checkout, ['checkoutRequestId', 'requestId'], ['checkoutRequestVersion', 'revision', 'recordVersion'])
  add('writeoff_draft', state?.writeoff?.draftId, state?.writeoff?.revision)
  for (const record of state?.reservation?.records || []) addEntity('reservation', record)
  addEntity('reservation', state?.reservation?.detail)
  addEntity('service_order', state?.serviceCompletion?.serviceOrder)
  addEntity('service_order', state?.reservation?.detail?.serviceOrder)
  addEntity('service_order', state?.writeoff?.activeServiceSession)

  for (const category of state?.room?.categories || []) {
    for (const room of category?.rooms || []) {
      addEntity('room', room)
      add('service_order', room?.serviceOrderId, room?.serviceOrderRevision)
    }
  }
  addEntity('room', state?.room?.detail, ['id', 'roomId'])
  addEntity('service_order', state?.room?.detail?.serviceOrder)
  addEntity('reservation', state?.room?.detail?.serviceOrder?.reservation)
  addEntity('room', state?.room?.assignment?.currentRoom)
  addEntity('service_order', state?.room?.assignment?.serviceOrder)
  addEntity('reservation', state?.room?.assignment?.reservation)

  for (const record of state?.room?.unassignedList?.records || []) {
    addEntity('service_order', record?.serviceOrder)
    addEntity('reservation', record?.reservation)
  }
  for (const record of state?.hangOrders?.records || []) addEntity('hang_order', record)
  for (const record of state?.orderCenter?.salesOrders || []) addEntity('sales_order', record)
  addEntity('sales_order', state?.orderCenter?.salesOrderDetail)
  for (const record of state?.memberCenter?.debtSnapshot?.records || []) addEntity('debt_record', record, ['id', 'debtItemId'])

  for (const source of state?.writeoff?.sources || []) {
    add('card_holder', source?.cardHolderId || source?.entitlementInstanceId || source?.id, source?.version ?? source?.revision)
    for (const project of source?.projects || []) {
      add(
        'member_benefit_pool',
        project?.memberBenefitPoolId || project?.entitlementSourceDetailId || project?.sourceDetailId || project?.id,
        project?.version ?? project?.revision
      )
    }
  }
  for (const line of state?.cashier?.cart?.lines || []) {
    if (cartLineRole(line) !== 'entitlement_service') continue
    add('card_holder', line?.entitlementInstanceId || line?.cardHolderId, line?.entitlementSourceVersion)
    add('member_benefit_pool', line?.entitlementSourceDetailId || line?.memberBenefitPoolId, line?.projectVersion)
  }

  return Array.from(versionByResource.values()).sort((left, right) => (
    left.kind.localeCompare(right.kind) || left.id.localeCompare(right.id)
  ))
}

function seedFixtureMemberVersions(state) {
  const records = [
    state?.cashier?.member,
    state?.writeoff?.member,
    ...(state?.memberCenter?.records || []),
    ...(state?.memberSelector?.records || [])
  ]
  for (const record of records) {
    if (!record || typeof record !== 'object') continue
    if (positiveFixtureVersion(record.recordVersion ?? record.revision ?? record.memberVersion) === null) {
      record.recordVersion = 1
    }
  }
}

function successEnvelope(state, options = {}) {
  return {
    result: {
      status: 'success',
      code: options.code || 'PD_V3_FIXTURE_OK',
      message: options.message || ''
    },
    state,
    stateContextId: state.stateContextId,
    stateRevision: state.stateRevision,
    versions: fixtureVersionsFromState(state),
    ...(options.data ? { data: options.data } : {}),
    ...(options.overlay ? { overlay: options.overlay } : {}),
    ...(options.navigation ? { navigation: options.navigation } : {})
  }
}

function actionDataEnvelope(data, options = {}) {
  return {
    result: {
      status: 'success',
      code: options.code || 'PD_V3_FIXTURE_OK',
      message: options.message || ''
    },
    data,
    stateContextId: cashierV3State.stateContextId,
    versions: Array.isArray(options.versions) ? clone(options.versions) : [],
    ...(options.navigation ? { navigation: clone(options.navigation) } : {})
  }
}

/**
 * 联调 adapter 与生产 V3 使用同一套严格响应绑定合同。
 * 所有绑定只从本次函数参数派生，不使用共享的“最近请求”变量，因此并发响应不会串单。
 */
function bindFixtureResponse(action, payload, rawResponse) {
  if (!rawResponse || typeof rawResponse !== 'object') return rawResponse
  const response = {
    ...rawResponse,
    boundAction: action,
    boundCanonical: action
  }
  const commandIdempotencyKey = String(payload?.command?.idempotencyKey || '').trim()
  const correlationId = String(payload?.correlationId || '').trim()
  const originalIdempotencyKey = String(payload?.originalIdempotencyKey || '').trim()

  if (commandIdempotencyKey) {
    response.idempotencyKey = commandIdempotencyKey
    response.boundIdempotencyKey = commandIdempotencyKey
  }
  if (correlationId) {
    response.correlationId = correlationId
    response.boundCorrelationId = correlationId
  }
  if (originalIdempotencyKey) {
    response.originalIdempotencyKey = originalIdempotencyKey
    response.boundOriginalIdempotencyKey = originalIdempotencyKey
  }
  if (response.state && typeof response.state === 'object') {
    response.stateContextId = response.state.stateContextId
    response.stateRevision = response.state.stateRevision
    response.versions = fixtureVersionsFromState(response.state)
  } else if (!response.stateContextId && cashierV3State.stateContextId) {
    // 失败／冲突写响应也必须绑定当前工作台上下文；否则 Bridge 会按
    // result_unknown 处理，无法在视觉联调中展示“手机号已存在”的可恢复提示。
    response.stateContextId = cashierV3State.stateContextId
  }
  return response
}

function reservationContext(reservationId, version) {
  return [{
    kind: 'reservation',
    id: reservationId,
    expectedVersion: Number(version)
  }]
}

function createReservationDetail(reservationId = 'reservation-3') {
  const detailById = {
    'reservation-1': {
      revision: 4,
      reservationNo: 'YY202607270001',
      statusLabel: '待确认',
      member: { id: 'member-4', name: '李佳', phone: '13900000001', memberNo: 'HY202607270004', statusLabel: '正常' },
      projects: [
        { id: 'reservation-project-main-1', name: '水光护理', source: 'unpaid', role: 'main', quantity: 1, appliedDurationMinutes: 60, durationDescription: '采用商品设置中的项目服务时长 60 分钟。' },
        { id: 'reservation-project-detail-1', name: '肩颈放松护理', source: 'unpaid', role: 'detail', quantity: 1, appliedDurationMinutes: 30, durationDescription: '作为明细项目，采用商品设置中的增项服务时长 30 分钟。' }
      ],
      appointmentStartAt: '2026-07-27 14:30',
      appointmentEndAt: '2026-07-27 16:00',
      estimatedStartAt: '2026-07-27 14:30',
      estimatedEndAt: '2026-07-27 16:00',
      actualStartAt: '',
      actualEndAt: '',
      plannedCraftsmen: [{ id: 'staff-1', name: '肖君鹏', roleLabel: '预约手艺人' }],
      actualCraftsmen: [],
      room: { id: null, name: '待分配房间', statusLabel: '未占用' },
      roomChanges: [],
      relatedRecords: { hangOrders: [], serviceOrders: [], writeoffs: [], salesOrders: [] },
      timeline: [
        { id: 'timeline-pd-1', title: '提交外部预约', occurredAt: '2026-07-27 09:10', operatorName: '小程序', description: '等待门店确认，尚未生成销售挂单。' }
      ],
      actions: [
        { code: 'reject-reservation', label: '拒绝', primary: false },
        { code: 'confirm-reservation', label: '确认预约', primary: true }
      ]
    },
    'reservation-2': {
      revision: 6,
      reservationNo: 'YY202607270002',
      statusLabel: '已预约',
      member: { id: 'member-2', name: '陈女士', phone: '13900000002', memberNo: 'HY202607270002', statusLabel: '正常' },
      projects: [
        { id: 'reservation-project-main-2', name: '面部清洁', source: 'card', role: 'main', quantity: 1, appliedDurationMinutes: 45, durationDescription: '采用商品设置中的项目服务时长 45 分钟。', writeoffAmount: 240, consumptionPerformanceAmount: 240, laborPerformanceAmount: 240 },
        { id: 'reservation-project-detail-2', name: '补水护理', source: 'card', role: 'detail', quantity: 1, appliedDurationMinutes: 30, durationDescription: '作为明细项目，采用商品设置中的增项服务时长 30 分钟。', writeoffAmount: 160, consumptionPerformanceAmount: 160, laborPerformanceAmount: 160 }
      ],
      appointmentStartAt: '2026-07-27 15:00',
      appointmentEndAt: '2026-07-27 16:15',
      estimatedStartAt: '2026-07-27 15:00',
      estimatedEndAt: '2026-07-27 16:15',
      actualStartAt: '',
      actualEndAt: '',
      plannedCraftsmen: [{ id: 'staff-2', name: '李美容师', roleLabel: '预约手艺人' }],
      actualCraftsmen: [],
      room: { id: 'vip-2', name: 'VIP 房 02', statusLabel: '预约安排，尚未占用' },
      roomChanges: [],
      relatedRecords: { hangOrders: [], serviceOrders: [], writeoffs: [], salesOrders: [] },
      timeline: [
        { id: 'timeline-pd-2-create', title: '创建预约', occurredAt: '2026-07-27 09:20', operatorName: '李美容师', description: '电话预约，已安排手艺人和房间。' },
        { id: 'timeline-pd-2-confirm', title: '确认预约', occurredAt: '2026-07-27 09:22', operatorName: '李美容师' }
      ],
      actions: [
        { code: 'edit-reservation', label: '编辑', primary: false },
        { code: 'cancel-reservation', label: '取消', primary: false },
        { code: 'mark-no-show', label: '爽约', primary: false },
        { code: 'start-service', label: '开始服务', primary: true }
      ]
    },
    'reservation-3': {
      revision: 3,
      reservationNo: 'YY202607270003',
      statusLabel: '待结账',
      member: { id: 'member-10088', name: '王女士', phone: '13900000003', memberNo: 'HY202607270003', statusLabel: '正常' },
      projects: [
        { id: 'reservation-project-main-3', name: '身体舒缓护理', source: 'card', role: 'main', quantity: 1, appliedDurationMinutes: 60, durationDescription: '采用商品设置中的项目服务时长 60 分钟。' },
        { id: 'reservation-project-detail-3', name: '肩颈放松护理', source: 'unpaid', role: 'detail', quantity: 1, appliedDurationMinutes: 30, durationDescription: '作为明细项目，采用商品设置中的增项服务时长 30 分钟。' }
      ],
      appointmentStartAt: '2026-07-27 10:00',
      appointmentEndAt: '2026-07-27 11:30',
      estimatedStartAt: '2026-07-27 10:05',
      estimatedEndAt: '2026-07-27 11:35',
      actualStartAt: '2026-07-27 10:08',
      actualEndAt: '2026-07-27 11:28',
      plannedCraftsmen: [{ id: 'staff-1', name: '肖君鹏', roleLabel: '预约手艺人' }],
      actualCraftsmen: [
        { id: 'staff-1', name: '肖君鹏', roleLabel: '主要手艺人' },
        { id: 'staff-2', name: '李美容师', roleLabel: '协作手艺人' }
      ],
      room: { id: 'room-3', name: '普通房 03', statusLabel: '待结账' },
      roomChanges: [
        { id: 'room-change-pd-1', fromRoomName: '待分配房间', toRoomName: '普通房 03', changedAt: '2026-07-27 10:08', operatorName: '肖君鹏', reason: '顾客到店后安排空闲房间。' }
      ],
      relatedRecords: {
        hangOrders: [{ no: 'GD202607270003', statusLabel: '待结账' }],
        serviceOrders: [{ no: 'FW202607270002', statusLabel: '待结账' }],
        writeoffs: [{ no: 'HX202607270001', statusLabel: '已核销' }],
        salesOrders: []
      },
      timeline: [
        { id: 'timeline-pd-3-create', title: '创建预约', occurredAt: '2026-07-27 09:35', operatorName: '肖君鹏' },
        { id: 'timeline-pd-3-start', title: '开始服务', occurredAt: '2026-07-27 10:08', operatorName: '肖君鹏', description: '开始服务后实际占用普通房 03。' },
        { id: 'timeline-pd-3-complete', title: '确认本次服务', occurredAt: '2026-07-27 11:28', operatorName: '肖君鹏', description: '卡内项目已核销；本次新增消费等待结账。' }
      ],
      actions: [{ code: 'go-checkout', label: '去结账', primary: true }]
    }
  }

  const resolvedId = detailById[reservationId] ? reservationId : 'reservation-3'
  return {
    id: resolvedId,
    ...clone(detailById[resolvedId]),
    detailReady: true
  }
}

function serviceCompletionLinesFromReservation(detail) {
  const craftsmen = (detail?.plannedCraftsmen || []).map((staff, index) => ({
    id: staff.id,
    name: staff.name,
    isPrimary: index === 0
  }))

  return (detail?.projects || []).map((project) => {
    const isCardProject = project.source === 'card'
    const quantity = Number(project.quantity || 1)
    return {
      id: project.id,
      lineRole: isCardProject ? 'entitlement_service' : 'sale',
      name: project.name,
      kind: '项目',
      quantity,
      isServiceProject: true,
      serviceRole: project.role === 'main' ? '主项目' : '明细项目',
      serviceSource: isCardProject ? '卡内项目' : '预约未购项目',
      entitlementSource: isCardProject
        ? { label: `预约卡内权益 · ${detail.reservationNo}` }
        : '预约未购项目（结账成功后正式完成）',
      ...(isCardProject ? {
        entitlementInstanceId: project.entitlementInstanceId || `reservation-card:${detail.id}`,
        entitlementInstanceType: 'card_project',
        entitlementSourceDetailId: project.entitlementSourceDetailId || `reservation-detail:${project.id}`,
        entitlementSourceVersion: Number(project.entitlementSourceVersion || 1),
        projectId: project.projectId || project.id,
        projectVersion: Number(project.projectVersion || 1),
        entitlementSourceName: project.entitlementSourceName || '预约卡内权益',
        fullCardNo: project.fullCardNo || detail.reservationNo,
        remainingTimes: Number(project.remainingTimes || quantity),
        occupiedTimes: Number(project.occupiedTimes || quantity),
        availableTimes: Number(project.availableTimes || quantity)
      } : {}),
      completionStatus: '本次已完成',
      actualCompletedQuantity: quantity,
      actualCraftsmen: clone(craftsmen),
      craftsmenSummary: craftsmen.map((staff) => `${staff.name}${staff.isPrimary ? '(主)' : ''}`).join('、'),
      originalAmount: Number(project.originalAmount || project.finalAmount || 0),
      finalAmount: Number(project.finalAmount || 0),
      writeoffAmount: Number(project.writeoffAmount || 0),
      consumptionPerformanceAmount: Number(project.consumptionPerformanceAmount || 0),
      laborPerformanceAmount: Number(project.laborPerformanceAmount || 0)
    }
  })
}

function makeReservationPageState(name) {
  return nextRootState((state) => {
    state.reservation = {
      ...(state.reservation || {}),
      querySettings: {
        visibleFields: [
          'reservation_no',
          'appointment_time',
          'member_name',
          'project',
          'project_source',
          'craftsman',
          'room',
          'status'
        ]
      },
      statusOptions: [
        { value: '待确认', label: '待确认', normal: true },
        { value: '已预约', label: '已预约', normal: true },
        { value: '服务中', label: '服务中', normal: true },
        { value: '待结账', label: '待结账', normal: true },
        { value: '已完成', label: '已完成', normal: true },
        { value: '已过期', label: '已过期', normal: false },
        { value: '已作废', label: '已作废', normal: false }
      ],
      records: (state.reservation?.records || []).map((record) => {
        if (record.id === 'reservation-1') {
          return {
            ...record,
            primaryAction: {
              code: 'confirm-reservation',
              label: '确认预约',
              primary: true
            }
          }
        }
        if (record.id === 'reservation-2') {
          return {
            ...record,
            primaryAction: {
              code: 'start-service',
              label: '开始服务',
              primary: true
            }
          }
        }
        return {
          ...record,
          serviceOrderId: 'FW202607270002',
          serviceOrderRevision: 7,
          primaryAction: {
            code: 'go-checkout',
            label: '去结账',
            primary: true,
            checkoutRequestId: 'PD-CHECKOUT-ROOM-3',
            checkoutRequestVersion: 1
          }
        }
      }),
      calendar: {
        ...(state.reservation?.calendar || {}),
        timeSlots: [
          '13:30', '13:45', '14:00', '14:15', '14:30', '14:45', '15:00', '15:15',
          '15:30', '15:45', '16:00', '16:15', '16:30', '16:45', '17:00'
        ]
      }
    }
    if (name === 'reservation-detail') {
      state.reservation.detail = createReservationDetail('reservation-3')
    }
  })
}

function makePreparedReservationEditorState(payload = {}) {
  return nextRootState((state) => {
    const preparationRequestId = payload.preparationRequestId
    state.reservation.editor = {
      preparationReady: true,
      draftReady: true,
      preparationRequestId,
      preparationToken: 'PD-RESERVATION-EDITOR-TOKEN-1',
      commandContexts: [{ kind: 'cashier_workspace', id: state.workspace.id, expectedVersion: Number(state.workspace.revision) }],
      catalogOptions: [
        { id: 'editor-project-1', name: '水光护理', source: 'card', selectable: true, projectServiceDuration: 60, addonServiceDuration: 30 },
        { id: 'editor-project-2', name: '面部清洁', source: 'unpaid', selectable: true, projectServiceDuration: 45, addonServiceDuration: 20 },
        { id: 'editor-project-3', name: '补水护理', source: 'unpaid', selectable: true, projectServiceDuration: 0, addonServiceDuration: 30, appliedDurationLabel: '60分钟（系统默认）' }
      ],
      craftsmenOptions: [
        { id: 'staff-1', name: '肖君鹏', storeName: '瑞昊一店', selectable: true },
        { id: 'staff-2', name: '李美容师', storeName: '瑞昊一店', selectable: true },
        { id: 'staff-conflict', name: '赵美容师', storeName: '瑞昊一店', selectable: false, disabledReason: '15:00–16:00 已有预约' }
      ],
      rooms: [
        { id: 'room-1', name: '普通房 01', categoryName: '普通房间', selectable: true },
        { id: 'vip-1', name: 'VIP 房 01', categoryName: 'VIP 房间', selectable: true },
        { id: 'vip-2', name: 'VIP 房 02', categoryName: 'VIP 房间', selectable: false, disabledReason: '15:00–16:15 已安排陈女士' }
      ],
      draft: {
        memberId: 'member-10087',
        member: { id: 'member-10087', name: '陈女士', phone: '13900000002', memberNo: 'HY202607270002' },
        projects: [
          {
            id: 'reservation-editor-main',
            projectId: 'editor-project-1',
            name: '水光护理',
            source: 'card',
            role: 'main',
            quantity: 1,
            appliedDurationMinutes: 60,
            durationDescription: '采用商品设置中的项目服务时长 60 分钟。'
          },
          {
            id: 'reservation-editor-detail',
            projectId: 'editor-project-2',
            name: '面部清洁',
            source: 'unpaid',
            role: 'detail',
            quantity: 1,
            appliedDurationMinutes: 20,
            durationDescription: '作为明细项目，采用增项服务时长 20 分钟。'
          }
        ],
        appointmentTime: '2026-07-27T14:30',
        expectedEndAt: '2026-07-27 15:50',
        expectedEndLabel: '今天 15:50',
        craftsmen: [{ id: 'staff-1', name: '肖君鹏', storeName: '瑞昊一店' }],
        roomId: null,
        room: null,
        remark: '顾客希望安静房间',
        durationSummary: '预计服务 80 分钟：主项目 60 分钟＋明细项目 20 分钟。',
        availabilityCheck: {
          status: 'available',
          message: '当前手艺人可预约；房间暂不分配，不影响保存。'
        },
        conflicts: [],
        scheduleCalculationReady: true,
        scheduleRecalculationRequestId: ''
      }
    }
  })
}

function createRoomDetail() {
  return {
    id: 'room-2',
    roomId: 'room-2',
    name: '普通房 02',
    roomName: '普通房 02',
    enabled: true,
    revision: 8,
    statusLabel: '服务中',
    serviceOrder: {
      id: 'FW202607270001',
      no: 'FW202607270001',
      revision: 3,
      member: { id: '10086', name: '肖君鹏', memberNo: 'HY202607270001', phone: '13800000000' },
      reservation: { id: 'reservation-service-1', no: 'YY202607270010', revision: 2 },
      serviceStartedAt: '2026-07-27 09:55',
      expectedEndedAt: '2026-07-27 11:25',
      actualEndedAt: ''
    },
    historyReservations: [{ id: 'history-1', memberName: '刘女士', reservationNo: 'YY202607260006', appointmentStartAt: '2026-07-26 15:00', projectSummary: '补水护理' }],
    upcomingReservations: [{ id: 'upcoming-1', memberName: '李佳', reservationNo: 'YY202607270001', appointmentStartAt: '2026-07-27 14:30', projectSummary: '水光护理' }],
    actions: [
      { code: 'view-service-order', label: '查看服务单' },
      { code: 'change-room', label: '换房', assignmentScope: 'active_service', assignmentMode: 'change' },
      { code: 'remove-room', label: '移出房间', assignmentScope: 'active_service', assignmentMode: 'remove' },
      { code: 'end-service', label: '结束服务' }
    ]
  }
}

function makeRoomPageState(name) {
  return nextRootState((state) => {
    const categories = clone(state.room?.categories || [])
    const ordinaryRooms = categories.find((category) => category.id === 'ordinary')?.rooms || []
    ordinaryRooms.forEach((room, index) => {
      if (!(Number(room.revision) > 0)) room.revision = index + 7
    })
    const roomOne = ordinaryRooms.find((room) => room.id === 'room-1')
    if (roomOne) {
      roomOne.nextReservationStatus = 'due'
      roomOne.nextReservation = '14:30 李佳 · 水光护理'
      roomOne.warningMessage = '顾客已到店，可从预约详情开始服务。'
    }
    const vipRooms = categories.find((category) => category.id === 'vip')?.rooms || []
    vipRooms.forEach((room, index) => {
      if (!(Number(room.revision) > 0)) room.revision = index + 11
    })
    const vipTwo = vipRooms.find((room) => room.id === 'vip-2')
    if (vipTwo) {
      vipTwo.nextReservationStatus = 'conflict'
      vipTwo.conflictMessage = '前一场服务若延长，可能影响 16:00 的下一场预约。'
    }
    state.room = {
      ...(state.room || {}),
      categories,
      pollingIntervalSeconds: 60,
      refreshedAt: '2026-07-27 14:32:10',
      staleMessage: name === 'room-stale' ? '网络连接异常，当前保留最后一次有效房态；数据可能不是最新。' : '',
      detail: ['room-detail', 'room-assignment'].includes(name) ? createRoomDetail() : null
    }
  })
}

function makeRoomAssignmentState(payload = {}) {
  return nextRootState((state) => {
    const mode = payload.assignmentMode || payload.mode || 'assign'
    const scope = payload.assignmentScope || 'active_service'
    const isReservationPlan = scope === 'reservation_plan'
    const serviceMemberName = String(payload.serviceOrderId || '') === 'FW202607270001' ? '肖君鹏' : '陈女士'
    const reservation = isReservationPlan
      ? { id: payload.reservationId || 'reservation-1', no: 'YY202607270001', revision: Number(payload.reservationVersion || 4), memberName: '李佳' }
      : null
    const serviceOrder = !isReservationPlan
      ? {
          id: payload.serviceOrderId || 'service-unassigned-1',
          no: payload.serviceOrderId || 'FW202607270011',
          revision: Number(payload.serviceOrderVersion || 3),
          memberName: serviceMemberName
        }
      : null
    const currentRoom = ['change', 'remove'].includes(mode)
      ? { id: payload.roomId || 'room-2', name: '普通房 02', revision: Number(payload.roomVersion || 8) }
      : null
    const commandContexts = [isReservationPlan
      ? { kind: 'reservation', id: reservation.id, expectedVersion: reservation.revision }
      : { kind: 'service_order', id: serviceOrder.id, expectedVersion: serviceOrder.revision }]
    if (currentRoom) {
      commandContexts.push({ kind: 'room', id: currentRoom.id, expectedVersion: currentRoom.revision })
    }
    state.room.assignment = {
      assignmentScope: scope,
      mode,
      assignmentMode: mode,
      preparationRequestId: payload.preparationRequestId,
      roomAssignmentPreparationId: payload.preparationRequestId,
      reservation,
      serviceOrder,
      currentRoom,
      member: { name: isReservationPlan ? '李佳' : serviceMemberName },
      candidates: [
        { id: 'room-1', name: '普通房 01', categoryName: '普通房间', statusLabel: '空闲', selectable: true, nextReservation: '17:30 王女士 · 面部清洁' },
        { id: 'vip-1', name: 'VIP 房 01', categoryName: 'VIP 房间', statusLabel: '空闲', selectable: true },
        { id: 'vip-2', name: 'VIP 房 02', categoryName: 'VIP 房间', statusLabel: '空闲', selectable: false, disabledReason: '15:00–16:15 已安排陈女士' }
      ],
      commandContexts
    }
  })
}

function failedEnvelope(code, message) {
  return {
    result: {
      status: 'failed',
      code,
      message
    }
  }
}

function conflictEnvelope(code, message) {
  return {
    result: {
      status: 'conflict',
      code,
      message
    },
    conflict: { code, message }
  }
}

function serviceOrderContext(state) {
  const serviceOrder = state.cashier?.serviceOrder
  if (!serviceOrder?.id || !(Number(serviceOrder.revision) > 0)) return []
  return [{
    kind: 'service_order',
    id: serviceOrder.id,
    expectedVersion: Number(serviceOrder.revision)
  }]
}

function checkoutContexts(state, requestId, requestVersion, { includeServiceContext = true } = {}) {
  const contexts = [
    {
      kind: 'cashier_workspace',
      id: state.workspace.id,
      expectedVersion: Number(state.workspace.revision)
    },
    {
      kind: 'checkout_request',
      id: requestId,
      expectedVersion: Number(requestVersion)
    }
  ]
  return includeServiceContext ? [...contexts, ...serviceOrderContext(state)] : contexts
}

function paymentLineStatusForScenario(name) {
  if (name === 'payment-processing') return '处理中'
  if (name === 'payment-pending-confirmation') return '结果确认中'
  if (name === 'payment-result-unknown') return '结果未知'
  if (name === 'payment-failed' || name === 'payment-failed-locked') return '失败'
  if (name === 'payment-success') return '成功'
  if (name === 'payment-service-pending') return '成功'
  return '待收款'
}

function createPaymentLine(id, name, amount, status, overrides = {}) {
  const locked = ['处理中', '结果确认中', '结果未知', '成功'].includes(status)
  return {
    id,
    name,
    amount,
    status,
    canEdit: !locked,
    canRemove: !locked,
    externalTransactionNo: '',
    remark: '',
    noteSummary: '未填写备注',
    ...overrides
  }
}

function recalculateCheckoutPayment(checkout) {
  const selectedLines = Array.isArray(checkout.payment?.selectedLines)
    ? checkout.payment.selectedLines
    : []
  const selectedAmount = selectedLines.reduce(
    (total, line) => total + Number(line.amount || 0),
    0
  )
  const receivableAmount = Number(checkout.payment?.summary?.receivableAmount || 0)
  checkout.payment.summary.selectedAmount = selectedAmount
  checkout.payment.summary.remainingAmount = Math.max(0, receivableAmount - selectedAmount)
  checkout.payment.resultLines = clone(selectedLines)
  checkout.cashPerformanceAmount = selectedAmount
}

function advanceCheckoutPaymentDraft(checkout, state) {
  const requestVersion = Number(checkout.checkoutRequestVersion || checkout.revision || 0) + 1
  const workspaceVersion = Number(state.workspace?.revision || 0) + 1
  const includedServiceContext = (checkout.commandContexts || [])
    .some((context) => context?.kind === 'service_order')
  state.workspace.revision = workspaceVersion
  checkout.checkoutRequestVersion = requestVersion
  checkout.revision = requestVersion
  checkout.preparationToken = `PD-CHECKOUT-SNAPSHOT-TOKEN-${requestVersion}`
  checkout.commandContexts = checkoutContexts(
    state,
    checkout.checkoutRequestId || checkout.requestId,
    requestVersion,
    { includeServiceContext: includedServiceContext }
  )
}

function mutateCheckoutPaymentDraft(mutator) {
  return nextRootState((state) => {
    const checkout = clone(state.cashier?.checkout || {})
    mutator(checkout)
    recalculateCheckoutPayment(checkout)
    advanceCheckoutPaymentDraft(checkout, state)
    state.cashier.checkout = checkout
  })
}

function checkoutPaymentLines(name) {
  if (name === 'payment-partial') {
    return [
      createPaymentLine('pd-payment-success', '微信', 800, '成功', {
        canEdit: false,
        canRemove: false,
        externalTransactionNo: 'PD-WX-0001',
        lockReason: '本笔已成功，禁止重复收取'
      }),
      createPaymentLine('pd-payment-failed', '支付宝', 500, '失败', {
        canEdit: false,
        canRemove: false,
        externalTransactionNo: 'PD-ALI-0002',
        lockReason: '仅允许继续处理本笔剩余收款'
      })
    ]
  }
  if (name === 'stress-long-content') {
    return [
      createPaymentLine('pd-payment-1', '合作方收款（合作渠道名称较长时仍需完整展示）', 300, '待收款'),
      createPaymentLine('pd-payment-2', '支付宝', 250, '待收款'),
      createPaymentLine('pd-payment-3', '银联', 200, '待收款'),
      createPaymentLine('pd-payment-4', '大众验券', 200, '待收款'),
      createPaymentLine('pd-payment-5', '抖音验券', 200, '待收款'),
      createPaymentLine('pd-payment-6', '其他收款', 150, '待收款')
    ]
  }
  const status = paymentLineStatusForScenario(name)
  return [createPaymentLine('pd-payment-1', '微信', 1300, status, {
    externalTransactionNo: status === '成功' ? 'PD-WX-202607270001' : '',
    canEdit: ['待收款', '失败'].includes(status),
    canRemove: ['待收款', '失败'].includes(status)
  })]
}

function checkoutStatusForScenario(name) {
  const statuses = {
    'payment-processing': 'processing',
    'payment-pending-confirmation': 'pending_confirmation',
    'payment-result-unknown': 'result_unknown',
    'payment-failed': 'failed',
    'payment-failed-locked': 'failed',
    'payment-partial': 'failed',
    'payment-success': 'succeeded',
    'payment-service-pending': 'payment_succeeded_service_pending'
  }
  return statuses[name] || 'editing'
}

function checkoutStepForScenario(name) {
  if (name === 'checkout-payment' || name === 'stress-long-content') return 2
  if (name === 'checkout-final') return 3
  if (name.startsWith('payment-')) return 4
  return 1
}

function createCheckoutSnapshot(state, name, options = {}) {
  const requestId = options.requestId || 'PD-CHECKOUT-0001'
  const requestVersion = Number(options.requestVersion) || 1
  const preparationRequestId = options.preparationRequestId || 'PD-CHECKOUT-PREPARE-0001'
  let paymentLines = Array.isArray(options.paymentLines)
    ? clone(options.paymentLines)
    : checkoutPaymentLines(name)
  const status = checkoutStatusForScenario(name)
  const cartLines = clone(options.orderLines || state.cashier?.cart?.lines || [])
  const cartSummary = clone(options.cartSummary || state.cashier?.cart?.summary || {})
  const composition = checkoutCompositionForLines(cartLines)
  if (!composition.hasSale) paymentLines = []
  const receivableAmount = Number(cartSummary.receivableAmount ?? 1300)
  const isPartial = name === 'payment-partial'
  const isFailed = ['payment-failed', 'payment-failed-locked', 'payment-partial'].includes(name)
  const isLockedFailure = name === 'payment-failed-locked'
  const isSuccess = name === 'payment-success'
  const isServicePending = name === 'payment-service-pending'
  const isUncertain = ['payment-pending-confirmation', 'payment-result-unknown'].includes(name)
  const selectedAmount = paymentLines.reduce((total, line) => total + Number(line.amount || 0), 0)

  if (name === 'stress-long-content' && cartLines[0]) {
    cartLines[0].name = '全效深层补水焕亮紧致舒缓修护护理项目（超长商品名称视觉压力测试）'
  }

  return {
    ...(state.cashier?.checkout || {}),
    checkoutRequestId: requestId,
    requestId,
    checkoutRequestVersion: requestVersion,
    revision: requestVersion,
    requestNo: 'JZ202607270001',
    salesOrderId: composition.hasSale && (isSuccess || isServicePending) ? 'sales-1' : undefined,
    salesOrderNo: composition.hasSale && (isSuccess || isServicePending) ? 'XS202607270001' : undefined,
    serviceOrderId: options.includeServiceContext === false ? undefined : state.cashier?.serviceOrder?.id,
    preparationRequestId,
    preparationToken: 'PD-CHECKOUT-SNAPSHOT-TOKEN-1',
    preparationReady: true,
    snapshotReady: true,
    recoveryReady: true,
    resumeOnLoad: true,
    status,
    activeStep: checkoutStepForScenario(name),
    member: clone(state.cashier?.member || null),
    orderLines: cartLines,
    composition,
    summary: {
      selectedCount: cartSummary.selectedCount ?? cartLines.length,
      originalAmount: cartSummary.originalAmount ?? 1300,
      discountAmount: cartSummary.discountAmount ?? 0,
      receivableAmount: cartSummary.receivableAmount ?? 1300
    },
    businessDate: '2026-07-27',
    sourceEnabled: true,
    sourceSelectable: true,
    sourceLabel: '到店',
    orderNote: name === 'stress-long-content'
      ? '顾客特别说明：本次服务完成后请提醒护理注意事项，并核对所有组合收款明细。'
      : '顾客到店消费',
    debtAmount: 0,
    cashPerformanceAmount: composition.hasSale ? (isPartial ? 800 : selectedAmount) : 0,
    balancePaymentAmount: 0,
    finalChanges: name === 'checkout-final'
      ? [{
          id: 'pd-final-change-1',
          label: '权益与价格已复核',
          description: '后端按最新权益重新试算，本次应收金额未变化。'
        }]
      : [],
    finalValidationMessage: name === 'checkout-final'
      ? '请确认最新权益和价格复核结果。'
      : '',
    finalChangesConfirmationRequired: false,
    failureReason: isPartial
      ? '微信 800 元已成功；支付宝 500 元未成功，只需继续处理剩余收款。'
      : isFailed
        ? (name === 'stress-long-content'
            ? '本次收款未成功。失败原因较长时也必须完整展示，不能让操作人员误以为可以重复扣款。'
            : '本次收款未成功，请核对后再处理。')
        : '',
    canClose: isFailed && !isLockedFailure,
    canReturnToPaymentEdit: name === 'payment-failed',
    canRetry: name === 'payment-failed',
    canContinuePartialPaymentRecovery: isPartial,
    partialPaymentSucceeded: isPartial,
    originalIdempotencyKey: isUncertain || isFailed || isServicePending || status === 'processing'
      ? 'PD-CHECKOUT-ORIGINAL-KEY-1'
      : '',
    hasUnfinishedProject: false,
    completionKind: !composition.hasSale && isSuccess ? 'service_completed' : undefined,
    completionDescription: isServicePending
      ? '本次收款已确认成功，权益与服务结果仍在恢复处理中；请继续处理原结账请求，不能再次收款。'
      : !composition.hasSale && isSuccess
        ? '卡内项目已完成服务，本次没有销售订单，也无需收款。'
        : undefined,
    childResults: isServicePending
      ? {
          payment: { status: 'succeeded', label: '收款成功' },
          sale: { status: 'succeeded', label: '销售已完成' },
          writeoff: { status: 'pending', label: '权益待处理' },
          service: { status: 'pending', label: '服务待处理' }
        }
      : {},
    completionActions: isSuccess && composition.hasSale
      ? [{ id: 'print-pd-sales-order', code: 'print-sales-order-receipt', label: '打印小票' }]
      : [],
    payment: {
      ...(state.cashier?.checkout?.payment || {}),
      availableBalance: Number(state.cashier?.member?.accountBalance || 0),
      balanceAvailable: true,
      balanceVerification: {
        required: receivableAmount > 0,
        status: 'not_verified',
        label: '验证会员身份',
        description: '本次只检查页面入口，不做真实身份验证。'
      },
      methods: composition.hasSale ? [
        { id: 'unionpay', name: '银联' },
        { id: 'wechat', name: '微信' },
        { id: 'alipay', name: '支付宝' },
        { id: 'dianping_voucher', name: '大众验券' },
        { id: 'douyin_voucher', name: '抖音验券' },
        { id: 'partner_collection', name: '合作方收款' },
        { id: 'other_collection', name: '其他收款' },
        { id: 'old_card_entry', name: '旧卡录入', canAdd: false, disabledReason: '旧卡录入需单独办理，不能与其他收款方式组合。', cashPerformanceEligible: false }
      ] : [],
      selectedLines: paymentLines,
      resultLines: clone(paymentLines),
      summary: {
        receivableAmount,
        selectedAmount,
        remainingAmount: isPartial ? 500 : Math.max(0, receivableAmount - selectedAmount),
        validationMessage: '收款金额以结账前后端最终试算为准。'
      }
    },
    commandContexts: checkoutContexts(state, requestId, requestVersion, {
      includeServiceContext: options.includeServiceContext !== false
    })
  }
}

function makeDebtRepaymentCheckoutState(payload = {}) {
  return nextRootState((state) => {
    const snapshot = state.memberCenter?.debtSnapshot || {}
    const record = (snapshot.records || []).find((item) => String(item.id || item.debtItemId) === String(payload.debtRecordId || payload.debtItemId))
    const amount = Number(payload.amount)
    const remaining = Number(record?.remainingDebtAmount || 0)
    if (!record || !Number.isInteger(amount) || amount <= 0 || amount > remaining) return
    const orderLine = {
      id: record.id,
      lineRole: 'sale',
      name: record.summary,
      kind: '欠款补交',
      quantity: 1,
      originalAmount: amount,
      finalAmount: amount,
      serviceSource: record.sourceLabel,
      salespersonSummary: '按原销售分配',
      couponSummary: '不适用',
      debtSummary: record.debtNo
    }
    state.cashier.checkout = {
      ...createCheckoutSnapshot(state, 'checkout-order', {
        requestId: `PD-DEBT-REPAY-${record.id}`,
        preparationRequestId: payload.preparationRequestId,
        orderLines: [orderLine],
        cartSummary: {
          selectedCount: 1,
          originalAmount: amount,
          discountAmount: 0,
          receivableAmount: amount
        },
        paymentLines: [createPaymentLine('pd-debt-payment-1', '微信', amount, '待收款')],
        includeServiceContext: false
      }),
      businessType: 'debt_repayment',
      debtRecordId: record.id,
      debtItemId: record.debtItemId,
      repaymentAmount: String(amount),
      requestNo: `HK${String(Date.now()).slice(-12)}`,
      orderNote: '欠款补交使用统一收款明细。',
      completionLabel: '还款成功',
      completionDescription: '本次还款已完成，欠款余额和补交记录已重新读取。'
    }
  })
}

function completeDebtRepayment(state, checkout) {
  if (checkout.businessType !== 'debt_repayment') return
  const snapshot = state.memberCenter?.debtSnapshot
  if (!snapshot) return
  const amount = Number(checkout.repaymentAmount || 0)
  snapshot.records = (snapshot.records || []).map((record) => {
    if (String(record.id || record.debtItemId) !== String(checkout.debtRecordId)) return record
    const remaining = Math.max(0, Number(record.remainingDebtAmount || 0) - amount)
    return {
      ...record,
      revision: Number(record.revision || 0) + 1,
      repaidAmount: String(Number(record.repaidAmount || 0) + amount),
      remainingDebtAmount: String(remaining),
      statusLabel: remaining > 0 ? '部分补交' : '已补清',
      latestRepaymentAt: '2026-07-28 06:20'
    }
  })
  const outstanding = snapshot.records.reduce((sum, record) => sum + Number(record.remainingDebtAmount || 0), 0)
  snapshot.outstandingDebtAmount = String(outstanding)
  snapshot.outstandingDebtCount = snapshot.records.filter((record) => Number(record.remainingDebtAmount || 0) > 0).length
  snapshot.debtDataAsOf = '2026-07-28 06:20:00'
  const memberId = snapshot.memberId || snapshot.member?.id
  fixtureDebtLedger.set(String(memberId), clone(snapshot.records))
  state.memberCenter.records = (state.memberCenter?.records || []).map((member) => (
    String(member.id) === String(memberId)
      ? { ...member, outstandingDebtAmount: String(outstanding), debtAmount: outstanding, outstandingDebtCount: snapshot.outstandingDebtCount, debtDataAsOf: snapshot.debtDataAsOf }
      : member
  ))
  if (String(state.cashier?.member?.id || '') === String(memberId)) {
    state.cashier.member = {
      ...state.cashier.member,
      outstandingDebtAmount: String(outstanding),
      debtAmount: outstanding,
      outstandingDebtCount: snapshot.outstandingDebtCount,
      debtDataAsOf: snapshot.debtDataAsOf
    }
  }
}

function serviceOrderCheckoutOptions(state) {
  if (!state.cashier?.serviceOrder?.sourceReservationId) return {}
  const orderLines = (state.serviceCompletion?.lines || []).map((line) => ({
    id: line.id,
    lineRole: line.lineRole || (['卡内项目', '预约项目'].includes(line.serviceSource) ? 'entitlement_service' : 'sale'),
    name: line.name,
    kind: line.kind || '项目',
    quantity: Number(line.quantity || 1),
    originalAmount: Number(line.originalAmount || line.finalAmount || 0),
    finalAmount: Number(line.finalAmount || 0),
    serviceRole: line.serviceRole,
    serviceSource: line.serviceSource,
    craftsmenSummary: line.craftsmenSummary || '',
    ...(line.lineRole === 'entitlement_service' ? {
      entitlementInstanceId: line.entitlementInstanceId,
      entitlementInstanceType: line.entitlementInstanceType,
      entitlementSourceDetailId: line.entitlementSourceDetailId,
      entitlementSourceVersion: line.entitlementSourceVersion,
      projectId: line.projectId,
      projectVersion: line.projectVersion,
      entitlementSourceName: line.entitlementSourceName,
      fullCardNo: line.fullCardNo,
      remainingTimes: line.remainingTimes,
      occupiedTimes: line.occupiedTimes,
      availableTimes: line.availableTimes
    } : {}),
    salespersonSummary: '—',
    couponSummary: '不适用',
    debtSummary: '不适用'
  }))
  const originalAmount = orderLines.reduce((sum, line) => sum + Number(line.originalAmount || 0), 0)
  const receivableAmount = orderLines.reduce((sum, line) => sum + Number(line.finalAmount || 0), 0)
  return {
    orderLines,
    cartSummary: {
      selectedCount: orderLines.length,
      originalAmount,
      discountAmount: Math.max(0, originalAmount - receivableAmount),
      receivableAmount
    },
    paymentLines: receivableAmount > 0
      ? [createPaymentLine('pd-reservation-payment-1', '微信', receivableAmount, '待收款')]
      : []
  }
}

function makeCheckoutState(name, options = {}) {
  return nextRootState((state) => {
    if (options.cashierDraft) {
      state.cashier.cart = {
        lines: clone(options.cashierDraft.lines || []),
        summary: clone(options.cashierDraft.summary || {}),
        primaryAction: options.cashierDraft.primaryAction,
        primaryActionLabel: options.cashierDraft.primaryActionLabel
      }
      state.cashier.checkoutComposition = clone(options.cashierDraft.checkoutComposition || null)
    }
    const serviceOrder = state.cashier?.serviceOrder
    if (serviceOrder) {
      serviceOrder.serviceConfirmed = true
      serviceOrder.confirmationRequired = false
      serviceOrder.status = '待结账'
    }
    const serviceCheckoutOptions = serviceOrderCheckoutOptions(state)
    if (serviceCheckoutOptions.orderLines) {
      state.cashier.cart = {
        ...(state.cashier.cart || {}),
        lines: clone(serviceCheckoutOptions.orderLines),
        summary: clone(serviceCheckoutOptions.cartSummary)
      }
    }
    if (name === 'checkout-entitlement-only') {
      const entitlementLines = (state.cashier?.cart?.lines || []).filter((line) => cartLineRole(line) === 'entitlement_service')
      state.cashier.cart = {
        ...(state.cashier.cart || {}),
        lines: clone(entitlementLines),
        summary: cartSummaryForLines(entitlementLines)
      }
      options = {
        ...options,
        orderLines: clone(entitlementLines),
        cartSummary: cartSummaryForLines(entitlementLines),
        paymentLines: []
      }
    }
    const composition = checkoutCompositionForLines(state.cashier?.cart?.lines || [])
    state.cashier.checkoutComposition = composition
    state.cashier.cart = {
      ...(state.cashier.cart || {}),
      primaryAction: composition.primaryAction,
      primaryActionLabel: composition.primaryActionLabel
    }
    state.cashier.checkout = createCheckoutSnapshot(state, name, {
      ...serviceCheckoutOptions,
      ...options
    })
  })
}

function makeCheckoutResumeFromService(payload = {}) {
  return nextRootState((state) => {
    const serviceOrderId = payload.serviceOrderId || 'FW202607270002'
    const serviceOrderVersion = Number(payload.serviceOrderVersion || 7)
    const existingServiceOrder = clone(state.cashier?.serviceOrder || {})
    state.cashier.serviceOrder = {
      ...existingServiceOrder,
      id: serviceOrderId,
      revision: serviceOrderVersion,
      serviceNo: serviceOrderId,
      status: '待结账',
      roomName: payload.roomName || '普通房 03',
      serviceConfirmed: true,
      confirmationRequired: false,
      completion: {
        status: 'succeeded',
        confirmationReady: true,
        successLabel: '服务已确认'
      }
    }
    const serviceCheckoutOptions = serviceOrderCheckoutOptions(state)
    if (serviceCheckoutOptions.orderLines) {
      state.cashier.cart = {
        ...(state.cashier.cart || {}),
        lines: clone(serviceCheckoutOptions.orderLines),
        summary: clone(serviceCheckoutOptions.cartSummary)
      }
    }
    state.cashier.checkout = createCheckoutSnapshot(state, 'checkout-order', {
      ...serviceCheckoutOptions,
      requestId: `PD-CHECKOUT-${serviceOrderId}`,
      requestVersion: 1,
      preparationRequestId: `PD-CHECKOUT-PREPARE-${serviceOrderId}`
    })
  })
}

function makeServiceCompletionState(preparationRequestId) {
  return nextRootState((state) => {
    const serviceOrder = clone(state.cashier?.serviceOrder || {})
    const lines = clone(state.serviceCompletion?.lines || [])
    const completedLines = lines.filter((line) => Number(line.actualCompletedQuantity ?? line.completedQuantity ?? 0) > 0)
    serviceOrder.completion = {
      ...(serviceOrder.completion || {}),
      status: 'editing',
      confirmationReady: true,
      snapshotToken: 'PD-SERVICE-SNAPSHOT-TOKEN-1',
      // 这里模拟后端在同一确认快照中返回的正式预览；页面仍只展示，不自行计算。
      preview: {
        completedProjectCount: completedLines.length,
        writeoffAmount: lines.reduce((sum, line) => sum + Number(line.writeoffAmount || 0), 0),
        consumptionPerformanceAmount: lines.reduce((sum, line) => sum + Number(line.consumptionPerformanceAmount || 0), 0),
        laborPerformanceAmount: lines.reduce((sum, line) => sum + Number(line.laborPerformanceAmount || 0), 0)
      }
    }
    state.cashier.serviceOrder = clone(serviceOrder)
    state.serviceCompletion = {
      ...(state.serviceCompletion || {}),
      source: 'cashier',
      preparationRequestId,
      commandContexts: serviceOrderContext(state),
      serviceOrder,
      lines
    }
  })
}

function makeSalesOrderDetailState() {
  return nextRootState((state) => {
    const record = clone(state.orderCenter?.salesOrders?.[0] || {})
    if (!record.id) {
      throw new Error(`${PREVIEW_MARKER}: 销售订单详情场景缺少列表记录。`)
    }

    state.orderCenter = {
      ...(state.orderCenter || {}),
      salesOrderDetail: {
        ...record,
        revision: 1,
        occurredAt: '2026-07-27 10:18:36',
        paymentCompletedAt: '2026-07-27 10:20:08',
        orderNote: '顾客到店消费；服务结束后在收银台完成结账。',
        amountSummary: {
          originalAmount: 1300,
          priceChangeDiscountAmount: 0,
          couponDiscountAmount: 0,
          otherDiscountAmount: 0,
          payableAmount: 1300,
          debtAmount: 0,
          actualReceivedAmount: 1300,
          balancePaymentAmount: 0
        },
        items: [
          {
            id: 'sales-item-project-1',
            itemType: '项目',
            name: '水光护理',
            purchaseSpec: '单次项目',
            unitPrice: 500,
            quantity: 1,
            originalAmount: 500,
            priceChangeDiscountAmount: 0,
            couponDiscountAmount: 0,
            payableAmount: 500,
            debtAmount: 0,
            actualReceivedAmount: 500,
            serviceRecipientType: '本人',
            salespeople: [
              {
                id: 'staff-sales-1',
                name: '李四',
                salesPerformanceAmount: 500
              }
            ],
            craftsmen: [
              {
                id: 'staff-craftsman-1',
                name: '王美容师',
                laborPerformanceAmount: 320
              }
            ]
          },
          {
            id: 'sales-item-card-1',
            itemType: '卡项',
            name: '年度护理次卡',
            purchaseSpec: '10 次／有效期 12 个月',
            unitPrice: 800,
            quantity: 1,
            originalAmount: 800,
            priceChangeDiscountAmount: 0,
            couponDiscountAmount: 0,
            payableAmount: 800,
            debtAmount: 0,
            actualReceivedAmount: 800,
            isCard: true,
            cardBatchId: 'card-batch-pd-1',
            cardBatchNo: 'KP202607270001',
            benefitEntryId: 'benefit-pd-1',
            benefitSummary: '水光护理 10 次，剩余 10 次，剩余项目金额 800 元',
            salespeople: [
              {
                id: 'staff-sales-2',
                name: '王五',
                salesPerformanceAmount: 800
              }
            ]
          }
        ],
        paymentDetails: [
          {
            id: 'payment-detail-pd-1',
            methodName: '微信',
            amount: 1300,
            externalTransactionNo: 'PD-WX-202607270001',
            remark: '前台扫码收款',
            isBalancePayment: false
          }
        ],
        cardBatches: [
          {
            id: 'card-batch-pd-1',
            cardBatchId: 'card-batch-pd-1',
            cardBatchNo: 'KP202607270001',
            cardName: '年度护理次卡',
            isCustomCard: false,
            benefitEntryId: 'benefit-pd-1',
            benefitSummary: '水光护理 10 次，剩余 10 次，剩余项目金额 800 元'
          }
        ],
        related: {
          services: [
            {
              id: 'service-order-pd-1',
              serviceNo: 'FW202607270001',
              status: '已结束'
            }
          ],
          writeoffs: [
            {
              id: 'writeoff-pd-1',
              writeoffNo: 'HX202607270001',
              status: '已核销'
            }
          ],
          operationLogs: [
            {
              id: 'operation-pd-1',
              actionLabel: '结账成功',
              occurredAt: '2026-07-27 10:20:08',
              operatorName: '肖君鹏',
              content: '生成销售订单并记录正式收款明细。'
            }
          ]
        },
        availableActions: ['print-sales-order-receipt']
      }
    }
  })
}

function mutateCheckoutState(mutator) {
  return nextRootState((state) => {
    const checkout = clone(state.cashier?.checkout || {})
    mutator(checkout, state)
    state.cashier.checkout = checkout
  })
}

function completeServiceSessionAfterCheckout(state, checkout) {
  const serviceOrder = clone(state.cashier?.serviceOrder || {})
  if (!serviceOrder.id || serviceOrder.status === '已完成') return
  serviceOrder.revision = Number(serviceOrder.revision || 0) + 1
  serviceOrder.status = '已完成'
  serviceOrder.completedAt = '2026-07-27 16:18'
  serviceOrder.confirmationRequired = false
  state.cashier.serviceOrder = clone(serviceOrder)
  state.serviceCompletion = {
    ...(state.serviceCompletion || {}),
    commandContexts: serviceOrderContext(state),
    serviceOrder: clone(serviceOrder)
  }

  const sourceReservationId = serviceOrder.sourceReservationId
  if (sourceReservationId) {
    state.reservation.records = (state.reservation?.records || []).map((record) => (
      String(record.id) === String(sourceReservationId)
        ? {
            ...record,
            revision: Number(record.revision || 0) + 1,
            status: '已完成',
            serviceOrderRevision: serviceOrder.revision,
            primaryAction: null
          }
        : record
    ))
    if (String(state.reservation?.detail?.id || '') === String(sourceReservationId)) {
      const detail = state.reservation.detail
      state.reservation.detail = {
        ...detail,
        revision: Number(detail.revision || 0) + 1,
        statusLabel: '已完成',
        room: {
          ...(detail.room || {}),
          statusLabel: '结账完成，房间已释放'
        },
        serviceOrderRevision: serviceOrder.revision,
        serviceOrder: clone(serviceOrder),
        relatedRecords: {
          ...(detail.relatedRecords || {}),
          serviceOrders: [{ id: serviceOrder.id, no: serviceOrder.serviceNo, statusLabel: '已完成' }],
          salesOrders: [{
            id: checkout.salesOrderId,
            no: checkout.salesOrderNo,
            statusLabel: '正常'
          }]
        },
        timeline: [
          ...(detail.timeline || []),
          {
            id: `timeline-${sourceReservationId}-checkout`,
            title: '结账完成',
            occurredAt: '2026-07-27 16:18',
            operatorName: '当前操作人',
            description: '本次服务和结账已完成，房间已释放。'
          }
        ],
        actions: []
      }
    }
  }

  const assignedRoom = (state.room?.categories || [])
    .flatMap((category) => category.rooms || [])
    .find((room) => room.name === serviceOrder.roomName)
  if (assignedRoom) {
    Object.assign(assignedRoom, {
      revision: Number(assignedRoom.revision || 0) + 1,
      status: '空闲',
      memberName: '',
      serviceDuration: '',
      primaryCraftsman: '',
      pendingWriteoffCount: 0,
      newConsumptionAmount: 0,
      serviceOrderId: null,
      serviceOrderRevision: null
    })
  }
}

function cartLineRole(line = {}) {
  return ['sale', 'entitlement_service'].includes(line.lineRole) ? line.lineRole : 'unknown'
}

function checkoutCompositionForLines(lines = []) {
  const lineRoles = [...new Set(lines.map(cartLineRole).filter((role) => role !== 'unknown'))].sort()
  const hasSale = lineRoles.includes('sale')
  const hasEntitlement = lineRoles.includes('entitlement_service')
  const primaryAction = hasSale && hasEntitlement
    ? 'collect_and_complete'
    : hasEntitlement
      ? 'complete_service'
      : hasSale
        ? 'collect_payment'
        : ''
  const primaryActionLabel = {
    collect_payment: '确认收款',
    complete_service: '确认完成服务',
    collect_and_complete: '收款并完成服务'
  }[primaryAction] || ''
  const steps = primaryAction
    ? [
        { key: 'order', number: 1, label: hasEntitlement ? '确认本次内容' : '确认订单' },
        ...(hasSale ? [{ key: 'payment', number: 2, label: '收款信息' }] : []),
        { key: 'final', number: 3, label: primaryActionLabel },
        { key: 'result', number: 4, label: '处理结果' }
      ]
    : []
  return { lineRoles, hasSale, hasEntitlement, primaryAction, primaryActionLabel, steps }
}

function cartSummaryForLines(lines = []) {
  const saleLines = lines.filter((line) => cartLineRole(line) === 'sale')
  const originalAmount = saleLines.reduce((sum, line) => sum + Number(line.originalAmount ?? line.finalAmount ?? 0), 0)
  const receivableAmount = saleLines.reduce((sum, line) => sum + Number(line.finalAmount ?? line.amount ?? 0), 0)
  return {
    selectedCount: lines.length,
    originalAmount,
    discountAmount: Math.max(0, originalAmount - receivableAmount),
    receivableAmount
  }
}

function clearCustomerBoundEntitlements(state) {
  const lines = (state.cashier?.cart?.lines || []).filter((line) => cartLineRole(line) !== 'entitlement_service')
  const composition = checkoutCompositionForLines(lines)
  state.cashier.cart = {
    ...(state.cashier.cart || {}),
    lines,
    summary: cartSummaryForLines(lines),
    primaryAction: composition.primaryAction,
    primaryActionLabel: composition.primaryActionLabel
  }
  state.cashier.checkoutComposition = composition
  state.cashier.entitlementSelector = null
  state.cashier.checkout = null
  fixtureEntitlementSelector = null
  fixtureCashierDraft = null
}

function fixtureMoney(value) {
  const raw = typeof value === 'number' && Number.isFinite(value)
    ? value.toFixed(2)
    : String(value ?? '').trim()
  if (!/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/.test(raw)) return null
  const [whole, fraction = ''] = raw.split('.')
  return `${whole}.${fraction.padEnd(2, '0')}`
}

function fixtureMoneyToCents(value) {
  const money = fixtureMoney(value)
  if (money === null) return null
  return BigInt(money.replace('.', ''))
}

function fixtureCentsToMoney(cents) {
  const raw = cents.toString().padStart(3, '0')
  return `${raw.slice(0, -2)}.${raw.slice(-2)}`
}

function fixtureCumulativeCents(totalCents, completedTimes, totalTimes) {
  if (completedTimes <= 0) return BigInt(0)
  if (completedTimes >= totalTimes) return totalCents
  const numerator = totalCents * BigInt(completedTimes)
  const divisor = BigInt(totalTimes)
  const whole = numerator / divisor
  const remainder = numerator % divisor
  return whole + (remainder * BigInt(2) >= divisor ? BigInt(1) : BigInt(0))
}

function fixtureAllocatedEntitlementAmount(purchaseAmount, totalTimes, consumedTimes, quantity) {
  const totalCents = fixtureMoneyToCents(purchaseAmount)
  if (totalCents === null
    || !Number.isInteger(totalTimes)
    || totalTimes <= 0
    || !Number.isInteger(consumedTimes)
    || consumedTimes < 0
    || !Number.isInteger(quantity)
    || quantity < 0
    || consumedTimes + quantity > totalTimes) return null
  const start = fixtureCumulativeCents(totalCents, consumedTimes, totalTimes)
  const end = fixtureCumulativeCents(totalCents, consumedTimes + quantity, totalTimes)
  return fixtureCentsToMoney(end - start)
}

function fixtureEntitlementLineSourceKey(line = {}) {
  return `${line.entitlementInstanceId || line.cardHolderId || ''}:${line.entitlementSourceDetailId || line.memberBenefitPoolId || ''}`
}

function recomputeFixtureEntitlementAmounts(lines = []) {
  const selectedQuantityBySource = new Map()
  for (const line of lines) {
    if (cartLineRole(line) !== 'entitlement_service') continue
    const sourceKey = fixtureEntitlementLineSourceKey(line)
    const selectedBefore = selectedQuantityBySource.get(sourceKey) || 0
    const sourceConsumedTimes = Number(
      line.sourceConsumedTimesAtSelection ?? line.consumedTimesAtSelection
    )
    const quantity = Number(line.quantity)
    const allocationStart = sourceConsumedTimes + selectedBefore
    const actualAmount = fixtureAllocatedEntitlementAmount(
      line.purchaseAmount,
      Number(line.totalPurchaseTimes),
      allocationStart,
      quantity
    )
    if (actualAmount === null) return false
    line.sourceConsumedTimesAtSelection = sourceConsumedTimes
    line.consumedTimesAtSelection = sourceConsumedTimes
    line.allocationStartConsumedTimes = allocationStart
    line.actualAmount = actualAmount
    selectedQuantityBySource.set(sourceKey, selectedBefore + quantity)
  }
  return true
}

function selectorSourcesFromState(state) {
  const sources = clone(state.writeoff?.sources || [])
  sources.push({
    id: 'preview-expired-card',
    cardHolderId: 'preview-expired-card',
    entitlementInstanceId: 'preview-expired-card',
    entitlementInstanceType: 'times_card',
    sourceType: 'times_card',
    sourceKindLabel: '次卡',
    name: '已失效护理次卡',
    fullCardNo: 'K202501010099',
    reference: 'K202501010099',
    status: '已失效',
    statusCode: 'expired',
    expiryDate: '2025-12-31',
    remainingTimes: 2,
    remainingAmount: 180,
    purchaseTimes: 10,
    purchaseAmount: 900,
    version: 1,
    selectable: false,
    disabled: true,
    projects: []
  })
  return sources.map((source) => ({
    ...source,
    cardHolderId: source.cardHolderId || source.entitlementInstanceId || source.id,
    entitlementInstanceId: source.entitlementInstanceId || source.id,
    entitlementInstanceType: source.entitlementInstanceType || source.sourceType,
    version: positiveFixtureVersion(source.version ?? source.revision),
    fullCardNo: source.fullCardNo || source.reference || '',
    selectable: source.selectable !== false && source.disabled !== true && source.status === '可用',
    occupiedTimes: Number(source.occupiedTimes || 0),
    availableTimes: Number(source.availableTimes ?? source.remainingTimes ?? 0),
    selectedProjectCount: 0,
    selectedTimes: 0,
    projects: (source.projects || []).map((project) => {
      const version = positiveFixtureVersion(project.version ?? project.revision)
      const purchaseAmount = fixtureMoney(project.purchaseAmount)
      const totalPurchaseTimes = Number(project.totalPurchaseTimes ?? project.purchaseTimes ?? project.totalTimes)
      const remainingTimes = Number(project.remainingTimes)
      const consumedTimesAtSelection = totalPurchaseTimes - remainingTimes
      const remainingAmount = fixtureAllocatedEntitlementAmount(
        purchaseAmount,
        totalPurchaseTimes,
        consumedTimesAtSelection,
        remainingTimes
      )
      return {
        ...project,
        memberBenefitPoolId: project.memberBenefitPoolId || project.entitlementSourceDetailId || project.sourceDetailId || project.id,
        projectId: project.projectId || project.id,
        entitlementSourceDetailId: project.entitlementSourceDetailId || project.sourceDetailId,
        version,
        purchaseAmount,
        totalPurchaseTimes,
        consumedTimesAtSelection,
        amountSourceVersion: version,
        amountCalculationVersion: 'fixture-cumulative-half-up-cent-v2',
        remainingAmount,
        occupiedTimes: Number(project.occupiedTimes ?? project.reservedTimes ?? 0),
        availableTimes: Number(project.availableTimes ?? Math.max(0, Number(project.remainingTimes || 0) - Number(project.occupiedTimes ?? project.reservedTimes ?? 0))),
        selected: false,
        selectedTimes: 0
      }
    })
  }))
}

function entitlementSelectorContexts(state, sources) {
  const memberId = String(state.cashier?.member?.id || state.cashier?.member?.memberId || '')
  const memberVersion = positiveFixtureVersion(
    state.cashier?.member?.recordVersion ?? state.cashier?.member?.revision ?? state.cashier?.member?.memberVersion
  )
  const contexts = [
    { kind: 'cashier_workspace', id: state.workspace.id, expectedVersion: Number(state.workspace.revision) },
    { kind: 'member', id: memberId, expectedVersion: memberVersion }
  ]
  for (const source of sources) {
    contexts.push({
      kind: 'card_holder',
      id: source.cardHolderId,
      expectedVersion: Number(source.version)
    })
    for (const project of source.projects || []) {
      contexts.push({
        kind: 'member_benefit_pool',
        id: project.memberBenefitPoolId,
        expectedVersion: Number(project.version)
      })
    }
  }
  return contexts
}

function makeEntitlementSelectorData(payload = {}) {
  const state = cashierV3State
  const member = state.cashier?.member
  const memberId = String(member?.id || member?.memberId || '')
  const selectorRequestId = String(payload.selectorRequestId || '')
  const sources = selectorSourcesFromState(state)
  const commandContexts = entitlementSelectorContexts(state, sources)
  return {
    ready: true,
    selectorRequestId,
    selectorToken: `PD-ENTITLEMENT:${selectorRequestId}:${memberId}:${state.workspace.revision}`,
    member: clone(member),
    sources,
    commandContexts
  }
}

function fixtureEntitlementCommandContextsMatch(payload, selector, requested) {
  const expectedByKey = new Map((selector.commandContexts || []).map((context) => (
    [`${context.kind}:${context.id}`, Number(context.expectedVersion)]
  )))
  const requiredKeys = new Set([
    `cashier_workspace:${cashierV3State.workspace?.id || ''}`,
    `member:${cashierV3State.cashier?.member?.id || cashierV3State.cashier?.member?.memberId || ''}`
  ])
  for (const line of requested) {
    requiredKeys.add(`card_holder:${line.entitlementInstanceId || ''}`)
    requiredKeys.add(`member_benefit_pool:${line.entitlementSourceDetailId || ''}`)
  }
  const contexts = Array.isArray(payload.command?.contexts) ? payload.command.contexts : []
  if (contexts.length !== requiredKeys.size) return false
  const actualKeys = new Set()
  for (const context of contexts) {
    const key = `${context.kind}:${context.id}`
    if (!requiredKeys.has(key)
      || actualKeys.has(key)
      || Number(context.expectedVersion) !== expectedByKey.get(key)) return false
    actualKeys.add(key)
  }
  return actualKeys.size === requiredKeys.size
}

function addEntitlementLinesDraft(payload = {}) {
  const selector = fixtureEntitlementSelector
  const memberId = String(cashierV3State.cashier?.member?.id || cashierV3State.cashier?.member?.memberId || '')
  const existingDraftLines = fixtureCashierDraft?.lines || cashierV3State.cashier?.cart?.lines || []
  const addIntentId = String(payload.addIntentId || '').trim()
  const requestedLines = Array.isArray(payload.lines) ? payload.lines : []
  if (payload.mutationMode !== 'append'
    || !/^[A-Za-z0-9_-]{16,64}$/.test(addIntentId)
    || requestedLines.length !== 1
    || Number(requestedLines[0]?.quantity) !== 1) {
    return { error: ['ENTITLEMENT_LINE_INVALID', '单次添加权益项目的请求无效。'] }
  }
  const requestedIntentLine = requestedLines[0]
  const intentLineId = `entitlement:${addIntentId.slice(-36)}`
  const commandIdempotencyKey = String(payload.command?.idempotencyKey || '').trim()
  const replayLine = existingDraftLines.find((line) => String(line.id || '') === intentLineId)
  if (replayLine) {
    const sameIntent = cartLineRole(replayLine) === 'entitlement_service'
      && String(replayLine.entitlementInstanceId || '') === String(requestedIntentLine.entitlementInstanceId || '')
      && String(replayLine.entitlementSourceDetailId || '') === String(requestedIntentLine.entitlementSourceDetailId || '')
      && String(replayLine.projectId || '') === String(requestedIntentLine.projectId || '')
      && String(replayLine.addCommandIdempotencyKey || '') === commandIdempotencyKey
    if (!sameIntent || !fixtureCashierDraft?.complete) {
      return { error: ['IDEMPOTENCY_CONFLICT', '本次添加请求已绑定其他权益项目。'] }
    }
    return { cashierDraft: clone(fixtureCashierDraft), versions: [] }
  }
  if (!selector?.ready
    || String(payload.selectorRequestId || '') !== String(selector.selectorRequestId || '')
    || String(payload.selectorToken || '') !== String(selector.selectorToken || '')
    || String(payload.memberId || '') !== memberId
    || !requestedLines.length) {
    return { error: ['ENTITLEMENT_SELECTOR_SESSION_EXPIRED', '权益选择会话已失效，请重新打开。'] }
  }
  if (!fixtureEntitlementCommandContextsMatch(payload, selector, requestedLines)) {
    return { error: ['INVALID_COMMAND_CONTEXT', '权益选择缺少当前会员、工作台或已选权益版本，请重新打开。'] }
  }

  const selectedLines = []
  const seen = new Set()
  for (const requested of requestedLines) {
    const source = (selector.sources || []).find((item) => String(item.entitlementInstanceId || item.id) === String(requested.entitlementInstanceId || ''))
    const project = (source?.projects || []).find((item) => (
      String(item.projectId || item.id) === String(requested.projectId || '')
      && String(item.entitlementSourceDetailId || item.sourceDetailId || '') === String(requested.entitlementSourceDetailId || '')
    ))
    const key = `${requested.entitlementInstanceId}:${requested.entitlementSourceDetailId}`
    const quantity = Number(requested.quantity)
    if (!source || !project || seen.has(key)
      || Number(requested.entitlementSourceVersion) !== Number(source.version)
      || Number(requested.projectVersion) !== Number(project.version)
      || !Number.isInteger(quantity) || quantity < 1 || quantity > Number(project.availableTimes || 0)
      || source.selectable === false || project.selectable === false || project.disabled === true) {
      return { error: ['ENTITLEMENT_SELECTION_CHANGED', '权益项目已经变化，请重新打开后选择。'] }
    }
    seen.add(key)
    const selectedQuantity = existingDraftLines.reduce((total, line) => (
      cartLineRole(line) === 'entitlement_service'
      && String(line.entitlementInstanceId || '') === String(source.entitlementInstanceId || source.id)
      && String(line.entitlementSourceDetailId || '') === String(project.entitlementSourceDetailId || project.sourceDetailId)
        ? total + Number(line.quantity || 0)
        : total
    ), 0)
    if (selectedQuantity + quantity > Number(project.availableTimes || 0)) {
      return { error: ['ENTITLEMENT_SELECTION_CHANGED', '权益项目可用次数已经变化，请重新打开后选择。'] }
    }
    selectedLines.push({
      id: intentLineId,
      addIntentId,
      addCommandIdempotencyKey: commandIdempotencyKey,
      lineRole: 'entitlement_service',
      cardHolderId: source.cardHolderId,
      memberBenefitPoolId: project.memberBenefitPoolId,
      name: project.name,
      kind: '项目',
      quantity,
      entitlementInstanceId: source.entitlementInstanceId || source.id,
      entitlementInstanceType: source.entitlementInstanceType || source.sourceType,
      entitlementSourceDetailId: project.entitlementSourceDetailId || project.sourceDetailId,
      entitlementSourceVersion: Number(source.version),
      projectId: project.projectId || project.id,
      projectVersion: Number(project.version),
      entitlementSourceName: source.name,
      fullCardNo: source.fullCardNo || source.reference,
      remainingTimes: Number(project.remainingTimes || 0),
      occupiedTimes: Number(project.occupiedTimes || 0),
      availableTimes: Number(project.availableTimes || 0),
      purchaseAmount: fixtureMoney(project.purchaseAmount),
      totalPurchaseTimes: Number(project.totalPurchaseTimes),
      sourceConsumedTimesAtSelection: Number(project.consumedTimesAtSelection),
      consumedTimesAtSelection: Number(project.consumedTimesAtSelection),
      amountSourceVersion: Number(project.amountSourceVersion || project.version),
      actualAmount: '0.00',
      amountRole: 'entitlement_actual',
      amountCalculationVersion: project.amountCalculationVersion,
      validThroughLabel: project.validThroughLabel || source.expiryText || '',
      debtRestrictionLabel: project.debtRestrictionLabel || source.debtRestrictionLabel || '',
      serviceObject: '本人',
      craftsmen: [],
      craftsmenSummary: '待分配',
      isExperience: false,
      serviceSource: '卡内项目'
    })
  }

  const currentLines = clone(fixtureCashierDraft?.lines || cashierV3State.cashier?.cart?.lines || [])
  const lines = [...currentLines, ...selectedLines]
  if (!recomputeFixtureEntitlementAmounts(lines)) {
    return { error: ['ENTITLEMENT_SELECTION_CHANGED', '权益实际金额不完整，请重新打开后选择。'] }
  }
  const checkoutComposition = checkoutCompositionForLines(lines)
  const cashierDraft = {
    workspaceId: String(cashierV3State.workspace?.id || ''),
    stateContextId: String(cashierV3State.stateContextId || ''),
    customerMode: 'member',
    memberId,
    status: 'editing',
    lines,
    summary: cartSummaryForLines(lines),
    checkoutComposition,
    primaryAction: checkoutComposition.primaryAction,
    primaryActionLabel: checkoutComposition.primaryActionLabel,
    complete: true,
    managedLineRoles: ['sale', 'entitlement_service']
  }
  fixtureCashierDraft = clone(cashierDraft)
  fixtureEntitlementSelector = null
  return { cashierDraft, versions: selector.commandContexts.map((context) => ({
    kind: context.kind,
    id: context.id,
    version: Number(context.expectedVersion)
  })) }
}

function mutateFixtureCashierDraft(action, payload = {}) {
  const current = fixtureCashierDraft
  if (!current?.complete || !Array.isArray(current.lines)) {
    return { error: ['CASHIER_DRAFT_INCOMPLETE', '购物车权威数据尚未完整加载。'] }
  }
  const lineId = String(payload.lineId || '')
  const lineIndex = current.lines.findIndex((line) => String(line.id || '') === lineId)
  if (action !== 'clear-cart-lines' && lineIndex < 0) {
    return { error: ['RESOURCE_NOT_FOUND', '该购物车项目不存在或已经删除。'] }
  }
  const lines = clone(current.lines)
  if (action === 'clear-cart-lines') {
    lines.splice(0, lines.length)
  } else if (action === 'remove-cart-line') {
    lines.splice(lineIndex, 1)
  } else if (action === 'change-cart-line-quantity') {
    const delta = Number(payload.delta)
    const nextQuantity = Number(lines[lineIndex].quantity || 0) + delta
    const isEntitlement = cartLineRole(lines[lineIndex]) === 'entitlement_service'
    const sourceKey = fixtureEntitlementLineSourceKey(lines[lineIndex])
    const selectedByOtherLines = isEntitlement
      ? lines.reduce((total, line, index) => (
          index !== lineIndex
          && cartLineRole(line) === 'entitlement_service'
          && fixtureEntitlementLineSourceKey(line) === sourceKey
            ? total + Number(line.quantity || 0)
            : total
        ), 0)
      : 0
    const maximum = isEntitlement ? Number(lines[lineIndex].availableTimes || 0) : 1000000
    if (!Number.isInteger(delta)
      || delta === 0
      || nextQuantity < 1
      || selectedByOtherLines + nextQuantity > maximum) {
      return { error: ['ENTITLEMENT_SELECTION_CHANGED', '卡内项目可用次数已经变化，请重新打开后选择。'] }
    }
    lines[lineIndex].quantity = nextQuantity
  } else if (action === 'update-cart-line-service-settings') {
    const line = lines[lineIndex]
    if (line.kind !== '项目' && cartLineRole(line) !== 'entitlement_service') {
      return { error: ['CASHIER_SERVICE_LINE_REQUIRED', '只有服务项目可以设置手艺人、服务对象或体验标记。'] }
    }
    const hasServiceObject = Object.prototype.hasOwnProperty.call(payload, 'serviceObject')
    const hasCraftsmen = Object.prototype.hasOwnProperty.call(payload, 'craftsmen')
    const hasExperience = Object.prototype.hasOwnProperty.call(payload, 'isExperience')
    if (!hasServiceObject && !hasCraftsmen && !hasExperience) {
      return { error: ['INVALID_COMMAND_PAYLOAD', '本次没有可保存的服务设置。'] }
    }
    if (hasServiceObject) {
      if (!['self', 'friend'].includes(payload.serviceObject)) {
        return { error: ['INVALID_COMMAND_PAYLOAD', '服务对象设置无效。'] }
      }
      line.serviceObject = payload.serviceObject === 'friend' ? '朋友' : '本人'
    }
    if (hasCraftsmen) {
      if (!Array.isArray(payload.craftsmen) || !payload.craftsmen.length) {
        return { error: ['INVALID_COMMAND_PAYLOAD', '请选择至少一位手艺人。'] }
      }
      line.craftsmen = payload.craftsmen.map((staff, index) => ({
        id: staff.id,
        name: staff.name || staff.staffName || `手艺人${index + 1}`,
        isPrimary: index === 0,
        sequence: index + 1
      }))
      line.craftsmenSummary = line.craftsmen
        .map((staff, index) => `${staff.name}${index === 0 ? '(主)' : ''}`)
        .join('、')
    }
    if (hasExperience) {
      if (typeof payload.isExperience !== 'boolean') {
        return { error: ['INVALID_COMMAND_PAYLOAD', '体验项目标记无效。'] }
      }
      line.isExperience = payload.isExperience
    }
  }
  if (!recomputeFixtureEntitlementAmounts(lines)) {
    return { error: ['CASHIER_DRAFT_INCOMPLETE', '权益实际金额分摊数据不完整。'] }
  }
  const checkoutComposition = checkoutCompositionForLines(lines)
  const cashierDraft = {
    ...clone(current),
    lines,
    summary: cartSummaryForLines(lines),
    checkoutComposition,
    primaryAction: checkoutComposition.primaryAction,
    primaryActionLabel: checkoutComposition.primaryActionLabel,
    complete: true,
    managedLineRoles: ['sale', 'entitlement_service']
  }
  fixtureCashierDraft = clone(cashierDraft)
  const versions = Array.isArray(payload.commandContexts)
    ? payload.commandContexts.map((context) => ({
        kind: context.kind,
        id: context.id,
        version: Number(context.expectedVersion)
      }))
    : []
  return { cashierDraft, versions }
}

function currentCheckoutScenario() {
  if (checkoutScenarios.has(scenario)) return scenario
  return 'checkout-order'
}

function fixtureRoomCandidates() {
  return (cashierV3State.room?.categories || []).flatMap((category) => (
    (category.rooms || []).map((room) => {
      const roomId = room.id || room.roomId
      const roomVersion = Number(room.revision ?? room.roomVersion)
      const roomTimeSlotId = room.roomTimeSlotId || `open-service:${roomId}`
      const roomTimeSlotVersion = Number(room.roomTimeSlotVersion || roomVersion + 1000)
      const selectable = room.status === '空闲'
      return {
        id: roomId,
        roomId,
        name: room.name,
        categoryName: category.name,
        selectable,
        canSelect: selectable,
        disabledReason: selectable ? '' : '该房间当前已被占用',
        availabilityDescription: selectable ? '当前空闲' : '当前已占用',
        revision: roomVersion,
        roomVersion,
        roomTimeSlotId,
        roomTimeSlotVersion,
        commandContexts: [
          { kind: 'room', id: roomId, expectedVersion: roomVersion },
          { kind: 'room_time_slot', id: roomTimeSlotId, expectedVersion: roomTimeSlotVersion }
        ]
      }
    })
  ))
}

function fixtureCandidateVersions(candidates) {
  return candidates.flatMap((candidate) => candidate.commandContexts.map((context) => ({
    kind: context.kind,
    id: context.id,
    version: Number(context.expectedVersion)
  })))
}

function seedFixtureRoomVersions(state) {
  let version = 10
  for (const category of state?.room?.categories || []) {
    for (const room of category.rooms || []) {
      if (positiveFixtureVersion(room.revision ?? room.roomVersion) === null) room.revision = version
      room.roomVersion = Number(room.revision)
      room.roomTimeSlotId = room.roomTimeSlotId || `open-service:${room.id || room.roomId}`
      room.roomTimeSlotVersion = positiveFixtureVersion(room.roomTimeSlotVersion) || Number(room.revision) + 1000
      version += 1
    }
  }
}

const rawFixtureAdapter = {
  async request(action, payload = {}) {
    fixtureMetrics.actions[action] = Number(fixtureMetrics.actions[action] || 0) + 1
    document.documentElement.dataset.pdV3LastAction = action
    document.documentElement.dataset.pdV3LastActionCount = String(fixtureMetrics.actions[action])
    if (action === 'submit-checkout') {
      document.documentElement.dataset.pdV3SubmitCount = String(fixtureMetrics.actions[action])
    }

    if ([
      'open-member-selector',
      'open-writeoff-member-selector',
      'query-member-selector',
      'query-sales-orders',
      'query-order-center-records',
      'save-order-center-query-settings'
    ].includes(action)) {
      return successEnvelope(nextRootState(() => {}))
    }

    if (action === 'query-query-entities') {
      const entityType = ['person', 'store', 'organization'].includes(payload.entityType)
        ? payload.entityType
        : 'person'
      const selectorScope = String(payload.selectorContext?.scope || '')
      return successEnvelope(nextRootState((state) => {
        const page = state.queryEntitySelector?.[entityType]
        if (!page || !Array.isArray(page.records)) return
        page.records = page.records.map((record) => {
          const isCraftsmanScope = entityType === 'person' && selectorScope === 'service_actual_craftsmen'
          const selectable = isCraftsmanScope ? record.positionName === '手艺人' : true
          return {
            ...record,
            selectable,
            disabled: !selectable,
            selectableReason: selectable ? '' : '当前岗位不能作为本次服务手艺人'
          }
        })
      }))
    }

    if (action === 'open-member-debt-repayment') {
      const memberId = String(payload.memberId || '').trim()
      const member = (cashierV3State.memberCenter?.records || []).find((record) => String(record.id) === memberId)
        || (String(cashierV3State.cashier?.member?.id || '') === memberId ? cashierV3State.cashier.member : null)
      if (!member) return failedEnvelope('PD_FIXTURE_MEMBER_NOT_FOUND', '未找到需要查看欠款的会员。')
      return successEnvelope(nextRootState((state) => {
        state.memberCenter.debtSnapshot = buildDebtSnapshot(member)
      }))
    }

    if (action === 'select-cashier-member' || action === 'select-writeoff-member') {
      const memberId = String(payload.memberId || '').trim()
      const selectedMember = (cashierV3State.memberSelector?.records || []).find((record) => (
        String(record.id || record.memberId || '').trim() === memberId
      ))
      if (!selectedMember) {
        return failedEnvelope('PD_FIXTURE_MEMBER_NOT_FOUND', '未找到本次选择的会员。')
      }

      const state = nextRootState((next) => {
        const memberDetail = (next.memberCenter?.records || []).find((record) => (
          String(record.id || record.memberId || '').trim() === memberId
          || (record.memberNo && record.memberNo === selectedMember.memberNo)
        ))
        const accountBalance = Number(memberDetail?.accountBalance || selectedMember.accountBalance || 0)
        const member = {
          ...(memberDetail ? clone(memberDetail) : {}),
          ...clone(selectedMember),
          serviceAdvisorName: memberDetail?.exclusiveServiceStaff || selectedMember.serviceAdvisorName || '',
          cardBenefitAmount: Number(memberDetail?.remainingProjectAmount || selectedMember.cardBenefitAmount || 0),
          principalBalance: Number(memberDetail?.principalBalance ?? selectedMember.principalBalance ?? accountBalance),
          giftBalance: Number(memberDetail?.giftBalance ?? selectedMember.giftBalance ?? 0)
        }
        if (action === 'select-cashier-member') {
          const previousMemberId = String(next.cashier?.member?.id || next.cashier?.member?.memberId || '')
          if (previousMemberId && previousMemberId !== memberId) clearCustomerBoundEntitlements(next)
          next.cashier.customerMode = 'member'
          next.cashier.member = member
        } else {
          next.writeoff.member = member
        }
      })
      return successEnvelope(state, { message: '已选择会员。' })
    }

    if (action === 'set-guest-order') {
      const state = nextRootState((next) => {
        clearCustomerBoundEntitlements(next)
        next.cashier.customerMode = 'guest'
        next.cashier.member = null
      })
      return successEnvelope(state, { message: '已切换为游客开单。' })
    }

    if (action === 'prepare-empty-room-cashier') {
      const candidate = fixtureRoomCandidates().find((room) => String(room.roomId) === String(payload.roomId))
      if (!candidate) return failedEnvelope('RESOURCE_NOT_FOUND', '该房间不存在、已停用或不属于当前门店。')
      if (!candidate.selectable || Number(candidate.roomVersion) !== Number(payload.roomVersion)) {
        return conflictEnvelope('RESOURCE_VERSION_CONFLICT', '该房间状态已经变化，请刷新房态后重试。')
      }
      const intentId = String(payload.roomOpenIntentId || '').trim()
      if (!intentId) return failedEnvelope('INVALID_COMMAND_CONTEXT', '房间开单信息无效，请重新操作。')
      const intent = {
        contractVersion: 'cashier-v3-empty-room-hang-preparation-v1',
        source: CASHIER_ROOM_OPEN_INTENT_SOURCE,
        intentId,
        stateContextId: cashierV3State.stateContextId,
        roomId: candidate.roomId,
        roomName: candidate.name,
        roomVersion: candidate.roomVersion,
        roomTimeSlotId: candidate.roomTimeSlotId,
        roomTimeSlotVersion: candidate.roomTimeSlotVersion,
        eventless: true,
        roomOccupied: false,
        cartReset: false
      }
      return actionDataEnvelope({ roomOpenIntent: intent }, {
        message: '房间已带入收银台；当前尚未占用，挂单成功后才会开始服务。',
        versions: fixtureCandidateVersions([candidate]),
        navigation: {
          routeName: 'cashier-v3-cashier',
          query: {
            roomOpenIntent: '1',
            roomOpenIntentSource: intent.source,
            roomOpenIntentId: intent.intentId,
            preferredRoomId: String(intent.roomId),
            preferredRoomName: intent.roomName,
            preferredRoomVersion: String(intent.roomVersion),
            preferredRoomTimeSlotId: intent.roomTimeSlotId,
            preferredRoomTimeSlotVersion: String(intent.roomTimeSlotVersion)
          }
        }
      })
    }

    if (action === 'open-hang-order') {
      const lines = clone(fixtureCashierDraft?.lines || cashierV3State.cashier?.cart?.lines || [])
      if (!lines.length) return failedEnvelope('INVALID_COMMAND_CONTEXT', '请先添加需要挂单的项目。')
      const candidates = fixtureRoomCandidates()
      let preferred = null
      if (payload.roomOpenIntentSource || payload.preferredRoomId) {
        if (payload.roomOpenIntentSource !== CASHIER_ROOM_OPEN_INTENT_SOURCE) {
          return failedEnvelope('INVALID_COMMAND_CONTEXT', '房间开单信息无效，请重新从房态图开单。')
        }
        preferred = candidates.find((room) => String(room.roomId) === String(payload.preferredRoomId)) || null
        if (!preferred
          || !preferred.selectable
          || Number(preferred.roomVersion) !== Number(payload.preferredRoomVersion)
          || preferred.roomTimeSlotId !== payload.preferredRoomTimeSlotId
          || Number(preferred.roomTimeSlotVersion) !== Number(payload.preferredRoomTimeSlotVersion)) {
          return conflictEnvelope('RESOURCE_VERSION_CONFLICT', '该房间状态已经变化，购物车已保留，请重新从房态图开单。')
        }
      }
      const workspaceContext = {
        kind: 'cashier_workspace',
        id: cashierV3State.workspace.id,
        expectedVersion: Number(cashierV3State.workspace.revision)
      }
      const commandContexts = [workspaceContext]
      const snapshot = {
        contractVersion: 'cashier-v3-empty-room-hang-preparation-v1',
        preparationReady: true,
        snapshotReady: true,
        preparationRequestId: payload.preparationRequestId,
        preparationToken: `PD-HANG-${payload.preparationRequestId}`,
        status: 'editing',
        eventless: true,
        cartPreserved: true,
        cartSelectedCount: lines.length,
        cartLineFingerprint: lines.map((line) => `${line.id}:${line.quantity}`).join('|'),
        startServiceAvailable: true,
        startServiceUnavailableReason: '',
        preferredMode: preferred ? 'start_service' : 'normal',
        preferredRoomId: preferred?.roomId || null,
        preferredRoomVersion: preferred?.roomVersion || null,
        preferredRoomTimeSlotId: preferred?.roomTimeSlotId || null,
        preferredRoomTimeSlotVersion: preferred?.roomTimeSlotVersion || null,
        roomCandidates: candidates,
        commandContexts,
        businessEffects: {
          roomOccupied: false,
          salesCreated: false,
          serviceCreated: false,
          writeoffCreated: false,
          performanceCreated: false,
          eventCreated: false,
          outboxCreated: false
        }
      }
      return actionDataEnvelope({ hangOrderPreparation: snapshot }, {
        message: '挂单准备已完成；尚未占用房间，也未产生订单、服务或业绩。',
        versions: [
          ...commandContexts.map((context) => ({
            kind: context.kind,
            id: context.id,
            version: Number(context.expectedVersion)
          })),
          ...fixtureCandidateVersions(candidates)
        ]
      })
    }

    if (action === 'submit-hang-order') {
      const lines = clone(fixtureCashierDraft?.lines || cashierV3State.cashier?.cart?.lines || [])
      const expectedFingerprint = lines.map((line) => `${line.id}:${line.quantity}`).join('|')
      const expectedToken = `PD-HANG-${payload.preparationRequestId}`
      if (!lines.length
        || payload.preparationToken !== expectedToken
        || payload.cartLineFingerprint !== expectedFingerprint) {
        return conflictEnvelope(
          'RESOURCE_VERSION_CONFLICT',
          '挂单准备信息已经变化，购物车已保留，请重新打开挂单页面。'
        )
      }
      const mode = payload.mode === 'start_service' ? 'start_service' : payload.mode === 'normal' ? 'normal' : ''
      if (!mode) return failedEnvelope('INVALID_COMMAND_CONTEXT', '挂单方式无效，请重新打开挂单页面。')

      let chosenRoom = null
      if (mode === 'start_service') {
        chosenRoom = fixtureRoomCandidates().find((room) => String(room.roomId) === String(payload.roomId)) || null
        if (!chosenRoom
          || !chosenRoom.selectable
          || Number(chosenRoom.roomVersion) !== Number(payload.roomVersion)
          || chosenRoom.roomTimeSlotId !== payload.roomTimeSlotId
          || Number(chosenRoom.roomTimeSlotVersion) !== Number(payload.roomTimeSlotVersion)) {
          return conflictEnvelope(
            'RESOURCE_VERSION_CONFLICT',
            '该房间已被占用或状态已经变化，购物车已保留，请重新选择空闲房间。'
          )
        }
      }

      const hangOrderId = `HGO-PD-${String(payload.preparationRequestId).replace(/[^A-Za-z0-9_-]/g, '').slice(-32)}`
      const hangOrderNo = `HG${String(Date.now()).slice(-12)}`
      const memberName = cashierV3State.cashier?.member?.name || '游客'
      const receivableAmount = cartSummaryForLines(lines).receivableAmount
      const emptyComposition = checkoutCompositionForLines([])
      const emptyDraft = {
        workspaceId: cashierV3State.workspace.id,
        stateContextId: cashierV3State.stateContextId,
        customerMode: 'guest',
        memberId: 0,
        status: 'editing',
        lines: [],
        summary: cartSummaryForLines([]),
        checkoutComposition: emptyComposition,
        primaryAction: '',
        primaryActionLabel: '',
        lineFingerprint: '0'.repeat(64),
        complete: true,
        managedLineRoles: ['sale', 'entitlement_service']
      }
      const state = nextRootState((next) => {
        next.workspace.revision = Number(next.workspace.revision || 0) + 1
        next.cashier.customerMode = 'guest'
        next.cashier.member = null
        next.cashier.cart = {
          ...(next.cashier.cart || {}),
          lines: [],
          summary: cartSummaryForLines([]),
          primaryAction: '',
          primaryActionLabel: ''
        }
        next.cashier.checkoutComposition = emptyComposition
        next.cashier.checkout = null
        next.cashier.serviceOrder = null
        if (chosenRoom) {
          const room = (next.room?.categories || [])
            .flatMap((category) => category.rooms || [])
            .find((candidate) => String(candidate.id || candidate.roomId) === String(chosenRoom.roomId))
          if (room) {
            Object.assign(room, {
              status: '服务中',
              memberName,
              serviceDuration: '刚刚开始',
              primaryCraftsman: '',
              hangOrderId,
              roomTimeSlotVersion: Number(chosenRoom.roomTimeSlotVersion) + 1
            })
          }
        }
        const record = {
          id: hangOrderId,
          revision: 1,
          hangAt: new Date().toLocaleString('zh-CN', { hour12: false }),
          memberName,
          phone: '',
          itemCount: lines.length,
          receivableAmount,
          operator: next.operator?.name || '当前操作人',
          orderNote: '',
          status: chosenRoom ? '服务中' : '普通挂单',
          roomName: chosenRoom?.name || ''
        }
        next.hangOrders.records = [record, ...(next.hangOrders?.records || [])]
        next.hangOrders.total = next.hangOrders.records.length
      })
      fixtureCashierDraft = clone(emptyDraft)
      return successEnvelope(state, {
        message: chosenRoom ? '挂单成功，房间已自动占用。' : '挂单成功。',
        data: {
          cashierDraft: emptyDraft,
          hangOrderSubmission: {
            hangOrder: {
              hangOrderId,
              hangOrderNo,
              hangStatus: chosenRoom ? 'service_in_progress' : 'pending_checkout',
              hangVersion: 1,
              roomId: chosenRoom?.roomId || 0,
              roomTimeSlotId: chosenRoom?.roomTimeSlotId || ''
            },
            roomOccupation: chosenRoom
              ? {
                  roomId: chosenRoom.roomId,
                  roomName: chosenRoom.name,
                  occupied: true,
                  slotVersion: Number(chosenRoom.roomTimeSlotVersion) + 1,
                  ownerKind: 'cashier_v3_hang_order',
                  ownerId: hangOrderId
                }
              : null,
            cashierDraft: emptyDraft
          }
        }
      })
    }

    if (action === 'open-add-card-service-project') {
      const memberId = String(cashierV3State.cashier?.member?.id || cashierV3State.cashier?.member?.memberId || '')
      if (!memberId || cashierV3State.cashier?.customerMode !== 'member' || String(payload.memberId || '') !== memberId) {
        return failedEnvelope('ENTITLEMENT_MEMBER_REQUIRED', '请先选择需要使用权益的会员。')
      }
      if (!String(payload.selectorRequestId || '').trim()) {
        return failedEnvelope('ENTITLEMENT_SELECTOR_REQUEST_REQUIRED', '权益选择请求无效，请重新打开。')
      }
      fixtureEntitlementSelector = makeEntitlementSelectorData(payload)
      return actionDataEnvelope({ entitlementSelector: clone(fixtureEntitlementSelector) }, {
        message: '会员权益已重新读取。',
        versions: fixtureEntitlementSelector.commandContexts.map((context) => ({
          kind: context.kind,
          id: context.id,
          version: Number(context.expectedVersion)
        }))
      })
    }

    if (action === 'add-checkout-entitlement-lines') {
      const addition = addEntitlementLinesDraft(payload)
      if (addition.error) return failedEnvelope(addition.error[0], addition.error[1])
      return actionDataEnvelope({ cashierDraft: addition.cashierDraft }, {
        message: '卡内项目已加入本次购物车。',
        versions: addition.versions
      })
    }

    if (['clear-cart-lines', 'remove-cart-line', 'change-cart-line-quantity', 'update-cart-line-service-settings'].includes(action)) {
      const mutation = mutateFixtureCashierDraft(action, payload)
      if (mutation.error) return failedEnvelope(mutation.error[0], mutation.error[1])
      return actionDataEnvelope({ cashierDraft: mutation.cashierDraft }, {
        message: action === 'clear-cart-lines'
          ? '购物车已清空。'
          : (action === 'remove-cart-line'
          ? '购物车项目已删除。'
          : (action === 'change-cart-line-quantity' ? '购物车数量已更新。' : '服务设置已保存。')),
        versions: mutation.versions
      })
    }

    if (action === 'create-member') {
      const name = String(payload.name || '').trim()
      const phone = String(payload.phone || '').trim()
      if (!name || !/^1[3-9]\d{9}$/.test(phone)) {
        return failedEnvelope('MEMBER_VALIDATION_FAILED', '请填写正确的会员姓名和手机号。')
      }
      const existing = (cashierV3State.memberSelector?.records || []).find((record) => (
        String(record.phone || '').trim() === phone
      ))
      if (existing) {
        return {
          result: {
            status: 'conflict',
            code: 'MEMBER_PHONE_EXISTS',
            message: '该手机号已存在会员，请直接选择已有会员。'
          },
          data: { existingMember: clone(existing) }
        }
      }
      const nextId = `member-${Date.now()}`
      const member = {
        id: nextId,
        name,
        phone,
        memberNo: `HY${String(Date.now()).slice(-10)}`,
        status: '正常',
        storeName: cashierV3State.storeName || cashierV3State.currentStore?.name || '当前门店',
        organizationName: '当前组织 / 当前门店',
        accountBalance: 0,
        principalBalance: 0,
        giftBalance: 0,
        cardBenefitAmount: 0,
        remainingTimes: 0,
        activeCardCount: 0,
        totalAvailableAmount: 0,
        ...(payload.sex !== '' && payload.sex !== null && payload.sex !== undefined ? { sex: payload.sex } : {}),
        ...(payload.birthday ? { birthday: payload.birthday } : {}),
        ...(payload.memberLevelId ? { levelId: payload.memberLevelId } : {}),
        ...(Array.isArray(payload.memberTagIds) ? { tagIds: clone(payload.memberTagIds) } : {}),
        ...(payload.exclusiveServicePersonId ? { exclusiveServicePersonId: payload.exclusiveServicePersonId } : {})
      }
      const state = nextRootState((next) => {
        next.memberSelector = {
          ...(next.memberSelector || {}),
          records: [member, ...(next.memberSelector?.records || [])],
          total: Number(next.memberSelector?.total || 0) + 1,
          page: 1
        }
        next.memberCenter = {
          ...(next.memberCenter || {}),
          records: [member, ...(next.memberCenter?.records || [])],
          total: Number(next.memberCenter?.total || 0) + 1,
          page: 1
        }
      })
      return successEnvelope(state, {
        message: '会员建档成功。',
        data: { member }
      })
    }

    if ([
      'prepare-service-completion',
      'prepare-room-service-completion',
      'prepare-reservation-service-completion',
      'prepare-writeoff-service-completion'
    ].includes(action)) {
      const preparationRequestId = payload.preparationRequestId
      if (!preparationRequestId || !payload.serviceOrderId) {
        return failedEnvelope('PD_FIXTURE_SERVICE_CONTEXT_MISSING', '服务确认联调缺少请求号或服务单。')
      }
      const state = makeServiceCompletionState(preparationRequestId)
      return successEnvelope(state, {
        overlay: {
          name: 'service-completion',
          preparationRequestId,
          serviceOrderId: payload.serviceOrderId
        }
      })
    }

    if (action === 'open-reservation-editor') {
      if (!payload.preparationRequestId) {
        return failedEnvelope('PD_FIXTURE_RESERVATION_PREPARATION_MISSING', '预约编辑联调缺少准备请求号。')
      }
      const state = makePreparedReservationEditorState(payload)
      return successEnvelope(state, {
        overlay: {
          name: 'reservation-editor',
          preparationRequestId: payload.preparationRequestId,
          reservationId: payload.reservationId || null
        }
      })
    }

    if (action === 'recalculate-reservation-plan') {
      const recalculationRequestId = payload.recalculationRequestId
      const state = nextRootState((next) => {
        const currentDraft = clone(payload.reservation || next.reservation?.editor?.draft || {})
        currentDraft.scheduleCalculationReady = true
        currentDraft.scheduleRecalculationRequestId = recalculationRequestId
        currentDraft.expectedEndAt = '2026-07-27 15:50'
        currentDraft.expectedEndLabel = '今天 15:50'
        currentDraft.durationSummary = '预计服务 80 分钟；实际采用时长以本次系统检查结果为准。'
        currentDraft.availabilityCheck = {
          status: 'available',
          message: currentDraft.roomId
            ? '当前人员与房间均可安排。'
            : '当前人员可预约；房间暂不分配，不影响保存。'
        }
        currentDraft.conflicts = []
        next.reservation.editor = {
          ...(next.reservation?.editor || {}),
          draft: currentDraft,
          recalculation: {
            recalculationRequestId,
            draft: currentDraft
          }
        }
      })
      return successEnvelope(state, {
        data: {
          reservationPlan: {
            recalculationRequestId,
            draft: clone(state.reservation.editor.draft)
          }
        }
      })
    }

    if (action === 'create-reservation' || action === 'update-reservation') {
      const state = nextRootState((next) => {
        next.reservation.editor = {
          ...(next.reservation?.editor || {}),
          submission: {
            status: 'succeeded',
            preparationRequestId: payload.preparationRequestId,
            originalIdempotencyKey: payload.idempotencyKey,
            idempotencyKey: payload.idempotencyKey,
            stateContextId: next.stateContextId,
            reservationId: payload.reservationId || 'reservation-pd-new'
          }
        }
      })
      return successEnvelope(state, { message: '预约已保存。' })
    }

    if (action === 'open-reservation-detail' || action === 'open-room-next-reservation') {
      const reservationId = payload.reservationId || 'reservation-1'
      const state = nextRootState((next) => {
        next.reservation.detail = createReservationDetail(reservationId)
      })
      return successEnvelope(state, {
        overlay: { name: 'reservation-detail', reservationId }
      })
    }

    if (action === 'view-sales-order' || action === 'open-sales-order-detail') {
      const state = makeSalesOrderDetailState()
      const salesOrderId = payload.salesOrderId || state.orderCenter?.salesOrderDetail?.id || 'sales-1'
      return successEnvelope(state, {
        overlay: { name: 'sales-order-detail', orderId: salesOrderId, salesOrderId },
        navigation: { routeName: 'cashier-v3-order-center' }
      })
    }

    if (action === 'confirm-reservation') {
      const state = nextRootState((next) => {
        next.reservation.records = (next.reservation?.records || []).map((record) => (
          String(record.id) === String(payload.reservationId)
            ? {
                ...record,
                revision: Number(record.revision || 0) + 1,
                status: '已预约',
                primaryAction: {
                  code: 'start-service',
                  label: '开始服务',
                  primary: true
                }
              }
            : record
        ))
      })
      return successEnvelope(state, { message: '预约已确认。' })
    }

    if (action === 'start-reservation-service') {
      const serviceOrderId = 'FW202607270020'
      const state = nextRootState((next) => {
        const targetRecord = (next.reservation?.records || []).find((record) => String(record.id) === String(payload.reservationId))
        const detail = String(next.reservation?.detail?.id || '') === String(payload.reservationId)
          ? next.reservation.detail
          : createReservationDetail(payload.reservationId)
        const nextReservationVersion = Number(targetRecord?.revision || 0) + 1
        const assignedRoomName = targetRecord?.roomName && targetRecord.roomName !== '待分配房间'
          ? targetRecord.roomName
          : '待分配房间'
        next.reservation.records = (next.reservation?.records || []).map((record) => (
          String(record.id) === String(payload.reservationId)
            ? {
                ...record,
                revision: nextReservationVersion,
                status: '服务中',
                serviceOrderId,
                serviceOrderRevision: 1,
                primaryAction: {
                  code: 'end-service',
                  label: '结束服务',
                  primary: true,
                  serviceOrderId,
                  serviceOrderVersion: 1
                }
              }
            : record
        ))
        if (String(next.reservation?.detail?.id || '') === String(payload.reservationId)) {
          next.reservation.detail = {
            ...detail,
            revision: nextReservationVersion,
            statusLabel: '服务中',
            actualStartAt: '2026-07-27 15:00',
            room: {
              ...(detail.room || {}),
              name: assignedRoomName,
              statusLabel: assignedRoomName === '待分配房间' ? '服务中，待分配房间' : '服务中，已占用'
            },
            serviceOrderId,
            serviceOrderRevision: 1,
            serviceOrder: {
              id: serviceOrderId,
              revision: 1,
              serviceNo: serviceOrderId,
              status: '服务中'
            },
            relatedRecords: {
              ...(detail.relatedRecords || {}),
              serviceOrders: [{ id: serviceOrderId, no: serviceOrderId, statusLabel: '服务中' }]
            },
            timeline: [
              ...(detail.timeline || []),
              {
                id: `timeline-${payload.reservationId}-start`,
                title: '开始服务',
                occurredAt: '2026-07-27 15:00',
                operatorName: '当前操作人',
                description: assignedRoomName === '待分配房间'
                  ? '服务已开始，当前仍待分配房间。'
                  : `服务已开始并实际占用${assignedRoomName}。`
              }
            ],
            actions: [{
              code: 'end-service',
              label: '结束服务',
              primary: true,
              serviceOrderId,
              serviceOrderVersion: 1
            }]
          }
        }
        next.cashier.serviceOrder = {
          id: serviceOrderId,
          revision: 1,
          serviceNo: serviceOrderId,
          status: '服务中',
          roomName: assignedRoomName,
          sourceReservationId: payload.reservationId,
          sourceReservationNo: detail.reservationNo,
          confirmationRequired: true,
          sections: [{
            key: 'reservation-projects',
            label: '预约项目',
            description: '按本次预约的主项目和明细项目确认实际完成情况。',
            lineIds: (detail.projects || []).map((project) => project.id)
          }],
          completion: { status: 'editing' }
        }
        const memberRecord = (next.memberCenter?.records || []).find((member) => (
          String(member.memberNo || '') === String(detail.member?.memberNo || '')
        ))
        const selectedMember = {
          ...(memberRecord || {}),
          ...(detail.member || {}),
          id: memberRecord?.id || detail.member?.id
        }
        next.cashier.customerMode = 'member'
        next.cashier.member = clone(selectedMember)
        next.writeoff.member = clone(selectedMember)
        next.serviceCompletion = {
          ...(next.serviceCompletion || {}),
          source: 'reservation',
          serviceOrder: clone(next.cashier.serviceOrder),
          lines: serviceCompletionLinesFromReservation(detail),
          commandContexts: serviceOrderContext(next)
        }
        const assignedRoom = assignedRoomName === '待分配房间'
          ? null
          : (next.room?.categories || [])
          .flatMap((category) => category.rooms || [])
          .find((room) => room.name === assignedRoomName)
        if (assignedRoom) {
          Object.assign(assignedRoom, {
            status: '服务中',
            memberName: '陈女士',
            serviceDuration: '刚刚开始',
            primaryCraftsman: '李美容师',
            pendingWriteoffCount: 1,
            newConsumptionAmount: 220,
            serviceOrderId,
            serviceOrderRevision: 1
          })
        }
      })
      return successEnvelope(state, { message: '服务已开始；房间从现在起才实际占用。' })
    }

    if ([
      'query-reservations',
      'save-reservation-query-settings',
      'change-reservation-calendar-date',
      'open-reservation-more-actions',
      'cancel-reservation',
      'reject-reservation',
      'mark-reservation-no-show'
    ].includes(action)) {
      return successEnvelope(nextRootState(() => {}))
    }

    if (action === 'open-room-detail') {
      const state = nextRootState((next) => {
        next.room.detail = createRoomDetail()
      })
      return successEnvelope(state, {
        overlay: { name: 'room-detail', roomId: payload.roomId || 'room-2' }
      })
    }

    if (action === 'open-unassigned-room-list' || action === 'refresh-room-status') {
      return successEnvelope(nextRootState(() => {}))
    }

    if (action === 'prepare-room-assignment') {
      if (!payload.preparationRequestId || !payload.assignmentScope || !payload.assignmentMode) {
        return failedEnvelope('PD_FIXTURE_ROOM_ASSIGNMENT_CONTEXT_MISSING', '房间安排联调缺少准备请求、范围或模式。')
      }
      const state = makeRoomAssignmentState(payload)
      const assignment = state.room.assignment
      return successEnvelope(state, {
        overlay: {
          name: 'room-assignment',
          preparationRequestId: payload.preparationRequestId,
          roomAssignmentPreparationId: payload.preparationRequestId,
          assignmentScope: assignment.assignmentScope,
          assignmentMode: assignment.assignmentMode,
          mode: assignment.mode,
          reservationId: assignment.reservation?.id || null,
          reservationVersion: assignment.reservation?.revision || null,
          serviceOrderId: assignment.serviceOrder?.id || null,
          serviceOrderVersion: assignment.serviceOrder?.revision || null,
          roomId: assignment.currentRoom?.id || null
        }
      })
    }

    if (action === 'save-service-room-assignment') {
      const state = nextRootState((next) => {
        next.room.assignment = null
        next.room.refreshedAt = '2026-07-27 14:33:00'
      })
      return successEnvelope(state, { message: '房间安排已保存。' })
    }

    if (action === 'open-room-service-session') {
      const state = nextRootState((next) => {
        next.room.detail = createRoomDetail()
      })
      return successEnvelope(state, {
        overlay: { name: 'room-detail', roomId: payload.roomId || 'room-2' }
      })
    }

    if ([
      'open-room-service-checkout',
      'open-reservation-checkout',
      'open-writeoff-service-checkout',
      'prepare-room-service-checkout',
      'prepare-reservation-checkout',
      'prepare-service-checkout'
    ].includes(action)) {
      const state = makeCheckoutResumeFromService(payload)
      return successEnvelope(state, {
        navigation: { routeName: 'cashier-v3-cashier' }
      })
    }

    if (action === 'confirm-service-completion') {
      const state = nextRootState((next) => {
        const current = clone(next.cashier?.serviceOrder || {})
        current.revision = Number(current.revision || 0) + 1
        current.status = '待结账'
        current.serviceConfirmed = true
        current.confirmationRequired = false
        current.completion = {
          ...(current.completion || {}),
          status: 'succeeded',
          confirmationReady: true,
          snapshotToken: 'PD-SERVICE-SNAPSHOT-TOKEN-1',
          nextAction: 'continue-service-checkout',
          nextActionLabel: '继续结账',
          successLabel: '服务已确认'
        }
        next.cashier.serviceOrder = clone(current)
        const actualCraftsmen = []
        const actualCraftsmanIds = new Set()
        ;(next.serviceCompletion?.lines || []).forEach((line) => {
          ;(line.actualCraftsmen || []).forEach((staff) => {
            if (!staff?.id || actualCraftsmanIds.has(String(staff.id))) return
            actualCraftsmanIds.add(String(staff.id))
            actualCraftsmen.push({
              id: staff.id,
              name: staff.name,
              roleLabel: staff.isPrimary ? '主要手艺人' : '协作手艺人'
            })
          })
        })
        const sourceReservationId = current.sourceReservationId
        if (sourceReservationId) {
          next.reservation.records = (next.reservation?.records || []).map((record) => (
            String(record.id) === String(sourceReservationId)
              ? {
                  ...record,
                  revision: Number(record.revision || 0) + 1,
                  status: '待结账',
                  serviceOrderId: current.id,
                  serviceOrderRevision: current.revision,
                  primaryAction: {
                    code: 'go-checkout',
                    label: '去结账',
                    primary: true,
                    serviceOrderId: current.id,
                    serviceOrderVersion: current.revision
                  }
                }
              : record
          ))
          if (String(next.reservation?.detail?.id || '') === String(sourceReservationId)) {
            const detail = next.reservation.detail
            next.reservation.detail = {
              ...detail,
              revision: Number(detail.revision || 0) + 1,
              statusLabel: '待结账',
              actualEndAt: '2026-07-27 16:15',
              actualCraftsmen,
              room: {
                ...(detail.room || {}),
                statusLabel: '服务已结束，待结账'
              },
              serviceOrderRevision: current.revision,
              serviceOrder: {
                id: current.id,
                revision: current.revision,
                serviceNo: current.serviceNo,
                status: '待结账'
              },
              relatedRecords: {
                ...(detail.relatedRecords || {}),
                serviceOrders: [{ id: current.id, no: current.serviceNo, statusLabel: '待结账' }],
                writeoffs: [{ id: 'HX202607270020', no: 'HX202607270020', statusLabel: '已核销' }]
              },
              timeline: [
                ...(detail.timeline || []),
                {
                  id: `timeline-${sourceReservationId}-complete`,
                  title: '确认本次服务',
                  occurredAt: '2026-07-27 16:15',
                  operatorName: '当前操作人',
                  description: '卡内项目已核销；服务已结束，等待结账。'
                }
              ],
              actions: [{
                code: 'go-checkout',
                label: '去结账',
                primary: true,
                serviceOrderId: current.id,
                serviceOrderVersion: current.revision
              }]
            }
          }
          const assignedRoom = (next.room?.categories || [])
            .flatMap((category) => category.rooms || [])
            .find((room) => room.name === current.roomName)
          if (assignedRoom) {
            Object.assign(assignedRoom, {
              status: '待结账',
              serviceDuration: '服务已结束',
              pendingWriteoffCount: 0,
              serviceOrderRevision: current.revision
            })
          }
        }
        next.serviceCompletion = {
          ...(next.serviceCompletion || {}),
          commandContexts: serviceOrderContext(next),
          serviceOrder: current
        }
      })
      return successEnvelope(state)
    }

    if (action === 'continue-service-checkout') {
      return successEnvelope(nextRootState(() => {}))
    }

    if (action === 'prepare-checkout') {
      const state = makeCheckoutState('checkout-order', {
        preparationRequestId: payload.preparationRequestId,
        cashierDraft: fixtureCashierDraft
      })
      return successEnvelope(state)
    }

    if (action === 'prepare-debt-repayment') {
      const snapshot = cashierV3State.memberCenter?.debtSnapshot || {}
      const record = (snapshot.records || []).find((item) => String(item.id || item.debtItemId) === String(payload.debtRecordId || payload.debtItemId))
      const amount = Number(payload.amount)
      if (!record || !Number.isInteger(amount) || amount <= 0 || amount > Number(record.remainingDebtAmount || 0)) {
        return failedEnvelope('PD_FIXTURE_DEBT_REPAYMENT_INVALID', '还款金额或欠款记录已经变化，请重新打开欠款明细。')
      }
      return successEnvelope(makeDebtRepaymentCheckoutState(payload))
    }

    if (action === 'checkout-step-next' || action === 'checkout-step-back') {
      const state = mutateCheckoutState((checkout) => {
        checkout.status = 'editing'
        checkout.activeStep = Number(payload.step)
      })
      return successEnvelope(state)
    }

    if (action === 'add-payment-method') {
      const checkout = cashierV3State.cashier?.checkout || {}
      const method = (checkout.payment?.methods || []).find((item) => item.id === payload.paymentMethodId)
      const remainingAmount = Number(checkout.payment?.summary?.remainingAmount || 0)
      if (!method || method.canAdd === false) {
        return failedEnvelope('PD_FIXTURE_PAYMENT_METHOD_INVALID', '请选择可用的记账收款方式。')
      }
      const state = mutateCheckoutPaymentDraft((draft) => {
        draft.payment.selectedLines.push(createPaymentLine(
          `pd-payment-${payload.paymentMethodId}-${Number(draft.checkoutRequestVersion || 0) + 1}`,
          method.name,
          remainingAmount,
          '待收款',
          { method: payload.paymentMethodId }
        ))
      })
      return successEnvelope(state, { message: '收款方式已添加。' })
    }

    if (action === 'update-payment-line') {
      const checkout = cashierV3State.cashier?.checkout || {}
      const currentLine = (checkout.payment?.selectedLines || [])
        .find((item) => item.id === payload.paymentLineId)
      const amountText = String(payload.amount ?? '')
      const amount = Number(amountText)
      const otherAmount = (checkout.payment?.selectedLines || [])
        .filter((line) => line.id !== payload.paymentLineId)
        .reduce((total, line) => total + Number(line.amount || 0), 0)
      const receivableAmount = Number(checkout.payment?.summary?.receivableAmount || 0)
      if (!currentLine || currentLine.canEdit === false) {
        return failedEnvelope('PD_FIXTURE_PAYMENT_LINE_CHANGED', '该收款明细已变化，请刷新后重试。')
      }
      if (!/^(?:0|[1-9]\d*)$/.test(amountText)
        || !(amount >= 0)
        || otherAmount + amount > receivableAmount) {
        return failedEnvelope('PD_FIXTURE_PAYMENT_AMOUNT_INVALID', '收款合计不能超过应收金额。')
      }
      const state = mutateCheckoutPaymentDraft((draft) => {
        const line = draft.payment?.selectedLines?.find((item) => item.id === payload.paymentLineId)
        if (!line) return
        line.amount = amount
        line.externalTransactionNo = payload.externalTransactionNo || ''
        line.remark = payload.remark || ''
        line.noteSummary = line.remark || '未填写备注'
      })
      return successEnvelope(state, { message: '收款明细已更新。' })
    }

    if (action === 'remove-payment-line') {
      const checkout = cashierV3State.cashier?.checkout || {}
      const currentLine = (checkout.payment?.selectedLines || [])
        .find((item) => item.id === payload.paymentLineId)
      if (!currentLine || currentLine.canRemove === false) {
        return failedEnvelope('PD_FIXTURE_PAYMENT_LINE_CHANGED', '该收款明细已变化，请刷新后重试。')
      }
      const state = mutateCheckoutPaymentDraft((draft) => {
        draft.payment.selectedLines = draft.payment.selectedLines
          .filter((line) => line.id !== payload.paymentLineId)
      })
      return successEnvelope(state, { message: '收款明细已删除。' })
    }

    if (action === 'prepare-checkout-submission') {
      const checkout = cashierV3State.cashier?.checkout || {}
      const remaining = Number(checkout.payment?.summary?.remainingAmount || 0)
      if (remaining !== 0
        || checkout.composition?.hasSale !== true
        || checkout.composition?.hasEntitlement === true
        || Number(checkout.balancePaymentAmount || 0) !== 0
        || Number(checkout.debtAmount || 0) !== 0) {
        return failedEnvelope(
          'PD_FIXTURE_SALE_ONLY_SUBMISSION_NOT_READY',
          '当前仅开放本次购买且已收齐的结账流程。'
        )
      }
      const state = nextRootState((next) => {
        const prepared = clone(next.cashier?.checkout || {})
        advanceCheckoutPaymentDraft(prepared, next)
        prepared.status = 'editing'
        prepared.requestStatus = 'ready_for_submit'
        next.cashier.checkout = prepared
      })
      return successEnvelope(state, { message: '结账资源已完成最终校验。' })
    }

    if (action === 'submit-checkout' || action === 'retry-checkout' || action === 'submit-debt-repayment') {
      const state = mutateCheckoutState((checkout, rootState) => {
        const isDebtRepayment = checkout.businessType === 'debt_repayment'
        const noPaymentRequired = Number(checkout.payment?.summary?.receivableAmount || 0) === 0
        const hasSale = checkout.composition?.hasSale === true
        const hasEntitlement = checkout.composition?.hasEntitlement === true
        checkout.status = noPaymentRequired || isDebtRepayment ? 'succeeded' : 'processing'
        checkout.activeStep = 4
        checkout.originalIdempotencyKey = payload.command?.idempotencyKey
          || payload.originalIdempotencyKey
          || checkout.originalIdempotencyKey
          || 'PD-CHECKOUT-ORIGINAL-KEY-1'
        checkout.canClose = noPaymentRequired || isDebtRepayment
        checkout.canRetry = false
        checkout.completionKind = isDebtRepayment
          ? 'debt_repayment'
          : noPaymentRequired && hasEntitlement && !hasSale
            ? 'service_completed'
            : noPaymentRequired
              ? 'no_payment'
              : undefined
        checkout.completionDescription = noPaymentRequired
          ? hasEntitlement && !hasSale
            ? '卡内项目已完成服务，本次没有销售订单，也无需收款。'
            : '本次无需新增收款，销售与服务已经完成。'
          : undefined
        if (noPaymentRequired && hasSale) {
          checkout.salesOrderId = 'sales-reservation-2'
          checkout.salesOrderNo = 'XS202607270020'
        } else if (!hasSale) {
          delete checkout.salesOrderId
          delete checkout.salesOrderNo
        }
        checkout.payment.selectedLines = checkout.payment.selectedLines.map((line) => ({
          ...line,
          status: noPaymentRequired || isDebtRepayment ? '成功' : '处理中',
          canEdit: false,
          canRemove: false
        }))
        checkout.payment.resultLines = clone(checkout.payment.selectedLines)
        if (isDebtRepayment) completeDebtRepayment(rootState, checkout)
        else if (noPaymentRequired) completeServiceSessionAfterCheckout(rootState, checkout)
      })
      return successEnvelope(state)
    }

    if (action === 'query-checkout-result' || action === 'query-debt-repayment-result') {
      const state = mutateCheckoutState((checkout, rootState) => {
        checkout.status = 'succeeded'
        if (checkout.composition?.hasSale === true) {
          checkout.salesOrderId = 'sales-1'
          checkout.salesOrderNo = 'XS202607270001'
        } else {
          delete checkout.salesOrderId
          delete checkout.salesOrderNo
        }
        checkout.canClose = true
        checkout.payment.selectedLines = checkout.payment.selectedLines.map((line) => ({
          ...line,
          status: '成功',
          canEdit: false,
          canRemove: false
        }))
        checkout.payment.resultLines = clone(checkout.payment.selectedLines)
        if (checkout.businessType === 'debt_repayment') completeDebtRepayment(rootState, checkout)
        else completeServiceSessionAfterCheckout(rootState, checkout)
      })
      return successEnvelope(state)
    }

    if (action === 'return-to-payment-edit') {
      const state = mutateCheckoutState((checkout) => {
        checkout.status = 'editing'
        checkout.activeStep = 2
        checkout.canClose = false
        checkout.canRetry = false
        checkout.canReturnToPaymentEdit = false
        checkout.payment.selectedLines = checkout.payment.selectedLines.map((line) => ({
          ...line,
          status: '待收款',
          canEdit: true,
          canRemove: true
        }))
      })
      return successEnvelope(state)
    }

    if (action === 'finish-checkout-and-return') {
      return successEnvelope(nextRootState((state) => {
        state.cashier.checkout = {}
      }))
    }

    if (action === 'continue-partial-payment-recovery') {
      const state = mutateCheckoutState((checkout) => {
        checkout.status = 'editing'
        checkout.activeStep = 2
        checkout.canContinuePartialPaymentRecovery = false
        checkout.payment.selectedLines = checkout.payment.selectedLines
          .filter((line) => line.status !== '成功')
          .map((line) => ({
            ...line,
            status: '待收款',
            canEdit: true,
            canRemove: false,
            lockReason: '仅处理原请求剩余收款'
          }))
        checkout.payment.resultLines = clone(checkout.payment.selectedLines)
      })
      return successEnvelope(state)
    }

    if ([
      'open-balance-payment',
      'open-balance-payment-identity-verification',
      'toggle-combination-payment',
      'open-payment-note',
      'open-checkout-source-selector',
      'confirm-checkout-final-changes',
      'confirm-debt-warning'
    ].includes(action)) {
      return successEnvelope(nextRootState(() => {}), {
        message: '页面联调入口只演示该操作的入口状态。'
      })
    }

    if (action === 'print-sales-order-receipt') {
      return failedEnvelope('PD_FIXTURE_PRINT_NOT_TESTED', '本次改造不做真实小票打印测试。')
    }

    return failedEnvelope(
      'PD_FIXTURE_ACTION_BLOCKED',
      `开发视觉联调未开放该操作：${action}`
    )
  }
}

window.__CASHIER_V3_ADAPTER__ = {
  async request(action, payload = {}) {
    const response = await rawFixtureAdapter.request(action, payload)
    return bindFixtureResponse(action, payload, response)
  }
}
window.__PD_V3_FIXTURE_METRICS__ = fixtureMetrics
window.addEventListener('cashier-v3:ui-result', (event) => {
  document.documentElement.dataset.pdV3LastUiStatus = String(event.detail?.status || '')
  document.documentElement.dataset.pdV3LastUiCode = String(event.detail?.code || '')
  document.documentElement.dataset.pdV3LastUiOverlay = String(event.detail?.overlay?.name || event.detail?.overlay || '')
})

if (scenario === 'workbench-no-member') {
  const replacement = replaceCashierV3State(nextRootState((state) => {
    clearCustomerBoundEntitlements(state)
    state.cashier.customerMode = 'guest'
    state.cashier.member = null
    state.writeoff.member = null
  }))
  if (!replacement.accepted) {
    const detail = Array.isArray(replacement.problems) && replacement.problems.length
      ? `（${replacement.problems.join(', ')}）`
      : ''
    stopFixture(`未选会员场景装载失败：${replacement.code || 'UNKNOWN'}${detail}`)
  }
}

if (checkoutScenarios.has(scenario)) {
  const replacement = replaceCashierV3State(makeCheckoutState(currentCheckoutScenario()))
  if (!replacement.accepted) {
    stopFixture(`结账场景装载失败：${replacement.code || 'UNKNOWN'}`)
  }
}

if (reservationScenarios.has(scenario)) {
  const replacement = replaceCashierV3State(makeReservationPageState(scenario))
  if (!replacement.accepted) {
    stopFixture(`预约场景装载失败：${replacement.code || 'UNKNOWN'}`)
  }
}

if (roomScenarios.has(scenario)) {
  const replacement = replaceCashierV3State(makeRoomPageState(scenario))
  if (!replacement.accepted) {
    stopFixture(`房态场景装载失败：${replacement.code || 'UNKNOWN'}`)
  }
}

if (scenario === 'sales-order-detail') {
  const replacement = replaceCashierV3State(makeSalesOrderDetailState())
  if (!replacement.accepted) {
    stopFixture(`销售订单详情场景装载失败：${replacement.code || 'UNKNOWN'}`)
  }
}

if (scenario === 'project-replacement') {
  // 项目替换尚未冻结生产根投影合同；视觉样例只挂在本机 DEV 状态上，不能进入 Bridge 必填根键。
  cashierV3State.replacement = {
    member: { id: 'member-1', name: '肖君鹏' },
    revision: 1,
    selectedCardId: 'replacement-card-1',
    selectedTargetId: 'replacement-target-1',
    cards: [
      { id: 'replacement-card-1', name: '焕颜护理次卡', reference: 'KH202607180021', expiryText: '2027-07-17 到期' },
      { id: 'replacement-card-2', name: '年度综合护理卡', reference: 'KH202606090008', expiryText: '2027-06-08 到期' }
    ],
    sourceProjects: [
      { id: 'source-project-1', cardId: 'replacement-card-1', name: '深层清洁护理', benefitReference: 'QY-000328', remainingTimes: 4, unitAmount: 260 },
      { id: 'source-project-2', cardId: 'replacement-card-1', name: '水光补水护理', benefitReference: 'QY-000329', remainingTimes: 2, unitAmount: 320 },
      { id: 'source-project-3', cardId: 'replacement-card-2', name: '肩颈舒缓护理', benefitReference: 'QY-000415', remainingTimes: 6, unitAmount: 180 }
    ],
    sourceLines: [
      { key: 'fixture-source-line-1', sourceId: 'source-project-1', name: '深层清洁护理', benefitReference: 'QY-000328', times: 2, maxTimes: 4, amount: 520 }
    ],
    targets: [
      { id: 'replacement-target-1', name: '焕亮修护护理', category: '面部护理' },
      { id: 'replacement-target-2', name: '舒敏补水护理', category: '面部护理' },
      { id: 'replacement-target-3', name: '肩颈深度舒缓', category: '身体护理' }
    ],
    summary: { totalAmount: 520, targetBenefitCount: 1 },
    recentRecords: [
      { id: 'TH202607250001', replacementNo: 'TH202607250001', operatedAt: '2026-07-25 16:22', targetName: '舒敏补水护理', amount: 360, status: '有效' },
      { id: 'TH202607180003', replacementNo: 'TH202607180003', operatedAt: '2026-07-18 11:08', targetName: '焕亮修护护理', amount: 520, status: '有效' }
    ]
  }
}

seedFixtureMemberVersions(cashierV3State)
seedFixtureRoomVersions(cashierV3State)
const initialFixtureVersions = fixtureVersionsFromState(cashierV3State)
const initialVersionMerge = mergeCashierV3PublicVersions(
  initialFixtureVersions,
  cashierV3State.stateContextId,
  { requestStateContextId: cashierV3State.stateContextId }
)
if (initialVersionMerge.merged !== initialFixtureVersions.length || initialVersionMerge.refused !== 0) {
  stopFixture('联调根状态的资源版本无法完整装载。')
}

const app = createApp(App)
app.use(router)
app.mount('#app')

if (reservationScenarios.has(scenario)) {
  router.replace({ name: 'cashier-v3-reservation' }).then(async () => {
    await nextTick()
    if (scenario === 'reservation-editor') {
      window.dispatchEvent(new CustomEvent('cashier-v3:open-reservation-editor', {
        detail: { mode: 'create' }
      }))
    }
    if (scenario === 'reservation-detail') {
      window.dispatchEvent(new CustomEvent('cashier-v3:open-reservation-detail', {
        detail: { reservationId: 'reservation-3' }
      }))
    }
  })
}

if (roomScenarios.has(scenario)) {
  router.replace({ name: 'cashier-v3-room' }).then(async () => {
    await nextTick()
    if (scenario === 'room-detail' || scenario === 'room-assignment') {
      window.dispatchEvent(new CustomEvent('cashier-v3:open-room-detail', {
        detail: { roomId: 'room-2' }
      }))
    }
  })
}

if (scenario === 'sales-order-detail') {
  router.replace({ name: 'cashier-v3-order-center' }).then(async () => {
    await nextTick()
    const order = cashierV3State.orderCenter?.salesOrders?.[0]
    if (order?.id) {
      window.dispatchEvent(new CustomEvent('cashier-v3:open-sales-order-detail', {
        detail: { orderId: order.id }
      }))
    }
  })
}

if (scenario === 'project-replacement') {
  router.replace({ name: 'cashier-v3-replacement' })
}

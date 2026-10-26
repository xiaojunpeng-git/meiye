export const CUSTOMER_CARE_CONTRACT_VERSION = 'customer-care.v1'

const allowedPreviewHosts = new Set(['127.0.0.1', 'localhost', '::1'])

export function isLocalCustomerCarePreview() {
  if (typeof window === 'undefined') return false
  const params = new URLSearchParams(window.location.search)
  return import.meta.env.DEV
    && allowedPreviewHosts.has(window.location.hostname)
    && params.get('preview') === '1'
}

export function cloneCustomerCarePreview(value) {
  return JSON.parse(JSON.stringify(value))
}

const taskActions = {
  unstarted: [
    { code: 'start-care-task', label: '开始跟进', tone: 'primary', enabled: true },
    { code: 'delete-care-task', label: '删除任务', tone: 'danger', enabled: true }
  ],
  inProgress: [
    { code: 'complete-care-task', label: '完成跟进', tone: 'primary', enabled: true },
    { code: 'void-care-task', label: '作废任务', tone: 'danger', enabled: true }
  ],
  managerOnly: [
    { code: 'reassign-care-task', label: '转派', tone: 'secondary', enabled: true }
  ]
}

const tasks = [
  {
    taskId: 'CARE-TASK-20260729-001',
    taskNo: 'GJ2607290001',
    taskVersion: 4,
    member: { memberId: 'member-10087', name: '林女士', phone: '138 0721 6688', level: '金卡', tags: ['重点维护', '面部护理'] },
    plannedAt: '2026-07-29 09:00',
    typeCode: 'SERVICE_FEEDBACK',
    typeLabel: '服务后回访',
    sourceCode: 'SERVICE_COMPLETED',
    sourceLabel: '服务后自动产生',
    status: 'IN_PROGRESS',
    statusLabel: '进行中',
    isOverdue: true,
    bucket: 'overdue',
    listScopes: ['my', 'all'],
    owner: { employeeId: 'employee-21', name: '李美容师' },
    store: { storeId: 'store-1', name: '瑞昊一店' },
    relatedBusiness: { type: 'service', label: '服务单 FW202607280018', summary: '深层清洁护理、舒缓修护' },
    latestCare: { followedAt: '2026-07-22 16:40', methodLabel: '微信', resultLabel: '恢复良好', content: '客户反馈护理后没有泛红，建议一周后复查。' },
    availableActions: taskActions.inProgress,
    appointmentCapability: { enabled: false, disabledReason: '预约功能与客情结果保持独立。' }
  },
  {
    taskId: 'CARE-TASK-20260729-002',
    taskNo: 'GJ2607290002',
    taskVersion: 2,
    member: { memberId: 'member-10216', name: '周女士', phone: '186 1120 3921', level: '银卡', tags: ['身体护理'] },
    plannedAt: '2026-07-29 15:00',
    typeCode: 'DAILY_FOLLOWUP',
    typeLabel: '日常跟进',
    sourceCode: 'MANUAL',
    sourceLabel: '手工创建',
    status: 'UNSTARTED',
    statusLabel: '未开始',
    isOverdue: false,
    bucket: 'today',
    listScopes: ['my', 'all'],
    owner: { employeeId: 'employee-21', name: '李美容师' },
    store: { storeId: 'store-1', name: '瑞昊一店' },
    relatedBusiness: { type: 'member', label: '会员日常维护', summary: '了解肩颈项目体验意向' },
    latestCare: { followedAt: '2026-07-16 11:20', methodLabel: '电话', resultLabel: '有意向', content: '客户月底回店，届时再确认时间。' },
    availableActions: taskActions.unstarted,
    appointmentCapability: { enabled: false, disabledReason: '预约功能与客情结果保持独立。' }
  },
  {
    taskId: 'CARE-TASK-20260730-003',
    taskNo: 'GJ2607300001',
    taskVersion: 1,
    member: { memberId: 'member-10631', name: '陈女士', phone: '139 6630 8127', level: '普通会员', tags: ['新客'] },
    plannedAt: '2026-07-30 10:30',
    typeCode: 'INVITATION',
    typeLabel: '邀约',
    sourceCode: 'PREVIOUS_FOLLOWUP',
    sourceLabel: '上次跟进生成',
    status: 'UNSTARTED',
    statusLabel: '未开始',
    isOverdue: false,
    bucket: 'future',
    listScopes: ['my', 'all'],
    owner: { employeeId: 'employee-21', name: '李美容师' },
    store: { storeId: 'store-1', name: '瑞昊一店' },
    relatedBusiness: { type: 'record', label: '上次跟进记录 GJ202607260031', summary: '客户希望工作日上午到店' },
    latestCare: { followedAt: '2026-07-26 18:10', methodLabel: '微信', resultLabel: '待确认时间', content: '已介绍体验项目，客户需要确认工作安排。' },
    availableActions: taskActions.unstarted,
    appointmentCapability: { enabled: false, disabledReason: '预约功能与客情结果保持独立。' }
  },
  {
    taskId: 'CARE-TASK-20260728-004',
    taskNo: 'GJ2607280001',
    taskVersion: 5,
    member: { memberId: 'member-10772', name: '王女士', phone: '137 9082 4611', level: '金卡', tags: ['长期会员'] },
    plannedAt: '2026-07-28 14:00',
    completedAt: '2026-07-28 14:26',
    typeCode: 'SERVICE_FEEDBACK',
    typeLabel: '服务后回访',
    sourceCode: 'SERVICE_COMPLETED',
    sourceLabel: '服务后自动产生',
    status: 'COMPLETED',
    statusLabel: '已完成',
    isOverdue: false,
    bucket: 'completed',
    listScopes: ['my', 'all'],
    owner: { employeeId: 'employee-21', name: '李美容师' },
    actualFollower: { employeeId: 'employee-21', name: '李美容师' },
    store: { storeId: 'store-1', name: '瑞昊一店' },
    relatedBusiness: { type: 'service', label: '服务单 FW202607270009', summary: '舒缓修护' },
    latestCare: { followedAt: '2026-07-28 14:26', methodLabel: '电话', resultLabel: '满意', content: '客户反馈皮肤状态稳定，已告知居家护理注意事项。' },
    availableActions: [],
    appointmentCapability: { enabled: false, disabledReason: '已完成任务不再办理预约关联。' }
  },
  {
    taskId: 'CARE-TASK-20260729-005',
    taskNo: 'GJ2607290003',
    taskVersion: 3,
    member: { memberId: 'member-10905', name: '赵女士', phone: '158 3290 7751', level: '银卡', tags: ['疗程中'] },
    plannedAt: '2026-07-29 08:30',
    typeCode: 'DAILY_FOLLOWUP',
    typeLabel: '日常跟进',
    sourceCode: 'MANUAL',
    sourceLabel: '手工创建',
    status: 'UNSTARTED',
    statusLabel: '未开始',
    isOverdue: true,
    bucket: 'overdue',
    listScopes: ['all'],
    owner: { employeeId: 'employee-35', name: '陈顾问' },
    store: { storeId: 'store-1', name: '瑞昊一店' },
    relatedBusiness: { type: 'member', label: '疗程维护', summary: '跟进客户近期到店安排' },
    latestCare: { followedAt: '2026-07-20 09:50', methodLabel: '到店沟通', resultLabel: '继续疗程', content: '客户计划下周继续疗程。' },
    availableActions: taskActions.managerOnly,
    appointmentCapability: { enabled: false, disabledReason: '预约服务尚未接入当前客情版本。' }
  }
]

const records = [
  { recordId: 'CARE-RECORD-001', recordNo: 'KQ2607280001', recordVersion: 2, stream: 'human', typeLabel: '服务后反馈', followedAt: '2026-07-28 14:26', methodLabel: '电话', memberName: '王女士', content: '客户反馈皮肤状态稳定，已告知居家护理注意事项。', resultLabel: '满意', actualFollowerName: '李美容师', creatorName: '李美容师', storeName: '瑞昊一店', status: 'NORMAL', statusLabel: '正常', availableActions: [{ code: 'void-care-record', label: '作废', tone: 'danger', enabled: true }] },
  { recordId: 'CARE-RECORD-002', recordNo: 'KQ2607260001', recordVersion: 1, stream: 'human', typeLabel: '日常跟进', followedAt: '2026-07-26 18:10', methodLabel: '微信', memberName: '陈女士', content: '已介绍体验项目，客户需要确认工作安排。', resultLabel: '待确认时间', actualFollowerName: '李美容师', creatorName: '李美容师', storeName: '瑞昊一店', status: 'NORMAL', statusLabel: '正常', availableActions: [{ code: 'void-care-record', label: '作废', tone: 'danger', enabled: true }] },
  { recordId: 'CARE-RECORD-003', recordNo: 'KQ2607180001', recordVersion: 3, stream: 'human', typeLabel: '回访', followedAt: '2026-07-18 10:15', methodLabel: '电话', memberName: '林女士', content: '原记录的会员反馈对象录入错误。', resultLabel: '已更正', actualFollowerName: '李美容师', creatorName: '李美容师', storeName: '瑞昊一店', status: 'VOIDED', statusLabel: '已作废', voidReason: '会员反馈对象录入错误', availableActions: [] },
  { recordId: 'APPOINTMENT-DYNAMIC-001', stream: 'appointment', typeLabel: '预约动态', occurredAt: '2026-07-27 17:36', memberName: '王女士', appointmentNo: 'YY202607270014', appointmentTime: '2026-07-30 14:00', projectSummary: '舒缓修护', storeName: '瑞昊一店', appointmentStatus: '已确认', availableActions: [{ code: 'open-reservation-detail', label: '查看预约摘要', tone: 'text', enabled: true }] }
]

export function createCustomerCarePreviewProjection() {
  return cloneCustomerCarePreview({
    contractVersion: CUSTOMER_CARE_CONTRACT_VERSION,
    dataAsOf: '2026-07-29 08:42:00',
    currentStore: { storeId: 'store-1', name: '瑞昊一店' },
    currentEmployee: { employeeId: 'employee-21', name: '李美容师' },
    permissions: {
      canViewAllTasks: true,
      canCreateTask: true,
      canCreateRecord: true,
      canViewStatistics: true,
      canManageRules: true,
      canHandleExceptions: true
    },
    taskView: {
      records: tasks,
      total: tasks.length,
      bucketCounts: { today: 1, overdue: 2, future: 1, completed: 1, all: 5 },
      statusOptions: [
        { value: '', label: '全部状态' },
        { value: 'UNSTARTED', label: '未开始' },
        { value: 'IN_PROGRESS', label: '进行中' },
        { value: 'COMPLETED', label: '已完成' },
        { value: 'VOIDED', label: '已作废' }
      ]
    },
    customerView: {
      records: [
        { memberId: 'member-10087', name: '林女士', phone: '138 0721 6688', storeName: '瑞昊一店', exclusiveServiceName: '李美容师', latestService: '2026-07-28 深层清洁护理', latestCare: '2026-07-22 微信 · 恢复良好', nextTask: { taskId: 'CARE-TASK-20260729-001', taskNo: 'GJ2607290001', label: '今天 09:00 服务后回访', canOpen: true }, overdueCount: 1 },
        { memberId: 'member-10216', name: '周女士', phone: '186 1120 3921', storeName: '瑞昊一店', exclusiveServiceName: '陈顾问', latestService: '2026-07-15 肩颈舒缓', latestCare: '2026-07-16 电话 · 有意向', nextTask: { taskId: 'CARE-TASK-20260729-002', taskNo: 'GJ2607290002', label: '今天 15:00 日常跟进', canOpen: true }, overdueCount: 0 },
        { memberId: 'member-10631', name: '陈女士', phone: '139 6630 8127', storeName: '瑞昊一店', exclusiveServiceName: '待分配', latestService: '暂无服务记录', latestCare: '2026-07-26 微信 · 待确认时间', nextTask: { taskId: 'CARE-TASK-20260730-003', taskNo: 'GJ2607300001', label: '明天 10:30 邀约', canOpen: true }, overdueCount: 0 }
      ]
    },
    recordView: { records, total: records.length },
    statistics: {
      metricVersion: 'care-metrics.pending-product-copy.v1',
      aggregationCaughtUp: true,
      metrics: [
        { code: 'care_open_workload', label: '当前待跟进', value: 28, unit: '项', userReady: false, description: '口径说明待产品确认' },
        { code: 'care_completed', label: '已完成', value: 21, unit: '项', userReady: false, description: '口径说明待产品确认' },
        { code: 'care_overdue_current', label: '当前逾期', value: 4, unit: '项', userReady: false, description: '口径说明待产品确认' },
        { code: 'care_activity_records', label: '实际跟进记录', value: 24, unit: '条', userReady: false, description: '口径说明待产品确认' }
      ],
      employeeRows: [
        { employeeId: 'employee-21', name: '李美容师', currentOpenWorkload: 7, completedByActualFollower: 12, currentOverdue: 1 },
        { employeeId: 'employee-35', name: '陈顾问', currentOpenWorkload: 9, completedByActualFollower: 6, currentOverdue: 2 },
        { employeeId: 'employee-42', name: '周美容师', currentOpenWorkload: 5, completedByActualFollower: 3, currentOverdue: 1 }
      ]
    },
    settings: {
      rules: [
        { ruleId: 'CARE-RULE-001', ruleVersion: 3, projectName: '深层清洁护理', storeName: '瑞昊一店', enabled: true, delayLabel: '完成服务后 1 天', ownerRuleLabel: '本次服务主要手艺人', statusLabel: '启用', availableActions: [{ code: 'save-care-followup-rule', label: '编辑规则', tone: 'text', enabled: true }] },
        { ruleId: 'CARE-RULE-002', ruleVersion: 1, projectName: '舒缓修护', storeName: '瑞昊一店', enabled: true, delayLabel: '完成服务后 2 天', ownerRuleLabel: '会员专属服务人', statusLabel: '启用', availableActions: [{ code: 'save-care-followup-rule', label: '编辑规则', tone: 'text', enabled: true }] }
      ],
      exceptions: [
        { exceptionId: 'CARE-EXCEPTION-001', exceptionVersion: 2, occurredAt: '2026-07-28 19:42', memberName: '刘女士', sourceLabel: '服务单 FW202607280026', reason: '会员没有专属服务人，且本次主要手艺人任职已停用。', statusLabel: '待处理', availableActions: [{ code: 'retry-care-auto-exception', label: '重新处理', tone: 'secondary', enabled: true }] }
      ]
    },
    preparations: {
      completion: {
        methodOptions: [{ value: 'PHONE', label: '电话' }, { value: 'WECHAT', label: '微信' }, { value: 'IN_STORE', label: '到店沟通' }, { value: 'OTHER', label: '其他' }],
        resultOptions: [{ value: 'SATISFIED', label: '满意' }, { value: 'INTENTIONAL', label: '有意向' }, { value: 'FOLLOW_UP', label: '需要继续跟进' }, { value: 'APPOINTMENT_SUCCESS', label: '预约成功' }],
        assigneeOptions: [{ value: 'employee-21', label: '李美容师', storeId: 'store-1', enabled: true }, { value: 'employee-35', label: '陈顾问', storeId: 'store-1', enabled: true }]
      },
      reassignment: {
        assigneeOptions: [{ value: 'employee-21', label: '李美容师', storeId: 'store-1', enabled: true }, { value: 'employee-35', label: '陈顾问', storeId: 'store-1', enabled: true }, { value: 'employee-42', label: '周美容师', storeId: 'store-1', enabled: true }]
      }
    }
  })
}

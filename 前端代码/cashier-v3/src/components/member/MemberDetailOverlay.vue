<script setup>
import { computed, ref, watch } from 'vue'
import Info from '@lucide/vue/dist/esm/icons/info.mjs'
import { formatMoney } from '@/services/cashierV3Bridge'

/**
 * 会员详情全页面前端壳。
 *
 * detail 是后端按当前账号数据权限组装的会员详情快照。本组件只展示后端已返回的
 * 资料、资产、记录和可执行动作：不在浏览器计算余额、到店次数、卡项次数、金额、
 * 业绩或业务状态，也不会自行改写会员、卡项、赠送、订单或预约。
 *
 * 会员详情页签包含业务记录与会员档案；档案页只展示后端返回的创建资料快照。
 * 各页签由调用方按需独立加载；该组件只消费后端已按数据权限裁剪的快照，不在浏览器
 * 汇总金额、权益、到店次数、状态或权限。
 *
 * 推荐的 detail 契约：
 * {
 *   member: {
 *     id, name, phone, memberNo, statusLabel, storeName, exclusiveServiceStaff,
 *     accountBalance, currentPoints, cardBenefitAmount, remainingProjectTimes,
 *     remainingProjectAmount, activeCardCount, totalConsumptionAmount,
 *     visitCount, latestPurchaseDate, latestVisitDate
 *   },
 *   profile: { basicFields, customFields, relation, latestVisit, careReminder },
 *   cards: [{ cardNo, cardName, statusLabel, storeName, openedAt, expiresAt,
 *     remainingTimes, remainingAmount, purchaseBatchNo,
 *     projects: [{ projectName, purchaseTimes, usedTimes, remainingTimes, remainingAmount }],
 *     actions }],
 *   balanceChanges: [{ changeNo, businessDate, typeLabel, changeAmount, balanceAfter,
 *     sourceNo, operatorName, remark, actions }],
 *   coupons: [{ couponNo, couponName, couponTypeLabel, couponAmount, effectiveAt,
 *     expiresAt, statusLabel, actions }],
 *   pointChanges: [{ changeNo, businessDate, typeLabel, changeAmount, pointsAfter,
 *     sourceNo, operatorName, remark, actions }],
 *   writeoffRecords: [{ serviceNo, businessDate, projectName, sourceLabel, cardName,
 *     cardNo, usedTimes, storeName, craftsmenSummary, laborPerformanceAmount,
 *     operatorName, statusLabel, isSupplement, actions }],
 *   salesOrders: [{ orderNo, businessDate, orderTypeLabel, payableAmount,
 *     actualReceivedAmount, statusLabel, completedAt, actions }],
 *   giftRecords: [{ giftNo, businessDate, sourceType, sourceOrderNo, sourceAction,
 *     storeName, operatorName, reason, createdAt,
 *     contents: [{ typeLabel, name, quantity, effectiveAt, expiresAt, statusLabel,
 *       couponTypeLabel, couponAmount, couponAction }], actions }],
 *   appointmentHighlights: [{ reservationNo, statusLabel, startAt, endAt, roomName,
 *     projectSummary, plannedCraftsmenSummary, actions }],
 *   careTasks: [{ taskTypeLabel, scheduledAt, ownerName, sourceLabel, relatedBusiness,
 *     statusLabel, isOverdue, creatorName, createdAt, completedByName, completedAt,
 *     resultSummary, actions }],
 *   careRecords: [{ typeLabel, followedAt, channelLabel, content, resultLabel,
 *     actualFollowerName, creatorName, createdAt, storeName, relatedBusiness,
 *     nextFollowUpAt, nextTaskOwnerName, statusLabel, actions }],
 *   exclusiveServiceStaffChanges: [{ previousStaffName, currentStaffName, reason,
 *     operatorName, occurredAt }],
 *   debtRecords: [{ debtNo, businessDate, sourceLabel, sourceOrderNo, summary,
 *     payableAmount, originallyReceivedAmount, originalDebtAmount, repaidAmount,
 *     remainingDebtAmount, cardWriteoffLimit, statusLabel, storeName, operatorName,
 *     latestRepaymentAt, actions }],
 *   tabStates: { profile: { isLoading }, ... },
 *   actions / availableActions: [{ code, label, disabled, disabledReason }]
 * }
 *
 * onAction 接收 { action, actionCode, member, memberId, activeTab, scope, record,
 * content }。调用方负责向后端请求、处理版本冲突、刷新 detail 及页面跳转。
 * 可通过 @tab-change 按需加载当前页签；本组件不会在切换标签时自行发出业务请求。
 */
const props = defineProps({
  detail: {
    type: Object,
    default: () => ({})
  },
  isLoading: {
    type: Boolean,
    default: false
  },
  onAction: {
    type: Function,
    default: null
  },
  initialTab: {
    type: String,
    default: 'profile'
  }
})

const emit = defineEmits(['close', 'tab-change'])

const DEFAULT_TABS = [
  { key: 'profile', label: '会员概况' },
  { key: 'assets', label: '权益明细' },
  { key: 'card-operations', label: '卡操作记录' },
  { key: 'balance-changes', label: '余额变动明细' },
  { key: 'sales', label: '销售订单' },
  { key: 'writeoff', label: '服务记录' },
  { key: 'care', label: '客情管理' },
  { key: 'debt', label: '欠款记录' },
  { key: 'gift', label: '赠送记录' },
  { key: 'points', label: '积分变动记录' },
  { key: 'archive', label: '会员档案' },
]

const ACTION_LABELS = Object.freeze({
  'open-sales-order-personnel-adjustment': '人员调整',
  'adjust-sales-order-personnel': '确认人员调整',
  'reopen-sales-order': '重开订单',
  'open-order-debt-settlements': '查看欠款',
  'refund-sales-order': '发起退款',
  'void-sales-order': '作废订单',
  'open-sales-order-detail': '查看详情',
  'open-debt-settlements': '补交',
  'open-writeoff-records': '查看服务记录',
  'open-operation-logs': '查看操作记录',
  'open-gift-records': '查看赠送记录'
})

const activeTab = ref(DEFAULT_TABS.some((tab) => tab.key === props.initialTab) ? props.initialTab : 'profile')
const activeActionKey = ref('')
const actionError = ref('')
const tabKeyword = ref('')
const todayDate = () => {
  const now = new Date()
  const year = now.getFullYear()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}
const tabDateFrom = ref(todayDate())
const tabDateTo = ref(todayDate())
const tabStatus = ref('')
const metricTooltip = ref({ visible: false, text: '', left: 0, top: 0 })

const detail = computed(() => (props.detail && typeof props.detail === 'object' ? props.detail : {}))
const member = computed(() => firstObject(detail.value, ['member', 'memberInfo', 'memberProfile', 'profileInfo']))
const memberId = computed(() => firstValueFrom([member.value, detail.value], ['id', 'memberId', 'uid', 'userId']))
const memberName = computed(() => text(firstValueFrom([member.value, detail.value], ['name', 'memberName', 'realName']), '会员'))
const memberStatus = computed(() => displayStatus(firstValueFrom([member.value, detail.value], ['statusLabel', 'statusName', 'status'])))
const hasDetail = computed(() => Object.keys(detail.value).length > 0)

const summary = computed(() => firstObject(detail.value, ['summary', 'assetSummary', 'memberSummary', 'metrics']))
const profile = computed(() => firstObject(detail.value, ['profile', 'memberProfile', 'profileInfo']))
const relation = computed(() => firstObjectFrom([profile.value, detail.value], ['relation', 'memberRelation', 'relationship']))
const visitHighlights = computed(() => {
  const profileRecords = readList(profile.value, ['visitHighlights', 'recentVisits'])
  if (profileRecords.length) return profileRecords
  return readList(detail.value, ['visitHighlights', 'recentVisits', 'visits', 'visitRecords', 'arrivalRecords'])
})
const latestVisit = computed(() => {
  const explicit = firstObjectFrom([profile.value, detail.value], ['latestVisit', 'recentVisit'])
  return Object.keys(explicit).length ? explicit : (visitHighlights.value[0] || {})
})
const careReminder = computed(() => firstObjectFrom([profile.value, detail.value], ['careReminder', 'customerCare', 'reminder']))

const cards = computed(() => readList(detail.value, ['cards', 'cardBenefits', 'memberCards', 'cardItems']))

function isTimeCard(record = {}) {
  return String(record.cardRuleType || '').trim() === 'time'
    || String(record.sourceKind || '').trim() === 'time_card'
    || record.unlimited === true
    || record.isTimeCard === true
}

function cardRemainingTimesDisplay(card = {}) {
  return isTimeCard(card) ? '—' : numberText(firstValue(card, ['remainingTimes', 'leftTimes']), ' 次')
}

function cardProjectTimesDisplay(card = {}, project = {}, keys = []) {
  return isTimeCard(card) ? '—' : numberText(firstValue(project, keys), ' 次')
}

function cardProjectAmountDisplay(card = {}, project = {}) {
  return isTimeCard(card) ? '—' : money(firstValue(project, ['remainingAmount', 'leftAmount']))
}

const cardOperations = computed(() => readList(detail.value, ['cardOperations', 'cardOperationRecords', 'cardOperationHistory']))
const balanceChanges = computed(() => readList(detail.value, ['balanceChanges', 'balanceChangeRecords', 'balanceHistory']))
const coupons = computed(() => readList(detail.value, ['coupons', 'couponRecords', 'memberCoupons']))
const pointChanges = computed(() => readList(detail.value, ['pointChanges', 'pointChangeRecords', 'pointHistory']))
const writeoffRecords = computed(() => readList(detail.value, ['writeoffRecords', 'serviceRecords', 'writeoffs']))
const salesOrders = computed(() => readList(detail.value, ['salesOrders', 'saleOrders', 'orders']))
const giftRecords = computed(() => readList(detail.value, ['giftRecords', 'gifts', 'giftHistory']))
const careTasks = computed(() => readList(detail.value, ['careTasks', 'customerCareTasks', 'followUpTasks']))
const careRecords = computed(() => readList(detail.value, ['careRecords', 'customerCareRecords', 'careTimeline', 'followUpRecords']))
const exclusiveServiceStaffChanges = computed(() => readList(detail.value, ['exclusiveServiceStaffChanges', 'serviceStaffChanges', 'exclusiveStaffHistory']))
const debtRecords = computed(() => readList(detail.value, ['debtRecords', 'debts', 'memberDebts']))

const availableActions = computed(() => normalizeActionList(readList(detail.value, ['actions', 'availableActions', 'actionList']), 'member'))

const tabs = DEFAULT_TABS
const isSearchableTab = computed(() => ['assets', 'card-operations', 'sales', 'writeoff', 'care', 'debt', 'gift', 'points'].includes(activeTab.value))
const tabKeywordPlaceholder = computed(() => {
  if (activeTab.value === 'writeoff') return '输入手艺人或项目后查询'
  if (activeTab.value === 'sales') return '输入商品名称或销售人后查询'
  if (activeTab.value === 'gift') return '输入赠送内容或来源后查询'
  return '输入关键词后查询'
})
const hasStatusFilter = computed(() => ['assets', 'gift', 'care'].includes(activeTab.value))
const showDateRange = computed(() => !['assets', 'care'].includes(activeTab.value) || tabStatus.value === '')

watch(
  () => props.initialTab,
  (tabKey) => {
    if (DEFAULT_TABS.some((tab) => tab.key === tabKey)) activeTab.value = tabKey
  }
)

const headerFacts = computed(() => [
  { label: '完整手机号', value: firstValueFrom([member.value, detail.value], ['phone', 'mobile', 'memberPhone']) },
  { label: '会员编号', value: firstValueFrom([member.value, detail.value], ['memberNo', 'memberCode', 'code']) },
  { label: '归属门店', value: firstValueFrom([member.value, detail.value], ['storeName', 'homeStoreName', 'belongStoreName']) },
  { label: '专属服务人', value: firstValueFrom([member.value, detail.value], ['exclusiveServiceStaff', 'exclusiveStaffName', 'serviceConsultantName']) }
])

const headerStats = computed(() => [
  metric('账户余额', summaryValue(['accountBalance', 'totalBalance', 'balance']), 'money'),
  metric('次卡权益金额', summaryValue(['cardBenefitAmount', 'cardBenefitValue']), 'money'),
  { ...metric('剩余项目次数', summaryValue(['remainingProjectTimes', 'remainingTimes']), 'times'), tooltip: '剩余项目次数不计算时间卡的次数' },
  metric('剩余项目金额', summaryValue(['remainingProjectAmount', 'remainingAmount']), 'money'),
  { label: '有效卡数量', value: numberText(summaryValue(['activeCardCount', 'validCardCount']), ' 张') },
  metric('总消费金额', summaryValue(['totalConsumptionAmount', 'totalPurchaseAmount']), 'money'),
  { label: '到店次数', value: numberText(summaryValue(['visitCount']), ' 次') },
  metric('当前积分', summaryValue(['currentPoints', 'points', 'pointBalance']), 'number')
])

const basicRows = computed(() => compactRows([
  { label: '会员等级', value: profileValue(['memberLevel', 'levelName', 'level']) },
  { label: '性别', value: profileValue(['genderLabel', 'gender']) },
  { label: '生日', value: profileValue(['birthday', 'birthDate']) },
  { label: '微信号', value: profileValue(['wechat', 'wechatNo', 'weChat']) },
  { label: '会员标签', value: listText(profileValue(['tags', 'memberTags', 'tagNames'])) },
  { label: '建档时间', value: profileValue(['createdAt', 'createdTime', 'registeredAt']) },
  { label: '地址', value: profileValue(['address', 'fullAddress']) },
  { label: '备注', value: profileValue(['remark', 'note', 'memo']) }
]))

const customFields = computed(() => readList(profile.value, ['customFields', 'fields', 'customProfileFields'])
  .map((field, index) => ({
    key: firstValue(field, ['key', 'id', 'fieldKey', 'name']) || `custom-field-${index}`,
    label: firstValue(field, ['label', 'fieldLabel', 'name', 'title']) || '自定义字段',
    value: listText(firstValue(field, ['displayValue', 'value', 'content']))
  })))

const archiveRows = computed(() => [
  { label: '会员姓名', value: memberName.value },
  { label: '完整手机号', value: firstValueFrom([member.value, detail.value], ['phone', 'mobile', 'memberPhone']) },
  { label: '会员编号', value: firstValueFrom([member.value, detail.value], ['memberNo', 'memberCode', 'code']) },
  { label: '会员状态', value: memberStatus.value },
  { label: '性别', value: profileValue(['genderLabel', 'gender']) },
  { label: '生日', value: profileValue(['birthday', 'birthDate']) },
  { label: '身份证', value: profileValue(['idCard', 'cardId', 'id_card']) },
  { label: '地址', value: profileValue(['address', 'fullAddress']) },
  { label: '会员等级', value: profileValue(['memberLevel', 'levelName', 'level']) || '普通会员' },
  { label: '会员标签', value: listText(profileValue(['tags', 'memberTags', 'tagNames'])) },
  { label: '备注', value: profileValue(['remark', 'note', 'memo']) },
  { label: '建档时间', value: profileValue(['createdAt', 'createdTime', 'registeredAt']) },
  { label: '归属门店', value: firstValueFrom([relation.value, member.value, detail.value], ['storeName', 'homeStoreName', 'belongStoreName']) },
  { label: '专属服务人', value: firstValueFrom([relation.value, member.value, detail.value], ['exclusiveServiceStaff', 'exclusiveStaffName', 'serviceConsultantName']) }
])

const relationRows = computed(() => compactRows([
  { label: '归属门店', value: firstValueFrom([relation.value, member.value, detail.value], ['storeName', 'homeStoreName', 'belongStoreName']) },
  { label: '专属服务人', value: firstValueFrom([relation.value, member.value, detail.value], ['exclusiveServiceStaff', 'exclusiveStaffName', 'serviceConsultantName']) },
  { label: '当前管理人', value: firstValueFrom([relation.value, careReminder.value], ['managerName', 'ownerName', 'currentManagerName']) },
  { label: '关系更新时间', value: firstValue(relation.value, ['updatedAt', 'changedAt', 'effectiveAt']) }
]))

const latestVisitRows = computed(() => compactRows([
  { label: '到店时间', value: firstValueFrom([latestVisit.value, detail.value], ['visitedAt', 'visitAt', 'latestVisitDate', 'businessDate']) },
  { label: '到店门店', value: firstValue(latestVisit.value, ['storeName', 'serviceStoreName']) },
  { label: '服务项目', value: firstValue(latestVisit.value, ['projectSummary', 'projectName', 'serviceProjectName']) },
  { label: '服务人员', value: firstValue(latestVisit.value, ['craftsmenSummary', 'staffSummary', 'serviceStaffName']) }
]))

const careReminderRows = computed(() => compactRows([
  { label: '最近回访', value: firstValue(careReminder.value, ['latestFollowUpAt', 'lastFollowUpAt', 'recentFollowUp']) },
  { label: '下次提醒', value: firstValue(careReminder.value, ['nextReminderAt', 'nextFollowUpAt', 'nextTaskAt']) },
  { label: '当前管理人', value: firstValue(careReminder.value, ['managerName', 'ownerName', 'currentManagerName']) },
  { label: '提醒内容', value: firstValue(careReminder.value, ['content', 'remark', 'note', 'summary']) }
]))

function hasValue(value) {
  return value !== undefined && value !== null && value !== ''
}

function firstValue(source, keys) {
  if (!source || typeof source !== 'object') return undefined
  for (const key of keys) {
    if (hasValue(source[key])) return source[key]
  }
  return undefined
}

function firstRawValue(source, keys) {
  if (!source || typeof source !== 'object') return undefined
  for (const key of keys) {
    if (source[key] !== undefined && source[key] !== null) return source[key]
  }
  return undefined
}

function firstValueFrom(sources, keys) {
  for (const source of sources) {
    const value = firstValue(source, keys)
    if (hasValue(value)) return value
  }
  return undefined
}

function firstObject(source, keys) {
  if (!source || typeof source !== 'object') return {}
  for (const key of keys) {
    const value = source[key]
    if (value && typeof value === 'object' && !Array.isArray(value)) return value
  }
  return {}
}

function firstObjectFrom(sources, keys) {
  for (const source of sources) {
    const value = firstObject(source, keys)
    if (Object.keys(value).length) return value
  }
  return {}
}

function readList(source, keys) {
  if (!source || typeof source !== 'object') return []
  for (const key of keys) {
    const value = source[key]
    if (Array.isArray(value)) return value
    if (value && typeof value === 'object') return [value]
  }
  return []
}

function text(value, fallback = '—') {
  return hasValue(value) ? String(value) : fallback
}

function listText(value, fallback = '—') {
  if (Array.isArray(value)) {
    const values = value.map((item) => {
      if (item && typeof item === 'object') return firstValue(item, ['label', 'name', 'value', 'title'])
      return item
    }).filter(hasValue)
    return values.length ? values.join('、') : fallback
  }
  return text(value, fallback)
}

function money(value) {
  return hasValue(value) ? formatMoney(value) : '—'
}

function numberText(value, suffix = '') {
  return hasValue(value) ? `${value}${suffix}` : '—'
}

function metric(label, value, type) {
  if (type === 'money') return { label, value: money(value) }
  if (type === 'times') return { label, value: numberText(value, ' 次') }
  if (type === 'count') return { label, value: numberText(value, ' 张') }
  if (type === 'number') return { label, value: numberText(value) }
  return { label, value: text(value) }
}

function summaryValue(keys) {
  return firstValueFrom([summary.value, member.value, detail.value], keys)
}

function profileValue(keys) {
  return firstValueFrom([profile.value, member.value, detail.value], keys)
}

function compactRows(rows) {
  return rows.filter((row) => hasValue(row.value))
}

function displayStatus(value) {
  const raw = text(value)
  return raw === '已撤销' || raw === '撤销' ? '已作废' : raw
}

function tabIsLoading(tabKey) {
  const states = firstObject(detail.value, ['tabStates', 'tabsState', 'tabLoading'])
  const state = states?.[tabKey]
  return state === true || state?.isLoading === true || state?.loading === true
}

function switchTab(tab) {
  if (!tab || tab.key === activeTab.value) return
  activeTab.value = tab.key
  actionError.value = ''
  tabKeyword.value = ''
  tabDateFrom.value = todayDate()
  tabDateTo.value = todayDate()
  tabStatus.value = tab.key === 'assets' || tab.key === 'gift'
    ? 'active'
    : (tab.key === 'care' ? 'unfinished' : '')
  emit('tab-change', {
    tab: tab.key,
    memberId: memberId.value,
    member: member.value,
    keyword: '',
    status: tabStatus.value,
    dateFrom: tabDateFrom.value,
    dateTo: tabDateTo.value
  })
}

function queryActiveTab() {
  if (!isSearchableTab.value) return
  emit('tab-change', {
    tab: activeTab.value,
    memberId: memberId.value,
    member: member.value,
    keyword: tabKeyword.value.trim(),
    status: hasStatusFilter.value ? tabStatus.value : '',
    dateFrom: tabDateFrom.value,
    dateTo: tabDateTo.value
  })
}

function switchTabByKey(tabKey) {
  switchTab(tabs.find((tab) => tab.key === tabKey))
}

function showMetricTooltip(event, item) {
  if (!item?.tooltip) return
  const rect = event.currentTarget.getBoundingClientRect()
  metricTooltip.value = {
    visible: true,
    text: item.tooltip,
    left: Math.min(Math.max(rect.left + (rect.width / 2), 150), window.innerWidth - 150),
    top: rect.bottom + 8
  }
}

function hideMetricTooltip() {
  metricTooltip.value = { ...metricTooltip.value, visible: false }
}

function requestClose() {
  if (activeActionKey.value) return
  emit('close')
}

function actionSourceList(source) {
  return readList(source, ['actions', 'availableActions', 'actionList'])
}

function normalizeAction(raw, scope, index = 0) {
  if (!raw) return null
  const source = typeof raw === 'string' ? { code: raw, label: raw } : raw
  if (!source || typeof source !== 'object') return null

  const code = String(firstValue(source, ['code', 'actionCode', 'action', 'key']) || firstValue(source, ['label', 'name', 'title']) || '').trim()
  const suppliedLabel = String(firstValue(source, ['label', 'name', 'title']) || '').trim()
  const label = suppliedLabel && suppliedLabel !== code ? suppliedLabel : (ACTION_LABELS[code] || code)
  if (!code || !label || source.visible === false) return null

  return {
    raw: source,
    code,
    label,
    key: `${scope}-${code}-${index}`,
    disabled: source.disabled === true || source.enabled === false,
    disabledReason: firstValue(source, ['disabledReason', 'reason', 'hint'])
  }
}

function normalizeActionList(rawActions, scope) {
  const seen = new Set()
  return rawActions
    .map((raw, index) => normalizeAction(raw, scope, index))
    .filter(Boolean)
    .filter((action) => {
      const uniqueKey = `${action.code}-${action.label}`
      if (seen.has(uniqueKey)) return false
      seen.add(uniqueKey)
      return true
    })
}

function recordActions(record, scope) {
  return normalizeActionList(actionSourceList(record), scope)
}

function recordKey(record, prefix, index) {
  return firstValue(record, ['id', 'recordId', 'cardId', 'orderId', 'giftId', 'reservationId', 'visitId', 'no', 'code']) || `${prefix}-${index}`
}

function cardProjects(card) {
  return readList(card, ['projects', 'cardProjects', 'benefits', 'projectItems'])
}

function cardName(card) {
  return text(firstValue(card, ['cardName', 'name', 'productName', 'title']), '未命名卡项')
}

function cardNo(card) {
  return text(firstValue(card, ['cardNo', 'cardNumber', 'no', 'code']))
}

function cardProjectName(project) {
  return text(firstValue(project, ['projectName', 'name', 'productName', 'title']))
}

function balanceChangeNo(record) {
  return text(firstValue(record, ['changeNo', 'recordNo', 'balanceNo', 'no', 'code']))
}

function cardOperationNo(record) {
  return text(firstValue(record, ['operationNo', 'recordNo', 'no', 'code']))
}

function balanceChangeType(record) {
  return text(firstValue(record, ['typeLabel', 'changeTypeLabel', 'businessTypeLabel', 'type', 'changeType']))
}

function balanceChangeAmount(record) {
  return money(firstValue(record, ['changeAmount', 'amount', 'totalAmount', 'paidAmount']))
}

function balanceAfter(record) {
  return money(firstValue(record, ['balanceAfter', 'availableBalanceAfter', 'totalBalanceAfter']))
}

function couponNo(record) {
  return text(firstValue(record, ['couponNo', 'couponCode', 'no', 'code']))
}

function couponName(record) {
  return text(firstValue(record, ['couponName', 'name', 'title']))
}

function couponType(record) {
  return text(firstValue(record, ['couponTypeLabel', 'typeLabel', 'couponType', 'type']))
}

function couponAmount(record) {
  const amountLabel = firstValue(record, ['couponAmountLabel', 'faceValueLabel', 'discountAmountLabel'])
  if (hasValue(amountLabel)) return text(amountLabel)
  return money(firstValue(record, ['couponAmount', 'faceValue', 'discountAmount', 'amount']))
}

function pointChangeNo(record) {
  return text(firstValue(record, ['changeNo', 'recordNo', 'pointNo', 'no', 'code']))
}

function pointChangeType(record) {
  return text(firstValue(record, ['typeLabel', 'changeTypeLabel', 'businessTypeLabel', 'type', 'changeType']))
}

function pointChangeAmount(record) {
  return numberText(firstValue(record, ['changeAmount', 'amount', 'pointAmount', 'points']))
}

function pointsAfter(record) {
  return numberText(firstValue(record, ['pointsAfter', 'pointBalanceAfter', 'balanceAfter']))
}

function sourceReference(record) {
  return text(firstValue(record, ['sourceNo', 'sourceOrderNo', 'sourceReference', 'orderNo', 'reference']))
}

function serviceNo(record) {
  return text(firstValue(record, ['serviceNo', 'writeoffNo', 'recordNo', 'no', 'code']))
}

function serviceProject(record) {
  return text(firstValue(record, ['projectName', 'serviceProjectName', 'name']))
}

function serviceSource(record) {
  return text(firstValue(record, ['sourceLabel', 'serviceSourceLabel', 'sourceTypeLabel', 'source']))
}

function serviceStatus(record) {
  return displayStatus(firstValue(record, ['statusLabel', 'statusName', 'status']))
}

function salesOrderNo(record) {
  return text(firstValue(record, ['orderNo', 'salesOrderNo', 'no', 'code']))
}

function salesOrderAmount(record) {
  return money(firstValue(record, ['orderAmount', 'payableAmount', 'finalAmount', 'totalAmount']))
}

function salesOrderReceived(record) {
  return money(firstValue(record, ['actualReceivedAmount', 'receivedAmount', 'paidAmount']))
}

function normalizedGiftSource(record) {
  const type = String(firstValue(record, ['sourceType', 'source', 'sourceLabel', 'giftSourceLabel']) || '').toLowerCase()
  const explicitLabel = firstValue(record, ['sourceLabel', 'giftSourceLabel', 'sourceTypeLabel'])
  const sourceNo = firstValue(record, ['sourceOrderNo', 'sourceNo', 'sourceReference', 'orderNo', 'batchNo'])

  if (type.includes('independent') || type.includes('独立')) return '独立赠送'
  if (type.includes('batch') || type.includes('批量')) return sourceNo ? `批量赠送（${sourceNo}）` : '批量赠送'
  if (sourceNo) return String(sourceNo)
  return text(explicitLabel)
}

function giftContents(record) {
  const contents = readList(record, ['contents', 'giftContents', 'giftItems', 'items'])
  return contents.length ? contents : [record]
}

function giftContentType(content) {
  const type = firstValue(content, ['typeLabel', 'giftTypeLabel', 'contentTypeLabel', 'type', 'giftType'])
  if (isCoupon(content)) return '优惠券'
  const normalized = String(type || '').toLowerCase()
  return ({ project: '项目', product: '产品', coupon: '优惠券' })[normalized] || text(type)
}

function laborPerformanceDisplay(record) {
  const status = String(firstValue(record, ['laborPerformanceStatus', 'laborStatus']) || '').toLowerCase()
  if (status === 'pending') return '未核算'
  if (status === 'legacy_unmigrated') return '旧数据未迁移'
  return money(firstValue(record, ['laborPerformanceAmount', 'laborAmount']))
}

function giftContentName(content) {
  return text(firstValue(content, ['name', 'contentName', 'giftName', 'couponName', 'productName', 'projectName']))
}

function isCoupon(content) {
  const type = String(firstValue(content, ['type', 'typeLabel', 'giftType', 'giftTypeLabel', 'contentType']) || '').toLowerCase()
  return type.includes('coupon') || type.includes('优惠券') || type.includes('券')
}

function couponInfo(content) {
  if (!isCoupon(content)) return ''
  const type = firstValue(content, ['couponTypeLabel', 'couponType', 'discountTypeLabel', 'discountType'])
  const amountText = firstValue(content, ['couponAmountLabel', 'discountAmountLabel', 'faceValueLabel'])
  const amount = firstValue(content, ['couponAmount', 'discountAmount', 'faceValue', 'amount'])
  const pieces = []
  if (hasValue(type)) pieces.push(String(type))
  if (hasValue(amountText)) pieces.push(String(amountText))
  else if (hasValue(amount)) pieces.push(money(amount))
  return pieces.join(' · ')
}

function giftContentStatus(content) {
  return displayStatus(firstValue(content, ['statusLabel', 'giftStatusLabel', 'status', 'giftStatus']))
}

function reservationNo(record) {
  return text(firstValue(record, ['reservationNo', 'appointmentNo', 'no', 'code']))
}

function reservationTime(record) {
  const start = firstValue(record, ['startAt', 'reservationStartAt', 'appointmentStartAt', 'scheduledStartAt'])
  const end = firstValue(record, ['endAt', 'reservationEndAt', 'appointmentEndAt', 'scheduledEndAt'])
  if (hasValue(start) && hasValue(end)) return `${start} 至 ${end}`
  return text(start || end)
}

function careTaskType(record) {
  return text(firstValue(record, ['taskTypeLabel', 'typeLabel', 'taskType', 'type']))
}

function careTaskStatus(record) {
  const status = displayStatus(firstValue(record, ['statusLabel', 'taskStatusLabel', 'status', 'taskStatus']))
  return firstValue(record, ['isOverdue', 'overdue']) === true && status !== '—' ? `${status}、已逾期` : status
}

function careRecordType(record) {
  return text(firstValue(record, ['typeLabel', 'recordTypeLabel', 'followUpTypeLabel', 'type']))
}

function debtNo(record) {
  return text(firstValue(record, ['debtNo', 'debtCode', 'recordNo', 'no', 'code']))
}

function debtSource(record) {
  return text(firstValue(record, ['sourceLabel', 'debtSourceLabel', 'sourceTypeLabel', 'source']))
}

function debtSummary(record) {
  return text(firstValue(record, ['summary', 'productSummary', 'itemSummary', 'description', 'title']))
}

function debtStatus(record) {
  return displayStatus(firstValue(record, ['statusLabel', 'debtStatusLabel', 'status', 'debtStatus']))
}

function debtCardWriteoffLimit(record) {
  const limit = firstValue(record, ['cardWriteoffLimit', 'writeoffLimit', 'availableWriteoffTimes'])
  return hasValue(limit) ? numberText(limit, ' 次') : '—'
}

function findNamedAction(record, directKeys, matcherParts, scope) {
  const direct = firstRawValue(record, directKeys)
  const directAction = normalizeAction(direct, `${scope}-direct`, 0)
  if (directAction) return directAction

  return recordActions(record, scope).find((action) => {
    const haystack = `${action.code} ${action.label}`.toLowerCase().replace(/[\s_-]/g, '')
    return matcherParts.some((part) => haystack.includes(part))
  }) || null
}

function giftSourceAction(record, index) {
  return findNamedAction(
    record,
    ['sourceAction', 'sourceOrderAction', 'orderAction', 'relatedOrderAction'],
    ['vieworder', 'openorder', 'salesorder', 'sourceorder', '查看原单', '原订单'],
    `gift-source-${recordKey(record, 'gift', index)}`
  )
}

function couponAction(record, content, recordIndex, contentIndex) {
  const direct = firstRawValue(content, ['couponAction', 'detailAction', 'viewAction'])
  const directAction = normalizeAction(direct, `coupon-${recordIndex}-${contentIndex}`, 0)
  if (directAction) return directAction

  return findNamedAction(
    content,
    ['couponAction', 'detailAction', 'viewAction'],
    ['viewcoupon', 'opencoupon', 'coupon', '查看优惠券'],
    `coupon-${recordKey(record, 'gift', recordIndex)}-${contentIndex}`
  ) || findNamedAction(
    record,
    ['couponAction'],
    ['viewcoupon', 'opencoupon', 'coupon', '查看优惠券'],
    `coupon-record-${recordKey(record, 'gift', recordIndex)}-${contentIndex}`
  )
}

function actionFailed(result) {
  if (result === false) return true
  const response = result?.result && typeof result.result === 'object' ? result.result : result
  const status = String(response?.status || '').toLowerCase()
  return response?.success === false || response?.ok === false || ['failed', 'error', 'conflict'].includes(status)
}

function actionFailureMessage(result) {
  const response = result?.result && typeof result.result === 'object' ? result.result : result
  return response?.message || response?.errorMessage || response?.error || '操作未完成，请稍后重试。'
}

async function triggerAction(action, context = {}) {
  if (!props.onAction || !action || action.disabled || activeActionKey.value) return

  activeActionKey.value = action.key
  actionError.value = ''
  try {
    const result = await props.onAction({
      action: action.raw,
      actionCode: action.code,
      member: member.value,
      memberId: memberId.value,
      activeTab: activeTab.value,
      ...context
    })
    if (actionFailed(result)) actionError.value = actionFailureMessage(result)
  } catch (error) {
    actionError.value = error?.message || '操作未完成，请稍后重试。'
  } finally {
    activeActionKey.value = ''
  }
}
</script>

<template>
  <div class="member-detail-overlay" role="presentation" @click.self="requestClose">
    <section class="member-detail-overlay__page" role="dialog" aria-modal="true" aria-label="会员详情">
      <header class="member-detail-overlay__header">
        <div class="member-detail-overlay__identity">
          <div class="member-detail-overlay__eyebrow">会员详情</div>
          <div class="member-detail-overlay__title-row">
            <h2>{{ memberName }}</h2>
            <span class="member-detail-overlay__status">{{ memberStatus }}</span>
          </div>
          <dl class="member-detail-overlay__facts">
            <div v-for="fact in headerFacts" :key="fact.label">
              <dt>{{ fact.label }}</dt>
              <dd>{{ text(fact.value) }}</dd>
            </div>
          </dl>
        </div>

        <div class="member-detail-overlay__header-actions">
          <template v-for="action in availableActions" :key="action.key">
            <button
              type="button"
              class="member-detail-overlay__button member-detail-overlay__button--secondary"
              :disabled="!onAction || action.disabled || activeActionKey !== ''"
              :title="action.disabledReason || ''"
              @click="triggerAction(action, { scope: 'member' })"
            >
              {{ activeActionKey === action.key ? '处理中…' : action.label }}
            </button>
          </template>
          <button type="button" class="member-detail-overlay__close" :disabled="activeActionKey !== ''" aria-label="关闭会员详情" @click="requestClose">×</button>
        </div>
      </header>

      <section v-if="hasDetail && !isLoading" class="member-detail-overlay__stats" aria-label="会员关键数据">
        <div
          v-for="item in headerStats"
          :key="item.label"
          class="member-detail-overlay__stat"
          @mouseenter="showMetricTooltip($event, item)"
          @mouseleave="hideMetricTooltip"
          @focusin="showMetricTooltip($event, item)"
          @focusout="hideMetricTooltip"
        >
          <span :tabindex="item.tooltip ? 0 : undefined" :aria-describedby="item.tooltip ? 'member-detail-metric-tooltip' : undefined">{{ item.label }}<Info v-if="item.tooltip" class="member-detail-overlay__metric-help" :size="14" aria-hidden="true" /></span>
          <strong>{{ item.value }}</strong>
        </div>
      </section>

      <nav v-if="hasDetail && !isLoading" class="member-detail-overlay__tabs" role="tablist" aria-label="会员详情页签">
        <button
          v-for="tab in tabs"
          :key="tab.key"
          type="button"
          role="tab"
          :aria-selected="activeTab === tab.key"
          :class="{ 'member-detail-overlay__tab--active': activeTab === tab.key }"
          class="member-detail-overlay__tab"
          @click="switchTab(tab)"
        >
          {{ tab.label }}
        </button>
      </nav>

      <form v-if="hasDetail && !isLoading && isSearchableTab" class="member-detail-overlay__query" @submit.prevent="queryActiveTab">
        <label v-if="showDateRange" class="member-detail-overlay__date-range">
          <div>
            <input v-model="tabDateFrom" type="date" aria-label="开始日期" />
            <em>至</em>
            <input v-model="tabDateTo" type="date" aria-label="结束日期" />
          </div>
        </label>
        <label>
          <input v-model="tabKeyword" type="search" maxlength="80" :placeholder="tabKeywordPlaceholder" />
        </label>
        <button type="submit" class="member-detail-overlay__button member-detail-overlay__button--secondary" :disabled="tabIsLoading(activeTab) || activeActionKey !== ''">查询</button>
        <label v-if="hasStatusFilter" class="member-detail-overlay__status-filter">
          <select v-model="tabStatus" aria-label="状态筛选" @change="queryActiveTab">
            <template v-if="activeTab === 'care'">
              <option value="unfinished">未完成</option>
              <option value="">全部</option>
            </template>
            <template v-else>
              <option value="active">有效</option>
              <option value="">全部</option>
            </template>
          </select>
        </label>
      </form>

      <main class="member-detail-overlay__body" :aria-busy="isLoading || tabIsLoading(activeTab)">
        <div v-if="isLoading" class="member-detail-overlay__loading">
          <span class="member-detail-overlay__loading-dot" />
          正在加载会员详情…
        </div>

        <div v-else-if="!hasDetail" class="member-detail-overlay__empty">
          <strong>暂无会员详情</strong>
          <span>请返回会员列表后重新打开该会员。</span>
        </div>

        <div v-else-if="tabIsLoading(activeTab)" class="member-detail-overlay__loading">
          <span class="member-detail-overlay__loading-dot" />
          正在加载{{ tabs.find((tab) => tab.key === activeTab)?.label || '当前' }}数据…
        </div>

        <template v-else>
          <section v-if="activeTab === 'profile'" class="member-detail-overlay__tab-content" aria-label="会员概况">
            <div class="member-detail-overlay__panel-grid">
              <section class="member-detail-overlay__panel">
                <header><h3>基本资料</h3></header>
                <dl v-if="basicRows.length" class="member-detail-overlay__info-grid">
                  <div v-for="row in basicRows" :key="row.label"><dt>{{ row.label }}</dt><dd>{{ text(row.value) }}</dd></div>
                </dl>
                <div v-else class="member-detail-overlay__empty-inline">暂无基本资料</div>
                <dl v-if="customFields.length" class="member-detail-overlay__info-grid member-detail-overlay__info-grid--custom">
                  <div v-for="field in customFields" :key="field.key"><dt>{{ field.label }}</dt><dd>{{ field.value }}</dd></div>
                </dl>
              </section>

              <section class="member-detail-overlay__panel">
                <header><h3>会员关系</h3></header>
                <dl v-if="relationRows.length" class="member-detail-overlay__info-grid">
                  <div v-for="row in relationRows" :key="row.label"><dt>{{ row.label }}</dt><dd>{{ text(row.value) }}</dd></div>
                </dl>
                <div v-else class="member-detail-overlay__empty-inline">暂无会员关系信息</div>
              </section>

              <section class="member-detail-overlay__panel">
                <header>
                  <div><h3>最近到店</h3><span>仅展示实际服务到店，不以销售订单替代。</span></div>
                  <button type="button" class="member-detail-overlay__inline-action" @click="switchTabByKey('writeoff')">查看服务记录</button>
                </header>
                <dl v-if="latestVisitRows.length" class="member-detail-overlay__info-grid">
                  <div v-for="row in latestVisitRows" :key="row.label"><dt>{{ row.label }}</dt><dd>{{ text(row.value) }}</dd></div>
                </dl>
                <div v-else class="member-detail-overlay__empty-inline">暂无实际到店记录</div>
              </section>

              <section class="member-detail-overlay__panel">
                <header>
                  <h3>客情提醒</h3>
                  <button type="button" class="member-detail-overlay__inline-action" @click="switchTabByKey('care')">查看客情管理</button>
                </header>
                <dl v-if="careReminderRows.length" class="member-detail-overlay__info-grid">
                  <div v-for="row in careReminderRows" :key="row.label"><dt>{{ row.label }}</dt><dd>{{ text(row.value) }}</dd></div>
                </dl>
                <div v-else class="member-detail-overlay__empty-inline">暂无客情提醒</div>
              </section>

            </div>
          </section>

          <section v-else-if="activeTab === 'assets'" class="member-detail-overlay__tab-content" aria-label="权益明细">
            <section v-for="(card, cardIndex) in cards" :key="recordKey(card, 'card', cardIndex)" class="member-detail-overlay__card-panel">
              <header class="member-detail-overlay__card-header">
                <div>
                  <div class="member-detail-overlay__card-title-row">
                    <h3>{{ cardName(card) }}</h3>
                    <span class="member-detail-overlay__record-status">{{ displayStatus(firstValue(card, ['statusLabel', 'statusName', 'status'])) }}</span>
                  </div>
                  <span>卡号：{{ cardNo(card) }}</span>
                </div>
                <div class="member-detail-overlay__record-actions">
                  <button
                    v-for="action in recordActions(card, `card-${cardIndex}`)"
                    :key="action.key"
                    type="button"
                    class="member-detail-overlay__inline-action"
                    :disabled="!onAction || action.disabled || activeActionKey !== ''"
                    :title="action.disabledReason || ''"
                    @click="triggerAction(action, { scope: 'card', record: card })"
                  >
                    {{ activeActionKey === action.key ? '处理中…' : action.label }}
                  </button>
                </div>
              </header>
              <dl class="member-detail-overlay__card-meta">
                <div><dt>所属门店</dt><dd>{{ text(firstValue(card, ['storeName', 'belongStoreName'])) }}</dd></div>
                <div><dt>开卡时间</dt><dd>{{ text(firstValue(card, ['openedAt', 'activatedAt', 'openAt'])) }}</dd></div>
                <div><dt>有效期至</dt><dd>{{ text(firstValue(card, ['expiresAt', 'expireAt', 'validUntil'])) }}</dd></div>
                <div><dt>剩余次数</dt><dd>{{ cardRemainingTimesDisplay(card) }}</dd></div>
                <div><dt>剩余金额</dt><dd>{{ money(firstValue(card, ['remainingAmount', 'leftAmount'])) }}</dd></div>
                <div><dt>购卡批号</dt><dd>{{ text(firstValue(card, ['purchaseBatchNo', 'batchNo'])) }}</dd></div>
              </dl>
              <div class="member-detail-overlay__table-wrap">
                <table class="member-detail-overlay__table">
                  <thead>
                    <tr><th>具体项目</th><th>购买次数</th><th>已用次数</th><th>剩余次数</th><th>剩余金额</th></tr>
                  </thead>
                  <tbody>
                    <tr v-for="(project, projectIndex) in cardProjects(card)" :key="recordKey(project, `card-project-${cardIndex}`, projectIndex)">
                      <td>{{ cardProjectName(project) }}</td>
                      <td>{{ cardProjectTimesDisplay(card, project, ['purchaseTimes', 'totalTimes', 'boughtTimes']) }}</td>
                      <td>{{ cardProjectTimesDisplay(card, project, ['usedTimes', 'consumedTimes']) }}</td>
                      <td>{{ cardProjectTimesDisplay(card, project, ['remainingTimes', 'leftTimes']) }}</td>
                      <td>{{ cardProjectAmountDisplay(card, project) }}</td>
                    </tr>
                    <tr v-if="!cardProjects(card).length"><td colspan="5" class="member-detail-overlay__table-empty">该卡暂未返回项目权益明细</td></tr>
                  </tbody>
                </table>
              </div>
            </section>
            <div v-if="!cards.length" class="member-detail-overlay__empty-inline member-detail-overlay__empty-inline--page">暂无卡项权益</div>

            <section class="member-detail-overlay__panel">
              <header><h3>优惠券</h3><span>展示后端返回的可查看优惠券记录。</span></header>
              <div class="member-detail-overlay__table-wrap">
                <table class="member-detail-overlay__table member-detail-overlay__table--wide">
                  <thead>
                    <tr><th>优惠券编号</th><th>优惠券名称</th><th>优惠类型</th><th>优惠金额</th><th>生效时间</th><th>到期时间</th><th>状态</th><th>操作</th></tr>
                  </thead>
                  <tbody>
                    <tr v-for="(record, index) in coupons" :key="recordKey(record, 'coupon', index)">
                      <td>{{ couponNo(record) }}</td>
                      <td>{{ couponName(record) }}</td>
                      <td>{{ couponType(record) }}</td>
                      <td class="member-detail-overlay__money">{{ couponAmount(record) }}</td>
                      <td>{{ text(firstValue(record, ['effectiveAt', 'effectiveTime', 'startAt'])) }}</td>
                      <td>{{ text(firstValue(record, ['expiresAt', 'expireAt', 'endAt'])) }}</td>
                      <td><span class="member-detail-overlay__record-status">{{ displayStatus(firstValue(record, ['statusLabel', 'couponStatusLabel', 'status'])) }}</span></td>
                      <td><div class="member-detail-overlay__record-actions"><button v-for="action in recordActions(record, `coupon-${index}`)" :key="action.key" type="button" class="member-detail-overlay__inline-action" :disabled="!onAction || action.disabled || activeActionKey !== ''" :title="action.disabledReason || ''" @click="triggerAction(action, { scope: 'coupon', record })">{{ activeActionKey === action.key ? '处理中…' : action.label }}</button></div></td>
                    </tr>
                    <tr v-if="!coupons.length"><td colspan="8" class="member-detail-overlay__table-empty">暂无可查看的优惠券记录</td></tr>
                  </tbody>
                </table>
              </div>
            </section>

          </section>

          <section v-else-if="activeTab === 'writeoff'" class="member-detail-overlay__tab-content" aria-label="服务记录">
            <section class="member-detail-overlay__panel">
              <header><h3>服务记录</h3></header>
              <div class="member-detail-overlay__table-wrap">
                <table class="member-detail-overlay__table member-detail-overlay__table--wide">
                  <thead>
                    <tr><th>服务记录号</th><th>业务日期</th><th>服务项目</th><th>服务来源</th><th>来源卡名称</th><th>完整卡号</th><th>本次使用次数</th><th>服务门店</th><th>手艺人</th><th>劳动业绩</th><th>核销操作人</th><th>服务状态</th><th>补单标记</th><th>操作</th></tr>
                  </thead>
                  <tbody>
                    <tr v-for="(record, index) in writeoffRecords" :key="recordKey(record, 'writeoff', index)">
                      <td>{{ serviceNo(record) }}</td>
                      <td>{{ text(firstValue(record, ['businessDate', 'date', 'writeoffAt'])) }}</td>
                      <td>{{ serviceProject(record) }}</td>
                      <td>{{ serviceSource(record) }}</td>
                      <td>{{ text(firstValue(record, ['cardName', 'sourceCardName'])) }}</td>
                      <td>{{ text(firstValue(record, ['cardNo', 'cardNumber', 'sourceCardNo'])) }}</td>
                      <td>{{ numberText(firstValue(record, ['usedTimes', 'writeoffTimes', 'times']), ' 次') }}</td>
                      <td>{{ text(firstValue(record, ['storeName', 'serviceStoreName'])) }}</td>
                      <td>{{ listText(firstValue(record, ['craftsmenSummary', 'craftsmen', 'staffSummary'])) }}</td>
                      <td class="member-detail-overlay__money">{{ laborPerformanceDisplay(record) }}</td>
                      <td>{{ text(firstValue(record, ['operatorName', 'writeoffOperatorName', 'operator'])) }}</td>
                      <td><span class="member-detail-overlay__record-status">{{ serviceStatus(record) }}</span></td>
                      <td>{{ firstValue(record, ['isSupplement', 'supplementFlag', 'isMakeup']) === true ? '补单' : '—' }}</td>
                      <td><div class="member-detail-overlay__record-actions"><button v-for="action in recordActions(record, `writeoff-${index}`)" :key="action.key" type="button" class="member-detail-overlay__inline-action" :disabled="!onAction || action.disabled || activeActionKey !== ''" :title="action.disabledReason || ''" @click="triggerAction(action, { scope: 'writeoff-record', record })">{{ activeActionKey === action.key ? '处理中…' : action.label }}</button></div></td>
                    </tr>
                    <tr v-if="!writeoffRecords.length"><td colspan="14" class="member-detail-overlay__table-empty">暂无可查看的服务记录</td></tr>
                  </tbody>
                </table>
              </div>
            </section>
          </section>

          <section v-else-if="activeTab === 'sales'" class="member-detail-overlay__tab-content" aria-label="销售订单">
            <section class="member-detail-overlay__panel">
              <header><h3>销售订单</h3><span>销售订单只展示购买单据，不混入核销和实际服务记录。</span></header>
              <div class="member-detail-overlay__table-wrap">
                <table class="member-detail-overlay__table member-detail-overlay__table--wide">
                  <thead>
                    <tr><th>销售订单号</th><th>业务日期</th><th>销售门店</th><th>订单类型</th><th>销售金额</th><th>现金业绩</th><th>订单状态</th><th>支付完成时间</th><th>操作</th></tr>
                  </thead>
                  <tbody>
                    <tr v-for="(record, index) in salesOrders" :key="recordKey(record, 'sale', index)">
                      <td>{{ salesOrderNo(record) }}</td>
                      <td>{{ text(firstValue(record, ['businessDate', 'date'])) }}</td>
                      <td>{{ text(firstValue(record, ['storeName', 'salesStoreName', 'businessStoreName'])) }}</td>
                      <td>{{ text(firstValue(record, ['orderTypeLabel', 'businessTypeLabel', 'typeLabel', 'isSupplement']) === true ? '补单' : firstValue(record, ['orderTypeLabel', 'businessTypeLabel', 'typeLabel'])) }}</td>
                      <td class="member-detail-overlay__money">{{ salesOrderAmount(record) }}</td>
                      <td class="member-detail-overlay__money">{{ salesOrderReceived(record) }}</td>
                      <td><span class="member-detail-overlay__record-status">{{ displayStatus(firstValue(record, ['statusLabel', 'statusName', 'status'])) }}</span></td>
                      <td>{{ text(firstValue(record, ['completedAt', 'paidAt', 'paymentCompletedAt'])) }}</td>
                      <td><div class="member-detail-overlay__record-actions"><button v-for="action in recordActions(record, `sale-${index}`)" :key="action.key" type="button" class="member-detail-overlay__inline-action" :disabled="!onAction || action.disabled || activeActionKey !== ''" :title="action.disabledReason || ''" @click="triggerAction(action, { scope: 'sales-order', record })">{{ activeActionKey === action.key ? '处理中…' : action.label }}</button></div></td>
                    </tr>
                    <tr v-if="!salesOrders.length"><td colspan="9" class="member-detail-overlay__table-empty">暂无销售订单</td></tr>
                  </tbody>
                </table>
              </div>
            </section>
          </section>

          <section v-else-if="activeTab === 'care'" class="member-detail-overlay__tab-content" aria-label="客情管理">
            <section class="member-detail-overlay__panel">
              <header><h3>当前客情关系</h3><span>客情查看范围由后端按当前账号数据权限返回。</span></header>
              <dl v-if="relationRows.length" class="member-detail-overlay__info-grid">
                <div v-for="row in relationRows" :key="row.label"><dt>{{ row.label }}</dt><dd>{{ text(row.value) }}</dd></div>
              </dl>
              <div v-else class="member-detail-overlay__empty-inline">暂无可查看的客情关系信息</div>
              <div v-if="exclusiveServiceStaffChanges.length" class="member-detail-overlay__table-wrap">
                <table class="member-detail-overlay__table member-detail-overlay__table--wide">
                  <thead>
                    <tr><th>原专属服务人</th><th>新专属服务人</th><th>变更原因</th><th>操作人</th><th>操作时间</th><th>操作</th></tr>
                  </thead>
                  <tbody>
                    <tr v-for="(record, index) in exclusiveServiceStaffChanges" :key="recordKey(record, 'service-staff-change', index)">
                      <td>{{ text(firstValue(record, ['previousStaffName', 'oldStaffName', 'fromStaffName'])) }}</td>
                      <td>{{ text(firstValue(record, ['currentStaffName', 'newStaffName', 'toStaffName'])) }}</td>
                      <td>{{ text(firstValue(record, ['reason', 'changeReason', 'remark'])) }}</td>
                      <td>{{ text(firstValue(record, ['operatorName', 'operator', 'staffName'])) }}</td>
                      <td>{{ text(firstValue(record, ['occurredAt', 'changedAt', 'createdAt', 'operatedAt'])) }}</td>
                      <td><div class="member-detail-overlay__record-actions"><button v-for="action in recordActions(record, `service-staff-change-${index}`)" :key="action.key" type="button" class="member-detail-overlay__inline-action" :disabled="!onAction || action.disabled || activeActionKey !== ''" :title="action.disabledReason || ''" @click="triggerAction(action, { scope: 'exclusive-service-staff-change', record })">{{ activeActionKey === action.key ? '处理中…' : action.label }}</button></div></td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </section>

            <section class="member-detail-overlay__panel">
              <header><h3>跟进任务</h3><span>任务的可操作性完全以服务端返回的语义动作和权限结果为准。</span></header>
              <div class="member-detail-overlay__table-wrap">
                <table class="member-detail-overlay__table member-detail-overlay__table--wide">
                  <thead>
                    <tr><th>任务单号</th><th>任务类型</th><th>计划跟进时间</th><th>任务负责人</th><th>任务来源</th><th>关联业务</th><th>任务状态</th><th>任务创建人</th><th>创建时间</th><th>实际跟进人</th><th>实际完成时间</th><th>完成结果</th><th>操作</th></tr>
                  </thead>
                  <tbody>
                    <tr v-for="(record, index) in careTasks" :key="recordKey(record, 'care-task', index)">
                      <td>{{ text(firstValue(record, ['taskNo', 'documentNo'])) }}</td>
                      <td>{{ careTaskType(record) }}</td>
                      <td>{{ text(firstValue(record, ['scheduledAt', 'plannedAt', 'nextFollowUpAt', 'dueAt'])) }}</td>
                      <td>{{ text(firstValue(record, ['ownerName', 'taskOwnerName', 'assigneeName'])) }}</td>
                      <td>{{ text(firstValue(record, ['sourceLabel', 'taskSourceLabel', 'source'])) }}</td>
                      <td>{{ text(firstValue(record, ['relatedBusiness', 'relatedSummary', 'sourceReference'])) }}</td>
                      <td><span class="member-detail-overlay__record-status">{{ careTaskStatus(record) }}</span></td>
                      <td>{{ text(firstValue(record, ['creatorName', 'createdByName', 'operatorName'])) }}</td>
                      <td>{{ text(firstValue(record, ['createdAt', 'createdTime'])) }}</td>
                      <td>{{ text(firstValue(record, ['completedByName', 'actualFollowerName', 'actualStaffName'])) }}</td>
                      <td>{{ text(firstValue(record, ['completedAt', 'finishedAt', 'actualCompletedAt'])) }}</td>
                      <td>{{ text(firstValue(record, ['resultSummary', 'result', 'completionResult'])) }}</td>
                      <td><div class="member-detail-overlay__record-actions"><button v-for="action in recordActions(record, `care-task-${index}`)" :key="action.key" type="button" class="member-detail-overlay__inline-action" :disabled="!onAction || action.disabled || activeActionKey !== ''" :title="action.disabledReason || ''" @click="triggerAction(action, { scope: 'care-task', record })">{{ activeActionKey === action.key ? '处理中…' : action.label }}</button></div></td>
                    </tr>
                    <tr v-if="!careTasks.length"><td colspan="13" class="member-detail-overlay__table-empty">暂无可查看的跟进任务</td></tr>
                  </tbody>
                </table>
              </div>
            </section>

            <section class="member-detail-overlay__panel">
              <header><h3>客情时间轴</h3><span>展示回访、日常跟进、邀约和服务后反馈等已返回记录。</span></header>
              <div class="member-detail-overlay__table-wrap">
                <table class="member-detail-overlay__table member-detail-overlay__table--wide">
                  <thead>
                    <tr><th>记录类型</th><th>跟进时间</th><th>跟进方式</th><th>跟进内容</th><th>跟进结果</th><th>实际跟进人</th><th>记录创建人</th><th>创建时间</th><th>记录门店</th><th>关联业务</th><th>下次跟进</th><th>下次任务负责人</th><th>状态</th><th>操作</th></tr>
                  </thead>
                  <tbody>
                    <tr v-for="(record, index) in careRecords" :key="recordKey(record, 'care-record', index)">
                      <td>{{ careRecordType(record) }}</td>
                      <td>{{ text(firstValue(record, ['followedAt', 'followUpAt', 'occurredAt'])) }}</td>
                      <td>{{ text(firstValue(record, ['channelLabel', 'followUpChannelLabel', 'methodLabel', 'channel'])) }}</td>
                      <td>{{ text(firstValue(record, ['content', 'summary', 'remark'])) }}</td>
                      <td>{{ text(firstValue(record, ['resultLabel', 'result', 'followUpResult'])) }}</td>
                      <td>{{ text(firstValue(record, ['actualFollowerName', 'followerName', 'operatorName'])) }}</td>
                      <td>{{ text(firstValue(record, ['creatorName', 'createdByName'])) }}</td>
                      <td>{{ text(firstValue(record, ['createdAt', 'createdTime'])) }}</td>
                      <td>{{ text(firstValue(record, ['storeName', 'recordStoreName'])) }}</td>
                      <td>{{ text(firstValue(record, ['relatedBusiness', 'relatedSummary', 'sourceReference'])) }}</td>
                      <td>{{ text(firstValue(record, ['nextFollowUpAt', 'nextReminderAt', 'nextTaskAt'])) }}</td>
                      <td>{{ text(firstValue(record, ['nextTaskOwnerName', 'nextOwnerName'])) }}</td>
                      <td><span class="member-detail-overlay__record-status">{{ displayStatus(firstValue(record, ['statusLabel', 'statusName', 'status'])) }}</span></td>
                      <td><div class="member-detail-overlay__record-actions"><button v-for="action in recordActions(record, `care-record-${index}`)" :key="action.key" type="button" class="member-detail-overlay__inline-action" :disabled="!onAction || action.disabled || activeActionKey !== ''" :title="action.disabledReason || ''" @click="triggerAction(action, { scope: 'care-record', record })">{{ activeActionKey === action.key ? '处理中…' : action.label }}</button></div></td>
                    </tr>
                    <tr v-if="!careRecords.length"><td colspan="14" class="member-detail-overlay__table-empty">暂无可查看的客情记录</td></tr>
                  </tbody>
                </table>
              </div>
            </section>
          </section>

          <section v-else-if="activeTab === 'debt'" class="member-detail-overlay__tab-content" aria-label="欠款记录">
            <section class="member-detail-overlay__panel">
              <header><h3>欠款记录</h3><span>每条欠款独立展示并由后端返回对应补交记录与可执行动作。</span></header>
              <div class="member-detail-overlay__table-wrap">
                <table class="member-detail-overlay__table member-detail-overlay__table--wide">
                  <thead>
                    <tr><th>欠款编号</th><th>业务日期</th><th>欠款来源</th><th>来源订单号</th><th>商品或充值摘要</th><th>商品应付金额</th><th>当时现金业绩</th><th>原始欠款金额</th><th>已补交金额</th><th>剩余欠款金额</th><th>卡项欠款可核销上限</th><th>欠款状态</th><th>欠款门店</th><th>欠款操作人</th><th>最近补交时间</th><th>操作</th></tr>
                  </thead>
                  <tbody>
                    <tr v-for="(record, index) in debtRecords" :key="recordKey(record, 'debt', index)">
                      <td>{{ debtNo(record) }}</td>
                      <td>{{ text(firstValue(record, ['businessDate', 'date'])) }}</td>
                      <td>{{ debtSource(record) }}</td>
                      <td>{{ text(firstValue(record, ['sourceOrderNo', 'orderNo', 'sourceNo'])) }}</td>
                      <td>{{ debtSummary(record) }}</td>
                      <td class="member-detail-overlay__money">{{ money(firstValue(record, ['payableAmount', 'itemPayableAmount'])) }}</td>
                      <td class="member-detail-overlay__money">{{ money(firstValue(record, ['originallyReceivedAmount', 'actualReceivedAmount', 'receivedAmount'])) }}</td>
                      <td class="member-detail-overlay__money">{{ money(firstValue(record, ['originalDebtAmount', 'debtAmount', 'amount'])) }}</td>
                      <td class="member-detail-overlay__money">{{ money(firstValue(record, ['repaidAmount', 'paidBackAmount', 'settledAmount'])) }}</td>
                      <td class="member-detail-overlay__money">{{ money(firstValue(record, ['remainingDebtAmount', 'remainingAmount', 'outstandingAmount'])) }}</td>
                      <td>{{ debtCardWriteoffLimit(record) }}</td>
                      <td><span class="member-detail-overlay__record-status">{{ debtStatus(record) }}</span></td>
                      <td>{{ text(firstValue(record, ['storeName', 'debtStoreName'])) }}</td>
                      <td>{{ text(firstValue(record, ['operatorName', 'debtOperatorName', 'operator'])) }}</td>
                      <td>{{ text(firstValue(record, ['latestRepaymentAt', 'lastRepaidAt', 'latestPaymentAt'])) }}</td>
                      <td><div class="member-detail-overlay__record-actions"><button v-for="action in recordActions(record, `debt-${index}`)" :key="action.key" type="button" class="member-detail-overlay__inline-action" :disabled="!onAction || action.disabled || activeActionKey !== ''" :title="action.disabledReason || ''" @click="triggerAction(action, { scope: 'debt-record', record })">{{ activeActionKey === action.key ? '处理中…' : action.label }}</button></div></td>
                    </tr>
                    <tr v-if="!debtRecords.length"><td colspan="16" class="member-detail-overlay__table-empty">暂无可查看的欠款记录</td></tr>
                  </tbody>
                </table>
              </div>
            </section>
          </section>

          <section v-else-if="activeTab === 'card-operations'" class="member-detail-overlay__tab-content" aria-label="卡操作记录">
            <section class="member-detail-overlay__panel">
              <header><h3>卡操作记录</h3><span>仅展示当前数据范围内已留痕的卡操作。</span></header>
              <div class="member-detail-overlay__table-wrap">
                <table class="member-detail-overlay__table member-detail-overlay__table--wide">
                  <thead>
                    <tr><th>操作单号</th><th>业务日期</th><th>操作类型</th><th>原卡名称</th><th>原卡号</th><th>目标内容</th><th>关联会员</th><th>金额</th><th>办理门店</th><th>操作人</th><th>原因</th><th>状态</th><th>完成时间</th></tr>
                  </thead>
                  <tbody>
                    <tr v-for="(record, index) in cardOperations" :key="recordKey(record, 'card-operation', index)">
                      <td>{{ cardOperationNo(record) }}</td>
                      <td>{{ text(firstValue(record, ['businessDate', 'date'])) }}</td>
                      <td>{{ text(firstValue(record, ['typeLabel', 'operationTypeLabel', 'operationType'])) }}</td>
                      <td>{{ text(firstValue(record, ['cardName', 'sourceCardName'])) }}</td>
                      <td>{{ text(firstValue(record, ['cardNo', 'sourceCardNo'])) }}</td>
                      <td>{{ text(firstValue(record, ['targetContent', 'targetName'])) }}</td>
                      <td>{{ text(firstValue(record, ['relatedMemberName', 'memberName'])) }}</td>
                      <td class="member-detail-overlay__money">{{ money(firstValue(record, ['amount', 'settlementAmount'])) }}</td>
                      <td>{{ text(firstValue(record, ['storeName', 'handlingStoreName'])) }}</td>
                      <td>{{ text(firstValue(record, ['operatorName', 'operator'])) }}</td>
                      <td>{{ text(firstValue(record, ['reason', 'remark'])) }}</td>
                      <td><span class="member-detail-overlay__record-status">{{ displayStatus(firstValue(record, ['statusLabel', 'status'])) }}</span></td>
                      <td>{{ text(firstValue(record, ['completedAt', 'occurredAt'])) }}</td>
                    </tr>
                    <tr v-if="!cardOperations.length"><td colspan="13" class="member-detail-overlay__table-empty">暂无卡操作记录</td></tr>
                  </tbody>
                </table>
              </div>
            </section>
          </section>

          <section v-else-if="activeTab === 'balance-changes'" class="member-detail-overlay__tab-content" aria-label="余额变动明细">
            <section class="member-detail-overlay__panel">
              <header><h3>余额变动明细</h3><span>本金、赠金拆分只读取后端余额变更事实。</span></header>
              <div class="member-detail-overlay__table-wrap">
                <table class="member-detail-overlay__table member-detail-overlay__table--wide">
                  <thead>
                    <tr><th>变更记录号</th><th>业务日期</th><th>变更类型</th><th>变更金额</th><th>本金变动</th><th>赠金变动</th><th>变动后本金</th><th>变动后赠金</th><th>变动后可用余额</th><th>来源单据</th><th>操作人</th><th>操作</th></tr>
                  </thead>
                  <tbody>
                    <tr v-for="(record, index) in balanceChanges" :key="recordKey(record, 'balance', index)">
                      <td>{{ balanceChangeNo(record) }}</td>
                      <td>{{ text(firstValue(record, ['businessDate', 'date', 'occurredAt'])) }}</td>
                      <td>{{ balanceChangeType(record) }}</td>
                      <td class="member-detail-overlay__money">{{ balanceChangeAmount(record) }}</td>
                      <td class="member-detail-overlay__money">{{ money(firstValue(record, ['principalDelta'])) }}</td>
                      <td class="member-detail-overlay__money">{{ money(firstValue(record, ['bonusDelta'])) }}</td>
                      <td class="member-detail-overlay__money">{{ money(firstValue(record, ['principalAfter'])) }}</td>
                      <td class="member-detail-overlay__money">{{ money(firstValue(record, ['bonusAfter'])) }}</td>
                      <td class="member-detail-overlay__money">{{ balanceAfter(record) }}</td>
                      <td>{{ sourceReference(record) }}</td>
                      <td>{{ text(firstValue(record, ['operatorName', 'operator', 'staffName'])) }}</td>
                      <td><div class="member-detail-overlay__record-actions"><button v-for="action in recordActions(record, `balance-${index}`)" :key="action.key" type="button" class="member-detail-overlay__inline-action" :disabled="!onAction || action.disabled || activeActionKey !== ''" :title="action.disabledReason || ''" @click="triggerAction(action, { scope: 'balance-change', record })">{{ activeActionKey === action.key ? '处理中…' : action.label }}</button></div></td>
                    </tr>
                    <tr v-if="!balanceChanges.length"><td colspan="12" class="member-detail-overlay__table-empty">暂无余额变动明细</td></tr>
                  </tbody>
                </table>
              </div>
            </section>
          </section>

          <section v-else-if="activeTab === 'gift'" class="member-detail-overlay__tab-content" aria-label="赠送记录">
            <section v-for="(record, recordIndex) in giftRecords" :key="recordKey(record, 'gift', recordIndex)" class="member-detail-overlay__gift-panel">
              <header class="member-detail-overlay__gift-header">
                <dl>
                  <div><dt>赠送记录号</dt><dd>{{ text(firstValue(record, ['giftNo', 'recordNo', 'no', 'code'])) }}</dd></div>
                  <div><dt>业务日期</dt><dd>{{ text(firstValue(record, ['businessDate', 'date'])) }}</dd></div>
                  <div><dt>赠送来源</dt><dd><button v-if="giftSourceAction(record, recordIndex)" type="button" class="member-detail-overlay__source-link" :disabled="!onAction || giftSourceAction(record, recordIndex).disabled || activeActionKey !== ''" :title="giftSourceAction(record, recordIndex).disabledReason || '点击查看关联原单'" @click="triggerAction(giftSourceAction(record, recordIndex), { scope: 'gift-source', record })">{{ normalizedGiftSource(record) }}</button><span v-else>{{ normalizedGiftSource(record) }}</span></dd></div>
                  <div><dt>办理门店</dt><dd>{{ text(firstValue(record, ['storeName', 'handlingStoreName'])) }}</dd></div>
                  <div><dt>操作人</dt><dd>{{ text(firstValue(record, ['operatorName', 'operator', 'staffName'])) }}</dd></div>
                  <div><dt>赠送原因</dt><dd>{{ text(firstValue(record, ['reason', 'giftReason', 'remark'])) }}</dd></div>
                  <div><dt>创建时间</dt><dd>{{ text(firstValue(record, ['createdAt', 'createdTime'])) }}</dd></div>
                </dl>
                <div class="member-detail-overlay__record-actions"><button v-for="action in recordActions(record, `gift-${recordIndex}`)" :key="action.key" type="button" class="member-detail-overlay__inline-action" :disabled="!onAction || action.disabled || activeActionKey !== ''" :title="action.disabledReason || ''" @click="triggerAction(action, { scope: 'gift-record', record })">{{ activeActionKey === action.key ? '处理中…' : action.label }}</button></div>
              </header>
              <div class="member-detail-overlay__table-wrap">
                <table class="member-detail-overlay__table member-detail-overlay__table--wide">
                  <thead>
                    <tr><th>赠送类型</th><th>赠送内容</th><th>赠送数量</th><th>生效时间</th><th>到期时间</th><th>赠送状态</th></tr>
                  </thead>
                  <tbody>
                    <tr v-for="(content, contentIndex) in giftContents(record)" :key="recordKey(content, `gift-content-${recordIndex}`, contentIndex)">
                      <td>{{ giftContentType(content) }}</td>
                      <td>
                        <button v-if="isCoupon(content) && couponAction(record, content, recordIndex, contentIndex)" type="button" class="member-detail-overlay__source-link" :disabled="!onAction || couponAction(record, content, recordIndex, contentIndex).disabled || activeActionKey !== ''" :title="couponAction(record, content, recordIndex, contentIndex).disabledReason || '查看优惠类型和优惠金额'" @click="triggerAction(couponAction(record, content, recordIndex, contentIndex), { scope: 'gift-coupon', record, content })">{{ giftContentName(content) }}</button>
                        <span v-else>{{ giftContentName(content) }}</span>
                        <small v-if="couponInfo(content)">{{ couponInfo(content) }}</small>
                      </td>
                      <td>{{ numberText(firstValue(content, ['quantity', 'count', 'giftQuantity'])) }}</td>
                      <td>{{ text(firstValue(content, ['effectiveAt', 'effectiveTime', 'startAt'])) }}</td>
                      <td>{{ text(firstValue(content, ['expiresAt', 'expireAt', 'endAt'])) }}</td>
                      <td><span class="member-detail-overlay__record-status">{{ giftContentStatus(content) }}</span></td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </section>
            <div v-if="!giftRecords.length" class="member-detail-overlay__empty-inline member-detail-overlay__empty-inline--page">暂无赠送记录</div>
          </section>

          <section v-else-if="activeTab === 'archive'" class="member-detail-overlay__tab-content member-detail-overlay__tab-content--archive" aria-label="会员档案">
            <section class="member-detail-overlay__panel member-detail-overlay__archive-panel">
              <header>
                <div>
                  <h3>会员档案</h3>
                  <span>展示会员新增/编辑时保存的基本资料与自定义字段。</span>
                </div>
              </header>
              <dl v-if="archiveRows.length" class="member-detail-overlay__info-grid member-detail-overlay__archive-grid">
                <div v-for="row in archiveRows" :key="row.label"><dt>{{ row.label }}</dt><dd>{{ text(row.value) }}</dd></div>
              </dl>
              <div v-if="customFields.length" class="member-detail-overlay__archive-custom">
                <h4>自定义字段</h4>
                <dl class="member-detail-overlay__info-grid member-detail-overlay__info-grid--custom">
                  <div v-for="field in customFields" :key="field.key"><dt>{{ field.label }}</dt><dd>{{ text(field.value) }}</dd></div>
                </dl>
              </div>
              <div v-if="!archiveRows.length && !customFields.length" class="member-detail-overlay__empty-inline">暂无会员档案资料</div>
            </section>
          </section>

          <section v-else-if="activeTab === 'points'" class="member-detail-overlay__tab-content" aria-label="积分变动记录">
            <section class="member-detail-overlay__panel">
              <header><h3>积分变动记录</h3><span>当前积分以顶部后端快照为准。</span></header>
              <div class="member-detail-overlay__table-wrap">
                <table class="member-detail-overlay__table member-detail-overlay__table--wide">
                  <thead>
                    <tr><th>变动记录号</th><th>业务日期</th><th>变动类型</th><th>变动积分</th><th>变动后积分</th><th>来源单据</th><th>操作人</th><th>备注</th><th>操作</th></tr>
                  </thead>
                  <tbody>
                    <tr v-for="(record, index) in pointChanges" :key="recordKey(record, 'point', index)">
                      <td>{{ pointChangeNo(record) }}</td>
                      <td>{{ text(firstValue(record, ['businessDate', 'date', 'occurredAt'])) }}</td>
                      <td>{{ pointChangeType(record) }}</td>
                      <td>{{ pointChangeAmount(record) }}</td>
                      <td>{{ pointsAfter(record) }}</td>
                      <td>{{ sourceReference(record) }}</td>
                      <td>{{ text(firstValue(record, ['operatorName', 'operator', 'staffName'])) }}</td>
                      <td>{{ text(firstValue(record, ['remark', 'note', 'memo'])) }}</td>
                      <td><div class="member-detail-overlay__record-actions"><button v-for="action in recordActions(record, `point-${index}`)" :key="action.key" type="button" class="member-detail-overlay__inline-action" :disabled="!onAction || action.disabled || activeActionKey !== ''" :title="action.disabledReason || ''" @click="triggerAction(action, { scope: 'point-change', record })">{{ activeActionKey === action.key ? '处理中…' : action.label }}</button></div></td>
                    </tr>
                    <tr v-if="!pointChanges.length"><td colspan="9" class="member-detail-overlay__table-empty">暂无积分变动记录</td></tr>
                  </tbody>
                </table>
              </div>
            </section>
          </section>

        </template>
      </main>

      <footer class="member-detail-overlay__footer">
        <p v-if="actionError" class="member-detail-overlay__error" role="alert">{{ actionError }}</p>
        <button type="button" class="member-detail-overlay__button member-detail-overlay__button--secondary" :disabled="activeActionKey !== ''" @click="requestClose">关闭</button>
      </footer>
    </section>
    <div
      v-if="metricTooltip.visible"
      id="member-detail-metric-tooltip"
      class="member-detail-overlay__metric-tooltip"
      role="tooltip"
      :style="{ left: `${metricTooltip.left}px`, top: `${metricTooltip.top}px` }"
    >{{ metricTooltip.text }}</div>
  </div>
</template>

<style scoped>
.member-detail-overlay {
  position: fixed;
  z-index: 1320;
  inset: 0;
  display: grid;
  place-items: center;
  padding: 20px;
  background: rgb(16 24 40 / 48%);
}

.member-detail-overlay__page {
  display: grid;
  width: min(1480px, 100%);
  height: min(900px, calc(100vh - 40px));
  max-height: calc(100vh - 40px);
  grid-template-rows: auto auto auto auto minmax(0, 1fr) auto;
  overflow: hidden;
  border: 1px solid #dfe5ef;
  border-radius: 16px;
  background: #fff;
  box-shadow: 0 28px 80px rgb(16 24 40 / 28%);
}

.member-detail-overlay__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 24px;
  padding: 20px 26px 18px;
  border-bottom: 1px solid #eaecf0;
}

.member-detail-overlay__identity {
  min-width: 0;
}

.member-detail-overlay__eyebrow {
  color: #667085;
  font-size: 13px;
  line-height: 20px;
}

.member-detail-overlay__title-row {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 2px;
}

.member-detail-overlay__title-row h2 {
  margin: 0;
  color: #172033;
  font-size: 23px;
  line-height: 32px;
}

.member-detail-overlay__status,
.member-detail-overlay__record-status {
  display: inline-flex;
  align-items: center;
  width: fit-content;
  min-height: 24px;
  padding: 0 9px;
  border-radius: 999px;
  background: #eff8ff;
  color: #175cd3;
  font-size: 12px;
  font-weight: 600;
  line-height: 20px;
  white-space: nowrap;
}

.member-detail-overlay__facts {
  display: flex;
  align-items: baseline;
  flex-wrap: wrap;
  gap: 8px 20px;
  margin: 8px 0 0;
}

.member-detail-overlay__facts > div {
  display: flex;
  gap: 6px;
  min-width: 0;
}

.member-detail-overlay__facts dt,
.member-detail-overlay__facts dd {
  margin: 0;
  color: #667085;
  font-size: 13px;
  line-height: 20px;
}

.member-detail-overlay__facts dt {
  color: #98a2b3;
}

.member-detail-overlay__header-actions,
.member-detail-overlay__record-actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 6px;
}

.member-detail-overlay__header-actions {
  flex: 0 0 auto;
}

.member-detail-overlay__button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 34px;
  padding: 0 12px;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 600;
  line-height: 1;
  transition: background .16s ease, border-color .16s ease, color .16s ease;
}

.member-detail-overlay__button--secondary {
  border: 1px solid #d7e0ea;
  background: #fff;
  color: #344054;
}

.member-detail-overlay__button--secondary:hover:not(:disabled) {
  border-color: #b9d4ff;
  background: #f7fbff;
  color: #175cd3;
}

.member-detail-overlay__close {
  width: 34px;
  height: 34px;
  border: 0;
  border-radius: 8px;
  background: transparent;
  color: #667085;
  cursor: pointer;
  font-size: 28px;
  line-height: 30px;
}

.member-detail-overlay__close:hover:not(:disabled) {
  background: #f2f4f7;
  color: #344054;
}

.member-detail-overlay__stats {
  display: grid;
  grid-template-columns: repeat(8, minmax(104px, 1fr));
  overflow-x: auto;
  border-bottom: 1px solid #eaecf0;
  background: #fbfcfe;
}

.member-detail-overlay__stat {
  display: grid;
  align-content: center;
  gap: 4px;
  min-width: 104px;
  min-height: 68px;
  padding: 10px 13px;
  border-right: 1px solid #edf0f4;
}

.member-detail-overlay__stat:last-child {
  border-right: 0;
}

.member-detail-overlay__stat span {
  overflow: hidden;
  color: #98a2b3;
  font-size: 11px;
  line-height: 16px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.member-detail-overlay__metric-help {
  display: inline-block;
  width: 14px;
  height: 14px;
  margin-left: 4px;
  vertical-align: -1px;
}

.member-detail-overlay__metric-tooltip {
  position: fixed;
  z-index: 1400;
  width: max-content;
  max-width: 280px;
  transform: translateX(-50%);
  padding: 7px 10px;
  border-radius: 6px;
  background: #1f2937;
  color: #fff;
  font-size: 12px;
  line-height: 18px;
  pointer-events: none;
  white-space: normal;
}

.member-detail-overlay__stat strong {
  overflow: hidden;
  color: #1d2939;
  font-size: 14px;
  font-variant-numeric: tabular-nums;
  line-height: 20px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.member-detail-overlay__tabs {
  display: flex;
  gap: 4px;
  overflow-x: auto;
  padding: 0 24px;
  border-bottom: 1px solid #eaecf0;
  background: #fff;
}

.member-detail-overlay__query {
  display: flex;
  align-items: end;
  flex-wrap: wrap;
  gap: 10px;
  padding: 10px 24px;
  border-bottom: 1px solid #eaecf0;
  background: #fbfcfe;
}

.member-detail-overlay__query label {
  display: grid;
  gap: 4px;
  min-width: min(360px, 100%);
  color: #667085;
  font-size: 12px;
  line-height: 18px;
}

.member-detail-overlay__query .member-detail-overlay__date-range {
  min-width: 250px;
}

.member-detail-overlay__date-range > div {
  display: flex;
  align-items: center;
  gap: 6px;
}

.member-detail-overlay__date-range em {
  font-style: normal;
  color: #98a2b3;
}

.member-detail-overlay__query .member-detail-overlay__status-filter {
  min-width: 92px;
}

.member-detail-overlay__query input {
  width: 100%;
  height: 34px;
  padding: 0 10px;
  border: 1px solid #d0d5dd;
  border-radius: 6px;
  outline: none;
  color: #344054;
  font-size: 13px;
}

.member-detail-overlay__query select {
  height: 34px;
  padding: 0 28px 0 10px;
  border: 1px solid #d0d5dd;
  border-radius: 6px;
  background: #fff;
  color: #344054;
  font-size: 13px;
}

.member-detail-overlay__query input:focus {
  border-color: #84adf8;
  box-shadow: 0 0 0 3px rgb(45 120 231 / 12%);
}

.member-detail-overlay__tab {
  position: relative;
  min-width: max-content;
  padding: 15px 14px 13px;
  border: 0;
  background: transparent;
  color: #667085;
  cursor: pointer;
  font-size: 14px;
  line-height: 20px;
}

.member-detail-overlay__tab::after {
  position: absolute;
  right: 12px;
  bottom: 0;
  left: 12px;
  height: 2px;
  border-radius: 999px 999px 0 0;
  background: transparent;
  content: '';
}

.member-detail-overlay__tab:hover {
  color: #175cd3;
}

.member-detail-overlay__tab--active {
  color: #175cd3;
  font-weight: 700;
}

.member-detail-overlay__tab--active::after {
  background: #1890ff;
}

.member-detail-overlay__body {
  min-height: 0;
  overflow-x: auto;
  overflow-y: scroll;
  padding: 22px 24px 28px;
  background: #f8fafc;
  scrollbar-gutter: stable;
}

.member-detail-overlay__tab-content {
  display: grid;
  align-content: start;
  min-height: 100%;
  gap: 18px;
}

.member-detail-overlay__panel,
.member-detail-overlay__card-panel,
.member-detail-overlay__gift-panel {
  overflow: hidden;
  border: 1px solid #e4e7ec;
  border-radius: 12px;
  background: #fff;
}

.member-detail-overlay__card-panel {
  border-left: 4px solid #2d78e7;
  box-shadow: 0 2px 10px rgb(16 24 40 / 6%);
}

.member-detail-overlay__panel-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 18px;
}

.member-detail-overlay__panel--full {
  grid-column: 1 / -1;
}

.member-detail-overlay__panel > header,
.member-detail-overlay__card-header,
.member-detail-overlay__gift-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  min-height: 54px;
  padding: 14px 17px;
  border-bottom: 1px solid #edf0f4;
}

.member-detail-overlay__panel > header h3,
.member-detail-overlay__card-header h3 {
  margin: 0;
  color: #1d2939;
  font-size: 15px;
  line-height: 22px;
}

.member-detail-overlay__panel > header span {
  color: #98a2b3;
  font-size: 12px;
  line-height: 18px;
  text-align: right;
}

.member-detail-overlay__panel > header > div > span {
  display: block;
  color: #98a2b3;
  font-size: 12px;
  line-height: 18px;
}

.member-detail-overlay__info-grid,
.member-detail-overlay__card-meta,
.member-detail-overlay__gift-header dl {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 14px 18px;
  margin: 0;
  padding: 16px 18px 18px;
}

.member-detail-overlay__info-grid--custom {
  padding-top: 0;
}

.member-detail-overlay__archive-custom h4 {
  margin: 0 17px 12px;
  padding: 0;
  color: #1d2939;
  font-size: 15px;
  line-height: 22px;
}

.member-detail-overlay__info-grid > div,
.member-detail-overlay__card-meta > div,
.member-detail-overlay__gift-header dl > div {
  min-width: 0;
}

.member-detail-overlay__info-grid dt,
.member-detail-overlay__card-meta dt,
.member-detail-overlay__gift-header dt {
  margin-bottom: 4px;
  color: #98a2b3;
  font-size: 12px;
  line-height: 18px;
}

.member-detail-overlay__info-grid dd,
.member-detail-overlay__card-meta dd,
.member-detail-overlay__gift-header dd {
  margin: 0;
  overflow-wrap: anywhere;
  color: #344054;
  font-size: 13px;
  line-height: 20px;
}

.member-detail-overlay__card-header {
  align-items: flex-start;
}

.member-detail-overlay__card-title-row {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}

.member-detail-overlay__card-header > div > span {
  display: block;
  margin-top: 4px;
  color: #667085;
  font-size: 12px;
  line-height: 18px;
}

.member-detail-overlay__card-meta {
  grid-template-columns: repeat(6, minmax(0, 1fr));
  padding-bottom: 14px;
}

.member-detail-overlay__gift-header {
  align-items: flex-start;
}

.member-detail-overlay__gift-header dl {
  flex: 1;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  padding: 0;
}

.member-detail-overlay__table-wrap {
  overflow-x: auto;
}

.member-detail-overlay__table {
  width: 100%;
  min-width: 720px;
  border-collapse: collapse;
  color: #344054;
  font-size: 13px;
  line-height: 20px;
}

.member-detail-overlay__table--wide {
  min-width: 1080px;
}

.member-detail-overlay__table th,
.member-detail-overlay__table td {
  padding: 11px 14px;
  border-bottom: 1px solid #edf0f4;
  text-align: left;
  vertical-align: top;
}

.member-detail-overlay__table th {
  background: #fbfcfe;
  color: #667085;
  font-size: 12px;
  font-weight: 600;
  white-space: nowrap;
}

.member-detail-overlay__table tbody tr:last-child td {
  border-bottom: 0;
}

.member-detail-overlay__table td {
  overflow-wrap: anywhere;
}

.member-detail-overlay__table td small {
  display: block;
  margin-top: 3px;
  color: #98a2b3;
  font-size: 12px;
  line-height: 18px;
}

.member-detail-overlay__money {
  color: #175cd3;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.member-detail-overlay__table-empty,
.member-detail-overlay__empty-inline {
  padding: 24px 18px;
  color: #98a2b3;
  font-size: 13px;
  line-height: 20px;
  text-align: center;
}

.member-detail-overlay__empty-inline--page {
  border: 1px dashed #d0d5dd;
  border-radius: 12px;
  background: #fff;
}

.member-detail-overlay__inline-action,
.member-detail-overlay__source-link {
  min-height: 28px;
  padding: 0 7px;
  border: 0;
  border-radius: 6px;
  background: transparent;
  color: #175cd3;
  cursor: pointer;
  font-size: 12px;
  font-weight: 600;
  line-height: 20px;
  text-align: left;
}

.member-detail-overlay__inline-action:hover:not(:disabled),
.member-detail-overlay__source-link:hover:not(:disabled) {
  background: #eff8ff;
}

.member-detail-overlay__inline-action:disabled,
.member-detail-overlay__source-link:disabled {
  color: #98a2b3;
}

.member-detail-overlay__source-link {
  min-height: auto;
  padding: 0;
  text-decoration: underline;
  text-decoration-color: #b2ddff;
  text-underline-offset: 2px;
}

.member-detail-overlay__loading,
.member-detail-overlay__empty {
  display: grid;
  min-height: 260px;
  place-content: center;
  justify-items: center;
  gap: 9px;
  color: #667085;
  font-size: 14px;
}

.member-detail-overlay__empty strong {
  color: #344054;
  font-size: 16px;
}

.member-detail-overlay__loading-dot {
  width: 22px;
  height: 22px;
  border: 3px solid #dbeafe;
  border-top-color: #1890ff;
  border-radius: 999px;
  animation: member-detail-overlay-spin .78s linear infinite;
}

.member-detail-overlay__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  min-height: 60px;
  gap: 18px;
  padding: 12px 24px;
  border-top: 1px solid #eaecf0;
  background: #fff;
}

.member-detail-overlay__footer .member-detail-overlay__button {
  margin-left: auto;
}

.member-detail-overlay__error {
  margin: 0;
  color: #b42318;
  font-size: 13px;
  line-height: 20px;
}

@keyframes member-detail-overlay-spin {
  to { transform: rotate(360deg); }
}

@media (max-width: 1240px) {
  .member-detail-overlay__stats {
    grid-template-columns: repeat(8, minmax(128px, 1fr));
  }

  .member-detail-overlay__card-meta {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}

@media (max-width: 1040px) {
  .member-detail-overlay {
    padding: 12px;
  }

  .member-detail-overlay__page {
    height: calc(100vh - 24px);
    max-height: calc(100vh - 24px);
  }

  .member-detail-overlay__header {
    gap: 14px;
    padding: 14px 18px 12px;
  }

  .member-detail-overlay__header-actions {
    max-width: 44%;
  }

  .member-detail-overlay__facts {
    gap: 4px 14px;
    margin-top: 5px;
  }

  .member-detail-overlay__stat {
    min-height: 58px;
    padding: 7px 10px;
  }

  .member-detail-overlay__tabs {
    padding: 0 16px;
  }

  .member-detail-overlay__tab {
    padding: 11px 10px 9px;
  }

  .member-detail-overlay__body {
    padding: 16px 18px 22px;
  }

  .member-detail-overlay__panel-grid {
    grid-template-columns: 1fr;
    gap: 14px;
  }

  .member-detail-overlay__info-grid,
  .member-detail-overlay__gift-header dl,
  .member-detail-overlay__card-meta {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .member-detail-overlay__footer {
    min-height: 54px;
    padding: 9px 18px;
  }
}
</style>

const ENTITLEMENT_CONTEXT_KIND_ORDER = Object.freeze([
  'cashier_workspace',
  'member',
  'card_holder',
  'member_benefit_pool'
])

function nonEmpty(value) {
  const normalized = String(value ?? '').trim()
  return normalized || ''
}

function positiveVersion(value) {
  const normalized = Number(value)
  return Number.isSafeInteger(normalized) && normalized > 0 ? normalized : null
}

function contextKey(kind, id) {
  return `${kind}:${nonEmpty(id)}`
}

export function entitlementCardHolderId(source = {}) {
  return nonEmpty(
    source.cardHolderId
    ?? source.card_holder_id
    ?? source.entitlementInstanceId
    ?? source.id
  )
}

export function entitlementBenefitPoolId(project = {}) {
  return nonEmpty(
    project.memberBenefitPoolId
    ?? project.member_benefit_pool_id
    ?? project.entitlementSourceDetailId
    ?? project.sourceDetailId
    ?? project.id
  )
}

export function normalizeEntitlementCommandContext(context = {}) {
  const kind = nonEmpty(context.kind)
  const id = nonEmpty(context.id)
  const expectedVersion = positiveVersion(context.expectedVersion ?? context.expected_version ?? context.revision)
  if (!kind || !id || expectedVersion === null) return null
  return { kind, id, expectedVersion }
}

/**
 * 加卡内项目只提交本次确实依赖的既有资源。服务端返回的其他候选权益版本
 * 不能跟着选中行一起发送，否则未选权益也会被无谓锁定并制造并发冲突。
 */
export function canonicalEntitlementCommandContexts({
  suppliedContexts = [],
  selectedLines = [],
  workspaceId,
  memberId
} = {}) {
  const normalizedWorkspaceId = nonEmpty(workspaceId)
  const normalizedMemberId = nonEmpty(memberId)
  if (!normalizedWorkspaceId || !normalizedMemberId || !Array.isArray(selectedLines) || !selectedLines.length) return null

  const cardHolderIds = new Set()
  const benefitPoolIds = new Set()
  for (const line of selectedLines) {
    const cardHolderId = entitlementCardHolderId(line)
    const benefitPoolId = entitlementBenefitPoolId(line)
    if (!cardHolderId || !benefitPoolId) return null
    cardHolderIds.add(cardHolderId)
    benefitPoolIds.add(benefitPoolId)
  }

  const byKey = new Map()
  for (const supplied of Array.isArray(suppliedContexts) ? suppliedContexts : []) {
    const context = normalizeEntitlementCommandContext(supplied)
    if (!context || !ENTITLEMENT_CONTEXT_KIND_ORDER.includes(context.kind)) continue
    const key = contextKey(context.kind, context.id)
    const previous = byKey.get(key)
    if (previous && previous.expectedVersion !== context.expectedVersion) return null
    byKey.set(key, context)
  }

  const required = [
    ['cashier_workspace', normalizedWorkspaceId],
    ['member', normalizedMemberId],
    ...Array.from(cardHolderIds).sort().map((id) => ['card_holder', id]),
    ...Array.from(benefitPoolIds).sort().map((id) => ['member_benefit_pool', id])
  ]
  const contexts = required.map(([kind, id]) => byKey.get(contextKey(kind, id)))
  return contexts.every(Boolean) ? contexts : null
}

export function cashierEntitlementScopeKey({
  stateContextId,
  storeId,
  workspaceId,
  workspaceVersion,
  customerMode,
  memberId
} = {}) {
  return [
    nonEmpty(stateContextId),
    nonEmpty(storeId),
    nonEmpty(workspaceId),
    positiveVersion(workspaceVersion) ?? '',
    nonEmpty(customerMode),
    nonEmpty(memberId)
  ].join('|')
}

/**
 * Selecting a member is the only customer-scope transition that may retain an
 * outstanding "open entitlements" intent. Workspace revisions normally
 * advance during member selection, so they are deliberately not part of this
 * stable identity check.
 */
export function shouldPreservePendingEntitlementSelector({
  pending = false,
  current = {},
  previous = {}
} = {}) {
  if (pending !== true) return false

  const stableIdentityKeys = ['stateContextId', 'storeId', 'workspaceId']
  const sameStableWorkspace = stableIdentityKeys.every((key) => {
    const currentValue = nonEmpty(current?.[key])
    const previousValue = nonEmpty(previous?.[key])
    return Boolean(currentValue) && currentValue === previousValue
  })
  if (!sameStableWorkspace) return false

  return nonEmpty(previous?.customerMode) === 'guest'
    && !nonEmpty(previous?.memberId)
    && nonEmpty(current?.customerMode) === 'member'
    && Boolean(nonEmpty(current?.memberId))
}

export function responseDataBlock(response = {}) {
  if (!response || typeof response !== 'object') return {}
  if (response.result && typeof response.result === 'object') {
    return response.data && typeof response.data === 'object' ? response.data : {}
  }
  const nested = response.data
  if (nested && typeof nested === 'object' && nested.result && typeof nested.result === 'object') {
    return nested.data && typeof nested.data === 'object' ? nested.data : {}
  }
  // ThinkPHP transports the standard V3 envelope below its own `data` key.
  // Projection responses use `result`; command responses may expose their
  // status directly on that envelope, while retaining the business payload in
  // its nested `data` field.
  if (nested && typeof nested === 'object' && typeof nested.status === 'string') {
    return nested.data && typeof nested.data === 'object' ? nested.data : {}
  }
  return {}
}

export function isCompleteCheckoutCompositionContract(composition, lines = []) {
  if (!composition || typeof composition !== 'object' || Array.isArray(composition) || !Array.isArray(lines)) return false
  if (!Array.isArray(composition.lineRoles) || !Array.isArray(composition.steps)) return false

  const actualRoles = [...new Set(lines.map((line) => String(line?.lineRole || '')))].sort()
  if (actualRoles.some((role) => !['sale', 'entitlement_service'].includes(role))) return false
  const rawDeclaredRoles = composition.lineRoles.map((role) => String(role))
  const declaredRoles = [...new Set(rawDeclaredRoles)].sort()
  if (rawDeclaredRoles.length !== declaredRoles.length
    || rawDeclaredRoles.some((role) => !['sale', 'entitlement_service'].includes(role))) return false
  if (actualRoles.join('|') !== declaredRoles.join('|')) return false

  const hasSale = actualRoles.includes('sale')
  const hasEntitlement = actualRoles.includes('entitlement_service')
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
  const expectedSteps = primaryAction
    ? [
        { key: 'order', number: 1, label: hasEntitlement ? '确认本次内容' : '确认订单' },
        ...(hasSale ? [{ key: 'payment', number: 2, label: '收款信息' }] : []),
        { key: 'final', number: 3, label: primaryActionLabel },
        { key: 'result', number: 4, label: '处理结果' }
      ]
    : []

  return composition.hasSale === hasSale
    && composition.hasEntitlement === hasEntitlement
    && composition.primaryAction === primaryAction
    && composition.primaryActionLabel === primaryActionLabel
    && composition.steps.length === expectedSteps.length
    && composition.steps.every((step, index) => (
      step
      && typeof step === 'object'
      && !Array.isArray(step)
      && step.key === expectedSteps[index].key
      && Number(step.number) === expectedSteps[index].number
      && step.label === expectedSteps[index].label
    ))
}

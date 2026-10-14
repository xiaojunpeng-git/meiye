/**
 * 在打开结账前按已售卡的组规则检查本次购物车；这里只拦明显超出的选择，
 * 最终扣次仍由服务端事务锁定已售卡状态复核。
 */
export function combinationChoicePreflightFailure(lines = [], sources = []) {
  const projects = new Map()
  for (const source of sources || []) {
    const holderId = String(source?.entitlementInstanceId || source?.cardHolderId || source?.id || '')
    for (const project of source?.projects || []) {
      const detailId = String(project?.entitlementSourceDetailId || project?.memberBenefitPoolId || project?.sourceDetailId || project?.id || '')
      if (holderId && detailId) projects.set(`${holderId}:${detailId}`, project)
    }
  }

  const groups = new Map()
  for (const line of lines || []) {
    if (line?.lineRole !== 'entitlement_service') continue
    const holderId = String(line.entitlementInstanceId || line.cardHolderId || '')
    const detailId = String(line.entitlementSourceDetailId || line.memberBenefitPoolId || '')
    const snapshot = line.displaySnapshot || {}
    // 旧的未结账草稿没有组合字段时，使用当前页面已读取的权益选择结果补齐；
    // 不为前置提示再次请求服务端，也不拿现售商品配置改写已售卡规则。
    const project = projects.get(`${holderId}:${detailId}`) || {}
    const field = (name) => snapshot[name] ?? project[name]
    if (field('cardRuleType') !== 'combination') continue

    const cardName = String(line.entitlementSourceName || snapshot.entitlementSourceName || '当前组合卡').trim()
    const groupRule = String(field('combinationGroupRule') || '')
    // 已识别为组合卡却没有组规则时，不能按普通卡放行；完整组规则仍由服务端负责最终核销。
    if (!groupRule) {
      return {
        code: 'CHECKOUT_COMBINATION_RULE_INCOMPLETE',
        message: `「${cardName}」的组合使用规则未完整加载，请重新打开“使用权益”后选择项目。`
      }
    }
    if (groupRule !== 'choice_kind') continue

    const groupNo = Number(field('combinationGroupNo'))
    const limit = Number(field('combinationChoiceLimit'))
    const selectedCount = Number(field('combinationSelectedKindCount'))
    const status = String(field('combinationSelectionStatus') || '')
    if (!holderId || !detailId || !Number.isInteger(groupNo) || groupNo <= 0
      || !Number.isInteger(limit) || limit <= 0 || !Number.isInteger(selectedCount)
      || selectedCount < 0 || !['candidate', 'selected'].includes(status)) {
      return {
        code: 'CHECKOUT_COMBINATION_RULE_INCOMPLETE',
        message: `「${cardName}」的组合使用规则未完整加载，请重新打开“使用权益”后选择项目。`
      }
    }
    const key = `${holderId}:${groupNo}`
    const current = groups.get(key) || {
      cardName,
      groupName: String(field('combinationGroupName') || `组合${groupNo}`).trim(),
      limit,
      selectedCount,
      candidates: new Map()
    }
    if (current.limit !== limit || current.selectedCount !== selectedCount) {
      return {
        code: 'CHECKOUT_COMBINATION_RULE_INCONSISTENT',
        message: `「${cardName}」的组合使用规则已更新，请重新打开“使用权益”后选择项目。`
      }
    }
    if (status === 'candidate') {
      current.candidates.set(detailId, String(line.name || snapshot.name || project.name || '未命名项目').trim())
    }
    groups.set(key, current)
  }

  for (const group of groups.values()) {
    const candidateCount = group.candidates.size
    const excess = group.selectedCount + candidateCount - group.limit
    if (excess <= 0) continue
    const names = [...group.candidates.values()].map((name) => `「${name}」`).join('、')
    const already = group.selectedCount > 0 ? `此前已选 ${group.selectedCount} 种，` : ''
    return {
      code: 'CHECKOUT_COMBINATION_CHOICE_LIMIT_EXCEEDED',
      message: `卡「${group.cardName}」的「${group.groupName}」最多可选 ${group.limit} 种项目。${already}本次选了 ${candidateCount} 种：${names}。请删除其中 ${excess} 种后再确认完成服务。`
    }
  }
  return null
}

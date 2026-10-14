import assert from 'node:assert/strict'
import { combinationChoicePreflightFailure } from '../../../前端代码/cashier-v3/src/services/cashierV3CombinationChoicePreflight.js'

const project = (detailId, name, groupNo, overrides = {}) => ({
  lineRole: 'entitlement_service',
  entitlementInstanceId: 'holder-5161025',
  entitlementSourceDetailId: detailId,
  entitlementSourceName: '组合卡本地验收',
  name,
  displaySnapshot: {
    cardRuleType: 'combination',
    combinationGroupNo: groupNo,
    combinationGroupName: groupNo === 1 ? '组合1 面部任选' : '组合2 护理任选',
    combinationGroupRule: 'choice_kind',
    combinationChoiceLimit: 1,
    combinationSelectedKindCount: 0,
    combinationSelectionStatus: 'candidate',
    ...overrides
  }
})

// 真实失败场景：同一任选 1 种的组里放两种项目，按钮应直接说明删哪种。
const exceeded = combinationChoicePreflightFailure([
  project('detail-1', '指尖胶原', 1),
  project('detail-2', '康养+幸福霜', 1)
])
assert.equal(exceeded?.code, 'CHECKOUT_COMBINATION_CHOICE_LIMIT_EXCEEDED')
assert.match(exceeded.message, /组合1 面部任选.*最多可选 1 种项目/)
assert.match(exceeded.message, /指尖胶原.*康养\+幸福霜.*删除其中 1 种/)

// 两组各选一种、同一项目拆成多行，以及已选项目重复使用，都不占第二个种类名额。
assert.equal(combinationChoicePreflightFailure([
  project('detail-1', '指尖胶原', 1),
  project('detail-3', '面部清洁', 2)
]), null)
assert.equal(combinationChoicePreflightFailure([
  project('detail-1', '指尖胶原', 1),
  project('detail-1', '指尖胶原', 1)
]), null)
assert.equal(combinationChoicePreflightFailure([
  project('detail-1', '指尖胶原', 1, {
    combinationSelectedKindCount: 1,
    combinationSelectionStatus: 'selected'
  })
]), null)

// 刷新前加入的旧草稿由当前权益投影补足组信息，仍需在进入结账前拦截。
const oldLine = (id, name) => ({
  lineRole: 'entitlement_service',
  entitlementInstanceId: 'holder-5161025',
  entitlementSourceDetailId: id,
  entitlementSourceName: '组合卡本地验收',
  name,
  displaySnapshot: { name }
})
const sources = [{
  id: 'holder-5161025',
  projects: [
    { ...project('detail-1', '指尖胶原', 1).displaySnapshot, id: 'detail-1' },
    { ...project('detail-2', '康养+幸福霜', 1).displaySnapshot, id: 'detail-2' }
  ]
}]
assert.equal(combinationChoicePreflightFailure([
  oldLine('detail-1', '指尖胶原'), oldLine('detail-2', '康养+幸福霜')
], sources)?.code, 'CHECKOUT_COMBINATION_CHOICE_LIMIT_EXCEEDED')

// 已识别为组合卡却缺少签发组状态时，不能假装规则有效并进入结账。
assert.equal(combinationChoicePreflightFailure([
  project('detail-1', '指尖胶原', 1, { combinationSelectionStatus: '' })
])?.code, 'CHECKOUT_COMBINATION_RULE_INCOMPLETE')
assert.equal(combinationChoicePreflightFailure([
  project('detail-1', '指尖胶原', 1, { combinationGroupRule: '' })
])?.code, 'CHECKOUT_COMBINATION_RULE_INCOMPLETE')

console.log('COMBINATION_CHOICE_PREFLIGHT=PASS checks=7')

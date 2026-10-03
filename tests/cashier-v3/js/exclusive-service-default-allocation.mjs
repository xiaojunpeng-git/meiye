import assert from 'node:assert/strict'
import {
  defaultCraftsmanWeights,
  defaultProjectCounts,
  defaultSalespersonWeights
} from '../../../前端代码/cashier-v3/src/services/personnelDefaultAllocation.js'

// 会员预选只生成可编辑草稿；与收银弹窗共用普通组、独立组和劳动岗口径。
const craftsmen = [
  { staffId: 672, selected: true, role: 'craftsmen', craftsmanPerformanceType: 'commission_labor' },
  { staffId: 728, selected: true, role: 'craftsmen', craftsmanPerformanceType: 'commission_labor' }
]
defaultCraftsmanWeights(craftsmen)
defaultProjectCounts(craftsmen, 1)
assert.deepEqual(craftsmen.map(({ performance, projectCountText }) => [performance, projectCountText]), [
  [50, '0.5'], [50, '0.5']
])

const salespeople = [
  { staffId: 672, selected: true, role: 'salespeople' },
  { staffId: 728, selected: true, role: 'salespeople' }
]
defaultSalespersonWeights(salespeople)
assert.deepEqual(salespeople.map(({ performance }) => performance), [50, 50])

const mixed = [
  { staffId: 1, selected: true, role: 'craftsmen', craftsmanPerformanceType: 'labor' },
  { staffId: 2, selected: true, role: 'craftsmen', craftsmanPerformanceType: 'commission' }
]
defaultCraftsmanWeights(mixed)
assert.equal(mixed[0].performance, undefined)
assert.equal(mixed[1].performance, 100)

// 两人预选后再添一位普通岗位，旧自动项目数不能伪装成人工编辑而留下 1/0。
const expanded = [
  { staffId: 523, selected: true, role: 'craftsmen', allocationGroupKey: 'independent:67', projectCountText: '1', projectCountTouched: true },
  { staffId: 923, selected: true, role: 'craftsmen', allocationGroupKey: 'normal', projectCountText: '1', projectCountTouched: true },
  { staffId: 1148, selected: true, role: 'craftsmen', allocationGroupKey: 'normal', projectCountText: '0', projectCountTouched: false }
]
defaultProjectCounts(expanded, 1)
assert.deepEqual(expanded.map(({ projectCountText }) => projectCountText), ['1', '0.5', '0.5'])

// 已人工设为非默认比例的组不因增人而被自动分配覆盖。
expanded[1].projectCountText = '0.7'
expanded[2].selected = false
defaultProjectCounts(expanded, 1)
assert.equal(expanded[1].projectCountText, '0.7')

// 服务记录调整只在增减人员后触发同一规则；一位独立岗位不参与普通组三人均分。
const historyExpanded = [
  { staffId: 523, selected: true, role: 'craftsmen', allocationGroupKey: 'independent:67', projectCountText: '1', projectCountTouched: true },
  { staffId: 923, selected: true, role: 'craftsmen', allocationGroupKey: 'normal', projectCountText: '0.5', projectCountTouched: true },
  { staffId: 1148, selected: true, role: 'craftsmen', allocationGroupKey: 'normal', projectCountText: '0.5', projectCountTouched: true },
  { staffId: 1320, selected: true, role: 'craftsmen', allocationGroupKey: 'normal', projectCountText: '0', projectCountTouched: false }
]
defaultProjectCounts(historyExpanded, 1, true)
assert.deepEqual(historyExpanded.map(({ projectCountText }) => projectCountText), ['1', '0.3', '0.3', '0.4'])

// 旧版六位小数自动草稿再次增人时，也要切回一位小数并把尾差给最后一人。
historyExpanded[1].projectCountText = '0.333333'
historyExpanded[2].projectCountText = '0.333333'
historyExpanded[3].projectCountText = '0.333334'
historyExpanded[3].projectCountTouched = true
historyExpanded.push({ staffId: 1400, selected: true, role: 'craftsmen', allocationGroupKey: 'normal', projectCountText: '0', projectCountTouched: false })
defaultProjectCounts(historyExpanded, 1, true)
assert.deepEqual(historyExpanded.map(({ projectCountText }) => projectCountText), ['1', '0.2', '0.2', '0.3', '0.3'])

// 旧草稿把新人的 0 也标成“已有值”时，选人仍应重分；真正手填则保留。
const reopened = historyExpanded.slice(0, 4).map((record, index) => ({
  ...record, projectCountText: index === 0 ? '1' : index < 3 ? '0.5' : '0', projectCountTouched: true
}))
defaultProjectCounts(reopened, 1, true)
assert.deepEqual(reopened.map(({ projectCountText }) => projectCountText), ['1', '0.3', '0.3', '0.4'])
reopened[1].projectCountText = '0.7'
reopened[1].projectCountManuallyEdited = true
defaultProjectCounts(reopened, 1, true)
assert.deepEqual(reopened.slice(1).map(({ projectCountText }) => projectCountText), ['0.7', '0.3', '0.4'])

console.log('PASS exclusive-service-default-allocation')

import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { createRequire } from 'node:module'
import vm from 'node:vm'

// Execute the actual computed properties and permission helper from one fixed Git tree.
// No DOM text substitution: changing either authority must invalidate the same Vue computation.
const tree = process.env.R13_SOURCE_TREE
assert.match(tree || '', /^[0-9a-f]{40}$/)
const require = createRequire(new URL('../../../前端代码/cashier-v3/package.json', import.meta.url))
const { reactive, computed, ref } = require('vue')
const read = path => execFileSync('git', ['show', `${tree}:${path}`], { encoding: 'utf8', maxBuffer: 8e6 })
const workbench = read('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue')
const bridge = read('前端代码/cashier-v3/src/services/cashierV3Bridge.js')
const helper = bridge.slice(bridge.indexOf('export function canUseCashierV3Operation('), bridge.indexOf('const CARD_OPERATION_FEATURE_BY_TYPE')).replace('export ', '')
const gates = workbench.slice(workbench.indexOf('const isCustomCardEntryVisible ='), workbench.indexOf('function catalogTypeDisplayLabel('))
const state = reactive({ operationPermissions: {} })
const catalog = ref({ types: ['卡项', '定制卡', '项目', '产品'] })
const preview = ref(null)
const context = vm.createContext({ computed, cashierV3State: state, catalog, previewCardOperation: preview,
  preferredCatalogTypeOrder: ['卡项', '定制卡', '项目', '产品'], defaultTypes: ['项目', '产品'] })
vm.runInContext(helper + gates + '; this.visible = isCustomCardEntryVisible; this.types = productTypes;', context)
let checks = 0
const check = (name, pass) => { assert.ok(pass, name); checks++ }
const key = 'cashier.v3.cashier.custom_card_entry'
check('缺少岗位授权不显示定制卡', !context.visible.value && !context.types.value.includes('定制卡'))
state.operationPermissions[key] = false
check('明确关闭岗位选项不显示', !context.visible.value && !context.types.value.includes('定制卡'))
check('关闭只裁剪定制卡入口', Array.from(context.types.value).join(',') === '卡项,项目,产品')
state.operationPermissions[key] = true
check('总开关和岗位选项均开时显示', context.visible.value && context.types.value.filter(t => t === '定制卡').length === 1)
catalog.value = { types: ['卡项', '项目', '产品'] }
check('岗位授权不能覆盖系统关闭', !context.visible.value && !context.types.value.includes('定制卡'))
catalog.value = { types: ['卡项', '定制卡', '项目', '产品'] }
state.operationPermissions[key] = false
check('岗位权限刷新后即时隐藏旧入口', !context.visible.value && !context.types.value.includes('定制卡'))
state.operationPermissions[key] = 'true'
check('非布尔权限不误当作已授权', !context.visible.value)
preview.value = { awaitingTarget: true, mode: 'card-upgrade' }
check('卡升级的定制卡创建按钮也复用同一隐藏结果', !context.visible.value && Array.from(context.types.value).join(',') === '卡项')
preview.value = null
// Preserve the other already-published button from the same historical change, independently.
const editExpression = workbench.match(/v-if="(pendingCardPurchase\.ordinaryCardSaleEditable[^"\n]*ordinary_card_edit[^"\n]*)"/)[1]
context.pendingCardPurchase = { ordinaryCardSaleEditable: true, cardPreview: { ruleType: 'normal' } }
state.operationPermissions['cashier.v3.cashier.ordinary_card_edit'] = true
check('定制卡关闭不影响已授权普通卡修改', vm.runInContext(editExpression, context))
state.operationPermissions['cashier.v3.cashier.ordinary_card_edit'] = false
check('普通卡修改关闭时仍按独立岗位选择隐藏', !vm.runInContext(editExpression, context))
console.log(JSON.stringify({ result: 'PASS', tree, checks }))

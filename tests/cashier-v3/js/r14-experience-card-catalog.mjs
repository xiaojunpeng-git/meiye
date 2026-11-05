import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { createRequire } from 'node:module'
import vm from 'node:vm'

// Run the real Vue selection/filter code from the fixed source tree. Catalog identity
// stays a card; this test protects display grouping without introducing a sale type.
const tree = process.env.R14_SOURCE_TREE
assert.match(tree || '', /^[a-f0-9]{40}$/)
const require = createRequire(new URL('../../../前端代码/cashier-v3/package.json', import.meta.url))
const { computed, ref, watch, nextTick } = require('vue')
const source = execFileSync('git', ['show', `${tree}:前端代码/cashier-v3/src/views/CashierWorkbenchView.vue`], { encoding: 'utf8' })
const start = source.indexOf('const isCustomCardEntryVisible =')
const end = source.indexOf('function catalogItemSelectedQuantity(')
const catalog = ref({ types: ['卡项', '定制卡', '项目', '产品'], items: [] })
const preview = ref(null), selected = ref('卡项'), category = ref(''), keyword = ref(''), mode = ref('member')
let granted = true, messages = 0
const context = vm.createContext({ computed, watch, catalog, previewCardOperation: preview,
  selectedType: selected, selectedCategory: category, keyword, currentCustomerMode: mode,
  preferredCatalogTypeOrder: ['卡项', '体验卡', '定制卡', '项目', '产品'], defaultTypes: ['项目', '产品'],
  canUseCashierV3Operation: () => granted, selectCatalogItem: () => {},
  window: { dispatchEvent: () => { messages++ } }, CustomEvent: class {} })
vm.runInContext(source.slice(start, end) + '; this.types=productTypes; this.filtered=filteredCatalogItems;', context)
let checks = 0
const check = (ok, label) => { assert.ok(ok, label); checks++ }
const ids = () => Array.from(context.filtered.value, i => i.id).join(',')
check(!context.types.value.includes('体验卡'), '无体验卡时不显示入口')
catalog.value.items = [
  { id: 1, kind: '卡项', cardStatisticsType: 0, name: '名字带体验卡的普通卡', category: 'A' },
  { id: 2, kind: '卡项', cardStatisticsType: 1, name: '配置为体验类型', category: 'A', code: 'T2' },
  { id: 3, kind: '卡项', cardStatisticsType: 1, name: '第二张', category: 'B' },
  { id: 4, kind: '项目', cardStatisticsType: 0, name: '项目' },
  { id: 'custom-card-entry', kind: '定制卡', name: '新建定制卡' }
]
await nextTick()
check(Array.from(context.types.value).join(',') === '卡项,体验卡,定制卡,项目,产品', '体验卡紧邻疗程卡右侧')
check(ids() === '1', '疗程卡排除体验卡且不按名称猜测')
context.selectCatalogType('体验卡')
check(ids() === '2,3', '体验入口只显示配置为体验卡的卡项')
category.value = 'A'; check(ids() === '2', '体验卡仍与商品分类取交集')
keyword.value = 't2'; check(ids() === '2', '体验卡支持编号搜索')
keyword.value = '第二'; check(ids() === '', '搜索不能带入其他分类')
check(context.types.value.includes('体验卡'), '搜索无结果不隐藏入口')
category.value = ''; keyword.value = ''
granted = false; catalog.value = { ...catalog.value }
check(!context.types.value.includes('定制卡'), '保留上轮定制卡岗位隐藏')
mode.value = 'guest'; selected.value = '项目'; context.selectCatalogType('体验卡')
check(selected.value === '项目' && messages === 1, '游客购买体验卡仍需会员')
mode.value = 'member'; preview.value = { awaitingTarget: true, mode: 'card-upgrade' }
check(Array.from(context.types.value).join(',') === '卡项', '升级仍沿用原目标入口')
check(ids() === '1,2,3,custom-card-entry', '升级目标不会因售卡分组缩小')
preview.value = null; selected.value = '体验卡'; catalog.value.items = [catalog.value.items[0]]
await nextTick()
check(selected.value === '卡项' && !context.types.value.includes('体验卡'), '目录无体验卡后回到疗程卡')
console.log(JSON.stringify({ result: 'PASS', tree, checks }))

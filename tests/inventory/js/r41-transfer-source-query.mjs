/** 回归采购仓草稿被隐藏总部仓条件排除；验证最终 HTTP 参数及相邻范围不串仓。 */
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import vm from 'node:vm'
import { platformTransferQueryScope } from '../../../前端代码/inventory-vue3/src/transferQueryScope.js'
import { createPlatformInventoryApi } from '../../../前端代码/inventory-vue3/src/services/inventoryApi.js'

const app = readFileSync(new URL('../../../前端代码/inventory-vue3/src/App.vue', import.meta.url), 'utf8')
assert.match(app, /Object\.assign\(query, platformTransferQueryScope\(platformTransferFromParty\.value, platformTransferToParty\.value, platformHqLocationId\.value\)\)/)
const urls = []
const api = createPlatformInventoryApi({ fetchImpl: async (url) => {
  urls.push(url)
  return { ok: true, status: 200, text: async () => JSON.stringify({ status: 200, data: { count: 0, list: [] } }) }
} })

// 同一构造路径覆盖初次筛选、保存后的目录刷新，以及切回原总部仓。
for (const [from, fallback, expected] of [['HQ:129', 128, 129], ['HQ:129', 133, 129], ['HQ:128', 129, 128]]) {
  const scope = platformTransferQueryScope(from, 'ALL:0', fallback)
  const payload = { hq_location_id: fallback, keyword: 'DB2610280004', page: 1, limit: 100, ...scope,
    status: 'DRAFT', business_date_from: '2026-10-01', business_date_to: '2026-10-31' }
  await api.listHqCrossTransfers(payload)
  const params = new URL(urls.at(-1), 'http://localhost').searchParams
  assert.equal(params.get('hq_location_id'), String(expected))
  assert.equal(params.get('from_location_id'), String(expected))
  assert.equal(params.get('keyword'), 'DB2610280004')
  assert.equal(params.get('status'), 'DRAFT')
  assert.equal(params.get('business_date_from'), '2026-10-01')
  assert.equal(params.get('business_date_to'), '2026-10-31')
  // 翻页重放已提交条件，不能被尚未查询的其他仓库输入覆盖。
  const frozen = structuredClone(payload)
  platformTransferQueryScope('HQ:130', 'HQ:133', 130)
  await api.listHqCrossTransfers({ ...frozen, page: 2 })
  const next = new URL(urls.at(-1), 'http://localhost').searchParams
  assert.equal(next.get('hq_location_id'), String(expected))
  assert.equal(next.get('from_location_id'), String(expected))
  assert.equal(next.get('page'), '2')
}
assert.deepEqual(platformTransferQueryScope('STORE:7', 'HQ:129', 128), {
  hq_location_id: 128, from_party_type: 'STORE', from_party_id: 7,
  to_party_type: 'HQ', to_party_id: 0, to_location_id: 129
})
assert.deepEqual(platformTransferQueryScope('ALL:0', 'STORE:1', 128), {
  hq_location_id: 128, to_party_type: 'STORE', to_party_id: 1
})
assert.deepEqual(platformTransferQueryScope('HQ:129', 'HQ:130', 128), {
  hq_location_id: 129, from_party_type: 'HQ', from_party_id: 0, from_location_id: 129,
  to_party_type: 'HQ', to_party_id: 0, to_location_id: 130
})

// 执行 App 原函数，不复制列表逻辑：模拟保存后目录刷新回旧仓库，验证单据仍能回显。
const ref = (value) => ({ value })
const calls = []
const fixture = { id: 1124, order_sn: 'DB2610280004', document_status: 'DRAFT', from_location_id: 129 }
const sandbox = {
  platformTransferQueryScope, mode: ref('platform'), active: ref('transfer'), keyword: ref(''), editor: ref(''),
  platformTransferCanCreate: ref(false), selectedTransferIds: ref([]), transferBatchError: ref(''),
  groupedListPages: new Set(['transfer']), groupedListVersion: 0,
  listLoading: ref(false), listError: ref(''), platformReadScopeMode: ref(false), platformListWarehouseOptions: ref([]),
  platformListWarehouseSelection: ref('HQ:128'), platformHqLocationId: ref(128),
  platformTransferFromParty: ref('ALL:0'), platformTransferToParty: ref('ALL:0'), platformTransferStatus: ref(''),
  platformListPage: ref(1), platformListPageSize: 100, platformListExecutedRequest: ref(null),
  operationalQueryPage: ref(null), businessDateRange: ref({ min: '2026-10-01', max: '2026-10-31' }),
  countDateRange: ref({}), outboundTypeFilter: ref(''), storeTransferUnreceivedOnly: ref(false),
  transferSavedNotice: ref(''), transferListRefreshFailed: ref(false), transferRefreshAfterSave: ref(false),
  transferListRefreshReason: ref(''), storeListTableScroll: ref(null), sourceRows: ref([]), remoteRows: ref([]),
  remoteTotal: ref(0), listDateRange: ref(null), inventoryApi: {}, platformInventoryApi: {},
  isExplicitListQuery: (value) => value !== null && typeof value === 'object',
  filtersBusinessDatePeriod: () => true, usesBusinessDatePeriod: () => true, operationalStatusFilterValue: () => '',
  resetGroupedDetails() {}, loadGroupedDetails() {}, mapApiRow: (_, row) => row,
  loadPlatformLocations: async () => { sandbox.platformHqLocationId.value = 128 },
  fetchPlatformListPage: async ({ kind, payload }) => {
    assert.equal(kind, 'hq-transfer')
    calls.push(structuredClone(payload))
    const matches = payload.hq_location_id === 129 && payload.from_location_id === 129
    return { count: matches ? 1 : 0, list: matches ? [fixture] : [], can_create: true }
  }
}
vm.createContext(sandbox)
const listFunction = app.slice(app.indexOf('async function loadCurrentList('), app.indexOf('\nasync function queryBatchStock('))
const saveFunction = app.slice(app.indexOf('function onModalSaved('), app.indexOf('\nonMounted(async () =>', app.indexOf('function onModalSaved(')))
vm.runInContext(`${listFunction}\n${saveFunction}`, sandbox)
sandbox.onModalSaved({ transferSourceKey: 'HQ:129', transferStatus: 'DRAFT', transferDraftCount: 1,
  transferSourceCount: 1, transferNumbers: [fixture.order_sn], businessDate: '2026-10-28' })
await new Promise((resolve) => setImmediate(resolve))
assert.equal(sandbox.listError.value, '')
assert.equal(sandbox.sourceRows.value[0]?.order_sn, fixture.order_sn)
assert.equal(calls.at(-1).hq_location_id, 129)
assert.equal(calls.at(-1).business_date_from, '2026-10-28')
assert.equal(sandbox.platformHqLocationId.value, 128, '旧共享仓库仍存在时也必须正确查询')
sandbox.platformTransferFromParty.value = 'HQ:128'
await sandbox.loadCurrentList()
assert.equal(calls.at(-1).hq_location_id, 128)
assert.equal(sandbox.sourceRows.value.length, 0, '切回其他仓不能泄漏采购仓草稿')
sandbox.platformTransferFromParty.value = 'HQ:129'
await sandbox.loadCurrentList()
assert.equal(sandbox.sourceRows.value[0]?.order_sn, fixture.order_sn)
console.log('PASS R41 调出仓上下文、目录刷新后回显、HTTP参数、分页、接收仓与门店范围')

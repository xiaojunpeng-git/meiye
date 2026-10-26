import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..', '..')
const page = fs.readFileSync(path.join(root, '前端代码/mobile-vue3/src/merchant/pages/warehouse/index.uvue'), 'utf8')
const home = fs.readFileSync(path.join(root, '前端代码/mobile-vue3/src/merchant/pages/home/index.uvue'), 'utf8')
const homeClient = fs.readFileSync(path.join(root, '前端代码/mobile-vue3/src/shared/api/mobile-home-client.uts'), 'utf8')
const client = fs.readFileSync(path.join(root, '前端代码/mobile-vue3/src/shared/api/mobile-warehouse-client.uts'), 'utf8')
const contract = JSON.parse(fs.readFileSync(path.join(root, '前端代码/mobile-vue3/src/shared/contracts/mobile-merchant-v1.contract.json'), 'utf8'))
const service = fs.readFileSync(path.join(root, '后端代码/app/services/mobile/warehouse/MobileWarehouseServices.php'), 'utf8')
const routes = fs.readFileSync(path.join(root, '后端代码/route/api-mobile.php'), 'utf8')

test('warehouse uses server scope and registered business metrics', () => {
	for (const marker of ['queryMobileWarehouse', 'breadcrumbs', 'rankingRows', 'openRow(row)', 'metric_version', 'data_as_of', 'aggregation_caught_up']) assert.equal(page.includes(marker), true, marker)
	assert.equal(service.includes('MobileMerchantAnalyticsEntryScopeServices'), true, 'server must resolve employee data scope')
	assert.equal(service.includes('RegisteredMetricReadServices'), true, 'rankings must use the registered metric reader')
	assert.equal(client.includes("path: '/mobile/merchant/warehouse/overview'"), true)
	assert.equal(routes.includes("Route::post('warehouse/overview', 'Warehouse/overview')"), true)
	assert.equal(contract.endpoints.queryWarehouseOverview.authorization, 'WAREHOUSE_FEATURE_AND_SERVER_DATA_SCOPE')
	assert.equal(contract.endpoints.queryWarehouseOverview.legacyAgentMetricApisForbidden, true)
	assert.equal(page.includes('merchantDataBusiness'), false)
})

test('ranking tabs and all-store display follow the current server contract', () => {
	for (const label of ['组织排行', '门店排行', '员工销售', '员工劳动', '员工点客', '服务项目']) assert.equal(service.includes(`'name' => '${label}'`), true, label)
	for (const code of ['staff_cash_performance', 'staff_labor_performance', 'project_count']) assert.equal(service.includes(`'code' => '${code}'`), true, code)
	assert.equal(service.includes("'metricCode' => 'cash_performance'"), true)
	assert.equal(service.includes("'groupDimension' => 'employee'"), true)
	assert.equal(service.includes("'groupDimension' => 'category_and_product_name'"), true)
	assert.equal(contract.warehouseHierarchy.projectGrouping, 'EXACT_CURRENT_CATEGORY_PATH_AND_PRODUCT_SNAPSHOT_NAME')
	assert.equal(contract.warehouseHierarchy.projectCashMetric, 'SIGNED_ALLOCATED_CASH_PERFORMANCE_CENTS')
	assert.equal(contract.warehouseHierarchy.projectQuantityMetric, 'COMPLETED_SERVICE_ITEM_QUANTITY')
	assert.deepEqual(contract.warehouseHierarchy.projectSortMetricEnum, ['cash', 'quantity'])
	assert.equal(contract.endpoints.queryWarehouseOverview.requestBodyOptional.includes('projectSortMetric'), true)
	for (const label of ['按现金业绩', '按服务数', '服务数 {{', '现金业绩 ¥']) assert.equal(page.includes(label), true, label)
	assert.equal(page.includes("selectedRankingCode.value === 'organization' ? rows.slice(0, 10) : rows"), true, 'all rankings keep server order and the organization display cap')
	assert.equal(page.includes('rowSortNumber('), false, 'rounded display values must not reorder server ranking')
	assert.equal(page.includes('@scrolltolower="loadMoreRanking"'), true, 'scroll bottom must request the next page')
	assert.equal(page.includes('warehouse-page__bar-card'), false, 'duplicate employee and project bar cards are removed')
	assert.equal(page.includes('warehouse-page__store-ranking-summary-copy'), false, 'redundant store ranking summary is removed')
	assert.equal(page.includes('warehouse-page__ranking-controls'), true, 'every visible ranking has an order control')
	assert.equal(contract.endpoints.queryWarehouseOverview.requestBodyOptional.includes('rankingPage'), true)
	assert.equal(contract.endpoints.queryWarehouseOverview.requestBodyOptional.includes('storeRankingPage'), true)
	assert.equal(service.includes("'rankingTotal' => $rankingTotal"), true, 'server must return the complete authorized total')
	assert.equal(page.includes("periodMode = ref('today')"), true, 'data page defaults to today')
	assert.equal(home.includes("selectedPeriod = ref('today')"), true, 'merchant home defaults to today')
	assert.equal(homeClient.includes('shanghaiOffsetMs'), true, 'relative date cache must expire at the Shanghai day boundary')
	assert.equal(service.includes('$this->storeRankingPage($rankingScopeStoreIds, $stores, $period,'), true, 'store ranking follows the authorized scope and selected period')
	assert.equal(page.includes('rankingRange'), true, 'continued pages reject a changed date range')
	assert.equal(page.includes('agentYejiRanking'), false)
})

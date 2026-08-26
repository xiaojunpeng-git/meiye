import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..', '..')
const page = fs.readFileSync(path.join(root, '前端代码/mobile-vue3/src/merchant/pages/warehouse/index.uvue'), 'utf8')
const client = fs.readFileSync(path.join(root, '前端代码/mobile-vue3/src/merchant/api/mobile-warehouse-client.uts'), 'utf8')
const contract = JSON.parse(fs.readFileSync(path.join(root, '前端代码/mobile-vue3/src/merchant/contracts/mobile-merchant-v1.contract.json'), 'utf8'))
const service = fs.readFileSync(path.join(root, '后端代码/app/services/mobile/warehouse/MobileWarehouseServices.php'), 'utf8')
const routes = fs.readFileSync(path.join(root, '后端代码/route/api-mobile.php'), 'utf8')

test('warehouse is a real server-scoped hierarchy page', () => {
	for (const marker of ['queryMobileWarehouse', 'breadcrumbs', 'rankingRows', 'openRow(row)', 'metric_version', 'data_as_of', 'aggregation_caught_up']) assert.equal(page.includes(marker), true, marker)
	assert.equal(service.includes("CASE WHEN fact_direction='reversal' THEN -amount_cents"), false, 'signed fact amounts must not be inverted again')
	assert.equal((service.match(/SUM\(amount_cents\)/g) || []).length >= 3, true, 'mobile warehouse must sum signed fact amounts directly')
	assert.equal(page.includes('merchantDataBusiness'), false)
	assert.equal(page.includes('agentYejiRanking'), false)
	assert.equal(client.includes("path: '/mobile/merchant/warehouse/overview'"), true)
	assert.equal(routes.includes("Route::post('warehouse/overview', 'Warehouse/overview')"), true)
	assert.equal(contract.endpoints.queryWarehouseOverview.authorization, 'WAREHOUSE_FEATURE_AND_SERVER_DATA_SCOPE')
	assert.equal(contract.endpoints.queryWarehouseOverview.legacyAgentMetricApisForbidden, true)
})

test('all confirmed ranking semantics are represented without mock business values', () => {
	for (const label of ['组织排行', '员工现金业绩', '员工劳动业绩', '员工点客', '项目数排行']) assert.equal(service.includes(`'name' => '${label}'`), true, label)
	assert.equal(service.includes("'groupDimension' => 'employee'"), true)
	assert.equal(service.includes("'groupDimension' => 'project'"), true)
	assert.equal(service.includes("'metricCode' => 'service_project_count'"), true)
	assert.equal(service.includes("'metricCode' => 'staff_project_num'"), false)
	for (const fact of ['cashier_v3_payment_fact', 'cashier_v3_performance_fact', 'cashier_v3_entitlement_service_fact']) assert.equal(service.includes(fact), true, fact)
	assert.equal(service.includes("'aggregation_caught_up' => true"), true)
	assert.equal(service.includes("'available' => false"), true, '仅员工点客在统一事实补齐前保持不可用')
	assert.equal(service.includes("'value' => null"), false)
	assert.equal(service.includes('merchantDataBusiness'), false)
	assert.equal(service.includes('agentYejiRanking'), false)
	assert.equal(service.includes('WAREHOUSE_SCOPE_'), false)
	assert.equal(service.includes('WAREHOUSE_PERIOD_INVALID'), false)
})

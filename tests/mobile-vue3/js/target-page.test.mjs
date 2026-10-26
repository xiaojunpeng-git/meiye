import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import test from 'node:test'

import { mobileRoot } from './helpers.mjs'

const page = fs.readFileSync(path.join(mobileRoot, 'src', 'merchant', 'pages', 'goals', 'index.uvue'), 'utf8')
const client = fs.readFileSync(path.join(mobileRoot, 'src', 'merchant', 'api', 'mobile-personal-target-client.uts'), 'utf8')
const contract = JSON.parse(fs.readFileSync(path.join(mobileRoot, 'src', 'merchant', 'contracts', 'mobile-merchant-v1.contract.json'), 'utf8'))

test('personal monthly target keeps the confirmed three fixed metrics', () => {
	for (const item of ['SALES_PERFORMANCE', 'CONSUMPTION_PERFORMANCE', 'SERVICE_VISITS', '销售业绩', '消耗业绩', '服务客次']) assert.equal(page.includes(item), true, item)
	assert.equal(page.includes('服务完成'), false)
	assert.equal(page.includes('客户跟进'), false)
	assert.deepEqual(contract.personalMonthlyTarget.metricCodes, ['SALES_PERFORMANCE', 'CONSUMPTION_PERFORMANCE', 'SERVICE_VISITS'])
	assert.equal(contract.personalMonthlyTarget.legacyStoreTargetForbidden, true)
})

test('target page uses merchant-session APIs and does not calculate actual performance locally', () => {
	assert.equal(page.includes('queryMobilePersonalMonthlyTarget'), true)
	assert.equal(page.includes('saveMobilePersonalMonthlyTarget'), true)
	assert.equal(page.includes('response.body.monthKey'), true)
	assert.equal(page.includes('response.body.projection'), false)
	assert.equal(page.includes('METRIC_INTERFACE_PENDING'), false)
	assert.equal(page.includes('旧报表'), false)
	assert.equal(client.includes('/mobile/merchant/personal-monthly-target/current'), true)
	assert.equal(client.includes('/mobile/merchant/personal-monthly-target'), true)
	assert.equal(contract.endpoints.currentPersonalMonthlyTarget.requestMetadataProfile, 'MERCHANT_SESSION')
	assert.equal(contract.endpoints.savePersonalMonthlyTarget.requestBodyRequired.includes('idempotencyKey'), true)
})

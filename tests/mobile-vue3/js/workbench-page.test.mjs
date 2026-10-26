import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import test from 'node:test'

import { mobileRoot, repoRoot } from './helpers.mjs'

const workbench = fs.readFileSync(path.join(mobileRoot, 'src', 'merchant', 'pages', 'workbench', 'index.uvue'), 'utf8')
const carePage = fs.readFileSync(path.join(mobileRoot, 'src', 'merchant', 'pages', 'customer-care', 'index.uvue'), 'utf8')
const reservationProjection = fs.readFileSync(path.join(repoRoot, '后端代码', 'app', 'services', 'cashier', 'v3', 'reservation', 'CashierV3ReservationPartitionProvider.php'), 'utf8')
const careQueryService = fs.readFileSync(path.join(repoRoot, '后端代码', 'app', 'services', 'customer', 'care', 'query', 'CustomerCareWorkbenchQueryService.php'), 'utf8')

test('merchant workbench is organized as server-backed todos and one common function list', () => {
	for (const label of ['待办', '跟进任务', '预约未到店', '常用功能']) assert.equal(workbench.includes(label), true, label)
	for (const removed of ['个人协同', '培训资料', '消息通知', '店务协同', '客户运营']) assert.equal(workbench.includes(removed), false, removed)
	assert.equal(workbench.includes("queryMobileCustomerCare({ view: 'tasks', query: { bucket: 'all', statusGroup: 'open', pageSize: 1 } }"), true)
	assert.equal(workbench.includes('projection.taskView.total'), true)
	assert.equal(workbench.includes('view.quickCounts.dueNotArrived'), true)
	assert.equal(workbench.includes("showPending('订单管理')"), true)
	assert.equal(workbench.includes("showMobileNotice(label + '待开发')"), true)
})

test('unfinished customer-care todo opens the matching server-side filter', () => {
	assert.equal(workbench.includes('/src/merchant/pages/customer-care/index?bucket=all&statusGroup=open'), true)
	assert.equal(carePage.includes("{ key: 'all', label: '未完成' }"), true)
	assert.equal(carePage.includes("bucket == 'all' && statusGroup == 'open'"), true)
	assert.equal(carePage.includes("? { bucket: 'all', statusGroup: 'open' }"), true)
	assert.equal(careQueryService.includes("if (($normalized['taskQuery']['statusGroup'] ?? '') !== 'open')"), true)
})

test('due-not-arrived count uses only V3 reservation and authoritative service start state', () => {
	assert.equal(reservationProjection.includes("Db::name('cashier_v3_reservation')->alias('r')"), true)
	assert.equal(reservationProjection.includes("join('cashier_v3_service_order s', 's.id=r.service_order_id')"), true)
	assert.equal(reservationProjection.includes("where('r.status', 'UNSTARTED')"), true)
	assert.equal(reservationProjection.includes("whereIn('status', ['UNSTARTED', 'IN_SERVICE', 'COMPLETED'])"), true)
	assert.equal(reservationProjection.includes("where('r.appointment_start_at', '<=', $now)"), true)
	assert.equal(reservationProjection.includes("where('s.status', 'OPEN')->where('s.service_started_at', 0)"), true)
	assert.equal(reservationProjection.includes("'dueNotArrived' => $dueNotArrivedCount"), true)
	assert.equal(reservationProjection.includes('store_reservation_order'), false)
})

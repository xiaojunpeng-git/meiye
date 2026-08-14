import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import test from 'node:test'

import { mobileRoot } from './helpers.mjs'

const page = fs.readFileSync(path.join(mobileRoot, 'src', 'merchant', 'pages', 'reservations', 'index.uvue'), 'utf8')
const client = fs.readFileSync(path.join(mobileRoot, 'src', 'shared', 'api', 'mobile-reservation-client.uts'), 'utf8')
const merchant = JSON.parse(fs.readFileSync(path.join(mobileRoot, 'src', 'shared', 'contracts', 'mobile-merchant-v1.contract.json'), 'utf8'))

test('mobile reservation page exposes the V3 reservation lifecycle transport', () => {
	for (const name of ['queryMobileReservations', 'openMobileReservationEditor', 'queryMobileReservationMembers', 'recalculateMobileReservation', 'createMobileReservation']) {
		assert.equal(page.includes(name), true, name)
	}
	for (const name of ['queryMobileReservationDetail', 'queryMobileReservationProjectCatalog', 'updateMobileReservation', 'deleteMobileReservation', 'startMobileReservationService', 'endMobileReservationService']) assert.equal(client.includes(name), true, name)
	assert.equal(page.includes('cashier_v3_reservation'), true)
	assert.equal(page.includes('legacy reservation'), false)
})

test('mobile reservation transport uses merchant session contract and no legacy endpoint', () => {
	for (const endpoint of ['listReservations', 'reservationDetail', 'openReservationEditor', 'reservationProjectCatalog', 'queryReservationMemberCandidates', 'recalculateReservation', 'createReservation', 'updateReservation', 'deleteReservation', 'startReservationService', 'endReservationService']) {
		assert.equal(merchant.endpoints[endpoint].requestMetadataProfile, 'MERCHANT_SESSION', endpoint)
	}
	assert.equal(merchant.endpoints.createReservation.legacyReservationForbidden, true)
	for (const pathValue of ['/mobile/merchant/reservations', '/mobile/merchant/reservations/:reservationId', '/mobile/merchant/reservations/editor', '/mobile/merchant/reservations/project-catalog', '/mobile/merchant/reservations/member-candidates', '/mobile/merchant/reservations/recalculate', '/mobile/merchant/reservations/:reservationId/start-service', '/mobile/merchant/reservations/:reservationId/end-service']) {
		assert.equal(client.includes(`path: '${pathValue}'`), true, pathValue)
	}
	assert.equal(client.includes('/reservation/'), false)
})

test('reservation submit is protected by V3 command idempotency and returned workspace contexts', () => {
	assert.equal(page.includes("'RESERVATION-' + uuid()"), true)
	assert.equal(page.includes('contexts: editorContexts.value'), true)
	assert.equal(page.includes('selectedCraftsmen'), true)
	assert.equal(page.includes('selectedRoomId'), true)
	assert.equal(page.includes("payload.result.status != 'success'"), true)
	assert.equal(page.includes("payload.result.status != 'succeeded'"), false)
})

test('reservation page takes an unauthenticated operator to the merchant login instead of silently ignoring actions', () => {
	assert.equal(page.includes("import { openMerchantBootstrap } from '../../../shared/platform/mobile-navigation.uts'"), true)
	assert.equal(page.includes('function openMerchantLogin() : void { openMerchantBootstrap() }'), true)
	assert.equal(page.includes("if (merchantLoginIsRequired()) { openMerchantLogin(); return }"), true)
	assert.equal(page.includes("loginRequired ? '前往商家端登录' : '重新加载'"), true)
})

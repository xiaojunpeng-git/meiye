import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import test from 'node:test'

import { mobileRoot } from './helpers.mjs'

const page = fs.readFileSync(path.join(mobileRoot, 'src', 'merchant', 'pages', 'reservations', 'index.uvue'), 'utf8')
const client = fs.readFileSync(path.join(mobileRoot, 'src', 'shared', 'api', 'mobile-reservation-client.uts'), 'utf8')
const merchant = JSON.parse(fs.readFileSync(path.join(mobileRoot, 'src', 'shared', 'contracts', 'mobile-merchant-v1.contract.json'), 'utf8'))

test('mobile reservation page exposes only the released V3 create slice', () => {
	for (const name of ['queryMobileReservations', 'openMobileReservationEditor', 'queryMobileReservationMembers', 'recalculateMobileReservation', 'createMobileReservation']) {
		assert.equal(page.includes(name), true, name)
	}
	for (const forbidden of ['start-reservation-service', 'finish-service-completion', 'prepare-reservation-checkout', 'cancel-reservation', 'update-reservation']) {
		assert.equal(client.includes(forbidden), false, forbidden)
	}
	assert.equal(page.includes('cashier_v3_reservation'), true)
	assert.equal(page.includes('legacy reservation'), false)
})

test('mobile reservation transport uses merchant session contract and no legacy endpoint', () => {
	for (const endpoint of ['listReservations', 'openReservationEditor', 'queryReservationMemberCandidates', 'recalculateReservation', 'createReservation']) {
		assert.equal(merchant.endpoints[endpoint].requestMetadataProfile, 'MERCHANT_SESSION', endpoint)
	}
	assert.equal(merchant.endpoints.createReservation.legacyReservationForbidden, true)
	for (const pathValue of ['/mobile/merchant/reservations', '/mobile/merchant/reservations/editor', '/mobile/merchant/reservations/member-candidates', '/mobile/merchant/reservations/recalculate']) {
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

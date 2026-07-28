import assert from 'node:assert/strict'
import test from 'node:test'

import { exactSorted, sourceJson } from './helpers.mjs'

const expectedStates = [
	'BOOTING',
	'PHONE_REQUIRED',
	'MEMBER_READY',
	'MERCHANT_CHECKING',
	'MERCHANT_READY',
	'SESSION_INVALID'
]

function transition(machine, from, event) {
	return machine.transitions.find(
		(candidate) => candidate.from === from && candidate.event === event
	)
}

function transitionKey(item) {
	return `${item.from}:${item.event}:${item.to}`
}

test('mode machine has the complete fixed state set', () => {
	const machine = sourceJson('shared/state/mobile-mode-machine-v1.json')
	const expectedTransitions = [
		['BOOTING', 'PHONE_REQUIRED', 'PHONE_REQUIRED'],
		['BOOTING', 'VALID_MEMBER_SESSION', 'MEMBER_READY'],
		['PHONE_REQUIRED', 'PHONE_VERIFIED', 'MEMBER_READY'],
		['MEMBER_READY', 'ENTER_MERCHANT', 'MERCHANT_CHECKING'],
		['MERCHANT_CHECKING', 'VERIFIED_AND_AUTHORIZED', 'MERCHANT_READY'],
		['MERCHANT_CHECKING', 'DENIED_OR_CANCELLED', 'MEMBER_READY'],
		['MERCHANT_READY', 'ENTER_MEMBER', 'MEMBER_READY'],
		['MERCHANT_READY', 'MERCHANT_SESSION_INVALID', 'MEMBER_READY'],
		['SESSION_INVALID', 'REQUIRE_PHONE', 'PHONE_REQUIRED']
	].map(([from, event, to]) => `${from}:${event}:${to}`)

	assert.equal(machine.contractVersion, 'mobile-mode-machine-v1')
	assert.deepEqual(exactSorted(machine.states), exactSorted(expectedStates))
	assert.deepEqual(
		exactSorted(machine.transitions.map(transitionKey)),
		exactSorted(expectedTransitions)
	)
})

test('phone verification is mandatory before merchant checking', () => {
	const machine = sourceJson('shared/state/mobile-mode-machine-v1.json')

	assert.equal(transition(machine, 'BOOTING', 'PHONE_REQUIRED').to, 'PHONE_REQUIRED')
	assert.equal(transition(machine, 'PHONE_REQUIRED', 'PHONE_VERIFIED').to, 'MEMBER_READY')
	assert.equal(transition(machine, 'MEMBER_READY', 'ENTER_MERCHANT').to, 'MERCHANT_CHECKING')
	assert.equal(
		transition(machine, 'MERCHANT_CHECKING', 'VERIFIED_AND_AUTHORIZED').to,
		'MERCHANT_READY'
	)

	const forbiddenDirect = machine.transitions.filter(
		(candidate) =>
			['BOOTING', 'PHONE_REQUIRED'].includes(candidate.from) &&
			['MERCHANT_CHECKING', 'MERCHANT_READY'].includes(candidate.to)
	)
	assert.equal(forbiddenDirect.length, 0)
	assert.equal(machine.guards.merchantReadyOnlyFrom, 'MERCHANT_CHECKING')
	assert.equal(machine.guards.appModeChoiceBypassesPhoneVerification, false)
})

test('merchant denial returns to member without invalidating the app identity', () => {
	const machine = sourceJson('shared/state/mobile-mode-machine-v1.json')
	const enterMerchant = transition(machine, 'MEMBER_READY', 'ENTER_MERCHANT')
	const enterMember = transition(machine, 'MERCHANT_READY', 'ENTER_MEMBER')
	const merchantInvalid = transition(machine, 'MERCHANT_READY', 'MERCHANT_SESSION_INVALID')
	const bootMember = transition(machine, 'BOOTING', 'VALID_MEMBER_SESSION')
	const deniedOrCancelled = transition(machine, 'MERCHANT_CHECKING', 'DENIED_OR_CANCELLED')

	assert.equal(
		transition(machine, 'MERCHANT_CHECKING', 'DENIED_OR_CANCELLED').to,
		'MEMBER_READY'
	)
	assert.equal(
		transition(machine, 'MERCHANT_READY', 'MERCHANT_SESSION_INVALID').to,
		'MEMBER_READY'
	)
	for (const item of [enterMember, merchantInvalid]) {
		assert.equal(item.actions.includes('CLEAR_MERCHANT_ROOT'), true)
		assert.equal(item.actions.includes('CLEAR_MERCHANT_CACHE_AND_PAGE_STACK'), true)
		assert.equal(item.actions.includes('INCREMENT_REQUEST_GENERATION'), true)
		assert.equal(item.actions.includes('PRESERVE_SERVER_DRAFTS'), true)
	}
	assert.equal(enterMerchant.actions.includes('CLEAR_MERCHANT_ROOT'), true)
	assert.equal(enterMerchant.actions.includes('CLEAR_MERCHANT_CACHE_AND_PAGE_STACK'), true)
	assert.equal(enterMerchant.actions.includes('INCREMENT_REQUEST_GENERATION'), true)
	assert.equal(bootMember.actions.includes('CLEAR_MERCHANT_ROOT'), true)
	assert.equal(bootMember.actions.includes('CLEAR_MERCHANT_CACHE_AND_PAGE_STACK'), true)
	assert.equal(bootMember.actions.includes('INCREMENT_REQUEST_GENERATION'), true)
	assert.equal(deniedOrCancelled.actions.includes('CLEAR_MERCHANT_ROOT'), true)
	assert.equal(deniedOrCancelled.actions.includes('CLEAR_MERCHANT_CACHE_AND_PAGE_STACK'), true)
	assert.equal(deniedOrCancelled.actions.includes('INCREMENT_REQUEST_GENERATION'), true)
})

test('only app or phone identity invalidation enters SESSION_INVALID', () => {
	const machine = sourceJson('shared/state/mobile-mode-machine-v1.json')
	assert.equal(machine.identityInvalidation.event, 'APP_OR_PHONE_IDENTITY_INVALID')
	assert.equal(machine.identityInvalidation.to, 'SESSION_INVALID')
	assert.equal(transition(machine, 'SESSION_INVALID', 'REQUIRE_PHONE').to, 'PHONE_REQUIRED')
	assert.equal(machine.guards.miniProgramColdStartDefault, 'MEMBER')
	assert.equal(machine.guards.merchantDeepLinkFailsClosed, true)
})

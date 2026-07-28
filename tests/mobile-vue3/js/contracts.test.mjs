import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import test from 'node:test'

import {
	assertRequired,
	exactSorted,
	fixtureJson,
	fixtureRoot,
	sourceJson
} from './helpers.mjs'

const expectedServerErrors = [
	'AUTH_REQUIRED',
	'PHONE_VERIFICATION_REQUIRED',
	'SMS_CHALLENGE_EXPIRED',
	'SMS_CODE_INVALID',
	'SMS_TOO_FREQUENT',
	'SMS_PROVIDER_FAILED',
	'SMS_PROVIDER_UNKNOWN',
	'EMPLOYEE_NOT_FOUND',
	'EMPLOYEE_DISABLED',
	'NO_VALID_ASSIGNMENT',
	'MOBILE_ENTRY_DISABLED',
	'MOBILE_JOB_FUNCTION_MISSING',
	'STORE_NOT_ALLOWED',
	'STORE_DISABLED',
	'AUTH_VERSION_CHANGED',
	'MERCHANT_SESSION_EXPIRED',
	'TOKEN_OWNER_MISMATCH',
	'CONTRACT_VERSION_UNSUPPORTED',
	'LEGACY_PHONE_CONFLICT',
	'ACTIVE_CONTEXT_MISMATCH'
]

function assertMerchantRoot(root, contract) {
	assertRequired(assert, root, contract.merchantRoot.required, 'merchant root')
	assertRequired(assert, root.employee, contract.merchantRoot.employeeRequired, 'employee')
	assertRequired(assert, root.activeContext, contract.merchantRoot.activeContextRequired, 'active context')
	assert.equal(root.contractVersion, contract.contractVersion)
	assert.ok(Number.isInteger(root.authVersion) && root.authVersion > 0)
	assert.equal(root.employee.status, 'ACTIVE')
	assert.equal(typeof root.activeContextId, 'string')
	assert.ok(root.activeContextId.length > 0)
	assert.equal(typeof root.stateContextId, 'string')
	assert.ok(root.stateContextId.length > 0)
	assert.equal(typeof root.stateRevision, 'string')
	assert.match(root.stateRevision, new RegExp(contract.merchantRoot.stateRevisionPattern))
	assert.notEqual(root.activeContextId, root.activeContext.contextId)
	assert.notEqual(root.stateContextId, root.activeContext.contextId)

	const activeMatches = root.contexts.filter(
		(context) => context.contextId === root.activeContext.contextId
	)
	assert.equal(activeMatches.length, 1)
	assert.equal(new Set(root.contexts.map((context) => context.contextId)).size, root.contexts.length)
	for (const context of root.contexts) {
		assertRequired(assert, context, contract.contextSummary.required, 'context summary')
		for (const forbidden of contract.contextSummary.forbidden) {
			assert.equal(Object.hasOwn(context, forbidden), false)
		}
	}
}

test('contract versions and server error set are frozen exactly', () => {
	const auth = sourceJson('shared/contracts/mobile-auth-v1.contract.json')
	const merchant = sourceJson('shared/contracts/mobile-merchant-v1.contract.json')
	const errors = sourceJson('shared/contracts/mobile-api-errors-v1.json')

	assert.equal(auth.contractVersion, 'mobile-auth-v1')
	assert.equal(merchant.contractVersion, 'mobile-merchant-v1')
	assert.equal(errors.contractVersion, 'mobile-api-errors-v1')
	assert.equal(new Set(errors.serverErrors).size, errors.serverErrors.length)
	assert.deepEqual(exactSorted(errors.serverErrors), exactSorted(expectedServerErrors))
	assert.equal(errors.clientRootRejectionReasons.includes('ACTIVE_CONTEXT_MISMATCH'), false)
})

test('SMS challenge exposes relative resend seconds and never exposes a code', () => {
	const auth = sourceJson('shared/contracts/mobile-auth-v1.contract.json')
	const endpoint = auth.endpoints.createSmsChallenge
	const response = fixtureJson('auth/challenge-success.json')

	assert.deepEqual(
		exactSorted(endpoint.request.required),
		exactSorted(['phone', 'purpose', 'deviceId', 'captchaProof'])
	)
	assert.equal(endpoint.request.purposeEnum.length, 1)
	assert.equal(endpoint.request.purposeEnum[0], 'APP_LOGIN')
	assertRequired(assert, response, endpoint.success.required, 'challenge response')
	assert.equal(response.contractVersion, auth.contractVersion)
	assert.ok(Number.isInteger(response.resendAfterSeconds))
	assert.ok(response.resendAfterSeconds >= 0)
	for (const forbidden of endpoint.success.forbidden) {
		assert.equal(Object.hasOwn(response, forbidden), false)
	}
})

test('SMS verification keeps the code as a six digit string and returns one projection', () => {
	const auth = sourceJson('shared/contracts/mobile-auth-v1.contract.json')
	const endpoint = auth.endpoints.verifySmsChallenge
	const response = fixtureJson('auth/verify-success.json')

	assert.equal(endpoint.request.codeType, 'STRING')
	const codePattern = new RegExp(endpoint.request.codePattern)
	assert.equal(codePattern.test('012345'), true)
	assert.equal(codePattern.test('12345'), false)
	assert.equal(codePattern.test('1234567'), false)
	assertRequired(assert, response, endpoint.success.required, 'verification response')
	assertRequired(assert, response.appSession, endpoint.success.appSessionRequired, 'app session')
	assertRequired(
		assert,
		response.phoneVerification,
		endpoint.success.phoneVerificationRequired,
		'phone verification'
	)
	assertRequired(
		assert,
		response.availableModes,
		endpoint.success.availableModesRequired,
		'available modes'
	)
	assert.equal(response.phoneVerification.status, 'VERIFIED')
	assert.equal(response.phoneVerification.method, 'SMS')
	assert.ok(response.phoneVerification.verificationVersion > 0)
})

test('provider failures, unknown outcomes, and legacy conflicts never create a session', () => {
	const expectedFixtures = [
		['auth/provider-failed.json', 'SMS_PROVIDER_FAILED'],
		['auth/provider-unknown.json', 'SMS_PROVIDER_UNKNOWN'],
		['auth/legacy-phone-conflict.json', 'LEGACY_PHONE_CONFLICT']
	]
	for (const [fixture, expectedCode] of expectedFixtures) {
		const response = fixtureJson(fixture)
		assert.equal(response.error.code, expectedCode)
		assert.equal(Object.hasOwn(response, 'appSession'), false)
		assert.equal(Object.hasOwn(response, 'phoneVerification'), false)
	}
})

test('merchant root keeps activeContextId distinct from business contextId', () => {
	const contract = sourceJson('shared/contracts/mobile-merchant-v1.contract.json')
	const root = fixtureJson('merchant/bootstrap-success.json')

	assertMerchantRoot(root, contract)
	assert.equal(Object.keys(contract.requestMetadata.required).length, 7)
	assert.equal(
		contract.requestMetadata.required['X-Mobile-Active-Context-Id'],
		'activeContextId'
	)
	assert.equal(
		contract.requestMetadata.required['X-Mobile-State-Context-Id'],
		'stateContextId'
	)
	assert.equal(contract.requestMetadata.emptyContextValuesAllowed, false)
	assert.equal(contract.endpoints.createMerchantSession.preContextExchange, true)
	assert.equal(contract.endpoints.createMerchantSession.method, 'POST')
	assert.equal(contract.endpoints.createMerchantSession.path, '/mobile/merchant/session')
	assert.equal(
		contract.endpoints.createMerchantSession.authentication,
		'APP_SESSION_AND_PHONE_VERIFICATION'
	)
	assert.equal(contract.endpoints.createMerchantSession.merchantContextRequired, false)
	assert.equal(contract.endpoints.createMerchantSession.response, 'merchantRoot')
	assert.equal(
		contract.requestMetadata.appliesTo,
		'AUTHENTICATED_MERCHANT_REQUESTS_AFTER_SESSION_EXCHANGE'
	)
	assert.equal(contract.endpoints.bootstrap.merchantContextRequired, true)
	assert.equal(contract.endpoints.switchContext.merchantContextRequired, true)
})

test('empty scope fails closed and delivery-only identity cannot enter merchant mode', () => {
	const contract = sourceJson('shared/contracts/mobile-merchant-v1.contract.json')
	const emptyScope = fixtureJson('merchant/bootstrap-empty-scope.json')
	const deliveryOnly = fixtureJson('auth/delivery-only-mode.json')

	assert.equal(contract.authorizationSemantics.emptyScopeMeansNoAccess, true)
	assert.equal(contract.authorizationSemantics.emptyCapabilitiesMeansNoCapability, true)
	assert.equal(contract.authorizationSemantics.emptyAvailableActionsMeansNoAction, true)
	assert.equal(contract.authorizationSemantics.deliveryOnlyMerchantAccess, false)
	assert.equal(emptyScope.expectedInstallable, false)
	assert.equal(emptyScope.root.dataScope.authorized, false)
	assert.equal(emptyScope.root.dataScope.scopeEntryCount, 0)
	assert.equal(emptyScope.root.capabilities.length, 0)
	assert.equal(emptyScope.root.availableActions.length, 0)
	assert.equal(deliveryOnly.scenario.onlyLegacyDeliveryRole, true)
	assert.equal(deliveryOnly.projection.availableModes.merchant, false)
})

test('multiple assignments do not union inactive-context capabilities', () => {
	const contract = sourceJson('shared/contracts/mobile-merchant-v1.contract.json')
	const fixture = fixtureJson('merchant/multi-assignment-no-union.json')

	assert.equal(contract.authorizationSemantics.mergeAcrossContexts, false)
	assert.deepEqual(fixture.activeRoot.capabilities, fixture.expectedActiveCapabilities)
	for (const inactiveCapability of fixture.inactiveContextServerOnlyCapabilities) {
		assert.equal(fixture.activeRoot.capabilities.includes(inactiveCapability), false)
	}
})

test('context switch rotates both security identifiers and invalidates old context', () => {
	const contract = sourceJson('shared/contracts/mobile-merchant-v1.contract.json')
	const before = fixtureJson('merchant/context-switch-before.json')
	const after = fixtureJson('merchant/context-switch-after.json')
	const oldContextError = fixtureJson('merchant/old-context-error.json')

	assertMerchantRoot(after, contract)
	assert.notEqual(after.activeContextId, before.requestContext.activeContextId)
	assert.notEqual(after.stateContextId, before.requestContext.stateContextId)
	assert.equal(after.activeContext.contextId, before.body.targetContextId)
	assert.ok(before.body.expectedAuthVersion > 0)
	assert.equal(oldContextError.error.code, 'ACTIVE_CONTEXT_MISMATCH')
	assert.equal(Object.hasOwn(oldContextError, 'state'), false)
})

test('golden merchant bootstrap remains bounded for mobile transport', () => {
	const filePath = path.join(fixtureRoot, 'merchant', 'bootstrap-success.json')
	const bytes = fs.readFileSync(filePath).byteLength
	assert.ok(bytes <= 256 * 1024, `bootstrap fixture is ${bytes} bytes`)
})

test('130 context summaries remain bounded and never carry permission projections', () => {
	const contract = sourceJson('shared/contracts/mobile-merchant-v1.contract.json')
	const root = fixtureJson('merchant/bootstrap-success.json')
	const contexts = Array.from({ length: 130 }, (_, index) => ({
		contextId: `context-load-${String(index + 1).padStart(3, '0')}`,
		label: `Store ${index + 1}`
	}))
	const payload = {
		...root,
		activeContext: {
			...root.activeContext,
			contextId: contexts[0].contextId
		},
		contexts
	}

	for (const context of payload.contexts) {
		for (const forbidden of contract.contextSummary.forbidden) {
			assert.equal(Object.hasOwn(context, forbidden), false)
		}
	}
	assert.equal(new Set(payload.contexts.map((context) => context.contextId)).size, 130)
	assert.ok(Buffer.byteLength(JSON.stringify(payload), 'utf8') <= 256 * 1024)
})

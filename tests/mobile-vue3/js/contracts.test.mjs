import assert from 'node:assert/strict'
import test from 'node:test'

import { exactSorted, sourceJson } from './helpers.mjs'

function assertRequired(assertion, value, required, label) {
	for (const field of required) assertion.equal(Object.hasOwn(value, field), true, `${label} missing ${field}`)
}

const serverErrors = [
	'AUTH_REQUIRED', 'PHONE_VERIFICATION_REQUIRED', 'SMS_CHALLENGE_EXPIRED', 'SMS_CODE_INVALID',
	'SMS_TOO_FREQUENT', 'SMS_PROVIDER_FAILED', 'SMS_PROVIDER_UNKNOWN', 'EMPLOYEE_NOT_FOUND',
	'EMPLOYEE_DISABLED', 'NO_VALID_ASSIGNMENT', 'MOBILE_ENTRY_DISABLED', 'MOBILE_JOB_FUNCTION_MISSING',
	'STORE_NOT_ALLOWED', 'STORE_DISABLED', 'AUTH_VERSION_CHANGED', 'MERCHANT_SESSION_EXPIRED',
	'TOKEN_OWNER_MISMATCH', 'CONTRACT_VERSION_UNSUPPORTED', 'LEGACY_PHONE_CONFLICT', 'ACTIVE_CONTEXT_MISMATCH'
]

const protocolErrors = [
	'INVALID_REQUEST_FIELD', 'UNSUPPORTED_MEDIA_TYPE', 'CONTRACT_HEADER_INVALID', 'CREDENTIAL_CONFLICT',
	'CREDENTIAL_FORBIDDEN', 'IDEMPOTENCY_KEY_CONFLICT', 'CAPTCHA_PROOF_INVALID', 'CAPTCHA_PROOF_EXPIRED',
	'INSTALLATION_ID_INVALID', 'ROUTE_NOT_FOUND', 'INSTALL_REQUIRED', 'STATION_CLOSED'
]

test('mobile error contracts are closed and preserve the twenty business errors', () => {
	const errors = sourceJson('shared/contracts/mobile-api-errors-v1.json')
	assert.deepEqual(exactSorted(errors.serverErrors), exactSorted(serverErrors))
	assert.equal(new Set(errors.serverErrors).size, 20)
	assert.deepEqual(exactSorted(errors.protocolErrors), exactSorted(protocolErrors))
	assert.equal(new Set(errors.protocolErrors).size, 12)
	assert.deepEqual(errors.businessErrorEnvelope, ['contractVersion', 'requestId', 'errorCode', 'message'])
	assert.deepEqual(errors.protocolErrorEnvelope, ['contractVersion', 'requestId', 'protocolCode', 'fieldPath', 'message'])
	assert.deepEqual(errors.sessionEndCauses, ['REPLACED_BY_NEW_DEVICE', 'SIGNED_OUT', 'ADMIN_SIGN_OUT_ALL', 'SESSION_TIMEOUT'])
})

test('public auth endpoints derive their required metadata from the contract', () => {
	const auth = sourceJson('shared/contracts/mobile-auth-v1.contract.json')
	const profile = auth.requestMetadataProfiles.PUBLIC
	assert.deepEqual(Object.keys(profile.required).sort(), [
		'X-Mobile-Client-Session-Id', 'X-Mobile-Contract-Version', 'X-Mobile-Platform', 'X-Mobile-Request-Id'
	])
	assert.equal(profile.forbidden.includes('Authorization'), true)
	assert.equal(auth.endpoints.createCaptchaChallenge.requestMetadataProfile, 'PUBLIC')
	assert.equal(auth.endpoints.verifyCaptchaChallenge.requestMetadataProfile, 'PUBLIC')
	assert.equal(auth.endpoints.createSmsChallenge.request.required.includes('idempotencyKey'), true)
	assert.equal(auth.phoneVerification.verificationVersionSource, 'identityVersion')
	assert.equal(auth.phoneVerification.merchantEligibilitySeconds, 300)
	assert.equal(auth.endpoints.verifySmsChallenge.request.codePattern, '^[0-9]{6}$')
	assert.equal(new RegExp(auth.endpoints.verifySmsChallenge.request.codePattern).test('012345'), true)
	assert.equal(new RegExp(auth.endpoints.verifySmsChallenge.request.codePattern).test('12345'), false)
	assert.equal(new RegExp(auth.endpoints.verifySmsChallenge.request.codePattern).test('1234567'), false)
	assertRequired(assert, auth.endpoints.createSmsChallenge.success, ['required', 'forbidden'], 'SMS success contract')
	for (const forbidden of ['code', 'verificationCode', 'providerPayload', 'resendAfter']) assert.equal(auth.endpoints.createSmsChallenge.success.forbidden.includes(forbidden), true)
	assert.equal(auth.endpoints.createSmsChallenge.clientRules.resendAfterSecondsType, 'NON_NEGATIVE_INTEGER')
	assert.equal(auth.endpoints.createSmsChallenge.clientRules.resendAuthority, 'SERVER_RELATIVE_SECONDS')
	assert.equal(auth.security.ordinaryUserTokenProvesPhoneVerification, false)
	assert.equal(auth.security.clientPhoneParameterIsTrusted, false)
	assert.equal(auth.security.legacyPhoneConflictFailsClosed, true)
	assert.equal(auth.security.verificationVersionMustBePositive, true)
	assert.equal(auth.security.providerUnknownCreatesVerification, false)
})

test('merchant credential profiles prohibit token aliasing and bind every endpoint', () => {
	const merchant = sourceJson('shared/contracts/mobile-merchant-v1.contract.json')
	const exchange = merchant.requestMetadataProfiles.APP_SESSION_EXCHANGE
	const session = merchant.requestMetadataProfiles.MERCHANT_SESSION

	assert.deepEqual(exchange.required, {'X-Mobile-Contract-Version': 'contractVersion', 'X-Mobile-Client-Session-Id': 'clientSessionId', 'X-Mobile-Platform': 'platform', 'X-Mobile-Request-Id': 'requestId', 'X-Mobile-App-Session': 'appSession'})
	assert.deepEqual(exchange.forbidden, ['Authorization', 'X-Mobile-Active-Context-Id', 'X-Mobile-State-Context-Id', 'Cookie', 'queryToken', 'bodyToken', 'headerAlias'])
	assert.deepEqual(session.required, {'X-Mobile-Contract-Version': 'contractVersion', 'X-Mobile-Client-Session-Id': 'clientSessionId', 'X-Mobile-Platform': 'platform', 'X-Mobile-Request-Id': 'requestId', 'Authorization': 'Bearer <merchantToken>', 'X-Mobile-Active-Context-Id': 'activeContextId', 'X-Mobile-State-Context-Id': 'stateContextId'})
	assert.deepEqual(session.forbidden, ['X-Mobile-App-Session', 'Cookie', 'queryToken', 'bodyToken', 'headerAlias'])
	assert.equal(exchange.emptyContextValuesAllowed, false)
	assert.equal(session.emptyContextValuesAllowed, false)
	assert.equal(merchant.endpoints.createMerchantSession.requestMetadataProfile, 'APP_SESSION_EXCHANGE')
	for (const endpoint of ['bootstrap', 'switchContext', 'logout']) {
		assert.equal(merchant.endpoints[endpoint].requestMetadataProfile, 'MERCHANT_SESSION')
	}
	assert.deepEqual(merchant.endpoints.logout.requestBodyRequired, ['idempotencyKey'])
})

test('merchant root and data scope remain fail-closed', () => {
	const merchant = sourceJson('shared/contracts/mobile-merchant-v1.contract.json')
	assert.deepEqual(merchant.merchantRoot.reasonCodeEnum, ['READY', 'BOOTSTRAPPED', 'CONTEXT_SWITCHED'])
	assert.equal(merchant.dataScope.scopeEntryCountMustBePositiveForMerchantReady, true)
	assert.deepEqual(merchant.authorizationSemantics.effectiveScopeIntersection, [
		'VALID_ASSIGNMENT', 'MOBILE_JOB_FUNCTION', 'MOBILE_AUTHORIZATION_SCOPE',
		'EMPLOYEE_DATA_SCOPE', 'EMPLOYEE_STORE_ISOLATION', 'OPERATING_ORGANIZATION_OR_STORE'
	])
	assert.equal(merchant.authorizationSemantics.emptyScopeMeansNoAccess, true)
	assert.equal(merchant.authorizationSemantics.emptyCapabilitiesMeansNoCapability, true)
	assert.equal(merchant.authorizationSemantics.emptyAvailableActionsMeansNoAction, true)
	assert.equal(merchant.authorizationSemantics.mergeAcrossContexts, false)
	assert.equal(merchant.authorizationSemantics.deliveryOnlyMerchantAccess, false)
	assert.deepEqual(merchant.contextSummary.required, ['contextId', 'label'])
	assert.deepEqual(merchant.contextSummary.forbidden, ['capabilities', 'dataScope', 'availableActions'])
	assert.deepEqual(merchant.dataScope.required, ['authorized', 'scopeEntryCount', 'scopeDigest'])
	assert.deepEqual(merchant.merchantRoot.required, ['contractVersion', 'merchantToken', 'expiresAt', 'employee', 'authVersion', 'activeContextId', 'activeContext', 'contexts', 'capabilities', 'dataScope', 'availableActions', 'reasonCode', 'stateContextId', 'stateRevision'])
	assert.deepEqual(merchant.merchantRoot.employeeRequired, ['id', 'name', 'avatar', 'status'])
})

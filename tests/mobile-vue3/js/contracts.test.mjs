import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import test from 'node:test'

import { exactSorted, repoRoot, sourceJson, sourceRoot } from './helpers.mjs'

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
	const merchant = sourceJson('merchant/contracts/mobile-merchant-v1.contract.json')
	const password = merchant.requestMetadataProfiles.EMPLOYEE_PASSWORD
	const exchange = merchant.requestMetadataProfiles.APP_SESSION_EXCHANGE
	const session = merchant.requestMetadataProfiles.MERCHANT_SESSION

	assert.deepEqual(password.required, {'X-Mobile-Contract-Version': 'contractVersion', 'X-Mobile-Client-Session-Id': 'clientSessionId', 'X-Mobile-Platform': 'platform', 'X-Mobile-Request-Id': 'requestId'})
	assert.deepEqual(password.forbidden, ['Authorization', 'X-Mobile-App-Session', 'X-Mobile-Active-Context-Id', 'X-Mobile-State-Context-Id', 'Cookie', 'queryToken', 'bodyToken', 'headerAlias'])
	assert.equal(password.emptyContextValuesAllowed, false)
	assert.deepEqual(exchange.required, {'X-Mobile-Contract-Version': 'contractVersion', 'X-Mobile-Client-Session-Id': 'clientSessionId', 'X-Mobile-Platform': 'platform', 'X-Mobile-Request-Id': 'requestId', 'X-Mobile-App-Session': 'appSession'})
	assert.deepEqual(exchange.forbidden, ['Authorization', 'X-Mobile-Active-Context-Id', 'X-Mobile-State-Context-Id', 'Cookie', 'queryToken', 'bodyToken', 'headerAlias'])
	assert.deepEqual(session.required, {'X-Mobile-Contract-Version': 'contractVersion', 'X-Mobile-Client-Session-Id': 'clientSessionId', 'X-Mobile-Platform': 'platform', 'X-Mobile-Request-Id': 'requestId', 'Authorization': 'Bearer <merchantToken>', 'X-Mobile-Active-Context-Id': 'activeContextId', 'X-Mobile-State-Context-Id': 'stateContextId'})
	assert.deepEqual(session.forbidden, ['X-Mobile-App-Session', 'Cookie', 'queryToken', 'bodyToken', 'headerAlias'])
	assert.equal(exchange.emptyContextValuesAllowed, false)
	assert.equal(session.emptyContextValuesAllowed, false)
	assert.equal(merchant.endpoints.passwordLogin.requestMetadataProfile, 'EMPLOYEE_PASSWORD')
	assert.deepEqual(merchant.endpoints.passwordLogin.requestBodyRequired, ['account', 'pwd', 'installationId', 'idempotencyKey'])
	assert.equal(merchant.endpoints.createMerchantSession.requestMetadataProfile, 'APP_SESSION_EXCHANGE')
	for (const endpoint of ['bootstrap', 'switchContext', 'logout']) {
		assert.equal(merchant.endpoints[endpoint].requestMetadataProfile, 'MERCHANT_SESSION')
	}
	assert.deepEqual(merchant.endpoints.logout.requestBodyRequired, ['idempotencyKey'])
})

test('employee password sign-in creates only a new merchant session', () => {
	const source = fs.readFileSync(path.join(sourceRoot, 'merchant/api/mobile-login-client.uts'), 'utf8')
	assert.equal(source.includes("'/mobile/merchant/password-login'"), true)
	assert.equal(source.includes("commonHeaders('mobile-merchant-v1')"), true)
	assert.equal(source.includes('installationId: deviceId'), true)
	assert.equal(source.includes('saveMerchantRoot(root)'), true)
	assert.equal(source.includes('merchant_legacy'), false)
})

test('merchant session chooses an effective mobile appointment instead of the newest appointment', () => {
	const source = fs.readFileSync(path.join(repoRoot, '后端代码', 'app', 'services', 'mobile', 'merchant', 'MobileMerchantSessionServices.php'), 'utf8')
	assert.match(source, /defaultEligibleMobileStaff\(\$employeeId, \$auth\)/)
	assert.match(source, /isStoreWithinMobileScope\(\$auth, \$storeId\)/)
	assert.match(source, /isStoreWithinEmployeeDataScope\(\$employeeId, \$storeId\)/)
	assert.equal(source.includes('resolveCurrentStaff($employeeId)'), false)
})

test('customer UTS transport mirror stays exact with the frozen customer and merchant headers', () => {
	const merchant = sourceJson('merchant/contracts/mobile-merchant-v1.contract.json')
	const customer = sourceJson('merchant/contracts/mobile-customer-v1.contract.json')
	const source = fs.readFileSync(path.join(sourceRoot, 'merchant/api/mobile-customer-client.uts'), 'utf8')
	assert.match(source, /contractVersion: 'mobile-merchant-v1'/)
	for (const endpoint of ['queryExclusiveCustomers', 'queryCustomers', 'queryCustomerServiceRecords', 'queryCustomerRecentSummary', 'queryCustomerOrderRecords', 'queryCustomerOrderRecordDetail', 'queryCustomerAssetRecords', 'customerProfileDraft', 'customerProfileAvatarUpload', 'customerProfileDetail', 'createCustomerProfile', 'updateCustomerProfile', 'createCustomer', 'unifiedQueryCapabilities', 'unifiedQueryCommand', 'listAudiences', 'createAudience', 'queryAudienceMembers']) {
		const contractEndpoint = customer.endpoints[endpoint]
		assert.match(source, new RegExp(`${endpoint}: \\{ method: '${contractEndpoint.method}', path: '${contractEndpoint.path}', requestMetadataProfile: 'MERCHANT_SESSION' \\}`))
	}
	assert.deepEqual(customer.endpoints.createCustomer.requestBodyRequired, ['name', 'phone', 'idempotencyKey'])
	assert.deepEqual(customer.endpoints.createCustomerProfile.requestBodyRequired, ['name', 'phone', 'idempotencyKey'])
	assert.deepEqual(customer.endpoints.updateCustomerProfile.requestBodyRequired, ['expectedVersion', 'idempotencyKey'])
	for (const [header, value] of Object.entries(merchant.requestMetadataProfiles.MERCHANT_SESSION.required)) {
		const escapedHeader = header.replace(/[.*+?^${}()|[\\]\\]/g, '\\$&')
		const escapedValue = value.replace(/[.*+?^${}()|[\\]\\]/g, '\\$&')
		assert.match(source, new RegExp(`(?:'${escapedHeader}'|${escapedHeader}): '${escapedValue}'`))
	}
	for (const header of merchant.requestMetadataProfiles.MERCHANT_SESSION.forbidden) assert.match(source, new RegExp(`'${header}'`))
})

test('customer-care UTS transport keeps the only supported merchant actions and headers', () => {
	const merchant = sourceJson('merchant/contracts/mobile-merchant-v1.contract.json')
	const source = fs.readFileSync(path.join(sourceRoot, 'merchant/api/mobile-customer-care-client.uts'), 'utf8')
	for (const endpoint of [
		"queryWorkbench: { method: 'POST', path: '/mobile/merchant/customer-care/workbench', requestMetadataProfile: 'MERCHANT_SESSION' }",
		"createTask: { method: 'POST', path: '/mobile/merchant/customer-care/actions/create-care-task', requestMetadataProfile: 'MERCHANT_SESSION' }",
		"completeTask: { method: 'POST', path: '/mobile/merchant/customer-care/actions/complete-care-task', requestMetadataProfile: 'MERCHANT_SESSION' }"
	]) assert.equal(source.includes(endpoint), true)
	assert.equal(source.includes('start-care-task'), false)
	for (const [header, value] of Object.entries(merchant.requestMetadataProfiles.MERCHANT_SESSION.required)) {
		const escapedHeader = header.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
		const escapedValue = value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
		assert.match(source, new RegExp(`(?:'${escapedHeader}'|${escapedHeader}): '${escapedValue}'`))
	}
})

test('merchant root and data scope remain fail-closed', () => {
	const merchant = sourceJson('merchant/contracts/mobile-merchant-v1.contract.json')
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

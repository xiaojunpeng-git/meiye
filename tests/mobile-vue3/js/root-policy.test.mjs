import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import path from 'node:path'
import test from 'node:test'

import { fixtureJson, sourceJson, testRoot } from './helpers.mjs'

const require = createRequire(import.meta.url)
const rootCore = require(path.join(testRoot, '.generated', 'root-state-core.cjs'))

function rootWith(root, overrides) {
	return {
		...root,
		...overrides,
		employee: Object.hasOwn(overrides, 'employee') ? overrides.employee : { ...root.employee },
		activeContext: Object.hasOwn(overrides, 'activeContext')
			? overrides.activeContext
			: { ...root.activeContext },
		contexts: Object.hasOwn(overrides, 'contexts')
			? overrides.contexts
			: root.contexts.map((context) => ({ ...context })),
		dataScope: Object.hasOwn(overrides, 'dataScope')
			? overrides.dataScope
			: { ...root.dataScope }
	}
}

test('root policy freezes server-first cleanup and success reason codes', () => {
	const policy = sourceJson('shared/state/merchant-root-policy-v1.json')
	const contract = sourceJson('merchant/contracts/mobile-merchant-v1.contract.json')

	assert.equal(policy.serverBeforeClient, true)
	assert.deepEqual(policy.successRootReasonCodes, contract.merchantRoot.reasonCodeEnum)
	assert.equal(policy.clearMerchantRootFields.includes('reasonCode'), true)
	assert.equal(policy.contextRules.clearOldRootBeforeInstallingSwitchResponse, true)
	assert.equal(policy.contextRules.clearResourceVersionStoreBeforeInstallingSwitchResponse, true)
	assert.deepEqual(policy.responseRules, {onlyCompleteRootReplacementMayContainState: true, queryMayContainState: false, toastMayContainState: false, navigationMayContainState: false})
	assert.deepEqual(policy.merchantReadyInstallRequirements, {completeMerchantRoot: true, contractVersionMatches: true, merchantTokenNonEmpty: true, employeeActive: true, authVersionPositive: true, activeContextIdNonEmpty: true, stateContextIdNonEmpty: true, stateRevisionValid: true, activeBusinessContextIdNonEmpty: true, activeContextAppearsExactlyOnceInContexts: true, contextIdsUnique: true, capabilitiesArray: true, availableActionsArray: true, dataScopeAuthorized: true, scopeEntryCountPositive: true, scopeDigestNonEmpty: true})
	const expectedClear = ['merchantSession', ...contract.merchantRoot.required.filter((field) => field !== 'contractVersion')]
	assert.deepEqual([...policy.clearMerchantRootFields].sort(), expectedClear.sort())
	assert.equal(policy.businessResourceStateAllowedInRoot, false)
	for (const forbidden of ['customer', 'order', 'metric', 'careTask', 'cashier']) assert.equal(policy.rootStateAllowedFields.includes(forbidden), false)
})

test('compiled production UTS owns merchant root install decisions', () => {
	const root = fixtureJson('merchant/bootstrap-success.json')

	assert.equal(rootCore.merchantStateIdentifiersAreCleared('', '', '', ''), true)
	assert.equal(rootCore.merchantContextIsComplete('', ''), false)
	assert.equal(
		rootCore.merchantRootInstallDecision(
			root.contractVersion,
			'mobile-merchant-v1',
			true,
			root.authVersion,
			root.activeContextId,
			root.stateContextId,
			root.stateRevision,
			true,
			root.dataScope.scopeEntryCount,
			root.dataScope.scopeDigest
		),
		'ACCEPT'
	)
	assert.equal(
		rootCore.merchantRootInstallDecision(
			'unsupported',
			'mobile-merchant-v1',
			true,
			1,
			'active',
			'state',
			'1',
			true,
			1,
			'scope'
		),
		'CONTRACT_VERSION_MISMATCH'
	)
	assert.equal(
		rootCore.merchantRootInstallDecision(
			'mobile-merchant-v1',
			'mobile-merchant-v1',
			true,
			1,
			'',
			'state',
			'1',
			true,
			1,
			'scope'
		),
		'MISSING_STATE_CONTEXT'
	)
})

test('compiled production UTS rejects stale roots and unauthorised projections', () => {
	const policy = sourceJson('shared/state/merchant-root-policy-v1.json')
	const contract = sourceJson('merchant/contracts/mobile-merchant-v1.contract.json')
	const root = fixtureJson('merchant/bootstrap-success.json')
	const current = rootWith(root, { stateRevision: '5' })

	assert.equal(
		rootCore.evaluateMerchantRootUpdate(policy, contract, current, rootWith(root, { stateRevision: '4' }), false, false, '', 1, 1),
		'STALE_ROOT_STATE'
	)
	assert.equal(
		rootCore.evaluateMerchantRootUpdate(policy, contract, current, rootWith(root, { stateRevision: '5' }), false, false, '', 1, 1),
		'DUPLICATE_ROOT_STATE'
	)
	assert.equal(
		rootCore.evaluateMerchantRootUpdate(policy, contract, null, rootWith(root, { dataScope: { ...root.dataScope, authorized: false } }), false, false, '', 1, 1),
		'UNAUTHORIZED_ROOT_STATE'
	)
})

test('compiled production UTS permits only explicit context root replacement', () => {
	const policy = sourceJson('shared/state/merchant-root-policy-v1.json')
	const contract = sourceJson('merchant/contracts/mobile-merchant-v1.contract.json')
	const current = fixtureJson('merchant/bootstrap-success.json')
	const switched = fixtureJson('merchant/context-switch-after.json')

	assert.equal(
		rootCore.evaluateMerchantRootUpdate(policy, contract, current, switched, false, false, '', 1, 1),
		'STATE_CONTEXT_MISMATCH'
	)
	assert.equal(
		rootCore.evaluateMerchantRootUpdate(policy, contract, current, switched, true, true, 'context-store-002', 1, 1),
		'ACCEPT_SWITCH'
	)
	assert.equal(
		rootCore.evaluateMerchantRootUpdate(policy, contract, current, switched, true, true, 'wrong-target', 1, 1),
		'INVALID_CONTEXT_SWITCH_ROOT'
	)
})

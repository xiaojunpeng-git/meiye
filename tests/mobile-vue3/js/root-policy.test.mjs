import assert from 'node:assert/strict'
import test from 'node:test'

import { fixtureJson, sourceJson } from './helpers.mjs'

function hasCompleteMerchantRoot(contract, incoming) {
	if (!incoming || !contract.merchantRoot.required.every((field) => Object.hasOwn(incoming, field))) {
		return false
	}
	if (
		!incoming.employee ||
		!contract.merchantRoot.employeeRequired.every((field) => Object.hasOwn(incoming.employee, field))
	) {
		return false
	}
	if (
		typeof incoming.merchantToken !== 'string' ||
		incoming.merchantToken.length === 0 ||
		typeof incoming.expiresAt !== 'string' ||
		incoming.expiresAt.length === 0 ||
		!Number.isInteger(incoming.authVersion) ||
		incoming.authVersion <= 0 ||
		!incoming.activeContext ||
		typeof incoming.activeContext.contextId !== 'string' ||
		incoming.activeContext.contextId.length === 0
	) {
		return false
	}
	if (
		!incoming.dataScope ||
		!Array.isArray(incoming.contexts) ||
		!Array.isArray(incoming.capabilities) ||
		!Array.isArray(incoming.availableActions)
	) {
		return false
	}
	return true
}

function evaluateRootUpdate(policy, contract, current, incoming, options = {}) {
	const {
		switchIntent = false,
		explicitSwitch = false,
		targetContextId = '',
		requestGeneration = 1,
		currentGeneration = 1
	} = options

	if (!hasCompleteMerchantRoot(contract, incoming)) {
		return 'INCOMPLETE_ROOT_STATE'
	}
	if (incoming.contractVersion !== contract.contractVersion) {
		return 'CONTRACT_VERSION_MISMATCH'
	}
	if (
		typeof incoming.employee.id !== 'string' ||
		incoming.employee.id.length === 0 ||
		!contract.merchantRoot.employeeStatusAllowedForMerchantReady.includes(incoming.employee.status)
	) {
		return 'UNAUTHORIZED_ROOT_STATE'
	}
	if (
		typeof incoming.activeContextId !== 'string' ||
		incoming.activeContextId.length === 0 ||
		typeof incoming.stateContextId !== 'string' ||
		incoming.stateContextId.length === 0
	) {
		return 'MISSING_STATE_CONTEXT'
	}
	if (
		typeof incoming.stateRevision !== 'string' ||
		!new RegExp(policy.stateRevisionPattern).test(incoming.stateRevision)
	) {
		return 'INVALID_STATE_REVISION'
	}
	if (switchIntent && !explicitSwitch) {
		return 'CONTEXT_SWITCH_IN_PROGRESS'
	}
	if (explicitSwitch && !current) {
		return 'INVALID_CONTEXT_SWITCH_ROOT'
	}

	const stateContextChanged = current && current.stateContextId !== incoming.stateContextId
	const activeContextChanged = current && current.activeContextId !== incoming.activeContextId
	const businessContextChanged =
		current && current.activeContext.contextId !== incoming.activeContext.contextId
	const anyContextChanged = stateContextChanged || activeContextChanged || businessContextChanged

	if (current && anyContextChanged && !explicitSwitch) {
		return stateContextChanged ? 'STATE_CONTEXT_MISMATCH' : 'ACTIVE_ROOT_CONTEXT_MISMATCH'
	}
	if (current && explicitSwitch) {
		if (
			!stateContextChanged ||
			!activeContextChanged ||
			!businessContextChanged ||
			targetContextId.length === 0 ||
			incoming.activeContext.contextId !== targetContextId
		) {
			return 'INVALID_CONTEXT_SWITCH_ROOT'
		}
	}
	if ((!current || stateContextChanged) && incoming.stateRevision !== policy.contextRules.newStateContextInitialRevision) {
		return 'INVALID_STATE_REVISION'
	}

	if (
		incoming.dataScope.authorized !== true ||
		!Number.isInteger(incoming.dataScope.scopeEntryCount) ||
		incoming.dataScope.scopeEntryCount <= 0 ||
		typeof incoming.dataScope.scopeDigest !== 'string' ||
		incoming.dataScope.scopeDigest.length === 0
	) {
		return 'UNAUTHORIZED_ROOT_STATE'
	}
	const activeContextMatches = incoming.contexts.filter(
		(context) => context.contextId === incoming.activeContext.contextId
	)
	if (activeContextMatches.length !== 1) {
		return 'INCOMPLETE_ROOT_STATE'
	}
	if (new Set(incoming.contexts.map((context) => context.contextId)).size !== incoming.contexts.length) {
		return 'INCOMPLETE_ROOT_STATE'
	}
	for (const context of incoming.contexts) {
		for (const forbidden of contract.contextSummary.forbidden) {
			if (Object.hasOwn(context, forbidden)) {
				return 'INCOMPLETE_ROOT_STATE'
			}
		}
	}

	if (current && !stateContextChanged) {
		const currentRevision = BigInt(current.stateRevision)
		const incomingRevision = BigInt(incoming.stateRevision)
		if (incomingRevision < currentRevision) {
			return 'STALE_ROOT_STATE'
		}
		if (incomingRevision === currentRevision) {
			return 'DUPLICATE_ROOT_STATE'
		}
	}
	if (
		!Number.isInteger(requestGeneration) ||
		requestGeneration <= 0 ||
		!Number.isInteger(currentGeneration) ||
		currentGeneration <= 0
	) {
		return 'INVALID_REQUEST_GENERATION'
	}
	if (requestGeneration < currentGeneration) {
		return 'STALE_REQUEST_GENERATION'
	}
	return explicitSwitch ? 'ACCEPT_SWITCH' : 'ACCEPT'
}

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

test('root policy freezes the server-first decision order', () => {
	const policy = sourceJson('shared/state/merchant-root-policy-v1.json')
	assert.equal(policy.serverBeforeClient, true)
	assert.equal(policy.stateRevisionType, 'STRING')
	assert.equal(policy.contextRules.compareRevisionAcrossDifferentStateContexts, false)
	assert.equal(policy.contextRules.ordinaryResponseCanChangeStateContext, false)
	assert.equal(policy.contextRules.explicitSwitchResponseCanChangeStateContext, true)
	assert.equal(policy.contextRules.clearOldRootBeforeInstallingSwitchResponse, true)
	assert.equal(policy.contextRules.clearResourceVersionStoreBeforeInstallingSwitchResponse, true)
	assert.equal(policy.generationRules.requestGenerationMayOverrideServerContextError, false)
})

test('same-context stale and duplicate revisions are rejected', () => {
	const policy = sourceJson('shared/state/merchant-root-policy-v1.json')
	const contract = sourceJson('shared/contracts/mobile-merchant-v1.contract.json')
	const fixture = fixtureJson('merchant/bootstrap-success.json')
	const current = rootWith(fixture, { stateRevision: '5' })

	assert.equal(
		evaluateRootUpdate(policy, contract, current, rootWith(fixture, { stateRevision: '4' })),
		'STALE_ROOT_STATE'
	)
	assert.equal(
		evaluateRootUpdate(policy, contract, current, rootWith(fixture, { stateRevision: '5' })),
		'DUPLICATE_ROOT_STATE'
	)
	assert.equal(
		evaluateRootUpdate(policy, contract, current, rootWith(fixture, { stateRevision: '6' })),
		'ACCEPT'
	)
})

test('ordinary responses cannot replace context during or after a switch intent', () => {
	const policy = sourceJson('shared/state/merchant-root-policy-v1.json')
	const contract = sourceJson('shared/contracts/mobile-merchant-v1.contract.json')
	const current = fixtureJson('merchant/bootstrap-success.json')
	const switched = fixtureJson('merchant/context-switch-after.json')

	assert.equal(
		evaluateRootUpdate(policy, contract, current, switched),
		'STATE_CONTEXT_MISMATCH'
	)
	assert.equal(
		evaluateRootUpdate(policy, contract, current, switched, { switchIntent: true }),
		'CONTEXT_SWITCH_IN_PROGRESS'
	)
	assert.equal(
		evaluateRootUpdate(policy, contract, current, switched, {
			switchIntent: true,
			explicitSwitch: true,
			targetContextId: 'context-store-002'
		}),
		'ACCEPT_SWITCH'
	)
	assert.equal(switched.stateRevision, policy.contextRules.newStateContextInitialRevision)
})

test('request generation only discards local stale responses', () => {
	const policy = sourceJson('shared/state/merchant-root-policy-v1.json')
	const contract = sourceJson('shared/contracts/mobile-merchant-v1.contract.json')
	const fixture = fixtureJson('merchant/bootstrap-success.json')
	const current = rootWith(fixture, { stateRevision: '1' })
	const incoming = rootWith(fixture, { stateRevision: '2' })

	assert.equal(
		evaluateRootUpdate(policy, contract, current, incoming, {
			requestGeneration: 2,
			currentGeneration: 3
		}),
		'STALE_REQUEST_GENERATION'
	)
	assert.equal(policy.merchantSessionInvalidTarget, 'MEMBER_READY')
	assert.equal(policy.appOrPhoneIdentityInvalidTarget, 'SESSION_INVALID')
})

test('stale generation cannot install an otherwise valid explicit switch', () => {
	const policy = sourceJson('shared/state/merchant-root-policy-v1.json')
	const contract = sourceJson('shared/contracts/mobile-merchant-v1.contract.json')
	const current = fixtureJson('merchant/bootstrap-success.json')
	const switched = fixtureJson('merchant/context-switch-after.json')

	assert.equal(
		evaluateRootUpdate(policy, contract, current, switched, {
			explicitSwitch: true,
			targetContextId: 'context-store-002',
			requestGeneration: 2,
			currentGeneration: 3
		}),
		'STALE_REQUEST_GENERATION'
	)
	assert.equal(policy.generationRules.staleGenerationMayInstallExplicitSwitch, false)
})

test('every newly signed state context starts at string revision one', () => {
	const policy = sourceJson('shared/state/merchant-root-policy-v1.json')
	const contract = sourceJson('shared/contracts/mobile-merchant-v1.contract.json')
	const initial = fixtureJson('merchant/bootstrap-success.json')
	const current = initial
	const switched = fixtureJson('merchant/context-switch-after.json')

	assert.equal(contract.merchantRoot.newStateContextInitialRevision, '1')
	assert.equal(policy.contextRules.newStateContextInitialRevision, '1')
	assert.equal(evaluateRootUpdate(policy, contract, null, initial), 'ACCEPT')
	assert.equal(
		evaluateRootUpdate(policy, contract, null, rootWith(initial, { stateRevision: '9' })),
		'INVALID_STATE_REVISION'
	)
	assert.equal(
		evaluateRootUpdate(policy, contract, current, rootWith(switched, { stateRevision: '9' }), {
			explicitSwitch: true,
			targetContextId: 'context-store-002'
		}),
		'INVALID_STATE_REVISION'
	)
})

test('explicit switch requires a current root and valid positive generations', () => {
	const policy = sourceJson('shared/state/merchant-root-policy-v1.json')
	const contract = sourceJson('shared/contracts/mobile-merchant-v1.contract.json')
	const switched = fixtureJson('merchant/context-switch-after.json')
	const current = fixtureJson('merchant/bootstrap-success.json')

	assert.equal(
		evaluateRootUpdate(policy, contract, null, switched, {
			explicitSwitch: true,
			targetContextId: 'context-store-002'
		}),
		'INVALID_CONTEXT_SWITCH_ROOT'
	)
	assert.equal(
		evaluateRootUpdate(policy, contract, current, rootWith(current, { stateRevision: '2' }), {
			requestGeneration: 0,
			currentGeneration: 1
		}),
		'INVALID_REQUEST_GENERATION'
	)
})

test('incomplete and unauthorized roots are never installable', () => {
	const policy = sourceJson('shared/state/merchant-root-policy-v1.json')
	const contract = sourceJson('shared/contracts/mobile-merchant-v1.contract.json')
	const current = fixtureJson('merchant/bootstrap-success.json')
	const emptyScope = fixtureJson('merchant/bootstrap-empty-scope.json').root

	assert.equal(
		evaluateRootUpdate(policy, contract, current, {
			stateContextId: current.stateContextId,
			stateRevision: '2'
		}),
		'INCOMPLETE_ROOT_STATE'
	)
	assert.equal(
		evaluateRootUpdate(policy, contract, null, emptyScope),
		'UNAUTHORIZED_ROOT_STATE'
	)
	assert.equal(
		evaluateRootUpdate(policy, contract, null, rootWith(current, { dataScope: null })),
		'INCOMPLETE_ROOT_STATE'
	)
	assert.equal(
		evaluateRootUpdate(policy, contract, null, rootWith(current, { authVersion: 0 })),
		'INCOMPLETE_ROOT_STATE'
	)
	assert.equal(
		evaluateRootUpdate(policy, contract, null, rootWith(current, {
			contractVersion: 'mobile-merchant-unsupported'
		})),
		'CONTRACT_VERSION_MISMATCH'
	)
	assert.equal(
		evaluateRootUpdate(policy, contract, null, rootWith(current, {
			contexts: [...current.contexts, { ...current.contexts[1] }]
		})),
		'INCOMPLETE_ROOT_STATE'
	)
	assert.equal(policy.merchantReadyInstallRequirements.dataScopeAuthorized, true)
	assert.equal(policy.merchantReadyInstallRequirements.scopeEntryCountPositive, true)
	assert.equal(policy.merchantReadyInstallRequirements.scopeDigestNonEmpty, true)
	assert.equal(contract.merchantRoot.activeBusinessContextIdMustBeOpaqueNonEmpty, true)
	assert.equal(
		evaluateRootUpdate(policy, contract, null, rootWith(current, {
			activeContext: { ...current.activeContext, contextId: '' },
			contexts: [{ contextId: '', label: 'Invalid Context' }]
		})),
		'INCOMPLETE_ROOT_STATE'
	)
})

test('ordinary responses cannot change active or business context under one state context', () => {
	const policy = sourceJson('shared/state/merchant-root-policy-v1.json')
	const contract = sourceJson('shared/contracts/mobile-merchant-v1.contract.json')
	const current = fixtureJson('merchant/bootstrap-success.json')
	const activeIdChanged = rootWith(current, {
		activeContextId: 'active-context-session-unexpected',
		stateRevision: '2'
	})
	const businessContextChanged = rootWith(current, {
		activeContext: { ...current.activeContext, contextId: 'context-store-002' },
		stateRevision: '2'
	})

	assert.equal(
		evaluateRootUpdate(policy, contract, current, activeIdChanged),
		'ACTIVE_ROOT_CONTEXT_MISMATCH'
	)
	assert.equal(
		evaluateRootUpdate(policy, contract, current, businessContextChanged),
		'ACTIVE_ROOT_CONTEXT_MISMATCH'
	)
})

test('query responses do not forge a complete root state', () => {
	const policy = sourceJson('shared/state/merchant-root-policy-v1.json')
	const query = fixtureJson('root/query-no-state.json')

	assert.equal(policy.responseRules.onlyCompleteRootReplacementMayContainState, true)
	assert.equal(policy.responseRules.queryMayContainState, false)
	assert.equal(policy.responseRules.toastMayContainState, false)
	assert.equal(policy.responseRules.navigationMayContainState, false)
	assert.equal(Object.hasOwn(query, 'state'), false)
})

test('merchant root cleanup list includes every sensitive root field', () => {
	const policy = sourceJson('shared/state/merchant-root-policy-v1.json')
	const contract = sourceJson('shared/contracts/mobile-merchant-v1.contract.json')
	const expected = [
		'merchantSession',
		...contract.merchantRoot.required.filter((field) => field !== 'contractVersion')
	]
	assert.deepEqual([...policy.clearMerchantRootFields].sort(), expected.sort())
	assert.equal(policy.businessResourceStateAllowedInRoot, false)
	for (const forbidden of ['customer', 'order', 'metric', 'careTask', 'cashier']) {
		assert.equal(policy.rootStateAllowedFields.includes(forbidden), false)
	}
})

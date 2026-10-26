import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import fs from 'node:fs'
import path from 'node:path'
import test from 'node:test'

import { fixtureJson, mobileRoot, testRoot } from './helpers.mjs'

const require = createRequire(import.meta.url)
const rootCore = require(path.join(testRoot, '.generated', 'root-state-core.cjs'))
const client = fs.readFileSync(path.join(mobileRoot, 'src/merchant/api/mobile-merchant-session-client.uts'), 'utf8')
const workbench = fs.readFileSync(path.join(mobileRoot, 'src/merchant/pages/workbench/index.uvue'), 'utf8')

test('merchant workbench exposes only authoritative contexts and hides the selector for one store', () => {
	assert.match(workbench, /v-if="storeContexts\.length > 1"/)
	assert.match(workbench, /currentMerchantRoot\(\)/)
	assert.match(workbench, /root\.contexts/)
	assert.doesNotMatch(workbench, /query.*Store|visibleStoreIds/)
	assert.match(workbench, /switchMobileMerchantContext\(targetContextId/)
})

test('context switch sends the formal optimistic and idempotent command', () => {
	assert.match(client, /path: '\/mobile\/merchant\/context\/switch'/)
	assert.match(client, /targetContextId: normalizedTarget/)
	assert.match(client, /expectedAuthVersion: Number\(current\.authVersion \|\| 0\)/)
	assert.match(client, /idempotencyKey: opaque\('merchant-context-switch'\)/)
})

test('complete switch root is accepted and preserves the server-wide scope projection', () => {
	const before = fixtureJson('merchant/bootstrap-success.json')
	const after = { ...fixtureJson('merchant/context-switch-after.json'), reasonCode: 'CONTEXT_SWITCHED' }
	assert.equal(rootCore.merchantContextSwitchInstallDecision(before, after, 'context-store-002'), 'ACCEPT_SWITCH')
	assert.deepEqual(after.contexts, before.contexts)
	assert.equal(client.includes('saveMerchantRoot(incoming)'), true)
	assert.equal(client.includes('incoming.dataScope ='), false)
	assert.equal(client.includes('visibleStoreIds'), false)
})

test('failed or invalid switch result preserves the old context', () => {
	const before = fixtureJson('merchant/bootstrap-success.json')
	const after = { ...fixtureJson('merchant/context-switch-after.json'), reasonCode: 'CONTEXT_SWITCHED' }
	const invalid = { ...after, activeContext: { ...after.activeContext, contextId: 'context-store-001' } }
	assert.equal(rootCore.merchantContextSwitchInstallDecision(before, invalid, 'context-store-002'), 'INVALID_CONTEXT_SWITCH_ROOT')
	assert.match(client, /if \(!result\.ok\) \{ done\(result\); return \}/)
	assert.match(client, /if \(decision != 'ACCEPT_SWITCH'\)[\s\S]*?return[\s\S]*?saveMerchantRoot\(incoming\)/)
})

test('successful switch reloads workbench data only after authoritative root installation', () => {
	assert.match(workbench, /if \(!result\.ok\) \{ storeSwitchMessage\.value = String\(result\.message/)
	assert.match(workbench, /loadStoreContext\(\)\s*loadTodos\(\)/)
})

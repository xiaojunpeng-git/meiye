import fs from 'node:fs'
import path from 'node:path'
import process from 'node:process'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const bridge = fs.readFileSync(
  path.join(root, '前端代码/cashier-v3/src/services/cashierV3Bridge.js'),
  'utf8'
)

let passed = 0
let failed = 0
function ok(name, condition) {
  if (condition) {
    passed += 1
    console.log(`PASS ${name}`)
    return
  }
  failed += 1
  console.log(`FAIL ${name}`)
}

const resolver = bridge.match(/function resolveCommandContexts\(action, payload\) \{([\s\S]*?)\n}\n\nfunction preparationKindForAction/)?.[1] || ''

ok(
  'revising an existing checkout adds its persisted request to command contexts',
  resolver.includes("if (payload.checkoutRequestId) {")
    && resolver.includes("buildCommandContext('checkout_request', payload.checkoutRequestId)")
)
ok(
  'new checkout preparation still relies on the workspace context when no prior request exists',
  resolver.includes('if (isCashierWorkspaceAction(action)) {')
    && resolver.includes("buildCommandContext('cashier_workspace', workspace.id)")
)
ok(
  'contexts without public versions remain blocked before the command is sent',
  resolver.includes('contexts.some((c) => !c.id || !Number.isSafeInteger(c.expectedVersion) || c.expectedVersion <= 0)')
)

console.log(`\n${passed} passed, ${failed} failed`)
process.exit(failed === 0 ? 0 : 1)
